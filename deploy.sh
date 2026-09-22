#!/usr/bin/env bash
#
# Деплой BAIA ERP на shared-хостинге (Plesk). Запускается ПОСЛЕ Pull в Plesk → Git
# из Планировщика задач («Деплой после Pull»):
#   cd /var/www/vhosts/baiaholding.kz/erp.baiaholding.kz && bash deploy.sh
#
# Почему не «Действия развертывания» Plesk: их shell падает на системном
# /etc/profile.d/nodenv.sh хостера, команды не выполняются. Cron профиль не читает.
#
# Что делает: composer install (только если composer.lock новее vendor/),
# миграции, кеши. Фронт (public/build) собирается локально и лежит в git —
# npm на сервере не нужен. Вывод — короткий, без цветов (Plesk обрезает длинный);
# полный лог — storage/logs/deploy.log.
#
set -uo pipefail
cd "$(dirname "$0")"

PHP=/opt/plesk/php/8.3/bin/php
[ -x "$PHP" ] || PHP=php
LOG=storage/logs/deploy.log
mkdir -p storage/logs
echo "===== $(date '+%Y-%m-%d %H:%M:%S') deploy start ($(git rev-parse --short HEAD 2>/dev/null || echo '?')) =====" >> "$LOG"

step() {  # step <название> <команда…> — выполняет, пишет в лог, при ошибке печатает хвост и выходит
  local name="$1"; shift
  local out
  if out=$("$@" 2>&1); then
    printf '%s\n' "$out" >> "$LOG"
    echo "✓ $name"
  else
    local code=$?
    printf '%s\n' "$out" >> "$LOG"
    echo "✗ $name (код $code):"
    printf '%s\n' "$out" | tail -n 15
    echo "===== FAILED at $name =====" >> "$LOG"
    exit $code
  fi
}

if [ ! -f vendor/autoload.php ] || [ composer.lock -nt vendor/autoload.php ]; then
  step "composer install" "$PHP" composer.phar install --no-dev --optimize-autoloader --no-interaction --no-ansi --no-progress
else
  echo "· composer: vendor актуален, пропуск"
fi

step "migrate"        "$PHP" artisan migrate --force --no-ansi
step "optimize:clear" "$PHP" artisan optimize:clear --no-ansi
step "optimize"       "$PHP" artisan optimize --no-ansi

echo "===== deploy ok =====" >> "$LOG"
echo "✓ Готово: $(git rev-parse --short HEAD 2>/dev/null || echo '?') · $(date '+%H:%M:%S')"
