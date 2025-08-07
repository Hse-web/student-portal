<?php
require_once __DIR__ . '/fee_calculator.php';

/**
 * Compute the current amount due and next due date for a student.
 * @return array [ totalDue (float), nextDueLabel (string), nextDueISO (string) ]
 */
function compute_student_due(mysqli $conn, int $studentId): array {
    // 1) Have they ever paid?
    $stmt = $conn->prepare("
      SELECT COUNT(*) 
        FROM payments 
       WHERE student_id = ? 
         AND status     = 'Paid'
    ");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $stmt->bind_result($paidCount);
    $stmt->fetch();
    $stmt->close();
    $isNew = ($paidCount === 0);

    // 2) Latest subscription row (to get the plan_id)
    $stmt = $conn->prepare("
      SELECT plan_id, subscribed_at
        FROM student_subscriptions
       WHERE student_id = ?
       ORDER BY subscribed_at DESC
       LIMIT 1
    ");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $stmt->bind_result($planId, $origSubscribedAt);
    if (! $stmt->fetch()) {
      $stmt->close();
      return [0.0, 'n/a', '1970-01-01'];
    }
    $stmt->close();

    // 3) Decide “base date” for due-date math:
    //    – first-ever: use original subscribed_at
    //    – renewals: use the last payment’s paid_at
    $baseDate = $origSubscribedAt ?? '1970-01-01';
    if (! $isNew) {
      $stmt = $conn->prepare("
        SELECT paid_at
          FROM payments
         WHERE student_id = ?
           AND status     = 'Paid'
         ORDER BY paid_at DESC
         LIMIT 1
      ");
      $stmt->bind_param('i', $studentId);
      $stmt->execute();
      $stmt->bind_result($lastPaidAt);
      if ($stmt->fetch() && $lastPaidAt !== null) {
        $baseDate = $lastPaidAt;
      }
      $stmt->close();
    }

    // 4) Late‐fee flag (only on renewals, only after the 5th)
    $day    = (int)date('j');
    $isLate = (! $isNew && $day > 5);

    // 5) Get fee breakdown
    $fee = calculate_student_fee(
      $conn,
      $studentId,
      $planId,
      $isNew,
      $isLate
    );

    // 6) Find duration
    $stmt = $conn->prepare("
      SELECT duration_months
        FROM payment_plans
       WHERE id = ?
    ");
    $stmt->bind_param('i', $planId);
    $stmt->execute();
    $stmt->bind_result($durationMonths);
    $stmt->fetch();
    $stmt->close();

    // 7) Compute next‐due = 5th of month after baseDate + duration
    try {
        $dt = new DateTime($baseDate);
        $dt->modify("+{$durationMonths} months")
           ->setDate(
             (int)$dt->format('Y'),
             (int)$dt->format('m'),
             5
           );
        $dueLabel = $dt->format('M j, Y');
        $dueISO   = $dt->format('Y-m-d');
    } catch (Exception $e) {
        $dueLabel = 'n/a';
        $dueISO   = '1970-01-01';
    }

    return [
      (float)$fee['total'],
      $dueLabel,
      $dueISO,
    ];
}

/**
 * Set a flash message into session.
 *
 * @param string $msg
 * @param string $type  success|error|info
 */
function set_flash(string $msg, string $type = 'success'): void {
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}

/**
 * Get & clear the flash message from session.
 *
 * @return array|null  ['msg'=>..., 'type'=>...] or null
 */
function get_flash(): ?array {
    if (! empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

/**
 * Create notifications for one or more students.
 *
 * @param mysqli      $conn
 * @param int|int[]   $studentIds  Single ID or array of student IDs
 * @param string      $title
 * @param string      $message
 */
function create_notification(mysqli $conn, $studentIds, string $title, string $message): void {
    if (! is_array($studentIds)) {
        $studentIds = [$studentIds];
    }
    $stmt = $conn->prepare("
        INSERT INTO notifications
          (student_id, title, message, is_read, created_at)
        VALUES
          (?, ?, ?, 0, NOW())
    ");
    foreach ($studentIds as $sid) {
        $stmt->bind_param('iss', $sid, $title, $message);
        $stmt->execute();
    }
    $stmt->close();
}

/**
 * Notify an admin user (internal).
 *
 * @param int     $adminId
 * @param string  $title
 * @param string  $message
 * @param mysqli  $conn
 */
function notify_admin(int $adminId, string $title, string $message, mysqli $conn): void {
    $stmt = $conn->prepare("
        INSERT INTO admin_notifications
          (admin_id, title, message)
        VALUES
          (?, ?, ?)
    ");
    $stmt->bind_param('iss', $adminId, $title, $message);
    $stmt->execute();
    $stmt->close();
}
