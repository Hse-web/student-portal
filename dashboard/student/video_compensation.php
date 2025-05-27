<?php
// dashboard/student/video_compensation.php

require_once __DIR__.'/../../config/session.php';
require_role('student');
require_once __DIR__.'/../../config/db.php';

$user_id    = $_SESSION['user_id'];
$class_date = $_GET['date'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $class_date)) {
    die("Invalid or missing date.");
}

// Fetch student & centre
$stmt = $conn->prepare("
  SELECT s.id, s.name, u.centre_id
    FROM students s
    JOIN users    u ON u.id=s.user_id
   WHERE u.id=?
");
$stmt->bind_param('i',$user_id);
$stmt->execute();
$stmt->bind_result($student_id,$studentName,$centre_id);
$stmt->fetch(); $stmt->close();

if ($centre_id===3) {
  die("Live make-ups only for Centre C.");
}

// Fetch video
$stmt = $conn->prepare("
  SELECT id, video_url
    FROM compensation_videos
   WHERE centre_id=? AND class_date=?
");
$stmt->bind_param('is',$centre_id,$class_date);
$stmt->execute();
$stmt->bind_result($video_id,$video_url);
if (!$stmt->fetch()) die("No video for $class_date");
$stmt->close();

// Check if already credited
$stmt = $conn->prepare("
  SELECT is_video_comp FROM attendance
   WHERE student_id=? AND date=?
");
$stmt->bind_param('is',$student_id,$class_date);
$stmt->execute();
$stmt->bind_result($isComp);
$hasRow = $stmt->fetch();
$stmt->close();
$already = $hasRow && (int)$isComp===1;

// Load quiz questions
$questions = [];
$q = $conn->prepare("
  SELECT id,question,options_json,correct_index
    FROM video_quiz_questions
   WHERE video_id=?
");
$q->bind_param('i',$video_id);
$q->execute();
$res = $q->get_result();
while($r=$res->fetch_assoc()){
  $r['options'] = json_decode($r['options_json'],true);
  $questions[]  = $r;
}
$q->close();

// Handle POST
if ($_SERVER['REQUEST_METHOD']==='POST' && !$already) {
  // Validate quiz
  foreach($questions as $ques){
    $sel = $_POST["q{$ques['id']}"] ?? -1;
    if ((int)$sel !== $ques['correct_index']) {
      $_SESSION['video_flash']="One or more answers are incorrect. Please try again.";
      header("Location: video_compensation.php?date=$class_date");
      exit;
    }
  }
  // Mark watched + attendance
  $c = $conn->prepare("INSERT INTO video_completions(student_id,video_id) VALUES(?,?)");
  $c->bind_param('ii',$student_id,$video_id); $c->execute(); $c->close();
  $u = $conn->prepare("
    UPDATE attendance SET status='Compensation',is_compensation=1,is_video_comp=1
     WHERE student_id=? AND date=?
  ");
  $u->bind_param('is',$student_id,$class_date); $u->execute(); $u->close();
  $_SESSION['video_flash']="✅ Quiz passed—attendance credited!";
  header("Location: video_compensation.php?date=$class_date");
  exit;
}

// Flash
$message = $_SESSION['video_flash'] ?? '';
unset($_SESSION['video_flash']);
$menu = [
  ['url'=>'student.php','label'=>'Dashboard'],
  ['url'=>'attendance.php','label'=>'Attendance'],
  ['url'=>"video_compensation.php?date=$class_date",'label'=>'Video Make-up']
];
?>
<div class="container mt-4">
  <div class="card shadow-sm">
    <div class="card-body">
      <h2>Video Make-up: <?=htmlspecialchars($class_date)?></h2>
      <?php if($message):?>
        <div class="alert alert-info"><?=htmlspecialchars($message)?></div>
      <?php endif;?>

      <?php if($already):?>
        <div class="alert alert-success">✅ Already credited.</div>
      <?php else: ?>
        <!-- 1) Video embed -->
        <div class="ratio ratio-16x9 mb-3">
          <iframe src="<?=htmlspecialchars($video_url)?>" allowfullscreen></iframe>
        </div>

        <!-- 2) Quiz form -->
        <form method="POST" class="mb-3">
          <?php foreach($questions as $i=>$ques): ?>
            <div class="mb-2">
              <strong><?=($i+1)?>. <?=htmlspecialchars($ques['question'])?></strong>
              <?php foreach($ques['options'] as $optIndex=>$opt): ?>
                <div class="form-check">
                  <input 
                    class="form-check-input" 
                    type="radio" 
                    id="q<?=$ques['id']?>_<?=$optIndex?>" 
                    name="q<?=$ques['id']?>" 
                    value="<?=$optIndex?>" 
                    required
                  >
                  <label class="form-check-label" for="q<?=$ques['id']?>_<?=$optIndex?>">
                    <?=htmlspecialchars($opt)?>
                  </label>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
          <button class="btn btn-primary">Submit Quiz & Credit</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

