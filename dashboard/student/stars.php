<?php
// File: dashboard/stars.php
// Assumes session, db, auth‐guard, $conn and $studentId are already set

// 1) Fetch current star count
$stmt = $conn->prepare("
    SELECT star_count
      FROM stars
     WHERE student_id = ?
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($starCount);
$stmt->fetch();
$stmt->close();

// 2) Fetch star‐earning history
$history = [];
$stmt = $conn->prepare("
    SELECT 
      event_date    AS date,
      stars         AS stars,
      reason        AS reason
    FROM star_history
    WHERE student_id = ?
    ORDER BY event_date DESC
    LIMIT 20
");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $history[] = $row;
}
$stmt->close();

// 3) Define reward shop items
$rewards = [
    [
      'title' => 'Set of Color Pencils',
      'desc'  => 'Useful for art students',
      'cost'  => 20,
      'img'   => '../assets/color_pencils.jpg',
    ],
    [
      'title' => 'Watercolor Kit',
      'desc'  => 'Encourage painting',
      'cost'  => 50,
      'img'   => '../assets/watercolor_kit.jpg',
    ],
    [
      'title' => 'Canvas Board (Small)',
      'desc'  => 'Boost larger projects',
      'cost'  => 30,
      'img'   => '../assets/canvas_board.jpg',
    ],
    [
      'title' => 'Premium Sketchbook',
      'desc'  => 'High-value reward',
      'cost'  => 40,
      'img'   => '../assets/sketchbook.jpg',
    ],
];
?>

<div class="container-fluid">
  <h4 class="section-header">⭐ Star Reward System</h4>

  <div class="d-flex align-items-center gap-3 mb-4">
    <div class="card p-3">
      <i class="bi bi-star-fill fs-1 text-warning"></i>
      <span class="h3 mb-0"><?= htmlspecialchars($starCount) ?> Stars</span>
    </div>
    <button
      class="btn btn-primary"
      data-bs-toggle="modal"
      data-bs-target="#starHistoryModal"
    >
      View Star History
    </button>
  </div>

  <h5 class="section-header">Reward Shop</h5>
  <div class="row g-4 mb-4">
    <?php foreach ($rewards as $r):
      $canRedeem = $starCount >= $r['cost'];
    ?>
      <div class="col-md-3">
        <div class="card h-100 shadow-sm">
          <img
            src="<?= htmlspecialchars($r['img']) ?>"
            class="card-img-top"
            alt="<?= htmlspecialchars($r['title']) ?>"
          >
          <div class="card-body">
            <h5 class="card-title"><?= htmlspecialchars($r['title']) ?></h5>
            <p class="card-text"><?= htmlspecialchars($r['desc']) ?></p>
          </div>
          <div class="card-footer text-center">
            <small><?= htmlspecialchars($r['cost']) ?> Stars</small><br>
            <button
              class="btn btn-sm btn-<?= $canRedeem ? 'primary' : 'secondary' ?>"
              <?= $canRedeem ? '' : 'disabled' ?>
            >
              Redeem
            </button>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Star History Modal -->
<div class="modal fade" id="starHistoryModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Why You Earned Stars</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <?php if (count($history)): ?>
          <div class="table-responsive">
            <table class="table table-bordered">
              <thead class="table-light">
                <tr>
                  <th>Date</th>
                  <th>Stars</th>
                  <th>Reason</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($history as $h): ?>
                  <tr>
                    <td><?= htmlspecialchars(date('d M Y', strtotime($h['date']))) ?></td>
                    <td>
                      <?= $h['stars']>0?'+':'' ?><?= htmlspecialchars($h['stars']) ?> Stars
                    </td>
                    <td><?= htmlspecialchars($h['reason']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <p class="text-center mb-0">No star history available.</p>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
