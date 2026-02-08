<?php

$envFile = __DIR__ . '/.env';
if (is_file($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        if (strpos($line, '=') !== false) {
            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value, " \t\"'\r\n");
            putenv($name . '=' . $value);
        }
    }
}

$token = getenv('BOT_TOKEN');
if (!$token) {
    fwrite(STDERR, "Задайте BOT_TOKEN в .env (скопируй из .env.example)\n");
    exit(1);
}

define('BOT_TOKEN', $token);
define('DATA_DIR', __DIR__ . '/data');
// Прямой контакт, если не получается отправить файл или есть вопросы (укажи в .env или здесь)
$adminContact = getenv('ADMIN_CONTACT');
define('ADMIN_CONTACT', ($adminContact !== false && $adminContact !== '') ? $adminContact : 'Telegram: @username');

$adminChatId = getenv('ADMIN_CHAT_ID');
define('ADMIN_CHAT_ID', ($adminChatId !== false && $adminChatId !== '') ? trim($adminChatId) : null);

if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}
