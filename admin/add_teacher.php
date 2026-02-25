<?php
session_start();
include '../config/db.php';
require_once '../config/security.php';

if(!isset($_SESSION['admin_logged_in'])){
    header("Location: login.php");
    exit();
}

$security = new SecurityManager($conn);
$message  = "";

if(isset($_POST['add_teacher'])){
    $fullname = trim($_POST['fullname'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $security->generateSecurePassword();

    $check = $conn->prepare("SELECT teacher_id FROM teachers WHERE email = ?");
    $check->bind_param("s", $email);
    $check->execute();
    $exists = $check->get_result()->num_rows > 0;
    $check->close();

    if ($exists) {
        $message = "Teacher already exists!";
    } else {
        $hash = $security->hashPassword($password);
        $stmt = $conn->prepare(
            "INSERT INTO teachers (fullname, email, password, status, force_password_change)
             VALUES (?, ?, ?, 'active', 1)"
        );
        $stmt->bind_param("sss", $fullname, $email, $hash);
        if ($stmt->execute()) {
            $message = "Teacher added successfully! Temporary password: <strong>" . htmlspecialchars($password) . "</strong> (shown once – please note it)";
        } else {
            $message = "Error adding teacher: " . $stmt->error;
        }
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Add Teacher</title>
    <link rel="stylesheet" href="../assets/css/admin-auth.css">
</head>
<body>

<div class="auth-card">
    <h2>Add Teacher</h2>

    <?php if($message): ?>
        <div class="message"><?= $message ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label>Full Name</label>
            <input type="text" name="fullname" required>
        </div>

        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" required>
        </div>

        <button class="btn" name="add_teacher">Add Teacher</button>
    </form>

    <div class="auth-links">
        <a href="manage_teachers.php">Back</a>
    </div>
</div>

</body>
</html>
