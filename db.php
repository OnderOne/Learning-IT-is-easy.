<?php
$db_url = "postgresql://my_database_7vkd_user:4s34tqHLipR5hb8uLHsshtX66F1c1jcJ@://render.com";

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

    // Этот код сам создаст таблицу прямо внутри базы данных
    $sql = "CREATE TABLE IF NOT EXISTS users (
        id SERIAL PRIMARY KEY,
        email VARCHAR(255) NOT NULL UNIQUE,
        username VARCHAR(100) NOT NULL UNIQUE,
        name VARCHAR(100),
        gender VARCHAR(20),
        age INT,
        password VARCHAR(255) NOT NULL
    );";
    $pdo->exec($sql);

} catch(PDOException $e) {
    die("Ошибка подключения к базе данных: " . $e->getMessage());
}
?>
