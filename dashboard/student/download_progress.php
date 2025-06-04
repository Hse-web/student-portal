<?php
// File: dashboard/student/download_progress.php

require_once __DIR__ . '/../../libs/fpdf.php';
session_start();
ob_clean();

require_once __DIR__     . '/../../config/session.php';
require_once __DIR__     . '/../../config/db.php';

// ─── 1) Authorization ───────────────────────────────────────────────────
if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'student') {
    header('Location: ../../login/index.php');
    exit;
}

$studentId = (int)($_SESSION['student_id'] ?? 0);
if ($studentId < 1) {
    echo "No student ID found in session.";
    exit;
}

// ─── 2) Fetch Student Info ──────────────────────────────────────────────
$stmt = $conn->prepare("SELECT id, name, group_name FROM students WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$stmt->bind_result($fetchedId, $studentName, $currentGroup);
$stmt->fetch();
$stmt->close();

// Compute “Next Group” by incrementing final letter (e.g. “Oil Pastels” → “Oil Pastelt”)
$nextGroup = preg_replace_callback(
    '/([A-Za-z])$/',
    fn($m) => chr(ord($m[1]) + 1),
    $currentGroup
);

// ─── 3) Determine Date Range ────────────────────────────────────────────
$range = $_GET['range'] ?? 'monthly';
$monthsBack = match($range) {
    'monthly' => 1,
    'quarter' => 3,
    'half'    => 6,
    default   => 1,
};

$end   = new DateTime();
$start = (clone $end)->modify('-'.($monthsBack - 1).' months')->modify('first day of');
$monthFrom = $start->format('Y-m');
$monthTo   = $end->format('Y-m');

$periodLabel = $start->format('M Y') . ' — ' . $end->format('M Y');

// ─── 4) Fetch Latest Progress Row in Range ─────────────────────────────
$stmt = $conn->prepare("
  SELECT month,
         hand_control,     hc_remark,
         coloring_shading, cs_remark,
         observations,     obs_remark,
         temperament,      temp_remark,
         attendance,       att_remark,
         homework,         hw_remark
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
    echo "No progress records found for the selected period.";
    exit;
}

// ─── 5) Grade‐Label Helpers ──────────────────────────────────────────────
function scoreLabel(int $code): string {
    return match($code) {
        5 => 'Excellent',
        4 => 'Very Good',
        3 => 'Good',
        2 => 'Average',
        1 => 'Needs Improvement',
        default => 'Not Rated',
    };
}

function computeOverall(array $row): string {
    $fields = [
        'hand_control',
        'coloring_shading',
        'observations',
        'temperament',
        'attendance',
        'homework'
    ];
    $sum = 0;
    $count = 0;
    foreach ($fields as $f) {
        $val = (int) ($row[$f] ?? 0);
        if ($val > 0) {
            $sum += $val;
            $count++;
        }
    }
    if ($count === 0) {
        return 'Not Rated';
    }
    // Average and round to nearest integer 1..5
    $avgCode = (int) round($sum / $count);
    return scoreLabel($avgCode);
}

$overallPerformance = computeOverall($row);


// ─── 6) Start Building the PDF ───────────────────────────────────────────
$pdf = new FPDF('P','mm','A4');
$pdf->AddPage();

// 6.1) Header Logo + Title
$logoPath = __DIR__ . '/../../assets/logo_desk.png';
if (file_exists($logoPath)) {
    $pdf->Image($logoPath, 10, 6, 30); // x=10mm, y=6mm, width=30mm (auto height)
}
$pdf->SetFont('Arial','B',20);
$pdf->Cell(0, 10, 'PROGRESS REPORT', 0, 1, 'C');
$pdf->SetFont('Arial','I',12);
// Use an actual em‐dash (—) between dates
$pdf->Cell(0, 10, "FROM {$periodLabel}", 0, 1, 'C');
$pdf->Ln(5);

// 6.2) Student Info Section
$pdf->SetFont('Arial','',11);
$pdf->SetFillColor(240,240,240);
$pdf->Cell(45, 10, 'Student Name:', 1, 0, 'L', true);
$pdf->Cell(145,10, $studentName,      1, 1, 'L');

$pdf->Cell(45, 10, 'Current Group:',   1, 0, 'L', true);
$pdf->Cell(70, 10, $currentGroup,     1, 0, 'L');
$pdf->Cell(35, 10, 'Next Group:',      1, 0, 'L', true);
$pdf->Cell(45, 10, $nextGroup,        1, 1, 'L');

$pdf->Ln(5);

// 6.3) Table Header Row (“TECHNICAL | GRADE | COMMENT”)
$pdf->SetFont('Arial','B',12);
$pdf->SetFillColor(255,204,0);          // Yellow: #FFCC00
$pdf->SetTextColor(0,0,0);
$pdf->Cell(60, 10, 'TECHNICAL', 1, 0, 'C', true);
$pdf->Cell(30, 10, 'GRADE',     1, 0, 'C', true);
$pdf->Cell(100,10, 'COMMENT',   1, 1, 'C', true);

// 6.4) Table Body: One Row per “technical” category
$fields = [
  'hand_control'     => ['Hand Control',     'hc_remark'],
  'coloring_shading' => ['Coloring & Shading','cs_remark'],
  'observations'     => ['Observations',     'obs_remark'],
  'temperament'      => ['Temperament',      'temp_remark'],
  'attendance'       => ['Attendance',       'att_remark'],
  'homework'         => ['Homework',         'hw_remark'],
];

foreach ($fields as $field => list($label, $remarkCol)) {
    $score   = (int) ($row[$field] ?? 0);
    $grade   = scoreLabel($score);
    $comment = $row[$remarkCol] ?? '';

    // 6.4.1) “Technical” column: purple background, white text
    $pdf->SetFont('Arial','B',11);
    $pdf->SetFillColor(110,37,91);   // Purple: #6E255B
    $pdf->SetTextColor(255,255,255); // White text
    $pdf->Cell(60, 10, strtoupper($label), 1, 0, 'L', true);

    // 6.4.2) “Grade” column: light gray background, black text
    $pdf->SetFont('Arial','B',11);
    $pdf->SetFillColor(235,235,235);  // Light gray
    $pdf->SetTextColor(0,0,0);
    $pdf->Cell(30, 10, $grade, 1, 0, 'C', true);

    // 6.4.3) “Comment” column: white background, black text, allow multi‐line
    $pdf->SetFont('Arial','',10);
    $pdf->SetFillColor(255,255,255);
    $pdf->SetTextColor(0,0,0);
    $pdf->MultiCell(100, 10, $comment, 1);
}

// 6.5) Spacer
$pdf->Ln(5);

// 6.6) Overall Performance box (light green background)
$pdf->SetFont('Arial','B',12);
$pdf->SetFillColor(204,255,204);    // Very light green
$pdf->SetTextColor(0,0,0);
$pdf->Cell(0, 10, 'Overall Performance: ' . $overallPerformance, 1, 1, 'C', true);

// 6.7) Signatures lines at the bottom
$pdf->Ln(15);
$pdf->SetFont('Arial','',11);
$pdf->Cell(80, 10, '________________________', 0, 0, 'C');
$pdf->Cell(30, 10, '', 0, 0);
$pdf->Cell(80, 10, '________________________', 0, 1, 'C');
$pdf->Cell(80, 6, "Teacher's Signature",     0, 0, 'C');
$pdf->Cell(30, 6, '', 0, 0);
$pdf->Cell(80, 6, "Parent's Signature",      0, 1, 'C');

// 6.8) Output the PDF as a Download
$filename = "progress_{$range}_{$studentName}.pdf";
$pdf->Output('D', $filename);
exit;
