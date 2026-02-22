<?php
session_start();
include '../config/db.php';

if(!isset($_SESSION['teacher_id'])){
    header("Location: login.php");
    exit();
}

$module_id = isset($_GET['module_id']) ? (int)$_GET['module_id'] : null;
if(!$module_id) die("Module not specified");

$teacher_id = $_SESSION['teacher_id'];

// Verify teacher owns this module
$check = $conn->prepare("SELECT module_id FROM modules WHERE module_id = ? AND teacher_id = ? AND deleted = 0");
$check->bind_param("ii", $module_id, $teacher_id);
$check->execute();
if ($check->get_result()->num_rows == 0) die("Unauthorized");
$check->close();

// Get module info
$module_stmt = $conn->prepare("
    SELECT m.module_code, m.module_name, c.course_name, m.year, m.semester
    FROM modules m
    LEFT JOIN courses c ON m.course_id = c.course_id
    WHERE m.module_id = ?
");
$module_stmt->bind_param("i", $module_id);
$module_stmt->execute();
$module = $module_stmt->get_result()->fetch_assoc();
$module_stmt->close();

// Get enrolled students
$students_query = "
    SELECT 
        s.student_id,
        s.reg_number,
        s.name,
        s.email,
        me.enrolled_at
    FROM module_enrollments me
    INNER JOIN students s ON me.student_id = s.student_id
    WHERE me.module_id = ? AND s.deleted = 0 AND s.status = 'active'
    ORDER BY s.name ASC
";

$students_stmt = $conn->prepare($students_query);
$students_stmt->bind_param("i", $module_id);
$students_stmt->execute();
$students = $students_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$students_stmt->close();

// Save attendance
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_attendance'){
    foreach($_POST['attendance'] as $student_id => $status){
        $student_id = (int)$student_id;
        $status = in_array($status, ['present', 'absent', 'late']) ? $status : 'absent';
        $date = date('Y-m-d');
        
        $check = $conn->prepare("SELECT id FROM attendance WHERE student_id = ? AND module_id = ? AND date = ?");
        $check->bind_param("iis", $student_id, $module_id, $date);
        $check->execute();
        
        if($check->get_result()->num_rows > 0){
            $stmt = $conn->prepare("UPDATE attendance SET status = ? WHERE student_id = ? AND module_id = ? AND date = ?");
            $stmt->bind_param("siis", $status, $student_id, $module_id, $date);
        } else {
            $stmt = $conn->prepare("INSERT INTO attendance (student_id, module_id, date, status) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("iiss", $student_id, $module_id, $date, $status);
        }
        $stmt->execute();
        $stmt->close();
        $check->close();
    }
    $message = "✓ Attendance saved successfully!";
}

?>

<!DOCTYPE html>
<html>
<head>
    <title>Module Students - <?= $module['module_code'] ?></title>
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
        }

        .module-info {
            background: linear-gradient(135deg, var(--terra-rosa), var(--honey-glow));
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .module-info strong {
            display: block;
            font-size: 18px;
            margin-bottom: 10px;
        }

        .student-count {
            text-align: center;
            padding: 20px;
            background: #f9f9f9;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 18px;
            font-weight: bold;
            color: var(--midnight-garden);
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

        .success {
            background: #d4edda;
            color: #155724;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #c3e6cb;
        }

        .btn {
            background: linear-gradient(135deg, var(--terra-rosa), var(--honey-glow));
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
            margin-top: 20px;
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

        .no-data {
            text-align: center;
            padding: 40px;
            color: #666;
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
    <h2>📚 Module Students & Attendance</h2>

    <div class="module-info">
        <strong><?= htmlspecialchars($module['module_code']) ?> - <?= htmlspecialchars($module['module_name']) ?></strong>
        <small><?= htmlspecialchars($module['course_name']) ?> | Year <?= $module['year'] ?> | Semester <?= $module['semester'] ?></small>
    </div>

    <?php if(isset($message)): ?>
        <div class="success"><?= $message ?></div>
    <?php endif; ?>

    <div class="student-count">
        Total Enrolled: <strong><?= count($students) ?></strong> Student(s)
    </div>

    <?php if (count($students) > 0): ?>
        <form method="POST">
            <input type="hidden" name="action" value="save_attendance">
            
            <table>
                <thead>
                    <tr>
                        <th>Reg Number</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Attendance (Today)</th>
                        <th>Enrolled</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $student): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($student['reg_number']) ?></strong></td>
                        <td><?= htmlspecialchars($student['name']) ?></td>
                        <td><?= htmlspecialchars($student['email']) ?></td>
                        <td>
                            <select name="attendance[<?= $student['student_id'] ?>]">
                                <option value="absent">Absent</option>
                                <option value="present" selected>Present</option>
                                <option value="late">Late</option>
                            </select>
                        </td>
                        <td><?= date('M d, Y', strtotime($student['enrolled_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <button type="submit" class="btn">💾 Save Attendance</button>
        </form>
    <?php else: ?>
        <div class="no-data">
            <p>No students enrolled in this module yet.</p>
        </div>
    <?php endif; ?>

    <div class="back-link">
        <a href="dashboard.php">← Back to Dashboard</a>
    </div>
</div>

</body>
</html>