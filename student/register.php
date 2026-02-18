<?php
session_start();
include "../config/db.php";

function safe_string($value) {
    return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
}

function safe_int($value) {
    return filter_var($value, FILTER_VALIDATE_INT);
}

function safe_email($value) {
    return filter_var(trim($value), FILTER_VALIDATE_EMAIL);
}

$message = "";
$message_type = "";

if (isset($_POST['register'])) {
    $reg_number = safe_string($_POST['reg_number'] ?? '');
    $name = safe_string($_POST['name'] ?? '');
    $email = safe_email($_POST['email'] ?? '');
    $course_id = safe_int($_POST['course_id'] ?? 0);
    $year = safe_int($_POST['year'] ?? 0);
    $semester = safe_int($_POST['semester'] ?? 0);
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    // Validation
    if (empty($reg_number) || empty($name) || !$email || !$course_id || !$year || !$semester) {
        $message = "Please fill all required fields!";
        $message_type = "error";
    } elseif ($password !== $confirm) {
        $message = "Passwords do not match!";
        $message_type = "error";
    } elseif (strlen($password) < 6) {
        $message = "Password must be at least 6 characters!";
        $message_type = "error";
    } else {
        // Check if email already exists
        $check_email = $conn->prepare("SELECT student_id FROM students WHERE email = ? AND deleted = 0");
        $check_email->bind_param("s", $email);
        $check_email->execute();
        
        if ($check_email->get_result()->num_rows > 0) {
            $message = "Email already registered!";
            $message_type = "error";
        } else {
            // Check if reg_number already exists
            $check_reg = $conn->prepare("SELECT student_id FROM students WHERE reg_number = ? AND deleted = 0");
            $check_reg->bind_param("s", $reg_number);
            $check_reg->execute();
            
            if ($check_reg->get_result()->num_rows > 0) {
                $message = "Registration number already exists!";
                $message_type = "error";
            } else {
                // Hash password and insert
                $hash = password_hash($password, PASSWORD_DEFAULT);
                
                $stmt = $conn->prepare("
                    INSERT INTO students (reg_number, name, email, password, course_id, year, semester, status, deleted, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', 0, NOW())
                ");
                $stmt->bind_param("ssssiis", $reg_number, $name, $email, $hash, $course_id, $year, $semester);
                
                if ($stmt->execute()) {
                    $message = "✓ Registration successful! Please wait for admin approval to activate your account.";
                    $message_type = "success";
                    // Clear form
                    $_POST = array();
                } else {
                    $message = "Registration failed: " . $stmt->error;
                    $message_type = "error";
                }
                $stmt->close();
            }
            $check_reg->close();
        }
        $check_email->close();
    }
}

// Fetch courses for dropdown
$courses_result = $conn->query("SELECT course_id, course_name FROM courses WHERE deleted = 0 ORDER BY course_name");

?>

<!DOCTYPE html>
<html>
<head>
    <title>Student Registration</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        .message {
            padding: 12px;
            margin: 15px 0;
            border-radius: 8px;
            font-weight: bold;
        }
        
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .form-row.full {
            grid-template-columns: 1fr;
        }

        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<div class="auth-card">
    <h2>Student Registration</h2>
    <p style="text-align: center; color: #666; margin-bottom: 20px;">Create your account (Admin approval required)</p>

    <?php if ($message): ?>
        <div class="message <?= $message_type ?>">
            <?= $message ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <!-- Registration Number -->
        <div class="form-row full">
            <div class="form-group">
                <label>Registration Number *</label>
                <input type="text" name="reg_number" value="<?= $_POST['reg_number'] ?? '' ?>" required placeholder="e.g., STU001">
            </div>
        </div>

        <!-- Full Name -->
        <div class="form-row full">
            <div class="form-group">
                <label>Full Name *</label>
                <input type="text" name="name" value="<?= $_POST['name'] ?? '' ?>" required placeholder="e.g., John Doe">
            </div>
        </div>

        <!-- Email -->
        <div class="form-row full">
            <div class="form-group">
                <label>Email *</label>
                <input type="email" name="email" value="<?= $_POST['email'] ?? '' ?>" required placeholder="e.g., john@example.com">
            </div>
        </div>

        <!-- Course Selection -->
        <div class="form-row">
            <div class="form-group">
                <label>Course *</label>
                <select name="course_id" required>
                    <option value="">-- Select Course --</option>
                    <?php 
                    while ($course = $courses_result->fetch_assoc()) {
                        $selected = (isset($_POST['course_id']) && $_POST['course_id'] == $course['course_id']) ? 'selected' : '';
                        echo "<option value='{$course['course_id']}' $selected>{$course['course_name']}</option>";
                    }
                    ?>
                </select>
            </div>

            <!-- Year Selection -->
            <div class="form-group">
                <label>Year *</label>
                <select name="year" required>
                    <option value="">-- Select Year --</option>
                    <option value="1" <?= (isset($_POST['year']) && $_POST['year'] == 1) ? 'selected' : '' ?>>Year 1</option>
                    <option value="2" <?= (isset($_POST['year']) && $_POST['year'] == 2) ? 'selected' : '' ?>>Year 2</option>
                    <option value="3" <?= (isset($_POST['year']) && $_POST['year'] == 3) ? 'selected' : '' ?>>Year 3</option>
                </select>
            </div>
        </div>

        <!-- Semester Selection -->
        <div class="form-row full">
            <div class="form-group">
                <label>Semester *</label>
                <select name="semester" required>
                    <option value="">-- Select Semester --</option>
                    <option value="1" <?= (isset($_POST['semester']) && $_POST['semester'] == 1) ? 'selected' : '' ?>>Semester 1</option>
                    <option value="2" <?= (isset($_POST['semester']) && $_POST['semester'] == 2) ? 'selected' : '' ?>>Semester 2</option>
                </select>
            </div>
        </div>

        <!-- Password Fields -->
        <div class="form-row">
            <div class="form-group">
                <label>Password *</label>
                <input type="password" name="password" required placeholder="Min 6 characters">
            </div>

            <div class="form-group">
                <label>Confirm Password *</label>
                <input type="password" name="confirm_password" required placeholder="Confirm password">
            </div>
        </div>

        <button class="btn" name="register" style="width: 100%; margin-top: 15px;">Register</button>
    </form>

    <div class="auth-links">
        <p>Already have an account? <a href="login.php">Login here</a></p>
    </div>
</div>

</body>
</html>