<?php
$host = '3emv2s.h.filess.io';
$db   = 'learning_it_guessmoon';     
$user = 'learning_it_guessmoon';
$pass = 'bb668b2d41856d3038f640e4b1b46400562c26bb';           
$port = 3306;

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Ошибка подключения к базе данных: " . $e->getMessage());
}
?>
