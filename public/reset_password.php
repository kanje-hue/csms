<?php
require_once '../config/db.php';
require_once '../config/security.php';

$security = new SecurityManager($conn);

$token = $_GET['token'] ?? '';
$message = '';
$valid = false;

if ($token) {
    $tokenData = $security->verifyResetToken($token);
    if ($tokenData) {
        $valid = true;
    } else {
        $message = "Invalid or expired reset link.";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['token'];
    $password = $_POST['password'];
    $confirm  = $_POST['confirm_password'];

    $tokenData = $security->verifyResetToken($token);

    if (!$tokenData) {
        $message = "Invalid or expired reset link.";
    } elseif ($password !== $confirm) {
        $message = "Passwords do not match.";
    } else {

        $strength = $security->validatePasswordStrength($password);

        if (!$strength['valid']) {
            $message = $strength['message'];
        } else {

            $user_id   = $tokenData['user_id'];
            $user_type = $tokenData['user_type'];

            $new_hash = password_hash($password, PASSWORD_BCRYPT);

            $security->updateUserPassword($user_type, $user_id, $new_hash);
            $security->invalidateToken($token);

            $message = "Password reset successful! You can now login.";
            $valid = false;
        }
    }
}
?>

<?php if ($valid): ?>
<h2>Reset Password</h2>
<form method="POST">
    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
    <input type="password" name="password" required placeholder="New Password">
    <input type="password" name="confirm_password" required placeholder="Confirm Password">
    <button type="submit">Reset Password</button>
</form>
<?php else: ?>
<p><?= $message ?></p>
<?php endif; ?>