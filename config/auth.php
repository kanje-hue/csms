<?php

function requireAdminLogin() {
    // Check if the user is logged in and has admin privileges
    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
        header('Location: /login.php');
        exit();
    }
}

function requireTeacherLogin() {
    // Check if the user is logged in and has teacher privileges
    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'teacher') {
        header('Location: /login.php');
        exit();
    }
}

function requireStudentLogin() {
    // Check if the user is logged in and has student privileges
    if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'student') {
        header('Location: /login.php');
        exit();
    }
}

function logout() {
    // Clear session and redirect to login page
    session_start();
    $_SESSION = [];
    session_destroy();
    header('Location: /login.php');
    exit();
}

?>