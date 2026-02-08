# Деплой бота на сервер

**Доступ:**
```bash
ssh root@195.62.53.151
su - admin
```
Пушить в git и деплоить **только из-под пользователя admin**.

**Путь бота на сервере:** `/home/admin/domains/website.com.ru/public_html/100`

**PHP и Composer:**
- PHP: `/usr/local/php83/bin/php`
- Composer: `/usr/local/php83/bin/php /usr/local/bin/composer`

---

## Первая настройка на сервере (один раз)

Подключиться как admin (см. выше), затем:

```bash
# Создать папку 100 и перейти в неё
mkdir -p /home/admin/domains/website.com.ru/public_html/100
cd /home/admin/domains/website.com.ru/public_html/100

# Клонировать репозиторий в текущую папку (файлы бота окажутся в .../100/)
git clone https://github.com/tormovies/tgbot_3.git .
# точку в конце — клонировать в текущую папку, а не в подпапку tgbot_3

# Создать .env с токеном бота
echo 'BOT_TOKEN=твой_токен_от_BotFather' > .env
# Или: nano .env  и вписать BOT_TOKEN=...

# Проверка запуска (полный путь — чтобы потом гасить только этого бота)
/usr/local/php83/bin/php /home/admin/domains/website.com.ru/public_html/100/bot.php
# Остановить: Ctrl+C
```

---

## Запуск бота постоянно (демон)

Запускать **только с полным путём к bot.php** — тогда можно остановить именно этого бота, не задевая остальные.

**Вариант 1: screen**
```bash
cd /home/admin/domains/website.com.ru/public_html/100
screen -S tgbot_100
/usr/local/php83/bin/php /home/admin/domains/website.com.ru/public_html/100/bot.php
# Отсоединиться: Ctrl+A, затем D
# Вернуться: screen -r tgbot_100
# Остановить бота: зайти screen -r tgbot_100 → Ctrl+C
```

**Вариант 2: nohup**
```bash
cd /home/admin/domains/website.com.ru/public_html/100
nohup /usr/local/php83/bin/php /home/admin/domains/website.com.ru/public_html/100/bot.php > bot.log 2>&1 &
```
**Остановить только этого бота** (по пути к скрипту, другие боты не трогает):
```bash
pkill -f "public_html/100/bot.php"
```

**Монитор (автоперезапуск при падении)**  
В репозитории есть скрипт `monitor.sh`: раз в N минут проверяет, запущен ли бот, и при необходимости перезапускает его. После `git pull` в папке `100` будет и `monitor.sh`.

Включить монитор через cron под **admin**:
```bash
crontab -e
```
Добавить строку (проверка каждые 2 минуты):
```
*/2 * * * * /bin/bash /home/admin/domains/website.com.ru/public_html/100/monitor.sh
```
Сохранить и выйти. Убедиться, что скрипт исполняемый:
```bash
chmod +x /home/admin/domains/website.com.ru/public_html/100/monitor.sh
```
О перезапусках пишется в `monitor.log` в той же папке.

**Вариант 3: systemd** (если на сервере есть systemd)  
Файл `/etc/systemd/system/tgbot.service` (создать от root):
```ini
[Unit]
Description=Telegram bot MT4/MT5
After=network.target

[Service]
Type=simple
User=admin
WorkingDirectory=/home/admin/domains/website.com.ru/public_html/100
ExecStart=/usr/local/php83/bin/php /home/admin/domains/website.com.ru/public_html/100/bot.php
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```
Затем от root: `systemctl daemon-reload && systemctl enable tgbot && systemctl start tgbot`

---

## Обновление (после git push с локальной машины)

На сервере под **admin**:

```bash
cd /home/admin/domains/website.com.ru/public_html/100
git pull origin master
# Перезапустить бота:
# systemd: sudo systemctl restart tgbot
# nohup: pkill -f "public_html/100/bot.php" ; nohup /usr/local/php83/bin/php /home/admin/domains/website.com.ru/public_html/100/bot.php > bot.log 2>&1 &
# screen: screen -r tgbot_100 → Ctrl+C → снова запустить полной командой
```

---

## Кратко: деплой после изменений

1. **Локально:** коммит и пуш (из-под admin или с своей машины в репо).
2. **На сервере** под admin:
   ```bash
   cd /home/admin/domains/website.com.ru/public_html/100
   git pull origin master
   # перезапустить бота (см. выше)
   ```

---

## Composer (если появятся зависимости)

```bash
cd /home/admin/domains/website.com.ru/public_html/100
/usr/local/php83/bin/php /usr/local/bin/composer install
```
