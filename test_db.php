
<?php
$conn = mysqli_connect("127.0.0.1", "root", "", "csms");
if ($conn) {
    echo "DB connected successfully!";
} else {
    echo "Failed: " . mysqli_connect_error();
}
