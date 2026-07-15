<?php

/**
 * ВРЕМЕННЫЙ скрипт для разового запуска миграций через веб (FPM),
 * когда консольный PHP на сервере без драйвера MySQL (could not find driver).
 * Работает под тем же PHP, что и сайт, — драйвер там есть.
 *
 * Открыть в браузере ОДИН раз:
 *   https://erp.baiaholding.kz/deploy-migrate.php?key=baia-fix-2026-Xk9q
 * После успешного запуска файл будет удалён из репозитория.
 */

if (($_GET['key'] ?? '') !== 'baia-fix-2026-Xk9q') {
    http_response_code(403);
    exit('forbidden');
}

header('Content-Type: text/plain; charset=utf-8');
@set_time_limit(300);

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

echo "=== optimize:clear ===\n";
$kernel->call('optimize:clear');
echo $kernel->output()."\n";

echo "=== migrate --force ===\n";
$kernel->call('migrate', ['--force' => true]);
echo $kernel->output()."\n";

// Роли и базовые права (идемпотентно) — на случай чистой базы.
echo "=== db:seed RolePermissionSeeder ===\n";
try {
    $kernel->call('db:seed', ['--class' => 'Database\\Seeders\\RolePermissionSeeder', '--force' => true]);
    echo $kernel->output()."\n";
} catch (\Throwable $e) {
    echo 'seed skipped: '.$e->getMessage()."\n";
}

echo "=== optimize ===\n";
$kernel->call('optimize');
echo $kernel->output()."\n";

echo "\nГОТОВО. Проверьте сайт. Затем этот файл будет удалён.\n";
