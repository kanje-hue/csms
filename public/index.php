<?php
require_once '../config/security.php'; // Include security configuration

session_start();
$db = new PDO('mysql:host=localhost;dbname=your_db_name', 'username', 'password'); // Update with your DB credentials

function validateInput($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action == 'register') {
            $username = validateInput($_POST['username']);
            $email = validateInput($_POST['email']);
            $password = password_hash(validateInput($_POST['password']), PASSWORD_BCRYPT);
            $role = validateInput($_POST['role']);

            // Insert into database (ensure username/email are unique)
            $stmt = $db->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)");
            if ($stmt->execute([$username, $email, $password, $role])) {
                echo "Registration successful!";
            } else {
                echo "Error in registration!";
            }
        } elseif ($action == 'login') {
            $username = validateInput($_POST['username']);
            $password = validateInput($_POST['password']);

            // Fetch user from database
            $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role'] = $user['role'];
                echo "Login successful! Welcome, " . $user['username'];
            } else {
                echo "Invalid credentials!";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login / Registration</title>
</head>
<body>
    <h2>Login</h2>
    <form method="POST">
        <input type="text" name="username" required placeholder="Username">
        <input type="password" name="password" required placeholder="Password">
        <input type="hidden" name="action" value="login">
        <button type="submit">Login</button>
    </form>

    <h2>Register</h2>
    <form method="POST">
        <input type="text" name="username" required placeholder="Username">
        <input type="email" name="email" required placeholder="Email">
        <input type="password" name="password" required placeholder="Password">
        <select name="role" required>
            <option value="admin">Admin</option>
            <option value="teacher">Teacher</option>
            <option value="student">Student</option>
        </select>
        <input type="hidden" name="action" value="register">
        <button type="submit">Register</button>
    </form>
</body>
</html>
