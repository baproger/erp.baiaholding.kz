<script setup>
import { ref, computed } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import { confirmDialog } from '@/composables/useConfirm';
import StatusBadge from '@/Components/StatusBadge.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
    entityType: String,   // 'deal' | 'project'
    entityId: Number,
    clientId: { type: Number, default: null },
    invoices: { type: Array, default: () => [] },
    expenses: { type: Array, default: () => [] },
    finance: { type: Object, default: () => ({ income: 0, invoiced: 0, expense: 0, profit: 0, margin: 0 }) },
});

const money = (v) => new Intl.NumberFormat('ru-RU').format(v ?? 0) + ' ₸';
const barIncome = computed(() => { const t = (props.finance.income ?? 0) + (props.finance.expense ?? 0); return t > 0 ? (props.finance.income / t * 100) : 0; });
const barExpense = computed(() => { const t = (props.finance.income ?? 0) + (props.finance.expense ?? 0); return t > 0 ? (props.finance.expense / t * 100) : 0; });

const showInvoice = ref(false);
const invoiceForm = useForm({
    invoiceable_type: props.entityType, invoiceable_id: props.entityId,
    client_id: props.clientId, amount: 0, status: 'sent', due_date: '', description: '',
});
const addInvoice = () => invoiceForm.post(route('invoices.store'), {
    preserveScroll: true, onSuccess: () => { invoiceForm.reset('amount', 'due_date', 'description'); showInvoice.value = false; },
});

const payFor = ref(null);
const payForm = useForm({ invoice_id: null, amount: 0, payment_date: new Date().toISOString().slice(0, 10), payment_method: 'bank', reference: '' });
const openPay = (inv) => { payFor.value = inv.id; payForm.invoice_id = inv.id; payForm.amount = Math.max(0, inv.amount - (inv.payments_sum_amount ?? 0)); };
const addPayment = () => payForm.post(route('payments.store'), { preserveScroll: true, onSuccess: () => { payFor.value = null; payForm.reset('amount', 'reference'); } });

const showExpense = ref(false);
const receiptInput = ref(null);
const expenseForm = useForm({ expenseable_type: props.entityType, expenseable_id: props.entityId, amount: 0, date: new Date().toISOString().slice(0, 10), description: '', type: 'direct', status: 'confirmed', file: null });
const onReceipt = (e) => { expenseForm.file = e.target.files[0] ?? null; };
const addExpense = () => expenseForm.post(route('expenses.store'), {
    preserveScroll: true, forceFormData: true,
    onSuccess: () => { expenseForm.reset('amount', 'description', 'file'); if (receiptInput.value) receiptInput.value.value = ''; showExpense.value = false; },
});
const fmtDateTime = (v) => v ? new Date(v).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';
const isImage = (path) => /\.(jpe?g|png|webp|heic)$/i.test(path ?? '');

const receiptView = ref(null); // { url, image } — открытый на просмотр чек
const openReceipt = (e) => { receiptView.value = { url: route('expenses.receipt', e.id), image: isImage(e.file_path) }; };
const closeReceipt = () => { receiptView.value = null; };

const delInvoice = async (i) => { if (await confirmDialog({ title: 'Удалить счёт', message: 'Счёт будет удалён.', confirmText: 'Удалить', danger: true })) router.delete(route('invoices.destroy', i.id), { preserveScroll: true }); };
const delExpense = async (e) => { if (await confirmDialog({ title: 'Удалить расход', message: 'Расход будет удалён.', confirmText: 'Удалить', danger: true })) router.delete(route('expenses.destroy', e.id), { preserveScroll: true }); };
</script>

<template>
    <div class="space-y-6">
        <!-- Summary -->
        <div class="space-y-4">
            <div class="grid grid-cols-3 gap-3">
                <div class="rounded-lg bg-indigo-50 p-3"><div class="text-xs text-indigo-700">Сумма сделки</div><div class="text-lg font-bold text-indigo-700">{{ money(finance.budget) }}</div></div>
                <div class="rounded-lg bg-green-50 p-3"><div class="text-xs text-green-700">Доход (оплачено)</div><div class="text-lg font-bold text-green-700">{{ money(finance.income) }}</div></div>
                <div class="rounded-lg bg-red-50 p-3"><div class="text-xs text-red-700">Расходы</div><div class="text-lg font-bold text-red-700">{{ money(finance.expense) }}</div></div>
            </div>

            <!-- income vs expense bar -->
            <div>
                <div class="mb-1 flex justify-between text-xs text-slate-500"><span>Доход vs Расходы</span><span>наценка {{ finance.markup }}%</span></div>
                <div class="flex h-3 overflow-hidden rounded bg-slate-100">
                    <div class="bg-green-500" :style="{ width: barIncome + '%' }"></div>
                    <div class="bg-red-400" :style="{ width: barExpense + '%' }"></div>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div class="rounded-lg border p-3">
                    <div class="text-xs font-semibold uppercase text-slate-400">Факт (по оплатам)</div>
                    <div class="mt-1 flex justify-between text-sm"><span class="text-slate-500">Прибыль</span><span class="font-bold" :class="finance.profit >= 0 ? 'text-green-600' : 'text-red-600'">{{ money(finance.profit) }}</span></div>
                    <div class="flex justify-between text-sm"><span class="text-slate-500">Маржа</span><span class="font-bold">{{ finance.margin }}%</span></div>
                    <div class="flex justify-between text-sm"><span class="text-slate-500">Доля расходов</span><span>{{ finance.expenseRatio }}%</span></div>
                </div>
                <div class="rounded-lg border p-3">
                    <div class="text-xs font-semibold uppercase text-slate-400">План (по сумме сделки)</div>
                    <div class="mt-1 flex justify-between text-sm"><span class="text-slate-500">Выгода</span><span class="font-bold" :class="finance.plannedProfit >= 0 ? 'text-green-600' : 'text-red-600'">{{ money(finance.plannedProfit) }}</span></div>
                    <div class="flex justify-between text-sm"><span class="text-slate-500">Маржа</span><span class="font-bold">{{ finance.plannedMargin }}%</span></div>
                    <div v-if="finance.plannedProfit < 0" class="mt-1 text-xs text-red-600">⚠ Расходы превысили сумму сделки</div>
                </div>
            </div>
        </div>

        <!-- Invoices -->
        <div>
            <div class="mb-2 flex items-center justify-between">
                <h4 class="font-semibold text-slate-700">Счета</h4>
                <button class="text-sm text-indigo-600 hover:underline" @click="showInvoice = !showInvoice">+ Счёт</button>
            </div>
            <div v-if="showInvoice" class="mb-3 rounded-md border border-dashed p-3">
                <div class="grid grid-cols-2 gap-2">
                    <TextInput v-model="invoiceForm.amount" type="number" step="0.01" placeholder="Сумма" />
                    <TextInput v-model="invoiceForm.due_date" type="date" />
                </div>
                <TextInput v-model="invoiceForm.description" placeholder="Описание" class="mt-2 w-full" />
                <div class="mt-2"><PrimaryButton :disabled="invoiceForm.processing" @click="addInvoice">Создать счёт</PrimaryButton></div>
            </div>
            <div class="space-y-2">
                <div v-for="inv in invoices" :key="inv.id" class="rounded-md bg-slate-50 p-3 text-sm">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="font-medium">{{ inv.number }}</span>
                            <span class="ml-2 text-slate-500">{{ money(inv.amount) }}</span>
                            <span class="ml-2 text-xs text-green-600">оплачено {{ money(inv.payments_sum_amount ?? 0) }}</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <StatusBadge :status="inv.status" />
                            <button class="text-indigo-600 hover:underline" @click="openPay(inv)">Оплата</button>
                            <button class="text-red-500" @click="delInvoice(inv)">✕</button>
                        </div>
                    </div>
                    <div v-if="payFor === inv.id" class="mt-2 flex items-end gap-2 border-t pt-2">
                        <TextInput v-model="payForm.amount" type="number" step="0.01" class="w-32" />
                        <TextInput v-model="payForm.payment_date" type="date" class="w-40" />
                        <select v-model="payForm.payment_method" class="rounded-md border-slate-300 text-sm">
                            <option value="bank">Банк</option><option value="cash">Нал</option><option value="card">Карта</option><option value="other">Другое</option>
                        </select>
                        <PrimaryButton :disabled="payForm.processing" @click="addPayment">ОК</PrimaryButton>
                        <button class="text-sm text-slate-500" @click="payFor = null">Отмена</button>
                    </div>
                </div>
                <div v-if="!invoices.length" class="py-3 text-center text-sm text-slate-400">Счетов нет</div>
            </div>
        </div>

        <!-- Expenses -->
        <div>
            <div class="mb-2 flex items-center justify-between">
                <h4 class="font-semibold text-slate-700">Расходы</h4>
                <button class="text-sm text-indigo-600 hover:underline" @click="showExpense = !showExpense">+ Расход</button>
            </div>
            <div v-if="showExpense" class="mb-3 rounded-md border border-dashed p-3">
                <div class="grid grid-cols-2 gap-2">
                    <TextInput v-model="expenseForm.amount" type="number" step="0.01" placeholder="Сумма" />
                    <TextInput v-model="expenseForm.date" type="date" />
                </div>
                <TextInput v-model="expenseForm.description" placeholder="Описание" class="mt-2 w-full" />
                <div class="mt-2">
                    <label class="mb-1 block text-xs font-medium text-slate-500">Чек (фото или PDF) *</label>
                    <input ref="receiptInput" type="file" accept="image/*,.pdf" @change="onReceipt"
                        class="block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-indigo-700 hover:file:bg-indigo-100" />
                    <div v-if="expenseForm.errors.file" class="mt-1 text-xs text-red-600">{{ expenseForm.errors.file }}</div>
                </div>
                <div class="mt-2"><PrimaryButton :disabled="expenseForm.processing || !expenseForm.file" @click="addExpense">Добавить расход</PrimaryButton></div>
            </div>
            <div class="space-y-2">
                <div v-for="e in expenses" :key="e.id" class="flex items-start justify-between gap-3 rounded-md bg-slate-50 p-3 text-sm">
                    <div>
                        <div><span class="font-medium">{{ money(e.amount) }}</span><span class="ml-2 text-slate-500">{{ e.description }}</span></div>
                        <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-slate-400">
                            <span v-if="e.responsible">{{ e.responsible.name }}</span>
                            <span>· {{ fmtDateTime(e.created_at) }}</span>
                            <button v-if="e.file_path" type="button" @click="openReceipt(e)"
                                class="text-indigo-600 hover:underline">📎 Посмотреть чек</button>
                            <span v-else class="text-amber-600">без чека</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <StatusBadge :status="e.status === 'confirmed' ? 'done' : 'draft'" />
                        <button class="text-red-500" @click="delExpense(e)">✕</button>
                    </div>
                </div>
                <div v-if="!expenses.length" class="py-3 text-center text-sm text-slate-400">Расходов нет</div>
            </div>
        </div>

        <!-- Просмотр чека -->
        <Modal :show="!!receiptView" max-width="2xl" @close="closeReceipt">
            <div class="bg-white">
                <div class="flex items-center justify-between border-b px-4 py-2">
                    <h4 class="font-semibold text-slate-700">Чек</h4>
                    <div class="flex items-center gap-3">
                        <a :href="receiptView?.url" target="_blank" class="text-sm text-indigo-600 hover:underline">Открыть в новой вкладке</a>
                        <button type="button" class="text-slate-400 hover:text-slate-600" @click="closeReceipt">✕</button>
                    </div>
                </div>
                <div class="max-h-[75vh] overflow-auto p-3 text-center">
                    <img v-if="receiptView?.image" :src="receiptView.url" alt="Чек" class="mx-auto max-w-full rounded" />
                    <iframe v-else :src="receiptView?.url" class="h-[70vh] w-full rounded border" title="Чек"></iframe>
                </div>
            </div>
        </Modal>
    </div>
</template>
