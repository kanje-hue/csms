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
            $_SESSION['role']         = $row['role']; // Assuming role is in the students table
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
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Student Login</title>
  <style>
    /* Reuse student colors */
    :root {
      --midnight-garden: #566947; /* This was the first color */
      --caramelized: #c49a82;
      --skipping-stones: #8fb3c1;
      --terra-rosa: #c46a6a;
      --art-craft: #000000;
      --wild-blue: #bfe3f5;
      --minty-fresh: #d9f2e6; /* This was the second color */
      --honey-glow: #f2c66d;
      --white: #ffffff;
    }

    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: "Segoe UI", Tahoma, sans-serif;
    }

    body {
      min-height: 100vh;
      background: linear-gradient(135deg, var(--minty-fresh), var(--midnight-garden)); /* Swapped the gradient colors */
      display: flex;
      justify-content: center;
      align-items: center;
      overflow: hidden;
      transition: background 0.6s ease;
    }

    body.light {
      background: #1f242b; /* Dark mode stays here */
    }

    body.light .auth-card {
      background: var(--white); /* Keep white background for the form */
    }

    /* Lamp */
    .lamp-container {
      position: absolute;
      top: 60px;
      left: 50%;
      transform: translateX(-50%);
      cursor: pointer;
      z-index: 1;
    }

    /* Lamp Shape */
    .lamp {
      width: 120px;
      height: 60px;
      background: #fff;
      border-radius: 60px 60px 0 0;
      position: relative;
      box-shadow: 0 0 15px rgba(242, 198, 109, 0.5); /* Glow Effect */
      transition: box-shadow 0.5s ease;
    }

    .lamp::after {
      content: "";
      width: 6px;
      height: 100px;
      background: #ddd;
      position: absolute;
      left: 50%;
      transform: translateX(-50%);
      top: 60px;
    }

    /* Lamp Chain */
    .chain {
      width: 6px;
      height: 40px;
      background: #aaa;
      margin: auto;
      position: absolute;
      top: 130px;
      left: 50%;
      transform: translateX(-50%);
      cursor: pointer;
      border-radius: 50%;
    }

    /* Light Beam Spotlight Effect */
    .light-spot {
      position: absolute;
      top: 0;
      left: 50%;
      width: 400px;
      height: 400px;
      background: radial-gradient(circle, rgba(255, 255, 255, 0.6) 10%, transparent 50%);
      filter: blur(20px);
      pointer-events: none;
      opacity: 0;
      transition: opacity 0.5s ease;
    }

    /* Card */
    .auth-card {
      width: 420px;
      background: var(--white);
      padding: 30px;
      border-radius: 18px;
      box-shadow: 0 20px 45px rgba(0,0,0,0.15);
      opacity: 0;
      transform: translateY(20px);
      transition: all 0.6s ease;
    }

    body.light .auth-card {
      opacity: 1;
      transform: translateY(0);
    }

    .auth-card h2 {
      text-align: center;
      color: var(--art-craft);
      margin-bottom: 8px;
    }

    .auth-card p {
      text-align: center;
      color: #666;
      margin-bottom: 25px;
    }

    .form-group {
      margin-bottom: 15px;
    }

    .form-group label {
      display: block;
      font-size: 14px;
      color: var(--art-craft);
      margin-bottom: 6px;
    }

    .form-group input,
    .form-group select {
      width: 100%;
      padding: 12px;
      border-radius: 10px;
      border: 1px solid #566947;
      outline: none;
    }

    .form-group input:focus,
    .form-group select:focus {
      border-color: var(--skipping-stones);
    }

    .btn {
      width: 100%;
      padding: 12px;
      background: linear-gradient(135deg, var(--terra-rosa), var(--honey-glow));
      border: none;
      border-radius: 12px;
      color: white;
      font-size: 16px;
      cursor: pointer;
    }

    .btn:hover {
      opacity: 0.9;
    }

    .message {
      text-align: center;
      color: var(--terra-rosa);
      margin-bottom: 12px;
    }

    .auth-links {
      text-align: center;
      margin-top: 15px;
    }

    .auth-links a {
      color: var(--midnight-garden);
      text-decoration: none;
    }
  </style>
</head>
<body class="<?php echo isset($_SESSION['role']) && $_SESSION['role'] === 'admin' ? 'admin' : 'student'; ?>">

<!-- Light Beam Effect -->
<div class="light-spot" id="lightSpot"></div>

<div class="lamp-container">
  <div class="lamp" id="lampSVG"></div>
  <div class="chain" onclick="toggleLamp()"></div>
</div>

<div class="auth-card" id="loginForm">
  <h2>Student Login</h2>
  <p>Welcome back</p>

  <?php if ($message): ?>
    <div class="message"><?= $message ?></div>
  <?php endif; ?>

  <form method="POST">
    <div class="form-group">
      <label>Email</label>
      <input type="email" name="email" id="emailInput" required>
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

<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.9.1/gsap.min.js"></script>
<script>
  let isOn = localStorage.getItem("lampState") === "on"; // Remember lamp state
  const clickSound = new Audio('click-sound.mp3'); // Make sure you have a click-sound.mp3 file

  function toggleLamp() {
    isOn = !isOn;
    localStorage.setItem("lampState", isOn ? "on" : "off");

    if (isOn) {
      document.body.classList.add("light");
      document.getElementById("lightSpot").style.opacity = 1;
      gsap.to("#lampSVG", { boxShadow: "0 0 30px rgba(242, 198, 109, 0.7)", duration: 0.5 }); // Increased glow intensity
      document.getElementById("emailInput").focus();
    } else {
      document.body.classList.remove("light");
      document.getElementById("lightSpot").style.opacity = 0;
      gsap.to("#lampSVG", { boxShadow: "0 0 15px rgba(242, 198, 109, 0.5)", duration: 0.5 }); // Reduced glow intensity
    }
    clickSound.play();
  }

  // Initialize lamp state based on saved state
  if (isOn) {
    toggleLamp(); // Automatically turn on lamp if state was saved as "on"
  }
</script>

</body>
</html>
