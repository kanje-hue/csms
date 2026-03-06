<?php
/**
 * public/test_reset.php - Debug password reset issues
 * Access: http://your-site.com/public/test_reset.php
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/db.php';

echo "<!DOCTYPE html>
<html>
<head>
    <title>Password Reset Debugger</title>
    <style>
        body { font-family: Arial; padding: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #16a085; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #16a085; color: white; }
        tr:hover { background: #f5f5f5; }
        .success { color: green; }
        .error { color: red; }
        .warning { color: orange; }
        .code { font-family: monospace; font-size: 18px; background: #f0f0f0; padding: 5px; }
        button { background: #16a085; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; }
        input[type=text], input[type=email] { padding: 8px; width: 300px; border: 1px solid #ddd; border-radius: 4px; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>🔑 Password Reset Debugger</h1>";

// Handle actions
if (isset($_POST['action'])) {
    if ($_POST['action'] === 'check_tokens') {
        $email = $_POST['email'] ?? '';
        
        echo "<h2>Checking tokens for: $email</h2>";
        
        $stmt = $conn->prepare("SELECT * FROM password_reset_tokens WHERE email = ? ORDER BY id DESC");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            echo "<table>";
            echo "<tr><th>ID</th><th>User Type</th><th>User ID</th><th>Code</th><th>Created</th><th>Expires</th><th>Used</th><th>Attempts</th></tr>";
            while ($row = $result->fetch_assoc()) {
                $expired = strtotime($row['expires_at']) < time() ? 'error' : 'success';
                echo "<tr>";
                echo "<td>{$row['id']}</td>";
                echo "<td>{$row['user_type']}</td>";
                echo "<td>{$row['user_id']}</td>";
                echo "<td class='code'>{$row['verification_code']}</td>";
                echo "<td>{$row['created_at']}</td>";
                echo "<td class='$expired'>{$row['expires_at']}</td>";
                echo "<td>" . ($row['is_used'] ? 'Yes' : 'No') . "</td>";
                echo "<td>{$row['code_attempts']}</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p class='error'>❌ No tokens found for this email</p>";
        }
        
    } elseif ($_POST['action'] === 'create_test_token') {
        $email = $_POST['email'] ?? '';
        $user_type = $_POST['user_type'] ?? 'students';
        $user_id = (int)($_POST['user_id'] ?? 1);
        
        // Generate test code
        $code = sprintf("%06d", rand(0, 999999));
        $token = bin2hex(random_bytes(32));
        $created_at = date('Y-m-d H:i:s');
        $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Delete old tokens
        $del = $conn->prepare("DELETE FROM password_reset_tokens WHERE email = ?");
        $del->bind_param('s', $email);
        $del->execute();
        
        // Insert new token
        $sql = "INSERT INTO password_reset_tokens (user_type, user_id, email, token, verification_code, created_at, expires_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('sisssss', $user_type, $user_id, $email, $token, $code, $created_at, $expires_at);
        
        if ($stmt->execute()) {
            echo "<div class='success'>✅ Test token created successfully!</div>";
            echo "<table>";
            echo "<tr><th>Email</th><th>User Type</th><th>User ID</th><th>Code</th><th>Expires</th></tr>";
            echo "<tr>";
            echo "<td>$email</td>";
            echo "<td>$user_type</td>";
            echo "<td>$user_id</td>";
            echo "<td class='code'>$code</td>";
            echo "<td>$expires_at</td>";
            echo "</tr>";
            echo "</table>";
        } else {
            echo "<div class='error'>❌ Failed to create token: " . $conn->error . "</div>";
        }
    }
}

// Show forms
echo "<h2>Check Existing Tokens</h2>
<form method='POST'>
    <input type='hidden' name='action' value='check_tokens'>
    <label>Email:</label>
    <input type='email' name='email' required placeholder='Enter email'>
    <button type='submit'>Check Tokens</button>
</form>

<h2>Create Test Token</h2>
<form method='POST'>
    <input type='hidden' name='action' value='create_test_token'>
    <label>Email:</label>
    <input type='email' name='email' required placeholder='user@example.com'><br><br>
    <label>User Type:</label>
    <select name='user_type'>
        <option value='students'>Student</option>
        <option value='teachers'>Teacher</option>
        <option value='admins'>Admin</option>
    </select><br><br>
    <label>User ID:</label>
    <input type='number' name='user_id' value='1' required><br><br>
    <button type='submit'>Create Test Token</button>
</form>

<h2>Database Status</h2>";

// Show table structure
$result = $conn->query("DESCRIBE password_reset_tokens");
if ($result) {
    echo "<h3>Table Structure:</h3>";
    echo "<table>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$row['Field']}</td>";
        echo "<td>{$row['Type']}</td>";
        echo "<td>{$row['Null']}</td>";
        echo "<td>{$row['Key']}</td>";
        echo "<td>{$row['Default']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p class='error'>❌ Table 'password_reset_tokens' does not exist!</p>";
}

// Show current time vs DB time
$now = date('Y-m-d H:i:s');
$db_time = $conn->query("SELECT NOW() as db_time")->fetch_assoc();
echo "<h3>Time Check:</h3>";
echo "<p>PHP Time: <strong>$now</strong></p>";
echo "<p>Database Time: <strong>{$db_time['db_time']}</strong></p>";

echo "</div></body></html>";
?>