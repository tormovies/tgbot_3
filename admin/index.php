<?php
/**
 * Просмотр заказов. Доступ по паролю из .env (ADMIN_PANEL_PASSWORD).
 * URL: https://ваш-домен/100/admin/
 * Логин: любой (например admin), пароль — из .env
 */

declare(strict_types=1);

$envFile = __DIR__ . '/../.env';
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

$panelPassword = getenv('ADMIN_PANEL_PASSWORD');
$dataDir = __DIR__ . '/../data';
$ordersFile = $dataDir . '/orders.json';

if ($panelPassword === false || $panelPassword === '') {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Не настроено</title></head><body>';
    echo '<p>Добавьте в .env: <code>ADMIN_PANEL_PASSWORD=ваш_пароль</code></p>';
    echo '</body></html>';
    exit;
}

// Проверка пароля (HTTP Basic Auth — браузер покажет окно ввода)
$user = $_SERVER['PHP_AUTH_USER'] ?? '';
$pass = $_SERVER['PHP_AUTH_PW'] ?? '';
if ($pass !== $panelPassword) {
    header('WWW-Authenticate: Basic realm="Админка заказов"');
    header('Content-Type: text/html; charset=utf-8');
    http_response_code(401);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Вход</title></head><body>';
    echo '<p>Неверный пароль. Логин может быть любым (например <code>admin</code>).</p></body></html>';
    exit;
}

$orders = [];
if (is_file($ordersFile)) {
    $json = file_get_contents($ordersFile);
    $decoded = json_decode($json, true);
    if (is_array($decoded)) {
        $orders = array_reverse($decoded);
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Заказы — админка бота</title>
    <style>
        body { font-family: sans-serif; margin: 1rem; max-width: 900px; }
        h1 { font-size: 1.25rem; }
        table { border-collapse: collapse; width: 100%; margin-top: 1rem; }
        th, td { border: 1px solid #ccc; padding: 0.5rem; text-align: left; vertical-align: top; }
        th { background: #f0f0f0; }
        .desc { max-width: 300px; word-break: break-word; }
        .meta { font-size: 0.9rem; color: #666; }
        .empty { color: #666; margin-top: 1rem; }
    </style>
</head>
<body>
    <h1>Заказы (новые сверху)</h1>
    <p class="meta">Всего: <?= count($orders) ?></p>

<?php if (empty($orders)): ?>
    <p class="empty">Заказов пока нет.</p>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>№</th>
                <th>Дата</th>
                <th>Платформа</th>
                <th>Тип</th>
                <th>Описание</th>
                <th>Контакт</th>
                <th>Файлов</th>
                <th>User ID / @username</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($orders as $o): ?>
            <tr>
                <td><?= htmlspecialchars((string) ($o['id'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string) ($o['date'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string) ($o['platform'] ?? '')) ?></td>
                <td><?= htmlspecialchars((string) ($o['type'] ?? '')) ?></td>
                <td class="desc"><?= nl2br(htmlspecialchars((string) ($o['description'] ?? ''))) ?></td>
                <td><?= htmlspecialchars((string) ($o['contact'] ?? '')) ?></td>
                <td><?= (int) ($o['file_count'] ?? 0) ?></td>
                <td><?= htmlspecialchars((string) ($o['user_id'] ?? '')) ?>
                    <?php if (!empty($o['username'])): ?> / @<?= htmlspecialchars($o['username']) ?><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</body>
</html>
