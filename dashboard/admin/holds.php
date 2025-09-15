<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');

require_once __DIR__ . '/../includes/functions.php';

$flash    = get_flash();
$students = list_students_min($conn, 1000);
$recent   = list_recent_holds($conn, 20);

function badge_class(string $status): string {
    $s = strtolower($status);
    return match ($s) {
        'approved'  => 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200',
        'pending'   => 'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-200',
        'rejected'  => 'bg-rose-50 text-rose-700 ring-1 ring-inset ring-rose-200',
        'cancelled' => 'bg-slate-100 text-slate-700 ring-1 ring-inset ring-slate-200',
        default     => 'bg-slate-100 text-slate-700 ring-1 ring-inset ring-slate-200',
    };
}

function alert_classes(string $type): string {
    return match ($type) {
        'success' => 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200',
        'warning' => 'bg-amber-50 text-amber-800 ring-1 ring-amber-200',
        'danger'  => 'bg-rose-50 text-rose-800 ring-1 ring-rose-200',
        default   => 'bg-slate-50 text-slate-800 ring-1 ring-slate-200',
    };
}
?>
<div class="mx-auto max-w-7xl p-6">
  <div class="mb-6 flex items-end justify-between gap-4">
    <div>
      <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Billing Holds</h1>
      <p class="mt-1 text-sm text-slate-500">Create holds for absent students and review recent entries.</p>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="mb-6 rounded-lg px-4 py-3 text-sm <?=
         htmlspecialchars(alert_classes($flash['type'])) ?>">
      <?= htmlspecialchars($flash['msg']) ?>
    </div>
  <?php endif; ?>

  <!-- Create Hold -->
  <div class="mb-8 rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="border-b border-slate-200 px-5 py-4">
      <h2 class="text-base font-medium text-slate-900">Create Hold</h2>
      <p class="mt-1 text-sm text-slate-500">Mark one or more months as on hold for a student.</p>
    </div>

    <div class="px-5 py-5">
      <form action="/artovue/actions/create_hold.php" method="post" class="grid grid-cols-1 gap-5 md:grid-cols-12">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token()) ?>">

        <!-- Student -->
        <div class="md:col-span-5">
          <label class="mb-1 block text-sm font-medium text-slate-700">
            Student <span class="text-rose-600">*</span>
          </label>
          <select name="student_id"
                  class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none ring-0 transition focus:border-slate-400"
                  required>
            <option value="" disabled selected>Choose student…</option>
            <?php foreach ($students as $s): ?>
              <option value="<?= (int)$s['id'] ?>">
                <?= htmlspecialchars($s['name']) ?> (ID: <?= (int)$s['id'] ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Start month -->
        <div class="md:col-span-3">
          <label class="mb-1 block text-sm font-medium text-slate-700">
            Start month <span class="text-rose-600">*</span>
          </label>
          <input type="month" name="start_ym"
                 class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-slate-400"
                 required>
        </div>

        <!-- End month -->
        <div class="md:col-span-3">
          <label class="mb-1 block text-sm font-medium text-slate-700">End month (optional)</label>
          <input type="month" name="end_ym"
                 class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-slate-400">
          <p class="mt-1 text-xs text-slate-500">Leave blank to hold a single month.</p>
        </div>

        <!-- Status -->
        <div class="md:col-span-3">
          <label class="mb-1 block text-sm font-medium text-slate-700">Status</label>
          <select name="status"
                  class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-slate-400">
            <option value="Approved" selected>Approved (immediate)</option>
            <option value="Pending">Pending (review)</option>
            <option value="Rejected">Rejected</option>
            <option value="Cancelled">Cancelled</option>
          </select>
        </div>

        <!-- Reason -->
        <div class="md:col-span-9">
          <label class="mb-1 block text-sm font-medium text-slate-700">Reason</label>
          <input type="text" name="reason" maxlength="255"
                 placeholder="Break / Travel / Exam…"
                 class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 outline-none placeholder:text-slate-400 focus:border-slate-400">
        </div>

        <!-- Submit -->
        <div class="md:col-span-12">
          <button type="submit"
                  class="inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-slate-800">
            Create Hold
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Recent Holds -->
  <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="border-b border-slate-200 px-5 py-4">
      <h2 class="text-base font-medium text-slate-900">Recent Holds</h2>
      <p class="mt-1 text-sm text-slate-500">Last 20 entries</p>
    </div>

    <div class="px-5 py-5">
      <?php if (empty($recent)): ?>
        <div class="flex items-center justify-center rounded-xl border border-dashed border-slate-300 px-6 py-10 text-center">
          <div>
            <div class="text-sm font-medium text-slate-900">No holds yet</div>
            <p class="mt-1 text-sm text-slate-500">New entries will appear here after you create them.</p>
          </div>
        </div>
      <?php else: ?>
        <div class="overflow-x-auto">
          <table class="min-w-full text-left text-sm">
            <thead class="text-xs uppercase tracking-wider text-slate-500">
              <tr>
                <th class="px-3 py-2">ID</th>
                <th class="px-3 py-2">Student</th>
                <th class="px-3 py-2">Month</th>
                <th class="px-3 py-2">Range</th>
                <th class="px-3 py-2">Status</th>
                <th class="px-3 py-2">Reason</th>
                <th class="px-3 py-2">Created</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
              <?php foreach ($recent as $r): ?>
                <tr class="hover:bg-slate-50">
                  <td class="px-3 py-3 text-slate-700"><?= (int)$r['id'] ?></td>
                  <td class="px-3 py-3 text-slate-900">
                    <?= htmlspecialchars($r['student_name'] ?? '') ?>
                    <span class="text-slate-400"> (<?= (int)$r['student_id'] ?>)</span>
                  </td>
                  <td class="px-3 py-3 text-slate-700"><?= htmlspecialchars($r['hold_month']) ?></td>
                  <td class="px-3 py-3 text-slate-700">
                    <?= htmlspecialchars($r['start_month']) ?> <span class="text-slate-400">→</span> <?= htmlspecialchars($r['end_month']) ?>
                  </td>
                  <td class="px-3 py-3">
                    <?php $cls = badge_class((string)$r['status']); ?>
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium <?= $cls ?>">
                      <?= htmlspecialchars($r['status']) ?>
                    </span>
                  </td>
                  <td class="px-3 py-3 text-slate-700"><?= htmlspecialchars($r['reason'] ?? '') ?></td>
                  <td class="px-3 py-3 text-slate-700"><?= htmlspecialchars($r['created_at']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
