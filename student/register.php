<?php
session_start();
include "../config/db.php";
$message = "";

if (isset($_POST['register'])) {
    $reg_number = mysqli_real_escape_string($conn, $_POST['reg_number']);
    $name       = mysqli_real_escape_string($conn, $_POST['name']);
    $email      = mysqli_real_escape_string($conn, $_POST['email']);
    $course_id  = (int)$_POST['course_id'];
    $semester   = mysqli_real_escape_string($conn, $_POST['semester']);
    $password   = $_POST['password'];
    $confirm    = $_POST['confirm_password'];

    if ($password !== $confirm) {
        $message = "Passwords do not match!";
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $check = mysqli_query($conn, "SELECT student_id FROM students WHERE email='$email'");
        if (mysqli_num_rows($check) > 0) {
            $message = "Email already registered!";
        } else {
            // Note: status = inactive, admin must approve
            $sql = "INSERT INTO students (reg_number, name, email, password, course_id, semester, status) 
                    VALUES ('$reg_number','$name','$email','$hash',$course_id,'$semester','inactive')";
            if (mysqli_query($conn, $sql)) {
                $message = "Registration successful! Wait for admin approval.";
            } else {
                $message = "Registration failed: " . mysqli_error($conn);
            }
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Student Registration</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
</head>
<body>
<div class="auth-card">
    <h2>Student Registration</h2>
    <p>Create your account</p>

    <?php if ($message): ?>
        <div class="message"><?= $message ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label>Registration Number</label>
            <input type="text" name="reg_number" required>
        </div>

        <div class="form-group">
            <label>Full Name</label>
            <input type="text" name="name" required>
        </div>

        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" required>
        </div>

        <div class="form-group">
            <label>Course</label>
            <select name="course_id" required>
                <option value="">--Select Course--</option>
                <?php
                $courses = mysqli_query($conn, "SELECT * FROM courses");
                while ($c = mysqli_fetch_assoc($courses)) {
                    echo "<option value='{$c['course_id']}'>{$c['course_name']}</option>";
                }
                ?>
            </select>
        </div>

        <div class="form-group">
            <label>Semester</label>
            <input type="text" name="semester" required>
        </div>

        <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" required>
        </div>

        <div class="form-group">
            <label>Confirm Password</label>
            <input type="password" name="confirm_password" required>
        </div>

        <button class="btn" name="register">Register</button>
    </form>

    <div class="auth-links">
        <a href="login.php">Already registered? Login</a>
    </div>
</div>
</body>
</html>
