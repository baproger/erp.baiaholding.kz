<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FinanceLayout from '@/Layouts/FinanceLayout.vue';
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
        <FinanceLayout title="Расходы" subtitle="заявки сотрудников: проверка и оплата" active="expenses.board">
            <template #actions>
                <label class="flex items-center gap-1 text-xs text-slate-400">месяц
                    <input v-model="monthSel" @change="setMonth" type="month"
                        class="rounded-lg border-slate-200 py-1.5 text-xs shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                </label>
            </template>

            <!-- Плитки-итоги (§5) -->
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div class="rounded-xl border p-4 shadow-sm" :class="totals.pending_count ? 'border-amber-200 bg-amber-50' : 'border-slate-200 bg-white'">
                    <div class="text-[11px] uppercase tracking-wide" :class="totals.pending_count ? 'text-amber-600' : 'text-slate-400'">Ждут проверки</div>
                    <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums" :class="totals.pending_count ? 'text-amber-700' : 'text-slate-800'">
                        {{ money(totals.pending) }}
                    </div>
                    <div class="mt-0.5 text-[11px]" :class="totals.pending_count ? 'text-amber-600' : 'text-slate-400'">{{ totals.pending_count }} заявок</div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="text-[11px] uppercase tracking-wide text-slate-400">Оплачено за {{ monthLabel }}</div>
                    <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums text-slate-800">{{ money(totals.confirmed) }}</div>
                </div>
            </div>

            <!-- Заявки с ОТКРЫТЫМИ чеками, в две колонки: бухгалтер видит документ
                 сразу, без кликов по каждой строке. -->
            <div v-if="pending.length" class="mt-6">
                <h3 class="mb-2 text-sm font-semibold text-slate-900">Требуют проверки</h3>
                <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                    <div v-for="e in pending" :key="e.id" class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="text-xl font-bold tabular-nums text-slate-900">{{ money(e.amount) }}</div>
                                <div class="mt-0.5 flex flex-wrap items-center gap-1.5 text-[11px] text-slate-400">
                                    <span>{{ formatDate(e.date) }}</span>
                                    <span class="rounded-full bg-slate-100 px-2.5 py-0.5 font-medium text-slate-500">{{ e.category ?? 'Без категории' }}</span>
                                    <span v-if="e.payout" class="rounded-full px-2.5 py-0.5 font-medium"
                                        :class="e.payout === 'Долг' ? 'bg-rose-50 text-rose-600' : 'bg-indigo-50 text-indigo-700'">{{ e.payout }}</span>
                                    <Link v-if="e.link" :href="route(e.link.route, e.link.id)" :title="e.link.name || ''"
                                        class="inline-flex max-w-56 items-center gap-1 rounded-full bg-indigo-50 px-2 py-0.5 font-medium text-indigo-700 transition-colors duration-150 hover:bg-indigo-100">
                                        {{ e.link.route === 'projects.show' ? '🏭' : '📄' }} {{ e.link.number }}<span v-if="e.link.name" class="truncate font-normal text-indigo-500"> · {{ e.link.name }}</span>
                                    </Link>
                                </div>
                            </div>
                            <div v-if="e.author" class="text-right text-[11px] text-slate-400">подал
                                <Link :href="route('users.show', e.author.id)" class="font-medium text-indigo-600 hover:underline">{{ e.author.name }}</Link>
                            </div>
                        </div>

                        <p class="mt-2 text-sm text-slate-600">{{ e.description || 'Без описания' }}</p>

                        <!-- Чек открыт сразу -->
                        <a v-if="e.is_image" :href="route('expenses.receipt', e.id)" target="_blank" class="block">
                            <img :src="route('expenses.receipt', e.id)" :alt="'Чек ' + e.id"
                                class="mt-2 max-h-44 w-full rounded-lg border border-slate-100 bg-slate-50 object-contain" />
                        </a>
                        <a v-else-if="e.has_file" :href="route('expenses.receipt', e.id)" target="_blank"
                            class="mt-2 inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-indigo-600 transition-colors duration-150 hover:bg-slate-50">
                            Открыть документ ↗
                        </a>
                        <div v-else class="mt-2">
                            <span class="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700">Чек не приложен — попросите сотрудника добавить</span>
                        </div>

                        <!-- Подтверждение: откуда платим + чек бухгалтера -->
                        <div v-if="canManage" class="mt-3 border-t border-slate-100 pt-3">
                            <div v-if="confirmFor === e.id" class="space-y-2">
                                <div class="flex gap-2">
                                    <button v-for="m in [['cash','💵 Наличные'],['bank','🏦 Банк']]" :key="m[0]" type="button"
                                        @click="cForm.payment_method = m[0]"
                                        class="rounded-full border px-3 py-1 text-xs font-medium transition-colors duration-150"
                                        :class="cForm.payment_method === m[0] ? 'border-indigo-500 bg-indigo-50 text-indigo-700' : 'border-slate-200 bg-white text-slate-500 hover:bg-slate-50'">
                                        {{ m[1] }}
                                    </button>
                                </div>
                                <input type="file" @input="cForm.file = $event.target.files[0]"
                                    class="w-full text-sm text-slate-500 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-slate-600" />
                                <div v-if="cForm.errors.file" class="text-xs text-red-600">{{ cForm.errors.file }}</div>
                                <div class="flex gap-2">
                                    <PrimaryButton :disabled="cForm.processing" @click="submitConfirm(e)">Оплатить</PrimaryButton>
                                    <button class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 transition-colors duration-150 hover:bg-slate-50" @click="confirmFor = null">Отмена</button>
                                </div>
                            </div>
                            <div v-else class="flex items-center justify-between gap-2">
                                <button class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors duration-150 hover:bg-indigo-700" @click="openConfirm(e)">
                                    ✓ Проверил, оплатить
                                </button>
                                <button class="rounded-lg bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-600 transition-colors duration-150 hover:bg-rose-100" @click="del(e)">Удалить</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div v-else class="mt-6 rounded-xl border border-slate-200 bg-white px-6 py-8 text-center shadow-sm">
                <div class="text-sm font-medium text-emerald-700">Заявок на проверке нет</div>
                <div class="mt-0.5 text-xs text-slate-400">Всё оплачено</div>
            </div>

            <!-- Оплаченные за месяц (§6): секция-карточка со строгими колонками -->
            <div class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
                    <h3 class="text-sm font-semibold text-slate-900">Оплачено · {{ monthLabel }}</h3>
                    <span class="rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium tabular-nums text-emerald-700">{{ money(totals.confirmed) }}</span>
                </div>
                <div v-if="confirmed.length" class="divide-y divide-slate-50">
                    <div v-for="e in confirmed" :key="e.id" class="group flex flex-wrap items-center gap-x-4 gap-y-2 px-6 py-3 transition-colors duration-150 hover:bg-slate-50/60">
                        <!-- Дата -->
                        <!-- Компактная дата: длинная («14 августа 2026 г.») не влезает в колонку и наезжает на описание -->
                        <span class="w-20 shrink-0 whitespace-nowrap text-xs tabular-nums text-slate-500">{{ new Date(e.date).toLocaleDateString('ru-RU') }}</span>
                        <!-- Что и категория -->
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="truncate text-sm font-medium text-slate-800">{{ e.description || 'Расход' }}</span>
                                <span v-if="e.payout" class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :class="e.payout === 'Долг' ? 'bg-rose-50 text-rose-600' : 'bg-indigo-50 text-indigo-700'">{{ e.payout }}</span>
                            </div>
                            <div class="mt-0.5 flex flex-wrap items-center gap-1.5 text-[11px] text-slate-400">
                                <span>{{ e.category ?? 'Без категории' }}</span>
                                <Link v-if="e.employee" :href="route('users.show', e.employee.id)" class="font-medium text-indigo-600 hover:underline">· {{ e.employee.name }}</Link>
                                <!-- По какой сделке / заказу цеха — кликабельный номер -->
                                <Link v-if="e.link" :href="route(e.link.route, e.link.id)" :title="e.link.name || ''"
                                    class="inline-flex max-w-64 items-center gap-1 rounded-full bg-indigo-50 px-2 py-0.5 font-medium text-indigo-700 transition-colors duration-150 hover:bg-indigo-100">
                                    {{ e.link.route === 'projects.show' ? '🏭' : '📄' }} {{ e.link.number }}<span v-if="e.link.name" class="truncate font-normal text-indigo-500"> · {{ e.link.name }}</span>
                                </Link>
                            </div>
                        </div>
                        <!-- Путь денег одной строкой: кто подал → кто подтвердил -->
                        <div class="flex shrink-0 items-center gap-1">
                            <Link v-if="e.author" :href="route('users.show', e.author.id)"
                                class="max-w-28 truncate rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-medium text-indigo-700 transition-colors duration-150 hover:bg-indigo-100">{{ e.author.name }}</Link>
                            <span v-else class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500">—</span>
                            <svg class="h-3.5 w-3.5 shrink-0 text-slate-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
                            <Link v-if="e.confirmer" :href="route('users.show', e.confirmer.id)"
                                class="max-w-28 truncate rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700 transition-colors duration-150 hover:bg-emerald-100">✓ {{ e.confirmer.name }}</Link>
                            <span v-else class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-500">⚙ система</span>
                        </div>
                        <!-- Чек и способ оплаты: одинаковые пилюли -->
                        <div class="flex w-40 shrink-0 items-center justify-end gap-1.5">
                            <a v-if="e.has_file" :href="route('expenses.receipt', e.id)" target="_blank"
                                class="rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-medium text-indigo-700 transition-colors duration-150 hover:bg-indigo-100">чек ↗</a>
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                :class="e.payment_method === 'cash' ? 'bg-emerald-50 text-emerald-700' : 'bg-sky-50 text-sky-700'">
                                {{ e.payment_method === 'cash' ? '💵 нал' : '🏦 банк' }}</span>
                        </div>
                        <!-- Сумма -->
                        <span class="w-28 shrink-0 whitespace-nowrap text-right text-sm font-semibold tabular-nums text-slate-800">{{ money(e.amount) }}</span>
                    </div>
                </div>
                <div v-else class="px-6 py-10 text-center text-sm text-slate-400">За {{ monthLabel }} оплаченных расходов нет</div>
            </div>
        </FinanceLayout>
    </AppLayout>
</template>
