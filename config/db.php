<?php
$conn = mysqli_connect("localhost", "root", "", "csms");

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}
?>
