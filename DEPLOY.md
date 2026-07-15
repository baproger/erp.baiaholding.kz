# Деплой на сервер (Plesk + Git)

Схема: `я делаю git push → GitHub → Plesk тянет автоматически → сайт обновляется`.
Собранный фронтенд (`public/build`) лежит в репозитории, поэтому **Node на сервере не нужен**.
Plesk только копирует файлы — `composer` и `migrate` запускаются через «Дополнительные действия развертывания».

## 1. Один раз: настройка в Plesk

**Git → Настройки репозитория:**
- URL: `https://github.com/baproger/erp.baiaholding.kz.git`, ветка `master`
- Режим развёртывания: **Автоматически**
- **Путь сервера — ВАЖНО:** для Laravel деплой должен идти в КОРЕНЬ домена (например `/erp.baiaholding.kz`), а document root домена — в подпапку `/erp.baiaholding.kz/public`.
  ⚠️ Если деплой настроен прямо в `.../public`, то `app/`, `.env`, `config/` окажутся в веб-доступе — это дыра (можно скачать `.env` с паролями БД). Проверить: document root сайта = `.../public`, а Git-деплой = на уровень выше.

**Веб-хук для авто-деплоя:** скопировать «URL-адрес для веб-хука» из Plesk →
GitHub → репозиторий → Settings → Webhooks → Add webhook → вставить URL,
Content type `application/json`, событие `Just the push event`. Без этого Plesk
не узнает о новом пуше сразу (будет тянуть только по расписанию/вручную).

## 2. Один раз: файл .env на сервере

`.env` НЕ хранится в git (секреты). Создать на сервере вручную (Plesk → Файлы,
в корне проекта), минимум:

```
APP_NAME=BAIA
APP_ENV=production
APP_DEBUG=false
APP_URL=https://erp.baiaholding.kz
APP_KEY=                      # сгенерировать: php artisan key:generate
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=<имя_бд>
DB_USERNAME=<пользователь>
DB_PASSWORD=<пароль>
SESSION_SECURE_COOKIE=true
```

## ⚠️ PHP в шелле ≠ PHP сайта (важно!)

`/usr/bin/php` в Plesk-шелле — системный (8.3) и падает с
«Your Composer dependencies require a PHP version ">= 8.4.1"» + warning
про `pdo_oci.so` (безвредный, это битое расширение системного PHP).
Сайт работает на PHP 8.4 (FPM) — в консоли нужно звать ЕГО бинарник по
полному пути. Найти его:

```bash
ls /opt/plesk/php/            # Plesk-сборки: /opt/plesk/php/8.4/bin/php
ls -d /opt/alt/php*/usr/bin/php   # CloudLinux alt-php: /opt/alt/php84/usr/bin/php
```

Ниже везде `PHP84` = полный путь (пример: `/opt/plesk/php/8.4/bin/php`).

## 3. Один раз: первичная инициализация (Plesk → PHP-консоль / SSH в корне проекта)

```bash
PHP84=/opt/plesk/php/8.4/bin/php    # или /opt/alt/php84/usr/bin/php
$PHP84 composer.phar install --no-dev --optimize-autoloader
$PHP84 artisan key:generate         # если APP_KEY пустой
$PHP84 artisan migrate --force
$PHP84 artisan db:seed --force      # роли + первый админ admin@baia.kz/password — СМЕНИТЬ пароль!
$PHP84 artisan storage:link
$PHP84 artisan optimize             # config+route+view cache
```

## 4. Автоматически при каждом пуше: Plesk «Дополнительные действия развертывания»

Включить галочку «Включить дополнительные действия развертывания» и вставить
(путь к PHP — свой, см. выше):

```bash
/opt/plesk/php/8.4/bin/php composer.phar install --no-dev --optimize-autoloader
/opt/plesk/php/8.4/bin/php artisan migrate --force
/opt/plesk/php/8.4/bin/php artisan optimize
```

⚠️ **Composer и Node НЕ установлены в Plesk-шелле** (`command not found`).
Решение:
- **Composer** — сам `composer.phar` лежит в репозитории; вызываем через PHP:
  `php composer.phar install --no-dev` (PHP в шелле есть). Ставит `vendor/` на
  сервере под правильную версию PHP. Нужен исходящий интернет с сервера (обычно
  есть на Plesk).
- **Node/npm НЕ нужен** — собранный фронт `public/build/` уже в репозитории и
  приезжает с гитом.

## Права на папки (один раз, если 500-е ошибки)

```bash
chmod -R 775 storage bootstrap/cache
```
