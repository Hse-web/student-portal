<?php
// dashboard/admin/edit_video.php
require_once __DIR__.'/../../config/session.php';
require_role('admin');
require_once __DIR__.'/../../config/db.php';

$adminUsername = $_SESSION['username'] ?? 'Admin';
$menu = [
  ['url'=>'index.php','icon'=>'bi-speedometer2','label'=>'Dashboard'],
  ['url'=>'compensation_videos.php','icon'=>'bi-play-btn','label'=>'Videos'],
  ['url'=>'video_manager.php','icon'=>'bi-collection-play-fill','label'=>'Quiz'],
];

$videoId = (int)($_GET['id']??0);
if(!$videoId) die("Invalid ID");

// POST→update
if($_SERVER['REQUEST_METHOD']==='POST'){
  $cid=$_POST['centre_id'];
  $dt=$_POST['class_date'];
  $url=trim($_POST['video_url']);
  if($cid&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$dt)&&filter_var($url,FILTER_VALIDATE_URL)){
    $u=$conn->prepare("
      UPDATE compensation_videos 
         SET centre_id=?,class_date=?,video_url=? 
       WHERE id=?
    ");
    $u->bind_param('issi',$cid,$dt,$url,$videoId);
    $u->execute(); $u->close();

    // delete old quiz
    $conn->prepare("DELETE FROM video_quiz_questions WHERE video_id=?")
         ->bind_param('i',$videoId)
         ->execute();

    // insert new quiz
    foreach($_POST['quiz'] as $q){
      $qtxt=trim($q['question']??'');
      $opts=array_filter(array_map('trim',$q['options']??[]));
      $correct=(int)($q['correct_index']??-1);
      if($qtxt&&count($opts)>=2&&isset($opts[$correct])){
        $ins=$conn->prepare("
          INSERT INTO video_quiz_questions
            (video_id,question,options_json,correct_index)
          VALUES(?,?,?,?)
        ");
        $json=json_encode(array_values($opts),JSON_UNESCAPED_UNICODE);
        $ins->bind_param('issi',$videoId,$qtxt,$json,$correct);
        $ins->execute(); $ins->close();
      }
    }

    $_SESSION['flash']=['type'=>'success','msg'=>'Updated!'];
    header('Location: compensation_videos.php');
    exit;
  } else {
    $_SESSION['flash']=['type'=>'danger','msg'=>'Invalid input'];
  }
}

// fetch centres + video + quiz
$centres=$conn->query("SELECT id,name FROM centres");
$stmt=$conn->prepare("SELECT centre_id,class_date,video_url FROM compensation_videos WHERE id=? LIMIT 1");
$stmt->bind_param('i',$videoId); $stmt->execute();
$stmt->bind_result($centre_id,$class_date,$video_url);
$stmt->fetch(); $stmt->close();

$questions=[];
$q=$conn->prepare("SELECT id,question,options_json,correct_index FROM video_quiz_questions WHERE video_id=?");
$q->bind_param('i',$videoId); $q->execute();
$res=$q->get_result();
while($r=$res->fetch_assoc()){
  $r['options']=json_decode($r['options_json'],true);
  $questions[]=$r;
}
$q->close();

$flash=$_SESSION['flash']??null; unset($_SESSION['flash']);
include __DIR__.'/../../templates/partials/header_admin.php';
?>
<main class="container-fluid px-4">
  <h1 class="mt-4">Edit Video #<?=$videoId?></h1>
  <?php if($flash):?>
    <div class="alert alert-<?=$flash['type']?>"><?=$flash['msg']?></div>
  <?php endif; ?>

  <div class="card mb-4 shadow-sm">
    <div class="card-body">
      <form method="POST" class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Centre</label>
          <select name="centre_id" class="form-select" required>
            <?php while($c=$centres->fetch_assoc()):?>
              <option value="<?=$c['id']?>"<?= $c['id']==$centre_id?' selected':''?>>
                <?=htmlspecialchars($c['name'])?>
              </option>
            <?php endwhile;?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Class Date</label>
          <input type="date" name="class_date" class="form-control"
                 value="<?=$class_date?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">YouTube URL</label>
          <input type="url" name="video_url" class="form-control"
                 value="<?=htmlspecialchars($video_url)?>" required>
        </div>
      </form>

      <hr>

      <h5>Quiz Questions</h5>
      <div id="quizContainer">
        <?php foreach($questions as $i=>$ques): ?>
          <div class="card mb-3 p-3">
            <div class="mb-2">
              <label class="form-label">Question</label>
              <input type="text"
                     name="quiz[<?=$i?>][question]"
                     class="form-control"
                     value="<?=htmlspecialchars($ques['question'])?>"
                     required>
            </div>
            <div class="row mb-2">
              <?php foreach($ques['options'] as $j=>$opt):?>
                <div class="col-md-6 mb-2">
                  <input type="text"
                         name="quiz[<?=$i?>][options][]"
                         class="form-control"
                         value="<?=htmlspecialchars($opt)?>"
                         placeholder="Option <?=($j+1)?>" required>
                </div>
              <?php endforeach;?>
            </div>
            <div class="mb-2">
              <label class="form-label">Correct Option</label>
              <select name="quiz[<?=$i?>][correct_index]" class="form-select" required>
                <?php foreach($ques['options'] as $j=>$opt):?>
                  <option value="<?=$j?>"<?= $j===$ques['correct_index']?' selected':'' ?>>
                    Option <?=($j+1)?>
                  </option>
                <?php endforeach;?>
              </select>
            </div>
          </div>
        <?php endforeach;?>
      </div>

      <button id="addQuestion" class="btn btn-sm btn-outline-secondary mb-3">
        + Add Question
      </button>
      <div class="d-grid">
        <button class="btn btn-primary">Save Changes</button>
      </div>
    </div>
  </div>
</main>
<?php include __DIR__.'/../../templates/partials/footer_admin.php'; ?>

<script>
let idx = <?=count($questions)?>;
document.getElementById('addQuestion').onclick = ()=>{
  const container = document.getElementById('quizContainer');
  const block = document.createElement('div');
  block.className = 'card mb-3 p-3';
  block.innerHTML = `
    <div class="mb-2">
      <label class="form-label">Question</label>
      <input type="text" name="quiz[${idx}][question]" class="form-control" required>
    </div>
    <div class="row mb-2">
      ${[0,1,2,3].map(i=>`
        <div class="col-md-6 mb-2">
          <input type="text"
                 name="quiz[${idx}][options][]"
                 class="form-control"
                 placeholder="Option ${i+1}"
                 required>
        </div>`).join('')}
    </div>
    <div class="mb-2">
      <label class="form-label">Correct Option</label>
      <select name="quiz[${idx}][correct_index]" class="form-select" required>
        <option value="">-- choose --</option>
        ${[0,1,2,3].map(i=>`<option value="${i}">Option ${i+1}</option>`).join('')}
      </select>
    </div>`;
  container.append(block);
  idx++;
};
</script>
