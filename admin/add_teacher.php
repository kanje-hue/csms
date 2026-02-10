<?php
session_start();
include '../config/db.php';

if(!isset($_SESSION['admin_logged_in'])){
    header("Location: login.php");
    exit();
}

$message = "";

if(isset($_POST['add_teacher'])){
    $fullname = mysqli_real_escape_string($conn, $_POST['fullname']);
    $email    = mysqli_real_escape_string($conn, $_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    $check = mysqli_query($conn, "SELECT teacher_id FROM teachers WHERE email='$email'");
    if(mysqli_num_rows($check) > 0){
        $message = "Teacher already exists!";
    } else {
        mysqli_query($conn, "
            INSERT INTO teachers (fullname,email,password,status)
            VALUES ('$fullname','$email','$password','active')
        ");
        $message = "Teacher added successfully!";
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

        <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" required>
        </div>

        <button class="btn" name="add_teacher">Add Teacher</button>
    </form>

    <div class="auth-links">
        <a href="manage_teachers.php">Back</a>
    </div>
</div>

</body>
</html>
