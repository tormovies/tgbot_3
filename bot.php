<?php

declare(strict_types=1);

require __DIR__ . '/config.php';

// В CLI error_log() по умолчанию уходит в syslog, а не в файл nohup — пишем в bot.log рядом со скриптом
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/bot.log');

const API_BASE = 'https://api.telegram.org/bot' . BOT_TOKEN . '/';
const STATE_ORDER_PLATFORM = 'order_platform';
const STATE_ORDER_TYPE = 'order_type';
const STATE_ORDER_DESCRIPTION = 'order_description';
const STATE_ORDER_CONTACT = 'order_contact';
const STATE_ORDER_CONFIRM = 'order_confirm';
const MAX_FILE_SIZE_BYTES = 20 * 1024 * 1024; // 20 МБ — лимит Telegram

// --- API ---

function apiRequest(string $method, array $params = []): ?array
{
    $url = API_BASE . $method;
    $payload = json_encode($params);
    // long poll getUpdates: Telegram держит до 30 с — таймаут запроса должен быть больше
    $timeout = ($method === 'getUpdates') ? 55 : 60;

    $raw = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $curlOpts = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            // 15 с часто не хватает при медленном DNS/маршруте; «0 bytes» = не установилось соединение
            CURLOPT_CONNECTTIMEOUT => 30,
            // На многих VPS сломан IPv6 — curl ждёт таймаут. Только IPv4 к api.telegram.org
            CURLOPT_IPRESOLVE => defined('CURL_IPRESOLVE_V4') ? CURL_IPRESOLVE_V4 : 1,
        ];
        curl_setopt_array($ch, $curlOpts);
        $raw = curl_exec($ch);
        $curlErr = curl_error($ch);
        $curlNo = curl_errno($ch);
        curl_close($ch);
        if ($raw === false || $curlNo !== 0) {
            error_log('Telegram cURL failed: ' . $method . ' — ' . ($curlErr !== '' ? $curlErr : 'curl_exec'));
            return null;
        }
    } else {
        $options = [
            'http' => [
                'method'  => 'POST',
                'header'  => 'Content-Type: application/json',
                'content' => $payload,
                'timeout' => (float) $timeout,
            ],
        ];
        $context = stream_context_create($options);
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            $err = error_get_last();
            error_log('Telegram HTTP failed: ' . $method . ' — ' . ($err['message'] ?? 'file_get_contents'));
            return null;
        }
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        error_log('Telegram JSON decode failed: ' . $method);
        return null;
    }
    if (($data['ok'] ?? false) !== true && !empty($data['description'])) {
        error_log('Telegram API error: ' . $data['description']);
    }
    $result = $data['result'] ?? null;
    return is_array($result) ? $result : null;
}

/** Экранирование для Markdown (Telegram), чтобы текст пользователя не ломал разбор. */
function escapeMarkdown(string $s): string
{
    return str_replace(['_', '*', '`', '['], ['\_', '\*', '\`', '\['], $s);
}

function sendMessage(
    int $chatId,
    string $text,
    ?array $replyMarkup = null,
    ?string $parseMode = null,
    bool $disableLinkPreview = false
): void {
    $params = ['chat_id' => $chatId, 'text' => $text];
    if ($replyMarkup !== null) {
        $params['reply_markup'] = json_encode($replyMarkup);
    }
    if ($parseMode !== null) {
        $params['parse_mode'] = $parseMode;
    }
    if ($disableLinkPreview) {
        $params['disable_web_page_preview'] = true;
    }
    apiRequest('sendMessage', $params);
}

function sendDocument(int $chatId, string $fileId, ?string $caption = null): void
{
    $params = ['chat_id' => $chatId, 'document' => $fileId];
    if ($caption !== null && $caption !== '') {
        $params['caption'] = $caption;
    }
    apiRequest('sendDocument', $params);
}

function sendPhoto(int $chatId, string $fileId, ?string $caption = null): void
{
    $params = ['chat_id' => $chatId, 'photo' => $fileId];
    if ($caption !== null && $caption !== '') {
        $params['caption'] = $caption;
    }
    apiRequest('sendPhoto', $params);
}

function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
{
    $params = ['callback_query_id' => $callbackQueryId];
    if ($text !== null && $text !== '') {
        $params['text'] = $text;
    }
    apiRequest('answerCallbackQuery', $params);
}

// --- Состояние (файл на пользователя) ---

function getState(int $userId): array
{
    $path = DATA_DIR . '/' . $userId . '.json';
    if (!is_file($path)) {
        return [];
    }
    $json = file_get_contents($path);
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function setState(int $userId, array $state): void
{
    $path = DATA_DIR . '/' . $userId . '.json';
    file_put_contents($path, json_encode($state, JSON_UNESCAPED_UNICODE));
}

function clearState(int $userId): void
{
    $path = DATA_DIR . '/' . $userId . '.json';
    if (is_file($path)) {
        unlink($path);
    }
}

function saveOrderToFile(array $orderData): void
{
    $dir = DATA_DIR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $path = $dir . '/orders.json';
    $orders = [];
    if (is_file($path)) {
        $json = @file_get_contents($path);
        if ($json !== false) {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $orders = $decoded;
            }
        }
    }
    $id = 1;
    foreach ($orders as $o) {
        $existingId = (int) ($o['id'] ?? 0);
        if ($existingId >= $id) {
            $id = $existingId + 1;
        }
    }
    $orderData['id'] = $id;
    $orderData['date'] = date('Y-m-d H:i:s');
    $orders[] = $orderData;
    $written = @file_put_contents($path, json_encode($orders, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    if ($written === false) {
        error_log('Order save failed: cannot write to ' . $path);
    }
}

// --- Клавиатуры ---

function mainMenuKeyboard(): array
{
    return [
        'keyboard' => [
            [['text' => '📋 Каталог'], ['text' => '💰 Цены']],
            [['text' => '📝 Заказать']],
        ],
        'resize_keyboard' => true,
    ];
}

function removeKeyboard(): array
{
    return ['remove_keyboard' => true];
}

function platformKeyboard(): array
{
    return [
        'keyboard' => [[['text' => 'MT4'], ['text' => 'MT5']]],
        'resize_keyboard' => true,
    ];
}

function typeKeyboard(): array
{
    return [
        'keyboard' => [[['text' => 'Индикатор'], ['text' => 'Советник (EA)']]],
        'resize_keyboard' => true,
    ];
}

function confirmKeyboard(): array
{
    return [
        'keyboard' => [[['text' => 'Да, отправить'], ['text' => 'Отмена']]],
        'resize_keyboard' => true,
    ];
}

function descriptionStepKeyboard(): array
{
    return [
        'keyboard' => [[['text' => 'Отправить']]],
        'resize_keyboard' => true,
    ];
}

/** Inline-кнопка под сообщением — не исчезает при фокусе на поле ввода */
function orderSubmitInlineButton(): array
{
    return [
        'inline_keyboard' => [[['text' => 'Отправить заявку', 'callback_data' => 'order_submit']]],
    ];
}

// --- Обработчики команд ---
// Что выводится и что делает каждая команда — задаётся в соответствующей функции ниже.
//
// /start   → handleStart()      — приветствие и кнопки меню
// /catalog → handleCatalog()    — текст каталога (или кнопка «Каталог»)
// /prices  → handlePrices()     — текст про цены
// /order   → handleOrderStart() — начало заказа (или кнопка «Заказать»)
// /cancel  → handleOrderCancel()— выход из сценария заказа
//
// Шаги заказа: Platform → Type → Description (текст/файлы, кнопка «Отправить заявку») → Confirm (Да/Отмена)

function handleStart(int $chatId): void
{
    $text = "Привет! Я бот по индикаторам и советникам для MT4/MT5.\n\n"
        . "Выберите действие кнопкой ниже или введите команду:\n"
        . "/catalog — каталог\n"
        . "/prices — цены\n"
        . "/order — оформить заказ\n"
        . "Чтобы снова открыть меню — /start";
    sendMessage($chatId, $text, mainMenuKeyboard());
}

function handleCatalog(int $chatId): void
{
    $text = "📋 *Каталог (MT4/MT5)*\n\n"
        . "*RSI Alerts* (1500 руб.)\n"
        . "[MT4](https://einvestor.ru/products/indikator-rsi-s-alertom) · [MT5](https://einvestor.ru/products/rsi-alerts-mt5)\n"
        . "Индикатор RSI со звуковыми, push и email уведомлениями при пересечении уровней.\n\n"
        . "*CCI Alerts* (1500 руб.)\n"
        . "[MT4](https://einvestor.ru/products/cci-alerts-dlya-mt4) · [MT5](https://einvestor.ru/products/cci-alerts-dlya-mt5)\n"
        . "Индикатор CCI со звуковыми, push и email уведомлениями при пересечении уровней.\n\n"
        . "*MFI Alerts* (1500 руб.)\n"
        . "[MT4](https://einvestor.ru/products/mfi-alerts-dlya-mt4) · [MT5](https://einvestor.ru/products/mfi-s-alertami-dlya-mt5)\n"
        . "Индикатор MFI со звуковыми, push и email уведомлениями при пересечении уровней.\n\n"
        . "*Demarker Alerts* (1500 руб.)\n"
        . "[MT4](https://einvestor.ru/products/demarker-s-alertami-dlya-mt4) · [MT5](https://einvestor.ru/products/demarker-alerts-mt5)\n"
        . "Индикатор Demarker со звуковыми, push и email уведомлениями при пересечении уровней.\n\n"
        . "*Fibo Alerts* (5000 руб.)\n"
        . "[MT4](https://einvestor.ru/products/fibo-alerts) · [MT5](https://einvestor.ru/products/fibonachchi-so-zvukovym-signalom-mt5)\n"
        . "Horizontal Channel Alert with Custom Fibo — канальный индикатор с Фибо и алертами, push и email уведомлениями.\n\n"
        . "*RSI MTF панель* (1500 руб.)\n"
        . "[MT4](https://einvestor.ru/products/panel-rsi-mtf-dlya-mt4)\n"
        . "Панель RSI: информация с нескольких таймфреймов и символов в одном месте, с уведомлениями и алертами.\n\n"
        . "*MarketView* (1500 руб.)\n"
        . "[MT4](https://einvestor.ru/products/informacionnaya-panel-marketview)\n"
        . "Информационная панель: выбранные символы, цены, изменение за день или период.";
    sendMessage($chatId, $text, null, 'Markdown', true);
}

function handlePrices(int $chatId): void
{
    $text = "💰 **Цены и условия**\n\n"
        . "• На готовые продукты — цена указана в каталоге.\n"
        . "• Разработка под заказ (MQL4/MQL5):\n"
        . "  — стоимость создания индикатора от 5000 руб.;\n"
        . "  — стоимость создания советника от 10000 руб.\n"
        . "  Стоимость может варьироваться в зависимости от сложности.\n\n"
        . "Оформите заявку через кнопку «Заказать» — ответим с расчётом.";
    sendMessage($chatId, $text, null, 'Markdown');
}

function handleOrderStart(int $chatId, int $userId): void
{
    clearState($userId);
    setState($userId, ['step' => STATE_ORDER_PLATFORM]);
    sendMessage($chatId, 'Выберите платформу:', platformKeyboard());
}

function handleOrderPlatform(int $chatId, int $userId, string $text): void
{
    $platform = mb_strtoupper(trim($text));
    if ($platform !== 'MT4' && $platform !== 'MT5') {
        sendMessage($chatId, 'Выберите MT4 или MT5 кнопкой ниже.', platformKeyboard());
        return;
    }
    $state = getState($userId);
    $state['step'] = STATE_ORDER_TYPE;
    $state['order_platform'] = $platform;
    setState($userId, $state);
    sendMessage($chatId, 'Нужен индикатор или советник?', typeKeyboard());
}

function handleOrderType(int $chatId, int $userId, string $text): void
{
    $raw = mb_strtolower(trim($text));
    $type = null;
    if (mb_strpos($raw, 'индикатор') !== false) {
        $type = 'Индикатор';
    } elseif (mb_strpos($raw, 'советник') !== false || mb_strpos($raw, 'ea') !== false) {
        $type = 'Советник (EA)';
    }
    if ($type === null) {
        sendMessage($chatId, 'Нажмите «Индикатор» или «Советник (EA)».', typeKeyboard());
        return;
    }
    $state = getState($userId);
    $state['step'] = STATE_ORDER_DESCRIPTION;
    $state['order_type'] = $type;
    $state['order_description'] = '';
    $state['order_files'] = [];
    setState($userId, $state);

    $descMsg = "Опишите задачу текстом и/или прикрепите файлы (скриншот, ТЗ в PDF и т.п.).\n\n"
        . "⚠️ Максимальный размер одного файла — 20 МБ.\n\n"
        . "Когда всё готово — нажмите кнопку *«Отправить заявку»* под этим сообщением (она не пропадёт при вводе текста).\n\n"
        . "Если файл не загружается или нужна помощь — напишите напрямую: " . ADMIN_CONTACT;
    sendMessage($chatId, $descMsg, orderSubmitInlineButton(), 'Markdown');
}

function handleOrderDescriptionText(int $chatId, int $userId, string $text): void
{
    $state = getState($userId);
    $prev = $state['order_description'] ?? '';
    $state['order_description'] = trim($prev ? $prev . "\n\n" . trim($text) : trim($text));
    setState($userId, $state);
    sendMessage($chatId, 'Принято. Когда готово — нажмите «Отправить заявку».', orderSubmitInlineButton());
}

function handleOrderDescriptionDone(int $chatId, int $userId, ?string $username): void
{
    $state = getState($userId);
    $platform = $state['order_platform'] ?? '';
    $type = $state['order_type'] ?? '';
    $desc = $state['order_description'] ?? '';
    $contact = ($username !== null && $username !== '')
        ? 'Telegram: @' . $username
        : 'Telegram ID: ' . $userId;
    $fileCount = count($state['order_files'] ?? []);

    if ($platform === '' || $type === '') {
        sendMessage($chatId, 'Что-то пошло не так. Начните заказ заново: нажмите «Заказать».', mainMenuKeyboard());
        clearState($userId);
        return;
    }

    // Сразу отправляем заявку — одна кнопка «Отправить», без шага подтверждения
    sendMessage(
        $chatId,
        '✅ Заявка отправлена. Мы свяжемся с вами для уточнения деталей и расчёта.',
        mainMenuKeyboard()
    );

    error_log(sprintf(
        "Order: platform=%s type=%s user_id=%s username=%s contact=%s files=%d desc=%s",
        $platform,
        $type,
        $userId,
        $username ?? '',
        $contact,
        $fileCount,
        mb_substr($desc, 0, 100)
    ));

    if (defined('ADMIN_CHAT_ID') && ADMIN_CHAT_ID !== null && ADMIN_CHAT_ID !== '') {
        $adminChatId = (int) ADMIN_CHAT_ID;
        $adminMsg = "🆕 *Новая заявка*\n\n"
            . "Платформа: " . escapeMarkdown($platform) . "\n"
            . "Тип: " . escapeMarkdown($type) . "\n"
            . "Описание: " . escapeMarkdown($desc) . "\n"
            . "Контакт: " . escapeMarkdown($contact) . "\n"
            . "Файлов: {$fileCount}\n";
        sendMessage($adminChatId, $adminMsg, null, 'Markdown');
        foreach ($state['order_files'] ?? [] as $file) {
            $fileId = $file['file_id'] ?? '';
            if ($fileId === '') continue;
            $name = $file['name'] ?? null;
            $caption = $name !== null ? $name : null;
            if (($file['type'] ?? '') === 'photo') {
                sendPhoto($adminChatId, $fileId, $caption);
            } else {
                sendDocument($adminChatId, $fileId, $caption);
            }
        }
    } else {
        error_log('Order: admin notification skipped — in .env set ADMIN_CHAT_ID (your Telegram chat_id)');
    }

    saveOrderToFile([
        'platform' => $platform,
        'type' => $type,
        'description' => $desc,
        'contact' => $contact,
        'file_count' => $fileCount,
        'user_id' => $userId,
        'username' => $username ?? '',
    ]);

    clearState($userId);
}

function handleOrderDescriptionDocument(int $chatId, int $userId, array $document): void
{
    $fileId = $document['file_id'] ?? '';
    $fileSize = (int) ($document['file_size'] ?? 0);
    $fileName = $document['file_name'] ?? 'файл';

    if ($fileSize > MAX_FILE_SIZE_BYTES) {
        sendMessage(
            $chatId,
            "⚠️ Файл «{$fileName}» слишком большой (лимит 20 МБ). Сожмите файл или отправьте ссылку. Если не получается — напишите напрямую: " . ADMIN_CONTACT,
            orderSubmitInlineButton()
        );
        return;
    }

    $state = getState($userId);
    $state['order_files'] = $state['order_files'] ?? [];
    $state['order_files'][] = ['type' => 'document', 'file_id' => $fileId, 'name' => $fileName];
    setState($userId, $state);
    sendMessage($chatId, 'Файл принят. Когда готово — нажмите «Отправить заявку» под сообщением.', orderSubmitInlineButton());
}

function handleOrderDescriptionPhoto(int $chatId, int $userId, array $photoSizes): void
{
    $largest = end($photoSizes);
    $fileId = $largest['file_id'] ?? '';

    $state = getState($userId);
    $state['order_files'] = $state['order_files'] ?? [];
    $state['order_files'][] = ['type' => 'photo', 'file_id' => $fileId];
    setState($userId, $state);
    sendMessage($chatId, 'Фото принято. Когда готово — нажмите «Отправить заявку» под сообщением.', orderSubmitInlineButton());
}

function handleOrderContact(int $chatId, int $userId, string $text): void
{
    $state = getState($userId);
    $state['step'] = STATE_ORDER_CONFIRM;
    $state['order_contact'] = trim($text);
    setState($userId, $state);

    $platform = $state['order_platform'] ?? '';
    $type = $state['order_type'] ?? '';
    $desc = $state['order_description'] ?? '(нет текста)';
    $contact = $state['order_contact'] ?? '';
    $files = $state['order_files'] ?? [];
    $fileCount = count($files);

    $summary = "**Проверьте заявку:**\n\n"
        . "Платформа: {$platform}\n"
        . "Тип: {$type}\n"
        . "Описание: {$desc}\n";
    if ($fileCount > 0) {
        $summary .= "Приложено файлов: {$fileCount}\n";
    }
    $summary .= "Контакт: {$contact}\n\n"
        . "Всё верно? Отправить заявку?";
    sendMessage($chatId, $summary, confirmKeyboard(), 'Markdown');
}

function handleOrderConfirm(int $chatId, int $userId, string $text, ?string $username): void
{
    $raw = mb_strtolower(trim($text));
    // Только явная отмена
    if (mb_strpos($raw, 'отмен') !== false || $raw === 'отмена') {
        clearState($userId);
        sendMessage($chatId, 'Заявка отменена.', mainMenuKeyboard());
        return;
    }

    // Любое другое сообщение в этом шаге = подтверждение (кнопка «Да, отправить» или повторное нажатие)
    $state = getState($userId);
    $platform = $state['order_platform'] ?? '';
    $type = $state['order_type'] ?? '';
    $desc = $state['order_description'] ?? '';
    $contact = $state['order_contact'] ?? '';

    // Если состояния уже нет (например заявка только что отправилась) — просто подсказка
    if ($platform === '' && $type === '') {
        clearState($userId);
        sendMessage($chatId, 'Заявка уже отправлена. Если нужен новый заказ — нажмите «Заказать» или /order.', mainMenuKeyboard());
        return;
    }

    // Сразу сообщаем пользователю — чтобы точно пришло
    sendMessage(
        $chatId,
        '✅ Заявка отправлена. Мы свяжемся с вами для уточнения деталей и расчёта.',
        mainMenuKeyboard()
    );

    $fileCount = count($state['order_files'] ?? []);
    error_log(sprintf(
            "Order: platform=%s type=%s user_id=%s username=%s contact=%s files=%d desc=%s",
            $platform,
            $type,
            $userId,
            $username ?? '',
            $contact,
            $fileCount,
            mb_substr($desc, 0, 100)
        ));

        // Отправка заявки в личный чат админа
        if (defined('ADMIN_CHAT_ID') && ADMIN_CHAT_ID !== null && ADMIN_CHAT_ID !== '') {
            $adminChatId = (int) ADMIN_CHAT_ID;
            $adminMsg = "🆕 *Новая заявка*\n\n"
                . "Платформа: " . escapeMarkdown($platform) . "\n"
                . "Тип: " . escapeMarkdown($type) . "\n"
                . "Описание: " . escapeMarkdown($desc) . "\n"
                . "Контакт: " . escapeMarkdown($contact) . "\n"
                . "Файлов: {$fileCount}\n";
            sendMessage($adminChatId, $adminMsg, null, 'Markdown');
            foreach ($state['order_files'] ?? [] as $file) {
                $fileId = $file['file_id'] ?? '';
                if ($fileId === '') {
                    continue;
                }
                $name = $file['name'] ?? null;
                $caption = $name !== null ? $name : null;
                if (($file['type'] ?? '') === 'photo') {
                    sendPhoto($adminChatId, $fileId, $caption);
                } else {
                    sendDocument($adminChatId, $fileId, $caption);
                }
            }
        } else {
            error_log('Order: admin notification skipped — in .env set ADMIN_CHAT_ID (your Telegram chat_id)');
        }

    saveOrderToFile([
        'platform' => $platform,
        'type' => $type,
        'description' => $desc,
        'contact' => $contact,
        'file_count' => $fileCount,
        'user_id' => $userId,
        'username' => $username ?? '',
    ]);

    clearState($userId);
}

function handleOrderCancel(int $chatId, int $userId): void
{
    clearState($userId);
    sendMessage($chatId, 'Оформление заказа отменено.', mainMenuKeyboard());
}

// --- Установка списка команд ---

function setMyCommands(): void
{
    $commands = [
        ['command' => 'start', 'description' => 'Старт, главное меню (каталог / цены / заказ)'],
        ['command' => 'catalog', 'description' => 'Каталог готовых индикаторов и советников (MT4/MT5)'],
        ['command' => 'prices', 'description' => 'Условия и цены: готовые продукты и разработка под заказ'],
        ['command' => 'order', 'description' => 'Оформить заказ своего индикатора/советника'],
    ];
    apiRequest('setMyCommands', ['commands' => $commands]);
}

// --- Главный цикл ---

function run(): void
{
    // Иначе при установленном webhook getUpdates не получает сообщения
    apiRequest('deleteWebhook', ['drop_pending_updates' => false]);
    setMyCommands();
    $curlOn = function_exists('curl_init') ? 'yes' : 'no';
    fwrite(STDERR, date('c') . " Bot: polling started, curl={$curlOn}\n");

    $offset = 0;
    while (true) {
        $updates = apiRequest('getUpdates', ['offset' => $offset, 'timeout' => 30]);
        if (!is_array($updates)) {
            continue;
        }
        foreach ($updates as $update) {
            $offset = $update['update_id'] + 1;

            // Inline-кнопка «Отправить заявку» (не исчезает при вводе текста)
            $callback = $update['callback_query'] ?? null;
            if ($callback !== null) {
                $cqId = $callback['id'] ?? '';
                $chatId = (int) ($callback['message']['chat']['id'] ?? 0);
                $userId = (int) ($callback['from']['id'] ?? 0);
                $username = $callback['from']['username'] ?? null;
                $data = $callback['data'] ?? '';
                if ($data === 'order_submit') {
                    $state = getState($userId);
                    if (($state['step'] ?? '') === STATE_ORDER_DESCRIPTION) {
                        handleOrderDescriptionDone($chatId, $userId, $username);
                    }
                }
                answerCallbackQuery($cqId);
                continue;
            }

            $message = $update['message'] ?? null;
            if (!$message) {
                continue;
            }
            $chatId = (int) $message['chat']['id'];
            $userId = (int) ($message['from']['id'] ?? 0);
            $username = $message['from']['username'] ?? null;
            $text = $message['text'] ?? '';

            $state = getState($userId);
            $step = $state['step'] ?? null;

            // Команды (в приоритете)
            $cmd = null;
            if (preg_match('/^\/(\w+)/', $text, $m)) {
                $cmd = $m[1];
            }

            if ($cmd === 'start') {
                handleStart($chatId);
                continue;
            }
            if ($cmd === 'catalog') {
                handleCatalog($chatId);
                continue;
            }
            if ($cmd === 'prices') {
                handlePrices($chatId);
                continue;
            }
            if ($cmd === 'order') {
                handleOrderStart($chatId, $userId);
                continue;
            }
            if ($cmd === 'cancel') {
                handleOrderCancel($chatId, $userId);
                continue;
            }

            // Кнопки главного меню — обрабатываем всегда (иначе в шаге заказа «Каталог» попадал бы в описание)
            if (preg_match('/^(?:каталог|📋 Каталог)$/ui', trim($text))) {
                if ($step !== null && $step !== '') {
                    clearState($userId);
                }
                handleCatalog($chatId);
                continue;
            }
            if (preg_match('/^(?:цены|💰 Цены)$/ui', trim($text))) {
                if ($step !== null && $step !== '') {
                    clearState($userId);
                }
                handlePrices($chatId);
                continue;
            }
            if (preg_match('/^(?:заказать|📝 Заказать)$/ui', trim($text))) {
                handleOrderStart($chatId, $userId);
                continue;
            }

            // Сценарий заказа по шагам
            if ($step === STATE_ORDER_PLATFORM) {
                handleOrderPlatform($chatId, $userId, $text);
                continue;
            }
            if ($step === STATE_ORDER_TYPE) {
                handleOrderType($chatId, $userId, $text);
                continue;
            }
            if ($step === STATE_ORDER_DESCRIPTION) {
                if (isset($message['document'])) {
                    handleOrderDescriptionDocument($chatId, $userId, $message['document']);
                } elseif (!empty($message['photo']) && is_array($message['photo'])) {
                    handleOrderDescriptionPhoto($chatId, $userId, $message['photo']);
                } elseif ($text !== '') {
                    $t = preg_replace('/[\s\r\n]+/u', ' ', trim($text));
                    $t = mb_strtolower($t);
                    $t = preg_replace('/[^\p{L}\p{M}\s]/u', '', $t);
                    $t = trim($t);
                    $isSendButton = ($t === 'отправить' || $t === 'отправитьзаявку' || $t === 'отправить заявку'
                        || $t === 'дальше');
                    if ($isSendButton) {
                        handleOrderDescriptionDone($chatId, $userId, $username);
                    } else {
                        handleOrderDescriptionText($chatId, $userId, $text);
                    }
                }
                continue;
            }
            if ($step === STATE_ORDER_CONTACT) {
                handleOrderContact($chatId, $userId, $text);
                continue;
            }
            if ($step === STATE_ORDER_CONFIRM) {
                handleOrderConfirm($chatId, $userId, $text, $username);
                continue;
            }
        }
    }
}

run();
