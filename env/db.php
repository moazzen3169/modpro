<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "suit_store";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("اتصال به دیتابیس ناموفق بود: " . $conn->connect_error);
}
?>
