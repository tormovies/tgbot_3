#!/bin/bash
# Монитор бота в .../100: проверяет, запущен ли процесс, и перезапускает при падении.
# Запускать по cron каждые 1–2 минуты от пользователя admin.

BOT_SCRIPT="/home/admin/domains/website.com.ru/public_html/100/bot.php"
PHP="/usr/local/php83/bin/php"
DIR="/home/admin/domains/website.com.ru/public_html/100"
LOG="$DIR/monitor.log"

if pgrep -f "public_html/100/bot.php" > /dev/null; then
    exit 0
fi

echo "$(date '+%Y-%m-%d %H:%M:%S') Bot was down, restarting" >> "$LOG"
cd "$DIR"
nohup "$PHP" "$BOT_SCRIPT" >> bot.log 2>&1 &
