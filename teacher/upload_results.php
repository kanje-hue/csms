<?php
session_start();
include '../config/db.php';

if(!isset($_SESSION['teacher_id'])){
    header("Location: login.php");
    exit();
}

$teacher_id = $_SESSION['teacher_id'];
$module_id = isset($_GET['module_id']) ? (int)$_GET['module_id'] : null;

if(!$module_id){
    die("Module not specified");
}

// Verify teacher owns module
$check = $conn->prepare("SELECT m.module_id, m.module_code, m.module_name, c.course_id, c.course_name, m.year, m.semester 
                         FROM modules m
                         LEFT JOIN courses c ON m.course_id = c.course_id
                         WHERE m.module_id = ? AND m.teacher_id = ? AND m.deleted = 0");
$check->bind_param("ii", $module_id, $teacher_id);
$check->execute();
$module_result = $check->get_result();

if($module_result->num_rows == 0){
    die("❌ Unauthorized");
}

$module = $module_result->fetch_assoc();
$check->close();

$course_id = $module['course_id'];
$message = "";
$message_type = "";

// Handle CSV upload
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_csv'){
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = "Security token verification failed";
        $message_type = "error";
    } elseif(!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK){
        $message = "Error uploading file";
        $message_type = "error";
    } else {
        $file = $_FILES['csv_file'];
        
        // Validate file type
        if($file['type'] !== 'text/csv' && $file['type'] !== 'text/plain'){
            $message = "Only CSV files are allowed";
            $message_type = "error";
        } else {
            $handle = fopen($file['tmp_name'], 'r');
            $row_count = 0;
            $success_count = 0;
            $error_details = [];
            
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                $row_count++;
                
                // Skip header
                if($row_count === 1) continue;
                
                if(count($data) < 3) continue;
                
                $reg_number = trim($data[0]);
                $ca_marks = floatval(trim($data[1]));
                $final_marks = floatval(trim($data[2]));
                
                // Validate marks
                if($ca_marks < 0 || $ca_marks > 60 || $final_marks < 0 || $final_marks > 40){
                    $error_details[] = "Row $row_count: Invalid marks (CA: 0-60, Final: 0-40)";
                    continue;
                }
                
                // Get student ID
                $student_stmt = $conn->prepare("SELECT student_id FROM students WHERE reg_number = ? AND course_id = ? AND deleted = 0");
                $student_stmt->bind_param("si", $reg_number, $course_id);
                $student_stmt->execute();
                $student_result = $student_stmt->get_result();
                
                if($student_result->num_rows > 0){
                    $student = $student_result->fetch_assoc();
                    $student_id = $student['student_id'];
                    $total_marks = $ca_marks + $final_marks;
                    
                    // Get grade
                    $grade_stmt = $conn->prepare("SELECT grade_name FROM grade_configuration WHERE course_id = ? AND ? BETWEEN min_marks AND max_marks LIMIT 1");
                    $grade_stmt->bind_param("id", $course_id, $total_marks);
                    $grade_stmt->execute();
                    $grade_result = $grade_stmt->get_result();
                    $grade = $grade_result->num_rows > 0 ? $grade_result->fetch_assoc()['grade_name'] : 'F';
                    $grade_stmt->close();
                    
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
                    
                    if($insert_stmt->execute()){
                        $success_count++;
                    }
                    $insert_stmt->close();
                } else {
                    $error_details[] = "Row $row_count: Student $reg_number not found";
                }
                
                $student_stmt->close();
            }
            
            fclose($handle);
            
            $message = "✓ Uploaded $success_count marks successfully!";
            $message_type = "success";
            
            if(!empty($error_details)){
                $message .= " (" . count($error_details) . " errors)";
            }
        }
    }
}

// Handle manual entry
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_marks'){
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = "Security token verification failed";
        $message_type = "error";
    } else {
        $student_id = (int)($_POST['student_id'] ?? 0);
        $ca_marks = floatval($_POST['ca_marks'] ?? 0);
        $final_marks = floatval($_POST['final_marks'] ?? 0);
        
        // Validate
        if($student_id <= 0 || $ca_marks < 0 || $ca_marks > 60 || $final_marks < 0 || $final_marks > 40){
            $message = "Invalid marks or student";
            $message_type = "error";
        } else {
            $total_marks = $ca_marks + $final_marks;
            
            // Get grade
            $grade_stmt = $conn->prepare("SELECT grade_name FROM grade_configuration WHERE course_id = ? AND ? BETWEEN min_marks AND max_marks LIMIT 1");
            $grade_stmt->bind_param("id", $course_id, $total_marks);
            $grade_stmt->execute();
            $grade_result = $grade_stmt->get_result();
            $grade = $grade_result->num_rows > 0 ? $grade_result->fetch_assoc()['grade_name'] : 'F';
            $grade_stmt->close();
            
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
            
            if($stmt->execute()){
                $message = "✓ Marks saved successfully!";
                $message_type = "success";
            } else {
                $message = "Error saving marks";
                $message_type = "error";
            }
            $stmt->close();
        }
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get enrolled students
$students_query = "
    SELECT DISTINCT
        s.student_id,
        s.reg_number,
        s.name,
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

// Function to determine student status
function getStudentStatus($ca_marks, $final_marks, $total_marks) {
    if($ca_marks === null || $final_marks === null) {
        return ['status' => 'Not Entered', 'class' => 'status-pending', 'icon' => '-'];
    }
    
    // Check if any component is below half
    $ca_half = 30; // Half of 60
    $final_half = 20; // Half of 40
    $total_half = 50; // Half of 100
    
    if($ca_marks < $ca_half || $final_marks < $final_half || $total_marks < $total_half) {
        return ['status' => 'Supplementary', 'class' => 'status-supplementary', 'icon' => '⚠️'];
    } else {
        return ['status' => 'Passed', 'class' => 'status-pass', 'icon' => '✓'];
    }
}

?>

<!DOCTYPE html>
<html>
<head>
    <title>Upload Results - <?= htmlspecialchars($module['module_code']) ?></title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        .auth-card {
            width: 1200px;
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
            text-align: center;
        }

        .module-info strong {
            display: block;
            font-size: 18px;
            margin-bottom: 5px;
        }

        .module-info small {
            display: block;
            opacity: 0.9;
        }

        .alert {
            padding: 12px;
            margin: 15px 0;
            border-radius: 8px;
            display: none;
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

        .info-box {
            background: #e3f2fd;
            border-left: 4px solid #2196F3;
            padding: 15px;
            border-radius: 4px;
            margin: 15px 0;
            color: #004085;
        }

        .tabs {
            display: flex;
            gap: 10px;
            margin: 20px 0;
            border-bottom: 2px solid #ddd;
        }

        .tab-btn {
            padding: 10px 15px;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: bold;
            color: #666;
            border-bottom: 3px solid transparent;
        }

        .tab-btn.active {
            color: var(--terra-rosa);
            border-bottom-color: var(--terra-rosa);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .form-section {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }

        .form-group {
            margin-bottom: 15px;
        }

        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: var(--midnight-garden);
        }

        input[type="file"],
        input[type="number"],
        select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            box-sizing: border-box;
        }

        input:focus,
        select:focus {
            outline: none;
            border-color: var(--terra-rosa);
        }

        .btn {
            padding: 12px 20px;
            background: linear-gradient(135deg, var(--terra-rosa), var(--honey-glow));
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
            font-size: 14px;
        }

        .btn:hover {
            opacity: 0.9;
        }

        .btn-secondary {
            background: var(--minty-fresh);
            color: var(--art-craft);
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

        .grade-cell {
            font-weight: bold;
            text-align: center;
            font-size: 16px;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: bold;
            text-align: center;
        }

        .status-pass {
            background: #d4edda;
            color: #155724;
        }

        .status-supplementary {
            background: #fff3cd;
            color: #856404;
        }

        .status-pending {
            background: #e2e3e5;
            color: #383d41;
        }

        .template-info {
            background: white;
            padding: 15px;
            border-left: 4px solid var(--terra-rosa);
            border-radius: 4px;
            margin: 15px 0;
            font-size: 13px;
            font-family: monospace;
        }

        .marks-input {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }

        .marks-input input {
            flex: 1;
        }

        .back-link {
            text-align: center;
            margin-top: 20px;
        }

        .back-link a {
            color: var(--terra-rosa);
            text-decoration: none;
            font-weight: bold;
            padding: 10px 20px;
            border: 2px solid var(--terra-rosa);
            border-radius: 8px;
            display: inline-block;
        }

        .back-link a:hover {
            background: var(--terra-rosa);
            color: white;
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

            .marks-input {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>

<div class="auth-card">
    <h2>📝 Upload Results</h2>

    <div class="module-info">
        <strong><?= htmlspecialchars($module['module_code']) ?> - <?= htmlspecialchars($module['module_name']) ?></strong>
        <small><?= htmlspecialchars($module['course_name']) ?> | Year <?= $module['year'] ?> | Semester <?= $module['semester'] ?></small>
    </div>

    <?php if ($message): ?>
        <div class="alert <?= $message_type === 'success' ? 'success' : 'error' ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="tabs">
        <button class="tab-btn active" onclick="switchTab(event, 'csv-upload')">📤 Upload CSV</button>
        <button class="tab-btn" onclick="switchTab(event, 'manual-entry')">✎ Manual Entry</button>
        <button class="tab-btn" onclick="switchTab(event, 'review')">👁️ Review Results</button>
    </div>

    <!-- CSV Upload Tab -->
    <div id="csv-upload" class="tab-content active">
        <div class="form-section">
            <h3 style="margin-top: 0;">CSV File Upload</h3>
            
            <p><strong>CSV Format:</strong></p>
            <div class="template-info">
Reg_Number,CA_Marks,Final_Marks<br>
12345b,45,35<br>
1234ab,52,38<br>
12345c,38,32
            </div>

            <div class="info-box">
                <strong>Supplementary Criteria:</strong><br>
                A student gets supplementary if any of these conditions are met:<br>
                • CA Marks < 30 (half of 60), OR<br>
                • Final Marks < 20 (half of 40), OR<br>
                • Total Marks < 50 (half of 100)
            </div>

            <p><strong>Requirements:</strong></p>
            <ul>
                <li>CA Marks: 0-60</li>
                <li>Final Marks: 0-40</li>
                <li>Total: 0-100 (automatically calculated)</li>
                <li>Grade: Automatically assigned</li>
                <li>Status: Automatically determined (Passed or Supplementary)</li>
            </ul>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="upload_csv">

                <div class="form-group">
                    <label for="csv_file">Choose CSV File:</label>
                    <input type="file" id="csv_file" name="csv_file" accept=".csv" required>
                </div>

                <button type="submit" class="btn">📤 Upload Marks</button>
            </form>

            <p style="text-align: center; margin-top: 20px;">
                <a href="download_template.php?module_id=<?= $module_id ?>" class="btn btn-secondary" style="display: inline-block;">📥 Download Template</a>
            </p>
        </div>
    </div>

    <!-- Manual Entry Tab -->
    <div id="manual-entry" class="tab-content">
        <div class="form-section">
            <h3 style="margin-top: 0;">Enter Marks Manually</h3>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="save_marks">

                <div class="form-group">
                    <label for="student_id">Select Student:</label>
                    <select id="student_id" name="student_id" required>
                        <option value="">-- Choose Student --</option>
                        <?php foreach ($students as $student): ?>
                        <option value="<?= $student['student_id'] ?>">
                            <?= htmlspecialchars($student['reg_number']) ?> - <?= htmlspecialchars($student['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="marks-input">
                    <div style="flex: 1;">
                        <label for="ca_marks">CA Marks (0-60):</label>
                        <input type="number" id="ca_marks" name="ca_marks" min="0" max="60" step="0.5" required onchange="calculateTotal()">
                    </div>
                    <div style="flex: 1;">
                        <label for="final_marks">Final Marks (0-40):</label>
                        <input type="number" id="final_marks" name="final_marks" min="0" max="40" step="0.5" required onchange="calculateTotal()">
                    </div>
                    <div style="flex: 1;">
                        <label>Total (0-100):</label>
                        <input type="number" id="total_marks" disabled style="background: #f0f0f0;">
                    </div>
                </div>

                <button type="submit" class="btn" style="margin-top: 20px;">💾 Save Marks</button>
            </form>
        </div>
    </div>

    <!-- Review Results Tab -->
    <div id="review" class="tab-content">
        <h3>Results Summary</h3>

        <?php if (count($students) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Reg Number</th>
                        <th>Student Name</th>
                        <th>CA Marks</th>
                        <th>Final Marks</th>
                        <th>Total</th>
                        <th>Grade</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $student): 
                        $ca = $student['ca_marks'];
                        $final = $student['final_marks'];
                        $total = ($ca !== null && $final !== null) ? $ca + $final : 0;
                        
                        $statusInfo = getStudentStatus($ca, $final, $total);
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($student['reg_number']) ?></strong></td>
                        <td><?= htmlspecialchars($student['name']) ?></td>
                        <td><?= $ca !== null ? number_format($ca, 2) : '-' ?></td>
                        <td><?= $final !== null ? number_format($final, 2) : '-' ?></td>
                        <td><strong><?= $total > 0 ? number_format($total, 2) : '-' ?></strong></td>
                        <td class="grade-cell"><?= $student['grade'] ?? '-' ?></td>
                        <td>
                            <span class="status-badge <?= $statusInfo['class'] ?>">
                                <?= $statusInfo['icon'] ?> <?= $statusInfo['status'] ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="text-align: center; color: #666;">No students enrolled in this module</p>
        <?php endif; ?>
    </div>

    <div class="back-link">
        <a href="dashboard.php">← Back to Dashboard</a>
    </div>
</div>

<script>
function switchTab(event, tabId) {
    // Hide all tabs
    const contents = document.querySelectorAll('.tab-content');
    contents.forEach(content => content.classList.remove('active'));
    
    // Remove active class from buttons
    const buttons = document.querySelectorAll('.tab-btn');
    buttons.forEach(btn => btn.classList.remove('active'));
    
    // Show selected tab
    document.getElementById(tabId).classList.add('active');
    event.target.classList.add('active');
}

function calculateTotal() {
    const ca = parseFloat(document.getElementById('ca_marks').value) || 0;
    const final = parseFloat(document.getElementById('final_marks').value) || 0;
    document.getElementById('total_marks').value = (ca + final).toFixed(2);
}
</script>

</body>
</html>