<?php
// File: dashboard/admin/attendance.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');

// 1) Load your helper (must define create_notification)
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../../config/db.php';

// 2) CSRF token
$csrf = generate_csrf_token();

// 3) Handle POST: save attendance & notify students
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['status'])) {
    if (! verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }

    $centre_id    = (int)$_POST['centre_id'];
    $selectedDate = $_POST['selected_date'];

    // a) Delete old records for that date & centre
    $del = $conn->prepare("
        DELETE a
          FROM attendance a
          JOIN students s ON s.id = a.student_id
         WHERE s.centre_id = ? 
           AND a.`date` = ?
    ");
    $del->bind_param('is', $centre_id, $selectedDate);
    $del->execute();
    $del->close();

    // b) Insert new & bucket by status
    $ins = $conn->prepare("
        INSERT INTO attendance(student_id, `date`, status)
        VALUES (?, ?, ?)
    ");
    $lists = [
      'Present'      => [],
      'Absent'       => [],
      'Compensation' => [],
    ];
    foreach ($_POST['status'] as $stu_id => $st) {
        if (! isset($lists[$st])) {
            continue;
        }
        $sid = (int)$stu_id;
        $ins->bind_param('iss', $sid, $selectedDate, $st);
        $ins->execute();
        $lists[$st][] = $sid;
    }
    $ins->close();

    // c) Notify each group
    foreach ($lists as $status => $ids) {
        if (empty($ids)) continue;
        switch ($status) {
            case 'Present':
                $title = 'Attendance Update';
                $msg   = "You were marked *present* on {$selectedDate}.";
                break;
            case 'Absent':
                $title = 'Attendance Alert';
                $msg   = "You were marked *absent* on {$selectedDate}.";
                break;
            case 'Compensation':
                $title = 'Attendance Compensation';
                $msg   = "A compensation was recorded for you on {$selectedDate}.";
                break;
        }
        create_notification($conn, $ids, $title, $msg);
    }

    // d) Redirect to avoid duplicate submissions
    header("Location:?page=attendance"
         . "&centre_id={$centre_id}"
         . "&month=" . urlencode($_POST['month'])
         . "&selected_date=" . urlencode($selectedDate));
    exit;
}

// ────────────────────────────────────────────────────────────────────────────
// 4) Calendar & data loading (unchanged)
// ────────────────────────────────────────────────────────────────────────────

$month = $_GET['month'] ?? date('Y-m');
try {
    $dt = new DateTime("{$month}-01");
} catch (Throwable $e) {
    $dt    = new DateTime();
    $month = $dt->format('Y-m');
}
$prev   = (clone $dt)->modify('-1 month')->format('Y-m');
$next   = (clone $dt)->modify('+1 month')->format('Y-m');
$label  = $dt->format('F Y');
$days   = (int)$dt->format('t');
$start  = (int)$dt->format('w');

$cells = array_pad([], $start, null);
for ($d = 1; $d <= $days; $d++) {
    $cells[] = $d;
}
while (count($cells) % 7) {
    $cells[] = null;
}
$weeks = array_chunk($cells, 7);

$centreList = $conn
    ->query("SELECT id,name FROM centres ORDER BY name")
    ->fetch_all(MYSQLI_ASSOC);

$centre_id = isset($_GET['centre_id'])
           ? (int)$_GET['centre_id']
           : ($centreList[0]['id'] ?? 0);

$stmt = $conn->prepare(
    "SELECT id,name FROM students WHERE centre_id=? ORDER BY name"
);
$stmt->bind_param('i', $centre_id);
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$selectedDate = $_GET['selected_date'] ?? date('Y-m-d');

$monthStatuses = [];
$stmt = $conn->prepare("
    SELECT a.`date`, a.status
      FROM attendance a
      JOIN students s ON s.id = a.student_id
     WHERE s.centre_id=? 
       AND DATE_FORMAT(a.`date`,'%Y-%m')=?
");
$stmt->bind_param('is', $centre_id, $month);
$stmt->execute();
foreach ($stmt->get_result() as $r) {
    $monthStatuses[$r['date']] = $r['status'];
}
$stmt->close();

$dayStatuses = [];
if ($selectedDate) {
    $ids = array_column($students,'id') ?: [0];
    $in  = implode(',', $ids);
    $stmt = $conn->prepare("
        SELECT student_id,status
          FROM attendance
         WHERE `date`=? AND student_id IN({$in})
    ");
    $stmt->bind_param('s', $selectedDate);
    $stmt->execute();
    foreach ($stmt->get_result() as $r) {
        $dayStatuses[$r['student_id']] = $r['status'];
    }
    $stmt->close();
}

$thisYear  = date('Y');
$countStmt = $conn->prepare("
    SELECT COUNT(*) 
      FROM attendance 
     WHERE student_id=? 
       AND status IN('Present','Compensation') 
       AND YEAR(`date`)=?
");
$ytd = [];
foreach ($students as $s) {
    $countStmt->bind_param('ii', $s['id'], $thisYear);
    $countStmt->execute();
    $countStmt->bind_result($n);
    $countStmt->fetch();
    $ytd[$s['id']] = $n;
}
$countStmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <title>Admin – Attendance</title>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <script src="https://cdn.tailwindcss.com"></script>
  <link 
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" 
    rel="stylesheet"
  />
</head>
<body class="bg-gray-100 min-h-screen">

  <div class="container mx-auto px-4 py-6 space-y-6">
    <!-- Month/Centre Nav -->
    <form method="get" class="flex flex-wrap items-center gap-2 text-sm">
      <input type="hidden" name="page" value="attendance">
      <input type="hidden" name="selected_date" value="<?= htmlspecialchars($selectedDate) ?>">

      <button type="submit" name="month" value="<?= $prev ?>"
              class="px-2 py-1 bg-gray-200 rounded">←</button>
      <button type="submit" name="month" value="<?= $next ?>"
              class="px-2 py-1 bg-gray-200 rounded">→</button>

      <span class="mx-2 font-semibold"><?= htmlspecialchars($label) ?></span>

      <select name="centre_id" class="border rounded px-2 py-1"
              onchange="this.form.submit()">
        <?php foreach ($centreList as $c): ?>
          <option value="<?= $c['id'] ?>"
            <?= $c['id'] == $centre_id ? 'selected' : '' ?>>
            <?= htmlspecialchars($c['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select name="month" class="border rounded px-2 py-1"
              onchange="this.form.submit()">
        <?php for ($m = 1; $m <= 12; $m++):
          $dt->setDate((int)$dt->format('Y'), $m, 1);
          $val = $dt->format('Y-m');
        ?>
          <option value="<?= $val ?>"
            <?= $val === $month ? 'selected' : '' ?>>
            <?= $dt->format('M Y') ?>
          </option>
        <?php endfor; ?>
      </select>
    </form>

    <!-- Calendar Grid -->
    <div class="overflow-x-auto bg-white rounded-lg shadow">
      <table class="min-w-full text-center text-sm">
        <thead class="bg-gray-100">
          <tr>
            <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $wd): ?>
              <th class="p-2 font-medium text-gray-600"><?= $wd ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($weeks as $week): ?>
            <tr>
              <?php foreach ($week as $d): ?>
                <?php if (is_null($d)): ?>
                  <td class="p-2"></td>
                <?php else:
                  $dayStr = sprintf('%s-%02d', $month, $d);
                  $cls    = $dayStr === $selectedDate ? 'bg-purple-100' : '';
                  $dot    = match($monthStatuses[$dayStr] ?? '') {
                    'Present'      => 'bg-green-500',
                    'Absent'       => 'bg-red-500',
                    'Compensation' => 'bg-yellow-300',
                    default        => '',
                  };
                ?>
                  <td class="relative p-2 <?= $cls ?>">
                    <span><?= $d ?></span>
                    <?php if ($dot): ?>
                      <span class="absolute bottom-1 right-1 w-4 h-4 rounded-full <?= $dot ?>"></span>
                    <?php endif; ?>
                  </td>
                <?php endif; ?>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Attendance Form -->
    <div class="bg-white rounded-lg shadow p-6">
      <h2 class="text-lg font-semibold mb-4">
        Attendance on <?= htmlspecialchars($selectedDate) ?>
      </h2>
      <form method="post" class="space-y-4">
        <input type="hidden" name="csrf_token"   value="<?= $csrf ?>">
        <input type="hidden" name="centre_id"     value="<?= $centre_id ?>">
        <input type="hidden" name="month"         value="<?= htmlspecialchars($month) ?>">
        <input type="hidden" name="selected_date" value="<?= htmlspecialchars($selectedDate) ?>">

        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-gray-50">
              <tr>
                <th class="p-2 text-left">Student</th>
                <th class="p-2 text-center">Taken YTD</th>
                <th class="p-2 text-left">Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($students as $s):
                $cur = $dayStatuses[$s['id']] ?? '';
              ?>
                <tr class="border-t">
                  <td class="p-2"><?= htmlspecialchars($s['name']) ?></td>
                  <td class="p-2 text-center"><?= $ytd[$s['id']] ?></td>
                  <td class="p-2">
                    <select name="status[<?= $s['id'] ?>]"
                            class="w-full border rounded p-1">
                      <option value="">–</option>
                      <option value="Present"
                        <?= $cur === 'Present' ? 'selected' : '' ?>>
                        Present
                      </option>
                      <option value="Absent"
                        <?= $cur === 'Absent' ? 'selected' : '' ?>>
                        Absent
                      </option>
                      <option value="Compensation"
                        <?= $cur === 'Compensation' ? 'selected' : '' ?>>
                        Compensation
                      </option>
                    </select>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <button type="submit"
                class="mt-4 w-full bg-purple-600 text-white py-2 rounded-lg">
          Save for <?= htmlspecialchars($selectedDate) ?>
        </button>
      </form>
    </div>
  </div>

  <script 
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js">
  </script>
</body>
</html>
