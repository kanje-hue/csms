<?php
/**
 * teacher/upload_results.php - Upload and Manage Student Results
 * Features: CSV upload, manual entry, grade calculation, supplementary detection
 */

session_start();
require_once '../config/db.php';
require_once '../config/security_base.php';

// Check teacher login
if (!isset($_SESSION['teacher_logged_in']) || !isset($_SESSION['teacher_id'])) {
    header("Location: login.php");
    exit();
}

$teacher_id = $_SESSION['teacher_id'];
$module_id = isset($_GET['module_id']) ? (int)$_GET['module_id'] : 0;

if (!$module_id) {
    header("Location: dashboard.php");
    exit();
}

// Verify teacher owns this module
$check_stmt = $conn->prepare("
    SELECT m.*, c.course_name, c.course_id 
    FROM modules m
    JOIN courses c ON m.course_id = c.course_id
    WHERE m.module_id = ? AND m.teacher_id = ? AND m.deleted = 0
");
$check_stmt->bind_param("ii", $module_id, $teacher_id);
$check_stmt->execute();
$module_result = $check_stmt->get_result();

if ($module_result->num_rows == 0) {
    die("❌ Unauthorized: This module is not assigned to you");
}

$module = $module_result->fetch_assoc();
$check_stmt->close();

$message = "";
$message_type = "";
$csrf_token = generateCSRF();

// Handle CSV Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_csv') {
    validateCSRF($_POST['csrf_token'] ?? '');
    
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $message = "Error uploading file";
        $message_type = "error";
    } else {
        $file = $_FILES['csv_file'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if ($file_ext !== 'csv') {
            $message = "Only CSV files are allowed";
            $message_type = "error";
        } else {
            $handle = fopen($file['tmp_name'], 'r');
            $row_count = 0;
            $success_count = 0;
            $errors = [];
            
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                $row_count++;
                
                // Skip header row
                if ($row_count === 1 && strtolower($data[0]) === 'reg_number') {
                    continue;
                }
                
                if (count($data) < 3) {
                    $errors[] = "Row $row_count: Invalid format";
                    continue;
                }
                
                $reg_number = trim($data[0]);
                $ca_marks = floatval($data[1]);
                $final_marks = floatval($data[2]);
                
                // Validate marks
                if ($ca_marks < 0 || $ca_marks > 60) {
                    $errors[] = "Row $row_count: CA marks must be between 0-60";
                    continue;
                }
                if ($final_marks < 0 || $final_marks > 40) {
                    $errors[] = "Row $row_count: Final marks must be between 0-40";
                    continue;
                }
                
                // Get student ID
                $student_stmt = $conn->prepare("
                    SELECT s.student_id 
                    FROM students s
                    JOIN module_enrollments me ON s.student_id = me.student_id
                    WHERE s.reg_number = ? AND me.module_id = ? AND s.deleted = 0
                ");
                $student_stmt->bind_param("si", $reg_number, $module_id);
                $student_stmt->execute();
                $student_result = $student_stmt->get_result();
                
                if ($student_result->num_rows > 0) {
                    $student = $student_result->fetch_assoc();
                    $student_id = $student['student_id'];
                    $total_marks = $ca_marks + $final_marks;
                    
                    // Determine grade based on total marks
                    if ($total_marks >= 70) $grade = 'A';
                    elseif ($total_marks >= 60) $grade = 'B';
                    elseif ($total_marks >= 50) $grade = 'C';
                    elseif ($total_marks >= 40) $grade = 'D';
                    else $grade = 'F';
                    
                    // Insert or update result
                    $insert_stmt = $conn->prepare("
                        INSERT INTO results (student_id, module_id, ca_marks, final_marks, total_marks, grade, status)
                        VALUES (?, ?, ?, ?, ?, ?, 'draft')
                        ON DUPLICATE KEY UPDATE 
                            ca_marks = VALUES(ca_marks),
                            final_marks = VALUES(final_marks),
                            total_marks = VALUES(total_marks),
                            grade = VALUES(grade),
                            status = 'draft'
                    ");
                    $insert_stmt->bind_param("iiddds", $student_id, $module_id, $ca_marks, $final_marks, $total_marks, $grade);
                    
                    if ($insert_stmt->execute()) {
                        $success_count++;
                    } else {
                        $errors[] = "Row $row_count: Database error";
                    }
                    $insert_stmt->close();
                } else {
                    $errors[] = "Row $row_count: Student $reg_number not found in this module";
                }
                $student_stmt->close();
            }
            
            fclose($handle);
            
            if ($success_count > 0) {
                $message = "✓ Successfully uploaded $success_count student results!";
                if (!empty($errors)) {
                    $message .= " (" . count($errors) . " errors)";
                }
                $message_type = "success";
            } else {
                $message = "No results were uploaded. Please check your CSV format.";
                $message_type = "error";
            }
        }
    }
}

// Handle Manual Save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_marks') {
    validateCSRF($_POST['csrf_token'] ?? '');
    
    $student_id = (int)($_POST['student_id'] ?? 0);
    $ca_marks = floatval($_POST['ca_marks'] ?? 0);
    $final_marks = floatval($_POST['final_marks'] ?? 0);
    
    if ($student_id <= 0) {
        $message = "Invalid student selection";
        $message_type = "error";
    } elseif ($ca_marks < 0 || $ca_marks > 60) {
        $message = "CA marks must be between 0-60";
        $message_type = "error";
    } elseif ($final_marks < 0 || $final_marks > 40) {
        $message = "Final marks must be between 0-40";
        $message_type = "error";
    } else {
        $total_marks = $ca_marks + $final_marks;
        
        // Determine grade
        if ($total_marks >= 70) $grade = 'A';
        elseif ($total_marks >= 60) $grade = 'B';
        elseif ($total_marks >= 50) $grade = 'C';
        elseif ($total_marks >= 40) $grade = 'D';
        else $grade = 'F';
        
        // Insert or update
        $stmt = $conn->prepare("
            INSERT INTO results (student_id, module_id, ca_marks, final_marks, total_marks, grade, status)
            VALUES (?, ?, ?, ?, ?, ?, 'draft')
            ON DUPLICATE KEY UPDATE 
                ca_marks = VALUES(ca_marks),
                final_marks = VALUES(final_marks),
                total_marks = VALUES(total_marks),
                grade = VALUES(grade)
        ");
        $stmt->bind_param("iiddds", $student_id, $module_id, $ca_marks, $final_marks, $total_marks, $grade);
        
        if ($stmt->execute()) {
            $message = "✓ Marks saved successfully!";
            $message_type = "success";
        } else {
            $message = "Error saving marks";
            $message_type = "error";
        }
        $stmt->close();
    }
}

// Get enrolled students with their results
$students_query = "
    SELECT 
        s.student_id,
        s.reg_number,
        s.name,
        s.email,
        r.ca_marks,
        r.final_marks,
        r.total_marks,
        r.grade,
        r.status
    FROM module_enrollments me
    INNER JOIN students s ON me.student_id = s.student_id
    LEFT JOIN results r ON r.student_id = s.student_id AND r.module_id = ?
    WHERE me.module_id = ? AND s.deleted = 0 AND s.status = 'active'
    ORDER BY s.name ASC
";

$students_stmt = $conn->prepare($students_query);
$students_stmt->bind_param("ii", $module_id, $module_id);
$students_stmt->execute();
$students = $students_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$students_stmt->close();

// Calculate statistics
$total_students = count($students);
$students_with_results = count(array_filter($students, fn($s) => $s['ca_marks'] !== null));
$average_score = $students_with_results > 0 
    ? array_sum(array_column(array_filter($students, fn($s) => $s['total_marks'] !== null), 'total_marks')) / $students_with_results 
    : 0;

// Function to check if student needs supplementary
function needsSupplementary($ca, $final, $total) {
    if ($ca === null || $final === null) return false;
    $ca_half = 30; // Half of 60
    $final_half = 20; // Half of 40
    $total_half = 50; // Half of 100
    
    return ($ca < $ca_half || $final < $final_half || $total < $total_half);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Results - <?= htmlspecialchars($module['module_code']) ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
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
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 100;
            width: 100%;
        }

        .header h1 {
            font-size: 1.8rem;
            background: linear-gradient(135deg, #2dd4bf, #14b8a6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .header a {
            color: white;
            text-decoration: none;
            padding: 0.5rem 1.2rem;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.1);
            transition: all 0.3s;
        }

        .header a:hover {
            background: #2dd4bf;
        }

        .container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 2rem;
        }

        .module-info {
            background: linear-gradient(135deg, #0f172a, #1e293b);
            color: white;
            padding: 2rem;
            border-radius: 20px;
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .module-details h2 {
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }

        .module-details p {
            color: #94a3b8;
        }

        .module-stats {
            text-align: right;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: #2dd4bf;
        }

        .alert {
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
        }

        .alert.success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }

        .alert.error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
        }

        .tabs {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            border-bottom: 2px solid #e2e8f0;
        }

        .tab-btn {
            padding: 0.8rem 1.5rem;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 600;
            color: #64748b;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
        }

        .tab-btn.active {
            color: #2dd4bf;
            border-bottom-color: #2dd4bf;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .upload-card {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #0f172a;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 0.8rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #2dd4bf;
            box-shadow: 0 0 0 3px rgba(45, 212, 191, 0.1);
        }

        .btn {
            padding: 0.8rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary {
            background: #2dd4bf;
            color: white;
        }

        .btn-primary:hover {
            background: #14b8a6;
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: #e2e8f0;
            color: #0f172a;
        }

        .btn-secondary:hover {
            background: #cbd5e1;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        th {
            background: #f8fafc;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: #0f172a;
            border-bottom: 2px solid #e2e8f0;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }

        tr:hover {
            background: #f8fafc;
        }

        .grade-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-weight: 600;
        }

        .grade-A { background: #d1fae5; color: #065f46; }
        .grade-B { background: #dbeafe; color: #1e40af; }
        .grade-C { background: #fef3c7; color: #92400e; }
        .grade-D { background: #fee2e2; color: #991b1b; }
        .grade-F { background: #fee2e2; color: #991b1b; }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
        }

        .status-draft {
            background: #fef3c7;
            color: #92400e;
        }

        .status-published {
            background: #d1fae5;
            color: #065f46;
        }

        .supplementary {
            background: #fee2e2;
            color: #991b1b;
            font-weight: 600;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
        }

        .marks-input {
            display: flex;
            gap: 0.5rem;
        }

        .marks-input input {
            width: 80px;
            padding: 0.4rem;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
        }

        .back-link {
            margin-top: 2rem;
            text-align: center;
        }

        .back-link a {
            color: #2dd4bf;
            text-decoration: none;
            font-weight: 600;
        }

        @media (max-width: 768px) {
            .container {
                padding: 0 1rem;
            }

            .module-info {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
            }

            .tabs {
                flex-direction: column;
            }

            table {
                font-size: 0.9rem;
            }

            td, th {
                padding: 0.5rem;
            }
        }
    </style>
</head>
<body>

<div class="header">
    <h1>CSMS Teacher</h1>
    <a href="logout.php">🚪 Logout</a>
</div>

<div class="container">
    <!-- Module Info -->
    <div class="module-info">
        <div class="module-details">
            <h2><?= htmlspecialchars($module['module_code']) ?> - <?= htmlspecialchars($module['module_name']) ?></h2>
            <p><?= htmlspecialchars($module['course_name']) ?> | Year <?= $module['year'] ?> | Semester <?= $module['semester'] ?></p>
        </div>
        <div class="module-stats">
            <div class="stat-value"><?= $total_students ?></div>
            <div>Enrolled Students</div>
            <div class="stat-value" style="font-size: 1.2rem;">Avg: <?= round($average_score, 1) ?></div>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert <?= $message_type ?>"><?= $message ?></div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="tabs">
        <button class="tab-btn active" onclick="switchTab(event, 'upload')">📤 CSV Upload</button>
        <button class="tab-btn" onclick="switchTab(event, 'manual')">✏️ Manual Entry</button>
        <button class="tab-btn" onclick="switchTab(event, 'review')">👁️ Review Results</button>
    </div>

    <!-- CSV Upload Tab -->
    <div id="upload" class="tab-content active">
        <div class="upload-card">
            <h3 style="margin-bottom: 1.5rem;">📤 Upload Results via CSV</h3>

            <div style="background: #f8fafc; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem;">
                <p><strong>CSV Format:</strong></p>
                <pre style="background: white; padding: 1rem; border-radius: 8px; margin-top: 0.5rem;">
Reg_Number,CA_Marks,Final_Marks
STU001,45,35
STU002,52,38
STU003,38,32</pre>
                <p style="margin-top: 0.5rem; color: #64748b;">CA Marks: 0-60 | Final Marks: 0-40</p>
            </div>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="action" value="upload_csv">

                <div class="form-group">
                    <label for="csv_file">Choose CSV File</label>
                    <input type="file" id="csv_file" name="csv_file" accept=".csv" required>
                </div>

                <button type="submit" class="btn btn-primary">📤 Upload and Process</button>
                <a href="download_template.php?module_id=<?= $module_id ?>" class="btn btn-secondary" style="margin-left: 1rem;">📥 Download Template</a>
            </form>
        </div>
    </div>

    <!-- Manual Entry Tab -->
    <div id="manual" class="tab-content">
        <div class="upload-card">
            <h3 style="margin-bottom: 1.5rem;">✏️ Enter Marks Manually</h3>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="action" value="save_marks">

                <div class="form-group">
                    <label for="student_id">Select Student</label>
                    <select id="student_id" name="student_id" required>
                        <option value="">-- Choose Student --</option>
                        <?php foreach ($students as $student): ?>
                        <option value="<?= $student['student_id'] ?>">
                            <?= htmlspecialchars($student['reg_number']) ?> - <?= htmlspecialchars($student['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem;">
                    <div class="form-group">
                        <label for="ca_marks">CA Marks (0-60)</label>
                        <input type="number" id="ca_marks" name="ca_marks" min="0" max="60" step="0.5" required>
                    </div>
                    <div class="form-group">
                        <label for="final_marks">Final Marks (0-40)</label>
                        <input type="number" id="final_marks" name="final_marks" min="0" max="40" step="0.5" required>
                    </div>
                    <div class="form-group">
                        <label>Total (Auto)</label>
                        <input type="number" id="total_display" disabled style="background: #f8fafc;">
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">💾 Save Marks</button>
            </form>
        </div>
    </div>

    <!-- Review Results Tab -->
    <div id="review" class="tab-content">
        <div class="upload-card">
            <h3 style="margin-bottom: 1.5rem;">👁️ Student Results</h3>

            <?php if (count($students) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Reg Number</th>
                        <th>Student Name</th>
                        <th>CA (0-60)</th>
                        <th>Final (0-40)</th>
                        <th>Total</th>
                        <th>Grade</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $student): 
                        $ca = $student['ca_marks'];
                        $final = $student['final_marks'];
                        $total = $student['total_marks'];
                        $grade = $student['grade'];
                        $needs_supp = ($ca !== null && $final !== null) ? needsSupplementary($ca, $final, $total) : false;
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($student['reg_number']) ?></strong></td>
                        <td><?= htmlspecialchars($student['name']) ?></td>
                        <td><?= $ca !== null ? number_format($ca, 1) : '-' ?></td>
                        <td><?= $final !== null ? number_format($final, 1) : '-' ?></td>
                        <td><strong><?= $total !== null ? number_format($total, 1) : '-' ?></strong></td>
                        <td>
                            <?php if ($grade): ?>
                                <span class="grade-badge grade-<?= $grade ?>"><?= $grade ?></span>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($student['status']): ?>
                                <span class="status-badge status-<?= $student['status'] ?>">
                                    <?= ucfirst($student['status']) ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($needs_supp): ?>
                                <span class="supplementary">⚠️ Supplementary</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <p style="text-align: center; color: #64748b; padding: 2rem;">No students enrolled in this module</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="back-link">
        <a href="dashboard.php">← Back to Dashboard</a>
    </div>
</div>

<script>
function switchTab(event, tabId) {
    // Hide all tabs
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Remove active from all buttons
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    
    // Show selected tab
    document.getElementById(tabId).classList.add('active');
    event.target.classList.add('active');
}

// Auto-calculate total
document.addEventListener('DOMContentLoaded', function() {
    const caInput = document.getElementById('ca_marks');
    const finalInput = document.getElementById('final_marks');
    const totalDisplay = document.getElementById('total_display');
    
    if (caInput && finalInput && totalDisplay) {
        function updateTotal() {
            const ca = parseFloat(caInput.value) || 0;
            const final = parseFloat(finalInput.value) || 0;
            totalDisplay.value = (ca + final).toFixed(1);
        }
        
        caInput.addEventListener('input', updateTotal);
        finalInput.addEventListener('input', updateTotal);
    }
});
</script>

</body>
</html>