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

// Как shell/cron: .proxy.env не всегда подхватывается в окружение PHP — читаем сами
$proxyEnvFile = __DIR__ . '/.proxy.env';
if (is_file($proxyEnvFile)) {
    $proxyLines = file($proxyEnvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($proxyLines as $pline) {
        $pline = trim($pline);
        if ($pline === '' || strpos($pline, '#') === 0) {
            continue;
        }
        if (preg_match('/^export\s+(\w+)=(.*)$/u', $pline, $pm)) {
            $pname = $pm[1];
            $pval = trim($pm[2], " \t\"'\r\n");
        } elseif (strpos($pline, '=') !== false) {
            [$pname, $pval] = explode('=', $pline, 2);
            $pname = trim($pname);
            $pval = trim($pval, " \t\"'\r\n");
            if ($pname === '' || strpos($pname, ' ') !== false) {
                continue;
            }
        } else {
            continue;
        }
        putenv($pname . '=' . $pval);
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

$tgProxy = getenv('TELEGRAM_HTTP_PROXY');
if ($tgProxy === false || trim((string) $tgProxy) === '') {
    $tgProxy = getenv('HTTPS_PROXY');
}
define('TELEGRAM_HTTP_PROXY', ($tgProxy !== false && trim((string) $tgProxy) !== '') ? trim((string) $tgProxy) : '');

if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}
