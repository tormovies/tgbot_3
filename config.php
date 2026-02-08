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
            putenv(trim($name) . '=' . trim($value, " \t\"'"));
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
define('ADMIN_CONTACT', getenv('ADMIN_CONTACT') ?: 'Telegram: @username');

if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}
