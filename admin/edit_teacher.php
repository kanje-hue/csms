<?php
/**
 * admin/edit_teacher.php - Edit Teacher Information
 */

session_start();
require_once '../config/db.php';
require_once '../config/security_base.php';

// Check admin login
checkAdminSession();

$teacher_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$teacher_id) {
    header("Location: manage_teachers.php");
    exit();
}

// Get teacher details
$teacher_stmt = $conn->prepare("SELECT * FROM teachers WHERE teacher_id = ? AND deleted = 0");
$teacher_stmt->bind_param("i", $teacher_id);
$teacher_stmt->execute();
$teacher = $teacher_stmt->get_result()->fetch_assoc();
$teacher_stmt->close();

if (!$teacher) {
    header("Location: manage_teachers.php");
    exit();
}

$message = "";
$message_type = "";
$csrf_token = generateCSRF();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_teacher'])) {
    validateCSRF($_POST['csrf_token'] ?? '');
    
    $fullname = sanitizeInput($_POST['fullname'] ?? '');
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $status = sanitizeInput($_POST['status'] ?? 'active');
    $reset_password = isset($_POST['reset_password']);
    
    $errors = [];
    
    if (empty($fullname) || strlen($fullname) < 3) {
        $errors[] = "Full name must be at least 3 characters";
    }
    
    if (!$email) {
        $errors[] = "Valid email address is required";
    }
    
    if (empty($errors)) {
        // Check if email already exists for another teacher
        $check = $conn->prepare("SELECT teacher_id FROM teachers WHERE email = ? AND teacher_id != ? AND deleted = 0");
        $check->bind_param("si", $email, $teacher_id);
        $check->execute();
        
        if ($check->get_result()->num_rows > 0) {
            $message = "Email address is already used by another teacher";
            $message_type = "error";
        } else {
            // Update teacher info
            $update = $conn->prepare("UPDATE teachers SET fullname = ?, email = ?, status = ? WHERE teacher_id = ?");
            $update->bind_param("sssi", $fullname, $email, $status, $teacher_id);
            
            if ($update->execute()) {
                // If reset password requested
                if ($reset_password) {
                    $temp_password = bin2hex(random_bytes(4)); // 8 char password
                    $hashed = password_hash($temp_password, PASSWORD_DEFAULT);
                    
                    $pass_update = $conn->prepare("UPDATE teachers SET password = ?, force_password_change = 1 WHERE teacher_id = ?");
                    $pass_update->bind_param("si", $hashed, $teacher_id);
                    $pass_update->execute();
                    $pass_update->close();
                    
                    $message = "✓ Teacher updated and password reset. New temporary password: <strong>$temp_password</strong>";
                    $message_type = "warning";
                } else {
                    $message = "✓ Teacher information updated successfully";
                    $message_type = "success";
                }
                
                // Log action
                logAdminAction($conn, $_SESSION['admin_id'], 'edit_teacher', "Edited teacher ID: $teacher_id");
                
                // Refresh teacher data
                $refresh = $conn->prepare("SELECT * FROM teachers WHERE teacher_id = ?");
                $refresh->bind_param("i", $teacher_id);
                $refresh->execute();
                $teacher = $refresh->get_result()->fetch_assoc();
                $refresh->close();
            } else {
                $message = "Error updating teacher information";
                $message_type = "error";
            }
            $update->close();
        }
        $check->close();
    } else {
        $message = implode("<br>", $errors);
        $message_type = "error";
    }
}

// Get modules assigned to this teacher
$modules_stmt = $conn->prepare("
    SELECT 
        m.*,
        c.course_name
    FROM modules m
    JOIN courses c ON m.course_id = c.course_id
    WHERE m.teacher_id = ? AND m.deleted = 0
    ORDER BY c.course_name, m.year, m.semester, m.module_code
");
$modules_stmt->bind_param("i", $teacher_id);
$modules_stmt->execute();
$modules = $modules_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$modules_stmt->close();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit Teacher - <?= htmlspecialchars($teacher['fullname']) ?></title>
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
        
        .btn-danger {
            background: #e74c3c;
            color: white;
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
            margin-bottom: 30px;
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
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            color: #333;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #16a085;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 20px 0;
        }
        
        .checkbox-group input[type="checkbox"] {
            width: auto;
            margin: 0;
        }
        
        .info-box {
            background: #e8f4f8;
            border-left: 4px solid #16a085;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        
        .module-list {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        
        .module-list h3 {
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
        }
        
        td {
            padding: 12px;
            border-bottom: 1px solid #eee;
        }
        
        .module-code {
            font-weight: bold;
            color: #16a085;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: bold;
        }
        
        .badge-active {
            background: #d4edda;
            color: #155724;
        }
        
        .no-modules {
            text-align: center;
            padding: 30px;
            color: #666;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
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
        <a href="manage_teachers.php">Teachers</a> > 
        <strong>Edit Teacher</strong>
    </div>

    <!-- Page Header -->
    <div class="page-header">
        <h1>✏️ Edit Teacher: <?= htmlspecialchars($teacher['fullname']) ?></h1>
    </div>

    <?php if ($message): ?>
        <div class="alert <?= $message_type ?>"><?= $message ?></div>
    <?php endif; ?>

    <!-- Edit Form -->
    <div class="form-card">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            
            <div class="info-box">
                <strong>📌 Teacher ID:</strong> #<?= $teacher['teacher_id'] ?> | 
                <strong>Joined:</strong> <?= date('M d, Y', strtotime($teacher['created_at'])) ?>
                <?php if ($teacher['force_password_change']): ?>
                    | <span style="color: #e74c3c;">⚠️ Must change password on next login</span>
                <?php endif; ?>
            </div>
            
            <div class="form-grid">
                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="fullname" value="<?= htmlspecialchars($teacher['fullname']) ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Email Address *</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($teacher['email']) ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="active" <?= $teacher['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $teacher['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
            </div>
            
            <div class="checkbox-group">
                <input type="checkbox" name="reset_password" id="reset_password">
                <label for="reset_password">🔑 Reset password (teacher will need to change on next login)</label>
            </div>
            
            <div class="action-buttons">
                <button type="submit" name="update_teacher" class="btn btn-primary">Update Teacher</button>
                <a href="manage_teachers.php" class="btn btn-secondary">Cancel</a>
                <a href="?action=delete&id=<?= $teacher_id ?>" class="btn btn-danger" onclick="return confirm('Move this teacher to recycle bin?')">Delete Teacher</a>
            </div>
        </form>
    </div>

    <!-- Assigned Modules -->
    <div class="module-list">
        <h3>📚 Modules Assigned to <?= htmlspecialchars($teacher['fullname']) ?></h3>
        
        <?php if (count($modules) > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>Course</th>
                    <th>Module Code</th>
                    <th>Module Name</th>
                    <th>Year</th>
                    <th>Semester</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($modules as $module): ?>
                <tr>
                    <td><?= htmlspecialchars($module['course_name']) ?></td>
                    <td class="module-code"><?= htmlspecialchars($module['module_code']) ?></td>
                    <td><?= htmlspecialchars($module['module_name']) ?></td>
                    <td>Year <?= $module['year'] ?></td>
                    <td>Semester <?= $module['semester'] ?></td>
                    <td>
                        <span class="badge badge-<?= $module['status'] ?>">
                            <?= ucfirst($module['status']) ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="no-modules">
            <p>This teacher is not assigned to any modules yet.</p>
            <a href="assign_modules.php?teacher_id=<?= $teacher_id ?>" class="btn btn-primary" style="margin-top: 10px;">Assign to Modules</a>
        </div>
        <?php endif; ?>
    </div>

    <a href="manage_teachers.php" class="btn btn-secondary" style="margin-top: 20px;">← Back to Teacher Management</a>
</div>

</body>
</html>