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

// =====================
// Generate & download Excel template
// =====================
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

// =====================
// Upload Excel with results
// =====================
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

// =====================
// Fetch teacher's modules
// =====================
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
</head>
<body>
<div class="auth-card" style="width: 900px;">
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

    <h3>Your Modules</h3>
    <table>
        <tr>
            <th>Code</th>
            <th>Name</th>
            <th>Course</th>
            <th>Attendance</th>
            <th>Upload Results</th>
        </tr>
        <?php while($m = mysqli_fetch_assoc($modules)): ?>
        <tr>
            <td><?= $m['module_code'] ?></td>
            <td><?= $m['module_name'] ?></td>
            <td><?= $m['course_name'] ?></td>
            <td><a href="attendance.php?module_id=<?= $m['module_id'] ?>">Manage</a></td>
            <td>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="module_id" value="<?= $m['module_id'] ?>">
                    <input type="file" name="excel_file" accept=".xlsx,.csv" required>
                    <button class="btn" name="upload_excel">Upload</button>
                    <button class="btn" name="download_template">Download Template</button>
                </form>
            </td>
        </tr>
        <?php endwhile; ?>
    </table>

    <div class="auth-links">
        <a href="logout.php">Logout</a>
    </div>
</div>
</body>
</html>
