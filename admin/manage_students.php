<?php
/**
 * admin/manage_students.php - Manage Students for a Semester
 */

session_start();
require_once '../config/db.php';
require_once '../config/security_base.php';

// Check admin login
checkAdminSession();

$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$year = isset($_GET['year']) ? (int)$_GET['year'] : 0;
$semester = isset($_GET['semester']) ? (int)$_GET['semester'] : 0;

if (!$course_id || !$year || !$semester) {
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

// Auto-enroll function
function auto_enroll_student($conn, $student_id, $course_id, $year, $semester) {
    $modules_stmt = $conn->prepare("SELECT module_id FROM modules WHERE course_id = ? AND year = ? AND semester = ? AND deleted = 0");
    $modules_stmt->bind_param("iii", $course_id, $year, $semester);
    $modules_stmt->execute();
    $modules = $modules_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $modules_stmt->close();
    
    $enrolled = 0;
    foreach ($modules as $module) {
        $check = $conn->prepare("SELECT id FROM module_enrollments WHERE student_id = ? AND module_id = ?");
        $check->bind_param("ii", $student_id, $module['module_id']);
        $check->execute();
        
        if ($check->get_result()->num_rows == 0) {
            $enroll = $conn->prepare("INSERT INTO module_enrollments (student_id, module_id, enrolled_at) VALUES (?, ?, NOW())");
            $enroll->bind_param("ii", $student_id, $module['module_id']);
            if ($enroll->execute()) {
                $enrolled++;
            }
            $enroll->close();
        }
        $check->close();
    }
    
    return $enrolled;
}

// Handle Activate Student
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'activate') {
    validateCSRF($_POST['csrf_token'] ?? '');
    
    $student_id = (int)($_POST['student_id'] ?? 0);
    
    if ($student_id) {
        $stmt = $conn->prepare("UPDATE students SET status = 'active' WHERE student_id = ? AND deleted = 0");
        $stmt->bind_param("i", $student_id);
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $enrolled = auto_enroll_student($conn, $student_id, $course_id, $year, $semester);
            
            $message = "✓ Student activated! Auto-enrolled in $enrolled module(s).";
            $message_type = "success";
            
            logAdminAction($conn, $_SESSION['admin_id'], 'activate_student', "Activated student ID: $student_id");
        }
        $stmt->close();
    }
}

// Handle Deactivate Student
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'deactivate') {
    validateCSRF($_POST['csrf_token'] ?? '');
    
    $student_id = (int)($_POST['student_id'] ?? 0);
    
    if ($student_id) {
        $stmt = $conn->prepare("UPDATE students SET status = 'inactive' WHERE student_id = ? AND deleted = 0");
        $stmt->bind_param("i", $student_id);
        
        if ($stmt->execute()) {
            $message = "⚠️ Student deactivated";
            $message_type = "success";
            
            logAdminAction($conn, $_SESSION['admin_id'], 'deactivate_student', "Deactivated student ID: $student_id");
        }
        $stmt->close();
    }
}

// Handle Delete Student
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    validateCSRF($_POST['csrf_token'] ?? '');
    
    $student_id = (int)($_POST['student_id'] ?? 0);
    
    if ($student_id) {
        $stmt = $conn->prepare("UPDATE students SET deleted = 1 WHERE student_id = ?");
        $stmt->bind_param("i", $student_id);
        
        if ($stmt->execute()) {
            $message = "Student moved to recycle bin";
            $message_type = "success";
            
            logAdminAction($conn, $_SESSION['admin_id'], 'delete_student', "Deleted student ID: $student_id");
        }
        $stmt->close();
    }
}

// Get pending students
$pending_stmt = $conn->prepare("
    SELECT student_id, reg_number, name, email, created_at
    FROM students
    WHERE course_id = ? AND year = ? AND semester = ? AND status = 'pending' AND deleted = 0
    ORDER BY created_at ASC
");
$pending_stmt->bind_param("iii", $course_id, $year, $semester);
$pending_stmt->execute();
$pending_students = $pending_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$pending_stmt->close();

// Get active students
$active_stmt = $conn->prepare("
    SELECT student_id, reg_number, name, email, created_at
    FROM students
    WHERE course_id = ? AND year = ? AND semester = ? AND status = 'active' AND deleted = 0
    ORDER BY name ASC
");
$active_stmt->bind_param("iii", $course_id, $year, $semester);
$active_stmt->execute();
$active_students = $active_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$active_stmt->close();

// Get inactive students
$inactive_stmt = $conn->prepare("
    SELECT student_id, reg_number, name, email, created_at
    FROM students
    WHERE course_id = ? AND year = ? AND semester = ? AND status = 'inactive' AND deleted = 0
    ORDER BY name ASC
");
$inactive_stmt->bind_param("iii", $course_id, $year, $semester);
$inactive_stmt->execute();
$inactive_students = $inactive_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$inactive_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Students - <?= htmlspecialchars($course['course_name']) ?></title>
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
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 100;
            width: 100%;
        }

        .header h1 {
            font-size: 1.8rem;
            font-weight: 700;
            background: linear-gradient(135deg, #2dd4bf, #14b8a6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .header a {
            color: white;
            text-decoration: none;
            padding: 0.5rem 1.2rem;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.1);
            transition: all 0.3s;
        }

        .header a:hover {
            background: #2dd4bf;
        }

        .container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 2rem;
        }

        .breadcrumb {
            background: white;
            padding: 1rem 1.5rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .breadcrumb a {
            color: #2dd4bf;
            text-decoration: none;
            font-weight: 500;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .page-header h1 {
            font-size: 2rem;
            color: #0f172a;
        }

        .btn {
            padding: 0.8rem 1.8rem;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s;
            cursor: pointer;
        }

        .btn-primary {
            background: linear-gradient(135deg, #2dd4bf, #14b8a6);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px -4px rgba(45, 212, 191, 0.4);
        }

        .btn-secondary {
            background: white;
            color: #0f172a;
            border: 1px solid #e2e8f0;
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #2dd4bf;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #0f172a;
        }

        .student-section {
            background: white;
            border-radius: 20px;
            margin-bottom: 2rem;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .section-header {
            padding: 1rem 1.5rem;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .section-header.pending { background: #f59e0b; }
        .section-header.active { background: #10b981; }
        .section-header.inactive { background: #64748b; }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: #f8fafc;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: #0f172a;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .action-btn {
            padding: 0.4rem 0.8rem;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            font-size: 0.85rem;
            cursor: pointer;
            text-decoration: none;
        }

        .btn-activate {
            background: #d1fae5;
            color: #065f46;
        }

        .btn-deactivate {
            background: #fee2e2;
            color: #991b1b;
        }

        .no-students {
            text-align: center;
            padding: 2rem;
            color: #64748b;
        }

        @media (max-width: 768px) {
            .container {
                padding: 0 1rem;
            }

            table {
                font-size: 0.9rem;
            }

            .action-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>

<div class="header">
    <h1>CSMS Admin</h1>
    <a href="logout.php">🚪 Logout</a>
</div>

<div class="container">
    <div class="breadcrumb">
        <a href="dashboard.php">Dashboard</a> > 
        <a href="manage_course_structure.php?course_id=<?= $course_id ?>"><?= htmlspecialchars($course['course_name']) ?></a> > 
        <a href="manage_semester.php?course_id=<?= $course_id ?>&year=<?= $year ?>&semester=<?= $semester ?>">Year <?= $year ?> - Semester <?= $semester ?></a> > 
        <strong>Students</strong>
    </div>

    <div class="page-header">
        <h1>👨‍🎓 Student Management</h1>
        <a href="manage_semester.php?course_id=<?= $course_id ?>&year=<?= $year ?>&semester=<?= $semester ?>" class="btn btn-secondary">← Back</a>
    </div>

    <?php if ($message): ?>
        <div class="alert <?= $message_type ?>"><?= $message ?></div>
    <?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-number"><?= count($pending_students) ?></div>
            <div>Pending Approval</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= count($active_students) ?></div>
            <div>Active Students</div>
        </div>
    </div>

    <?php if (count($pending_students) > 0): ?>
    <div class="student-section">
        <div class="section-header pending">
            <span>⏳ Pending Approvals</span>
            <span><?= count($pending_students) ?> students</span>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Reg Number</th>
                    <th>Student Name</th>
                    <th>Email</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pending_students as $student): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($student['reg_number']) ?></strong></td>
                    <td><?= htmlspecialchars($student['name']) ?></td>
                    <td><?= htmlspecialchars($student['email']) ?></td>
                    <td>
                        <div class="action-buttons">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                <input type="hidden" name="action" value="activate">
                                <input type="hidden" name="student_id" value="<?= $student['student_id'] ?>">
                                <button type="submit" class="action-btn btn-activate">✓ Activate</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div class="student-section">
        <div class="section-header active">
            <span>✓ Active Students</span>
            <span><?= count($active_students) ?> students</span>
        </div>
        
        <?php if (count($active_students) > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>Reg Number</th>
                    <th>Student Name</th>
                    <th>Email</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($active_students as $student): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($student['reg_number']) ?></strong></td>
                    <td><?= htmlspecialchars($student['name']) ?></td>
                    <td><?= htmlspecialchars($student['email']) ?></td>
                    <td>
                        <div class="action-buttons">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                <input type="hidden" name="action" value="deactivate">
                                <input type="hidden" name="student_id" value="<?= $student['student_id'] ?>">
                                <button type="submit" class="action-btn btn-deactivate">Deactivate</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="no-students">No active students</div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>