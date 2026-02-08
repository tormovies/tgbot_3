<?php

declare(strict_types=1);

require __DIR__ . '/config.php';

const API_BASE = 'https://api.telegram.org/bot' . BOT_TOKEN . '/';
const STATE_ORDER_PLATFORM = 'order_platform';
const STATE_ORDER_TYPE = 'order_type';
const STATE_ORDER_DESCRIPTION = 'order_description';
const STATE_ORDER_CONTACT = 'order_contact';
const STATE_ORDER_CONFIRM = 'order_confirm';

// --- API ---

function apiRequest(string $method, array $params = []): ?array
{
    $url = API_BASE . $method;
    $options = [
        'http' => [
            'method'  => 'POST',
            'header'  => 'Content-Type: application/json',
            'content' => json_encode($params),
        ],
    ];
    $context = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);
    if ($result === false) {
        return null;
    }
    $data = json_decode($result, true);
    $result = $data['result'] ?? null;
    return is_array($result) ? $result : null;
}

function sendMessage(
    int $chatId,
    string $text,
    ?array $replyMarkup = null,
    ?string $parseMode = null
): void {
    $params = ['chat_id' => $chatId, 'text' => $text];
    if ($replyMarkup !== null) {
        $params['reply_markup'] = json_encode($replyMarkup);
    }
    if ($parseMode !== null) {
        $params['parse_mode'] = $parseMode;
    }
    apiRequest('sendMessage', $params);
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

// --- Обработчики команд ---
// Что выводится и что делает каждая команда — задаётся в соответствующей функции ниже.
//
// /start   → handleStart()      — приветствие и кнопки меню
// /catalog → handleCatalog()    — текст каталога (или кнопка «Каталог»)
// /prices  → handlePrices()     — текст про цены
// /help    → handleHelp()       — подсказка по командам
// /order   → handleOrderStart() — начало заказа (или кнопка «Заказать»)
// /cancel  → handleOrderCancel()— выход из сценария заказа
//
// Шаги заказа: handleOrderPlatform → handleOrderType → handleOrderDescription
//              → handleOrderContact → handleOrderConfirm

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
    $text = "📋 **Каталог (MT4/MT5)**\n\n"
        . "Здесь будет список готовых индикаторов и советников.\n"
        . "Пока каталог заполняется — напишите, что ищете, через кнопку «Заказать».";
    sendMessage($chatId, $text, null, 'Markdown');
}

function handlePrices(int $chatId): void
{
    $text = "💰 **Цены и условия**\n\n"
        . "• Готовые продукты — цена указана в каталоге.\n"
        . "• Разработка под заказ (MQL4/MQL5) — стоимость и сроки после описания задачи.\n\n"
        . "Оформите заявку через кнопку «Заказать» — ответим с расчётом.";
    sendMessage($chatId, $text, null, 'Markdown');
}

function handleHelp(int $chatId): void
{
    $text = "**Что умеет бот:**\n\n"
        . "/start — главное меню\n"
        . "/catalog — каталог индикаторов и советников MT4/MT5\n"
        . "/prices — цены и условия\n"
        . "/order — оформить заказ своего индикатора или советника\n"
        . "/help — эта подсказка\n\n"
        . "Можно пользоваться кнопками под сообщениями вместо команд.";
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
    setState($userId, $state);
    sendMessage(
        $chatId,
        'Опишите задачу: что должно делать, на каком таймфрейме, какие условия. Чем подробнее — тем точнее расчёт.',
        removeKeyboard()
    );
}

function handleOrderDescription(int $chatId, int $userId, string $text): void
{
    $state = getState($userId);
    $state['step'] = STATE_ORDER_CONTACT;
    $state['order_description'] = trim($text);
    setState($userId, $state);
    sendMessage($chatId, 'Как с вами связаться? (Telegram уже есть; можно дописать email или телефон.)');
}

function handleOrderContact(int $chatId, int $userId, string $text): void
{
    $state = getState($userId);
    $state['step'] = STATE_ORDER_CONFIRM;
    $state['order_contact'] = trim($text);
    setState($userId, $state);

    $platform = $state['order_platform'] ?? '';
    $type = $state['order_type'] ?? '';
    $desc = $state['order_description'] ?? '';
    $contact = $state['order_contact'] ?? '';

    $summary = "**Проверьте заявку:**\n\n"
        . "Платформа: {$platform}\n"
        . "Тип: {$type}\n"
        . "Описание: {$desc}\n"
        . "Контакт: {$contact}\n\n"
        . "Всё верно? Отправить заявку?";
    sendMessage($chatId, $summary, confirmKeyboard(), 'Markdown');
}

function handleOrderConfirm(int $chatId, int $userId, string $text, ?string $username): void
{
    $raw = mb_strtolower(trim($text));
    if (mb_strpos($raw, 'отмен') !== false || $raw === 'отмена') {
        clearState($userId);
        sendMessage($chatId, 'Заявка отменена.', mainMenuKeyboard());
        return;
    }
    if (mb_strpos($raw, 'да') !== false || mb_strpos($raw, 'отправить') !== false) {
        $state = getState($userId);
        $platform = $state['order_platform'] ?? '';
        $type = $state['order_type'] ?? '';
        $desc = $state['order_description'] ?? '';
        $contact = $state['order_contact'] ?? '';

        sendMessage(
            $chatId,
            '✅ Заявка отправлена. Мы свяжемся с вами для уточнения деталей и расчёта.',
            mainMenuKeyboard()
        );

        error_log(sprintf(
            "Order: platform=%s type=%s user_id=%s username=%s contact=%s desc=%s",
            $platform,
            $type,
            $userId,
            $username ?? '',
            $contact,
            mb_substr($desc, 0, 100)
        ));

        clearState($userId);
        return;
    }
    sendMessage($chatId, 'Нажмите «Да, отправить» или «Отмена».', confirmKeyboard());
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
        ['command' => 'help', 'description' => 'Краткая подсказка по боту'],
    ];
    apiRequest('setMyCommands', ['commands' => $commands]);
}

// --- Главный цикл ---

function run(): void
{
    setMyCommands();

    $offset = 0;
    while (true) {
        $updates = apiRequest('getUpdates', ['offset' => $offset, 'timeout' => 30]);
        if (!is_array($updates)) {
            continue;
        }
        foreach ($updates as $update) {
            $offset = $update['update_id'] + 1;
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
            if ($cmd === 'help') {
                handleHelp($chatId);
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

            // Кнопки главного меню (без состояния заказа)
            if ($step === null || $step === '') {
                if (preg_match('/^(?:каталог|📋 Каталог)$/ui', trim($text))) {
                    handleCatalog($chatId);
                    continue;
                }
                if (preg_match('/^(?:цены|💰 Цены)$/ui', trim($text))) {
                    handlePrices($chatId);
                    continue;
                }
                if (preg_match('/^(?:заказать|📝 Заказать)$/ui', trim($text))) {
                    handleOrderStart($chatId, $userId);
                    continue;
                }
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
                handleOrderDescription($chatId, $userId, $text);
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
