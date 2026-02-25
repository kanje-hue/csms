<?php
session_start();
include 'config/db.php';

// ... (keep all the previous code until HANDLE LOGIN)

// HANDLE LOGIN
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login'){
    $role = trim($_POST['role'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    if(empty($role) || empty($email) || empty($password)){
        $message = "❌ Please fill in all fields";
        $message_type = "error";
    } else {
        if($role === 'admin'){
            $stmt = $conn->prepare("SELECT admin_id, name, password FROM admins WHERE email = ? AND deleted = 0");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if($result->num_rows > 0){
                $admin = $result->fetch_assoc();
                // Check password (handle both plaintext and hashed)
                if($admin['password'] === $password || password_verify($password, $admin['password'])){
                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['admin_id'] = $admin['admin_id'];
                    $_SESSION['admin_name'] = $admin['name'];
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    header("Location: admin/manage_courses.php");
                    exit();
                } else {
                    $message = "❌ Invalid email or password";
                    $message_type = "error";
                }
            } else {
                $message = "❌ Invalid email or password";
                $message_type = "error";
            }
            $stmt->close();
        }
        elseif($role === 'teacher'){
            $stmt = $conn->prepare("SELECT teacher_id, fullname, password FROM teachers WHERE email = ? AND deleted = 0");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if($result->num_rows > 0){
                $teacher = $result->fetch_assoc();
                // Check password (handle both plaintext and hashed)
                if($teacher['password'] === $password || password_verify($password, $teacher['password'])){
                    $_SESSION['teacher_logged_in'] = true;
                    $_SESSION['teacher_id'] = $teacher['teacher_id'];
                    $_SESSION['teacher_name'] = $teacher['fullname'];
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    header("Location: teacher/dashboard.php");
                    exit();
                } else {
                    $message = "❌ Invalid email or password";
                    $message_type = "error";
                }
            } else {
                $message = "❌ Invalid email or password";
                $message_type = "error";
            }
            $stmt->close();
        }
        elseif($role === 'student'){
            $stmt = $conn->prepare("SELECT student_id, name, course_id, year, password FROM students WHERE email = ? AND deleted = 0 AND status = 'active'");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if($result->num_rows > 0){
                $student = $result->fetch_assoc();
                // Check password (handle both plaintext and hashed)
                if($student['password'] === $password || password_verify($password, $student['password'])){
                    $_SESSION['student_id'] = $student['student_id'];
                    $_SESSION['student_name'] = $student['name'];
                    $_SESSION['course_id'] = $student['course_id'];
                    $_SESSION['year'] = $student['year'];
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    header("Location: student/dashboard.php");
                    exit();
                } else {
                    $message = "❌ Invalid email or password, or account not active";
                    $message_type = "error";
                }
            } else {
                $message = "❌ Invalid email or password, or account not active";
                $message_type = "error";
            }
            $stmt->close();
        }
    }
}

// HANDLE REGISTRATION - Hash passwords
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register'){
    $role = trim($_POST['register_role'] ?? '');
    $fullname = trim($_POST['fullname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');
    
    if(empty($role) || empty($fullname) || empty($email) || empty($password) || empty($confirm_password)){
        $message = "❌ Please fill in all fields";
        $message_type = "error";
    } elseif($password !== $confirm_password){
        $message = "❌ Passwords do not match";
        $message_type = "error";
    } else {
        // Hash the password for new registrations
        $hashed_password = password_hash($password, PASSWORD_BCRYPT);
        
        if($role === 'admin'){
            $check = $conn->prepare("SELECT admin_id FROM admins WHERE email = ?");
            $check->bind_param("s", $email);
            $check->execute();
            
            if($check->get_result()->num_rows > 0){
                $message = "❌ Email already registered";
                $message_type = "error";
            } else {
                $stmt = $conn->prepare("INSERT INTO admins (name, email, password, status, deleted) VALUES (?, ?, ?, 'active', 0)");
                $stmt->bind_param("sss", $fullname, $email, $hashed_password);
                
                if($stmt->execute()){
                    $message = "✅ Admin account created successfully! You can now login.";
                    $message_type = "success";
                } else {
                    $message = "❌ Error creating account";
                    $message_type = "error";
                }
                $stmt->close();
            }
            $check->close();
        }
        elseif($role === 'teacher'){
            $check = $conn->prepare("SELECT teacher_id FROM teachers WHERE email = ?");
            $check->bind_param("s", $email);
            $check->execute();
            
            if($check->get_result()->num_rows > 0){
                $message = "❌ Email already registered";
                $message_type = "error";
            } else {
                $stmt = $conn->prepare("INSERT INTO teachers (fullname, email, password, status, deleted) VALUES (?, ?, ?, 'active', 0)");
                $stmt->bind_param("sss", $fullname, $email, $hashed_password);
                
                if($stmt->execute()){
                    $message = "✅ Teacher account created successfully! You can now login.";
                    $message_type = "success";
                } else {
                    $message = "❌ Error creating account";
                    $message_type = "error";
                }
                $stmt->close();
            }
            $check->close();
        }
        elseif($role === 'student'){
            $reg_number = trim($_POST['reg_number'] ?? '');
            $course_id = safe_int($_POST['course_id'] ?? 0);
            
            if(empty($reg_number) || !$course_id){
                $message = "❌ Please fill in registration number and select a course";
                $message_type = "error";
            } else {
                $check = $conn->prepare("SELECT student_id FROM students WHERE email = ?");
                $check->bind_param("s", $email);
                $check->execute();
                
                if($check->get_result()->num_rows > 0){
                    $message = "❌ Email already registered";
                    $message_type = "error";
                } else {
                    $stmt = $conn->prepare("INSERT INTO students (reg_number, name, email, password, course_id, year, status, deleted) VALUES (?, ?, ?, ?, ?, 1, 'pending', 0)");
                    $stmt->bind_param("ssssi", $reg_number, $fullname, $email, $hashed_password, $course_id);
                    
                    if($stmt->execute()){
                        $message = "✅ Student account created successfully! Awaiting admin approval.";
                        $message_type = "success";
                    } else {
                        $message = "❌ Error creating account";
                        $message_type = "error";
                    }
                    $stmt->close();
                }
                $check->close();
            }
        }
    }
}