<?php
// File: actions/upload_proof.php

require_once __DIR__ . '/../config/bootstrap.php';
require_role('student');
require_once __DIR__ . '/../config/db.php';

// 1) Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// 2) CSRF check
if (empty($_POST['csrf_token']) || ! verify_csrf_token($_POST['csrf_token'])) {
    exit('CSRF token mismatch');
}

// 3) Grab inputs
$studentId = (int)($_POST['student_id'] ?? 0);
$amount    = (float)($_POST['amount']     ?? 0.00);
$method    = trim($_POST['payment_method'] ?? '');
$txn       = trim($_POST['txn_id']         ?? '');

if ($studentId < 1) {
    exit('Invalid student.');
}
if ($amount <= 0 || $method === '') {
    exit('Invalid payment data.');
}

// 4) Validate file upload
if (empty($_FILES['proof_file']) || $_FILES['proof_file']['error'] !== UPLOAD_ERR_OK) {
    exit('❌ File upload error');
}

// 4.a) Accept only JPEG, PNG, PDF ≤ 5MB
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($_FILES['proof_file']['tmp_name']);
$ok    = in_array($mime, ['image/jpeg','image/png','application/pdf'], true)
         && $_FILES['proof_file']['size'] <= 5*1024*1024;
if (! $ok) {
    exit('Invalid file type or size');
}

// 5) Store file safely
$dir = __DIR__ . '/../../uploads/payment_proofs/';
if (! is_dir($dir)) {
    mkdir($dir, 0755, true);
}

$cleanName = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', basename($_FILES['proof_file']['name']));
$filename  = "{$studentId}_" . time() . "_{$cleanName}";
$target    = $dir . $filename;

if (! move_uploaded_file($_FILES['proof_file']['tmp_name'], $target)) {
    exit('Failed to save file');
}

$relPath = "uploads/payment_proofs/{$filename}";

// 6) Insert into payment_proofs in a transaction
$conn->begin_transaction();
try {
    $stmt = $conn->prepare("
        INSERT INTO payment_proofs
          (student_id, file_path, payment_method, txn_id, amount, status, uploaded_at)
        VALUES (?, ?, ?, ?, ?, 'Pending', NOW())
    ");
    $stmt->bind_param('isssd', $studentId, $relPath, $method, $txn, $amount);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    @unlink($target);
    exit('DB error: ' . $e->getMessage());
}

// 7) Flash + redirect back to student_payment
$_SESSION['flash'] = 'Proof submitted—waiting for approval.';
header('Location: ../dashboard/student/index.php?page=student_payment');
exit;
