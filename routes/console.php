<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Schedule;

Schedule::command('tasks:notify-overdue')->hourly();
// Дни рождения: раз в день утром, чтобы «сегодня»/«через 3 дня» не дублировались.
Schedule::command('users:notify-birthdays')->dailyAt('09:00');
// Сегодня заканчивается тендер лота → уведомление его менеджеру.
Schedule::command('pre-deals:notify-tender-deadline')->dailyAt('09:00');
// Долги сотрудников: 1-го числа гасим за ПРОШЛЫЙ месяц (его бонусы уже
// начислены целиком). Команда идемпотентна — повторный прогон не удвоит.
Schedule::command('debts:charge')->monthlyOn(1, '03:00');
