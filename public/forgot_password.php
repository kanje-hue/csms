<?php
require_once '../config/db.php';
require_once '../config/security.php';
require_once '../config/email_config.php';

$security = new SecurityManager($conn);
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $email = trim($_POST['email']);
    $role  = trim($_POST['role']);

    $roleMap = [
        'admin'   => ['table'=>'admins','pk'=>'admin_id','type'=>'admins'],
        'teacher' => ['table'=>'teachers','pk'=>'teacher_id','type'=>'teachers'],
        'student' => ['table'=>'students','pk'=>'student_id','type'=>'students']
    ];

    if (!isset($roleMap[$role])) {
        $message = "Invalid role selected.";
    } else {

        $meta = $roleMap[$role];

        $stmt = $conn->prepare(
            "SELECT {$meta['pk']} as user_id 
             FROM {$meta['table']} 
             WHERE email=? AND deleted=0 LIMIT 1"
        );

        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user) {

            $token = $security->generateResetToken(
                $meta['type'],
                $user['user_id'],
                $email
            );

            $resetLink = "http://localhost/csms/public/reset_password.php?token=$token";

            send_email(
                $email,
                $email,
                "CSMS Password Reset",
                "
                <h3>Password Reset Request</h3>
                <p>Click link below to reset your password:</p>
                <a href='$resetLink'>$resetLink</a>
                <p>This link expires in 1 hour.</p>
                "
            );
        }

        $message = "If that email exists, a reset link has been sent.";
    }
}
?>

<h2>Forgot Password</h2>

<form method="POST">
    <select name="role" required>
        <option value="">Select Role</option>
        <option value="admin">Admin</option>
        <option value="teacher">Teacher</option>
        <option value="student">Student</option>
    </select>

    <input type="email" name="email" required placeholder="Enter Email">

    <button type="submit">Send Reset Link</button>
</form>

<p><?= $message ?></p>