<?php
$db_url = "postgresql://my_database_7vkd_user:4s34tqHLipR5hb8uLHsshtX66F1c1jcJ@dpg-dauklm0473hc73bk5hag-a.oregon-postgres.render.com/my_database_7vkd";

try {
    $dbopts = parse_url($db_url);
    $host = $dbopts["host"];
    $port = $dbopts["port"] ?? 5432;
    $user = $dbopts["user"];
    $pass = $dbopts["pass"];
    $db   = ltrim($dbopts["path"], '/');

    // Подключение к базе данных PostgreSQL
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$db", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Ошибка подключения к базе данных: " . $e->getMessage());
}
?>

