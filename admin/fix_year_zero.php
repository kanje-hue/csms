<?php
/**
 * admin/fix_year_zero.php - Permanently fix year 0 data
 * This script will update year 0 to year 1 in the database
 */

session_start();
require_once '../config/db.php';
require_once '../config/security_base.php';

// Check admin login
checkAdminSession();

$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$confirmed = isset($_GET['confirm']) && $_GET['confirm'] == 'yes';

$message = "";
$message_type = "";

if ($course_id && $confirmed) {
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Update modules
        $module_update = $conn->prepare("UPDATE modules SET year = 1 WHERE course_id = ? AND (year = 0 OR year IS NULL)");
        $module_update->bind_param("i", $course_id);
        $module_update->execute();
        $module_count = $module_update->affected_rows;
        $module_update->close();
        
        // Update students
        $student_update = $conn->prepare("UPDATE students SET year = 1 WHERE course_id = ? AND (year = 0 OR year IS NULL)");
        $student_update->bind_param("i", $course_id);
        $student_update->execute();
        $student_count = $student_update->affected_rows;
        $student_update->close();
        
        $conn->commit();
        
        $message = "✅ Fixed $module_count modules and $student_count students. All data preserved and moved to Year 1.";
        $message_type = "success";
        
        logAdminAction($conn, $_SESSION['admin_id'], 'fix_year_zero', "Fixed year 0 data for course ID: $course_id");
        
    } catch (Exception $e) {
        $conn->rollback();
        $message = "Error: " . $e->getMessage();
        $message_type = "error";
    }
}

// Get count of year 0 data
$count_stmt = $conn->prepare("
    SELECT 
        (SELECT COUNT(*) FROM modules WHERE course_id = ? AND (year = 0 OR year IS NULL)) as module_count,
        (SELECT COUNT(*) FROM students WHERE course_id = ? AND (year = 0 OR year IS NULL)) as student_count
");
$count_stmt->bind_param("ii", $course_id, $course_id);
$count_stmt->execute();
$counts = $count_stmt->get_result()->fetch_assoc();
$count_stmt->close();

$has_data = ($counts['module_count'] > 0 || $counts['student_count'] > 0);

$csrf_token = generateCSRF();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fix Year 0 Data - CSMS</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f2f5;
            color: #1e293b;
        }

        .header {
            background: linear-gradient(135deg, #0f172a, #1e293b);
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            font-size: 1.8rem;
            background: linear-gradient(135deg, #2dd4bf, #14b8a6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .container {
            max-width: 800px;
            margin: 2rem auto;
            padding: 0 2rem;
        }

        .card {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        }

        h1 {
            color: #0f172a;
            margin-bottom: 1.5rem;
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }

        .alert.success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }

        .alert.error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }

        .data-box {
            background: #f8fafc;
            border-radius: 12px;
            padding: 1.5rem;
            margin: 1.5rem 0;
        }

        .stat-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .stat-label {
            font-weight: 500;
            color: #64748b;
        }

        .stat-value {
            font-weight: 600;
            color: #0f172a;
        }

        .warning {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 1rem;
            border-radius: 8px;
            margin: 1.5rem 0;
        }

        .btn {
            padding: 0.8rem 1.8rem;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
            cursor: pointer;
            margin-right: 1rem;
        }

        .btn-primary {
            background: #f59e0b;
            color: white;
        }

        .btn-primary:hover {
            background: #d97706;
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: #e2e8f0;
            color: #0f172a;
        }

        .btn-secondary:hover {
            background: #cbd5e1;
        }

        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
    </style>
</head>
<body>

<div class="header">
    <h1>CSMS Admin</h1>
    <a href="logout.php" style="color: white; text-decoration: none;">🚪 Logout</a>
</div>

<div class="container">
    <div class="card">
        <h1>🔄 Fix Year 0 Data</h1>
        
        <?php if ($message): ?>
            <div class="alert <?= $message_type ?>"><?= $message ?></div>
        <?php endif; ?>

        <?php if ($has_data): ?>
            <div class="data-box">
                <h3 style="margin-bottom: 1rem;">Data Found with Year 0:</h3>
                <div class="stat-row">
                    <span class="stat-label">Modules with Year 0:</span>
                    <span class="stat-value"><?= $counts['module_count'] ?></span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">Students with Year 0:</span>
                    <span class="stat-value"><?= $counts['student_count'] ?></span>
                </div>
            </div>

            <div class="warning">
                <strong>⚠️ What will happen:</strong>
                <ul style="margin-top: 0.5rem; margin-left: 1.5rem;">
                    <li>All modules with Year 0 will be updated to Year 1</li>
                    <li>All students with Year 0 will be updated to Year 1</li>
                    <li>All data will be preserved - nothing will be deleted</li>
                    <li>This action can be undone by running a manual SQL query if needed</li>
                </ul>
            </div>

            <a href="?course_id=<?= $course_id ?>&confirm=yes" class="btn btn-primary" onclick="return confirm('Are you sure you want to move all Year 0 data to Year 1?')">
                ✅ Yes, Fix Year 0 Data
            </a>
            <a href="manage_course_structure.php?course_id=<?= $course_id ?>" class="btn btn-secondary">← Cancel</a>

        <?php else: ?>
            <div class="alert success">
                ✅ No Year 0 data found for this course. Everything is clean!
            </div>
            <a href="manage_course_structure.php?course_id=<?= $course_id ?>" class="btn btn-secondary">← Back to Course</a>
        <?php endif; ?>
    </div>
</div>

</body>
</html>