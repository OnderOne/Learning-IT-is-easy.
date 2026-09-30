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
    <title>AI Chat - IT Tasks Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="style.css">
</head>
<body class="theme-<?= $theme ?> chat-page">

<div class="chat-header">
    <div style="display:flex;align-items:center;gap:12px;">
        <a href="index.php" class="profile-btn" style="padding:8px 14px;border-radius:12px;font-size:14px;">← Назад</a>
        <h1 style="font-size:18px;font-weight:700;">🤖 AI Помічник</h1>
    </div>
    <div style="font-size:14px;color:var(--text-muted);">
        <?= htmlspecialchars($user['name'] ?? $user['username']) ?>
    </div>
</div>

<div id="chatBox" class="chat-container"></div>

<div class="chat-footer">
    <div class="chat-input-wrap">
        <label class="file-btn" title="Прикріпити файл">
            📎
            <input type="file" id="fileInput" hidden onchange="handleFile(this)">
        </label>

        <input id="userInput" type="text" class="chat-input" 
               placeholder="Напиши питання..." 
               onkeydown="if(event.key === 'Enter') sendMessage()">

        <button class="chat-btn" onclick="sendMessage()">Надіслати</button>
    </div>
    <div id="fileName" class="file-name"></div>
</div>

<script>
const GROQ_API_KEY = "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";
const chatBox = document.getElementById('chatBox');
const userInput = document.getElementById('userInput');
const fileName = document.getElementById('fileName');
let selectedFile = null;

const userName = "<?= htmlspecialchars($user['name'] ?? $user['username']) ?>";
let messages = [
    {
        role: "system",
        content: "Ти дружній AI-помічник на сайті IT Tasks Pro. Відповідай українською мовою (якщо користувач пише українською). Допомагай з HTML, CSS, JavaScript, PHP, MySQL та програмуванням. Відповідай коротко, зрозуміло і з прикладами коду якщо потрібно. Якщо питання не по темі — ввічливо скажи, що краще допомагаєш з програмуванням."
    }
];

function addMessage(text, isUser = false) {
    const row = document.createElement('div');
    row.className = 'message-row ' + (isUser ? 'user' : 'bot');

    const bubble = document.createElement('div');
    bubble.className = 'message ' + (isUser ? 'user' : 'bot');
    bubble.innerHTML = text;

    const meta = document.createElement('div');
    meta.className = 'message-meta';
    meta.textContent = isUser ? userName : 'AI Помічник';

    row.appendChild(bubble);
    row.appendChild(meta);
    chatBox.appendChild(row);
    chatBox.scrollTop = chatBox.scrollHeight;
}

function handleFile(input) {
    if (input.files && input.files[0]) {
        selectedFile = input.files[0];
        fileName.textContent = '📎 ' + selectedFile.name;
    }
}

async function sendMessage() {
    const text = userInput.value.trim();
    if (!text && !selectedFile) return;
    if (selectedFile && selectedFile.type.startsWith('image/')) {
    const reader = new FileReader();

    reader.onload = async function(e) {
        const imageData = e.target.result;

        let messageText = text ? text + '<br><br>' : '';
        messageText += `<img src="${imageData}" style="max-width:260px;border-radius:12px;">`;

        addMessage(messageText, true);

        userInput.value = '';
        fileName.textContent = '';
        selectedFile = null;
        document.getElementById('fileInput').value = '';

        await askAI(text || "Що ти бачиш на цьому зображенні?", imageData);
    };

    reader.readAsDataURL(selectedFile);
    return;
}

    let messageText = text;
    if (selectedFile) {
        messageText += (text ? '<br>' : '') + '📎 Файл: <b>' + selectedFile.name + '</b>';
    }

    addMessage(messageText, true);
    userInput.value = '';
    fileName.textContent = '';
    selectedFile = null;
    document.getElementById('fileInput').value = '';

    await askAI(text);
}

async function askAI(userText) {
    const thinkingId = 'thinking-' + Date.now();
    addMessage('<span id="' + thinkingId + '">Думаю...</span>', false);

    messages.push({ role: "user", content: userText });

    try {
        const response = await fetch("https://api.groq.com/openai/v1/chat/completions", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "Authorization": "Bearer " + GROQ_API_KEY
            },
            body: JSON.stringify({
                model: "openai/gpt-oss-20b",
                messages: messages,
                temperature: 0.7,
                max_tokens: 1024
            })
        });

        const data = await response.json();
        const thinkingEl = document.getElementById(thinkingId);
        if (thinkingEl) {
            thinkingEl.parentElement.parentElement.remove();
        }

        if (data.choices && data.choices[0]) {
            const reply = data.choices[0].message.content;
            messages.push({ role: "assistant", content: reply });
            const formatted = reply
                .replace(/\n/g, '<br>')
                .replace(/```([\s\S]*?)```/g, '<pre style="background:rgba(0,0,0,0.3);padding:12px;border-radius:10px;overflow:auto;margin:8px 0;">$1</pre>');

            addMessage(formatted);
        } else {
            addMessage("Вибач, щось пішло не так. Спробуй ще раз.");
            console.error(data);
        }
    } catch (err) {
        const thinkingEl = document.getElementById(thinkingId);
        if (thinkingEl) thinkingEl.parentElement.parentElement.remove();
        
        addMessage("Помилка з'єднання з AI. Перевір інтернет або ключ.");
        console.error(err);
    }
}
addMessage('Привіт, ' + userName + '! Я AI-помічник на базі Llama 3.3. Задавай будь-які питання по HTML, CSS, JavaScript, PHP та програмуванню.');
</script>
</body>
</html>