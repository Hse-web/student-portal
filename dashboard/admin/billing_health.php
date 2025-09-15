<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');
require_once __DIR__ . '/../includes/functions.php';

// Fetch students (adjust WHERE if you have an "inactive" flag)
$students = [];
$res = $conn->query("SELECT id, name FROM students ORDER BY name ASC");
while ($row = $res->fetch_assoc()) $students[] = $row;

$rows = [];
foreach ($students as $s) {
    $sid = (int)$s['id'];
    $name = (string)$s['name'];

    $due = compute_student_due($conn, $sid); // uses your exact logic
    $nextDue = $due['due_date'] ?? null;     // 'Y-m-d' or null

    // Window status (only meaningful if we have a due date)
    $window = ($nextDue && is_payment_window_open($nextDue)) ? 'OPEN' : 'CLOSED';

    // Hold flag for the **next due month**
    $holdFlag = 'No';
    if ($nextDue) {
        $yy = (int)substr($nextDue,0,4);
        $mm = (int)substr($nextDue,5,2);
        $holdFlag = is_month_on_hold($conn, $sid, $yy, $mm) ? 'Yes' : 'No';
    }

    $rows[] = [
        'id'       => $sid,
        'name'     => $name,
        'next_due' => $nextDue ?: '—',
        'window'   => $window,
        'hold'     => $holdFlag,
    ];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Billing Health (preview)</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/tailwindcss@3.4.1/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-slate-50">
  <div class="max-w-7xl mx-auto p-6">
    <h1 class="text-2xl font-bold mb-4">Billing Health (preview)</h1>

    <div class="flex gap-3 items-center mb-4">
      <form method="get" class="flex gap-2">
        <input name="q" type="text" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>"
               placeholder="Search name / ID…" class="border rounded px-3 py-2 w-64">
        <select name="window" class="border rounded px-3 py-2">
          <?php $w = $_GET['window'] ?? ''; ?>
          <option value="">All windows</option>
          <option value="OPEN"   <?= $w==='OPEN'?'selected':'' ?>>OPEN</option>
          <option value="CLOSED" <?= $w==='CLOSED'?'selected':'' ?>>CLOSED</option>
        </select>
        <select name="hold" class="border rounded px-3 py-2">
          <?php $h = $_GET['hold'] ?? ''; ?>
          <option value="">All holds</option>
          <option value="Yes" <?= $h==='Yes'?'selected':'' ?>>Hold: Yes</option>
          <option value="No"  <?= $h==='No'?'selected':''  ?>>Hold: No</option>
        </select>
        <button class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded">Filter</button>
        <a href="billing_health.php" class="px-4 py-2 border rounded">Reset</a>
      </form>
      <form method="post" action="" onsubmit="return exportCsv();">
        <button type="submit" class="px-4 py-2 border rounded">Export CSV</button>
      </form>
    </div>

    <?php
      // Simple in-memory filtering
      $q = strtolower(trim($_GET['q'] ?? ''));
      $wf = $_GET['window'] ?? '';
      $hf = $_GET['hold'] ?? '';
      $data = array_filter($rows, function($r) use ($q,$wf,$hf) {
        if ($q !== '') {
          if (strpos(strtolower($r['name']), $q)===false && strpos((string)$r['id'], $q)===false) return false;
        }
        if ($wf !== '' && $r['window'] !== $wf) return false;
        if ($hf !== '' && $r['hold'] !== $hf) return false;
        return true;
      });
    ?>

    <div class="overflow-x-auto bg-white rounded-xl shadow">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-100 text-slate-700 uppercase text-xs">
          <tr>
            <th class="text-left px-4 py-3">ID</th>
            <th class="text-left px-4 py-3">Name</th>
            <th class="text-left px-4 py-3">Next due</th>
            <th class="text-left px-4 py-3">Window</th>
            <th class="text-left px-4 py-3">Hold?</th>
          </tr>
        </thead>
        <tbody class="divide-y">
          <?php foreach ($data as $r): ?>
            <tr>
              <td class="px-4 py-2"><?= (int)$r['id'] ?></td>
              <td class="px-4 py-2"><?= htmlspecialchars($r['name']) ?></td>
              <td class="px-4 py-2"><?= htmlspecialchars($r['next_due']) ?></td>
              <td class="px-4 py-2">
                <?php if ($r['window']==='OPEN'): ?>
                  <span class="px-2 py-1 text-xs rounded bg-emerald-100 text-emerald-700">OPEN</span>
                <?php else: ?>
                  <span class="px-2 py-1 text-xs rounded bg-slate-200 text-slate-700">CLOSED</span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-2">
                <?php if ($r['hold']==='Yes'): ?>
                  <span class="px-2 py-1 text-xs rounded bg-amber-100 text-amber-800">Yes</span>
                <?php else: ?>
                  <span class="px-2 py-1 text-xs rounded bg-slate-200 text-slate-700">No</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($data)): ?>
            <tr><td colspan="5" class="px-4 py-6 text-center text-slate-500">No results</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <script>
    function exportCsv() {
      const rows = <?= json_encode($rows, JSON_UNESCAPED_UNICODE) ?>;
      const headers = ['ID','Name','Next due','Window','Hold?'];
      const lines = [headers.join(',')].concat(
        rows.map(r => [r.id, `"${(r.name||'').replaceAll('"','""')}"`, r.next_due, r.window, r.hold].join(','))
      );
      const blob = new Blob([lines.join('\n')], {type:'text/csv;charset=utf-8;'});
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'billing-health.csv';
      a.click();
      return false;
    }
  </script>
</body>
</html>
