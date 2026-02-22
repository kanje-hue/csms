<?php
session_start();
include '../config/db.php';

if(!isset($_SESSION['admin_logged_in'])){
    header("Location: login.php");
    exit();
}

$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : null;
$year = isset($_GET['year']) ? (int)$_GET['year'] : null;
$semester = isset($_GET['semester']) ? (int)$_GET['semester'] : null;

if(!$course_id || !$year || !$semester){
    die("Invalid access.");
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = "";
$message_type = "";

/* ================= AUTO-ENROLL FUNCTION ================= */
function auto_enroll_student($conn, $student_id, $course_id, $year, $semester) {
    try {
        // Get all modules for this course/year/semester
        $query = "SELECT module_id FROM modules WHERE course_id = ? AND year = ? AND semester = ? AND deleted = 0";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("iii", $course_id, $year, $semester);
        $stmt->execute();
        $result = $stmt->get_result();
        $modules = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        $enrolled_count = 0;
        
        // Enroll in each module using INSERT IGNORE
        foreach($modules as $module) {
            $module_id = $module['module_id'];
            $enroll = $conn->prepare("INSERT IGNORE INTO module_enrollments (student_id, module_id, enrolled_at) VALUES (?, ?, NOW())");
            $enroll->bind_param("ii", $student_id, $module_id);
            
            if($enroll->execute()) {
                $enrolled_count++;
            }
            $enroll->close();
        }
        
        return $enrolled_count;
    } catch(Exception $e) {
        return 0;
    }
}

/* ================= ACTIVATE STUDENT ================= */
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'activate'){
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = "Security token verification failed";
        $message_type = "error";
    } else {
        $student_id = (int)($_POST['student_id'] ?? 0);
        
        if($student_id > 0){
            // Step 1: Update student status to active
            $stmt = $conn->prepare("UPDATE students SET status = 'active' WHERE student_id = ? AND deleted = 0");
            $stmt->bind_param("i", $student_id);
            
            if($stmt->execute() && $stmt->affected_rows > 0){
                // Step 2: Auto-enroll in ALL modules for this course/year/semester
                $enrolled = auto_enroll_student($conn, $student_id, $course_id, $year, $semester);
                
                $message = "✓ Student activated! Auto-enrolled in $enrolled module(s). Students will appear in all teacher's lists.";
                $message_type = "success";
            } else {
                $message = "Error activating student";
                $message_type = "error";
            }
            $stmt->close();
        }
    }
}

/* ================= DEACTIVATE STUDENT ================= */
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'deactivate'){
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = "Security token verification failed";
        $message_type = "error";
    } else {
        $student_id = (int)($_POST['student_id'] ?? 0);
        
        if($student_id > 0){
            $stmt = $conn->prepare("UPDATE students SET status = 'inactive' WHERE student_id = ? AND deleted = 0");
            $stmt->bind_param("i", $student_id);
            
            if($stmt->execute()){
                $message = "⚠️ Student deactivated";
                $message_type = "success";
            }
            $stmt->close();
        }
    }
}

/* ================= DELETE STUDENT ================= */
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete'){
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = "Security token verification failed";
        $message_type = "error";
    } else {
        $student_id = (int)($_POST['student_id'] ?? 0);
        
        if($student_id > 0){
            $stmt = $conn->prepare("UPDATE students SET deleted = 1 WHERE student_id = ?");
            $stmt->bind_param("i", $student_id);
            
            if($stmt->execute()){
                $message = "Student deleted";
                $message_type = "success";
            }
            $stmt->close();
        }
    }
}

/* ================= FETCH DATA ================= */

// Get course name
$course_stmt = $conn->prepare("SELECT course_name FROM courses WHERE course_id = ? AND deleted = 0");
$course_stmt->bind_param("i", $course_id);
$course_stmt->execute();
$course_result = $course_stmt->get_result()->fetch_assoc();
$course_name = $course_result ? $course_result['course_name'] : 'Unknown';
$course_stmt->close();

// Fetch pending students
$pending_query = "SELECT student_id, reg_number, name, email, created_at 
                  FROM students 
                  WHERE deleted = 0 AND course_id = ? AND year = ? AND semester = ? AND status = 'pending' 
                  ORDER BY created_at DESC";
$pending_stmt = $conn->prepare($pending_query);
$pending_stmt->bind_param("iii", $course_id, $year, $semester);
$pending_stmt->execute();
$pending_students = $pending_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$pending_stmt->close();

// Fetch ALL students 
$student_query = "SELECT student_id, reg_number, name, email, status, created_at
                  FROM students 
                  WHERE deleted = 0 AND course_id = ? AND year = ? AND semester = ?
                  ORDER BY status DESC, name ASC";
$student_stmt = $conn->prepare($student_query);
$student_stmt->bind_param("iii", $course_id, $year, $semester);
$student_stmt->execute();
$all_students = $student_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$student_stmt->close();

// Separate active and inactive
$active_students = array_filter($all_students, function($s) { return $s['status'] === 'active'; });
$inactive_students = array_filter($all_students, function($s) { return $s['status'] === 'inactive'; });

?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Students</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        .auth-card {
            width: 1000px;
            max-width: 100%;
            padding: 30px;
            border-radius: 18px;
            background: var(--white);
            box-shadow: 0 20px 45px rgba(0,0,0,0.15);
            margin: 30px auto;
        }

        h2 {
            text-align: center;
            margin-bottom: 15px;
            color: var(--midnight-garden);
        }

        h3 {
            margin-top: 30px;
            margin-bottom: 15px;
            color: var(--midnight-garden);
            border-bottom: 2px solid var(--minty-fresh);
            padding-bottom: 10px;
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

        tr:hover {
            background: #f0f0f0;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }

        .badge-active {
            background: #d4edda;
            color: #155724;
        }

        .badge-pending {
            background: #cce5ff;
            color: #004085;
        }

        .badge-inactive {
            background: #fff3cd;
            color: #856404;
        }

        .btn {
            background: none;
            border: none;
            color: var(--terra-rosa);
            font-weight: bold;
            cursor: pointer;
            text-decoration: underline;
            margin: 0 5px;
            padding: 0;
            font-size: 14px;
        }

        .btn:hover {
            opacity: 0.8;
        }

        .btn.danger {
            color: #f44336;
        }

        .btn.success {
            color: #4CAF50;
        }

        .btn.warning {
            color: #FF9800;
        }

        .back-btn {
            display: inline-block;
            padding: 8px 15px;
            background: linear-gradient(135deg, var(--terra-rosa), var(--honey-glow));
            color: #fff;
            border-radius: 12px;
            text-decoration: none;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .back-btn:hover {
            opacity: 0.9;
        }

        .pending-section {
            background: #cce5ff;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #004085;
        }

        .no-data {
            text-align: center;
            padding: 30px;
            color: #666;
        }

        .count-badge {
            background: #FF6B6B;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
            margin-left: 10px;
        }

        @media (max-width: 768px) {
            .auth-card {
                width: 95%;
                padding: 15px;
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
    <h2>Manage Students</h2>

    <div class="breadcrumb">
        <?= htmlspecialchars($course_name) ?> | Year <?= $year ?> | Semester <?= $semester ?>
    </div>

    <?php if ($message): ?>
        <div class="alert <?= $message_type === 'success' ? 'success' : 'error' ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <a href="manage_courses.php" class="back-btn">← Back</a>
    <div style="clear: both;"></div>

    <!-- PENDING STUDENTS -->
    <?php if (count($pending_students) > 0): ?>
        <div class="pending-section">
            <h3 style="margin-top: 0; color: #004085;">⏳ Pending Approvals <span class="count-badge"><?= count($pending_students) ?></span></h3>
            <table>
                <thead>
                    <tr>
                        <th>Reg Number</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Registered</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending_students as $s): ?>
                    <tr style="background: #e3f2fd;">
                        <td><strong><?= htmlspecialchars($s['reg_number']) ?></strong></td>
                        <td><?= htmlspecialchars($s['name']) ?></td>
                        <td><?= htmlspecialchars($s['email']) ?></td>
                        <td><?= date('M d, Y', strtotime($s['created_at'])) ?></td>
                        <td>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="action" value="activate">
                                <input type="hidden" name="student_id" value="<?= $s['student_id'] ?>">
                                <button type="submit" class="btn success">✓ Activate</button>
                            </form>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="student_id" value="<?= $s['student_id'] ?>">
                                <button type="submit" class="btn danger" onclick="return confirm('Reject this registration?');">✗ Reject</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <!-- ACTIVE STUDENTS -->
    <h3>✓ Active Students <span class="count-badge"><?= count($active_students) ?></span></h3>
    <?php if (count($active_students) > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>Reg Number</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($active_students as $s): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($s['reg_number']) ?></strong></td>
                    <td><?= htmlspecialchars($s['name']) ?></td>
                    <td><?= htmlspecialchars($s['email']) ?></td>
                    <td><span class="badge badge-active">✓ Active</span></td>
                    <td>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="action" value="deactivate">
                            <input type="hidden" name="student_id" value="<?= $s['student_id'] ?>">
                            <button type="submit" class="btn warning">Deactivate</button>
                        </form>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="student_id" value="<?= $s['student_id'] ?>">
                            <button type="submit" class="btn danger" onclick="return confirm('Delete this student?');">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="no-data">No active students</div>
    <?php endif; ?>

    <!-- INACTIVE STUDENTS -->
    <?php if (count($inactive_students) > 0): ?>
        <h3>⚠️ Inactive Students <span class="count-badge"><?= count($inactive_students) ?></span></h3>
        <table>
            <thead>
                <tr>
                    <th>Reg Number</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($inactive_students as $s): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($s['reg_number']) ?></strong></td>
                    <td><?= htmlspecialchars($s['name']) ?></td>
                    <td><?= htmlspecialchars($s['email']) ?></td>
                    <td><span class="badge badge-inactive">⚠️ Inactive</span></td>
                    <td>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="action" value="activate">
                            <input type="hidden" name="student_id" value="<?= $s['student_id'] ?>">
                            <button type="submit" class="btn success">Activate</button>
                        </form>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="student_id" value="<?= $s['student_id'] ?>">
                            <button type="submit" class="btn danger" onclick="return confirm('Delete this student?');">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div style="text-align: center; margin-top: 30px;">
        <a href="manage_courses.php">← Back to Dashboard</a>
    </div>
</div>

</body>
</html>