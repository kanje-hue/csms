<?php
session_start();
include '../config/db.php';

if(!isset($_SESSION['admin_logged_in'])){
    header("Location: login.php");
    exit();
}

$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : null;

if(!$course_id){
    die("❌ Course not specified");
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = "";
$message_type = "";

// Fix enrollments
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'fix_enrollments'){
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = "Security token verification failed";
        $message_type = "error";
    } else {
        // Get all active students for this course
        $students_query = "SELECT student_id, year, semester FROM students WHERE course_id = ? AND status = 'active' AND deleted = 0";
        $students_stmt = $conn->prepare($students_query);
        $students_stmt->bind_param("i", $course_id);
        $students_stmt->execute();
        $students = $students_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $students_stmt->close();
        
        $enrolled_count = 0;
        
        // For each student, enroll in all modules of their course/year/semester
        foreach($students as $student) {
            $student_id = $student['student_id'];
            $year = $student['year'];
            $semester = $student['semester'];
            
            // Get all modules for this course/year/semester
            $modules_query = "SELECT module_id FROM modules WHERE course_id = ? AND year = ? AND semester = ? AND deleted = 0";
            $modules_stmt = $conn->prepare($modules_query);
            $modules_stmt->bind_param("iii", $course_id, $year, $semester);
            $modules_stmt->execute();
            $modules = $modules_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $modules_stmt->close();
            
            // Enroll in each module
            foreach($modules as $module) {
                $enroll = $conn->prepare("INSERT IGNORE INTO module_enrollments (student_id, module_id, enrolled_at) VALUES (?, ?, NOW())");
                $enroll->bind_param("ii", $student_id, $module['module_id']);
                if($enroll->execute() && $enroll->affected_rows > 0) {
                    $enrolled_count++;
                }
                $enroll->close();
            }
        }
        
        $message = "✓ Fixed! Enrolled " . $enrolled_count . " student(s) in module(s)";
        $message_type = "success";
    }
}

// Get course name
$course_stmt = $conn->prepare("SELECT course_name FROM courses WHERE course_id = ? AND deleted = 0");
$course_stmt->bind_param("i", $course_id);
$course_stmt->execute();
$course_result = $course_stmt->get_result()->fetch_assoc();
$course_name = $course_result ? $course_result['course_name'] : 'Unknown';
$course_stmt->close();

// Get all years and semesters for this course
$years_semesters = "SELECT DISTINCT year, semester FROM students WHERE course_id = ? AND deleted = 0 ORDER BY year DESC, semester DESC";
$ys_stmt = $conn->prepare($years_semesters);
$ys_stmt->bind_param("i", $course_id);
$ys_stmt->execute();
$year_semester_data = $ys_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$ys_stmt->close();

?>

<!DOCTYPE html>
<html>
<head>
    <title>Verify Enrollments</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        .auth-card {
            width: 1100px;
            max-width: 100%;
            padding: 30px;
            border-radius: 18px;
            background: var(--white);
            box-shadow: 0 20px 45px rgba(0,0,0,0.15);
            margin: 30px auto;
        }

        h2 {
            text-align: center;
            color: var(--midnight-garden);
            margin-bottom: 10px;
        }

        .breadcrumb {
            text-align: center;
            font-size: 13px;
            color: #666;
            margin-bottom: 20px;
        }

        .alert {
            padding: 12px;
            margin: 15px 0;
            border-radius: 8px;
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

        .tabs {
            display: flex;
            gap: 10px;
            margin: 20px 0;
            border-bottom: 2px solid #ddd;
            flex-wrap: wrap;
        }

        .tab-btn {
            padding: 10px 15px;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: bold;
            color: #666;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
        }

        .tab-btn.active {
            color: var(--terra-rosa);
            border-bottom-color: var(--terra-rosa);
        }

        .tab-btn:hover {
            color: var(--terra-rosa);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th, td {
            padding: 12px;
            border: 1px solid #ddd;
            text-align: left;
        }

        th {
            background: var(--minty-fresh);
            color: var(--art-craft);
            font-weight: bold;
        }

        tr:nth-child(even) {
            background: #f9f9f9;
        }

        .status-cell {
            font-weight: bold;
            text-align: center;
        }

        .status-ok {
            color: #4CAF50;
        }

        .status-error {
            color: #f44336;
        }

        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: linear-gradient(135deg, var(--terra-rosa), var(--honey-glow));
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
            margin: 20px 0;
        }

        .btn:hover {
            opacity: 0.9;
        }

        .back-link {
            text-align: center;
            margin-top: 20px;
        }

        .back-link a {
            color: var(--terra-rosa);
            text-decoration: none;
            font-weight: bold;
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin: 20px 0;
        }

        .stat-box {
            background: linear-gradient(135deg, var(--terra-rosa), var(--honey-glow));
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }

        .stat-number {
            font-size: 28px;
            font-weight: bold;
        }

        .stat-label {
            font-size: 12px;
            opacity: 0.9;
            margin-top: 5px;
        }

        @media (max-width: 768px) {
            .auth-card {
                width: 95%;
                padding: 15px;
            }

            .tabs {
                flex-direction: column;
            }

            .stat-grid {
                grid-template-columns: 1fr;
            }

            table {
                font-size: 12px;
            }

            th, td {
                padding: 8px;
            }
        }
    </style>
</head>
<body>

<div class="auth-card">
    <h2>🔍 Verify & Fix Enrollments</h2>
    <div class="breadcrumb">
        <?= htmlspecialchars($course_name) ?>
    </div>

    <?php if ($message): ?>
        <div class="alert <?= $message_type === 'success' ? 'success' : 'error' ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="tabs">
        <?php foreach ($year_semester_data as $index => $ys): ?>
            <button class="tab-btn <?= $index === 0 ? 'active' : '' ?>" onclick="switchTab(event, 'year-<?= $ys['year'] ?>-sem-<?= $ys['semester'] ?>')">
                Year <?= $ys['year'] ?> | Semester <?= $ys['semester'] ?>
            </button>
        <?php endforeach; ?>
    </div>

    <?php foreach ($year_semester_data as $index => $ys): 
        $year = $ys['year'];
        $semester = $ys['semester'];
        
        // Get statistics for this year/semester
        $students_query = "SELECT COUNT(*) as count FROM students WHERE course_id = ? AND year = ? AND semester = ? AND status = 'active' AND deleted = 0";
        $students_stmt = $conn->prepare($students_query);
        $students_stmt->bind_param("iii", $course_id, $year, $semester);
        $students_stmt->execute();
        $students_count = $students_stmt->get_result()->fetch_assoc()['count'];
        $students_stmt->close();
        
        $modules_query = "SELECT COUNT(*) as count FROM modules WHERE course_id = ? AND year = ? AND semester = ? AND deleted = 0";
        $modules_stmt = $conn->prepare($modules_query);
        $modules_stmt->bind_param("iii", $course_id, $year, $semester);
        $modules_stmt->execute();
        $modules_count = $modules_stmt->get_result()->fetch_assoc()['count'];
        $modules_stmt->close();
        
        // Get enrollment details
        $enrollment_query = "
            SELECT 
                m.module_id,
                m.module_code,
                m.module_name,
                COUNT(DISTINCT me.student_id) as enrolled_students
            FROM modules m
            LEFT JOIN module_enrollments me ON m.module_id = me.module_id
            WHERE m.course_id = ? AND m.year = ? AND m.semester = ? AND m.deleted = 0
            GROUP BY m.module_id, m.module_code, m.module_name
            ORDER BY m.module_code ASC
        ";
        $enrollment_stmt = $conn->prepare($enrollment_query);
        $enrollment_stmt->bind_param("iii", $course_id, $year, $semester);
        $enrollment_stmt->execute();
        $enrollment_data = $enrollment_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $enrollment_stmt->close();
    ?>

    <div id="year-<?= $year ?>-sem-<?= $semester ?>" class="tab-content <?= $index === 0 ? 'active' : '' ?>">
        <div class="stat-grid">
            <div class="stat-box">
                <div class="stat-number"><?= $students_count ?></div>
                <div class="stat-label">Active Students</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?= $modules_count ?></div>
                <div class="stat-label">Modules</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?= $students_count * $modules_count ?></div>
                <div class="stat-label">Expected Enrollments</div>
            </div>
        </div>

        <h3 style="margin-top: 30px; color: var(--midnight-garden);">Enrollment Status</h3>
        <table>
            <thead>
                <tr>
                    <th>Module Code</th>
                    <th>Module Name</th>
                    <th>Enrolled Students</th>
                    <th>Total Students</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($enrollment_data as $enrollment): 
                    $is_complete = $enrollment['enrolled_students'] == $students_count;
                    $status_class = $is_complete ? 'status-ok' : 'status-error';
                    $status_text = $is_complete ? '✓ Complete' : '❌ Missing';
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($enrollment['module_code']) ?></strong></td>
                    <td><?= htmlspecialchars($enrollment['module_name']) ?></td>
                    <td><?= $enrollment['enrolled_students'] ?></td>
                    <td><?= $students_count ?></td>
                    <td class="status-cell <?= $status_class ?>"><?= $status_text ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php endforeach; ?>

    <form method="POST" style="text-align: center;">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="action" value="fix_enrollments">
        <button type="submit" class="btn">🔧 Fix All Missing Enrollments for This Course</button>
    </form>

    <div class="back-link">
        <a href="dashboard.php">← Back to Dashboard</a>
    </div>
</div>

<script>
function switchTab(event, tabId) {
    // Hide all tab contents
    const contents = document.querySelectorAll('.tab-content');
    contents.forEach(content => {
        content.classList.remove('active');
    });
    
    // Remove active class from all buttons
    const buttons = document.querySelectorAll('.tab-btn');
    buttons.forEach(btn => {
        btn.classList.remove('active');
    });
    
    // Show selected tab and mark button as active
    document.getElementById(tabId).classList.add('active');
    event.target.classList.add('active');
}
</script>

</body>
</html>