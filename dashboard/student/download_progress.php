<?php
// File: dashboard/student/download_progress.php

require_once __DIR__ . '/../../config/bootstrap.php';
require_role('student');
require_once __DIR__ . '/../includes/functions.php';    // get_current_group_label()
require_once __DIR__ . '/../helpers/functions.php';     // get_next_group_label()
require_once __DIR__ . '/../../libs/fpdf.php';

date_default_timezone_set('Asia/Kolkata');

// ─── 1) AUTH & STUDENT ────────────────────────────────────────────────
if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'student') {
    header('Location: ../../login/index.php');
    exit;
}
$studentId = (int)($_SESSION['student_id'] ?? 0);
if ($studentId < 1) {
    exit("Invalid student. Please log in again.");
}

// ─── 2) STUDENT & GROUP INFO ──────────────────────────────────────────
$stmt = $conn->prepare("SELECT name FROM students WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($studentName);
$stmt->fetch();
$stmt->close();

$currentGroup = get_current_group_label($conn, $studentId);
$nextGroup    = get_next_group_label   ($conn, $studentId);

// ─── 3) RANGE DEFINITION ──────────────────────────────────────────────
$range      = $_GET['range'] ?? 'monthly';
$monthsBack = match($range) {
    'quarter' => 3,
    'half'    => 6,
    default   => 1,
};

$end   = new DateTime;             // today
$start = (clone $end)
    ->modify('-'.($monthsBack - 1).' months')
    ->modify('first day of');

$monthFrom = $start->format('Y-m');
$monthTo   = $end->format('Y-m');

// ─── 4) PULL LATEST IN-PERIOD ROW ────────────────────────────────────
$sql = "
    SELECT 
      month,
      hand_control,   hc_remark,
      coloring_shading, cs_remark,
      observations,    obs_remark,
      temperament,     temp_remark,
      attendance,      att_remark,
      homework,        hw_remark
    FROM progress
   WHERE student_id = ?
     AND month BETWEEN ? AND ?
   ORDER BY month DESC
   LIMIT 1
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('iss', $studentId, $monthFrom, $monthTo);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ─── 5) FALLBACK TO ABSOLUTE LATEST ──────────────────────────────────
if (!$row) {
    $stmt = $conn->prepare("
      SELECT 
        month,
        hand_control,   hc_remark,
        coloring_shading, cs_remark,
        observations,    obs_remark,
        temperament,     temp_remark,
        attendance,      att_remark,
        homework,        hw_remark
      FROM progress
     WHERE student_id = ?
     ORDER BY month DESC
     LIMIT 1
    ");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
      exit("No progress available yet. We record progress every month—please check back after this month.");
    }
}

// ─── 6) PERIOD LABEL ─────────────────────────────────────────────────
if ($monthsBack === 1) {
    $periodLabel = (new DateTime($row['month'].'-01'))->format('F Y');
} else {
    $periodLabel = $start->format('M Y') . ' – ' . $end->format('M Y');
}

// ─── 7) HELPERS ──────────────────────────────────────────────────────
function scoreLabel(int $c): string {
    return match($c) {
      5 => 'Excellent',
      4 => 'Very Good',
      3 => 'Good',
      2 => 'Average',
      1 => 'Needs Improvement',
      default => 'Not Rated',
    };
}
function computeOverall(array $r): string {
    $fields = ['hand_control','coloring_shading','observations','temperament','attendance','homework'];
    $sum = 0; $cnt = 0;
    foreach ($fields as $f) {
      $v = (int)($r[$f] ?? 0);
      if ($v>0) { $sum += $v; $cnt++; }
    }
    return $cnt 
      ? scoreLabel((int)floor($sum/$cnt)) 
      : 'Not Rated';
}
$overallPerformance = computeOverall($row);

// ─── 8) GENERATE PDF ─────────────────────────────────────────────────
$pdf = new FPDF('P','mm','A4');
$pdf->AddPage();

// Logo (if present)
$logo = __DIR__ . '/../../assets/logo_desk.png';
if (file_exists($logo)) {
    $pdf->Image($logo, 10, 6, 30);
}

// Title
$pdf->SetFont('Arial','B',20);
$pdf->Cell(0,10,'PROGRESS REPORT',0,1,'C');
$pdf->SetFont('Arial','I',12);
$pdf->Cell(0,8,$periodLabel,0,1,'C');
$pdf->Ln(5);

// Student info
$pdf->SetFont('Arial','',11);
$pdf->SetFillColor(240,240,240);
$pdf->Cell(45,10,'Student Name:',1,0,'L',true);
$pdf->Cell(145,10,$studentName,1,1,'L');
$pdf->Cell(45,10,'Current Group:',1,0,'L',true);
$pdf->Cell(70,10,$currentGroup,1,0,'L');
$pdf->Cell(35,10,'Next Group:',1,0,'L',true);
$pdf->Cell(40,10,$nextGroup,1,1,'L');
$pdf->Ln(5);

// Table header
$pdf->SetFont('Arial','B',12);
$pdf->SetFillColor(255,204,0);
$pdf->Cell(60,10,'TECHNICAL',1,0,'C',true);
$pdf->Cell(30,10,'GRADE',1,0,'C',true);
$pdf->Cell(100,10,'COMMENT',1,1,'C',true);

// Rows
$fields = [
  'hand_control'     => ['Hand Control','hc_remark'],
  'coloring_shading' => ['Coloring & Shading','cs_remark'],
  'observations'     => ['Observations','obs_remark'],
  'temperament'      => ['Temperament','temp_remark'],
  'attendance'       => ['Attendance','att_remark'],
  'homework'         => ['Homework','hw_remark'],
];

$x0 = $pdf->GetX();
foreach ($fields as $f => list($label,$rc)) {
    $score   = (int)($row[$f] ?? 0);
    $grade   = scoreLabel($score);
    $comment = trim($row[$rc] ?? '');

    // TECHNICAL cell
    $pdf->SetFont('Arial','B',11);
    $pdf->SetFillColor(110,37,91);
    $pdf->SetTextColor(255,255,255);
    $pdf->Cell(60,10,strtoupper($label),1,0,'L',true);

    // GRADE cell
    $pdf->SetFont('Arial','B',11);
    $pdf->SetFillColor(235,235,235);
    $pdf->SetTextColor(0,0,0);
    $pdf->Cell(30,10,$grade,1,0,'C',true);

    // COMMENT cell (multi‐line)
    $xBefore = $pdf->GetX();
    $yBefore = $pdf->GetY();
    $pdf->SetFont('Arial','',10);
    $pdf->SetFillColor(255,255,255);
    $pdf->MultiCell(100,10,$comment,1,'L',false);

    // Move to the start of next row
    $yAfter = $pdf->GetY();
    $pdf->SetXY($x0, $yAfter);
}

// Overall
$pdf->Ln(3);
$pdf->SetFont('Arial','B',12);
$pdf->SetFillColor(204,255,204);
$pdf->Cell(0,12,'Overall Performance: '.$overallPerformance,1,1,'C',true);

// Signatures
$pdf->Ln(10);
$pdf->SetFont('Arial','',11);
$pdf->Cell(80,10,'________________________',0,0,'C');
$pdf->Cell(30,10,'',0,0);
$pdf->Cell(80,10,'________________________',0,1,'C');
$pdf->Cell(80,6,"Teacher's Signature",0,0,'C');
$pdf->Cell(30,6,'',0,0);
$pdf->Cell(80,6,"Parent's Signature",0,1,'C');

// Output
$fn = sprintf(
  'progress_%s_%s.pdf',
  $range,
  preg_replace('/\s+/','_',strtolower($studentName))
);
$pdf->Output('D', $fn);
exit;
?>
