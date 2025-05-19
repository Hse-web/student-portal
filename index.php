<?php
session_start();

// if already logged in, send to the right dashboard
if (!empty($_SESSION['logged_in'])) {
    if (($_SESSION['role'] ?? '') === 'admin') {
        header('Location: dashboard/admin/index.php');
        exit;
    } elseif (($_SESSION['role'] ?? '') === 'student') {
        header('Location: dashboard/student/index.php');
        exit;
    }
}

// otherwise redirect to your real login page:
// adjust this path to wherever your form actually lives!
header('Location: login.php');
exit;
