<?php
// File: config/helpers/audit.php

/**
 * Write an audit log entry.
 */
function log_audit(mysqli $conn, int $userId, string $operation,
                   string $tableName, int $recordId, array $changes = null): void
{
    $sql  = "INSERT INTO audit_logs
               (user_id, operation, table_name, record_id, changes)
             VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $json = $changes ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null;
    $stmt->bind_param('issis', $userId, $operation, $tableName, $recordId, $json);
    $stmt->execute();
    $stmt->close();
}
