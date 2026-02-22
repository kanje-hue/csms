<?php
/**
 * This script auto-enrolls a student in ALL modules of their registered course/year/semester
 * Called when admin activates a pending student in manage_students.php
 */

function auto_enroll_student($conn, $student_id) {
    // Get student's course, year, and semester
    $student_query = $conn->prepare("SELECT course_id, year, semester FROM students WHERE student_id = ? AND deleted = 0");
    $student_query->bind_param("i", $student_id);
    $student_query->execute();
    $student = $student_query->get_result()->fetch_assoc();
    $student_query->close();
    
    if (!$student) {
        return false;
    }
    
    $course_id = $student['course_id'];
    $year = $student['year'];
    $semester = $student['semester'];
    
    // Get all modules for this course/year/semester
    $modules_query = $conn->prepare("
        SELECT module_id FROM modules 
        WHERE course_id = ? AND year = ? AND semester = ? AND deleted = 0
    ");
    $modules_query->bind_param("iii", $course_id, $year, $semester);
    $modules_query->execute();
    $modules = $modules_query->get_result()->fetch_all(MYSQLI_ASSOC);
    $modules_query->close();
    
    // Enroll student in each module
    $enrollment_count = 0;
    foreach ($modules as $module) {
        $module_id = $module['module_id'];
        
        // Check if already enrolled
        $check = $conn->prepare("SELECT id FROM module_enrollments WHERE student_id = ? AND module_id = ?");
        $check->bind_param("ii", $student_id, $module_id);
        $check->execute();
        
        if ($check->get_result()->num_rows == 0) {
            // Enroll student
            $enroll = $conn->prepare("INSERT INTO module_enrollments (student_id, module_id, enrolled_at) VALUES (?, ?, NOW())");
            $enroll->bind_param("ii", $student_id, $module_id);
            if ($enroll->execute()) {
                $enrollment_count++;
            }
            $enroll->close();
        }
        $check->close();
    }
    
    return $enrollment_count;
}

?>