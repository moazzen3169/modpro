<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "suit_store";

$conn = new mysqli($servername, $username, $password, $dbname);
$conn->set_charset('utf8mb4');
?>
