<?php
// File: actions/upload_proof.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/../config/bootstrap.php';
require_role('student');
require_once __DIR__ . '/../config/db.php';

require_once __DIR__ . '/../dashboard/includes/functions.php';
// 1) Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// 2) CSRF check
if (empty($_POST['csrf_token']) || ! verify_csrf_token($_POST['csrf_token'])) {
    exit('CSRF token mismatch');
}

// 3) Grab and validate inputs
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

// 4) Prevent duplicate pending proofs
$stmt = $conn->prepare("
  SELECT COUNT(*) 
    FROM payment_proofs
   WHERE student_id = ?
     AND status     = 'Pending'
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($pendingCount);
$stmt->fetch();
$stmt->close();
if ($pendingCount > 0) {
    exit('❌ You already have a pending proof awaiting approval.');
}

// 5) Validate file upload
if (empty($_FILES['proof_file']) || $_FILES['proof_file']['error'] !== UPLOAD_ERR_OK) {
    exit('❌ File upload error');
}

// 5.a) Only accept JPEG, PNG, or PDF files up to 5MB
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($_FILES['proof_file']['tmp_name']);
$ok    = in_array($mime, ['image/jpeg','image/png','application/pdf'], true)
         && $_FILES['proof_file']['size'] <= 5 * 1024 * 1024;
if (! $ok) {
    exit('Invalid file type or size');
}

// 6) Move file to secure location
$dir = __DIR__ . '/../uploads/payment_proofs/';
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

// 7) Insert proof record in database
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

// 8) (Optional) Notify admin about new proof (admin_id=1 assumed)
notify_admin(1, 'New Payment Proof Uploaded', "Student #{$studentId} uploaded a payment proof for ₹{$amount}. Please review.");

// 9) Set success message for student and redirect back to payment page
$_SESSION['flash'] = 'Proof submitted — waiting for approval.';
$_SESSION['just_uploaded_proof'] = true;
header('Location: ../dashboard/student/index.php?page=student_payment');
exit;
?>
