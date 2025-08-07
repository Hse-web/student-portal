<?php
// File: admin_export_payments.php


require_once __DIR__ . '/../../config/bootstrap.php';
require_role('admin');
require_once __DIR__ . '/../../config/db.php';

// ─── 1) Read filters from GET ────────────────────────────────────────
$searchTerm   = trim($_GET['search']    ?? '');
$centreFilter = (int) ($_GET['centre']  ?? 0);
$planFilter   = (int) ($_GET['plan']    ?? 0);
$statusFilter = trim($_GET['status']   ?? '');

// ─── 2) Build the base SQL with dynamic WHERE clauses ───────────────
$sql = "
  SELECT
    pp.id               AS proof_id,
    pp.student_id,
    pp.uploaded_at,
    pp.payment_method,
    pp.txn_id,
    pp.amount          AS proof_amount,
    pp.status          AS proof_status,
    s.name             AS student_name,
    s.email            AS student_email,
    s.group_name       AS group_name,
    s.centre_id,
    ss.plan_id,
    p.plan_name
  FROM payment_proofs pp
  JOIN students            s  ON s.id = pp.student_id
  LEFT JOIN student_subscriptions ss
                         ON ss.student_id = s.id
                         AND ss.id = (
                              SELECT id 
                                FROM student_subscriptions 
                               WHERE student_id = s.id
                               ORDER BY subscribed_at DESC 
                               LIMIT 1
                           )
  LEFT JOIN payment_plans  p  ON p.id = ss.plan_id
  WHERE 1=1
";

$params     = [];
$paramTypes = '';

if ($searchTerm !== '') {
    $sql .= " AND (
                s.name       LIKE ?
             OR s.email      LIKE ?
             OR s.group_name LIKE ?
            )";
    $wild = "%{$searchTerm}%";
    $paramTypes .= 'sss';
    $params[] = $wild;
    $params[] = $wild;
    $params[] = $wild;
}

if ($centreFilter > 0) {
    $sql .= " AND s.centre_id = ?";
    $paramTypes .= 'i';
    $params[] = $centreFilter;
}

if ($planFilter > 0) {
    $sql .= " AND ss.plan_id = ?";
    $paramTypes .= 'i';
    $params[] = $planFilter;
}

if (in_array($statusFilter, ['Pending','Approved','Rejected'], true)) {
    $sql .= " AND pp.status = ?";
    $paramTypes .= 's';
    $params[] = $statusFilter;
}

$sql .= " ORDER BY pp.uploaded_at DESC";

$stmt = $conn->prepare($sql);
if ($paramTypes !== '') {
    $stmt->bind_param($paramTypes, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$rows   = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ─── 3) Determine output format ──────────────────────────────────────
$format = strtolower(trim($_GET['format'] ?? 'csv'));

if ($format === 'pdf') {
    // ─── PDF Export using FPDF ────────────────────────────────────────
  require_once __DIR__ . '/../../libs/fpdf.php';

    // Create a new PDF document (A4, Landscape)
    $pdf = new FPDF('L', 'mm', 'A4');
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 10, 'Payment Proofs Export', 0, 1, 'C');
    $pdf->Ln(4);

    // Set up column headers (smaller font, bold)
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetFillColor(230, 230, 230);

    // Column widths in mm (adjust as needed)
    $colWidths = [
        'proof_id'      => 15,
        'student_name'  => 40,
        'student_email' => 50,
        'group_name'    => 35,
        'plan_name'     => 40,
        'proof_status'  => 20,
        'proof_amount'  => 20,
        'uploaded_at'   => 30,
    ];

    // Header row
    $pdf->Cell($colWidths['proof_id'],      8, 'ID',           1, 0, 'C', true);
    $pdf->Cell($colWidths['student_name'],  8, 'Student Name', 1, 0, 'C', true);
    $pdf->Cell($colWidths['student_email'], 8, 'Email',        1, 0, 'C', true);
    $pdf->Cell($colWidths['group_name'],    8, 'Group',        1, 0, 'C', true);
    $pdf->Cell($colWidths['plan_name'],     8, 'Plan',         1, 0, 'C', true);
    $pdf->Cell($colWidths['proof_status'],  8, 'Status',       1, 0, 'C', true);
    $pdf->Cell($colWidths['proof_amount'],  8, 'Amount (₹)',   1, 0, 'C', true);
    $pdf->Cell($colWidths['uploaded_at'],   8, 'Uploaded At',  1, 1, 'C', true);

    // Populate data rows
    $pdf->SetFont('Arial', '', 9);
    foreach ($rows as $r) {
        $pdf->Cell($colWidths['proof_id'],      6, $r['proof_id'],                             1, 0, 'C');
        $pdf->Cell($colWidths['student_name'],  6, substr($r['student_name'],  0, 25),          1, 0, 'L');
        $pdf->Cell($colWidths['student_email'], 6, substr($r['student_email'], 0, 30),          1, 0, 'L');
        $pdf->Cell($colWidths['group_name'],    6, substr($r['group_name'],    0, 20),          1, 0, 'L');
        $pdf->Cell($colWidths['plan_name'],     6, substr($r['plan_name'],     0, 25),          1, 0, 'L');
        $pdf->Cell($colWidths['proof_status'],  6, $r['proof_status'],                           1, 0, 'C');
        $pdf->Cell($colWidths['proof_amount'],  6, number_format($r['proof_amount'], 2),         1, 0, 'R');
        $pdf->Cell($colWidths['uploaded_at'],   6, date('Y-m-d', strtotime($r['uploaded_at'])),   1, 1, 'C');
    }

    // Output the PDF directly to browser
    $filename = 'payment_proofs_export_' . date('Ymd_His') . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    $pdf->Output('I', $filename);
    exit;
}
else {
    // ─── CSV Export ─────────────────────────────────────────────────────
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=payment_proofs_export.csv');

    $out = fopen('php://output', 'w');

    // Column headings
    fputcsv($out, [
      'ID',
      'Student Name',
      'Student Email',
      'Group',
      'Plan',
      'Status',
      'Amount (₹)',
      'Uploaded At'
    ]);

    // Data rows
    foreach ($rows as $r) {
        fputcsv($out, [
          $r['proof_id'],
          $r['student_name'],
          $r['student_email'],
          $r['group_name'],
          $r['plan_name'] ?: '—',
          $r['proof_status'],
          number_format($r['proof_amount'], 2),
          $r['uploaded_at']
        ]);
    }

    fclose($out);
    exit;
}
?>
