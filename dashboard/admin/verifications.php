<?php
// dashboard/verifications.php
if (empty($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true) {
    header('Location: ../login/index.php');
    exit();
}

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/db.php';
$studentId = (int)$_SESSION['student_id'];

// Flash message
$message = $_SESSION['verification_message'] ?? '';
unset($_SESSION['verification_message']);

// Fetch existing record
$stmt = $conn->prepare("
    SELECT photo_path, id_path, status, uploaded_at, verified_at
      FROM student_verifications
     WHERE student_id = ?
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($photoPath, $idPath, $status, $uploadedAt, $verifiedAt);
$hasRecord = $stmt->fetch();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Student Verification</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
  <div class="container py-5">
    <h2 class="mb-4">Student Verification</h2>

    <?php if ($message): ?>
      <div class="alert alert-info"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <?php if ($hasRecord): ?>
      <div class="mb-4">
        <p><strong>Status:</strong>
          <?php if ($status === 'verified'): ?>
            <span class="badge bg-success">Verified</span>
            <small class="text-muted">(on <?php echo htmlspecialchars($verifiedAt); ?>)</small>
          <?php elseif ($status === 'rejected'): ?>
            <span class="badge bg-danger">Rejected</span>
          <?php else: ?>
            <span class="badge bg-warning text-dark">Pending</span>
          <?php endif; ?>
        </p>
        <?php if ($photoPath): ?>
          <div class="mb-3">
            <strong>Photo:</strong><br>
            <img src="../<?php echo htmlspecialchars($photoPath); ?>" class="img-thumbnail" style="max-width:180px;">
          </div>
        <?php endif; ?>
        <?php if ($idPath): ?>
          <div class="mb-3">
            <strong>ID Document:</strong><br>
            <a href="../<?php echo htmlspecialchars($idPath); ?>" target="_blank">View / Download</a>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <form 
      method="POST" 
      action="../action/upload_verifications.php" 
      enctype="multipart/form-data" 
      class="bg-white p-4 border rounded"
    >
      <div class="mb-3">
        <label class="form-label">Profile Photo</label>
        <input type="file" name="profile_photo" class="form-control" accept="image/*" required>
      </div>
      <div class="mb-3">
        <label class="form-label">Government ID (PNG/JPG/PDF)</label>
        <input type="file" name="govt_id" class="form-control" accept="image/*,application/pdf" required>
      </div>
      <button type="submit" class="btn btn-primary">Upload Documents</button>
    </form>

    <a href="student.php" class="btn btn-link mt-3">&larr; Back to Dashboard</a>
  </div>
</body>
</html>
