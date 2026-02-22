<?php
session_start();
include '../config/db.php';

if(!isset($_SESSION['admin_logged_in'])){
    header("Location: login.php");
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = "";
$message_type = "";

// Auto-activate ALL pending students
if(isset($_POST['auto_activate'])){
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = "Security token verification failed";
        $message_type = "error";
    } else {
        $stmt = $conn->prepare("UPDATE students SET status = 'active' WHERE status = 'pending' AND deleted = 0");
        
        if($stmt->execute()){
            $affected = $stmt->affected_rows;
            $message = "✓ $affected pending student(s) activated successfully! They can now login.";
            $message_type = "success";
        } else {
            $message = "Error activating students";
            $message_type = "error";
        }
        $stmt->close();
    }
}

// Count pending students
$count_stmt = $conn->prepare("SELECT COUNT(*) as pending FROM students WHERE status = 'pending' AND deleted = 0");
$count_stmt->execute();
$pending_count = $count_stmt->get_result()->fetch_assoc()['pending'];
$count_stmt->close();

// Get all pending students
$query = "
    SELECT 
        s.student_id,
        s.reg_number,
        s.name,
        s.email,
        s.status,
        s.year,
        s.semester,
        c.course_name,
        s.created_at
    FROM students s
    LEFT JOIN courses c ON s.course_id = c.course_id
    WHERE s.status = 'pending' AND s.deleted = 0
    ORDER BY s.created_at DESC
";

$stmt = $conn->prepare($query);
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

?>

<!DOCTYPE html>
<html>
<head>
    <title>Auto-Activate Students</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        .auth-card {
            width: 900px;
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

        .alert {
            padding: 15px;
            margin: 15px 0;
            border-radius: 8px;
            display: none;
            font-weight: bold;
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

        .stats-box {
            background: linear-gradient(135deg, #cce5ff, #e3f2fd);
            padding: 25px;
            border-radius: 10px;
            text-align: center;
            margin: 20px 0;
        }

        .stats-number {
            font-size: 40px;
            font-weight: bold;
            color: #004085;
            margin: 10px 0;
        }

        .stats-label {
            color: #004085;
            font-size: 16px;
        }

        .btn-activate {
            display: inline-block;
            padding: 12px 30px;
            background: linear-gradient(135deg, #4CAF50, #45a049);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
            margin: 10px 5px;
        }

        .btn-activate:hover {
            opacity: 0.9;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        th {
            background: var(--minty-fresh);
            color: var(--art-craft);
            font-weight: bold;
        }

        tr:hover {
            background: #f9f9f9;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
            background: #cce5ff;
            color: #004085;
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
            background: #f9f9f9;
            border-radius: 10px;
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
    <h2>⚡ Auto-Activate Pending Students</h2>

    <?php if ($message): ?>
        <div class="alert <?= $message_type === 'success' ? 'success' : 'error' ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Statistics -->
    <div class="stats-box">
        <div class="stats-label">Pending Registrations</div>
        <div class="stats-number"><?= $pending_count ?></div>
        <?php if ($pending_count > 0): ?>
            <form method="POST" style="margin-top: 20px;">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <button type="submit" name="auto_activate" class="btn-activate">
                    ✓ Activate All <?= $pending_count ?> Student(s)
                </button>
            </form>
        <?php else: ?>
            <p style="color: #004085; margin-top: 15px;">No pending students!</p>
        <?php endif; ?>
    </div>

    <!-- Pending Students List -->
    <?php if(count($students) > 0): ?>
        <h3 style="margin-top: 30px; color: var(--midnight-garden);">📋 Pending Students</h3>
        <table>
            <thead>
                <tr>
                    <th>Reg Number</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Course</th>
                    <th>Year</th>
                    <th>Semester</th>
                    <th>Registered</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($students as $student): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($student['reg_number']) ?></strong></td>
                    <td><?= htmlspecialchars($student['name']) ?></td>
                    <td><?= htmlspecialchars($student['email']) ?></td>
                    <td><?= htmlspecialchars($student['course_name'] ?? 'N/A') ?></td>
                    <td><?= $student['year'] ?></td>
                    <td><?= $student['semester'] ?></td>
                    <td><?= date('M d, Y', strtotime($student['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="no-data">
            <p>No pending students! ✓ All students are activated.</p>
        </div>
    <?php endif; ?>

    <div class="back-link">
        <a href="dashboard.php">← Back to Dashboard</a>
    </div>
</div>

</body>
</html>