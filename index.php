<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once 'db.php';

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$theme = $_COOKIE['theme'] ?? 'dark';
$lang  = $_COOKIE['lang']  ?? 'ua';
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IT Tasks Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
</head>

<body class="theme-<?= $theme ?> flex min-h-screen">

<div class="w-80 sidebar flex flex-col">
    <div class="p-5" style="border-bottom: 1px solid var(--border);">
        <h1 class="text-xl font-bold" id="logo">💻 IT Tasks Pro</h1>
        <p class="text-xs text-muted" id="subtitle">HTML • CSS • JS • PHP • DB</p>
    </div>

    <div class="p-3">
        <input id="searchInput" onkeyup="showSuggestions()" class="search-input" placeholder="">
    </div>

    <div id="suggestions" class="mx-3 mt-1 rounded-2xl shadow-2xl hidden overflow-hidden max-h-96 overflow-y-auto"></div>

    <div class="flex-1 overflow-auto px-2 space-y-1 pb-4" id="taskList"></div>
</div>

<div class="flex-1 flex flex-col">
    <div class="p-5 flex justify-between header">
        <div>
            <h2 class="text-xl font-semibold" id="welcome"></h2>
            <p class="text-sm text-muted" id="welcome_sub"></p>
        </div>
        <div class="flex gap-3 items-center">
            <div onclick="toggleSettings()" class="text-2xl cursor-pointer hover:scale-110 transition">⚙️</div>
            <div onclick="window.location.href='chat.php'" 
                 class="profile-btn px-3 py-2 rounded-xl cursor-pointer flex items-center gap-2 text-sm"
                 title="AI Chat">
                🤖 AI
            </div>
            <div onclick="window.location.href='profile.php'" class="profile-btn px-4 py-2 rounded-xl cursor-pointer flex items-center gap-2">
                👤 <?= htmlspecialchars($user['name'] ?? $user['username']) ?>
            </div>
        </div>
    </div>

    <div id="taskArea" class="flex-1 p-10 overflow-auto"></div>
</div>

<div id="settingsPanel" class="fixed right-0 top-0 w-80 h-full transform translate-x-full transition-all duration-300 z-50">
    <div class="p-4 flex justify-between" style="border-bottom: 1px solid var(--border);">
        <b class="text-lg" id="settings_title">Settings</b>
        <button onclick="toggleSettings()" class="text-xl">✖</button>
    </div>

    <div class="flex">
        <div onclick="tab(0)" class="settings-btn flex-1 text-center" id="tab-lang">🌍 Language</div>
        <div onclick="tab(1)" class="settings-btn flex-1 text-center" id="tab-design">🎨 Design</div>
    </div>

    <div class="p-4">
        <div id="langTab">
            <div class="settings-btn" data-lang="ua" onclick="setLang('ua')">🇺🇦 Українська</div>
            <div class="settings-btn" data-lang="en" onclick="setLang('en')">🇬🇧 English</div>
            <div class="settings-btn" data-lang="de" onclick="setLang('de')">🇩🇪 Deutsch</div>
            <div class="settings-btn" data-lang="fr" onclick="setLang('fr')">🇫🇷 Français</div>
        </div>

        <div id="designTab" class="hidden">
            <div class="settings-btn" data-theme="dark"  onclick="setTheme('dark')">🌑 Dark</div>
            <div class="settings-btn" data-theme="light" onclick="setTheme('light')">☀️ Light</div>
            <div class="settings-btn" data-theme="metal" onclick="setTheme('metal')">⚡ Metal</div>
            <div class="settings-btn" data-theme="wood"  onclick="setTheme('wood')">🌲 Wood</div>
            <div class="settings-btn" data-theme="sand"  onclick="setTheme('sand')">🏖 Sand</div>
        </div>
    </div>
</div>

<script>
const translations = {
    ua: {
        logo: "💻 IT Tasks Pro",
        subtitle: "HTML • CSS • JS • PHP • Бази даних",
        welcome: "Вітаємо",
        welcome_sub: "Вчи програмування з нуля",
        settings_title: "Налаштування",
        search_placeholder: "Пошук завдання (1 або 1.1)",
        task: "Завдання",
        theory: "Теорія",
        practice: "Практика",
        in_development: "Завдання в розробці...",
        tab_lang: "🌍 Мова",
        tab_design: "🎨 Дизайн"
    },
    en: {
        logo: "💻 IT Tasks Pro",
        subtitle: "HTML • CSS • JS • PHP • Databases",
        welcome: "Welcome",
        welcome_sub: "Learn programming from scratch",
        settings_title: "Settings",
        search_placeholder: "Search task (1 or 1.1)",
        task: "Task",
        theory: "Theory",
        practice: "Practice",
        in_development: "Task in development...",
        tab_lang: "🌍 Language",
        tab_design: "🎨 Design"
    },
    de: {
        logo: "💻 IT Tasks Pro",
        subtitle: "HTML • CSS • JS • PHP • Datenbanken",
        welcome: "Willkommen",
        welcome_sub: "Lerne Programmieren von Grund auf",
        settings_title: "Einstellungen",
        search_placeholder: "Aufgabe suchen (1 oder 1.1)",
        task: "Aufgabe",
        theory: "Theorie",
        practice: "Praxis",
        in_development: "Aufgabe in Entwicklung...",
        tab_lang: "🌍 Sprache",
        tab_design: "🎨 Design"
    },
    fr: {
        logo: "💻 IT Tasks Pro",
        subtitle: "HTML • CSS • JS • PHP • Bases de données",
        welcome: "Bienvenue",
        welcome_sub: "Apprenez la programmation depuis zéro",
        settings_title: "Paramètres",
        search_placeholder: "Rechercher une tâche (1 ou 1.1)",
        task: "Tâche",
        theory: "Théorie",
        practice: "Pratique",
        in_development: "Tâche en développement...",
        tab_lang: "🌍 Langue",
        tab_design: "🎨 Design"
    }
};

let currentLang = "<?= $lang ?>";
let currentTheme = "<?= $theme ?>";

const tasksData = {};

tasksData["1.1"] = {
    title: "1.1",
    content: {
        ua: `
            <h3 class="text-xl font-bold mb-4 text-accent">Вітаємо на IT Tasks Pro!</h3>
            <p class="mb-4">Ми дуже раді, що ти вирішив(ла) навчитися програмуванню разом з нами. 
            Тут ти крок за кроком освоїш HTML, CSS, JavaScript, PHP та роботу з базами даних.</p>
            <p class="mb-4">Якщо щось буде незрозуміло — завжди можна звернутися в наш чат-бот підтримки.</p>
            <h4 class="font-bold mt-6 mb-2">План старту:</h4>
            <ol class="list-decimal ml-5 space-y-2 mb-4">
                <li><b>Комп’ютер або ноутбук</b> — бажано саме ПК/ноут, а не телефон.</li>
                <li><b>Редактор коду</b>:
                    <ul class="list-disc ml-5 mt-1">
                        <li><b>Notepad++</b> — рекомендуємо новачкам. Писати трохи складніше, але краще запам’ятовується.</li>
                        <li><b>VS Code</b> — для тих, хто вже трохи розбирається або хоче одразу зручний інструмент.</li>
                    </ul>
                </li>
                <li><b>Концентрація</b> — якщо будеш займатися регулярно, точно не пошкодуєш.</li>
            </ol>
            <p class="mt-4">Готовий? Тоді переходь до наступного підзавдання.</p>
        `,
        en: `
            <h3 class="text-xl font-bold mb-4 text-accent">Welcome to IT Tasks Pro!</h3>
            <p class="mb-4">We are very happy that you decided to learn programming with us.</p>
            <p class="mb-4">If something is unclear — contact our support chatbot.</p>
            <h4 class="font-bold mt-6 mb-2">Starting plan:</h4>
            <ol class="list-decimal ml-5 space-y-2">
                <li><b>Computer or laptop</b></li>
                <li><b>Code editor</b>: Notepad++ (for beginners) or VS Code</li>
                <li><b>Concentration</b></li>
            </ol>
        `
    }
};

tasksData["1.2"] = {
    title: "1.2",
    content: {
        ua: `
            <h3 class="text-xl font-bold mb-4 text-accent">Структура HTML-документа</h3>
            <p class="mb-4">Кожен HTML-сайт складається з двох головних частин:</p>
            <ul class="list-disc ml-5 mb-4 space-y-2">
                <li><b>&lt;head&gt;</b> — «голова» сайту. Тут підключаються стилі, скрипти, вказується заголовок сторінки, кодування тощо.</li>
                <li><b>&lt;body&gt;</b> — «тіло» сайту. Тут пишеться все, що бачить користувач.</li>
            </ul>
            <p class="mb-3">Ось мінімальний шаблон:</p>
            <pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm mb-4">&lt;!DOCTYPE html&gt;
&lt;html lang="uk"&gt;
&lt;head&gt;
    &lt;meta charset="UTF-8"&gt;
    &lt;meta name="viewport" content="width=device-width, initial-scale=1.0"&gt;
    &lt;title&gt;Мій перший сайт&lt;/title&gt;
&lt;/head&gt;
&lt;body&gt;
    
&lt;/body&gt;
&lt;/html&gt;</pre>
            <p><b>Завдання:</b> Створи файл <code>index.html</code>, встав цей код і відкрий його в браузері.</p>
        `,
        en: `
            <h3 class="text-xl font-bold mb-4 text-accent">HTML Document Structure</h3>
            <p class="mb-4">Every HTML page has two main parts: <b>&lt;head&gt;</b> and <b>&lt;body&gt;</b>.</p>
            <pre class="bg-black/30 p-4 rounded-xl text-sm mb-4">&lt;!DOCTYPE html&gt;
&lt;html lang="en"&gt;
&lt;head&gt;
    &lt;meta charset="UTF-8"&gt;
    &lt;title&gt;My first website&lt;/title&gt;
&lt;/head&gt;
&lt;body&gt;
    
&lt;/body&gt;
&lt;/html&gt;</pre>
            <p><b>Task:</b> Create index.html with this code.</p>
        `
    }
};

tasksData["2.1"] = {
    title: "2.1",
    content: {
        ua: `
            <h3 class="text-xl font-bold mb-4 text-accent">Заголовки h1–h6</h3>
            <p class="mb-4">У HTML є 6 рівнів заголовків:</p>
            <ul class="list-disc ml-5 mb-4 space-y-1">
                <li><code>&lt;h1&gt;</code> — найголовніший (бажано один на сторінку)</li>
                <li><code>&lt;h2&gt;</code> — підзаголовок</li>
                <li><code>&lt;h3&gt;</code> — ще менший</li>
                <li><code>&lt;h4&gt;</code>, <code>&lt;h5&gt;</code>, <code>&lt;h6&gt;</code></li>
            </ul>
            <pre class="bg-black/30 p-4 rounded-xl text-sm">&lt;h1&gt;Головний заголовок&lt;/h1&gt;
&lt;h2&gt;Підзаголовок&lt;/h2&gt;
&lt;h3&gt;Менший заголовок&lt;/h3&gt;</pre>
        `
    }
};

tasksData["2.2"] = {
    title: "2.2",
    content: {
        ua: `
            <h3 class="text-xl font-bold mb-4 text-accent">Практика: Заголовки</h3>
            <p class="mb-3">Створи сторінку з такою структурою:</p>
            <ul class="list-disc ml-5 mb-4">
                <li><code>&lt;h1&gt;</code> — «Мій перший сайт»</li>
                <li><code>&lt;h2&gt;</code> — «Про мене»</li>
                <li><code>&lt;h3&gt;</code> — «Мої захоплення»</li>
            </ul>
            <p>Збережи файл і відкрий у браузері.</p>
        `
    }
};

tasksData["3.1"] = {
    title: "3.1",
    content: {
        ua: `
            <h3 class="text-xl font-bold mb-4 text-accent">Абзаци та перенос рядка</h3>
            <ul class="list-disc ml-5 mb-4 space-y-2">
                <li><code>&lt;p&gt;</code> — абзац тексту</li>
                <li><code>&lt;br&gt;</code> — примусовий перенос рядка</li>
                <li><code>&lt;hr&gt;</code> — горизонтальна лінія</li>
            </ul>
            <pre class="bg-black/30 p-4 rounded-xl text-sm">&lt;p&gt;Це перший абзац.&lt;/p&gt;
&lt;p&gt;Це другий абзац.&lt;/p&gt;
&lt;p&gt;Рядок один&lt;br&gt;Рядок два&lt;/p&gt;
&lt;hr&gt;</pre>
        `
    }
};

tasksData["3.2"] = {
    title: "3.2",
    content: {
        ua: `
            <h3 class="text-xl font-bold mb-4 text-accent">Практика</h3>
            <p>Напиши невелику історію про себе (4–6 речень), використовуючи кілька тегів <code>&lt;p&gt;</code> та хоча б один <code>&lt;br&gt;</code>.</p>
        `
    }
};

tasksData["4.1"] = {
    title: "4.1",
    content: {
        ua: `
            <h3 class="text-xl font-bold mb-4 text-accent">Форматування тексту</h3>
            <ul class="list-disc ml-5 space-y-2">
                <li><code>&lt;b&gt;</code> / <code>&lt;strong&gt;</code> — <b>жирний</b></li>
                <li><code>&lt;i&gt;</code> / <code>&lt;em&gt;</code> — <i>курсив</i></li>
                <li><code>&lt;u&gt;</code> — <u>підкреслений</u></li>
                <li><code>&lt;mark&gt;</code> — <mark>виділений</mark></li>
                <li><code>&lt;small&gt;</code> — маленький текст</li>
                <li><code>&lt;del&gt;</code> — <del>закреслений</del></li>
            </ul>
        `
    }
};

tasksData["4.2"] = {
    title: "4.2",
    content: {
        ua: `
            <h3 class="text-xl font-bold mb-4 text-accent">Практика</h3>
            <p>Зроби текст, в якому будуть використані: жирний, курсив, підкреслення і виділення маркером.</p>
        `
    }
};

tasksData["5.1"] = {
    title: "5.1",
    content: {
        ua: `
            <h3 class="text-xl font-bold mb-4 text-accent">Списки</h3>
            <p class="mb-2"><b>Маркований список:</b></p>
            <pre class="bg-black/30 p-4 rounded-xl text-sm mb-4">&lt;ul&gt;
  &lt;li&gt;Пункт 1&lt;/li&gt;
  &lt;li&gt;Пункт 2&lt;/li&gt;
&lt;/ul&gt;</pre>
            <p class="mb-2"><b>Нумерований список:</b></p>
            <pre class="bg-black/30 p-4 rounded-xl text-sm">&lt;ol&gt;
  &lt;li&gt;Перший&lt;/li&gt;
  &lt;li&gt;Другий&lt;/li&gt;
&lt;/ol&gt;</pre>
        `
    }
};

tasksData["5.2"] = {
    title: "5.2",
    content: {
        ua: `
            <h3 class="text-xl font-bold mb-4 text-accent">Практика</h3>
            <p>Створи два списки:</p>
            <ol class="list-decimal ml-5">
                <li>Список улюблених фільмів (маркований)</li>
                <li>Список справ на сьогодні (нумерований)</li>
            </ol>
        `
    }
};

tasksData["6.1"] = {
    title: "6.1",
    content: {
        ua: `
            <h3 class="text-xl font-bold mb-4 text-accent">Посилання</h3>
            <p class="mb-3">Тег <code>&lt;a&gt;</code> створює посилання.</p>
            <pre class="bg-black/30 p-4 rounded-xl text-sm mb-4">&lt;a href="https://google.com"&gt;Перейти в Google&lt;/a&gt;

&lt;a href="https://youtube.com" target="_blank"&gt;Відкрити YouTube в новій вкладці&lt;/a&gt;</pre>
            <p><code>target="_blank"</code> відкриває посилання в новій вкладці.</p>
        `
    }
};

tasksData["6.2"] = {
    title: "6.2",
    content: {
        ua: `
            <h3 class="text-xl font-bold mb-4 text-accent">Практика</h3>
            <p>Зроби три посилання:</p>
            <ul class="list-disc ml-5">
                <li>На Google</li>
                <li>На YouTube (відкривається в новій вкладці)</li>
                <li>На будь-який інший сайт</li>
            </ul>
        `
    }
};

tasksData["7.1"] = {
    title: "7.1",
    content: {
        ua: `
            <h3 class="text-xl font-bold mb-4 text-accent">Зображення</h3>
            <p class="mb-3">Тег <code>&lt;img&gt;</code> додає картинку.</p>
            <pre class="bg-black/30 p-4 rounded-xl text-sm mb-4">&lt;img src="photo.jpg" alt="Опис фото" width="300"&gt;</pre>
            <ul class="list-disc ml-5 mt-3 space-y-1">
                <li><code>src</code> — шлях до файлу</li>
                <li><code>alt</code> — опис (якщо картинка не завантажилась)</li>
                <li><code>width</code> / <code>height</code> — розмір</li>
            </ul>
        `
    }
};

tasksData["7.2"] = {
    title: "7.2",
    content: {
        ua: `
            <h3 class="text-xl font-bold mb-4 text-accent">Практика</h3>
            <p>Додай на сторінку будь-яке зображення (можна взяти з інтернету за прямим посиланням).</p>
            <p>Приклад:</p>
            <pre class="bg-black/30 p-4 rounded-xl text-sm">&lt;img src="https://picsum.photos/400/250" alt="Випадкове фото"&gt;</pre>
        `
    }
};

tasksData["8.1"] = {
    title: "8.1",
    content: {
        ua: `
            <h3 class="text-xl font-bold mb-4 text-accent">Таблиці</h3>
            <p class="mb-3">Основні теги:</p>
            <ul class="list-disc ml-5 mb-4">
                <li><code>&lt;table&gt;</code> — таблиця</li>
                <li><code>&lt;tr&gt;</code> — рядок</li>
                <li><code>&lt;td&gt;</code> — комірка</li>
                <li><code>&lt;th&gt;</code> — заголовок комірки</li>
            </ul>
            <pre class="bg-black/30 p-4 rounded-xl text-sm">&lt;table border="1"&gt;
  &lt;tr&gt;
    &lt;th&gt;Ім’я&lt;/th&gt;
    &lt;th&gt;Вік&lt;/th&gt;
  &lt;/tr&gt;
  &lt;tr&gt;
    &lt;td&gt;Олексій&lt;/td&gt;
    &lt;td&gt;25&lt;/td&gt;
  &lt;/tr&gt;
&lt;/table&gt;</pre>
        `
    }
};

tasksData["8.2"] = {
    title: "8.2",
    content: {
        ua: `
            <h3 class="text-xl font-bold mb-4 text-accent">Практика</h3>
            <p>Створи таблицю 3×3 (три рядки і три стовпці) з будь-якими даними.</p>
        `
    }
};

tasksData["9.1"] = {
    title: "9.1",
    content: {
        ua: `
            <h3 class="text-xl font-bold mb-4 text-accent">Форми (початок)</h3>
            <p class="mb-3">Форма потрібна, щоб користувач міг щось вводити.</p>
            <pre class="bg-black/30 p-4 rounded-xl text-sm mb-4">&lt;form&gt;
  &lt;input type="text" placeholder="Ваше ім’я"&gt;
  &lt;br&gt;&lt;br&gt;
  &lt;button type="submit"&gt;Надіслати&lt;/button&gt;
&lt;/form&gt;</pre>
            <p>Основні типи <code>input</code>: text, password, email, number, checkbox, radio.</p>
        `
    }
};

tasksData["9.2"] = {
    title: "9.2",
    content: {
        ua: `
            <h3 class="text-xl font-bold mb-4 text-accent">Практика</h3>
            <p>Зроби форму з такими полями:</p>
            <ul class="list-disc ml-5">
                <li>Ім’я (text)</li>
                <li>Email</li>
                <li>Пароль</li>
                <li>Кнопка «Зареєструватися»</li>
            </ul>
        `
    }
};

tasksData["10.1"] = {
    title: "10.1",
    content: {
        ua: `
            <h3 class="text-xl font-bold mb-4 text-accent">Семантичні теги</h3>
            <p class="mb-3">Сучасний HTML використовує семантичні теги замість просто <code>div</code>:</p>
            <ul class="list-disc ml-5 space-y-1">
                <li><code>&lt;header&gt;</code> — шапка сайту</li>
                <li><code>&lt;nav&gt;</code> — навігація</li>
                <li><code>&lt;main&gt;</code> — основний контент</li>
                <li><code>&lt;section&gt;</code> — секція</li>
                <li><code>&lt;article&gt;</code> — стаття</li>
                <li><code>&lt;footer&gt;</code> — підвал сайту</li>
            </ul>
        `
    }
};

tasksData["10.2"] = {
    title: "10.2",
    content: {
        ua: `
            <h3 class="text-xl font-bold mb-4 text-accent">Практика</h3>
            <p>Зроби просту структуру сторінки:</p>
            <pre class="bg-black/30 p-4 rounded-xl text-sm">&lt;header&gt;
  &lt;h1&gt;Мій сайт&lt;/h1&gt;
&lt;/header&gt;

&lt;nav&gt;
  &lt;a href="#"&gt;Головна&lt;/a&gt; |
  &lt;a href="#"&gt;Про мене&lt;/a&gt;
&lt;/nav&gt;

&lt;main&gt;
  &lt;section&gt;
    &lt;h2&gt;Про мене&lt;/h2&gt;
    &lt;p&gt;Тут текст...&lt;/p&gt;
  &lt;/section&gt;
&lt;/main&gt;

&lt;footer&gt;
  &lt;p&gt;© 2025 Мій сайт&lt;/p&gt;
&lt;/footer&gt;</pre>
        `
    }
};

for (let i = 11; i <= 36; i++) {
    tasksData[`${i}.1`] = {
        title: `${i}.1`,
        content: { ua: `<p class="text-muted">Теоретичний матеріал до завдання ${i} з’явиться найближчим часом.</p>` }
    };
    tasksData[`${i}.2`] = {
        title: `${i}.2`,
        content: { ua: `<p class="text-muted">Практичне завдання ${i} з’явиться найближчим часом.</p>` }
    };
}

function getTaskContent(key) {
    const task = tasksData[key];
    if (!task) return translations[currentLang].in_development;
    if (typeof task.content === "object") {
        return task.content[currentLang] || task.content.ua || translations[currentLang].in_development;
    }
    return task.content || translations[currentLang].in_development;
}

function getTaskTitle(key) {
    const task = tasksData[key];
    const tr = translations[currentLang];
    if (!task) return key;
    return `${key} ${key.endsWith('.1') ? tr.theory : tr.practice}`;
}

function renderTasks() {
    const container = document.getElementById("taskList");
    const tr = translations[currentLang];
    container.innerHTML = "";

    for (let i = 1; i <= 36; i++) {
        container.innerHTML += `
            <div onclick="toggleTask(${i})" class="task-item">
                ${tr.task} ${i}
            </div>
            <div id="subtasks-${i}" class="hidden ml-4 space-y-1 mb-1">
                <div onclick="openTask(${i},1,this)" class="subtask-item">${i}.1 ${tr.theory}</div>
                <div onclick="openTask(${i},2,this)" class="subtask-item">${i}.2 ${tr.practice}</div>
            </div>`;
    }
}

let activeSubtask = null;

function toggleTask(n) {
    document.getElementById(`subtasks-${n}`).classList.toggle('hidden');
}

function openTask(t, s, el) {
    const key = `${t}.${s}`;

    if (activeSubtask) activeSubtask.classList.remove('active');
    activeSubtask = el;
    el.classList.add('active');

    document.getElementById("taskArea").innerHTML = `
        <div class="max-w-2xl mx-auto task-card rounded-3xl p-10">
            <h2 class="text-3xl font-bold mb-6 text-accent">${getTaskTitle(key)}</h2>
            <div class="task-content rounded-2xl p-8 leading-relaxed">
                ${getTaskContent(key)}
            </div>
        </div>
    `;
}

function showSuggestions() {
    const input = document.getElementById("searchInput").value.trim().toLowerCase();
    const box = document.getElementById("suggestions");
    box.innerHTML = "";
    box.classList.add("hidden");

    if (!input) return;

    let found = false;
    const tr = translations[currentLang];

    for (let t = 1; t <= 36; t++) {
        for (let s = 1; s <= 2; s++) {
            const key = `${t}.${s}`;
            if (key.startsWith(input) || `${tr.task.toLowerCase()} ${key}`.includes(input)) {
                found = true;
                const div = document.createElement("div");
                div.className = "px-5 py-3 cursor-pointer";
                div.style.borderBottom = "1px solid var(--border)";
                div.textContent = `${tr.task} ${key}`;
                div.onclick = () => {
                    const subtasksDiv = document.getElementById(`subtasks-${t}`);
                    if (subtasksDiv) subtasksDiv.classList.remove('hidden');
                    const subEl = document.querySelector(`#subtasks-${t} .subtask-item:nth-child(${s})`);
                    if (subEl) openTask(t, s, subEl);
                    box.classList.add("hidden");
                    document.getElementById("searchInput").value = "";
                };
                box.appendChild(div);
            }
        }
    }

    if (found) box.classList.remove("hidden");
}

function toggleSettings() {
    document.getElementById("settingsPanel").classList.toggle("translate-x-full");
}

function tab(n) {
    document.getElementById("langTab").classList.toggle("hidden", n !== 0);
    document.getElementById("designTab").classList.toggle("hidden", n !== 1);
}

function setTheme(t) {
    document.cookie = `theme=${t};path=/;max-age=31536000`;
    location.reload();
}

function setLang(l) {
    document.cookie = `lang=${l};path=/;max-age=31536000`;
    location.reload();
}

function highlightActive() {
    document.querySelectorAll('[data-lang]').forEach(el => {
        el.classList.toggle('active', el.dataset.lang === currentLang);
    });
    document.querySelectorAll('[data-theme]').forEach(el => {
        el.classList.toggle('active', el.dataset.theme === currentTheme);
    });
}

function init() {
    const tr = translations[currentLang];

    document.getElementById("logo").textContent = tr.logo;
    document.getElementById("subtitle").textContent = tr.subtitle;
    document.getElementById("welcome").innerHTML = `${tr.welcome}, <?= htmlspecialchars($user['name'] ?? $user['username']) ?>`;
    document.getElementById("welcome_sub").textContent = tr.welcome_sub;
    document.getElementById("settings_title").textContent = tr.settings_title;
    document.getElementById("searchInput").placeholder = tr.search_placeholder;
    document.getElementById("tab-lang").textContent = tr.tab_lang;
    document.getElementById("tab-design").textContent = tr.tab_design;

    renderTasks();
    highlightActive();
}

window.onload = init;
</script>
</body>
</html>