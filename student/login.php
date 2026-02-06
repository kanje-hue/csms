<?php
include "../config/db.php";
$message = "";

if (isset($_POST['login'])) {
  $email = mysqli_real_escape_string($conn, $_POST['email']);
  $pass  = $_POST['password'];

  $sql = mysqli_query($conn, "SELECT * FROM students WHERE email='$email'");
  if (mysqli_num_rows($sql) == 1) {
    $row = mysqli_fetch_assoc($sql);
    if (password_verify($pass, $row['password'])) {
      $message = "Login successful!";
    } else {
      $message = "Wrong password!";
    }
  } else {
    $message = "Account not found!";
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
    <a href="register.php">Create account</a>
  </div>
</div>

</body>
</html>
