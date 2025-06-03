<?php
// action/upload_verifications.php
require_once __DIR__ . '/../config/session.php';
if (empty($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true) {
    header('Location: ../login/index.php');
    exit();
}

require __DIR__ . '/../config/db.php';
$studentId = (int)$_SESSION['student_id'];

// Prepare upload directory
$uploadDir = __DIR__ . '/verifications/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$errors = [];

// Handle profile photo
if (!empty($_FILES['profile_photo']['tmp_name'])) {
    $ext       = pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
    $photoName = "photo_{$studentId}_" . time() . ".{$ext}";
    if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $uploadDir . $photoName)) {
        $photoPath = "action/verifications/{$photoName}";
    } else {
        $errors[] = 'Unable to save profile photo.';
    }
} else {
    $errors[] = 'Profile photo is required.';
}

// Handle government ID
if (!empty($_FILES['govt_id']['tmp_name'])) {
    $ext    = pathinfo($_FILES['govt_id']['name'], PATHINFO_EXTENSION);
    $idName = "id_{$studentId}_" . time() . ".{$ext}";
    if (move_uploaded_file($_FILES['govt_id']['tmp_name'], $uploadDir . $idName)) {
        $idPath = "action/verifications/{$idName}";
    } else {
        $errors[] = 'Unable to save government ID.';
    }
} else {
    $errors[] = 'Government ID is required.';
}

if (empty($errors)) {
    // Upsert record
    $stmt = $conn->prepare("SELECT verification_id FROM student_verifications WHERE student_id = ?");
    $stmt->bind_param('i', $studentId);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows) {
        // Update
        $stmt->close();
        $upd = $conn->prepare("
            UPDATE student_verifications
               SET photo_path = ?, id_path = ?, status = 'pending',
                   uploaded_at = NOW(), verified_at = NULL
             WHERE student_id = ?
        ");
        $upd->bind_param('ssi', $photoPath, $idPath, $studentId);
        $upd->execute();
        $upd->close();
    } else {
        // Insert
        $stmt->close();
        $ins = $conn->prepare("
            INSERT INTO student_verifications
                (student_id, photo_path, id_path, status)
            VALUES (?, ?, ?, 'pending')
        ");
        $ins->bind_param('iss', $studentId, $photoPath, $idPath);
        $ins->execute();
        $ins->close();
    }

    $_SESSION['verification_message'] = 'Documents uploaded successfully. Status: Pending.';
} else {
    $_SESSION['verification_message'] = implode(' ', $errors);
}

// Redirect back
header('Location: ../dashboard/verifications.php');
exit();
