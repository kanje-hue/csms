<?php
session_start();
include '../config/db.php';

if(!isset($_SESSION['admin_logged_in'])){
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$module_id = isset($_GET['module_id']) ? (int)$_GET['module_id'] : null;
$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : null;

if(!$module_id || !$course_id){
    http_response_code(400);
    echo json_encode(['error' => 'Invalid parameters']);
    exit();
}

// Fetch results for the module
$query = "
    SELECT 
        s.reg_number,
        s.name,
        r.ca_marks,
        r.final_marks,
        r.grade
    FROM results r
    INNER JOIN students s ON r.student_id = s.student_id
    WHERE r.module_id = ? 
    AND s.course_id = ?
    AND s.deleted = 0
    ORDER BY s.name ASC
";

$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $module_id, $course_id);
$stmt->execute();
$results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

header('Content-Type: application/json');
echo json_encode($results);
?>