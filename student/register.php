<?php
/**
 * student/register.php - Student Registration
 * Features: Course selection, password strength, pending approval
 */

session_start();
require_once '../config/db.php';
require_once '../config/security.php';

$security = new SecurityManager($conn);

function safe_string($value) {
    return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
}

function safe_int($value) {
    return filter_var($value, FILTER_VALIDATE_INT);
}

$message = "";
$message_type = "";

// Fetch courses for dropdown
$courses = $conn->query("SELECT course_id, course_name FROM courses WHERE deleted = 0 ORDER BY course_name");

if (isset($_POST['register'])) {
    $reg_number = safe_string($_POST['reg_number'] ?? '');
    $name = safe_string($_POST['name'] ?? '');
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $course_id = safe_int($_POST['course_id'] ?? 0);
    $year = safe_int($_POST['year'] ?? 0);
    $semester = safe_int($_POST['semester'] ?? 0);
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    // Validation
    $errors = [];
    if (!$reg_number) $errors[] = "Registration number required";
    if (!$name) $errors[] = "Full name required";
    if (!$email) $errors[] = "Valid email required";
    if (!$course_id) $errors[] = "Course selection required";
    if (!$year || $year < 1 || $year > 3) $errors[] = "Valid year required";
    if (!$semester || !in_array($semester, [1,2])) $errors[] = "Valid semester required";
    
    if ($password !== $confirm) {
        $errors[] = "Passwords do not match";
    } else {
        $strength = $security->validatePasswordStrength($password);
        if (!$strength['valid']) {
            $errors[] = $strength['message'];
        }
    }

    if (empty($errors)) {
        // Check if email exists
        $check = $conn->prepare("SELECT student_id FROM students WHERE email = ? OR reg_number = ? AND deleted = 0");
        $check->bind_param("ss", $email, $reg_number);
        $check->execute();
        
        if ($check->get_result()->num_rows > 0) {
            $message = "Email or registration number already exists!";
            $message_type = "error";
        } else {
            // Insert new student with pending status
            $hash = $security->hashPassword($password);
            $insert = $conn->prepare("
                INSERT INTO students (reg_number, name, email, password, course_id, year, semester, status, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");
            $insert->bind_param("ssssiis", $reg_number, $name, $email, $hash, $course_id, $year, $semester);
            
            if ($insert->execute()) {
                $message = "✓ Registration successful! Please wait for admin approval.";
                $message_type = "success";
                $_POST = array(); // Clear form
            } else {
                $message = "Registration failed: " . $insert->error;
                $message_type = "error";
            }
            $insert->close();
        }
        $check->close();
    } else {
        $message = implode("<br>", $errors);
        $message_type = "error";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Registration - CSMS</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .card {
            background: white;
            width: 100%;
            max-width: 550px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.4);
            overflow: hidden;
        }
        .card-header {
            background: linear-gradient(135deg, #16a085, #117a65);
            padding: 30px;
            text-align: center;
            color: white;
        }
        .card-header h1 { font-size: 28px; margin-bottom: 5px; }
        .card-header p { font-size: 14px; opacity: 0.9; }
        .card-body { padding: 30px; }
        .alert {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid;
        }
        .alert.success { background: #d1fae5; color: #065f46; border-color: #10b981; }
        .alert.error { background: #fee2e2; color: #991b1b; border-color: #ef4444; }
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
            color: #374151;
            font-size: 14px;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }
        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: #16a085;
            box-shadow: 0 0 0 3px rgba(22,160,133,0.1);
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        .btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #16a085, #117a65);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 10px;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(22,160,133,0.3);
        }
        .hint {
            font-size: 12px;
            color: #6b7280;
            margin-top: 5px;
        }
        .auth-links {
            text-align: center;
            margin-top: 20px;
        }
        .auth-links a {
            color: #16a085;
            text-decoration: none;
            font-weight: 600;
        }
        .auth-links a:hover { text-decoration: underline; }
        @media (max-width: 480px) { .form-row { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="card">
        <div class="card-header">
            <h1>CSMS Student</h1>
            <p>Create your account • Admin approval required</p>
        </div>
        <div class="card-body">
            <?php if ($message): ?>
                <div class="alert <?= $message_type ?>"><?= $message ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label>Registration Number *</label>
                    <input type="text" name="reg_number" value="<?= $_POST['reg_number'] ?? '' ?>" placeholder="e.g., STU001" required>
                </div>

                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="name" value="<?= $_POST['name'] ?? '' ?>" placeholder="John Doe" required>
                </div>

                <div class="form-group">
                    <label>Email *</label>
                    <input type="email" name="email" value="<?= $_POST['email'] ?? '' ?>" placeholder="john@example.com" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Course *</label>
                        <select name="course_id" required>
                            <option value="">Select Course</option>
                            <?php while ($course = $courses->fetch_assoc()): ?>
                            <option value="<?= $course['course_id'] ?>" <?= (isset($_POST['course_id']) && $_POST['course_id'] == $course['course_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($course['course_name']) ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Year *</label>
                        <select name="year" required>
                            <option value="">Year</option>
                            <option value="1" <?= (isset($_POST['year']) && $_POST['year'] == 1) ? 'selected' : '' ?>>Year 1</option>
                            <option value="2" <?= (isset($_POST['year']) && $_POST['year'] == 2) ? 'selected' : '' ?>>Year 2</option>
                            <option value="3" <?= (isset($_POST['year']) && $_POST['year'] == 3) ? 'selected' : '' ?>>Year 3</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Semester *</label>
                    <select name="semester" required>
                        <option value="">Select Semester</option>
                        <option value="1" <?= (isset($_POST['semester']) && $_POST['semester'] == 1) ? 'selected' : '' ?>>Semester 1</option>
                        <option value="2" <?= (isset($_POST['semester']) && $_POST['semester'] == 2) ? 'selected' : '' ?>>Semester 2</option>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Password *</label>
                        <input type="password" name="password" required placeholder="Min 8 chars">
                        <div class="hint">8+ chars, uppercase, number, special</div>
                    </div>
                    <div class="form-group">
                        <label>Confirm *</label>
                        <input type="password" name="confirm_password" required placeholder="Re-enter">
                    </div>
                </div>

                <button type="submit" name="register" class="btn">Register Account</button>
            </form>

            <div class="auth-links">
                <p>Already have an account? <a href="login.php">Login here</a></p>
                <p><a href="../public/">← Back to Unified Login</a></p>
            </div>
        </div>
    </div>
</body>
</html>