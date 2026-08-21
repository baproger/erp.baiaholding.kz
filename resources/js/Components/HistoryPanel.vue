<script setup>
// История изменений — читаемая лента, а не JSON-дамп: «создал(а) сделку»
// раскрывается в короткие чипы ключевых полей, правки — «Поле: было → стало».
import { money } from '@/utils/format';

defineProps({ history: { type: Array, default: () => [] } });

const actionLabel = { created: 'создал(а)', updated: 'изменил(а)', deleted: 'удалил(а)' };
const actionDot = { created: 'bg-emerald-500', updated: 'bg-amber-500', deleted: 'bg-rose-500' };
const fieldRu = {
    name: 'Название', number: '№', budget: 'Сумма договора', status: 'Статус', deal_stage_id: 'Этап',
    project_stage_id: 'Этап', responsible_user_id: 'Ответственный', deadline: 'Срок', contract_date: 'Дата договора',
    description: 'Описание', note: 'Заметка', client_name: 'Товар', company_name: 'Заказчик', bin: '№ договора',
    address: 'Адрес', source: 'Источник', partner_pct: 'Доля партнёра', lot_number: 'Кол-во', unit: 'Ед.',
    closed_at: 'Закрытие', bonus_rate_override: 'Бонус, %', company_id: 'Фирма',
};
const statusRu = { active: 'активна', closed: 'закрыта', cancelled: 'отменена' };
const money0 = (v) => (v === null || v === undefined || v === '' ? '∅' : money(v));
const fmtVal = (field, v) => {
    if (v === null || v === undefined || v === '') return '∅';
    if (field === 'budget') return money0(v);
    if (field === 'partner_pct' || field === 'bonus_rate_override') return Number(v) + '%';
    if (field === 'status') return statusRu[v] ?? v;
    const s = String(v);
    return s.length > 90 ? s.slice(0, 90) + '…' : s;
};
const fmt = (t) => new Date(t).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });

// «Создал(а)»: new_value — JSON всех полей. Показываем только осмысленные чипы.
const SHOW_ON_CREATE = ['number', 'company_name', 'client_name', 'budget', 'source', 'partner_pct', 'deadline', 'status'];
const createdChips = (log) => {
    try {
        const data = typeof log.new_value === 'string' ? JSON.parse(log.new_value) : (log.new_value ?? {});
        if (!data || typeof data !== 'object') return [];
        return SHOW_ON_CREATE.filter((k) => data[k] !== null && data[k] !== undefined && data[k] !== '')
            .map((k) => ({ k, label: fieldRu[k] ?? k, value: fmtVal(k, data[k]) }));
    } catch (e) { return []; }
};
const isCreate = (log) => log.action === 'created' || log.field_name === '*';
</script>

<template>
    <div class="rounded-2xl border border-slate-200/70 bg-gradient-to-br from-slate-50/80 via-white/60 to-indigo-50/40 p-4 backdrop-blur">
        <div class="space-y-0">
            <div v-for="(log, i) in history" :key="log.id" class="flex gap-3">
                <div class="flex flex-col items-center">
                    <span class="mt-1.5 h-2.5 w-2.5 rounded-full ring-4 ring-white/70" :class="actionDot[log.action] || 'bg-slate-400'"></span>
                    <span v-if="i < history.length - 1" class="w-px flex-1 bg-slate-200/80"></span>
                </div>
                <div class="min-w-0 flex-1 pb-4">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                        <div class="text-sm text-slate-800">
                            <span class="font-medium">{{ log.user?.name ?? 'Система' }}</span>
                            <span class="text-slate-500"> {{ actionLabel[log.action] || log.action }}</span>
                            <template v-if="isCreate(log)"><span class="text-slate-500"> запись</span></template>
                            <template v-else-if="log.field_name"><span class="text-slate-500"> · </span><span class="font-medium text-slate-700">{{ fieldRu[log.field_name] || log.field_name }}</span></template>
                        </div>
                        <span class="whitespace-nowrap text-[11px] tabular-nums text-slate-400">{{ fmt(log.created_at) }}</span>
                    </div>
                    <!-- Создание: чипы ключевых полей вместо JSON -->
                    <div v-if="isCreate(log)" class="mt-1.5 flex flex-wrap gap-1.5">
                        <span v-for="c in createdChips(log)" :key="c.k" class="inline-flex items-center gap-1 rounded-full bg-white/80 px-2.5 py-0.5 text-[11px] ring-1 ring-inset ring-slate-200">
                            <span class="text-slate-400">{{ c.label }}</span><span class="font-medium tabular-nums text-slate-700">{{ c.value }}</span>
                        </span>
                        <span v-if="!createdChips(log).length" class="text-[11px] text-slate-400">без деталей</span>
                    </div>
                    <!-- Правка: было → стало -->
                    <div v-else-if="log.field_name" class="mt-1 inline-flex max-w-full flex-wrap items-center gap-1.5 rounded-lg bg-white/80 px-2.5 py-1 text-xs ring-1 ring-inset ring-slate-200">
                        <span class="truncate text-rose-500 line-through decoration-rose-300">{{ fmtVal(log.field_name, log.old_value) }}</span>
                        <span class="text-slate-300">→</span>
                        <span class="truncate font-medium text-emerald-700">{{ fmtVal(log.field_name, log.new_value) }}</span>
                    </div>
                </div>
            </div>
            <div v-if="!history.length" class="py-6 text-center text-sm text-slate-400">История пуста</div>
        </div>
    </div>
</template>
