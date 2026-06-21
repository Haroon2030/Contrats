<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

if (!isset($_GET['id'])) {
    header('Location: drafts.php');
    exit();
}

$id = (int) $_GET['id'];

$stmt = $conn->prepare("UPDATE contracts SET status='deleted' WHERE id=?");
$stmt->bind_param('i', $id);
$stmt->execute();

header('Location: drafts.php');
exit();
