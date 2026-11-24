<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once 'db.php';

$userId = (int)$_SESSION['user_id'];
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$isAdmin = (($user['role'] ?? '') === 'admin') || ((int)($user['is_admin'] ?? 0) === 1);

$courseNames = [
    'html_css' => 'HTML + CSS',
    'python' => 'Python',
    'php' => 'PHP',
    'javascript' => 'JavaScript',
    'cpp' => 'C++'
];
$courseLessonCounts = ['html_css' => 30, 'python' => 12, 'php' => 12, 'javascript' => 12, 'cpp' => 12];
if (empty($_SESSION['course_csrf'])) $_SESSION['course_csrf'] = bin2hex(random_bytes(32));
$courseCsrf = $_SESSION['course_csrf'];
$courseDbReady = false;
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_course_progress (
        user_id INT NOT NULL PRIMARY KEY,
        active_course VARCHAR(32) NOT NULL DEFAULT 'html_css',
        last_task VARCHAR(16) DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $courseDbReady = true;
} catch (Throwable $ex) {
    error_log('Course progress table error: ' . $ex->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['course_api'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!$courseDbReady) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Не вдалося підготувати таблицю курсів. Перевір права MySQL.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload) || !hash_equals($courseCsrf, (string)($payload['csrf'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Сесія застаріла. Онови сторінку.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $action = (string)($payload['action'] ?? '');
    $courseId = (string)($payload['course'] ?? '');
    if (!isset($courseNames[$courseId])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Невідомий курс.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        if ($action === 'save_course') {
            $q = $pdo->prepare('INSERT INTO user_course_progress (user_id, active_course, last_task) VALUES (?, ?, NULL) ON DUPLICATE KEY UPDATE active_course = VALUES(active_course), last_task = NULL');
            $q->execute([$userId, $courseId]);
        } elseif ($action === 'save_task') {
            $taskKey = (string)($payload['task'] ?? '');
            if (!preg_match('/^(\d{1,2})\.([12])$/', $taskKey, $match) || (int)$match[1] < 1 || (int)$match[1] > $courseLessonCounts[$courseId]) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'message' => 'Некоректний номер завдання.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $q = $pdo->prepare('INSERT INTO user_course_progress (user_id, active_course, last_task) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE active_course = VALUES(active_course), last_task = VALUES(last_task)');
            $q->execute([$userId, $courseId, $taskKey]);
        } else {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Невідома дія.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $ex) {
        error_log('Course progress save error: ' . $ex->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Не вдалося зберегти вибір курсу.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$activeCourseFromDb = 'html_css';
$lastTaskFromDb = '';
if ($courseDbReady) {
    try {
        $q = $pdo->prepare('SELECT active_course, last_task FROM user_course_progress WHERE user_id = ? LIMIT 1');
        $q->execute([$userId]);
        $courseRow = $q->fetch(PDO::FETCH_ASSOC);
        if ($courseRow) {
            if (isset($courseNames[$courseRow['active_course'] ?? ''])) $activeCourseFromDb = $courseRow['active_course'];
            $lastTaskFromDb = (string)($courseRow['last_task'] ?? '');
        } else {
            $q = $pdo->prepare('INSERT INTO user_course_progress (user_id, active_course, last_task) VALUES (?, ?, NULL)');
            $q->execute([$userId, 'html_css']);
        }
    } catch (Throwable $ex) {
        error_log('Course progress load error: ' . $ex->getMessage());
    }
}


$defaults = [
    'theme' => 'dark',
    'language' => 'ua',
    'font' => 'Arial',
    'fontSize' => 16,
    'compact' => false,
    'animations' => true,
    'custom' => [],
    'images' => ['site' => '', 'header' => '', 'card' => '', 'answer' => '']
];

$presets = [
    'dark' => ['bg-main'=>'#0b0f19','bg-sidebar'=>'#111827','bg-header'=>'#111827','bg-card'=>'#1f2937','bg-input'=>'#1f2937','bg-hover'=>'#374151','bg-active'=>'#1e293b','text-main'=>'#e5e7eb','text-muted'=>'#9ca3af','text-accent'=>'#60a5fa','border'=>'#374151'],
    'light' => ['bg-main'=>'#f3f4f6','bg-sidebar'=>'#ffffff','bg-header'=>'#ffffff','bg-card'=>'#ffffff','bg-input'=>'#f9fafb','bg-hover'=>'#e5e7eb','bg-active'=>'#dbeafe','text-main'=>'#111827','text-muted'=>'#6b7280','text-accent'=>'#2563eb','border'=>'#d1d5db'],
    'metal' => ['bg-main'=>'#17191d','bg-sidebar'=>'#24272d','bg-header'=>'#292d33','bg-card'=>'#343941','bg-input'=>'#252930','bg-hover'=>'#454b55','bg-active'=>'#3b424c','text-main'=>'#f1f5f9','text-muted'=>'#b0b8c4','text-accent'=>'#9ca3af','border'=>'#515965'],
    'wood' => ['bg-main'=>'#24180f','bg-sidebar'=>'#302014','bg-header'=>'#382719','bg-card'=>'#493323','bg-input'=>'#382719','bg-hover'=>'#5b412c','bg-active'=>'#533a26','text-main'=>'#f8ead7','text-muted'=>'#d0b99c','text-accent'=>'#e9ad68','border'=>'#654a31'],
    'sand' => ['bg-main'=>'#e9dfcc','bg-sidebar'=>'#d8c9ae','bg-header'=>'#e1d3ba','bg-card'=>'#f5eddf','bg-input'=>'#fffaf0','bg-hover'=>'#d4c2a3','bg-active'=>'#e7d2ad','text-main'=>'#332719','text-muted'=>'#76634c','text-accent'=>'#8a5727','border'=>'#c4b08f'],
    'neon' => ['bg-main'=>'#090b17','bg-sidebar'=>'#111126','bg-header'=>'#14142c','bg-card'=>'#1b1b38','bg-input'=>'#111126','bg-hover'=>'#29294d','bg-active'=>'#252546','text-main'=>'#f2f0ff','text-muted'=>'#b6b2d6','text-accent'=>'#a78bfa','border'=>'#454078']
];

$settings = $defaults;
try {
    $stmt = $pdo->prepare('SELECT settings_json FROM user_settings WHERE user_id = ?');
    $stmt->execute([$userId]);
    $json = $stmt->fetchColumn();
    if ($json) {
        $stored = json_decode($json, true);
        if (is_array($stored)) $settings = array_replace_recursive($defaults, $stored);
    }
} catch (Throwable $ex) {
    // Settings are optional until the settings page has saved them.
}

$theme = $settings['theme'] ?? 'dark';
if (!isset($presets[$theme])) $theme = 'dark';
$colors = $presets[$theme];

if (is_array($settings['custom'] ?? null)) {
    foreach ($colors as $key => $defaultColor) {
        $candidate = $settings['custom'][$key] ?? $defaultColor;
        if (is_string($candidate) && preg_match('/^#[0-9a-fA-F]{6}$/', $candidate)) {
            $colors[$key] = $candidate;
        }
    }
}

$allowedFonts = ['Arial','Verdana','Tahoma','Georgia','Trebuchet MS','Courier New'];
$font = in_array($settings['font'] ?? 'Arial', $allowedFonts, true) ? $settings['font'] : 'Arial';
$fontSize = max(12, min(24, (int)($settings['fontSize'] ?? 16)));
$css = [];
foreach ($colors as $key => $value) $css[] = '--' . $key . ':' . $value;
$css[] = '--site-font:' . json_encode($font, JSON_UNESCAPED_UNICODE);
$css[] = '--site-font-size:' . $fontSize . 'px';
$css[] = '--accent-color:' . (preg_match('/^#[0-9a-fA-F]{6}$/', (string)($user['theme_color'] ?? '')) ? $user['theme_color'] : ($colors['text-accent'] ?? '#7c9cff'));

$userIdForPath = (string)$userId;
$imageVariables = ['site'=>'site-bg-image','header'=>'header-bg-image','card'=>'card-bg-image','answer'=>'answer-bg-image'];
foreach ($imageVariables as $slot => $variable) {
    $url = $settings['images'][$slot] ?? '';
    $safeImage = 'none';
    if (is_string($url) && preg_match('#^uploads/theme_images/' . preg_quote($userIdForPath, '#') . '/[a-f0-9]{32}\.(jpg|png|webp|gif)$#', $url)) {
        $safeImage = 'url(' . json_encode($url, JSON_UNESCAPED_SLASHES) . ')';
    }
    $css[] = '--' . $variable . ':' . $safeImage;
}

$bodyClass = 'theme-' . $theme;
if (!empty($settings['compact'])) $bodyClass .= ' compact-mode';
if (empty($settings['animations'])) $bodyClass .= ' no-animations';

$allowedLanguages = ['ua','en','de','fr','es','pl','zh','ja'];
$lang = in_array($settings['language'] ?? 'ua', $allowedLanguages, true) ? $settings['language'] : 'ua';

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="<?= h($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IT Tasks Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        body {
            <?= implode(";\n", $css) ?>
        }
        .course-picker{margin:0 0 22px;padding:20px;border:1px solid var(--border);border-radius:18px;background:var(--bg-card);box-shadow:0 10px 28px rgba(0,0,0,.07)}
        .course-picker-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:16px}
        .course-picker-head h2{margin:0 0 5px;font-size:19px;font-weight:800;color:var(--text-main)}
        .course-picker-head p{margin:0;color:var(--text-muted);font-size:12px;line-height:1.6}
        .current-course-label{display:inline-flex;align-items:center;gap:7px;padding:8px 11px;border-radius:999px;border:1px solid var(--border);background:var(--bg-active);color:var(--text-main);font-size:12px;font-weight:750;white-space:nowrap}
        .course-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:11px}
        .course-card{display:flex;min-width:0;flex-direction:column;align-items:flex-start;gap:8px;padding:14px 12px;text-align:left;border:1px solid var(--border);border-radius:14px;background:var(--bg-main);color:var(--text-main);cursor:pointer;transition:border-color .18s,transform .18s,background .18s;}
        .course-card:hover{transform:translateY(-2px);border-color:var(--text-accent)}
        .course-card.selected{border-color:var(--text-accent);background:var(--bg-active);box-shadow:inset 0 0 0 1px var(--text-accent)}
        .course-card-icon{display:grid;place-items:center;width:37px;height:37px;border-radius:11px;background:var(--bg-hover);font-size:20px}
        .course-card-title{font-size:14px;font-weight:800;line-height:1.3}
        .course-card-desc{color:var(--text-muted);font-size:11px;line-height:1.5;min-height:49px}
        .course-card-level{color:var(--text-muted);font-size:10px}
        .course-card-selected{margin-top:auto;padding-top:6px;color:var(--text-accent);font-weight:800;font-size:11px}
        .course-status{margin-top:13px;color:var(--text-muted);font-size:11px;line-height:1.5}
        @media(max-width:1100px){.course-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.course-card-desc{min-height:0}}
        @media(max-width:650px){.course-picker{padding:14px}.course-picker-head{flex-direction:column}.course-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.course-card{padding:12px 10px}.course-card-desc{min-height:0}}
    </style>
</head>
<body class="<?= h($bodyClass) ?>">
<div class="app-shell">
    <aside class="sidebar">
        <a class="brand" href="index.php">
            <span class="brand-logo">IT</span>
            <span class="brand-text" id="logo">IT <b>Tasks Pro</b></span>
        </a>
        <div class="sidebar-label" id="sidebarLabel">НАВЧАННЯ</div>
        <div class="search-wrap">
            <span class="search-symbol">⌕</span>
            <input id="searchInput" oninput="showSuggestions()" class="search-input" placeholder="" autocomplete="off">
        </div>
        <div id="suggestions" class="suggestions hidden"></div>
        <div class="task-list" id="taskList"></div>
        <div class="sidebar-bottom">
            <div class="sidebar-status"><span></span><b id="statusText">Система готова</b></div>
            <div class="sidebar-version">IT Tasks Pro · Learning workspace</div>
        </div>
    </aside>
    <main class="main-content">
        <header class="topbar">
            <div class="topbar-copy">
                <div class="eyebrow" id="topEyebrow">ТВІЙ ПРОСТІР ДЛЯ НАВЧАННЯ</div>
                <h1 id="welcome"></h1>
                <p class="page-subtitle" id="welcome_sub"></p>
            </div>
            <div class="topbar-actions">
                <a class="icon-action" href="settings.php" title="Налаштування" aria-label="Налаштування">⚙</a>
                <a class="action-link" href="meeting.php" title="Онлайн-зустріч"><span>📹</span><span>Зустріч</span></a>
                <?php if ($isAdmin): ?><a class="action-link" href="admin_users.php" title="Керування користувачами"><span>🛡️</span><span>Адмін</span></a><?php endif; ?>
                <a class="action-link" href="chat.php"><span>🤖</span><span id="aiText">AI-помічник</span></a>
                <a class="profile-link" href="profile.php"><span class="profile-avatar">👤</span><span><?= h($user['name'] ?? $user['username']) ?></span></a>
            </div>
        </header>
        <section class="course-picker" aria-labelledby="coursePickerTitle">
            <div class="course-picker-head">
                <div><h2 id="coursePickerTitle">Мої курси</h2><p>Обери курс для навчання. Твій вибір і останнє відкрите завдання збережуться в акаунті.</p></div>
                <span class="current-course-label" id="currentCourseLabel">🌐 HTML + CSS</span>
            </div>
            <div class="course-grid" id="courseSelector"></div>
            <div class="course-status" id="courseStatus" role="status" aria-live="polite">Курс зберігається в акаунті.</div>
        </section>
        <div id="introSections">
        <section class="welcome-card">
            <div class="welcome-copy">
                <span class="welcome-tag" id="welcomeTag">LEARN · BUILD · GROW</span>
                <h2 id="heroTitle">Почни з малого. Створи щось велике.</h2>
                <p id="heroText">Обирай завдання зліва, вивчай теорію та закріплюй знання практикою.</p>
            </div>
            <div class="welcome-art" aria-hidden="true"><div class="art-orbit"></div><div class="art-orbit orbit-two"></div><div class="art-core">&lt;/&gt;</div></div>
        </section>
        <section class="workspace-heading">
            <div><h2 id="workspaceTitle">Навчальні завдання</h2><p id="workspaceHint">Обери тему в меню або скористайся пошуком.</p></div>
            <span class="task-count" id="taskCount">36 тем · 72 завдання</span>
        </section>
        </div>
        <section id="taskArea" class="task-area">
            <div class="empty-welcome"><div class="empty-welcome-icon">⌘</div><h3 id="emptyTitle">З чого почнемо?</h3><p id="emptyText">Вибери тему в лівому меню, а потім відкрий теорію або практичне завдання.</p></div>
        </section>
        <footer class="page-footer"><span>© <?= date('Y') ?> IT Tasks Pro</span><span id="footerText">Створено для навчання та розвитку</span></footer>
    </main>
</div>
<script>
const translations = {
    ua: { logo:"IT Tasks Pro", subtitle:"HTML • CSS • JS • PHP • Бази даних", welcome:"Вітаємо", welcome_sub:"Продовжуй навчатися та створювати нове.", search_placeholder:"Пошук за номером, наприклад 1.1", task:"Тема", theory:"Теорія", practice:"Практика", in_development:"Завдання в розробці...", sidebar:"НАВЧАННЯ", status:"Система готова", eyebrow:"ТВІЙ ПРОСТІР ДЛЯ НАВЧАННЯ", ai:"AI-помічник", heroTitle:"Почни з малого. Створи щось велике.", heroText:"Обирай завдання зліва, вивчай теорію та закріплюй знання практикою.", workspace:"Навчальні завдання", hint:"Обери тему в меню або скористайся пошуком.", count:"36 тем · 72 завдання", emptyTitle:"З чого почнемо?", emptyText:"Вибери тему в лівому меню, а потім відкрий теорію або практичне завдання.", footer:"Створено для навчання та розвитку" },
    en: { logo:"IT Tasks Pro", subtitle:"HTML • CSS • JS • PHP • Databases", welcome:"Welcome", welcome_sub:"Keep learning and building something new.", search_placeholder:"Search by number, e.g. 1.1", task:"Topic", theory:"Theory", practice:"Practice", in_development:"This task is coming soon...", sidebar:"LEARNING", status:"System ready", eyebrow:"YOUR LEARNING SPACE", ai:"AI assistant", heroTitle:"Start small. Build something great.", heroText:"Choose a task on the left, learn the theory, and practise your skills.", workspace:"Learning tasks", hint:"Choose a topic or use search.", count:"36 topics · 72 tasks", emptyTitle:"Where shall we start?", emptyText:"Choose a topic in the left menu, then open a theory or practice task.", footer:"Built for learning and growth" },
    de: { logo:"IT Tasks Pro", subtitle:"HTML • CSS • JS • PHP • Datenbanken", welcome:"Willkommen", welcome_sub:"Lerne weiter und erschaffe etwas Neues.", search_placeholder:"Nach Nummer suchen, z. B. 1.1", task:"Thema", theory:"Theorie", practice:"Praxis", in_development:"Diese Aufgabe kommt bald...", sidebar:"LERNEN", status:"System bereit", eyebrow:"DEIN LERNBEREICH", ai:"KI-Assistent", heroTitle:"Klein anfangen. Großes schaffen.", heroText:"Wähle links eine Aufgabe, lerne die Theorie und übe deine Fähigkeiten.", workspace:"Lernaufgaben", hint:"Wähle ein Thema oder nutze die Suche.", count:"36 Themen · 72 Aufgaben", emptyTitle:"Womit fangen wir an?", emptyText:"Wähle links ein Thema und öffne dann Theorie oder Praxis.", footer:"Zum Lernen und Weiterentwickeln" },
    fr: { logo:"IT Tasks Pro", subtitle:"HTML • CSS • JS • PHP • Bases de données", welcome:"Bienvenue", welcome_sub:"Continuez à apprendre et à créer.", search_placeholder:"Rechercher par numéro, ex. 1.1", task:"Thème", theory:"Théorie", practice:"Pratique", in_development:"Cette tâche arrive bientôt...", sidebar:"APPRENTISSAGE", status:"Système prêt", eyebrow:"VOTRE ESPACE D’APPRENTISSAGE", ai:"Assistant IA", heroTitle:"Commencez petit. Créez grand.", heroText:"Choisissez une tâche, étudiez la théorie et pratiquez.", workspace:"Exercices", hint:"Choisissez un thème ou utilisez la recherche.", count:"36 thèmes · 72 tâches", emptyTitle:"Par où commencer ?", emptyText:"Choisissez un thème à gauche, puis ouvrez la théorie ou la pratique.", footer:"Conçu pour apprendre et progresser" },
    es: { logo:"IT Tasks Pro", subtitle:"HTML • CSS • JS • PHP • Bases de datos", welcome:"¡Bienvenido!", welcome_sub:"Sigue aprendiendo y creando cosas nuevas.", search_placeholder:"Buscar por número, p. ej. 1.1", task:"Tema", theory:"Teoría", practice:"Práctica", in_development:"Esta tarea estará disponible pronto...", sidebar:"APRENDIZAJE", status:"Sistema listo", eyebrow:"TU ESPACIO DE APRENDIZAJE", ai:"Asistente IA", heroTitle:"Empieza poco a poco. Crea algo grande.", heroText:"Elige una tarea, estudia la teoría y practica tus habilidades.", workspace:"Tareas de aprendizaje", hint:"Elige un tema o utiliza la búsqueda.", count:"36 temas · 72 tareas", emptyTitle:"¿Por dónde empezamos?", emptyText:"Elige un tema a la izquierda y abre la teoría o la práctica.", footer:"Creado para aprender y crecer" },
    pl: { logo:"IT Tasks Pro", subtitle:"HTML • CSS • JS • PHP • Bazy danych", welcome:"Witaj", welcome_sub:"Ucz się dalej i twórz nowe rzeczy.", search_placeholder:"Szukaj po numerze, np. 1.1", task:"Temat", theory:"Teoria", practice:"Praktyka", in_development:"To zadanie pojawi się wkrótce...", sidebar:"NAUKA", status:"System gotowy", eyebrow:"TWOJA PRZESTRZEŃ DO NAUKI", ai:"Asystent AI", heroTitle:"Zacznij od małych kroków. Twórz wielkie rzeczy.", heroText:"Wybierz zadanie, poznaj teorię i ćwicz swoje umiejętności.", workspace:"Zadania edukacyjne", hint:"Wybierz temat lub skorzystaj z wyszukiwania.", count:"36 tematów · 72 zadania", emptyTitle:"Od czego zaczniemy?", emptyText:"Wybierz temat po lewej, a następnie teorię lub praktykę.", footer:"Stworzone do nauki i rozwoju" },
    zh: { logo:"IT Tasks Pro", subtitle:"HTML • CSS • JS • PHP • 数据库", welcome:"欢迎", welcome_sub:"继续学习，创造新事物。", search_placeholder:"按编号搜索，例如 1.1", task:"主题", theory:"理论", practice:"练习", in_development:"此任务即将推出……", sidebar:"学习", status:"系统已就绪", eyebrow:"你的学习空间", ai:"AI 助手", heroTitle:"从小处开始，创造大成果。", heroText:"选择左侧任务，学习理论并通过练习巩固知识。", workspace:"学习任务", hint:"选择主题或使用搜索。", count:"36 个主题 · 72 项任务", emptyTitle:"从哪里开始？", emptyText:"在左侧选择主题，然后打开理论或练习任务。", footer:"为学习与成长而打造" },
    ja: { logo:"IT Tasks Pro", subtitle:"HTML • CSS • JS • PHP • データベース", welcome:"ようこそ", welcome_sub:"学び続け、新しいものを作りましょう。", search_placeholder:"番号で検索（例：1.1）", task:"トピック", theory:"理論", practice:"実践", in_development:"この課題は近日公開予定です…", sidebar:"学習", status:"システム準備完了", eyebrow:"あなたの学習スペース", ai:"AIアシスタント", heroTitle:"小さく始めて、大きく作ろう。", heroText:"左側から課題を選び、理論を学んで実践しましょう。", workspace:"学習課題", hint:"トピックを選ぶか検索してください。", count:"36 トピック · 72 課題", emptyTitle:"何から始めますか？", emptyText:"左側でトピックを選び、理論または実践課題を開いてください。", footer:"学習と成長のために" }
};
let currentLang = <?= json_encode($lang, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
let activeSubtask = null;
let currentTheme = <?= json_encode($theme, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
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
// Встав цей блок після tasksData["10.2"] у файлі, де вже створено tasksData.
Object.assign(tasksData, {
    "11.1": {
        "title": "11.1",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Блокові та рядкові елементи: div і span</h3>\n<p class=\"mb-4\"><code>&lt;div&gt;</code> — універсальний блок для групування елементів. Зазвичай він починається з нового рядка та займає доступну ширину.</p>\n<p class=\"mb-4\"><code>&lt;span&gt;</code> — рядковий контейнер для частини тексту або невеликого елемента. Він не створює нового рядка.</p>\n<pre class=\"bg-black/30 p-4 rounded-xl overflow-auto text-sm mb-4\">&lt;div&gt;\n  &lt;h2&gt;Про мене&lt;/h2&gt;\n  &lt;p&gt;Я вивчаю &lt;span&gt;HTML&lt;/span&gt;.&lt;/p&gt;\n&lt;/div&gt;</pre>\n<p><b>Пам’ятай:</b> контейнер допомагає об’єднувати елементи й оформлювати їх разом.</p>"
        }
    },
    "11.2": {
        "title": "11.2",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Практика: контейнер про себе</h3>\n<p class=\"mb-3\">Створи блок про себе, використовуючи <code>&lt;div&gt;</code>.</p>\n<ul class=\"list-disc ml-5 mb-4 space-y-1\"><li>Додай заголовок з ім’ям або псевдонімом.</li><li>Напиши два речення про свої захоплення.</li><li>За допомогою <code>&lt;span&gt;</code> виділи назву улюбленого предмета або мови програмування.</li></ul>\n<p>Відкрий файл у браузері й перевір, як елементи розташовані на сторінці.</p>"
        }
    },
    "12.1": {
        "title": "12.1",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Атрибути id та class</h3>\n<p class=\"mb-4\"><code>class</code> дає змогу призначити однакове оформлення кільком елементам. Одне ім’я класу можна повторювати.</p>\n<p class=\"mb-3\"><code>id</code> задає унікальний ідентифікатор елемента на сторінці. Його зручно використовувати для посилань, CSS та JavaScript.</p>\n<pre class=\"bg-black/30 p-4 rounded-xl overflow-auto text-sm\">&lt;h1 id=\"main-title\"&gt;Мій сайт&lt;/h1&gt;\n&lt;p class=\"description\"&gt;Перший абзац.&lt;/p&gt;\n&lt;p class=\"description\"&gt;Другий абзац.&lt;/p&gt;</pre>"
        }
    },
    "12.2": {
        "title": "12.2",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Практика: ідентифікатори та класи</h3>\n<p class=\"mb-3\">Створи сторінку з трьома блоками:</p>\n<ol class=\"list-decimal ml-5 mb-4 space-y-2\"><li>Головний заголовок з унікальним <code>id=\"main-title\"</code>.</li><li>Два абзаци з однаковим класом <code>description</code>.</li><li>Кнопку або посилання з класом <code>action</code>.</li></ol>\n<p>Перевір, що <code>id</code> не повторюється, а клас може бути в кількох елементів.</p>"
        }
    },
    "13.1": {
        "title": "13.1",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Аудіо та відео в HTML</h3>\n<p class=\"mb-3\">Тег <code>&lt;audio&gt;</code> додає аудіо, а <code>&lt;video&gt;</code> — відео. Атрибут <code>controls</code> показує кнопки керування.</p>\n<pre class=\"bg-black/30 p-4 rounded-xl overflow-auto text-sm mb-4\">&lt;audio controls&gt;\n  &lt;source src=\"music.mp3\" type=\"audio/mpeg\"&gt;\n  Ваш браузер не підтримує аудіо.\n&lt;/audio&gt;\n\n&lt;video controls width=\"480\"&gt;\n  &lt;source src=\"video.mp4\" type=\"video/mp4\"&gt;\n  Ваш браузер не підтримує відео.\n&lt;/video&gt;</pre>\n<p>Файли <code>music.mp3</code> і <code>video.mp4</code> мають існувати за вказаним шляхом.</p>"
        }
    },
    "13.2": {
        "title": "13.2",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Практика: медіафайли</h3>\n<p class=\"mb-3\">Створи окрему HTML-сторінку з розділом «Медіа».</p>\n<ul class=\"list-disc ml-5 mb-4 space-y-1\"><li>Додай заголовок і короткий опис.</li><li>Створи елемент <code>&lt;audio controls&gt;</code> для аудіофайлу.</li><li>Створи елемент <code>&lt;video controls&gt;</code> із шириною 400 пікселів.</li></ul>\n<p>Можеш використати власні файли. Якщо їх немає, спочатку перевір структуру елементів у коді.</p>"
        }
    },
    "14.1": {
        "title": "14.1",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Вбудовані сторінки: iframe</h3>\n<p class=\"mb-4\">Тег <code>&lt;iframe&gt;</code> дає змогу вбудувати іншу вебсторінку або дозволений вміст у свою сторінку.</p>\n<pre class=\"bg-black/30 p-4 rounded-xl overflow-auto text-sm mb-4\">&lt;iframe\n  src=\"https://example.com\"\n  title=\"Приклад вбудованої сторінки\"\n  width=\"600\"\n  height=\"350\"\n&gt;&lt;/iframe&gt;</pre>\n<p>Деякі сайти забороняють вбудовування, тому порожнє вікно не обов’язково означає помилку у твоєму HTML.</p>"
        }
    },
    "14.2": {
        "title": "14.2",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Практика: вбудований вміст</h3>\n<p class=\"mb-3\">Створи сторінку «Корисні ресурси».</p>\n<ol class=\"list-decimal ml-5 mb-4 space-y-2\"><li>Додай заголовок і пояснювальний абзац.</li><li>Створи <code>iframe</code> з атрибутами <code>title</code>, <code>width</code> і <code>height</code>.</li><li>Перевір результат у браузері.</li></ol>\n<p>Використовуй лише ресурси, які дозволяють вбудовування, або встав демонстраційну адресу <code>https://example.com</code>.</p>"
        }
    },
    "15.1": {
        "title": "15.1",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Коментарі та спеціальні символи</h3>\n<p class=\"mb-3\">HTML-коментар не відображається як звичайний текст на сторінці:</p>\n<pre class=\"bg-black/30 p-4 rounded-xl text-sm mb-4\">&lt;!-- Це коментар для розробника --&gt;\n&lt;p&gt;Цей текст видно на сторінці.&lt;/p&gt;</pre>\n<p class=\"mb-3\">Деякі символи записують за допомогою HTML-сутностей:</p>\n<ul class=\"list-disc ml-5 space-y-1\"><li><code>&amp;lt;</code> відображає <code>&lt;</code>.</li><li><code>&amp;gt;</code> відображає <code>&gt;</code>.</li><li><code>&amp;amp;</code> відображає знак <code>&amp;</code>.</li><li><code>&amp;copy;</code> відображає ©.</li></ul>"
        }
    },
    "15.2": {
        "title": "15.2",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Практика: коментарі та символи</h3>\n<p class=\"mb-3\">Створи нижню частину сайту з назвою «Мій навчальний проєкт».</p>\n<ul class=\"list-disc ml-5 mb-4 space-y-1\"><li>Додай HTML-коментар перед блоком.</li><li>Виведи символ © і назву свого сайту.</li><li>Додай абзац, у якому буквально показані символи <code>&lt;</code> та <code>&gt;</code> за допомогою сутностей.</li></ul>\n<p>Переглянь сторінку та переконайся, що коментар не видно користувачу.</p>"
        }
    },
    "16.1": {
        "title": "16.1",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Підключення CSS</h3>\n<p class=\"mb-3\">CSS відповідає за вигляд сторінки: кольори, відступи, розміри та розташування.</p>\n<p class=\"mb-3\">Зовнішній файл підключають у блоці <code>&lt;head&gt;</code>:</p>\n<pre class=\"bg-black/30 p-4 rounded-xl overflow-auto text-sm mb-4\">&lt;link rel=\"stylesheet\" href=\"style.css\"&gt;</pre>\n<p class=\"mb-3\">У файлі <code>style.css</code> можна написати:</p>\n<pre class=\"bg-black/30 p-4 rounded-xl overflow-auto text-sm\">body {\n  background-color: #f2f2f2;\n  color: #222222;\n  font-family: Arial, sans-serif;\n}</pre>"
        }
    },
    "16.2": {
        "title": "16.2",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Практика: перші стилі</h3>\n<p class=\"mb-3\">Створи файли <code>index.html</code> і <code>style.css</code> в одній папці.</p>\n<ol class=\"list-decimal ml-5 mb-4 space-y-2\"><li>Підключи CSS через тег <code>&lt;link&gt;</code>.</li><li>Встанови колір фону та колір тексту для <code>body</code>.</li><li>Зміни шрифт і додай відступ навколо сторінки.</li><li>Переконайся, що зміни видно після оновлення браузера.</li></ol>"
        }
    },
    "17.1": {
        "title": "17.1",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">CSS-селектори</h3>\n<p class=\"mb-3\">Селектор визначає, до яких елементів застосовуються стилі.</p>\n<pre class=\"bg-black/30 p-4 rounded-xl overflow-auto text-sm mb-4\">p {\n  color: #334155;\n}\n\n.card {\n  padding: 16px;\n}\n\n#main-title {\n  text-align: center;\n}</pre>\n<ul class=\"list-disc ml-5 space-y-1\"><li><code>p</code> — селектор тегу.</li><li><code>.card</code> — селектор класу.</li><li><code>#main-title</code> — селектор id.</li></ul>"
        }
    },
    "17.2": {
        "title": "17.2",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Практика: селектори</h3>\n<p class=\"mb-3\">Створи сторінку з заголовком, двома абзацами та двома картками.</p>\n<ul class=\"list-disc ml-5 mb-4 space-y-1\"><li>Задай загальний стиль для всіх абзаців.</li><li>Створи клас <code>.card</code> і признач його двом блокам.</li><li>Використай унікальний <code>id</code> для головного заголовка.</li><li>Зміни для заголовка розмір і вирівнювання.</li></ul>\n<p>Спробуй змінити один стиль і подивись, які елементи оновилися.</p>"
        }
    },
    "18.1": {
        "title": "18.1",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Кольори та фонові зображення</h3>\n<p class=\"mb-3\">У CSS кольори можна задавати назвою, HEX-кодом або функцією <code>rgb()</code>.</p>\n<pre class=\"bg-black/30 p-4 rounded-xl overflow-auto text-sm mb-4\">body {\n  background-color: #f8fafc;\n}\n\n.hero {\n  color: rgb(30, 41, 59);\n  background-image: url(\"background.jpg\");\n  background-size: cover;\n  background-position: center;\n}</pre>\n<p>Для тексту на зображенні обирай контрастний колір, щоб його було легко читати.</p>"
        }
    },
    "18.2": {
        "title": "18.2",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Практика: кольорова картка</h3>\n<p class=\"mb-3\">Створи блок із заголовком та описом і оформи його за допомогою CSS.</p>\n<ul class=\"list-disc ml-5 mb-4 space-y-1\"><li>Задай фоновий колір сторінки.</li><li>Задай картці інший фоновий колір і контрастний текст.</li><li>За бажанням додай фонове зображення.</li><li>Використай <code>background-size: cover</code> та <code>background-position: center</code>.</li></ul>"
        }
    },
    "19.1": {
        "title": "19.1",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Шрифти та оформлення тексту</h3>\n<p class=\"mb-3\">CSS дає змогу налаштовувати шрифт, розмір, товщину, міжрядковий інтервал і вирівнювання тексту.</p>\n<pre class=\"bg-black/30 p-4 rounded-xl overflow-auto text-sm mb-4\">h1 {\n  font-family: Arial, sans-serif;\n  font-size: 32px;\n  font-weight: 700;\n  text-align: center;\n}\n\np {\n  line-height: 1.6;\n  letter-spacing: 0.2px;\n}</pre>\n<p>Для основного тексту важливі читабельний розмір і достатній контраст.</p>"
        }
    },
    "19.2": {
        "title": "19.2",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Практика: типографіка</h3>\n<p class=\"mb-3\">Оформи сторінку з назвою сайту й трьома абзацами.</p>\n<ol class=\"list-decimal ml-5 mb-4 space-y-2\"><li>Зроби заголовок великим і жирним.</li><li>Вирівняй його по центру.</li><li>Для абзаців встанови <code>line-height</code> приблизно <code>1.6</code>.</li><li>Зміни шрифт і перевір, чи текст зручно читати.</li></ol>"
        }
    },
    "20.1": {
        "title": "20.1",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Модель блоку: margin та padding</h3>\n<p class=\"mb-3\"><code>padding</code> — внутрішній відступ між вмістом і краєм елемента. <code>margin</code> — зовнішній відступ від інших елементів.</p>\n<pre class=\"bg-black/30 p-4 rounded-xl overflow-auto text-sm mb-4\">.card {\n  padding: 20px;\n  margin: 16px;\n  width: 280px;\n  box-sizing: border-box;\n}</pre>\n<p><code>box-sizing: border-box</code> допомагає враховувати padding і border всередині заданої ширини.</p>"
        }
    },
    "20.2": {
        "title": "20.2",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Практика: відступи</h3>\n<p class=\"mb-3\">Створи три картки з текстом і оформи їх однаково.</p>\n<ul class=\"list-disc ml-5 mb-4 space-y-1\"><li>Задай внутрішній відступ через <code>padding</code>.</li><li>Додай відстань між картками через <code>margin</code> або <code>gap</code>.</li><li>Встанови <code>box-sizing: border-box</code>.</li><li>Зміни значення відступів і порівняй результат.</li></ul>"
        }
    },
    "21.1": {
        "title": "21.1",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Рамки, заокруглення та тіні</h3>\n<p class=\"mb-3\">Властивість <code>border</code> створює рамку, <code>border-radius</code> заокруглює кути, а <code>box-shadow</code> додає тінь.</p>\n<pre class=\"bg-black/30 p-4 rounded-xl overflow-auto text-sm mb-4\">.card {\n  border: 1px solid #cbd5e1;\n  border-radius: 14px;\n  box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);\n}</pre>\n<p>Використовуй тіні помірно, щоб картки не виглядали перевантаженими.</p>"
        }
    },
    "21.2": {
        "title": "21.2",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Практика: картка профілю</h3>\n<p class=\"mb-3\">Створи картку профілю з ім’ям, коротким описом і кнопкою.</p>\n<ul class=\"list-disc ml-5 mb-4 space-y-1\"><li>Додай тонку рамку.</li><li>Заокругли кути.</li><li>Додай м’яку тінь.</li><li>Внутрішні елементи відділи від країв за допомогою <code>padding</code>.</li></ul>\n<p>Перевір сторінку на вузькому вікні браузера.</p>"
        }
    },
    "22.1": {
        "title": "22.1",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Властивості display, width та height</h3>\n<p class=\"mb-3\"><code>display</code> визначає спосіб розміщення елемента. Часто використовують <code>block</code>, <code>inline</code>, <code>inline-block</code>, <code>flex</code> і <code>grid</code>.</p>\n<pre class=\"bg-black/30 p-4 rounded-xl overflow-auto text-sm mb-4\">.badge {\n  display: inline-block;\n  width: 120px;\n  min-height: 36px;\n}\n\n.hidden {\n  display: none;\n}</pre>\n<p><code>display: none</code> приховує елемент і прибирає його з розкладки сторінки.</p>"
        }
    },
    "22.2": {
        "title": "22.2",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Практика: розміри елементів</h3>\n<p class=\"mb-3\">Створи ряд із трьох позначок або карток.</p>\n<ol class=\"list-decimal ml-5 mb-4 space-y-2\"><li>Одному елементу задай <code>display: block</code>.</li><li>Другому — <code>inline-block</code>.</li><li>Третьому задай ширину та мінімальну висоту.</li><li>Тимчасово застосуй <code>display: none</code> і перевір, що зміниться.</li></ol>"
        }
    },
    "23.1": {
        "title": "23.1",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Flexbox: розташування в ряд</h3>\n<p class=\"mb-3\">Flexbox допомагає розташовувати елементи в ряд або колонку та керувати проміжками між ними.</p>\n<pre class=\"bg-black/30 p-4 rounded-xl overflow-auto text-sm mb-4\">.cards {\n  display: flex;\n  justify-content: space-between;\n  align-items: center;\n  gap: 16px;\n}\n\n.card {\n  flex: 1;\n}</pre>\n<p><code>justify-content</code> керує розподілом по головній осі, а <code>align-items</code> — вирівнюванням по поперечній.</p>"
        }
    },
    "23.2": {
        "title": "23.2",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Практика: панель навігації</h3>\n<p class=\"mb-3\">Зроби верхню панель сайту з назвою зліва та трьома посиланнями справа.</p>\n<ul class=\"list-disc ml-5 mb-4 space-y-1\"><li>Для контейнера встанови <code>display: flex</code>.</li><li>Використай <code>justify-content: space-between</code>.</li><li>Встанови <code>align-items: center</code> і проміжок <code>gap</code>.</li><li>Перевір, чи панель не ламається при зменшенні вікна.</li></ul>"
        }
    },
    "24.1": {
        "title": "24.1",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">CSS Grid: сітка карток</h3>\n<p class=\"mb-3\">Grid допомагає будувати двовимірні сітки з рядками та колонками.</p>\n<pre class=\"bg-black/30 p-4 rounded-xl overflow-auto text-sm mb-4\">.grid-cards {\n  display: grid;\n  grid-template-columns: repeat(3, minmax(0, 1fr));\n  gap: 16px;\n}</pre>\n<p><code>repeat(3, ...)</code> створює три колонки однакової ширини, а <code>gap</code> додає проміжки.</p>"
        }
    },
    "24.2": {
        "title": "24.2",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Практика: галерея на Grid</h3>\n<p class=\"mb-3\">Створи галерею з шести карток.</p>\n<ol class=\"list-decimal ml-5 mb-4 space-y-2\"><li>Зроби контейнер із <code>display: grid</code>.</li><li>Розмісти картки у три колонки.</li><li>Задай відстань між картками через <code>gap</code>.</li><li>На вузькому екрані зменш кількість колонок до двох.</li></ol>"
        }
    },
    "25.1": {
        "title": "25.1",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Позиціонування: relative, absolute, fixed</h3>\n<p class=\"mb-3\"><code>position: relative</code> зберігає місце елемента й дозволяє зміщувати його. <code>absolute</code> позиціонується відносно найближчого позиціонованого предка. <code>fixed</code> прив’язує елемент до вікна браузера.</p>\n<pre class=\"bg-black/30 p-4 rounded-xl overflow-auto text-sm\">.card {\n  position: relative;\n}\n\n.card-label {\n  position: absolute;\n  top: 8px;\n  right: 8px;\n}\n\n.help-button {\n  position: fixed;\n  right: 20px;\n  bottom: 20px;\n}</pre>"
        }
    },
    "25.2": {
        "title": "25.2",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Практика: мітка на картці</h3>\n<p class=\"mb-3\">Створи картку товару або навчального курсу.</p>\n<ul class=\"list-disc ml-5 mb-4 space-y-1\"><li>Задай картці <code>position: relative</code>.</li><li>Додай усередину мітку «NEW» з <code>position: absolute</code>.</li><li>Розмісти мітку у верхньому правому куті.</li><li>Перевір, чи мітка залишається всередині картки.</li></ul>"
        }
    },
    "26.1": {
        "title": "26.1",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Псевдокласи та псевдоелементи</h3>\n<p class=\"mb-3\">Псевдокласи задають стиль для певного стану елемента. Наприклад, <code>:hover</code> — коли курсор наведено, <code>:focus</code> — коли елемент у фокусі.</p>\n<pre class=\"bg-black/30 p-4 rounded-xl overflow-auto text-sm mb-4\">a:hover {\n  text-decoration: underline;\n}\n\ninput:focus {\n  outline: 2px solid #3b82f6;\n}\n\n.note::before {\n  content: \"ℹ \";\n}</pre>\n<p>Псевдоелементи <code>::before</code> і <code>::after</code> можуть додавати декоративний вміст.</p>"
        }
    },
    "26.2": {
        "title": "26.2",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Практика: стани кнопки та поля</h3>\n<p class=\"mb-3\">Оформи кнопку, посилання та поле введення.</p>\n<ul class=\"list-disc ml-5 mb-4 space-y-1\"><li>Для кнопки зроби інший колір під час <code>:hover</code>.</li><li>Для посилання додай підкреслення при наведенні.</li><li>Для поля введення виділи рамку в стані <code>:focus</code>.</li><li>За бажанням додай декоративний символ через <code>::before</code>.</li></ul>"
        }
    },
    "27.1": {
        "title": "27.1",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Адаптивний дизайн і media queries</h3>\n<p class=\"mb-3\">Медіазапити дозволяють змінювати стилі залежно від ширини екрана.</p>\n<pre class=\"bg-black/30 p-4 rounded-xl overflow-auto text-sm mb-4\">.cards {\n  display: grid;\n  grid-template-columns: repeat(3, minmax(0, 1fr));\n  gap: 16px;\n}\n\n@media (max-width: 800px) {\n  .cards {\n    grid-template-columns: repeat(2, minmax(0, 1fr));\n  }\n}\n\n@media (max-width: 520px) {\n  .cards {\n    grid-template-columns: 1fr;\n  }\n}</pre>\n<p>Підбирай ширину переломлення, перевіряючи макет на різних розмірах екрана.</p>"
        }
    },
    "27.2": {
        "title": "27.2",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Практика: адаптивна сітка</h3>\n<p class=\"mb-3\">Зроби галерею з шести карток і налаштуй її для трьох типів екранів.</p>\n<ul class=\"list-disc ml-5 mb-4 space-y-1\"><li>На широкому екрані — три колонки.</li><li>До 800 пікселів — дві колонки.</li><li>До 520 пікселів — одна колонка.</li></ul>\n<p>Зменшуй ширину вікна браузера та переконайся, що горизонтальна прокрутка не з’являється без потреби.</p>"
        }
    },
    "28.1": {
        "title": "28.1",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">CSS-змінні</h3>\n<p class=\"mb-3\">CSS-змінні допомагають зберігати значення кольорів, відступів та інших параметрів в одному місці.</p>\n<pre class=\"bg-black/30 p-4 rounded-xl overflow-auto text-sm mb-4\">:root {\n  --color-main: #2563eb;\n  --color-text: #1e293b;\n  --space: 16px;\n}\n\n.button {\n  background: var(--color-main);\n  padding: var(--space);\n  color: white;\n}</pre>\n<p>Щоб змінити головний колір усього сайту, достатньо змінити значення змінної.</p>"
        }
    },
    "28.2": {
        "title": "28.2",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Практика: власна палітра</h3>\n<p class=\"mb-3\">Створи три CSS-змінні: головний колір, колір тексту та основний відступ.</p>\n<ol class=\"list-decimal ml-5 mb-4 space-y-2\"><li>Оголоси їх у <code>:root</code>.</li><li>Використай змінні для заголовка, картки та кнопки.</li><li>Зміни лише значення змінних і перевір, як оновиться сторінка.</li></ol>"
        }
    },
    "29.1": {
        "title": "29.1",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Переходи та трансформації</h3>\n<p class=\"mb-3\"><code>transition</code> робить зміну стилю плавною. <code>transform</code> дозволяє масштабувати, обертати або переміщувати елемент.</p>\n<pre class=\"bg-black/30 p-4 rounded-xl overflow-auto text-sm mb-4\">.button {\n  transition: background-color 0.2s ease, transform 0.2s ease;\n}\n\n.button:hover {\n  background-color: #1d4ed8;\n  transform: translateY(-2px);\n}</pre>\n<p>Не додавай рух до кожного елемента без потреби; анімація має допомагати помітити дію.</p>"
        }
    },
    "29.2": {
        "title": "29.2",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Практика: інтерактивна кнопка</h3>\n<p class=\"mb-3\">Створи кнопку «Дізнатися більше».</p>\n<ul class=\"list-disc ml-5 mb-4 space-y-1\"><li>Задай їй фоновий колір і відступи.</li><li>Додай плавний <code>transition</code>.</li><li>При наведенні трохи зміни колір і підніми кнопку за допомогою <code>translateY</code>.</li><li>Перевір, що кнопка лишається доступною для натискання.</li></ul>"
        }
    },
    "30.1": {
        "title": "30.1",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">CSS-анімації через @keyframes</h3>\n<p class=\"mb-3\">Правило <code>@keyframes</code> описує етапи анімації, а властивість <code>animation</code> запускає її.</p>\n<pre class=\"bg-black/30 p-4 rounded-xl overflow-auto text-sm mb-4\">@keyframes fade-in {\n  from { opacity: 0; }\n  to { opacity: 1; }\n}\n\n.message {\n  animation: fade-in 0.5s ease-out;\n}</pre>\n<p>Для важливих повідомлень не покладайся лише на анімацію — текст має бути зрозумілим і без неї.</p>"
        }
    },
    "30.2": {
        "title": "30.2",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Практика: поява картки</h3>\n<p class=\"mb-3\">Створи картку з назвою навчального модуля.</p>\n<ol class=\"list-decimal ml-5 mb-4 space-y-2\"><li>Опиши анімацію через <code>@keyframes</code>.</li><li>Зроби плавну появу від прозорого до видимого стану.</li><li>Застосуй анімацію до картки на короткий час.</li><li>Переконайся, що вміст не блимає й залишається читабельним.</li></ol>"
        }
    },
    "31.1": {
        "title": "31.1",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Початок JavaScript</h3>\n<p class=\"mb-3\">JavaScript додає сторінці поведінку: реагування на натискання, перевірку форм, зміну тексту й роботу з даними.</p>\n<p class=\"mb-3\">Зовнішній файл підключають перед закривальним тегом <code>&lt;/body&gt;</code> або через атрибут <code>defer</code>:</p>\n<pre class=\"bg-black/30 p-4 rounded-xl overflow-auto text-sm mb-4\">&lt;script src=\"script.js\" defer&gt;&lt;/script&gt;</pre>\n<p>У файлі <code>script.js</code> можна перевірити роботу за допомогою <code>console.log()</code>.</p>"
        }
    },
    "31.2": {
        "title": "31.2",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Практика: перший скрипт</h3>\n<p class=\"mb-3\">Створи файл <code>script.js</code> та підключи його до HTML-сторінки.</p>\n<ul class=\"list-disc ml-5 mb-4 space-y-1\"><li>Виведи повідомлення <code>Hello, JavaScript!</code> у консоль.</li><li>Виведи своє ім’я або псевдонім.</li><li>Відкрий інструменти розробника в браузері та знайди вкладку Console.</li></ul>\n<p>Якщо повідомлення немає, перевір назву файлу та шлях у тегу <code>&lt;script&gt;</code>.</p>"
        }
    },
    "32.1": {
        "title": "32.1",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Змінні та типи даних у JavaScript</h3>\n<p class=\"mb-3\"><code>const</code> використовують для змінної, яку не планують перепризначати. <code>let</code> — коли значення потрібно буде змінювати.</p>\n<pre class=\"bg-black/30 p-4 rounded-xl overflow-auto text-sm mb-4\">const username = \"Alex\";\nlet age = 16;\nlet isStudent = true;\nlet score = 12;\n\nconsole.log(username, age, isStudent, score);</pre>\n<p>Основні типи для початку: рядок (<code>string</code>), число (<code>number</code>) і логічне значення (<code>boolean</code>).</p>"
        }
    },
    "32.2": {
        "title": "32.2",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Практика: змінні</h3>\n<p class=\"mb-3\">Створи змінні для невеликої картки користувача.</p>\n<ul class=\"list-disc ml-5 mb-4 space-y-1\"><li><code>username</code> — ім’я або псевдонім.</li><li><code>favoriteLanguage</code> — улюблена мова програмування.</li><li><code>tasksDone</code> — кількість виконаних завдань.</li><li><code>isLearning</code> — логічне значення.</li></ul>\n<p>Виведи всі значення через <code>console.log()</code>. Спробуй змінити значення змінної, оголошеної через <code>let</code>.</p>"
        }
    },
    "33.1": {
        "title": "33.1",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Оператори та порівняння</h3>\n<p class=\"mb-3\">Арифметичні оператори: <code>+</code>, <code>-</code>, <code>*</code>, <code>/</code>, <code>%</code>. Оператори порівняння повертають логічне значення.</p>\n<pre class=\"bg-black/30 p-4 rounded-xl overflow-auto text-sm mb-4\">const a = 10;\nconst b = 3;\n\nconsole.log(a + b);\nconsole.log(a % b);\nconsole.log(a > b);\nconsole.log(a === 10);\nconsole.log(a !== b);</pre>\n<p>Для перевірки рівності значень найчастіше використовуй <code>===</code>, а не <code>==</code>.</p>"
        }
    },
    "33.2": {
        "title": "33.2",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Практика: обчислення та порівняння</h3>\n<p class=\"mb-3\">Напиши скрипт для підрахунку балів.</p>\n<ol class=\"list-decimal ml-5 mb-4 space-y-2\"><li>Створи дві змінні з числами.</li><li>Виведи їхню суму, різницю, добуток і частку.</li><li>Перевір, чи перше число більше за друге.</li><li>Перевір рівність через <code>===</code>.</li></ol>\n<p>Відкрий Console та поясни собі, чому кожен результат має саме таке значення.</p>"
        }
    },
    "34.1": {
        "title": "34.1",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Умови if, else та else if</h3>\n<p class=\"mb-3\">Умова дає змогу виконувати різний код залежно від значення.</p>\n<pre class=\"bg-black/30 p-4 rounded-xl overflow-auto text-sm mb-4\">const score = 10;\n\nif (score >= 10) {\n  console.log(\"Завдання виконано\");\n} else if (score > 0) {\n  console.log(\"Ще трохи практики\");\n} else {\n  console.log(\"Почни з першого завдання\");\n}</pre>\n<p>Умова в дужках має повертати логічне значення — <code>true</code> або <code>false</code>.</p>"
        }
    },
    "34.2": {
        "title": "34.2",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Практика: перевірка результату</h3>\n<p class=\"mb-3\">Створи змінну <code>score</code> і напиши перевірку результату.</p>\n<ul class=\"list-disc ml-5 mb-4 space-y-1\"><li>Якщо балів 90 або більше — виведи «Відмінно».</li><li>Якщо від 60 до 89 — виведи «Добре».</li><li>Якщо менше 60 — виведи «Потрібно попрактикуватися».</li></ul>\n<p>Змінюй значення <code>score</code> та перевір усі три гілки.</p>"
        }
    },
    "35.1": {
        "title": "35.1",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Цикли та масиви</h3>\n<p class=\"mb-3\">Масив зберігає кілька значень в одному списку. Цикл дає змогу повторити дію для кожного значення.</p>\n<pre class=\"bg-black/30 p-4 rounded-xl overflow-auto text-sm mb-4\">const languages = [\"HTML\", \"CSS\", \"JavaScript\"];\n\nfor (let i = 0; i &lt; languages.length; i++) {\n  console.log(languages[i]);\n}\n\nfor (const language of languages) {\n  console.log(language);\n}</pre>\n<p>Індекси масиву починаються з нуля, а <code>length</code> повертає кількість елементів.</p>"
        }
    },
    "35.2": {
        "title": "35.2",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Практика: список завдань</h3>\n<p class=\"mb-3\">Створи масив із п’яти навчальних завдань.</p>\n<ol class=\"list-decimal ml-5 mb-4 space-y-2\"><li>Виведи всі елементи циклом <code>for...of</code>.</li><li>Виведи кількість завдань за допомогою <code>length</code>.</li><li>Виведи перший та останній елементи.</li><li>Додай новий елемент методом <code>push()</code> і перевір довжину ще раз.</li></ol>"
        }
    },
    "36.1": {
        "title": "36.1",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">DOM та події</h3>\n<p class=\"mb-3\">DOM — це представлення HTML-сторінки, з яким JavaScript може працювати. Елемент можна знайти через <code>querySelector()</code>, а подію обробити через <code>addEventListener()</code>.</p>\n<pre class=\"bg-black/30 p-4 rounded-xl overflow-auto text-sm mb-4\">const button = document.querySelector(\"#hello-button\");\nconst message = document.querySelector(\"#message\");\n\nbutton.addEventListener(\"click\", function () {\n  message.textContent = \"Привіт! Кнопка працює.\";\n});</pre>\n<p>У HTML мають існувати елементи з відповідними <code>id</code>.</p>"
        }
    },
    "36.2": {
        "title": "36.2",
        "content": {
            "ua": "<h3 class=\"text-xl font-bold mb-4 text-accent\">Мініпроєкт: інтерактивний лічильник</h3>\n<p class=\"mb-3\">Створи лічильник, який змінюється після натискання кнопок.</p>\n<p class=\"mb-2\"><b>HTML:</b></p>\n<pre class=\"bg-black/30 p-4 rounded-xl overflow-auto text-sm mb-4\">&lt;h2 id=\"count\"&gt;0&lt;/h2&gt;\n&lt;button id=\"minus\"&gt;−1&lt;/button&gt;\n&lt;button id=\"reset\"&gt;Скинути&lt;/button&gt;\n&lt;button id=\"plus\"&gt;+1&lt;/button&gt;\n&lt;script src=\"script.js\" defer&gt;&lt;/script&gt;</pre>\n<p class=\"mb-2\"><b>Завдання для JavaScript:</b></p>\n<ul class=\"list-disc ml-5 mb-4 space-y-1\"><li>Створи змінну <code>count</code> зі значенням <code>0</code>.</li><li>Знайди всі чотири елементи через <code>querySelector()</code>.</li><li>За кліком на <code>plus</code> збільшуй число на один.</li><li>За кліком на <code>minus</code> зменшуй число на один.</li><li>За кліком на <code>reset</code> встановлюй нуль.</li><li>Після кожної дії оновлюй текст елемента <code>count</code>.</li></ul>\n<p>Перевір, що всі три кнопки працюють без перезавантаження сторінки.</p>"
        }
    }
});


const courseCatalog = {
    html_css: { title: 'HTML + CSS', icon: '🌐', description: 'Створення сторінок, оформлення та адаптивний дизайн.', lessons: 30, level: 'Початковий рівень' },
    python: { title: 'Python', icon: '🐍', description: 'Змінні, умови, цикли, функції, колекції та перші проєкти.', lessons: 12, level: 'Від основ до практики' },
    php: { title: 'PHP', icon: '🐘', description: 'Серверна логіка, форми, сесії та робота з базою даних.', lessons: 12, level: 'Веброзробка' },
    javascript: { title: 'JavaScript', icon: '⚡', description: 'Інтерактивність сторінок, DOM, події та дані браузера.', lessons: 12, level: 'Від основ до DOM' },
    cpp: { title: 'C++', icon: '⚙️', description: 'Синтаксис, умови, цикли, функції та базові алгоритми.', lessons: 12, level: 'Основи програмування' }
};
let activeCourse = <?= json_encode($activeCourseFromDb, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
let savedLastTask = <?= json_encode($lastTaskFromDb, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const courseCsrf = <?= json_encode($courseCsrf, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const displayName = <?= json_encode((string)($user['name'] ?? $user['username']), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

const courseCurricula = {
    python: [
        ['Перший запуск Python', `<p class="mb-3">Python — мова програмування з простим і зрозумілим синтаксисом. Код можна виконувати у файлі з розширенням <code>.py</code>.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">print("Привіт, світе!")</pre>`, `<p class="mb-3">Створи файл <code>main.py</code> та виведи три рядки: привітання, своє ім’я або псевдонім і назву курсу, який вивчаєш.</p>`],
        ['Змінні та типи даних', `<p class="mb-3">Змінна зберігає значення. Основні типи: <code>str</code> — текст, <code>int</code> — ціле число, <code>float</code> — дробове число, <code>bool</code> — істина або хиба.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">name = "Alex"\nage = 15\nprogress = 2.5\nis_learning = True</pre>`, `<p class="mb-3">Створи змінні для імені, віку, улюбленої мови програмування та логічної відповіді, чи подобається тобі програмувати. Виведи їх через <code>print()</code>.</p>`],
        ['Введення даних', `<p class="mb-3">Функція <code>input()</code> отримує текст від користувача. Щоб використати введене число в обчисленнях, перетвори його через <code>int()</code> або <code>float()</code>.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">name = input("Як тебе звати? ")\nprint("Вітаю,", name)</pre>`, `<p class="mb-3">Напиши програму, яка запитує ім’я та улюблений предмет, а потім виводить персональне привітання. Додатково запитай вік і перетвори відповідь на ціле число.</p>`],
        ['Арифметичні оператори', `<p class="mb-3">Для обчислень використовують <code>+</code>, <code>-</code>, <code>*</code>, <code>/</code>, цілочисельне ділення <code>//</code>, остачу <code>%</code> та піднесення до степеня <code>**</code>.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">a = 17\nb = 5\nprint(a + b)\nprint(a // b)\nprint(a % b)</pre>`, `<p class="mb-3">Запитай у користувача два числа й виведи їхню суму, різницю, добуток і частку. Окремо перевір, що відбувається, якщо друге число дорівнює нулю.</p>`],
        ['Умови if та else', `<p class="mb-3">Умова дозволяє вибрати, який блок коду виконати. У Python блоки визначаються відступами.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">score = 75\nif score >= 60:\n    print("Зараховано")\nelse:\n    print("Потрібно повторити")</pre>`, `<p class="mb-3">Створи змінну з кількістю балів. Якщо результат не менший за 90 — виведи «Відмінно», від 60 до 89 — «Добре», інакше — «Потрібно потренуватися».</p>`],
        ['Цикл for', `<p class="mb-3">Цикл <code>for</code> повторює команди для елементів послідовності. Функція <code>range()</code> допомагає створювати діапазон чисел.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">for number in range(1, 6):\n    print(number)</pre>`, `<p class="mb-3">Виведи числа від 1 до 20. Потім виведи лише парні числа в цьому діапазоні.</p>`],
        ['Цикл while', `<p class="mb-3">Цикл <code>while</code> повторює блок, доки умова істинна. Переконайся, що умова колись стане хибною, інакше цикл може не завершитися.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">count = 3\nwhile count > 0:\n    print(count)\n    count -= 1</pre>`, `<p class="mb-3">За допомогою <code>while</code> виведи числа від 5 до 1, а потім слово «Старт!». Не забудь змінювати лічильник у циклі.</p>`],
        ['Списки', `<p class="mb-3">Список зберігає кілька значень. Індекси починаються з нуля, а метод <code>append()</code> додає елемент у кінець.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">languages = ["HTML", "CSS"]\nlanguages.append("Python")\nprint(languages[0])\nprint(len(languages))</pre>`, `<p class="mb-3">Створи список із п’яти улюблених речей або мов програмування. Виведи перший і останній елементи, додай ще один елемент та покажи довжину списку.</p>`],
        ['Рядки та методи', `<p class="mb-3">Рядки — це текст. Методи <code>lower()</code>, <code>upper()</code>, <code>strip()</code> змінюють регістр або прибирають крайні пробіли.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">text = "  Python  "\nprint(text.strip())\nprint(text.upper())</pre>`, `<p class="mb-3">Запитай у користувача його ім’я. Прибери зайві пробіли та виведи ім’я великими літерами й у звичайному вигляді.</p>`],
        ['Функції', `<p class="mb-3">Функція об’єднує команди, які можна використовувати повторно. Параметри передають дані, а <code>return</code> повертає результат.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">def add(a, b):\n    return a + b\n\nprint(add(3, 4))</pre>`, `<p class="mb-3">Напиши функцію <code>rectangle_area(width, height)</code>, яка повертає площу прямокутника. Перевір її на кількох наборах чисел.</p>`],
        ['Словники', `<p class="mb-3">Словник зберігає пари «ключ — значення». Значення отримують за ключем, а нові пари можна додавати присвоєнням.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">student = {"name": "Alex", "score": 10}\nprint(student["name"])\nstudent["score"] = 12</pre>`, `<p class="mb-3">Створи словник профілю з ім’ям, курсом і кількістю виконаних завдань. Виведи значення окремо та онови кількість завдань.</p>`],
        ['Мініпроєкт: вікторина', `<p class="mb-3">Поєднай введення, змінні, умови та лічильник балів, щоб зробити текстову вікторину.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">answer = input("Яка мова запускається в браузері? ")\nif answer.strip().lower() == "javascript":\n    print("Правильно!")\nelse:\n    print("Спробуй ще раз.")</pre>`, `<p class="mb-3">Створи вікторину з трьох запитань. За правильну відповідь нараховуй бал, а наприкінці показуй загальний результат. Перевір, що програма приймає відповіді з великими літерами й зайвими пробілами.</p>`]
    ],
    php: [
        ['Як працює PHP', `<p class="mb-3">PHP виконується на сервері. Код зазвичай розміщують у файлах із розширенням <code>.php</code> і запускають через локальний сервер, наприклад XAMPP.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">&lt;?php\necho "Привіт зі світу PHP!";\n?&gt;</pre>`, `<p class="mb-3">Створи <code>first.php</code> у папці вебсервера. Виведи заголовок сторінки й коротке повідомлення. Відкрий файл через localhost, а не подвійним кліком.</p>`],
        ['Виведення та HTML', `<p class="mb-3"><code>echo</code> виводить текст або значення. PHP може генерувати HTML, який потім відображає браузер.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">&lt;?php\n$title = "Мій сайт";\necho "&lt;h1&gt;" . $title . "&lt;/h1&gt;";\n?&gt;</pre>`, `<p class="mb-3">Створи сторінку з заголовком, двома абзацами та поточним роком. Частину вмісту виведи через PHP.</p>`],
        ['Змінні та типи', `<p class="mb-3">Змінна PHP починається зі знака <code>$</code>. Значення можуть бути рядком, цілим або дробовим числом, логічним значенням чи масивом.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">&lt;?php\n$name = "Alex";\n$tasks = 5;\n$isActive = true;\necho $name;\n?&gt;</pre>`, `<p class="mb-3">Створи змінні для імені, назви курсу й кількості завдань. Виведи всі значення. Зміни одне з них і перевір результат.</p>`],
        ['Конкатенація та оператори', `<p class="mb-3">У PHP крапка <code>.</code> об’єднує рядки. Арифметичні оператори виконують звичайні обчислення.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">&lt;?php\n$name = "Alex";\necho "Привіт, " . $name;\n$total = 4 + 6;\necho $total;\n?&gt;</pre>`, `<p class="mb-3">Створи дві числові змінні та виведи суму, різницю й добуток. Побудуй привітання, об’єднавши текст зі змінною імені.</p>`],
        ['Умови if та else', `<p class="mb-3">Умови дозволяють виконати різні блоки залежно від значення. PHP використовує оператори порівняння на кшталт <code>===</code> та <code>&gt;=</code>.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">&lt;?php\n$score = 80;\nif ($score >= 60) {\n    echo "Зараховано";\n} else {\n    echo "Не зараховано";\n}\n?&gt;</pre>`, `<p class="mb-3">Залежно від числа балів виведи «Відмінно», «Добре» або «Спробуй ще». Перевір усі гілки зміною значення змінної.</p>`],
        ['Цикли for та foreach', `<p class="mb-3">Цикл <code>for</code> повторює дії задану кількість разів. <code>foreach</code> зручно перебирає елементи масиву.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">&lt;?php\n$items = ['HTML', 'CSS', 'PHP'];\nforeach ($items as $item) {\n    echo $item . '&lt;br&gt;';\n}\n?&gt;</pre>`, `<p class="mb-3">Створи масив із п’яти курсів і виведи їх списком HTML. Додатково використай <code>for</code>, щоб вивести числа від 1 до 10.</p>`],
        ['Масиви', `<p class="mb-3">Індексований масив зберігає значення за порядковими індексами. Асоціативний масив використовує іменовані ключі.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">&lt;?php\n$user = ['name' => 'Alex', 'course' => 'PHP'];\necho $user['name'];\n?&gt;</pre>`, `<p class="mb-3">Створи асоціативний масив користувача з ім’ям, email і курсом. Виведи дані на сторінку та додай ще одне поле.</p>`],
        ['Функції', `<p class="mb-3">Функція дає ім’я повторюваному блоку коду. Параметри передають значення, а <code>return</code> повертає результат.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">&lt;?php\nfunction greet($name) {\n    return 'Привіт, ' . $name;\n}\necho greet('Alex');\n?&gt;</pre>`, `<p class="mb-3">Напиши функцію, яка приймає назву курсу й повертає рядок-привітання. Виклич її тричі з різними аргументами.</p>`],
        ['Форми GET і POST', `<p class="mb-3">HTML-форми надсилають дані на сервер. <code>GET</code> передає параметри в URL, а <code>POST</code> надсилає їх у тілі запиту. Введені дані слід перевіряти.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">&lt;form method="post"&gt;\n  &lt;input name="username" required&gt;\n  &lt;button&gt;Надіслати&lt;/button&gt;\n&lt;/form&gt;</pre>`, `<p class="mb-3">Створи форму з полем імені, яка використовує POST. У PHP перевір наявність поля та безпечно виведи його через <code>htmlspecialchars()</code>.</p>`],
        ['Сесії та авторизація', `<p class="mb-3">Сесія зберігає дані користувача між запитами. Для роботи з нею викликають <code>session_start()</code> до виведення HTML.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">&lt;?php\nsession_start();\n$_SESSION['visits'] = ($_SESSION['visits'] ?? 0) + 1;\necho $_SESSION['visits'];\n?&gt;</pre>`, `<p class="mb-3">Створи сторінку, яка підраховує кількість відкриттів у межах сесії. Додай кнопку-посилання для скидання лічильника.</p>`],
        ['База даних через PDO', `<p class="mb-3">PDO дозволяє підключатися до бази даних і виконувати запити. Для значень користувача застосовуй підготовлені запити з параметрами, а не склеюй SQL-рядок.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">$stmt = $pdo-&gt;prepare('SELECT id, username FROM users WHERE id = ?');\n$stmt-&gt;execute([$userId]);\n$user = $stmt-&gt;fetch(PDO::FETCH_ASSOC);</pre>`, `<p class="mb-3">На навчальному прикладі з XAMPP підключись до своєї тестової БД через PDO та виконай підготовлений SELECT із параметром. Не виводь пароль бази даних у браузер.</p>`],
        ['Мініпроєкт: сторінка профілю', `<p class="mb-3">Поєднай PHP, HTML, форми та масиви, щоб побудувати сторінку профілю. Виводь дані користувача безпечно.</p>`, `<p class="mb-3">Створи профіль із полями імені, курсу та кількості виконаних завдань. Додай форму редагування й серверну перевірку введених даних. За бажанням збережи інформацію у тестовій таблиці через PDO.</p>`]
    ],
    javascript: [
        ['Підключення JavaScript', `<p class="mb-3">JavaScript додає сторінці інтерактивність. Підключай файл через <code>defer</code>, щоб браузер спочатку міг прочитати HTML.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">&lt;script src="script.js" defer&gt;&lt;/script&gt;</pre>`, `<p class="mb-3">Створи файл <code>script.js</code>, підключи його до HTML та виведи в Console текст «Hello, JavaScript!» за допомогою <code>console.log()</code>.</p>`],
        ['Змінні й типи даних', `<p class="mb-3"><code>const</code> оголошує зв’язок, який не перепризначають, а <code>let</code> — змінну, якій можна надати нове значення. Основні типи: string, number, boolean.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">const course = "JavaScript";\nlet completed = 0;\nconst isLearning = true;</pre>`, `<p class="mb-3">Створи змінні для імені, курсу й кількості виконаних завдань. Виведи їх у консоль і зміни значення змінної, оголошеної через <code>let</code>.</p>`],
        ['Оператори', `<p class="mb-3">JavaScript підтримує арифметичні оператори й порівняння. Для суворої перевірки рівності зазвичай використовують <code>===</code>.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">const a = 10;\nconst b = 3;\nconsole.log(a + b, a % b, a > b, a === 10);</pre>`, `<p class="mb-3">Створи дві змінні та виведи суму, різницю, добуток, остачу від ділення і результат порівняння.</p>`],
        ['Умови', `<p class="mb-3">Конструкції <code>if</code>, <code>else if</code> і <code>else</code> дозволяють вибирати дію на основі умови.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">const score = 82;\nif (score >= 90) {\n  console.log("Відмінно");\n} else if (score >= 60) {\n  console.log("Добре");\n} else {\n  console.log("Практикуйся");\n}</pre>`, `<p class="mb-3">Залежно від кількості балів виведи одну з трьох оцінок. Перевір усі варіанти, змінюючи значення змінної.</p>`],
        ['Цикли', `<p class="mb-3">Цикли <code>for</code> і <code>while</code> повторюють дії. Умова та лічильник мають гарантувати завершення циклу.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">for (let i = 1; i <= 5; i++) {\n  console.log(i);\n}</pre>`, `<p class="mb-3">Виведи числа від 1 до 20, а потім лише парні числа. Використай цикл і умову.</p>`],
        ['Масиви', `<p class="mb-3">Масив містить упорядкований список. Доступ до елементів здійснюється за індексом, який починається з нуля. Метод <code>push()</code> додає елемент.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">const courses = ["HTML", "CSS"];\ncourses.push("JavaScript");\nconsole.log(courses[0], courses.length);</pre>`, `<p class="mb-3">Створи масив із п’яти курсів, виведи перший та останній елементи, додай ще один курс і покажи довжину масиву.</p>`],
        ['Функції', `<p class="mb-3">Функції дають змогу повторно використовувати логіку. Вони можуть приймати параметри та повертати значення.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">function add(a, b) {\n  return a + b;\n}\nconsole.log(add(2, 3));</pre>`, `<p class="mb-3">Напиши функцію для обчислення площі прямокутника. Перевір її з кількома ширинами та висотами.</p>`],
        ['Об’єкти', `<p class="mb-3">Об’єкт зберігає властивості у форматі «ключ — значення». До властивостей можна звертатися через крапку або квадратні дужки.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">const user = { name: "Alex", course: "JS" };\nconsole.log(user.name);\nuser.course = "JavaScript";</pre>`, `<p class="mb-3">Створи об’єкт профілю з ім’ям, курсом і кількістю виконаних завдань. Виведи значення, а потім онови одне з них.</p>`],
        ['DOM: пошук елементів', `<p class="mb-3">DOM представляє структуру HTML як об’єкти. Метод <code>querySelector()</code> знаходить перший елемент, який відповідає CSS-селектору.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">const title = document.querySelector("h1");\ntitle.textContent = "Новий заголовок";</pre>`, `<p class="mb-3">Створи заголовок і абзац. Знайди їх через <code>querySelector()</code> і зміни текст за допомогою <code>textContent</code>.</p>`],
        ['Події та кнопки', `<p class="mb-3">Метод <code>addEventListener()</code> дає змогу реагувати на події, наприклад <code>click</code>, введення тексту або надсилання форми.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">button.addEventListener("click", () => {\n  message.textContent = "Кнопку натиснуто";\n});</pre>`, `<p class="mb-3">Створи кнопку й абзац. Після натискання кнопки змінюй текст абзацу, не перезавантажуючи сторінку.</p>`],
        ['JSON і localStorage', `<p class="mb-3"><code>localStorage</code> зберігає невеликі текстові значення в браузері. Об’єкти можна перетворювати на текст через <code>JSON.stringify()</code>, а назад — через <code>JSON.parse()</code>.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">localStorage.setItem("course", "JavaScript");\nconst course = localStorage.getItem("course");</pre>`, `<p class="mb-3">Збережи в <code>localStorage</code> ім’я користувача або обраний курс. Після перезавантаження сторінки прочитай це значення та покажи його.</p>`],
        ['Мініпроєкт: лічильник', `<p class="mb-3">Лічильник поєднує змінну, DOM і події. Інтерфейс відображає поточне число та має кнопки збільшення, зменшення й скидання.</p>`, `<p class="mb-3">Створи лічильник із кнопками «−1», «Скинути» та «+1». Оновлюй число після кожного кліку й не перезавантажуй сторінку.</p>`]
    ],
    cpp: [
        ['Структура програми C++', `<p class="mb-3">Програма C++ зазвичай містить директиви <code>#include</code> та функцію <code>main()</code>. Інструкції часто завершуються крапкою з комою.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">#include &lt;iostream&gt;\nusing namespace std;\nint main() {\n    cout &lt;&lt; "Hello!";\n    return 0;\n}</pre>`, `<p class="mb-3">Створи програму, яка виводить три рядки тексту: привітання, своє ім’я або псевдонім і назву курсу.</p>`],
        ['Виведення через cout', `<p class="mb-3">Потік <code>cout</code> виводить текст і значення. Маніпулятор <code>endl</code> або символ <code>\n</code> переходить на новий рядок.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">cout &lt;&lt; "Перше" &lt;&lt; endl;\ncout &lt;&lt; "Друге\\n";</pre>`, `<p class="mb-3">Виведи заголовок програми, розділювач і короткий опис, кожен з нового рядка.</p>`],
        ['Змінні та типи', `<p class="mb-3">Тип змінної визначає, які дані вона зберігає. Поширені типи: <code>int</code>, <code>double</code>, <code>char</code>, <code>bool</code> і <code>string</code>.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">int age = 15;\ndouble average = 10.5;\nbool learning = true;</pre>`, `<p class="mb-3">Створи змінні для цілого числа, дробового числа, одного символу та логічного значення. Виведи всі значення через <code>cout</code>.</p>`],
        ['Введення через cin', `<p class="mb-3">Потік <code>cin</code> читає дані з клавіатури. Тип введених даних має відповідати типу змінної.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">int age;\ncout &lt;&lt; "Вік: ";\ncin &gt;&gt; age;\ncout &lt;&lt; age;</pre>`, `<p class="mb-3">Запитай ім’я та вік. Зчитай число через <code>cin</code> і виведи персональне повідомлення. Для імені з пробілами вивчи використання <code>getline()</code>.</p>`],
        ['Арифметика', `<p class="mb-3">Основні оператори: <code>+</code>, <code>-</code>, <code>*</code>, <code>/</code> і <code>%</code>. Ділення двох цілих чисел дає цілий результат.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">int a = 17, b = 5;\ncout &lt;&lt; a + b &lt;&lt; endl;\ncout &lt;&lt; a / b &lt;&lt; endl;\ncout &lt;&lt; a % b;</pre>`, `<p class="mb-3">Зчитай два числа й виведи суму, різницю, добуток і частку. Подумай, як перевірити ділення на нуль.</p>`],
        ['Умови if та else', `<p class="mb-3">Умови <code>if</code>, <code>else if</code> і <code>else</code> дозволяють виконувати різні команди за результатом порівняння.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">if (score >= 60) {\n    cout &lt;&lt; "Зараховано";\n} else {\n    cout &lt;&lt; "Повторити";\n}</pre>`, `<p class="mb-3">Зчитай число балів і виведи оцінку за трьома діапазонами. Перевір кожен варіант окремо.</p>`],
        ['Цикл for', `<p class="mb-3">Цикл <code>for</code> зручний, коли кількість повторень відома. У ньому задають початок, умову та крок.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">for (int i = 1; i <= 5; i++) {\n    cout &lt;&lt; i &lt;&lt; endl;\n}</pre>`, `<p class="mb-3">Виведи числа від 1 до 100, а потім числа від 1 до 20, які діляться на 2 без остачі.</p>`],
        ['Цикл while', `<p class="mb-3">Цикл <code>while</code> повторює код, поки умова істинна. Значення, що впливає на умову, потрібно оновлювати.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">int count = 3;\nwhile (count > 0) {\n    cout &lt;&lt; count &lt;&lt; endl;\n    count--;\n}</pre>`, `<p class="mb-3">Виведи зворотний відлік від 10 до 1 через <code>while</code> і після циклу виведи «Старт!».</p>`],
        ['Масиви', `<p class="mb-3">Масив зберігає кілька значень одного типу. Індекси починаються з нуля, а розмір звичайного масиву задають під час оголошення.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">int numbers[3] = {4, 7, 2};\ncout &lt;&lt; numbers[0];</pre>`, `<p class="mb-3">Створи масив із п’яти оцінок. Виведи всі елементи циклом, обчисли їхню суму й середнє значення.</p>`],
        ['Функції', `<p class="mb-3">Функція — окремий блок коду з ім’ям і, за потреби, параметрами. Тип перед іменем визначає тип значення, яке повертається.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">int add(int a, int b) {\n    return a + b;\n}</pre>`, `<p class="mb-3">Напиши функцію, яка приймає ширину й висоту прямокутника та повертає його площу. Виклич її для кількох наборів чисел.</p>`],
        ['Рядки та вектори', `<p class="mb-3"><code>string</code> зберігає текст. Контейнер <code>vector</code> — динамічний масив, який може змінювати розмір під час виконання.</p><pre class="bg-black/30 p-4 rounded-xl overflow-auto text-sm">#include &lt;string&gt;\n#include &lt;vector&gt;\nstring name = "Alex";\nvector&lt;int&gt; values = {1, 2, 3};\nvalues.push_back(4);</pre>`, `<p class="mb-3">Створи вектор із кількох цілих чисел, додай ще одне через <code>push_back()</code> і виведи його елементи. Окремо створи рядок і виведи його довжину.</p>`],
        ['Мініпроєкт: калькулятор', `<p class="mb-3">Калькулятор об’єднує введення, арифметику та умови. Спершу зчитай два числа й символ операції, а потім виконай відповідне обчислення.</p>`, `<p class="mb-3">Створи консольний калькулятор для операцій <code>+</code>, <code>-</code>, <code>*</code> і <code>/</code>. Перевір невідому операцію та не допускай ділення на нуль.</p>`]
    ]
};

function makeCourseTaskMap(curriculum) {
    const map = {};
    curriculum.forEach((lesson, index) => {
        const number = index + 1;
        map[`${number}.1`] = { title: `${number}.1`, content: { ua: `<h3 class="text-xl font-bold mb-4 text-accent">${lesson[0]}</h3>${lesson[1]}` } };
        map[`${number}.2`] = { title: `${number}.2`, content: { ua: `<h3 class="text-xl font-bold mb-4 text-accent">Практика: ${lesson[0]}</h3>${lesson[2]}` } };
    });
    return map;
}
const courseTaskMaps = {
    html_css: tasksData,
    python: makeCourseTaskMap(courseCurricula.python),
    php: makeCourseTaskMap(courseCurricula.php),
    javascript: makeCourseTaskMap(courseCurricula.javascript),
    cpp: makeCourseTaskMap(courseCurricula.cpp)
};
function activeTaskMap() { return courseTaskMaps[activeCourse] || courseTaskMaps.html_css; }
function activeLessonCount() { return courseCatalog[activeCourse]?.lessons || 30; }
function activeCourseMeta() { return courseCatalog[activeCourse] || courseCatalog.html_css; }

function getTaskContent(key) {
    const task = activeTaskMap()[key];
    const tr = translations[currentLang] || translations.ua;
    if (!task) return tr.in_development;
    if (typeof task.content === "object") return task.content[currentLang] || task.content.ua || tr.in_development;
    return task.content || tr.in_development;
}
function getTaskTitle(key) {
    const tr = translations[currentLang] || translations.ua;
    return `${key} ${key.endsWith('.1') ? tr.theory : tr.practice}`;
}
function showEmptyState() {
    const tr = translations[currentLang] || translations.ua;
    document.getElementById("introSections").classList.remove("hidden");
    document.getElementById("taskArea").innerHTML = `<div class="empty-welcome"><div class="empty-welcome-icon">⌘</div><h3>${tr.emptyTitle}</h3><p>${tr.emptyText}</p></div>`;
}
function renderCourseSelector() {
    const container = document.getElementById('courseSelector');
    container.innerHTML = '';
    Object.entries(courseCatalog).forEach(([id, course]) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `course-card${activeCourse === id ? ' selected' : ''}`;
        button.setAttribute('aria-pressed', String(activeCourse === id));
        button.innerHTML = `<span class="course-card-icon">${course.icon}</span><span class="course-card-title">${course.title}</span><span class="course-card-desc">${course.description}</span><span class="course-card-level">${course.level}</span><span class="course-card-selected">${activeCourse === id ? '✓ Обрано' : 'Обрати курс'}</span>`;
        button.addEventListener('click', () => selectCourse(id));
        container.appendChild(button);
    });
    const meta = activeCourseMeta();
    document.getElementById('currentCourseLabel').textContent = `${meta.icon} ${meta.title}`;
    document.getElementById('courseStatus').textContent = `Обраний курс зберігається у твоєму акаунті: ${meta.title}.`;
}
async function saveCourseProgress(action, extra = {}) {
    const response = await fetch('index.php?course_api=1', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ action, course: activeCourse, csrf: courseCsrf, ...extra })
    });
    const result = await response.json().catch(() => ({ ok: false, message: 'Сервер повернув некоректну відповідь.' }));
    if (!response.ok || !result.ok) throw new Error(result.message || 'Не вдалося зберегти зміни.');
    return result;
}
async function selectCourse(courseId) {
    if (!courseCatalog[courseId] || courseId === activeCourse) return;
    activeCourse = courseId;
    savedLastTask = '';
    activeSubtask = null;
    renderCourseSelector();
    renderTasks();
    showEmptyState();
    const status = document.getElementById('courseStatus');
    status.textContent = 'Зберігаю обраний курс…';
    try {
        await saveCourseProgress('save_course');
        status.textContent = `Обраний курс збережено: ${activeCourseMeta().title}. Він залишиться після повторного входу.`;
    } catch (error) {
        status.textContent = `Курс обрано, але не вдалося зберегти: ${error.message}`;
    }
}
function renderTasks() {
    const container = document.getElementById("taskList");
    const tr = translations[currentLang] || translations.ua;
    const lessonCount = activeLessonCount();
    const meta = activeCourseMeta();
    container.innerHTML = "";
    for (let i = 1; i <= lessonCount; i++) {
        const group = document.createElement('div');
        group.className = 'task-group';
        group.innerHTML = `
            <button type="button" onclick="toggleTask(${i})" class="task-item" aria-expanded="false" aria-controls="subtasks-${i}">
                <span class="task-item-icon">${String(i).padStart(2,'0')}</span>
                <span class="task-item-label">${tr.task} ${i}</span>
                <span class="task-chevron">⌄</span>
            </button>
            <div id="subtasks-${i}" class="subtasks hidden">
                <button type="button" onclick="openTask(${i},1,this)" class="subtask-item"><span>▤</span> ${i}.1 <small>${tr.theory}</small></button>
                <button type="button" onclick="openTask(${i},2,this)" class="subtask-item"><span>✎</span> ${i}.2 <small>${tr.practice}</small></button>
            </div>`;
        container.appendChild(group);
    }
    document.getElementById('taskCount').textContent = `${lessonCount} тем · ${lessonCount * 2} завдань`;
    document.getElementById('workspaceTitle').textContent = `${meta.title} — навчальні завдання`;
    document.getElementById('workspaceHint').textContent = meta.description;
}
function toggleTask(n) {
    const list = document.getElementById(`subtasks-${n}`);
    if (!list) return;
    const button = list.previousElementSibling;
    list.classList.toggle('hidden');
    if (button) button.setAttribute('aria-expanded', String(!list.classList.contains('hidden')));
}
function openTask(t, s, el, saveProgress = true) {
    const key = `${t}.${s}`;
    if (t < 1 || t > activeLessonCount() || !activeTaskMap()[key]) return;
    document.getElementById("introSections").classList.add("hidden");
    if (activeSubtask) activeSubtask.classList.remove('active');
    activeSubtask = el || null;
    if (activeSubtask) {
        activeSubtask.classList.add('active');
        const subtasks = activeSubtask.closest('.subtasks');
        if (subtasks) subtasks.classList.remove('hidden');
        if (subtasks && subtasks.previousElementSibling) subtasks.previousElementSibling.setAttribute('aria-expanded', 'true');
    }
    const taskArea = document.getElementById("taskArea");
    const tr = translations[currentLang] || translations.ua;
    taskArea.innerHTML = `
        <article class="task-card">
            <div class="task-card-top"><span class="task-number">${key}</span><span class="task-kind">${key.endsWith('.1') ? tr.theory : tr.practice}</span></div>
            <h2 class="task-title">${getTaskTitle(key)}</h2>
            <div class="task-content">${getTaskContent(key)}</div>
        </article>`;
    if (window.innerWidth < 850) taskArea.scrollIntoView({behavior:'smooth', block:'start'});
    if (saveProgress) {
        savedLastTask = key;
        saveCourseProgress('save_task', { task: key }).catch(error => {
            document.getElementById('courseStatus').textContent = `Завдання відкрите, але прогрес не збережено: ${error.message}`;
        });
    }
}
function showSuggestions() {
    const input = document.getElementById("searchInput").value.trim().toLowerCase();
    const box = document.getElementById("suggestions");
    box.innerHTML = "";
    box.classList.add("hidden");
    if (!input) return;
    let found = false;
    const tr = translations[currentLang] || translations.ua;
    for (let t = 1; t <= activeLessonCount(); t++) {
        for (let s = 1; s <= 2; s++) {
            const key = `${t}.${s}`;
            if (key.startsWith(input) || `${tr.task.toLowerCase()} ${key}`.includes(input)) {
                found = true;
                const div = document.createElement("button");
                div.type = 'button';
                div.className = "suggestion-item";
                div.textContent = `${tr.task} ${key}`;
                div.addEventListener('click', () => {
                    const subtasksDiv = document.getElementById(`subtasks-${t}`);
                    if (subtasksDiv) subtasksDiv.classList.remove('hidden');
                    const subEl = document.querySelector(`#subtasks-${t} .subtask-item:nth-child(${s})`);
                    openTask(t, s, subEl);
                    box.classList.add("hidden");
                    document.getElementById("searchInput").value = "";
                });
                box.appendChild(div);
            }
        }
    }
    if (found) box.classList.remove("hidden");
}
function init() {
    const tr = translations[currentLang] || translations.ua;
    document.getElementById("logo").innerHTML = `IT <b>Tasks Pro</b>`;
    document.getElementById("welcome").textContent = `${tr.welcome}, ${displayName}`;
    document.getElementById("welcome_sub").textContent = tr.welcome_sub;
    document.getElementById("searchInput").placeholder = tr.search_placeholder;
    document.getElementById("sidebarLabel").textContent = tr.sidebar;
    document.getElementById("statusText").textContent = tr.status;
    document.getElementById("topEyebrow").textContent = tr.eyebrow;
    document.getElementById("aiText").textContent = tr.ai;
    document.getElementById("heroTitle").textContent = tr.heroTitle;
    document.getElementById("heroText").textContent = tr.heroText;
    document.getElementById("workspaceTitle").textContent = tr.workspace;
    document.getElementById("workspaceHint").textContent = tr.hint;
    document.getElementById("taskCount").textContent = tr.count;
    document.getElementById("emptyTitle").textContent = tr.emptyTitle;
    document.getElementById("emptyText").textContent = tr.emptyText;
    document.getElementById("footerText").textContent = tr.footer;
    renderCourseSelector();
    renderTasks();
    showEmptyState();
    if (savedLastTask && /^\d{1,2}\.[12]$/.test(savedLastTask)) {
        const parts = savedLastTask.split('.').map(Number);
        if (parts[0] >= 1 && parts[0] <= activeLessonCount()) {
            const subtask = document.querySelector(`#subtasks-${parts[0]} .subtask-item:nth-child(${parts[1]})`);
            openTask(parts[0], parts[1], subtask, false);
        }
    }
}
window.addEventListener('DOMContentLoaded', init);
</script>
</body>
</html>

