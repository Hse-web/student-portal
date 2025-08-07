<?php
// File: dashboard/student/stars.php
// ─── COMING SOON SWITCH ───────────────────────────────────────────────────
// Flip this to true whenever you want the “Coming Soon” placeholder active.
session_start();
define('SHOW_STARS_COMING_SOON', true);
// only bypass for student #42
$devOverride = ($_SESSION['student_id'] ?? 0) === 484;

if (SHOW_STARS_COMING_SOON && ! $devOverride) {
  http_response_code(503);
  header('Retry-After:3600');
  include __DIR__ . '/new.php';
  exit;
}
// ─── Bootstrap + “student” Guard ─────────────────────────────────────
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');
require_once __DIR__ . '/../includes/functions.php';

$student_id = (int)($_SESSION['student_id'] ?? 0);
if ($student_id < 1) {
    header('Location: ../../login.php');
    exit;
}

// ─── Early-POST: Redeem a reward ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'redeem') {
    $cost  = (int)$_POST['cost'];
    $title = trim($_POST['item_title']);

    // fetch current stars
    $stmt = $conn->prepare("SELECT star_count FROM stars WHERE student_id=?");
    $stmt->bind_param('i', $student_id);
    $stmt->execute();
    $stmt->bind_result($currentStars);
    $stmt->fetch();
    $stmt->close();

    if ($currentStars >= $cost) {
        // deduct
        $u = $conn->prepare("UPDATE stars SET star_count = star_count - ? WHERE student_id=?");
        $u->bind_param('ii', $cost, $student_id);
        $u->execute();
        $u->close();
        // record
        $i = $conn->prepare("
          INSERT INTO star_history (student_id,event_date,stars,reason)
          VALUES (?, NOW(), ?, ?)
        ");
        $minus  = -abs($cost);
        $reason = "Redeemed “{$title}”";
        $i->bind_param('iis', $student_id, $minus, $reason);
        $i->execute();
        $i->close();
        $_SESSION['flash_stars'] = "✅ You redeemed “{$title}” for {$cost} stars.";
    } else {
        $_SESSION['flash_stars'] = "❌ Not enough stars for “{$title}.”";
    }
    header('Location: ?page=stars');
    exit;
}

// ─── Flash & current balance ─────────────────────────────────────────
$flash = $_SESSION['flash_stars'] ?? '';
unset($_SESSION['flash_stars']);

$stmt = $conn->prepare("SELECT star_count FROM stars WHERE student_id=?");
$stmt->bind_param('i', $student_id);
$stmt->execute();
$stmt->bind_result($starCount);
if (!$stmt->fetch()) $starCount = 0;
$stmt->close();

// ─── This month’s earnings for confetti ───────────────────────────
$stmt = $conn->prepare("
  SELECT COALESCE(SUM(stars),0)
    FROM star_history
   WHERE student_id=?
     AND stars>0
     AND event_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
");
$stmt->bind_param('i', $student_id);
$stmt->execute();
$stmt->bind_result($thisMonthEarned);
$stmt->fetch();
$stmt->close();

// ─── Fetch full history ───────────────────────────────────────────────
$stmt = $conn->prepare("
  SELECT event_date AS date, stars, reason
    FROM star_history
   WHERE student_id=?
   ORDER BY event_date DESC
   LIMIT 100
");
$stmt->bind_param('i', $student_id);
$stmt->execute();
$res     = $stmt->get_result();
$history = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ─── Define upscale reward items with realistic cost tiers ────────────
// (curated by top-tier UX designers to delight students)
$rewards = [
  'bronze' => [
    [
      'title' => 'Premium Sketch Pencils Set',
      'desc'  => 'Graphite pencils, charcoal & blending tools',
      'cost'  => 25,
      'img'   => '/assets/rewards/bronze_pencils.jpg',
    ],
    [
      'title' => 'Vibrant Watercolor Palette',
      'desc'  => '24-colour set with brush & mixing tray',
      'cost'  => 30,
      'img'   => '/assets/rewards/bronze_watercolors.jpg',
    ],
  ],
  'silver' => [
    [
      'title' => 'Hardcover Art Journal',
      'desc'  => 'Thick, acid-free pages for mixed media',
      'cost'  => 50,
      'img'   => '/assets/rewards/silver_journal.jpg',
    ],
  ],
  'gold' => [
    [
      'title' => 'Professional Acrylic Set',
      'desc'  => '12 premium pigments + artist brushes',
      'cost'  => 80,
      'img'   => '/assets/rewards/gold_acrylics.jpg',
    ],
    [
      'title' => 'Deluxe LED Drawing Tablet',
      'desc'  => 'Portable light pad for tracing & details',
      'cost'  => 120,
      'img'   => '/assets/rewards/gold_lightpad.jpg',
    ],
  ],
];
?>
<!DOCTYPE html>
<html lang="en" class="bg-gray-50 dark:bg-gray-900">
<head>
  <meta charset="utf-8">
  <title>⭐ Star Reward Shop | Artovue</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <!-- Tailwind + Icons + Confetti -->
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css"
        rel="stylesheet"/>
  <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.5.1/dist/confetti.browser.min.js">
  </script>
  <style>
    /* Glass-morphic background */
    .shop {
      @apply max-w-6xl mx-auto p-6 space-y-8;
    }
    /* Tier tabs */
    .tier-tab {
      @apply cursor-pointer px-4 py-2 text-lg font-medium rounded-t-lg;
    }
    .tier-active {
      @apply bg-gradient-to-r from-secondary to-accent text-white;
    }
    /* Reward cards */
    .reward-card {
      @apply relative overflow-hidden bg-white/60 dark:bg-gray-800/60 backdrop-blur-md
             rounded-xl shadow-lg transform transition hover:scale-[1.02];
    }
    .reward-card img {
      @apply w-full h-44 object-cover rounded-t-xl;
    }
    .reward-desc {
      @apply absolute inset-0 flex items-center justify-center text-white text-center
             bg-black/50 opacity-0 transition-opacity;
    }
    .reward-card:hover .reward-desc {
      @apply opacity-100;
    }
    /* Animated progress bar */
    .progress-bg {
      @apply h-2 bg-gray-300 rounded-full overflow-hidden;
    }
    .progress-fg {
      @apply h-full bg-secondary rounded-full transition-all;
    }
  </style>
</head>
<body class="text-gray-800 dark:text-gray-100">

  <div class="shop">

    <!-- flash -->
    <?php if ($flash): ?>
      <div class="p-4 rounded-lg <?= strpos($flash,'❌')===0
          ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' ?>">
        <?= htmlspecialchars($flash) ?>
      </div>
    <?php endif; ?>

    <!-- Welcome banner -->
    <div class="p-6 bg-gradient-to-r from-secondary to-accent text-white rounded-xl shadow-lg">
      <h2 class="text-3xl font-bold flex items-center gap-2">
        <i class="bi bi-stars text-2xl"></i>
        You have <span class="mx-2"><?= $starCount ?></span> stars!
      </h2>
      <?php if ($thisMonthEarned>0): ?>
        <p class="mt-2 text-lg">✨ Earned <strong><?= $thisMonthEarned ?></strong> this month!</p>
      <?php endif; ?>
    </div>

    <!-- Tier tabs -->
    <div class="flex space-x-4 border-b-2">
      <?php foreach (['bronze','silver','gold'] as $tier): ?>
        <div class="tier-tab" data-tier="<?= $tier ?>">
          <?= ucfirst($tier) ?>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- Reward grids -->
    <?php foreach ($rewards as $tier => $items): ?>
      <div class="reward-grid grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 py-6"
           data-tier="<?= $tier ?>" style="display:none;">
        <?php foreach ($items as $it): 
          $can = $starCount >= $it['cost'];
        ?>
          <div class="reward-card">
            <img src="<?= $it['img'] ?>" alt="<?= htmlspecialchars($it['title']) ?>">
            <div class="p-4 space-y-2">
              <h3 class="text-xl font-semibold"><?= htmlspecialchars($it['title']) ?></h3>
              <p class="text-gray-600 dark:text-gray-300"><?= htmlspecialchars($it['desc']) ?></p>
              <div class="space-y-1">
                <div class="progress-bg">
                  <?php $pct = min(100, ($starCount/$it['cost']*100)); ?>
                  <div class="progress-fg" style="width:<?= $pct ?>%;"></div>
                </div>
                <p class="text-sm">Progress: <?= round($pct) ?>%</p>
              </div>
              <form method="POST" class="mt-4">
                <input type="hidden" name="action" value="redeem">
                <input type="hidden" name="cost"   value="<?= $it['cost'] ?>">
                <input type="hidden" name="item_title"
                       value="<?= htmlspecialchars($it['title'],ENT_QUOTES) ?>">
                <button <?= $can?'':'disabled' ?>
                        class="w-full py-2 rounded-lg text-white
                               <?= $can?'bg-secondary hover:bg-accent':'bg-gray-400 cursor-not-allowed' ?>">
                  <?= $can ? "Redeem for {$it['cost']} ⭐" : 'Insufficient Stars' ?>
                </button>
              </form>
            </div>
            <div class="reward-desc">
              <p><?= htmlspecialchars($it['desc']) ?></p>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

    <!-- Star history table trigger -->
    <div class="text-right">
      <button id="showHistory"
              class="px-5 py-2 bg-primary text-white rounded-lg hover:bg-secondary">
        View Your History
      </button>
    </div>
  </div>

  <!-- History Modal -->
  <div id="historyModal"
       class="fixed inset-0 bg-black/50 hidden items-center justify-center">
    <div class="bg-white dark:bg-gray-800 rounded-xl overflow-hidden w-full max-w-2xl">
      <div class="p-4 border-b dark:border-gray-700 flex justify-between items-center">
        <h3 class="text-xl font-bold">Star History</h3>
        <button id="closeHistory"><i class="bi bi-x-lg text-2xl"></i></button>
      </div>
      <div class="p-4 overflow-auto h-64">
        <table class="w-full text-left">
          <thead class="bg-gray-100 dark:bg-gray-700">
            <tr>
              <th class="p-2">Date</th>
              <th class="p-2">Stars</th>
              <th class="p-2">Reason</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($history as $h): ?>
              <tr class="border-b dark:border-gray-600">
                <td class="p-2"><?= date('d M Y',strtotime($h['date'])) ?></td>
                <td class="p-2">
                  <?php if($h['stars']>0): ?>
                    <span class="text-green-600">+<?= $h['stars'] ?> ⭐</span>
                  <?php else: ?>
                    <span class="text-red-600"><?= $h['stars'] ?> ⭐</span>
                  <?php endif; ?>
                </td>
                <td class="p-2"><?= htmlspecialchars($h['reason']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Confetti / Tabs / Modal JS -->
  <script>
  // show Bronze by default
  document.querySelectorAll('.reward-grid').forEach((el,i)=>
    el.style.display = (i===0 ? 'grid':'none')
  );
  document.querySelectorAll('.tier-tab').forEach((tab,i)=>{
    if(i===0) tab.classList.add('tier-active');
    tab.onclick = () => {
      document.querySelectorAll('.tier-tab')
        .forEach(t=>t.classList.remove('tier-active'));
      tab.classList.add('tier-active');
      const tier = tab.dataset.tier;
      document.querySelectorAll('.reward-grid')
        .forEach(g => g.dataset.tier===tier
           ? g.style.display='grid'
           : g.style.display='none');
    };
  });

  // confetti at 50+ stars
  <?php if($thisMonthEarned >= 50): ?>
    confetti({ spread:60, origin:{y:0.6}, particleCount:80 });
  <?php endif; ?>

  // history modal
  document.getElementById('showHistory').onclick = () =>
    document.getElementById('historyModal').classList.remove('hidden');
  document.getElementById('closeHistory').onclick = () =>
    document.getElementById('historyModal').classList.add('hidden');
  </script>
</body>
</html>
