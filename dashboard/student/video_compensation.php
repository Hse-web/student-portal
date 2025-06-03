<?php
// File: dashboard/student/videos.php
$page = 'videos';
require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');

$studentId = $_SESSION['student_id'];

// ─── Fetch all compensation videos (no “per-centre” filter in this example; you can restrict by the student’s centre if desired) ─────────
$stmt = $conn->prepare("
  SELECT
    v.id,
    c.name AS centre,
    v.class_date,
    v.video_url,
    (SELECT COUNT(*) FROM video_quiz_questions q WHERE q.video_id = v.id) AS quiz_count
  FROM compensation_videos v
  JOIN centres c ON c.id = v.centre_id
  ORDER BY v.class_date DESC
");
$stmt->execute();
$videos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
  <div class="w-full max-w-5xl mx-auto space-y-6">
    <h2 class="text-2xl font-semibold text-gray-800">Compensation Videos</h2>

    <?php if (empty($videos)): ?>
      <p class="text-gray-500">No videos available yet.</p>
    <?php else: ?>
      <?php foreach ($videos as $v): ?>
        <div class="bg-white p-4 rounded shadow mb-4">
          <div class="flex justify-between items-center">
            <div>
              <h3 class="text-lg font-medium"><?= htmlspecialchars($v['centre']) ?></h3>
              <p class="text-sm text-gray-600"><?= htmlspecialchars($v['class_date']) ?></p>
            </div>
            <div class="flex space-x-2">
              <a href="<?= htmlspecialchars($v['video_url']) ?>"
                 class="btn btn-sm btn-primary"
                 target="_blank">
                Watch Video
              </a>
              <?php if ($v['quiz_count'] > 0): ?>
                <a href="?page=take_quiz&video_id=<?= $v['id'] ?>"
                   class="btn btn-sm btn-outline-secondary">
                  Take Quiz (<?= $v['quiz_count'] ?> Qs)
                </a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
<?php
