<?php
//Assign roles to make Senior Module available only for Senior Managers
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['role'])) {
    echo json_encode(['role' => null]);
    exit();
}

echo json_encode(['role' => $_SESSION['role']]);
?>
