<?php
include "../config/db.php";
$message = "";

if (isset($_POST['register'])) {
  $fullname = mysqli_real_escape_string($conn, $_POST['fullname']);
  $email    = mysqli_real_escape_string($conn, $_POST['email']);
  $gender   = mysqli_real_escape_string($conn, $_POST['gender']);
  $course   = mysqli_real_escape_string($conn, $_POST['course']);
  $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

  $check = mysqli_query($conn, "SELECT id FROM students WHERE email='$email'");
  if (mysqli_num_rows($check) > 0) {
    $message = "Email already registered!";
  } else {
    $sql = "INSERT INTO students (fullname,email,gender,course,password)
            VALUES ('$fullname','$email','$gender','$course','$password')";
    if (mysqli_query($conn, $sql)) {
      $message = "Registration successful!";
    } else {
      $message = "Registration failed!";
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
      <label>Full Name</label>
      <input type="text" name="fullname" required>
    </div>

    <div class="form-group">
      <label>Email</label>
      <input type="email" name="email" required>
    </div>

    <div class="form-group">
      <label>Gender</label>
      <select name="gender" required>
        <option value="">Select</option>
        <option>Male</option>
        <option>Female</option>
      </select>
    </div>

    <div class="form-group">
      <label>Course</label>
      <input type="text" name="course" required>
    </div>

    <div class="form-group">
      <label>Password</label>
      <input type="password" name="password" required>
    </div>

    <button class="btn" name="register">Register</button>
  </form>

  <div class="auth-links">
    <a href="login.php">Already registered? Login</a>
  </div>
</div>

</body>
</html>
