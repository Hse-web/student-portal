<?php
// File: actions/upload_proof.php

// 1) Session, role & DB
require_once __DIR__ . '/../config/session.php';
require_role('student');
require_once __DIR__ . '/../config/db.php'; // provides $conn

// 2) Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// 3) CSRF check (matches name="csrf_token")
if (empty($_POST['csrf_token']) 
    || !verify_csrf_token($_POST['csrf_token'])
) {
    exit('CSRF token mismatch');
}

// 4) Identify student
$studentId = (int)($_SESSION['student_id'] ?? 0);

// 5) Payment method & txn
$method = trim($_POST['payment_method']  ?? '');
$txn    = trim($_POST['txn_id']          ?? '');

// 6) Validate file upload
if (empty($_FILES['proof_file']) 
    || $_FILES['proof_file']['error'] !== UPLOAD_ERR_OK
) {
    exit('❌ File upload error');
}

// Limit to JPEG, PNG, PDF ≤ 5 MB
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($_FILES['proof_file']['tmp_name']);
$ok    = in_array($mime, ['image/jpeg','image/png','application/pdf'], true)
         && $_FILES['proof_file']['size'] <= 5*1024*1024;
if (!$ok) {
    exit('Invalid file type or size');
}

// 7) Store file safely
$dir = __DIR__ . '/../uploads/payment_proofs/';
if (!is_dir($dir)) mkdir($dir, 0755, true);

$clean = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', basename($_FILES['proof_file']['name']));
$fname = "{$studentId}_" . time() . "_{$clean}";
$target = $dir . $fname;

if (!move_uploaded_file($_FILES['proof_file']['tmp_name'], $target)) {
    exit('Failed to save file');
}

$relPath = "uploads/payment_proofs/{$fname}";

// 8) Insert DB record in a transaction
$conn->begin_transaction();
try {
    $stmt = $conn->prepare("
        INSERT INTO payment_proofs
          (student_id, file_path, payment_method, txn_id, status, uploaded_at)
        VALUES (?, ?, ?, ?, 'Pending', NOW())
    ");
    $stmt->bind_param('isss', $studentId, $relPath, $method, $txn);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    @unlink($target);
    exit('DB error: ' . $e->getMessage());
}

// 9) Flash + redirect back into the student dashboard
$_SESSION['flash'] = 'proof_submitted';
header('Location: ../dashboard/student/index.php?page=student_payment');
exit;
