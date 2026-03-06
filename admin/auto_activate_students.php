<?php
/**
 * admin/auto_activate_students.php - Auto-Activate All Pending Students
 */

session_start();
require_once '../config/db.php';
require_once '../config/security_base.php';

// Check admin login
checkAdminSession();

$message = "";
$message_type = "";
$csrf_token = generateCSRF();

// Handle auto-activation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auto_activate'])) {
    validateCSRF($_POST['csrf_token'] ?? '');
    
    // Get all pending students
    $pending = $conn->query("
        SELECT student_id, course_id, year, semester 
        FROM students 
        WHERE status = 'pending' AND deleted = 0
    ");
    
    $activated = 0;
    $enrolled_total = 0;
    
    while ($student = $pending->fetch_assoc()) {
        // Activate student
        $activate = $conn->prepare("UPDATE students SET status = 'active' WHERE student_id = ?");
        $activate->bind_param("i", $student['student_id']);
        
        if ($activate->execute()) {
            $activated++;
            
            // Get modules for this course/year/semester
            $modules = $conn->prepare("
                SELECT module_id FROM modules 
                WHERE course_id = ? AND year = ? AND semester = ? AND deleted = 0
            ");
            $modules->bind_param("iii", $student['course_id'], $student['year'], $student['semester']);
            $modules->execute();
            $module_list = $modules->get_result()->fetch_all(MYSQLI_ASSOC);
            $modules->close();
            
            // Enroll in each module
            foreach ($module_list as $module) {
                $enroll = $conn->prepare("INSERT IGNORE INTO module_enrollments (student_id, module_id, enrolled_at) VALUES (?, ?, NOW())");
                $enroll->bind_param("ii", $student['student_id'], $module['module_id']);
                if ($enroll->execute()) {
                    $enrolled_total++;
                }
                $enroll->close();
            }
        }
        $activate->close();
    }
    
    $message = "✓ $activated students activated and enrolled in $enrolled_total modules";
    $message_type = "success";
    
    logAdminAction($conn, $_SESSION['admin_id'], 'auto_activate', "Auto-activated $activated students");
}

// Get statistics
$pending_count = $conn->query("SELECT COUNT(*) as count FROM students WHERE status = 'pending' AND deleted = 0")->fetch_assoc()['count'];

// Get pending by course
$by_course = $conn->query("
    SELECT 
        c.course_name,
        COUNT(*) as pending_count
    FROM students s
    JOIN courses c ON s.course_id = c.course_id
    WHERE s.status = 'pending' AND s.deleted = 0
    GROUP BY c.course_id, c.course_name
    ORDER BY pending_count DESC
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Auto-Activate Students - CSMS</title>
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
            max-width: 800px;
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
        
        .stats-card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .pending-number {
            font-size: 72px;
            font-weight: bold;
            color: #16a085;
            line-height: 1;
            margin: 20px 0;
        }
        
        .warning-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 20px;
            border-radius: 8px;
            margin: 30px 0;
            text-align: left;
        }
        
        .course-list {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .course-list h3 {
            color: #1a1a2e;
            margin-bottom: 15px;
        }
        
        .course-item {
            display: flex;
            justify-content: space-between;
            padding: 10px;
            border-bottom: 1px solid #eee;
        }
        
        .course-item:last-child {
            border-bottom: none;
        }
        
        .course-name {
            font-weight: bold;
        }
        
        .course-count {
            background: #16a085;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 30px;
        }
        
        @media (max-width: 768px) {
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
        <a href="pending_students.php">Pending Approvals</a> > 
        <strong>Auto-Activate</strong>
    </div>

    <!-- Page Header -->
    <div class="page-header">
        <h1>⚡ Auto-Activate Pending Students</h1>
    </div>

    <?php if ($message): ?>
        <div class="alert <?= $message_type ?>"><?= $message ?></div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-card">
        <h2>Pending Approvals</h2>
        <div class="pending-number"><?= $pending_count ?></div>
        <p style="color: #666;">students waiting for activation</p>
    </div>

    <!-- Pending by Course -->
    <?php if (count($by_course) > 0): ?>
    <div class="course-list">
        <h3>📊 Pending by Course</h3>
        <?php foreach ($by_course as $course): ?>
        <div class="course-item">
            <span class="course-name"><?= htmlspecialchars($course['course_name']) ?></span>
            <span class="course-count"><?= $course['pending_count'] ?> pending</span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Warning Box -->
    <div class="warning-box">
        <strong>⚠️ Important:</strong>
        <ul style="margin-top: 10px; margin-left: 20px;">
            <li>This will activate ALL pending students</li>
            <li>Students will be automatically enrolled in all modules for their course/year/semester</li>
            <li>Students will receive email notifications (if configured)</li>
            <li>This action cannot be undone automatically</li>
        </ul>
    </div>

    <!-- Action Form -->
    <?php if ($pending_count > 0): ?>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
        
        <div class="action-buttons">
            <button type="submit" name="auto_activate" class="btn btn-primary" onclick="return confirm('Activate ALL <?= $pending_count ?> pending students? This cannot be undone.')">
                ✓ Activate All <?= $pending_count ?> Students
            </button>
            <a href="pending_students.php" class="btn btn-danger">Cancel</a>
        </div>
    </form>
    <?php else: ?>
    <div style="text-align: center; padding: 40px; background: white; border-radius: 12px;">
        <p style="color: #666;">No pending students to activate.</p>
        <a href="pending_students.php" class="btn btn-primary" style="margin-top: 20px;">Back to Pending</a>
    </div>
    <?php endif; ?>

    <a href="dashboard.php" class="btn btn-secondary" style="margin-top: 20px; display: inline-block;">← Back to Dashboard</a>
</div>

</body>
</html>