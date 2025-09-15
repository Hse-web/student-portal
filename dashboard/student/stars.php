<?php
// File: dashboard/student/stars.php
// ─── COMING SOON SWITCH ───────────────────────────────────────────────────
// Flip this to true whenever you want the “Coming Soon” placeholder active.
// session_start();
// define('SHOW_STARS_COMING_SOON', true);
// // only bypass for student #42
// $devOverride = ($_SESSION['student_id'] ?? 0) === 177;

// if (SHOW_STARS_COMING_SOON && ! $devOverride) {
//   http_response_code(503);
//   header('Retry-After:3600');
//   include __DIR__ . '/new.php';
//   exit;
// }
// ─────────────────────────────────────────────────────────────────────────
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
        $i = $conn->prepare("INSERT INTO star_history (student_id,event_date,stars,reason) VALUES (?,NOW(),? ,?)");
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

// ─── This month’s earnings for welcome banner & confetti ───────────
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
$res = $stmt->get_result();
$history = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ─── Define reward items with tiers ─────────────────────────────────
$rewards = [
  'bronze' => [
    ['title'=>'Set of Color Pencils','desc'=>'Useful for drawing','cost'=>20,'img'=>'/assets/IMG-20250317-WA0008.jpg'],
    ['title'=>'Canvas Board (Small)','desc'=>'Boost larger projects','cost'=>30,'img'=>'/assets/IMG-20250317-WA0005.jpg'],
  ],
  'silver' => [
    ['title'=>'Premium Sketchbook','desc'=>'High-quality paper','cost'=>40,'img'=>'/assets/sketchbook.jpg'],
  ],
  'gold' => [
    ['title'=>'Watercolor Kit','desc'=>'Encourage painting','cost'=>50,'img'=>'/assets/IMG-20250317-WA0006.jpg'],
  ],
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>⭐ Star Reward Shop</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <!-- Tailwind + Bootstrap Icons + Confetti -->
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet"/>
  <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.5.1/dist/confetti.browser.min.js"></script>
  <style>
    .reward-card { position: relative; overflow: hidden; }
    .reward-desc {
      position: absolute; inset:0;
      background:rgba(0,0,0,0.6); color:#fff;
      display:flex; align-items:center; justify-content:center;
      text-align:center; opacity:0; transition:opacity .3s;
      padding:1rem;
    }
    .reward-card:hover .reward-desc { opacity:1; }
    /* group tabs */
    .tier-tab { padding:.5rem 1rem; cursor:pointer; }
    .tier-active { border-bottom:2px solid #9333ea; color:#9333ea; }
    /* history filters */
    #historySearch { width:200px; }
  </style>
</head>
<body class="bg-gray-50 text-gray-800">

  <div class="max-w-5xl mx-auto p-6">

    <?php if ($flash): ?>
      <div class="mb-4 p-3 <?= strpos($flash,'❌')===0?'bg-red-100 text-red-800':'bg-green-100 text-green-800' ?> rounded">
        <?= htmlspecialchars($flash) ?>
      </div>
    <?php endif; ?>

    <!-- Welcome Banner -->
    <div class="mb-6 p-6 bg-gradient-to-r from-purple-600 to-indigo-500 text-white rounded-lg shadow-lg">
      <h2 class="text-2xl font-bold">⭐ You have <?= $starCount ?> stars!</h2>
      <?php if ($thisMonthEarned>0): ?>
        <p class="mt-2">Congratulations—earned <strong><?= $thisMonthEarned ?></strong> this month!</p>
      <?php endif; ?>
    </div>

    <!-- Tiered Shop Tabs -->
    <div class="mb-4 flex space-x-4">
      <?php foreach (['bronze','silver','gold'] as $tier): ?>
        <div class="tier-tab text-lg capitalize" data-tier="<?= $tier ?>"><?= $tier ?></div>
      <?php endforeach; ?>
    </div>

    <!-- Reward Grid -->
    <?php foreach ($rewards as $tier=>$items): ?>
      <div class="reward-tier grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 mb-8" data-tier="<?= $tier ?>" style="display:none;">
        <?php foreach ($items as $item):
          $can = $starCount >= $item['cost'];
        ?>
          <div class="bg-white rounded-lg shadow reward-card">
            <?php if (@getimagesize(__DIR__."/../../{$item['img']}")): ?>
              <img src="<?= $item['img'] ?>" alt="" class="w-full h-40 object-cover">
            <?php else: ?>
              <div class="w-full h-40 bg-gray-200 flex items-center justify-center">No Image</div>
            <?php endif; ?>
            <div class="p-4">
              <h4 class="font-semibold"><?= htmlspecialchars($item['title']) ?></h4>
              <div class="mt-2 font-bold"><?= $item['cost'] ?> ⭐</div>
              <div class="mt-4">
                <div class="h-1 bg-gray-200 rounded">
                  <div class="h-full bg-green-500" style="width:<?= min(100,($starCount/$item['cost']*100)) ?>%"></div>
                </div>
                <small class="text-gray-500">Progress to redeem</small>
              </div>
              <form method="POST" class="mt-4">
                <input type="hidden" name="action" value="redeem">
                <input type="hidden" name="cost" value="<?= $item['cost'] ?>">
                <input type="hidden" name="item_title" value="<?= htmlspecialchars($item['title']) ?>">
                <button type="submit"
                  <?= $can?'':'disabled' ?>
                  class="w-full py-2 rounded text-white <?= $can?'bg-green-600 hover:bg-green-700':'bg-gray-400 cursor-not-allowed' ?>">
                  <?= $can?'Redeem':'Insufficient' ?>
                </button>
              </form>
            </div>
            <div class="reward-desc">
              <?= htmlspecialchars($item['desc']) ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

    <!-- Star History Modal Trigger -->
    <div class="text-right mb-6">
      <button class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700"
              data-bs-toggle="modal" data-bs-target="#historyModal">
        View Star History
      </button>
    </div>

    <!-- Star History Modal -->
    <div class="modal fade" id="historyModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Star History</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div class="flex justify-between mb-4">
              <select id="historyFilter" class="form-select w-48">
                <option value="all">All</option>
                <option value="earned">Earned</option>
                <option value="spent">Redeemed</option>
              </select>
              <input id="historySearch" type="text" placeholder="Search reason…" class="form-input">
            </div>
            <div class="overflow-auto">
              <table class="min-w-full text-sm">
                <thead class="bg-gray-100">
                  <tr>
                    <th class="p-2 text-left">Date</th>
                    <th class="p-2 text-left">Stars</th>
                    <th class="p-2 text-left">Reason</th>
                  </tr>
                </thead>
                <tbody id="historyTbody">
                  <?php foreach ($history as $h): 
                    $icon = $h['stars']>0 ? '<i class="bi bi-star-fill text-green-600"></i>' 
                                         : '<i class="bi bi-gift-fill text-red-600"></i>';
                  ?>
                    <tr data-stars="<?= $h['stars'] ?>" data-reason="<?= htmlspecialchars(strtolower($h['reason'])) ?>">
                      <td class="p-2"><?= date('d M Y', strtotime($h['date'])) ?></td>
                      <td class="p-2"><?= $icon ?> <?= $h['stars']>0?'+':''?><?= $h['stars'] ?></td>
                      <td class="p-2"><?= htmlspecialchars($h['reason']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
          <div class="modal-footer">
            <button class="px-4 py-2 bg-gray-500 text-white rounded" data-bs-dismiss="modal">Close</button>
          </div>
        </div>
      </div>
    </div>

  </div><!-- /.container -->

  <!-- Bootstrap & JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
  document.addEventListener('DOMContentLoaded', () => {
    // ── Tier tabs logic ─────────────────────────
    const tabs = document.querySelectorAll('.tier-tab'),
          tiers = document.querySelectorAll('.reward-tier');
    function showTier(t){
      tiers.forEach(el=> el.style.display = el.dataset.tier===t ? 'grid':'none');
      tabs.forEach(tb=> tb.classList.toggle('tier-active', tb.dataset.tier===t));
    }
    tabs.forEach(tb=> tb.onclick = ()=> showTier(tb.dataset.tier));
    showTier('bronze');

    // ── Confetti milestone ────────────────────
    <?php if($thisMonthEarned >= 50): ?>
      confetti({ spread: 60, origin: { y: 0.6 } });
    <?php endif; ?>

    // ── History filter + search ───────────────
    const tbody = document.getElementById('historyTbody'),
          rows  = Array.from(tbody.rows),
          filt  = document.getElementById('historyFilter'),
          search= document.getElementById('historySearch');
    function refreshHistory(){
      const mode = filt.value, term = search.value.trim().toLowerCase();
      rows.forEach(r=> {
        const stars = parseInt(r.dataset.stars),
              reason= r.dataset.reason;
        let ok = (mode==='all')
              || (mode==='earned' && stars>0)
              || (mode==='spent'  && stars<0);
        if (term && !reason.includes(term)) ok = false;
        r.style.display = ok?'':'none';
      });
    }
    filt.onchange = refreshHistory;
    search.oninput = refreshHistory;
  });
  </script>

</body>
</html>
