<?php
// actions/approve_payment_proof.php
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session.php';
if (empty($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login/index.php');
    exit();
}

$id  = (int)($_GET['id']  ?? 0);
$act = ($_GET['a'] === 'approve') ? 'Approved' : 'Rejected';
if (!$id) {
    die('Invalid proof ID');
}

// 1) update proof status
$stmt = $conn->prepare("
  UPDATE payment_proofs
     SET status = ?
   WHERE id = ?
");
$stmt->bind_param('si', $act, $id);
$stmt->execute();
$stmt->close();

if ($act === 'Approved') {
    // fetch student_id
    $res = $conn->query("SELECT student_id FROM payment_proofs WHERE id = $id");
    $sid = $res->fetch_assoc()['student_id'];

    // mark their payments row Paid, zero due, set paid_at=NOW()
    $upd = $conn->prepare("
      UPDATE payments
         SET status     = 'Paid',
             amount_due = 0,
             paid_at    = NOW()
       WHERE student_id = ?
    ");
    $upd->bind_param('i', $sid);
    $upd->execute();
    $upd->close();
}

header('Location: ../admin/payment_proofs_list.php');
exit;
