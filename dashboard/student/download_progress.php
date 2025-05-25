<?php
// dashboard/student/download_progress.php
require_once __DIR__ . '/../../libs/fpdf.php';
session_start();
ob_clean();
require_once __DIR__.'/../../config/session.php';
require_once __DIR__.'/../../config/db.php';

if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'student') {
    header('Location: ../../login/index.php');
    exit;
}

$studentId = $_SESSION['student_id'] ?? 0;
if (!$studentId) {
    echo "No student ID found in session.";
    exit;
}

$stmt = $conn->prepare("SELECT id, name, group_name FROM students WHERE id=? LIMIT 1");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($studentId, $studentName, $currentGroup);
$stmt->fetch();
$stmt->close();

$nextGroup = preg_replace_callback('/([A-Za-z])$/', fn($m)=> chr(ord($m[1])+1), $currentGroup);

$range = $_GET['range'] ?? 'monthly';
$monthsBack = match($range) {
    'monthly' => 1,
    'quarter' => 3,
    'half'    => 6,
    default   => 1
};

$end   = new DateTime();
$start = (clone $end)->modify('-'.($monthsBack - 1).' months')->modify('first day of');
$monthFrom = $start->format('Y-m');
$monthTo   = $end->format('Y-m');

// Fetch latest available progress
$stmt = $conn->prepare("
  SELECT month,
         hand_control, hc_remark,
         coloring_shading, cs_remark,
         observations, obs_remark,
         temperament, temp_remark,
         attendance, att_remark,
         homework, hw_remark
    FROM progress
   WHERE student_id = ?
     AND month BETWEEN ? AND ?
   ORDER BY month DESC
   LIMIT 1
");
$stmt->bind_param('iss', $studentId, $monthFrom, $monthTo);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();

if (!$row) {
    echo "No progress records found for selected period.";
    exit;
}

// Grade mapping
function scoreLabel(int $code): string {
    return match($code) {
        4 => 'Excellent',
        3 => 'Very Good',
        2 => 'Good',
        1 => 'Average',
        default => 'Not Rated',
    };
}

// Compute overall
function computeOverall(array $row): string {
    $fields = ['hand_control','coloring_shading','observations','temperament','attendance','homework'];
    $sum = 0; $count = 0;
    foreach ($fields as $f) {
        $val = (int)($row[$f] ?? 0);
        if ($val > 0) {
            $sum += $val;
            $count++;
        }
    }
    return $count ? scoreLabel((int)round($sum / $count)) : 'Not Rated';
}

$overallPerformance = computeOverall($row);
$periodLabel = $start->format('M Y') . ' – ' . $end->format('M Y');

// Start PDF
$pdf = new FPDF();
$pdf->AddPage();

$pdf->Image(__DIR__.'/../../assets/logo_desk.png', 10, 6, 30);
$pdf->SetFont('Arial', 'B', 20);
$pdf->Cell(0, 10, 'PROGRESS REPORT', 0, 1, 'C');
$pdf->SetFont('Arial', 'I', 12);
$pdf->Cell(0, 10, "FROM $periodLabel", 0, 1, 'C');
$pdf->Ln(5);

// Student Info
$pdf->SetFont('Arial', '', 11);
$pdf->SetFillColor(240,240,240);
$pdf->Cell(50,10,'Student Name:',1,0,'L',true);
$pdf->Cell(130,10,$studentName,1,1,'L');
$pdf->Cell(50,10,'Current Group:',1,0,'L',true);
$pdf->Cell(70,10,$currentGroup,1,0,'L');
$pdf->Cell(40,10,'Next Group:',1,0,'L',true);
$pdf->Cell(30,10,$nextGroup,1,1,'L');

// Grade Table
$pdf->Ln(5);
$pdf->SetFillColor(255,204,0);
$pdf->SetFont('Arial','B',12);
$pdf->Cell(60,10,'TECHNICAL',1,0,'C',true);
$pdf->Cell(30,10,'GRADE',1,0,'C',true);
$pdf->Cell(100,10,'COMMENT',1,1,'C',true);

$fields = [
  'hand_control'     => ['Hand Control', 'hc_remark'],
  'coloring_shading' => ['Coloring & Shading', 'cs_remark'],
  'observations'     => ['Observations', 'obs_remark'],
  'temperament'      => ['Temperament', 'temp_remark'],
  'attendance'       => ['Attendance', 'att_remark'],
  'homework'         => ['Homework', 'hw_remark'],
];

$pdf->SetFont('Arial','',11);
foreach ($fields as $field => [$label, $remarkCol]) {
    $score = (int)($row[$field] ?? 0);
    $grade = scoreLabel($score);
    $comment = $row[$remarkCol] ?? '';

    $pdf->SetFont('Arial','B',11);
    $pdf->SetFillColor(110,37,91);
    $pdf->SetTextColor(255);
    $pdf->Cell(60,10,strtoupper($label),1,0,'L',true);

    $pdf->SetFont('Arial','B',12);
    $pdf->SetTextColor(0);
    $pdf->Cell(30,10,$grade,1,0,'C');

    $pdf->SetFont('Arial','',10);
    $pdf->MultiCell(100,10,$comment,1);
}

// Overall Performance
$pdf->Ln(5);
$pdf->SetFont('Arial','B',12);
$pdf->SetFillColor(204,255,204);
$pdf->Cell(0,10,'Overall Performance: ' . $overallPerformance,1,1,'C',true);

// Signatures
$pdf->Ln(10);
$pdf->SetTextColor(0);
$pdf->Cell(80,10,'________________________',0,0,'C');
$pdf->Cell(30);
$pdf->Cell(80,10,'________________________',0,1,'C');
$pdf->Cell(80,10,"Teacher's Signature",0,0,'C');
$pdf->Cell(30);
$pdf->Cell(80,10,"Parent's Signature",0,1,'C');

// Output
$filename = "progress_{$range}_{$studentName}.pdf";
$pdf->Output('D', $filename);
exit;
