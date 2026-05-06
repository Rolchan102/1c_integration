#!/bin/bash
# run_every_15sec.sh — цикл: запуск задачи каждые 15 секунд в течение минуты (4 итерации)

PHP_SCRIPT="$1"
LOG_FILE="$2"
LOCK_NAME="$3"  # например: step1

LOCKFILE="/tmp/1c_integration_locks/${LOCK_NAME}.lock"
PIDFILE="/tmp/1c_integration_locks/${LOCK_NAME}.loop.pid"

# === Защита от двойного запуска самого цикла ===
if [[ -f "$PIDFILE" ]]; then
    OLD_PID=$(cat "$PIDFILE" 2>/dev/null)
    if [[ -n "$OLD_PID" ]] && kill -0 "$OLD_PID" 2>/dev/null; then
        # Процесс ещё работает — выходим без ошибки
        exit 0
    else
        # "Мёртвый" PID — чистим
        rm -f "$PIDFILE" "$LOCKFILE"
    fi
fi
echo $$ > "$PIDFILE"

# === Цикл: 4 итерации по 15 секунд = 1 минута ===
for i in {1..4}; do
    # Запуск с блокировкой (неблокирующей): если предыдущий ещё работает — этот пропустится
    /usr/bin/flock -n "$LOCKFILE" \
        /usr/bin/php "$PHP_SCRIPT" >> "$LOG_FILE" 2>&1
    
    # Пауза 15 сек, кроме последней итерации
    [[ $i -lt 4 ]] && sleep 15
done

# Очистка PID-файла
rm -f "$PIDFILE"
