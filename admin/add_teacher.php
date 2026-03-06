<?php
/**
 * admin/add_teacher.php - Add New Teacher with Force Password Change
 * Teachers receive email with credentials and must change password on first login
 */

session_start();
require_once '../config/db.php';
require_once '../config/security_base.php';
require_once '../config/email_config.php';

// Check admin login
checkAdminSession();

$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;

$message = "";
$message_type = "";
$csrf_token = generateCSRF();

// Generate secure random password
function generateSecurePassword($length = 10) {
    $uppercase = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lowercase = 'abcdefghijkmnopqrstuvwxyz';
    $numbers = '23456789';
    $special = '!@#$%&*';
    
    $all = $uppercase . $lowercase . $numbers . $special;
    $password = '';
    
    // Ensure at least one of each type
    $password .= $uppercase[random_int(0, strlen($uppercase)-1)];
    $password .= $lowercase[random_int(0, strlen($lowercase)-1)];
    $password .= $numbers[random_int(0, strlen($numbers)-1)];
    $password .= $special[random_int(0, strlen($special)-1)];
    
    // Fill the rest
    for ($i = strlen($password); $i < $length; $i++) {
        $password .= $all[random_int(0, strlen($all)-1)];
    }
    
    return str_shuffle($password);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_teacher'])) {
    validateCSRF($_POST['csrf_token'] ?? '');
    
    $fullname = sanitizeInput($_POST['fullname'] ?? '');
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $plain_password = $_POST['password'] ?? '';
    
    // Validate inputs
    $errors = [];
    
    if (empty($fullname) || strlen($fullname) < 3) {
        $errors[] = "Full name must be at least 3 characters";
    }
    
    if (!$email) {
        $errors[] = "Valid email address is required";
    }
    
    if (empty($errors)) {
        // Check if teacher already exists
        $check = $conn->prepare("SELECT teacher_id FROM teachers WHERE email = ? AND deleted = 0");
        $check->bind_param("s", $email);
        $check->execute();
        
        if ($check->get_result()->num_rows > 0) {
            $message = "A teacher with this email already exists";
            $message_type = "error";
        } else {
            // Determine password
            if (!empty($plain_password)) {
                // Validate provided password strength
                if (strlen($plain_password) < 8) {
                    $message = "Password must be at least 8 characters";
                    $message_type = "error";
                } elseif (!preg_match('/[A-Z]/', $plain_password)) {
                    $message = "Password must contain at least one uppercase letter";
                    $message_type = "error";
                } elseif (!preg_match('/[a-z]/', $plain_password)) {
                    $message = "Password must contain at least one lowercase letter";
                    $message_type = "error";
                } elseif (!preg_match('/[0-9]/', $plain_password)) {
                    $message = "Password must contain at least one number";
                    $message_type = "error";
                } elseif (!preg_match('/[^A-Za-z0-9]/', $plain_password)) {
                    $message = "Password must contain at least one special character";
                    $message_type = "error";
                } else {
                    $temp_password = $plain_password;
                }
            } else {
                // Generate secure random password
                $temp_password = generateSecurePassword(12);
            }
            
            // If no errors, create teacher
            if (empty($message)) {
                $hashed_password = password_hash($temp_password, PASSWORD_DEFAULT);
                
                $insert = $conn->prepare("
                    INSERT INTO teachers (fullname, email, password, status, force_password_change, created_at) 
                    VALUES (?, ?, ?, 'active', 1, NOW())
                ");
                $insert->bind_param("sss", $fullname, $email, $hashed_password);
                
                if ($insert->execute()) {
                    $teacher_id = $insert->insert_id;
                    
                    // Send welcome email with credentials
                    $login_link = "http://" . $_SERVER['HTTP_HOST'] . "/csms/public/";
                    $email_sent = send_teacher_welcome_email($email, $fullname, $temp_password, $login_link);
                    
                    // Log action
                    logAdminAction($conn, $_SESSION['admin_id'], 'add_teacher', "Added teacher: $fullname ($email)");
                    
                    $message = "✓ Teacher account created successfully! ";
                    if ($email_sent) {
                        $message .= "Login credentials sent to teacher's email.";
                        $message_type = "success";
                    } else {
                        $message .= "<br><strong>Temporary Password:</strong> $temp_password";
                        $message_type = "warning";
                    }
                } else {
                    $message = "Error creating teacher account";
                    $message_type = "error";
                }
                $insert->close();
            }
        }
        $check->close();
    } else {
        $message = implode("<br>", $errors);
        $message_type = "error";
    }
}

// Get all teachers for display
$teachers = $conn->query("
    SELECT teacher_id, fullname, email, status, force_password_change, created_at 
    FROM teachers 
    WHERE deleted = 0 
    ORDER BY created_at DESC 
    LIMIT 10
");
?>
<!DOCTYPE html>
<html>
<head>
    <title>Add Teacher - CSMS</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
        }
        
        .header {
            background: linear-gradient(135deg, #1a1a2e, #16213e);
            color: white;
            padding: 20px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .container {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }
        
        .breadcrumb {
            background: white;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .breadcrumb a {
            color: #16a085;
            text-decoration: none;
        }
        
        .page-header {
            margin-bottom: 30px;
        }
        
        .page-header h1 {
            color: #1a1a2e;
            font-size: 24px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary {
            background: #16a085;
            color: white;
        }
        
        .btn-primary:hover {
            background: #117a65;
        }
        
        .btn-secondary {
            background: #f8f9fa;
            color: #1a1a2e;
            border: 1px solid #ddd;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .alert.warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
        }
        
        .form-card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 40px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        
        .form-card h2 {
            color: #1a1a2e;
            margin-bottom: 20px;
            font-size: 20px;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #333;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #16a085;
        }
        
        .password-hint {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        
        .teacher-list {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        
        .teacher-list h3 {
            color: #1a1a2e;
            margin-bottom: 15px;
            font-size: 18px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-weight: bold;
            color: #555;
            border-bottom: 2px solid #e0e0e0;
        }
        
        td {
            padding: 12px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: bold;
        }
        
        .status-active {
            background: #d4edda;
            color: #155724;
        }
        
        .force-change {
            background: #f8d7da;
            color: #721c24;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: bold;
        }
        
        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #16a085;
            text-decoration: none;
        }
        
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            table {
                font-size: 12px;
            }
            
            td, th {
                padding: 8px;
            }
        }
    </style>
</head>
<body>

<div class="header">
    <div style="font-size: 20px;">CSMS Admin</div>
    <a href="logout.php" style="color: white; text-decoration: none;">🚪 Logout</a>
</div>

<div class="container">
    <!-- Breadcrumb -->
    <div class="breadcrumb">
        <a href="dashboard.php">Dashboard</a> > 
        <a href="manage_teachers.php<?= $course_id ? '?course_id=' . $course_id : '' ?>">Teachers</a> > 
        <strong>Add Teacher</strong>
    </div>

    <!-- Page Header -->
    <div class="page-header">
        <h1>➕ Add New Teacher</h1>
    </div>

    <?php if ($message): ?>
        <div class="alert <?= $message_type ?>"><?= $message ?></div>
    <?php endif; ?>

    <!-- Add Teacher Form -->
    <div class="form-card">
        <h2>Teacher Account Details</h2>
        <p style="color: #666; margin-bottom: 20px;">Teachers will receive an email with login credentials and must change password on first login.</p>
        
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            
            <div class="form-grid">
                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="fullname" placeholder="e.g., John Smith" required>
                </div>
                
                <div class="form-group">
                    <label>Email Address *</label>
                    <input type="email" name="email" placeholder="teacher@example.com" required>
                </div>
                
                <div class="form-group">
                    <label>Password (leave empty to auto-generate)</label>
                    <input type="password" name="password" placeholder="Optional - system will generate secure password">
                    <div class="password-hint">
                        If provided, password must be at least 8 characters with uppercase, lowercase, number, and special character.
                    </div>
                </div>
            </div>
            
            <button type="submit" name="add_teacher" class="btn btn-primary">Create Teacher Account</button>
            <a href="manage_teachers.php<?= $course_id ? '?course_id=' . $course_id : '' ?>" class="btn btn-secondary">Cancel</a>
        </form>
    </div>

    <!-- Recently Added Teachers -->
    <div class="teacher-list">
        <h3>📋 Recently Added Teachers</h3>
        
        <?php if ($teachers->num_rows > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Password Status</th>
                    <th>Added</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($teacher = $teachers->fetch_assoc()): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($teacher['fullname']) ?></strong></td>
                    <td><?= htmlspecialchars($teacher['email']) ?></td>
                    <td>
                        <span class="status-badge status-<?= $teacher['status'] ?>">
                            <?= ucfirst($teacher['status']) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($teacher['force_password_change']): ?>
                            <span class="force-change">⚠️ Must Change on First Login</span>
                        <?php else: ?>
                            <span style="color: #27ae60;">✓ Password Set</span>
                        <?php endif; ?>
                    </td>
                    <td><?= date('M d, Y', strtotime($teacher['created_at'])) ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p style="text-align: center; padding: 30px; color: #666;">No teachers added yet.</p>
        <?php endif; ?>
    </div>

    <a href="manage_teachers.php<?= $course_id ? '?course_id=' . $course_id : '' ?>" class="back-link">← Back to Teacher Management</a>
</div>

</body>
</html>