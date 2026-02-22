<?php
session_start();
include '../config/db.php';

if(!isset($_SESSION['teacher_id'])){
    header("Location: login.php");
    exit();
}

$module_id = isset($_GET['module_id']) ? (int)$_GET['module_id'] : null;

if(!$module_id){
    die("Module not specified");
}

// Get module info
$module_stmt = $conn->prepare("SELECT module_code FROM modules WHERE module_id = ? AND teacher_id = ? AND deleted = 0");
$module_stmt->bind_param("ii", $module_id, $_SESSION['teacher_id']);
$module_stmt->execute();
$module_result = $module_stmt->get_result();

if($module_result->num_rows == 0){
    die("Unauthorized");
}

$module = $module_result->fetch_assoc();
$module_stmt->close();

// Get enrolled students
$students_query = "
    SELECT s.reg_number, s.name
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

// Generate CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $module['module_code'] . '_marks_template.csv');

$output = fopen('php://output', 'w');

// Header
fputcsv($output, ['Reg_Number', 'CA_Marks', 'Final_Marks']);

// Student rows
foreach ($students as $student) {
    fputcsv($output, [$student['reg_number'], '', '']);
}

fclose($output);
exit();