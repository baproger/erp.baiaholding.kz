<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { confirmDialog } from '@/composables/useConfirm';
import { useStickyFilters } from '@/composables/useStickyFilters';
import { money, formatDate } from '@/utils/format';

const props = defineProps({
    month: String,
    pending: { type: Array, default: () => [] },
    confirmed: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({ pending: 0, confirmed: 0, pending_count: 0 }) },
    canManage: { type: Boolean, default: false },
});

const monthSel = ref(props.month);
const setMonth = () => router.get(route('expenses.board'), { month: monthSel.value || undefined },
    { preserveState: true, preserveScroll: true, replace: true });
useStickyFilters('expense-board', { monthSel }, setMonth);

const monthLabel = computed(() => new Date(props.month + '-01T00:00:00')
    .toLocaleDateString('ru-RU', { month: 'long', year: 'numeric' }));

// Подтверждение: бухгалтер решает, откуда платить, и прикладывает чек.
const confirmFor = ref(null);
const cForm = useForm({ payment_method: 'cash', file: null });
const openConfirm = (e) => { cForm.reset(); cForm.clearErrors(); confirmFor.value = e.id; };
const submitConfirm = (e) => cForm.patch(route('expenses.confirm', e.id), {
    preserveScroll: true, forceFormData: true, onSuccess: () => (confirmFor.value = null),
});

const del = async (e) => {
    if (!(await confirmDialog({
        title: 'Удалить расход', message: `Расход на ${money(e.amount)} будет удалён.`,
        confirmText: 'Удалить', danger: true,
    }))) return;
    router.delete(route('expenses.destroy', e.id), { preserveScroll: true });
};
</script>

<template>
    <Head title="Расходы" />
    <AppLayout>
        <template #header>
            <div class="flex w-full min-w-0 flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <span class="truncate">Расходы</span>
                <label class="flex items-center gap-1 text-xs font-normal text-slate-400">месяц
                    <input v-model="monthSel" @change="setMonth" type="month" class="rounded-lg border-slate-200 py-1.5 text-xs font-normal shadow-sm" />
                </label>
            </div>
        </template>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div class="rounded-2xl border p-4" :class="totals.pending_count ? 'border-amber-300 bg-amber-50' : 'border-slate-200 bg-white shadow-sm'">
                <div class="text-[11px] font-medium uppercase tracking-wide" :class="totals.pending_count ? 'text-amber-700' : 'text-slate-400'">Ждут проверки</div>
                <div class="mt-1.5 text-2xl font-bold tabular-nums" :class="totals.pending_count ? 'text-amber-700' : 'text-slate-300'">
                    {{ money(totals.pending) }}
                </div>
                <div class="mt-0.5 text-[11px] text-slate-500">{{ totals.pending_count }} заявок</div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="text-[11px] font-medium uppercase tracking-wide text-slate-400">Оплачено за {{ monthLabel }}</div>
                <div class="mt-1.5 text-2xl font-bold tabular-nums text-slate-800">{{ money(totals.confirmed) }}</div>
            </div>
        </div>

        <!-- Заявки с ОТКРЫТЫМИ чеками, в две колонки: бухгалтер видит документ
             сразу, без кликов по каждой строке. -->
        <div v-if="pending.length" class="mt-5">
            <div class="mb-2 px-1 text-xs font-bold uppercase tracking-wide text-amber-700">Требуют проверки</div>
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div v-for="e in pending" :key="e.id" class="overflow-hidden rounded-2xl border border-amber-200 bg-white shadow-sm">
                    <div class="flex flex-wrap items-start justify-between gap-2 border-b border-amber-100 bg-amber-50/60 px-4 py-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-lg font-bold tabular-nums text-slate-900">{{ money(e.amount) }}</span>
                                <span class="rounded-full bg-white px-2 py-0.5 text-[11px] font-semibold text-slate-500 ring-1 ring-slate-200">{{ e.category ?? 'Без категории' }}</span>
                                <span v-if="e.payout" class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-700">{{ e.payout }}</span>
                            </div>
                            <div class="mt-1 text-sm text-slate-600">{{ e.description || 'Без описания' }}</div>
                            <div class="mt-1 flex flex-wrap items-center gap-x-2 text-xs text-slate-400">
                                <span>{{ formatDate(e.date) }}</span>
                                <Link v-if="e.author" :href="route('users.show', e.author.id)" class="font-medium text-indigo-600 hover:underline">
                                    ☻ {{ e.author.name }}
                                </Link>
                                <Link v-if="e.deal_id" :href="route('deals.show', e.deal_id)" class="font-medium text-indigo-600 hover:underline">по сделке ↗</Link>
                            </div>
                        </div>
                    </div>

                    <!-- Чек открыт сразу -->
                    <div class="bg-slate-50 px-4 py-3">
                        <a v-if="e.is_image" :href="route('expenses.receipt', e.id)" target="_blank" class="block">
                            <img :src="route('expenses.receipt', e.id)" :alt="'Чек ' + e.id"
                                class="max-h-64 w-full rounded-lg border border-slate-200 bg-white object-contain" />
                        </a>
                        <a v-else-if="e.has_file" :href="route('expenses.receipt', e.id)" target="_blank"
                            class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-indigo-600 hover:bg-slate-50">
                            Открыть документ ↗
                        </a>
                        <div v-else class="rounded-lg border border-dashed border-amber-300 bg-amber-50/50 px-3 py-4 text-center text-xs text-amber-700">
                            Чек не приложен — попросите сотрудника добавить
                        </div>
                    </div>

                    <!-- Подтверждение: откуда платим + чек бухгалтера -->
                    <div v-if="canManage" class="border-t border-slate-100 px-4 py-3">
                        <div v-if="confirmFor === e.id" class="space-y-2">
                            <div class="flex gap-2">
                                <button v-for="m in [['cash','💵 Наличные'],['bank','🏦 Банк']]" :key="m[0]" type="button"
                                    @click="cForm.payment_method = m[0]"
                                    class="rounded-lg border px-3 py-1.5 text-sm font-medium transition"
                                    :class="cForm.payment_method === m[0] ? 'border-indigo-500 bg-indigo-50 text-indigo-700 ring-1 ring-indigo-500' : 'border-slate-200 text-slate-500 hover:border-slate-300'">
                                    {{ m[1] }}
                                </button>
                            </div>
                            <input type="file" @input="cForm.file = $event.target.files[0]"
                                class="w-full text-sm text-slate-500 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-slate-600" />
                            <div v-if="cForm.errors.file" class="text-xs text-rose-600">{{ cForm.errors.file }}</div>
                            <div class="flex gap-2">
                                <PrimaryButton :disabled="cForm.processing" @click="submitConfirm(e)">Оплатить</PrimaryButton>
                                <button class="rounded-lg px-3 py-1.5 text-sm text-slate-400 hover:text-slate-600" @click="confirmFor = null">Отмена</button>
                            </div>
                        </div>
                        <div v-else class="flex items-center gap-2">
                            <button class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-emerald-700" @click="openConfirm(e)">
                                ✓ Проверил, оплатить
                            </button>
                            <button class="rounded-lg px-3 py-1.5 text-sm text-slate-400 transition hover:text-rose-600" @click="del(e)">Удалить</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div v-else class="mt-5 rounded-2xl border border-dashed border-emerald-300 bg-emerald-50/40 px-4 py-8 text-center">
            <div class="text-sm font-medium text-emerald-700">Заявок на проверке нет</div>
            <div class="mt-0.5 text-xs text-slate-400">Всё оплачено</div>
        </div>

        <!-- Оплаченные за месяц: стеклянная карточка, строгие колонки -->
        <div class="mt-5 overflow-hidden rounded-2xl border border-white/60 bg-white/60 shadow-[0_8px_30px_rgb(0,0,0,0.06)] ring-1 ring-slate-200/60 backdrop-blur-xl">
            <div class="flex items-center justify-between border-b border-slate-200/60 bg-gradient-to-r from-slate-50/80 to-white/40 px-5 py-3 backdrop-blur">
                <span class="text-xs font-bold uppercase tracking-wide text-slate-600">Оплачено · {{ monthLabel }}</span>
                <span class="rounded-full bg-emerald-100/80 px-2.5 py-0.5 text-xs font-bold tabular-nums text-emerald-700">{{ money(totals.confirmed) }}</span>
            </div>
            <div v-if="confirmed.length" class="divide-y divide-slate-100/70">
                <div v-for="e in confirmed" :key="e.id" class="group flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3 transition-colors hover:bg-white/80">
                    <!-- Дата -->
                    <span class="w-16 shrink-0 text-xs font-medium tabular-nums text-slate-400">{{ formatDate(e.date) }}</span>
                    <!-- Что и категория -->
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="truncate text-sm font-medium text-slate-800">{{ e.description || 'Расход' }}</span>
                            <span v-if="e.payout" class="shrink-0 rounded-full bg-amber-100/80 px-2 py-0.5 text-[10px] font-bold text-amber-700">{{ e.payout }}</span>
                        </div>
                        <div class="mt-0.5 flex items-center gap-1.5 text-xs text-slate-400">
                            <span>{{ e.category ?? 'Без категории' }}</span>
                            <Link v-if="e.employee" :href="route('users.show', e.employee.id)" class="text-indigo-500 hover:underline">· {{ e.employee.name }}</Link>
                        </div>
                    </div>
                    <!-- Путь денег одной строкой: кто подал → кто подтвердил -->
                    <div class="flex shrink-0 items-center gap-1">
                        <Link v-if="e.author" :href="route('users.show', e.author.id)"
                            class="max-w-28 truncate rounded-full bg-indigo-50/80 px-2.5 py-1 text-[11px] font-semibold text-indigo-600 ring-1 ring-indigo-200/60 transition hover:bg-indigo-100">{{ e.author.name }}</Link>
                        <span v-else class="rounded-full bg-slate-100/80 px-2.5 py-1 text-[11px] text-slate-400">—</span>
                        <svg class="h-3.5 w-3.5 shrink-0 text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
                        <Link v-if="e.confirmer" :href="route('users.show', e.confirmer.id)"
                            class="max-w-28 truncate rounded-full bg-emerald-50/80 px-2.5 py-1 text-[11px] font-semibold text-emerald-600 ring-1 ring-emerald-200/60 transition hover:bg-emerald-100">✓ {{ e.confirmer.name }}</Link>
                        <span v-else class="rounded-full bg-slate-100/80 px-2.5 py-1 text-[11px] text-slate-400">⚙ система</span>
                    </div>
                    <!-- Чек и способ оплаты: одинаковые пилюли -->
                    <div class="flex w-40 shrink-0 items-center justify-end gap-1.5">
                        <a v-if="e.has_file" :href="route('expenses.receipt', e.id)" target="_blank"
                            class="rounded-full border border-indigo-200/70 bg-indigo-50/70 px-2.5 py-1 text-[11px] font-semibold text-indigo-600 transition hover:bg-indigo-100">чек ↗</a>
                        <span class="rounded-full px-2.5 py-1 text-[11px] font-semibold"
                            :class="e.payment_method === 'cash' ? 'bg-emerald-50/80 text-emerald-600 ring-1 ring-emerald-200/60' : 'bg-sky-50/80 text-sky-600 ring-1 ring-sky-200/60'">
                            {{ e.payment_method === 'cash' ? '💵 нал' : '🏦 банк' }}</span>
                    </div>
                    <!-- Сумма -->
                    <span class="w-28 shrink-0 text-right text-sm font-bold tabular-nums text-slate-800">{{ money(e.amount) }}</span>
                </div>
            </div>
            <div v-else class="px-4 py-10 text-center text-sm text-slate-400">За {{ monthLabel }} оплаченных расходов нет</div>
        </div>
    </AppLayout>
</template>
