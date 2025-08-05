<?php
$data = json_decode(file_get_contents('php://input'), true);
$studentId = $data['student_id'] ?? null;
$date = date('Y-m-d');

if (!$studentId) {
    echo json_encode(['success' => false, 'message' => 'Missing student ID']);
    exit;
}

$attendanceFile = '../data/attendance.json';
$attendance = file_exists($attendanceFile) ? json_decode(file_get_contents($attendanceFile), true) : [];

if (!isset($attendance[$studentId])) {
    $attendance[$studentId] = [];
}

if (in_array($date, $attendance[$studentId])) {
    echo json_encode(['success' => false, 'message' => 'Attendance already marked for today']);
    exit;
}
