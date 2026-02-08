#!/bin/bash
# Проверка: запущен ли бот в .../100. Запускать из любой папки.

if pgrep -f "public_html/100/bot.php" > /dev/null; then
    echo "Бот запущен"
    ps -o pid,etime -p $(pgrep -f "public_html/100/bot.php") 2>/dev/null | tail -1
else
    echo "Бот не запущен"
fi
