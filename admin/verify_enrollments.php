<?php
/**
 * admin/verify_enrollments.php - Verify and Fix Student Enrollments
 */

session_start();
require_once '../config/db.php';
require_once '../config/security_base.php';

// Check admin login
checkAdminSession();

$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;

if (!$course_id) {
    header("Location: dashboard.php");
    exit();
}

// Get course details
$course_stmt = $conn->prepare("SELECT course_name FROM courses WHERE course_id = ? AND deleted = 0");
$course_stmt->bind_param("i", $course_id);
$course_stmt->execute();
$course = $course_stmt->get_result()->fetch_assoc();
$course_stmt->close();

$message = "";
$message_type = "";
$csrf_token = generateCSRF();

// Handle fix enrollments
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'fix') {
    validateCSRF($_POST['csrf_token'] ?? '');
    
    $year = isset($_POST['year']) ? (int)$_POST['year'] : 0;
    $semester = isset($_POST['semester']) ? (int)$_POST['semester'] : 0;
    
    if ($year && $semester) {
        // Get all active students for this course/year/semester
        $students = $conn->prepare("
            SELECT student_id FROM students 
            WHERE course_id = ? AND year = ? AND semester = ? AND status = 'active' AND deleted = 0
        ");
        $students->bind_param("iii", $course_id, $year, $semester);
        $students->execute();
        $student_list = $students->get_result()->fetch_all(MYSQLI_ASSOC);
        $students->close();
        
        // Get all modules for this course/year/semester
        $modules = $conn->prepare("
            SELECT module_id FROM modules 
            WHERE course_id = ? AND year = ? AND semester = ? AND deleted = 0
        ");
        $modules->bind_param("iii", $course_id, $year, $semester);
        $modules->execute();
        $module_list = $modules->get_result()->fetch_all(MYSQLI_ASSOC);
        $modules->close();
        
        $fixed = 0;
        $total_possible = count($student_list) * count($module_list);
        
        foreach ($student_list as $student) {
            foreach ($module_list as $module) {
                // Check if enrollment exists
                $check = $conn->prepare("
                    SELECT id FROM module_enrollments 
                    WHERE student_id = ? AND module_id = ?
                ");
                $check->bind_param("ii", $student['student_id'], $module['module_id']);
                $check->execute();
                
                if ($check->get_result()->num_rows == 0) {
                    // Create enrollment
                    $enroll = $conn->prepare("
                        INSERT INTO module_enrollments (student_id, module_id, enrolled_at) 
                        VALUES (?, ?, NOW())
                    ");
                    $enroll->bind_param("ii", $student['student_id'], $module['module_id']);
                    if ($enroll->execute()) {
                        $fixed++;
                    }
                    $enroll->close();
                }
                $check->close();
            }
        }
        
        $message = "✓ Fixed $fixed missing enrollments out of $total_possible possible";
        $message_type = "success";
        
        logAdminAction($conn, $_SESSION['admin_id'], 'fix_enrollments', "Fixed $fixed enrollments for course ID: $course_id, Year $year, Sem $semester");
    }
}

// Get all years and semesters with students
$years_semesters = $conn->prepare("
    SELECT DISTINCT year, semester 
    FROM students 
    WHERE course_id = ? AND deleted = 0
    ORDER BY year DESC, semester DESC
");
$years_semesters->bind_param("i", $course_id);
$years_semesters->execute();
$year_sem_data = $years_semesters->get_result()->fetch_all(MYSQLI_ASSOC);
$years_semesters->close();

// Get enrollment statistics
$enrollment_stats = [];
foreach ($year_sem_data as $ys) {
    $year = $ys['year'];
    $semester = $ys['semester'];
    
    // Get student count
    $student_count = $conn->prepare("
        SELECT COUNT(*) as count FROM students 
        WHERE course_id = ? AND year = ? AND semester = ? AND status = 'active' AND deleted = 0
    ");
    $student_count->bind_param("iii", $course_id, $year, $semester);
    $student_count->execute();
    $students = $student_count->get_result()->fetch_assoc()['count'];
    $student_count->close();
    
    // Get module count
    $module_count = $conn->prepare("
        SELECT COUNT(*) as count FROM modules 
        WHERE course_id = ? AND year = ? AND semester = ? AND deleted = 0
    ");
    $module_count->bind_param("iii", $course_id, $year, $semester);
    $module_count->execute();
    $modules = $module_count->get_result()->fetch_assoc()['count'];
    $module_count->close();
    
    // Get actual enrollment count
    $enroll_count = $conn->prepare("
        SELECT COUNT(*) as count FROM module_enrollments me
        JOIN students s ON me.student_id = s.student_id
        WHERE s.course_id = ? AND s.year = ? AND s.semester = ? 
        AND s.status = 'active' AND s.deleted = 0
    ");
    $enroll_count->bind_param("iii", $course_id, $year, $semester);
    $enroll_count->execute();
    $actual = $enroll_count->get_result()->fetch_assoc()['count'];
    $enroll_count->close();
    
    $expected = $students * $modules;
    $missing = $expected - $actual;
    $percentage = $expected > 0 ? round(($actual / $expected) * 100) : 0;
    
    $enrollment_stats["{$year}_{$semester}"] = [
        'students' => $students,
        'modules' => $modules,
        'expected' => $expected,
        'actual' => $actual,
        'missing' => $missing,
        'percentage' => $percentage
    ];
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Verify Enrollments - <?= htmlspecialchars($course['course_name']) ?></title>
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
        
        .btn-success {
            background: #27ae60;
            color: white;
        }
        
        .btn-warning {
            background: #f39c12;
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
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #eee;
        }
        
        .stat-header h3 {
            color: #1a1a2e;
            font-size: 18px;
        }
        
        .progress-bar {
            height: 20px;
            background: #e0e0e0;
            border-radius: 10px;
            overflow: hidden;
            margin: 15px 0;
        }
        
        .progress-fill {
            height: 100%;
            background: #16a085;
            transition: width 0.3s;
        }
        
        .stat-row {
            display: flex;
            justify-content: space-between;
            margin: 8px 0;
            font-size: 14px;
        }
        
        .stat-label {
            color: #666;
        }
        
        .stat-value {
            font-weight: bold;
        }
        
        .missing {
            color: #e74c3c;
        }
        
        .ok {
            color: #27ae60;
        }
        
        .fix-form {
            margin-top: 15px;
            text-align: right;
        }
        
        .summary-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        
        .summary-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .summary-item {
            text-align: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .summary-number {
            font-size: 24px;
            font-weight: bold;
            color: #1a1a2e;
        }
        
        .summary-label {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        
        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #16a085;
            text-decoration: none;
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
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
        <a href="manage_course_structure.php?course_id=<?= $course_id ?>"><?= htmlspecialchars($course['course_name']) ?></a> > 
        <strong>Verify Enrollments</strong>
    </div>

    <!-- Page Header -->
    <div class="page-header">
        <h1>🔍 Verify Student Enrollments - <?= htmlspecialchars($course['course_name']) ?></h1>
    </div>

    <?php if ($message): ?>
        <div class="alert <?= $message_type ?>"><?= $message ?></div>
    <?php endif; ?>

    <!-- Summary -->
    <?php
    $total_students = 0;
    $total_modules = 0;
    $total_expected = 0;
    $total_actual = 0;
    $total_missing = 0;
    
    foreach ($enrollment_stats as $stat) {
        $total_students += $stat['students'];
        $total_modules += $stat['modules'];
        $total_expected += $stat['expected'];
        $total_actual += $stat['actual'];
        $total_missing += $stat['missing'];
    }
    ?>
    
    <div class="summary-card">
        <h3 style="color: #1a1a2e;">📊 Overall Summary</h3>
        <div class="summary-stats">
            <div class="summary-item">
                <div class="summary-number"><?= $total_students ?></div>
                <div class="summary-label">Active Students</div>
            </div>
            <div class="summary-item">
                <div class="summary-number"><?= $total_modules ?></div>
                <div class="summary-label">Modules</div>
            </div>
            <div class="summary-item">
                <div class="summary-number"><?= $total_expected ?></div>
                <div class="summary-label">Expected Enrollments</div>
            </div>
            <div class="summary-item">
                <div class="summary-number <?= $total_missing > 0 ? 'missing' : 'ok' ?>">
                    <?= $total_actual ?>
                </div>
                <div class="summary-label">Actual Enrollments</div>
            </div>
            <div class="summary-item">
                <div class="summary-number missing"><?= $total_missing ?></div>
                <div class="summary-label">Missing</div>
            </div>
        </div>
    </div>

    <!-- Year/Semester Stats -->
    <div class="stats-grid">
        <?php foreach ($year_sem_data as $ys): 
            $year = $ys['year'];
            $semester = $ys['semester'];
            $key = "{$year}_{$semester}";
            $stat = $enrollment_stats[$key] ?? ['students' => 0, 'modules' => 0, 'expected' => 0, 'actual' => 0, 'missing' => 0, 'percentage' => 0];
        ?>
        <div class="stat-card">
            <div class="stat-header">
                <h3>Year <?= $year ?> - Semester <?= $semester ?></h3>
                <span class="<?= $stat['missing'] > 0 ? 'missing' : 'ok' ?>">
                    <?= $stat['percentage'] ?>%
                </span>
            </div>
            
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?= $stat['percentage'] ?>%"></div>
            </div>
            
            <div class="stat-row">
                <span class="stat-label">Active Students:</span>
                <span class="stat-value"><?= $stat['students'] ?></span>
            </div>
            <div class="stat-row">
                <span class="stat-label">Modules:</span>
                <span class="stat-value"><?= $stat['modules'] ?></span>
            </div>
            <div class="stat-row">
                <span class="stat-label">Expected Enrollments:</span>
                <span class="stat-value"><?= $stat['expected'] ?></span>
            </div>
            <div class="stat-row">
                <span class="stat-label">Actual Enrollments:</span>
                <span class="stat-value <?= $stat['missing'] > 0 ? 'missing' : 'ok' ?>">
                    <?= $stat['actual'] ?>
                </span>
            </div>
            <div class="stat-row">
                <span class="stat-label">Missing:</span>
                <span class="stat-value missing"><?= $stat['missing'] ?></span>
            </div>
            
            <?php if ($stat['missing'] > 0): ?>
            <div class="fix-form">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="action" value="fix">
                    <input type="hidden" name="year" value="<?= $year ?>">
                    <input type="hidden" name="semester" value="<?= $semester ?>">
                    <button type="submit" class="btn btn-warning" onclick="return confirm('Fix missing enrollments for Year <?= $year ?>, Semester <?= $semester ?>?')">
                        🔧 Fix <?= $stat['missing'] ?> Missing
                    </button>
                </form>
            </div>
            <?php else: ?>
            <div style="text-align: right; margin-top: 15px;">
                <span class="ok">✓ All enrollments complete</span>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Global Fix Button -->
    <?php if ($total_missing > 0): ?>
    <div style="text-align: center; margin: 30px 0;">
        <form method="POST" style="display: inline;">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="action" value="fix_all">
            <button type="submit" class="btn btn-primary" onclick="return confirm('Fix ALL missing enrollments for this course?')">
                🔧 Fix All Missing Enrollments (<?= $total_missing ?> total)
            </button>
        </form>
    </div>
    <?php endif; ?>

    <a href="manage_course_structure.php?course_id=<?= $course_id ?>" class="back-link">← Back to Course Structure</a>
</div>

</body>
</html>