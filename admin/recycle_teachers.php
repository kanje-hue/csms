<?php
/**
 * admin/recycle_teachers.php - Restore or Permanently Delete Teachers
 */

session_start();
require_once '../config/db.php';
require_once '../config/security_base.php';

// Check admin login
checkAdminSession();

$message = "";
$message_type = "";

// Handle restore
if (isset($_GET['action']) && $_GET['action'] === 'restore' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    $stmt = $conn->prepare("UPDATE teachers SET deleted = 0, deleted_at = NULL WHERE teacher_id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        $message = "Teacher restored successfully";
        $message_type = "success";
        
        logAdminAction($conn, $_SESSION['admin_id'], 'restore_teacher', "Restored teacher ID: $id");
    }
    $stmt->close();
}

// Handle permanent delete
if (isset($_GET['action']) && $_GET['action'] === 'permanent' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    $stmt = $conn->prepare("DELETE FROM teachers WHERE teacher_id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        $message = "Teacher permanently deleted";
        $message_type = "success";
        
        logAdminAction($conn, $_SESSION['admin_id'], 'permanent_delete_teacher', "Permanently deleted teacher ID: $id");
    }
    $stmt->close();
}

// Get deleted teachers - FIXED: Check if deleted_at column exists
$check_column = $conn->query("SHOW COLUMNS FROM teachers LIKE 'deleted_at'");
$has_deleted_at = $check_column->num_rows > 0;

if ($has_deleted_at) {
    // Use deleted_at if it exists
    $teachers = $conn->query("
        SELECT teacher_id, fullname, email, status, force_password_change, created_at, deleted_at 
        FROM teachers 
        WHERE deleted = 1 
        ORDER BY deleted_at DESC
    ")->fetch_all(MYSQLI_ASSOC);
} else {
    // Fallback to created_at if deleted_at doesn't exist
    $teachers = $conn->query("
        SELECT teacher_id, fullname, email, status, force_password_change, created_at 
        FROM teachers 
        WHERE deleted = 1 
        ORDER BY created_at DESC
    ")->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Recycle Bin - Teachers</title>
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
            display: flex;
            justify-content: space-between;
            align-items: center;
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
        
        .btn-success {
            background: #27ae60;
            color: white;
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
        
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        
        th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: bold;
            color: #555;
        }
        
        td {
            padding: 15px;
            border-bottom: 1px solid #eee;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .teacher-name {
            font-weight: bold;
            color: #1a1a2e;
        }
        
        .deleted-date {
            font-size: 12px;
            color: #666;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .action-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            font-size: 12px;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-restore {
            background: #d4edda;
            color: #155724;
        }
        
        .btn-delete {
            background: #f8d7da;
            color: #721c24;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px;
            background: white;
            border-radius: 12px;
        }
        
        .empty-state p {
            color: #666;
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            table {
                font-size: 12px;
            }
            
            td, th {
                padding: 10px;
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
        <strong>Recycle Bin</strong>
    </div>

    <!-- Page Header -->
    <div class="page-header">
        <h1>🗑️ Teacher Recycle Bin</h1>
        <a href="manage_teachers.php" class="btn btn-primary">← Back to Teachers</a>
    </div>

    <?php if ($message): ?>
        <div class="alert <?= $message_type ?>"><?= $message ?></div>
    <?php endif; ?>

    <!-- Teachers Table -->
    <?php if (count($teachers) > 0): ?>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Email</th>
                <th>Status</th>
                <th>Deleted On</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($teachers as $teacher): ?>
            <tr>
                <td>#<?= $teacher['teacher_id'] ?></td>
                <td><span class="teacher-name"><?= htmlspecialchars($teacher['fullname']) ?></span></td>
                <td><?= htmlspecialchars($teacher['email']) ?></td>
                <td>
                    <?php if ($teacher['force_password_change']): ?>
                        <span style="color: #e74c3c;">⚠️ Pending</span>
                    <?php else: ?>
                        <span style="color: #27ae60;">✓ Set</span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="deleted-date">
                        <?php 
                        if (isset($teacher['deleted_at']) && $teacher['deleted_at']) {
                            echo date('M d, Y H:i', strtotime($teacher['deleted_at']));
                        } else {
                            echo date('M d, Y H:i', strtotime($teacher['created_at']));
                        }
                        ?>
                    </span>
                </td>
                <td>
                    <div class="action-buttons">
                        <a href="?action=restore&id=<?= $teacher['teacher_id'] ?>" class="action-btn btn-restore" onclick="return confirm('Restore this teacher?')">↩️ Restore</a>
                        <a href="?action=permanent&id=<?= $teacher['teacher_id'] ?>" class="action-btn btn-delete" onclick="return confirm('Permanently delete this teacher? This cannot be undone.')">🗑️ Delete</a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <div class="empty-state">
        <p>🗑️ Recycle bin is empty</p>
        <a href="manage_teachers.php" class="btn btn-primary">Back to Teachers</a>
    </div>
    <?php endif; ?>
</div>

</body>
</html>