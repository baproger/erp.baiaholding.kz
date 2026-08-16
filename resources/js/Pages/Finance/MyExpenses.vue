<script setup>
import { ref, computed } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import FinanceLayout from '@/Layouts/FinanceLayout.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import { useStickyFilters } from '@/composables/useStickyFilters';
import { money, formatDate } from '@/utils/format';

const props = defineProps({
    month: String,
    mine: { type: Array, default: () => [] },
    payouts: { type: Array, default: () => [] },
    totals: { type: Object, default: () => ({ pending: 0, confirmed: 0, payouts: 0 }) },
    categories: { type: Array, default: () => [] },
});

const monthSel = ref(props.month);
const setMonth = () => router.get(route('myExpenses.index'), { month: monthSel.value || undefined },
    { preserveState: true, preserveScroll: true, replace: true });
useStickyFilters('my-expenses', { monthSel }, setMonth);

const monthLabel = computed(() => new Date(props.month + '-01T00:00:00')
    .toLocaleDateString('ru-RU', { month: 'long', year: 'numeric' }));

// Заявка бухгалтеру: сумма, категория, дата, за что и чек/счёт.
const show = ref(false);
const form = useForm({
    expenseable_type: '', expenseable_id: '',
    category_id: '', amount: '', date: new Date().toISOString().slice(0, 10),
    description: '', file: null,
});
const open = () => { form.reset(); form.date = new Date().toISOString().slice(0, 10); form.clearErrors(); show.value = true; };
const submit = () => form.post(route('expenses.store'), {
    preserveScroll: true, forceFormData: true, onSuccess: () => (show.value = false),
});
</script>

<template>
    <Head title="Мои расходы" />
    <AppLayout>
        <FinanceLayout title="Мои расходы" subtitle="мои заявки и что мне выдано" active="myExpenses.index" :wide="false">
            <template #actions>
                <label class="flex items-center gap-1 text-xs text-slate-400">месяц
                    <input v-model="monthSel" @change="setMonth" type="month"
                        class="rounded-lg border-slate-200 py-1.5 text-xs shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                </label>
                <PrimaryButton @click="open">+ Расход компании</PrimaryButton>
            </template>

            <!-- Плитки-итоги (§5) -->
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 shadow-sm">
                    <div class="text-[11px] uppercase tracking-wide text-amber-600">Ждёт бухгалтера</div>
                    <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums text-amber-700">{{ money(totals.pending) }}</div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="text-[11px] uppercase tracking-wide text-slate-400">Оплачено за {{ monthLabel }}</div>
                    <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums text-emerald-600">{{ money(totals.confirmed) }}</div>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="text-[11px] uppercase tracking-wide text-slate-400">Мне выдано</div>
                    <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums text-slate-800">{{ money(totals.payouts) }}</div>
                    <div class="mt-0.5 text-[11px] text-slate-400">авансы и долги</div>
                </div>
            </div>

            <!-- Мои заявки бухгалтеру (§6) -->
            <div class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
                    <h3 class="text-sm font-semibold text-slate-900">Мои заявки · {{ monthLabel }}</h3>
                </div>
                <div v-if="mine.length" class="divide-y divide-slate-50">
                    <div v-for="e in mine" :key="e.id" class="flex flex-wrap items-center gap-3 px-6 py-3 transition-colors duration-150 hover:bg-slate-50/60">
                        <span class="w-24 shrink-0 whitespace-nowrap text-xs tabular-nums text-slate-500">{{ formatDate(e.date) }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-medium text-slate-800">{{ e.description || 'Расход' }}</div>
                            <div class="mt-0.5 text-[11px] text-slate-400">{{ e.category ?? '—' }}</div>
                        </div>
                        <a v-if="e.has_file" :href="route('expenses.receipt', e.id)" target="_blank"
                            class="shrink-0 rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-medium text-indigo-700 transition-colors duration-150 hover:bg-indigo-100">чек ↗</a>
                        <span v-else class="shrink-0 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700">без чека</span>
                        <span class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium"
                            :class="e.status === 'confirmed' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700'">
                            {{ e.status === 'confirmed' ? 'Оплачен' : 'Ждёт бухгалтера' }}
                        </span>
                        <span class="w-28 shrink-0 whitespace-nowrap text-right text-sm font-semibold tabular-nums text-slate-800">{{ money(e.amount) }}</span>
                    </div>
                </div>
                <div v-else class="px-6 py-10 text-center text-sm text-slate-400">
                    За {{ monthLabel }} заявок не было — нажмите «+ Расход компании»
                </div>
            </div>

            <!-- Что мне выдали: аванс и долг заводит бухгалтер, но касаются меня -->
            <div v-if="payouts.length" class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
                    <h3 class="text-sm font-semibold text-slate-900">Мне выдано · {{ monthLabel }}</h3>
                </div>
                <div class="divide-y divide-slate-50">
                    <div v-for="e in payouts" :key="e.id" class="flex flex-wrap items-center gap-3 px-6 py-3 transition-colors duration-150 hover:bg-slate-50/60">
                        <span class="w-24 shrink-0 whitespace-nowrap text-xs tabular-nums text-slate-500">{{ formatDate(e.date) }}</span>
                        <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium"
                            :class="e.payout === 'Долг' ? 'bg-rose-50 text-rose-600' : 'bg-indigo-50 text-indigo-700'">{{ e.payout }}</span>
                        <div class="min-w-0 flex-1 truncate text-sm text-slate-600">{{ e.description || '—' }}</div>
                        <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium"
                            :class="e.payment_method === 'cash' ? 'bg-emerald-50 text-emerald-700' : 'bg-sky-50 text-sky-700'">
                            {{ e.payment_method === 'cash' ? 'наличные' : 'банк' }}</span>
                        <span class="w-28 shrink-0 whitespace-nowrap text-right text-sm font-semibold tabular-nums text-slate-800">{{ money(e.amount) }}</span>
                    </div>
                </div>
            </div>

            <p class="mt-3 px-1 text-[11px] text-slate-400">
                Заявка — это счёт бухгалтеру на оплату. Пока он не подтвердил, деньги из кассы не уходят.
                Приложите чек или счёт — так бухгалтер оплатит быстрее.
            </p>

            <Modal :show="show" max-width="lg" @close="show = false">
                <div class="p-6">
                    <h2 class="mb-1 text-lg font-semibold text-slate-900">Расход компании</h2>
                    <p class="mb-4 text-xs text-slate-400">Счёт бухгалтеру на оплату: он проверит и оплатит.</p>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <InputLabel value="Категория *" />
                            <select v-model="form.category_id" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                                <option value="">— выберите —</option>
                                <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                            </select>
                            <InputError :message="form.errors.category_id" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Сумма, ₸ *" />
                            <TextInput v-model="form.amount" type="number" min="1" step="0.01" class="mt-1 w-full" />
                            <InputError :message="form.errors.amount" class="mt-1" />
                        </div>
                        <div>
                            <InputLabel value="Дата *" />
                            <TextInput v-model="form.date" type="date" class="mt-1 w-full" />
                            <InputError :message="form.errors.date" class="mt-1" />
                        </div>
                        <div class="sm:col-span-2">
                            <InputLabel value="За что" />
                            <TextInput v-model="form.description" class="mt-1 w-full" placeholder="Например: бензин на доставку" />
                        </div>
                        <div class="sm:col-span-2">
                            <InputLabel value="Чек или счёт" />
                            <input type="file" @input="form.file = $event.target.files[0]"
                                class="mt-1 w-full text-sm text-slate-500 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-slate-600" />
                            <InputError :message="form.errors.file" class="mt-1" />
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end gap-2">
                        <SecondaryButton @click="show = false">Отмена</SecondaryButton>
                        <PrimaryButton :disabled="form.processing" @click="submit">Отправить бухгалтеру</PrimaryButton>
                    </div>
                </div>
            </Modal>
        </FinanceLayout>
    </AppLayout>
</template>
