<?php
// dashboard/admin/ajax/get_plans.php
require_once __DIR__.'/../../../config/session.php';
require_role('admin');
require_once __DIR__.'/../../../config/db.php';

header('Content-Type: application/json');
if (!empty($_POST['centre_id']) && !empty($_POST['group_name'])) {
    $centreId  = (int) $_POST['centre_id'];
    $groupName = $_POST['group_name'];  // string, will use in prepared statement

    $stmt = $pdo->prepare(
        "SELECT id, plan_name 
         FROM payment_plans 
         WHERE centre_id = ? AND group_name = ?"
    );
    $stmt->execute([$centreId, $groupName]);
    $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($plans) {
        echo '<option value="">-- Select Plan --</option>';
        foreach ($plans as $plan) {
            $planName = htmlspecialchars($plan['plan_name']);
            $planId   = (int) $plan['id'];
            echo "<option value=\"{$planId}\">{$planName}</option>";
        }
    } else {
        // No plans found for the given group and centre
        echo '<option value="">No plans available</option>';
    }
}