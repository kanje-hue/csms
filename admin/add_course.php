<?php
/**
 * admin/add_course.php - Add New Course
 */

session_start();
require_once '../config/db.php';
require_once '../config/security_base.php';

// Check admin login
checkAdminSession();

$message = "";
$message_type = "";
$csrf_token = generateCSRF();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_course'])) {
    validateCSRF($_POST['csrf_token'] ?? '');
    
    $course_name = sanitizeInput($_POST['course_name'] ?? '');
    
    if (empty($course_name)) {
        $message = "Course name is required";
        $message_type = "error";
    } else {
        // Check if course already exists
        $check = $conn->prepare("SELECT course_id FROM courses WHERE course_name = ? AND deleted = 0");
        $check->bind_param("s", $course_name);
        $check->execute();
        
        if ($check->get_result()->num_rows > 0) {
            $message = "Course with this name already exists";
            $message_type = "error";
        } else {
            $insert = $conn->prepare("INSERT INTO courses (course_name, status, created_at) VALUES (?, 'active', NOW())");
            $insert->bind_param("s", $course_name);
            
            if ($insert->execute()) {
                $message = "Course added successfully";
                $message_type = "success";
                
                logAdminAction($conn, $_SESSION['admin_id'], 'add_course', "Added course: $course_name");
            } else {
                $message = "Error adding course";
                $message_type = "error";
            }
            $insert->close();
        }
        $check->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Course - CSMS</title>
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
            font-weight: 700;
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
            max-width: 600px;
            margin: 2rem auto;
            padding: 0 2rem;
        }

        .card {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        }

        h1 {
            color: #0f172a;
            margin-bottom: 1.5rem;
            font-size: 1.8rem;
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
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

        .form-group {
            margin-bottom: 1.5rem;
        }

        label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #0f172a;
        }

        input {
            width: 100%;
            padding: 0.8rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s;
        }

        input:focus {
            outline: none;
            border-color: #2dd4bf;
            box-shadow: 0 0 0 3px rgba(45, 212, 191, 0.1);
        }

        .btn {
            padding: 0.8rem 1.8rem;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
            cursor: pointer;
            font-size: 1rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, #2dd4bf, #14b8a6);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px -4px rgba(45, 212, 191, 0.4);
        }

        .btn-secondary {
            background: #e2e8f0;
            color: #0f172a;
            margin-left: 1rem;
        }

        .btn-secondary:hover {
            background: #cbd5e1;
        }

        .button-group {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1rem;
        }
    </style>
</head>
<body>

<div class="header">
    <h1>CSMS Admin</h1>
    <a href="logout.php">🚪 Logout</a>
</div>

<div class="container">
    <div class="card">
        <h1>➕ Add New Course</h1>

        <?php if ($message): ?>
            <div class="alert <?= $message_type ?>"><?= $message ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            
            <div class="form-group">
                <label for="course_name">Course Name</label>
                <input type="text" id="course_name" name="course_name" placeholder="e.g., Computer Science" required>
            </div>

            <div class="button-group">
                <button type="submit" name="add_course" class="btn btn-primary">Add Course</button>
                <a href="dashboard.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

</body>
</html>