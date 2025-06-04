<?php
// File: dashboard/student/stars.php

// ─── 1) Bootstrap + “student” Guard ─────────────────────────────────────
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');

// ─── 2) Identify this student (ID was stored in session at login) ──────
$student_id = (int)($_SESSION['student_id'] ?? 0);
if ($student_id < 1) {
    header('Location: ../../login.php');
    exit;
}

// ─── 3) Early‐POST: Redeem a reward item ────────────────────────────────
// If the student clicks “Redeem” on a particular item, we process it here.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'redeem'
    && isset($_POST['cost'], $_POST['item_title'])
) {
    $cost = (int)$_POST['cost'];
    $title = trim($_POST['item_title']);

    //  a) Fetch current star count for this student
    $stmt0 = $conn->prepare("SELECT star_count FROM stars WHERE student_id = ? LIMIT 1");
    $stmt0->bind_param('i', $student_id);
    $stmt0->execute();
    $stmt0->bind_result($currentStars);
    $stmt0->fetch();
    $stmt0->close();

    if ($currentStars >= $cost) {
        //  b) Deduct the cost
        $stmt1 = $conn->prepare("
            UPDATE stars
               SET star_count = star_count - ?
             WHERE student_id = ?
        ");
        $stmt1->bind_param('ii', $cost, $student_id);
        $stmt1->execute();
        $stmt1->close();

        //  c) Record this redemption in star_history (negative stars for redemption)
        $stmt2 = $conn->prepare("
            INSERT INTO star_history
                (student_id, event_date, stars, reason)
            VALUES (?, NOW(), ?, ?)
        ");
        $minus = -abs($cost);
        $reason = "Redeemed “{$title}”";
        $stmt2->bind_param('iis', $student_id, $minus, $reason);
        $stmt2->execute();
        $stmt2->close();

        $_SESSION['flash_stars'] = "✅ You have successfully redeemed “{$title}” for {$cost} stars.";
    }
    else {
        $_SESSION['flash_stars'] = "❌ You do not have enough stars to redeem “{$title}.”";
    }

    // Redirect back to avoid form‐resubmission
    header('Location: ?page=stars');
    exit;
}

// ─── 4) Any flash message? ───────────────────────────────────────────────
$flash = $_SESSION['flash_stars'] ?? '';
unset($_SESSION['flash_stars']);


// ─── 5) Fetch current star count (if no row in `stars`, treat as 0) ─────
$stmtS = $conn->prepare("SELECT star_count FROM stars WHERE student_id = ? LIMIT 1");
$stmtS->bind_param('i', $student_id);
$stmtS->execute();
$stmtS->bind_result($starCount);
if (!$stmtS->fetch()) {
    $starCount = 0;
}
$stmtS->close();


// ─── 6) Fetch this student’s star‐history (last 20 events) ─────────────
$history = [];
$stmtH   = $conn->prepare("
    SELECT event_date AS date, stars, reason
      FROM star_history
     WHERE student_id = ?
     ORDER BY event_date DESC
     LIMIT 20
");
$stmtH->bind_param('i', $student_id);
$stmtH->execute();
$resH = $stmtH->get_result();
while ($row = $resH->fetch_assoc()) {
    $history[] = $row;
}
$stmtH->close();


?>

<?php if ($flash): ?>
  <div class="mb-4 p-3
              <?= (strpos($flash, '❌') === 0)
                   ? 'bg-red-100 border border-red-400 text-red-700'
                   : 'bg-green-100 border border-green-400 text-green-700' ?>
              rounded">
    <?= htmlspecialchars($flash) ?>
  </div>
<?php endif; ?>

<div class="mb-6">
  <h2 class="text-2xl font-semibold text-gray-800 mb-2">⭐ Star Reward System</h2>
  <div class="text-lg text-gray-700">
    You currently have <strong><?= htmlspecialchars($starCount) ?></strong> stars.
  </div>
  <button
    class="mt-2 px-3 py-1 bg-blue-600 text-white rounded hover:bg-blue-700 transition"
    type="button"
    data-bs-toggle="modal"
    data-bs-target="#starHistoryModal"
  >
    View Star History
  </button>
</div>

<h3 class="text-xl font-semibold text-gray-800 mb-4">Reward Shop</h3>
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
  <?php
  // ─── 8) Define the reward items ─────────────────────────────────────────
  $rewards = [
    [
      'title' => 'Set of Color Pencils',
      'desc'  => 'Useful for art students',
      'cost'  => 20,
      'img'   => '/student-portal/assets/IMG-20250317-WA0008.jpg',
    ],
    [
      'title' => 'Watercolor Kit',
      'desc'  => 'Encourage painting',
      'cost'  => 50,
      'img'   => '/student-portal/assets/IMG-20250317-WA0006.jpg',
    ],
    [
      'title' => 'Canvas Board (Small)',
      'desc'  => 'Boost larger projects',
      'cost'  => 30,
      'img'   => '/student-portal/assets/IMG-20250317-WA0005.jpg',
    ],
    [
      'title' => 'Premium Sketchbook',
      'desc'  => 'High-value reward',
      'cost'  => 40,
      'img'   => '/student-portal/assets/sketchbook.jpg',
    ],
  ];

  foreach ($rewards as $item):
    $canRedeem = ($starCount >= $item['cost']);
  ?>
    <div class="bg-white shadow rounded-lg overflow-hidden">
      <?php if (file_exists(__DIR__ . '/../../assets/' . basename($item['img']))): ?>
        <img
          src="<?= htmlspecialchars($item['img']) ?>"
          alt="<?= htmlspecialchars($item['title']) ?>"
          class="w-full h-40 object-cover"
        />
      <?php else: ?>
        <div class="w-full h-40 bg-gray-200 flex items-center justify-center text-gray-500">
          (image missing)
        </div>
      <?php endif; ?>

      <div class="p-4">
        <h4 class="text-lg font-semibold text-gray-800"><?= htmlspecialchars($item['title']) ?></h4>
        <p class="mt-1 text-sm text-gray-600"><?= htmlspecialchars($item['desc']) ?></p>
      </div>

      <div class="px-4 pb-4">
        <div class="mb-2 text-sm text-gray-700">
          <strong><?= htmlspecialchars($item['cost']) ?> Stars</strong>
        </div>
        <form method="POST" action="?page=stars">
          <input type="hidden" name="action" value="redeem" />
          <input type="hidden" name="cost" value="<?= (int)$item['cost'] ?>" />
          <input type="hidden" name="item_title" value="<?= htmlspecialchars($item['title']) ?>" />
          <button
            type="submit"
            <?= $canRedeem ? '' : 'disabled' ?>
            class="
              w-full text-center px-3 py-2 rounded 
              <?= $canRedeem
                   ? 'bg-green-600 text-white hover:bg-green-700'
                   : 'bg-gray-300 text-gray-500 cursor-not-allowed' ?>
              transition
            "
          >
            <?= $canRedeem ? 'Redeem' : 'Insufficient Stars' ?>
          </button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
</div>


<!-- ───────────────────────────────────────────────────────────────────────────
     Star History Modal (Bootstrap 5) – lists last 20 events from star_history
────────────────────────────────────────────────────────────────────────────── -->
<div
  class="modal fade"
  id="starHistoryModal"
  tabindex="-1"
  aria-labelledby="starHistoryModalLabel"
  aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="starHistoryModalLabel">Why You Earned / Spent Stars</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <?php if (count($history)): ?>
          <div class="overflow-x-auto">
            <table class="min-w-full bg-white rounded-lg overflow-hidden">
              <thead class="bg-gray-50">
                <tr>
                  <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Date
                  </th>
                  <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Stars
                  </th>
                  <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    Reason
                  </th>
                </tr>
              </thead>
              <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($history as $h): ?>
                  <tr>
                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700">
                      <?= htmlspecialchars(date('d M Y', strtotime($h['date']))) ?>
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap text-sm <?= ($h['stars'] > 0) ? 'text-green-700' : 'text-red-700' ?>">
                      <?= ($h['stars']>0 ? '+':'') . htmlspecialchars($h['stars']) ?>
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700">
                      <?= htmlspecialchars($h['reason']) ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <p class="text-center text-gray-600">No star history available.</p>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button 
          type="button" 
          class="px-4 py-2 bg-gray-500 text-white rounded hover:bg-gray-600 transition" 
          data-bs-dismiss="modal"
        >
          Close
        </button>
      </div>
    </div>
  </div>
</div>

