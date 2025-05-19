<?php
require_once __DIR__ . '/../../config/session.php';
require_role('admin');
require_once __DIR__ . '/../../config/db.php';

// Show flash messages
if (!empty($_SESSION['flash_success'])) {
    echo '<div class="alert alert-success mx-3">'
       . htmlspecialchars($_SESSION['flash_success'])
       . '</div>';
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    echo '<div class="alert alert-danger mx-3">'
       . htmlspecialchars($_SESSION['flash_error'])
       . '</div>';
    unset($_SESSION['flash_error']);
}

// Fetch students
$res = $conn->query("
  SELECT 
    s.id, s.name, s.email, s.phone,
    c.name AS centre_name, s.group_name
  FROM students s
  JOIN centres c ON c.id = s.centre_id
  ORDER BY s.name
");
$students = $res->fetch_all(MYSQLI_ASSOC);

// Fetch plans once
$plans = [];
$res2 = $conn->query("SELECT id, duration_months FROM payment_plans ORDER BY duration_months");
while ($r = $res2->fetch_assoc()) {
    $plans[] = $r;
}
// Helper to label plans
function planTitle(int $d): string {
    return match ($d) {
        1        => 'Regular Works',
        2,3      => 'Core Works',
        6        => 'Pro Works',
        default  => "{$d}-Month Plan",
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Manage Students</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
        rel="stylesheet">
</head>
<body class="bg-light">
  <div class="container py-4">
    <h2 class="section-header">Manage Students</h2>

    <div class="d-flex justify-content-end mb-3">
      <a href="?page=add_student" class="btn btn-primary me-2">
        <i class="bi bi-person-plus me-1"></i> Add Student
      </a>
    </div>

    <!-- bulk-delete form wraps entire table -->
    <form 
      action="delete_bulk.php" 
      method="post"
      onsubmit="return confirm('Really delete selected students?')"
    >
      <div class="table-responsive">
        <table class="table table-striped align-middle">
          <thead class="table-dark">
            <tr>
              <th><input type="checkbox" id="selectAll"></th>
              <th>#</th><th>Name</th><th>Email</th><th>Phone</th>
              <th>Centre</th><th>Group</th><th>Plan</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($students)): ?>
              <tr><td colspan="9" class="text-center">No students found.</td></tr>
            <?php else: foreach ($students as $stu): ?>
              <?php
                $stmt = $conn->prepare("
                  SELECT p.duration_months
                    FROM student_subscriptions ss
                    JOIN payment_plans p ON p.id = ss.plan_id
                   WHERE ss.student_id = ?
                   ORDER BY ss.subscribed_at DESC
                   LIMIT 1
                ");
                $stmt->bind_param('i', $stu['id']);
                $stmt->execute();
                $stmt->bind_result($dur);
                $hasSub = $stmt->fetch();
                $stmt->close();
              ?>
              <tr>
                <td>
                  <input 
                    type="checkbox" 
                    class="selectBox" 
                    name="student_ids[]" 
                    value="<?= $stu['id'] ?>"
                  >
                </td>
                <td><?= htmlspecialchars($stu['id']) ?></td>
                <td><?= htmlspecialchars($stu['name']) ?></td>
                <td><?= htmlspecialchars($stu['email']) ?></td>
                <td><?= htmlspecialchars($stu['phone']) ?></td>
                <td><?= htmlspecialchars($stu['centre_name']) ?></td>
                <td><?= htmlspecialchars($stu['group_name']) ?></td>
                <td>
                  <?php if ($hasSub): ?>
                    <?= htmlspecialchars(planTitle($dur)) ?>
                  <?php else: ?>
                    <form action="../actions/subscribe_plan.php" method="post" class="d-flex">
                      <input type="hidden" name="csrf_token" 
                             value="<?= htmlspecialchars(generate_csrf_token()) ?>">
                      <input type="hidden" name="student_id" 
                             value="<?= htmlspecialchars($stu['id']) ?>">
                      <select name="plan_id" class="form-select form-select-sm me-2" required>
                        <option value="" disabled selected>Select Plan</option>
                        <?php foreach ($plans as $p): ?>
                          <option value="<?= $p['id'] ?>">
                            <?= htmlspecialchars(planTitle((int)$p['duration_months'])) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <button type="submit" class="btn btn-sm btn-primary">
                        Subscribe
                      </button>
                    </form>
                  <?php endif; ?>
                </td>
                <td>
                  <a href="edit_student.php?id=<?= $stu['id'] ?>"
                     class="btn btn-sm btn-outline-primary">Edit</a>
                  <a href="delete_student.php?id=<?= $stu['id'] ?>"
                     class="btn btn-sm btn-outline-danger"
                     onclick="return confirm('Really delete this student?')">
                     Delete
                  </a>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <button type="submit" class="btn btn-danger mb-4">
        <i class="bi bi-trash me-1"></i> Delete Selected
      </button>
    </form>
  </div>

  <script>
    document.getElementById('selectAll').addEventListener('change', function() {
      document.querySelectorAll('.selectBox')
        .forEach(cb => cb.checked = this.checked);
    });
  </script>
</body>
</html>
