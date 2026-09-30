<?php
// Сюда вставьте скопированную строку Internal Database URL вместо примера ниже:
$db_url = "ВСТАВЬТЕ_СЮДА_ВАШУ_СКОПИРОВАННУЮ_СТРОКУ_INTERNAL_DATABASE_URL";

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
