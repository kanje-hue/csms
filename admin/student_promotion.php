<?php
/**
 * admin/student_promotion.php - Academic Year-End Promotion
 * Admin-controlled student progression to next year
 */

session_start();
require_once '../config/db.php';
require_once '../config/security_base.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}

$message = "";
$message_type = "";
$csrf_token = generateCSRF();

// Get all courses
$courses = $conn->query("SELECT course_id, course_name FROM courses WHERE deleted = 0 ORDER BY course_name");

$selected_course = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : 1;

// Get students for selected course/year
$students = [];
$promotion_summary = [];

if ($selected_course > 0) {
    // Get all active students in this course/year
    $student_query = "
        SELECT 
            s.student_id,
            s.reg_number,
            s.name,
            s.year,
            s.semester,
            COUNT(DISTINCT r.id) as results_count,
            SUM(CASE WHEN r.grade IN ('F', 'D') THEN 1 ELSE 0 END) as failed_modules,
            AVG(CASE 
                WHEN r.grade = 'A' THEN 4.0
                WHEN r.grade = 'B' THEN 3.0
                WHEN r.grade = 'C' THEN 2.0
                WHEN r.grade = 'D' THEN 1.0
                WHEN r.grade = 'F' THEN 0.0
                ELSE NULL END) as gpa
        FROM students s
        LEFT JOIN results r ON s.student_id = r.student_id
        WHERE s.course_id = ? AND s.year = ? AND s.status = 'active' AND s.deleted = 0
        GROUP BY s.student_id, s.reg_number, s.name, s.year, s.semester
        ORDER BY s.name ASC
    ";
    
    $stmt = $conn->prepare($student_query);
    $stmt->bind_param("ii", $selected_course, $selected_year);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Calculate promotion summary
    $total_students = count($students);
    $eligible_count = 0;
    $failed_count = 0;
    
    foreach ($students as $s) {
        // Promotion criteria: No failed modules AND GPA >= 2.0
        if ($s['failed_modules'] == 0 && ($s['gpa'] >= 2.0 || $s['gpa'] === null)) {
            $eligible_count++;
        } else {
            $failed_count++;
        }
    }
    
    $promotion_summary = [
        'total' => $total_students,
        'eligible' => $eligible_count,
        'failed' => $failed_count
    ];
}

// Handle promotion action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['promote'])) {
    validateCSRF($_POST['csrf_token'] ?? '');
    
    $student_ids = $_POST['student_ids'] ?? [];
    $target_year = $selected_year + 1;
    $target_semester = 1; // Start at semester 1 of new year
    
    if ($target_year > 3) {
        $message = "Students have completed the program. They should be graduated, not promoted.";
        $message_type = "error";
    } elseif (empty($student_ids)) {
        $message = "No students selected for promotion.";
        $message_type = "error";
    } else {
        $promoted = 0;
        $errors = 0;
        
        foreach ($student_ids as $student_id) {
            $student_id = (int)$student_id;
            
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // 1. Update student year
                $update = $conn->prepare("UPDATE students SET year = ?, semester = 1 WHERE student_id = ?");
                $update->bind_param("ii", $target_year, $student_id);
                $update->execute();
                
                // 2. Get modules for new year
                $modules = $conn->prepare("
                    SELECT module_id FROM modules 
                    WHERE course_id = ? AND year = ? AND semester = 1 AND deleted = 0
                ");
                $modules->bind_param("ii", $selected_course, $target_year);
                $modules->execute();
                $module_list = $modules->get_result()->fetch_all(MYSQLI_ASSOC);
                $modules->close();
                
                // 3. Enroll in new modules
                foreach ($module_list as $module) {
                    $enroll = $conn->prepare("
                        INSERT IGNORE INTO module_enrollments (student_id, module_id, enrolled_at)
                        VALUES (?, ?, NOW())
                    ");
                    $enroll->bind_param("ii", $student_id, $module['module_id']);
                    $enroll->execute();
                    $enroll->close();
                }
                
                // 4. Create notification for student
                $notif_msg = "Congratulations! You have been promoted to Year $target_year.";
                $notif = $conn->prepare("
                    INSERT INTO notifications (user_type, user_id, type, message, created_at)
                    VALUES ('student', ?, 'promotion', ?, NOW())
                ");
                $notif->bind_param("is", $student_id, $notif_msg);
                $notif->execute();
                $notif->close();
                
                $conn->commit();
                $promoted++;
                
            } catch (Exception $e) {
                $conn->rollback();
                $errors++;
                error_log("Promotion error for student $student_id: " . $e->getMessage());
            }
        }
        
        logAdminAction($conn, $_SESSION['admin_id'], 'bulk_promotion', 
            "Promoted $promoted students to Year $target_year");
        
        $message = "✓ Successfully promoted $promoted students to Year $target_year.";
        if ($errors > 0) {
            $message .= " ($errors students failed)";
        }
        $message_type = "success";
        
        // Refresh page
        header("Location: student_promotion.php?course_id=$selected_course&year=$selected_year&success=1");
        exit();
    }
}

// Get available years for promotion
$years = [1, 2];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Student Promotion - CSMS Admin</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f0f2f5;
            color: #1e293b;
        }
        .header {
            background: linear-gradient(135deg, #0f172a, #1e293b);
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 2rem;
        }
        .breadcrumb {
            background: white;
            padding: 1rem 1.5rem;
            border-radius: 12px;
            margin-bottom: 2rem;
        }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }
        .filter-section {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 2rem;
        }
        .filter-form {
            display: flex;
            gap: 1rem;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        .form-group {
            flex: 1;
            min-width: 200px;
        }
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        .form-group select {
            width: 100%;
            padding: 0.8rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
        }
        .btn {
            padding: 0.8rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary {
            background: #2dd4bf;
            color: white;
        }
        .btn-success {
            background: #10b981;
            color: white;
        }
        .summary-card {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
        }
        .summary-item {
            text-align: center;
            padding: 1rem;
            border-radius: 8px;
        }
        .summary-item.total { background: #e2e8f0; }
        .summary-item.eligible { background: #d1fae5; }
        .summary-item.failed { background: #fee2e2; }
        .summary-number {
            font-size: 2rem;
            font-weight: 700;
        }
        table {
            width: 100%;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
        }
        th {
            background: #f8fafc;
            padding: 1rem;
            text-align: left;
        }
        td {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }
        .badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .badge-eligible {
            background: #d1fae5;
            color: #065f46;
        }
        .badge-not-eligible {
            background: #fee2e2;
            color: #991b1b;
        }
        .action-buttons {
            margin-top: 2rem;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>CSMS Admin - Student Promotion</h1>
        <a href="dashboard.php" style="color:white;">← Back</a>
    </div>
    
    <div class="container">
        <div class="breadcrumb">
            <a href="dashboard.php">Dashboard</a> > <strong>Student Promotion</strong>
        </div>
        
        <div class="page-header">
            <h1>📈 Academic Year-End Promotion</h1>
            <p>Promote eligible students to the next academic year</p>
        </div>
        
        <!-- Filter Section -->
        <div class="filter-section">
            <form method="GET" class="filter-form">
                <div class="form-group">
                    <label>Select Course</label>
                    <select name="course_id" required>
                        <option value="">-- Choose Course --</option>
                        <?php while ($course = $courses->fetch_assoc()): ?>
                        <option value="<?= $course['course_id'] ?>" <?= $selected_course == $course['course_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($course['course_name']) ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Current Year</label>
                    <select name="year" required>
                        <option value="1" <?= $selected_year == 1 ? 'selected' : '' ?>>Year 1 → Year 2</option>
                        <option value="2" <?= $selected_year == 2 ? 'selected' : '' ?>>Year 2 → Year 3</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Load Students</button>
            </form>
        </div>
        
        <?php if (!empty($promotion_summary) && $selected_course > 0): ?>
        
        <!-- Promotion Summary -->
        <div class="summary-card">
            <div class="summary-item total">
                <div class="summary-number"><?= $promotion_summary['total'] ?></div>
                <div>Total Students</div>
            </div>
            <div class="summary-item eligible">
                <div class="summary-number"><?= $promotion_summary['eligible'] ?></div>
                <div>Eligible for Promotion</div>
            </div>
            <div class="summary-item failed">
                <div class="summary-number"><?= $promotion_summary['failed'] ?></div>
                <div>Need Review</div>
            </div>
        </div>
        
        <!-- Promotion Form -->
        <form method="POST" id="promotionForm">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="promote" value="1">
            
            <div style="margin-bottom: 1rem;">
                <button type="button" onclick="selectAllEligible()" class="btn btn-primary">Select All Eligible</button>
                <button type="button" onclick="deselectAll()" class="btn btn-primary">Deselect All</button>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th style="width: 40px;">
                            <input type="checkbox" id="selectAll" onchange="toggleAll(this)">
                        </th>
                        <th>Reg Number</th>
                        <th>Student Name</th>
                        <th>Current Year</th>
                        <th>Failed Modules</th>
                        <th>GPA</th>
                        <th>Eligibility</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $student): 
                        $eligible = ($student['failed_modules'] == 0 && ($student['gpa'] >= 2.0 || $student['gpa'] === null));
                    ?>
                    <tr>
                        <td>
                            <input type="checkbox" name="student_ids[]" value="<?= $student['student_id'] ?>" 
                                   class="student-checkbox <?= $eligible ? 'eligible-checkbox' : '' ?>"
                                   <?= $eligible ? '' : 'disabled' ?>>
                        </td>
                        <td><strong><?= htmlspecialchars($student['reg_number']) ?></strong></td>
                        <td><?= htmlspecialchars($student['name']) ?></td>
                        <td>Year <?= $student['year'] ?></td>
                        <td><?= $student['failed_modules'] ?></td>
                        <td><?= $student['gpa'] ? number_format($student['gpa'], 2) : 'N/A' ?></td>
                        <td>
                            <span class="badge <?= $eligible ? 'badge-eligible' : 'badge-not-eligible' ?>">
                                <?= $eligible ? '✓ Eligible' : '✗ Not Eligible' ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div class="action-buttons">
                <button type="submit" class="btn btn-success" style="padding: 1rem 3rem;"
                        onclick="return confirm('Promote selected students to Year <?= $selected_year + 1 ?>? This action cannot be undone.')">
                    🚀 Promote Selected Students to Year <?= $selected_year + 1 ?>
                </button>
            </div>
        </form>
        <?php endif; ?>
    </div>
    
    <script>
        function toggleAll(source) {
            const checkboxes = document.querySelectorAll('.student-checkbox:not(:disabled)');
            checkboxes.forEach(cb => cb.checked = source.checked);
        }
        
        function selectAllEligible() {
            const checkboxes = document.querySelectorAll('.student-checkbox:not(:disabled)');
            checkboxes.forEach(cb => cb.checked = true);
            document.getElementById('selectAll').checked = true;
        }
        
        function deselectAll() {
            const checkboxes = document.querySelectorAll('.student-checkbox');
            checkboxes.forEach(cb => cb.checked = false);
            document.getElementById('selectAll').checked = false;
        }
    </script>
</body>
</html>