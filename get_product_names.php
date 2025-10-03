<?php
require_once __DIR__ . '/env/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$namesStmt = $conn->prepare('SELECT DISTINCT model_name FROM Products ORDER BY model_name');
$namesStmt->execute();
$namesResult = $namesStmt->get_result();

$names = [];
while ($name = $namesResult->fetch_assoc()) {
    $names[] = $name['model_name'];
}

echo json_encode($names, JSON_UNESCAPED_UNICODE);
