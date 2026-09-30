<?php
try {
    // Создаем базу прямо в файле внутри вашего проекта на Render
    $pdo = new PDO("sqlite:/var/www/html/database.sqlite");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Автоматически создаем таблицу пользователей при первом открытии
    $sql = "CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL UNIQUE,
        username TEXT NOT NULL UNIQUE,
        name TEXT,
        gender TEXT,
        age INTEGER,
        password TEXT NOT NULL
    );";
    $pdo->exec($sql);

} catch(PDOException $e) {
    die("Ошибка подключения к базе данных: " . $e->getMessage());
}
?>
