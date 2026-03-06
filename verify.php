<?php
/**
 * verify.php - Handle email verification
 */

require_once 'config/db.php';
require_once 'config/security.php';

$security = new SecurityManager($conn);

$token = $_GET['token'] ?? '';

if ($security->verifyEmailToken($token)) {
    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Email verified successfully! You can now log in.'];
} else {
    $_SESSION['flash'] = ['type' => 'error', 'message' => 'Invalid or expired verification link.'];
}

header('Location: index.php?view=login');
exit;
?>