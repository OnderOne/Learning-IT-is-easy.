<?php
// Переменные берутся напрямую из скрытых настроек хостинга Render
$host = getenv('DB_HOST');
$db   = getenv('DB_NAME');     
$user = getenv('DB_USER');
$pass = getenv('DB_PASS');           
$port = 5432; 

try {
    // Подключение к базе данных PostgreSQL
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=$db", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Автоматическое создание таблицы users
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
