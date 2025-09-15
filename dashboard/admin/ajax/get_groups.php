<?php
// dashboard/admin/ajax/get_groups.php

require_once __DIR__.'/../../../config/session.php';
require_role('admin');
require_once __DIR__.'/../../../config/db.php';

header('Content-Type: application/json');

if (
  empty($_POST['centre_id']) ||
  ! ctype_digit($_POST['centre_id'])
) {
  exit;
}

// Only proceed if centre_id is provided
if (!empty($_POST['centre_id'])) {
    $centreId = (int) $_POST['centre_id'];  // cast to int for safety
    // Prepare and execute a query to get distinct group names for this centre
    $stmt = $pdo->prepare("SELECT DISTINCT group_name FROM payment_plans WHERE centre_id = ?");
    $stmt->execute([$centreId]);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($groups) {
        // Output an initial prompt option, if desired
        echo '<option value="">-- Select Group --</option>';
        // Loop through each group and output as an <option>
        foreach ($groups as $row) {
            $groupName = htmlspecialchars($row['group_name']);
            echo "<option value=\"{$groupName}\">{$groupName}</option>";
        }
    } else {
        // No groups found for this centre
        echo '<option value="">No groups available</option>';
    }
}

$centre_id = (int)$_POST['centre_id'];
if ($centre_id < 1) {
  exit;
}

$stmt = $conn->prepare("
  SELECT DISTINCT group_name
    FROM payment_plans
   WHERE centre_id = ?
   ORDER BY group_name
");
$stmt->bind_param('i', $centre_id);
$stmt->execute();
$res = $stmt->get_result();

echo '<option value="" disabled selected>— Select Group —</option>';
while ($row = $res->fetch_assoc()) {
  $g = htmlspecialchars($row['group_name'], ENT_QUOTES, 'UTF-8');
  echo "<option value=\"{$g}\">{$g}</option>";
}
$stmt->close();
