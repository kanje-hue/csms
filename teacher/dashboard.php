<?php
session_start();
include '../config/db.php';
require '../vendor/autoload.php'; // PhpSpreadsheet
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;

if(!isset($_SESSION['teacher_id'])){
    header("Location: login.php");
    exit();
}

$teacher_id = $_SESSION['teacher_id'];
$message = "";
$errors = [];

/* ============================
   Generate & download Excel template
============================ */
if(isset($_POST['download_template']) && isset($_POST['module_id'])){
    $module_id = (int)$_POST['module_id'];

    $students = mysqli_query($conn, "
        SELECT s.email, s.name
        FROM module_registrations mr
        JOIN students s ON mr.student_id = s.student_id
        WHERE mr.module_id = $module_id
    ");

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setCellValue('A1','student_email');
    $sheet->setCellValue('B1','marks');
    $sheet->setCellValue('C1','grade');

    $row_num = 2;
    while($s = mysqli_fetch_assoc($students)){
        $sheet->setCellValue("A$row_num", $s['email']);
        $sheet->setCellValue("B$row_num", "");
        $sheet->setCellValue("C$row_num", "");
        $row_num++;
    }

    $writer = new Xlsx($spreadsheet);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="module_'.$module_id.'_template.xlsx"');
    header('Cache-Control: max-age=0');
    $writer->save('php://output');
    exit();
}

/* ============================
   Upload Excel with results
============================ */
if(isset($_POST['upload_excel']) && isset($_FILES['excel_file'])){
    $file = $_FILES['excel_file']['tmp_name'];
    $module_id = (int)$_POST['module_id'];

    $spreadsheet = IOFactory::load($file);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray();

    for($i=1; $i<count($rows); $i++){
        $row = $rows[$i];
        $student_email = trim($row[0]);
        $marks = (int)$row[1];
        $grade = trim($row[2]);

        if(empty($student_email) || $marks < 0 || $marks > 100 || empty($grade)){
            $errors[] = "Row ".($i+1)." has invalid data.";
            continue;
        }

        $student_res = mysqli_query($conn, "SELECT student_id FROM students WHERE email='$student_email'");
        if(mysqli_num_rows($student_res) !== 1){
            $errors[] = "Row ".($i+1).": student email '$student_email' not found.";
            continue;
        }
        $student_id = mysqli_fetch_assoc($student_res)['student_id'];

        $check_reg = mysqli_query($conn, "SELECT * FROM module_registrations WHERE student_id=$student_id AND module_id=$module_id");
        if(mysqli_num_rows($check_reg) === 0){
            $errors[] = "Row ".($i+1).": student not registered in this module.";
            continue;
        }

        $check_result = mysqli_query($conn, "SELECT * FROM results WHERE student_id=$student_id AND module_id=$module_id");
        if(mysqli_num_rows($check_result) > 0){
            mysqli_query($conn, "UPDATE results SET marks=$marks, grade='$grade' WHERE student_id=$student_id AND module_id=$module_id");
        } else {
            mysqli_query($conn, "INSERT INTO results (student_id,module_id,marks,grade) VALUES ($student_id,$module_id,$marks,'$grade')");
        }
    }

    if(empty($errors)){
        $message = "Excel uploaded successfully!";
    }
}

/* ============================
   Fetch teacher's modules
============================ */
$modules = mysqli_query($conn, "
    SELECT m.module_id, m.module_code, m.module_name, c.course_name
    FROM modules m
    JOIN courses c ON m.course_id = c.course_id
    WHERE m.teacher_id=$teacher_id
    ORDER BY m.semester, m.module_name ASC
");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Teacher Dashboard</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        .auth-card.admin { width:950px; margin:50px auto; padding:25px; border-radius:18px; background:var(--white); box-shadow:0 20px 45px rgba(0,0,0,0.15); }
        h2 { text-align:center; color:var(--midnight-garden); margin-bottom:10px; }
        h3 { color:var(--midnight-garden); margin-top:20px; }

        /* Top dashboard cards like admin */
        .dashboard-container {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            justify-content: center;
            margin-bottom: 25px;
        }
        .card {
            width: 200px;
            height: 80px;
            background: linear-gradient(135deg, var(--skipping-stones), var(--minty-fresh));
            border-radius: 18px;
            box-shadow: 0 15px 30px rgba(0,0,0,0.15);
            display: flex;
            justify-content: center;
            align-items: center;
            color: var(--art-craft);
            text-decoration: none;
            font-weight: bold;
            font-size: 16px;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .card:hover { transform: scale(1.05); }

        table { width:100%; border-collapse:collapse; margin-top:10px; }
        th, td { padding:10px; border:1px solid #566947; text-align:center; }
        th { background: var(--minty-fresh); }
        tr:nth-child(even){ background:#f0f0f0; }

        .btn { padding:7px 15px; border:none; border-radius:8px; cursor:pointer; color:#fff; margin:2px; }
        .btn-upload { background: linear-gradient(135deg, var(--terra-rosa), var(--honey-glow)); }
        .btn-download { background: var(--skipping-stones); }
        a.btn-action { padding:5px 10px; border-radius:6px; color:white; text-decoration:none; margin:2px; }
        a.attendance { background:#3498db; }
        a.logout { display:block; text-align:center; margin-top:15px; color:var(--midnight-garden); font-weight:bold; text-decoration:none; }
        .message { text-align:center; color:var(--terra-rosa); margin-bottom:12px; }
    </style>
</head>
<body>
<div class="auth-card admin">
    <h2>Teacher Dashboard</h2>
    <p>Welcome, <?= $_SESSION['teacher_name'] ?? 'Teacher' ?></p>

    <?php if($message): ?>
        <div class="message"><?= $message ?></div>
    <?php endif; ?>

    <?php if(!empty($errors)): ?>
        <div class="message" style="color:red;">
            <?php foreach($errors as $e){ echo $e."<br>"; } ?>
        </div>
    <?php endif; ?>

    <!-- Top Dashboard Cards -->
    <div class="dashboard-container">
        <a href="#attendance" class="card">Manage Attendance</a>
        <a href="#results" class="card">Upload Results</a>
        <a href="change_password.php" class="card" style="background: var(--terra-rosa); color:white;">Change Password</a>
        <a href="logout.php" class="card" style="background: var(--terra-rosa); color:white;">Logout</a>
    </div>

    <!-- Attendance Section -->
    <h3 id="attendance">Manage Attendance</h3>
    <table>
        <tr>
            <th>Module Code</th>
            <th>Module Name</th>
            <th>Course</th>
            <th>Action</th>
        </tr>
        <?php mysqli_data_seek($modules, 0); 
        while($m = mysqli_fetch_assoc($modules)): ?>
        <tr>
            <td><?= $m['module_code'] ?></td>
            <td><?= $m['module_name'] ?></td>
            <td><?= $m['course_name'] ?></td>
            <td><a class="btn-action attendance" href="attendance.php?module_id=<?= $m['module_id'] ?>">Manage Attendance</a></td>
        </tr>
        <?php endwhile; ?>
    </table>

    <!-- Upload Results Section -->
    <h3 id="results">Upload Results</h3>
    <?php mysqli_data_seek($modules, 0); 
    while($m = mysqli_fetch_assoc($modules)): ?>
    <div style="margin-bottom:15px; padding:10px; border:1px solid #566947; border-radius:8px;">
        <strong><?= $m['module_code'] ?> - <?= $m['module_name'] ?></strong>
        <form method="POST" enctype="multipart/form-data" style="margin-top:10px;">
            <input type="hidden" name="module_id" value="<?= $m['module_id'] ?>">
            <input type="file" name="excel_file" accept=".xlsx,.csv" required>
            <button class="btn btn-upload" name="upload_excel">Upload Results</button>
            <button class="btn btn-download" name="download_template">Download Template</button>
        </form>
    </div>
    <?php endwhile; ?>

    <a href="logout.php" class="logout">Logout</a>
</div>
</body>
</html>
