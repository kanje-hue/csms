<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include database connection
include '../config/db.php';

$message = ""; // To show success or error messages

// Handle form submission
if (isset($_POST['register'])) {
    $reg = mysqli_real_escape_string($conn, $_POST['reg_number']);
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $semester = mysqli_real_escape_string($conn, $_POST['semester']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    // Check if email or reg_number already exists
    $check = "SELECT * FROM students WHERE email='$email' OR reg_number='$reg'";
    $result = mysqli_query($conn, $check);

    if (mysqli_num_rows($result) > 0) {
        $message = "<p style='color:red;'>Email or Registration Number already exists!</p>";
    } else {
        $sql = "INSERT INTO students (reg_number, name, email, password, semester) 
                VALUES ('$reg', '$name', '$email', '$password', '$semester')";

        if (mysqli_query($conn, $sql)) {
            $message = "<p style='color:green;'>Registration successful. Wait for admin approval.</p>";
        } else {
            $message = "<p style='color:red;'>Error: " . mysqli_error($conn) . "</p>";
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Student Registration - CSMS</title>
</head>
<body>
    <h2>Student Registration</h2>

    <!-- Show message -->
    <?php echo $message; ?>

    <form method="POST" action="">
        <label>Registration Number:</label><br>
        <input type="text" name="reg_number" required><br><br>

        <label>Full Name:</label><br>
        <input type="text" name="name" required><br><br>

        <label>Email:</label><br>
        <input type="email" name="email" required><br><br>

        <label>Semester:</label><br>
        <input type="text" name="semester" required><br><br>

        <label>Password:</label><br>
        <input type="password" name="password" required><br><br>

        <button type="submit" name="register">Register</button>
    </form>

    <p>Already registered? <a href="login.php">Login here</a></p>
</body>
</html>
