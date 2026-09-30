<?php
$host = 'dpg-dauk1m0473hc73bk5hag-a';
$db   = 'my_database_7vkd';     
$user = 'my_database_7vkd_user';
$pass = '4s34tqHLipR5hb0uLHsshtX66F1c1jcJ';           
$port = 5432;

try {
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$db", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Ошибка подключения к базе данных: " . $e->getMessage());
}
?>

