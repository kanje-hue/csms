<?php
session_start();
include '../config/db.php';

if(!isset($_SESSION['admin_logged_in'])){
    header("Location: login.php");
    exit();
}

function safe_int($value) {
    return filter_var($value, FILTER_VALIDATE_INT);
}

$message = "";
$message_type = "";

// Handle restore / permanent delete
if(isset($_GET['action'], $_GET['id'])){
    $teacher_id = safe_int($_GET['id']);

    if(!$teacher_id) {
        $message = "Invalid teacher ID";
        $message_type = "error";
    } elseif($_GET['action'] === 'restore'){
        $stmt = $conn->prepare("UPDATE teachers SET deleted=0 WHERE teacher_id=?");
        $stmt->bind_param("i", $teacher_id);
        
        if($stmt->execute()){
            $message = "✓ Teacher restored successfully";
            $message_type = "success";
        } else {
            $message = "Error restoring teacher";
            $message_type = "error";
        }
        $stmt->close();
        
    } elseif($_GET['action'] === 'permanent'){
        try {
            // Disable foreign key checks temporarily
            $conn->query("SET FOREIGN_KEY_CHECKS=0");
            
            // Delete notifications for this teacher
            $conn->query("DELETE FROM notifications WHERE teacher_id = $teacher_id");
            
            // Get all modules taught by this teacher
            $modules_result = $conn->query("SELECT module_id FROM modules WHERE teacher_id = $teacher_id");
            $module_ids = array();
            while($row = $modules_result->fetch_assoc()) {
                $module_ids[] = $row['module_id'];
            }
            
            // Delete results for those modules
            foreach($module_ids as $mid) {
                $conn->query("DELETE FROM results WHERE module_id = $mid");
            }
            
            // Delete module enrollments if table exists
            if(!empty($module_ids)) {
                $ids_str = implode(',', $module_ids);
                $conn->query("DELETE FROM module_enrollments WHERE module_id IN ($ids_str)");
            }
            
            // Delete modules
            $conn->query("DELETE FROM modules WHERE teacher_id = $teacher_id");
            
            // Finally delete the teacher
            $conn->query("DELETE FROM teachers WHERE teacher_id = $teacher_id");
            
            // Re-enable foreign key checks
            $conn->query("SET FOREIGN_KEY_CHECKS=1");
            
            $message = "✓ Teacher permanently deleted";
            $message_type = "success";
            
        } catch (Exception $e) {
            $conn->query("SET FOREIGN_KEY_CHECKS=1");
            $message = "Error: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// Fetch deleted teachers (removed phone column)
$teachers = $conn->query("SELECT teacher_id, fullname, email FROM teachers WHERE deleted=1 ORDER BY teacher_id DESC");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Recycle Bin - Teachers</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        .container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
        }

        .auth-card {
            width: 100%;
            padding: 30px;
            border-radius: 12px;
            background: white;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            margin: 30px auto;
        }

        h2 {
            text-align: center;
            color: #333;
            margin-bottom: 20px;
        }

        .back-link {
            margin-bottom: 20px;
        }

        .back-link a {
            color: #2196F3;
            text-decoration: none;
            font-weight: bold;
            padding: 8px 15px;
            display: inline-block;
            border-radius: 4px;
            transition: all 0.3s;
        }

        .back-link a:hover {
            background: #e3f2fd;
        }

        .alert {
            padding: 15px;
            margin: 15px 0;
            border-radius: 5px;
            display: none;
        }

        .alert.success {
            background: #d4edda;
            color: #155724;
            display: block;
            border: 1px solid #c3e6cb;
        }

        .alert.error {
            background: #f8d7da;
            color: #721c24;
            display: block;
            border: 1px solid #f5c6cb;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border-radius: 8px;
            overflow: hidden;
        }

        th {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: bold;
        }

        td {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
        }

        tr:hover {
            background: #f9f9f9;
        }

        tr:last-child td {
            border-bottom: none;
        }

        .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn-action {
            padding: 6px 12px;
            border-radius: 4px;
            color: white;
            text-decoration: none;
            font-size: 12px;
            font-weight: bold;
            border: none;
            cursor: pointer;
            transition: all 0.3s;
        }

        .restore {
            background: #4CAF50;
        }

        .restore:hover {
            background: #45a049;
        }

        .delete {
            background: #e74c3c;
        }

        .delete:hover {
            background: #c0392b;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #999;
        }

        @media (max-width: 768px) {
            .auth-card {
                padding: 15px;
            }

            table {
                font-size: 12px;
            }

            th, td {
                padding: 8px;
            }

            .actions {
                flex-direction: column;
                gap: 4px;
            }

            .btn-action {
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="auth-card">
        <h2>🗑️ Recycle Bin - Teachers</h2>
        
        <div class="back-link">
            <a href="manage_teachers.php">← Back to Manage Teachers</a>
        </div>

        <!-- Alert Messages -->
        <?php if ($message): ?>
            <div class="alert <?= $message_type === 'success' ? 'success' : 'error' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Full Name</th>
                    <th>Email</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if($teachers->num_rows > 0): ?>
                    <?php while($t = $teachers->fetch_assoc()): ?>
                    <tr>
                        <td><strong><?= $t['teacher_id'] ?></strong></td>
                        <td><?= htmlspecialchars($t['fullname']) ?></td>
                        <td><?= htmlspecialchars($t['email']) ?></td>
                        <td>
                            <div class="actions">
                                <a class="btn-action restore" href="?action=restore&id=<?= $t['teacher_id'] ?>">↩️ Restore</a>
                                <a class="btn-action delete" href="?action=permanent&id=<?= $t['teacher_id'] ?>" onclick="return confirm('Permanently delete this teacher and ALL their associated data? This cannot be undone.')">🗑️ Delete</a>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" class="no-data">✅ No deleted teachers. Your recycle bin is empty!</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

    </div>
</div>

</body>
</html>