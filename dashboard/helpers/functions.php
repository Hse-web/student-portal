<?php
// File: dashboard/helpers/functions.php

/**
 * Return the currently applied art_group_id for $studentId,
 * or fall back on the student’s original group_name → art_groups.id.
 */
function get_current_group_id(mysqli $conn, int $studentId): int {
    // 1) Most recent *applied* promotion
    $stmt = $conn->prepare("
      SELECT art_group_id
        FROM student_promotions
       WHERE student_id = ?
         AND is_applied  = 1
       ORDER BY effective_date DESC
       LIMIT 1
    ");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $stmt->bind_result($grp);
    if ($stmt->fetch()) {
        $stmt->close();
        return (int)$grp;
    }
    $stmt->close();

    // 2) Fallback: students.group_name → art_groups.id
    $stmt = $conn->prepare("
      SELECT ag.id
        FROM students s
        JOIN art_groups ag ON ag.label = s.group_name
       WHERE s.id = ?
       LIMIT 1
    ");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $stmt->bind_result($grp);
    if ($stmt->fetch()) {
        $stmt->close();
        return (int)$grp;
    }
    $stmt->close();

    return 0;
}

/**
 * Return the currently applied art_group label for $studentId,
 * or fall back to students.group_name if no promotion found.
 */
function get_current_group_label(mysqli $conn, int $studentId): string {
    $id = get_current_group_id($conn, $studentId);
    if ($id > 0) {
        $stmt = $conn->prepare("SELECT label FROM art_groups WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->bind_result($label);
        if ($stmt->fetch()) {
            $stmt->close();
            return $label;
        }
        $stmt->close();
    }
    // extreme fallback
    return '';
}

function get_next_group_label(mysqli $conn, int $studentId): string {
    // 1) Find the sort_order of the current group
    $curOrder = null;
    $stmt = $conn->prepare("
      SELECT ag.sort_order
        FROM student_promotions sp
        JOIN art_groups ag ON ag.id = sp.art_group_id
       WHERE sp.student_id = ?
         AND sp.is_applied = 1
       ORDER BY sp.effective_date DESC
       LIMIT 1
    ");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $stmt->bind_result($curOrder);
    $stmt->fetch();
    $stmt->close();

    // 1a) If none via promotions, fall back to students.group_name → art_groups
    if ($curOrder === null) {
        $stmt = $conn->prepare("
          SELECT ag.sort_order
            FROM students s
            JOIN art_groups ag ON ag.label = s.group_name
           WHERE s.id = ?
           LIMIT 1
        ");
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $stmt->bind_result($curOrder);
        $stmt->fetch();
        $stmt->close();
        if ($curOrder === null) {
            return '';
        }
    }

    // 2) Fetch the very next art_group by sort_order
    $nextLabel = '';
    $stmt = $conn->prepare("
      SELECT label
        FROM art_groups
       WHERE sort_order > ?
       ORDER BY sort_order ASC
       LIMIT 1
    ");
    $stmt->bind_param('i', $curOrder);
    $stmt->execute();
    $stmt->bind_result($nextLabel);
    $stmt->fetch();
    $stmt->close();

    return $nextLabel ?? '';
}
