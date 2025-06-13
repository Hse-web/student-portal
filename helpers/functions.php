<?php
// File: dashboard/includes/functions.php

/**
 * Set a flash message into session.
 */
function set_flash(string $msg, string $type = 'success'): void {
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}

/**
 * Get & clear the flash message from session.
 */
function get_flash(): ?array {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

/**
 * Returns the label of the applied art group for $studentId,
 * or falls back to students.group_name if no promotion found.
 */
function get_current_group_label(mysqli $conn, int $studentId): string {
    // 1) Try promotions
    $stmt = $conn->prepare("
      SELECT ag.label
        FROM student_promotions sp
        JOIN art_groups ag ON ag.id = sp.art_group_id
       WHERE sp.student_id = ?
         AND sp.is_applied = 1
       ORDER BY sp.effective_date DESC
       LIMIT 1
    ");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $stmt->bind_result($label);
    if ($stmt->fetch()) {
        $stmt->close();
        return $label;
    }
    $stmt->close();

    // 2) Fallback to students.group_name
    $stmt = $conn->prepare("
      SELECT group_name
        FROM students
       WHERE id = ?
       LIMIT 1
    ");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $stmt->bind_result($label);
    if (!$stmt->fetch()) {
        $label = '';
    }
    $stmt->close();
    return $label;
}

/**
 * Returns the label of the upcoming art group for $studentId,
 * or empty string if none scheduled.
 */
function get_next_group_label(mysqli $conn, int $studentId): string {
    $stmt = $conn->prepare("
      SELECT ag.label
        FROM student_promotions sp
        JOIN art_groups ag ON ag.id = sp.art_group_id
       WHERE sp.student_id = ?
         AND sp.is_applied = 0
       ORDER BY sp.effective_date ASC
       LIMIT 1
    ");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $stmt->bind_result($label);
    if ($stmt->fetch()) {
        $stmt->close();
        return $label;
    }
    $stmt->close();
    return '';
}
