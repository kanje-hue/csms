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
$check = $conn->prepare("SELECT m.module_id, m.module_code, m.module_name, c.course_name, m.year, m.semester 
                         FROM modules m
                         LEFT JOIN courses c ON m.course_id = c.course_id
                         WHERE m.module_id = ? AND m.teacher_id = ? AND m.deleted = 0");
$check->bind_param("ii", $module_id, $teacher_id);
$check->execute();
$module_result = $check->get_result();

if($module_result->num_rows == 0){
    die("Unauthorized: This module is not assigned to you");
}

$module = $module_result->fetch_assoc();
$check->close();

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
$students_result = $students_stmt->get_result();
$students = $students_result->fetch_all(MYSQLI_ASSOC);
$students_stmt->close();

?>

<!DOCTYPE html>
<html>
<head>
    <title>Module Students</title>
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

        .no-data {
            text-align: center;
            padding: 40px;
            color: #666;
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

    <div class="student-count">
        Total Enrolled: <strong><?= count($students) ?></strong> Student(s)
    </div>

    <?php if (count($students) > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>Reg Number</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Enrolled Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($students as $student): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($student['reg_number']) ?></strong></td>
                    <td><?= htmlspecialchars($student['name']) ?></td>
                    <td><?= htmlspecialchars($student['email']) ?></td>
                    <td><?= date('M d, Y', strtotime($student['enrolled_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="no-data">
            <p>❌ No students enrolled in this module yet.</p>
        </div>
    <?php endif; ?>

    <div class="back-link">
        <a href="dashboard.php">← Back to Dashboard</a>
    </div>
</div>

</body>
</html>