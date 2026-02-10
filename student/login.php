<?php
session_start();
include "../config/db.php";
$message = "";

if (isset($_POST['login'])) {
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $pass  = $_POST['password'];

    $sql = mysqli_query($conn, "SELECT * FROM students WHERE email='$email' AND status='active'");

    if (mysqli_num_rows($sql) === 1) {
        $row = mysqli_fetch_assoc($sql);

        if (password_verify($pass, $row['password'])) {
            $_SESSION['student_id']   = $row['student_id'];
            $_SESSION['student_name'] = $row['name'];
            $_SESSION['course_id']    = $row['course_id'];
            $_SESSION['semester']     = $row['semester'];
            header("Location: dashboard.php");
            exit();
        } else {
            $message = "Wrong password!";
        }
    } else {
        $message = "Account not found or inactive!";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
  <title>Student Login</title>
  <link rel="stylesheet" href="../assets/css/auth.css">
</head>
<body>
<div class="auth-card">
  <h2>Student Login</h2>
  <p>Welcome back</p>

  <?php if ($message): ?>
    <div class="message"><?= $message ?></div>
  <?php endif; ?>

  <form method="POST">
    <div class="form-group">
      <label>Email</label>
      <input type="email" name="email" required>
    </div>

    <div class="form-group">
      <label>Password</label>
      <input type="password" name="password" required>
    </div>

    <button class="btn" name="login">Login</button>
  </form>

  <div class="auth-links">
    <a href="forgot_password.php">Forgot your password?</a> |
    <a href="register.php">Create account</a>
  </div>
</div>
</body>
</html>
