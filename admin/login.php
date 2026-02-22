<?php
session_start();
include '../config/db.php';

$message = '';

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    // Check admin credentials
    $stmt = $conn->prepare("SELECT admin_id, name, email FROM admins WHERE email = ? AND password = ? AND deleted = 0");
    $stmt->bind_param("ss", $email, $password);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if($result->num_rows > 0){
        $admin = $result->fetch_assoc();
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_id'] = $admin['admin_id'];
        $_SESSION['admin_name'] = $admin['name'];
        
        // Redirect directly to manage_courses.php
        header("Location: manage_courses.php");
        exit();
    } else {
        $message = "❌ Invalid email or password";
    }
    
    $stmt->close();
}

?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Login - CSMS</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
</head>
<body>

<div class="auth-card">
    <h2>🏢 Admin Login</h2>

    <?php if($message): ?>
        <div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #f5c6cb;">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <div style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 8px; font-weight: bold; color: var(--midnight-garden);">Email:</label>
            <input type="email" name="email" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; box-sizing: border-box;">
        </div>

        <div style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 8px; font-weight: bold; color: var(--midnight-garden);">Password:</label>
            <input type="password" name="password" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; box-sizing: border-box;">
        </div>

        <button type="submit" style="width: 100%; padding: 12px; background: linear-gradient(135deg, var(--terra-rosa), var(--honey-glow)); color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 16px;">
            🔐 Login
        </button>
    </form>

    <p style="text-align: center; margin-top: 20px; color: #666;">
        <a href="../index.php" style="color: var(--terra-rosa); text-decoration: none; font-weight: bold;">← Back to Home</a>
    </p>
</div>

</body>
</html>