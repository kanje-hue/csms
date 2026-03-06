<?php
/**
 * teacher/download_attendance_template.php - Download CSV Template for Attendance
 */

session_start();
require_once '../config/db.php';

if(!isset($_SESSION['teacher_id'])){
    header("Location: login.php");
    exit();
}

$module_id = isset($_GET['module_id']) ? (int)$_GET['module_id'] : null;

if(!$module_id){
    die("Module not specified");
}

// Verify teacher owns this module
$check_stmt = $conn->prepare("
    SELECT m.module_code, m.module_name, c.course_name 
    FROM modules m
    JOIN courses c ON m.course_id = c.course_id
    WHERE m.module_id = ? AND m.teacher_id = ? AND m.deleted = 0
");
$check_stmt->bind_param("ii", $module_id, $_SESSION['teacher_id']);
$check_stmt->execute();
$module_result = $check_stmt->get_result();

if($module_result->num_rows == 0){
    die("❌ Unauthorized");
}

$module = $module_result->fetch_assoc();
$check_stmt->close();

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
header('Content-Disposition: attachment; filename=' . $module['module_code'] . '_attendance_template.csv');

$output = fopen('php://output', 'w');

// Header with instructions
fputcsv($output, ['# Attendance Template for ' . $module['module_code']]);
fputcsv($output, ['# Format: Reg_Number,Attended_Classes,Total_Classes']);
fputcsv($output, ['# Students with ≥60% attendance will be marked eligible']);
fputcsv($output, []);

// Column headers
fputcsv($output, ['Reg_Number', 'Attended_Classes', 'Total_Classes']);

// Sample row
fputcsv($output, ['Example: STU001', '25', '30']);

// Empty rows for each student
foreach ($students as $student) {
    fputcsv($output, [$student['reg_number'], '', '']);
}

fclose($output);
exit();
?>