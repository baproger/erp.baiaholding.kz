<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Индексы под реальные запросы (аудит производительности 22.09.2026, хостинг
 * 1 ГБ / 2 vCPU). Логику не меняют — только убирают full scan там, где
 * страницы фильтруют/сортируют по этим колонкам. Идемпотентно.
 */
return new class extends Migration
{
    /** @var array<string, array<string, array<int, string>>> таблица => имя индекса => колонки */
    private const INDEXES = [
        'audit_logs' => [
            'audit_logs_table_record_idx' => ['table_name', 'record_id'],
            'audit_logs_user_created_idx' => ['user_id', 'created_at'],
        ],
        'projects' => [
            'projects_workshop_idx' => ['workshop'],
            'projects_deadline_idx' => ['deadline'],
        ],
        'notifications' => [
            'notifications_notifiable_read_idx' => ['notifiable_type', 'notifiable_id', 'read_at'],
            'notifications_notifiable_created_idx' => ['notifiable_type', 'notifiable_id', 'created_at'],
        ],
        'expenses' => [
            'expenses_type_idx' => ['type'],
            'expenses_status_date_idx' => ['status', 'date'],
            'expenses_company_status_date_idx' => ['company_id', 'status', 'date'],
        ],
        'error_logs' => [
            'error_logs_user_idx' => ['user_id'],
        ],
        'deals' => [
            'deals_company_status_created_idx' => ['company_id', 'status', 'created_at'],
            'deals_company_deadline_idx' => ['company_id', 'deadline'],
        ],
        'pre_deals' => [
            'pre_deals_company_created_idx' => ['company_id', 'created_at'],
            'pre_deals_status_idx' => ['status'],
        ],
        'cash_receipts' => [
            'cash_receipts_company_method_idx' => ['company_id', 'method'],
        ],
        'chats' => [
            'chats_updated_at_idx' => ['updated_at'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $existing = collect(Schema::getIndexes($table))->pluck('name')->all();
            foreach ($indexes as $name => $columns) {
                if (in_array($name, $existing, true)) {
                    continue;
                }
                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $existing = collect(Schema::getIndexes($table))->pluck('name')->all();
            foreach (array_keys($indexes) as $name) {
                if (in_array($name, $existing, true)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }
};
