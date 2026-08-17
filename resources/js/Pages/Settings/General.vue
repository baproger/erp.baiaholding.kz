<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageLayout from '@/Layouts/PageLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';

const props = defineProps({ settings: Object });
const form = useForm({
    company_name: props.settings.company_name,
    currency: props.settings.currency,
    auto_create_project: !!props.settings.auto_create_project,
    default_locale: props.settings.default_locale,
    tax_percent: props.settings.tax_percent,
});
const save = () => form.put(route('settings.update'), { preserveScroll: true });
</script>

<template>
    <Head title="Настройки" />
    <AppLayout>
        <template #header>{{ $t('page.settings', 'Настройки системы') }}</template>

        <PageLayout title="Настройки" subtitle="общие параметры" :wide="false">
            <template #tabs>
                <nav class="mb-4 flex gap-1 overflow-x-auto border-b border-slate-200 pb-px">
                    <Link :href="route('settings.index')" class="whitespace-nowrap rounded-t-lg border-b-2 border-indigo-600 px-3 py-2 text-sm font-medium text-indigo-700 transition-colors duration-150">Общие</Link>
                    <Link :href="route('stages.index')" class="whitespace-nowrap rounded-t-lg border-b-2 border-transparent px-3 py-2 text-sm font-medium text-slate-500 transition-colors duration-150 hover:bg-slate-50 hover:text-slate-700">Этапы</Link>
                    <Link :href="route('screens.index')" class="whitespace-nowrap rounded-t-lg border-b-2 border-transparent px-3 py-2 text-sm font-medium text-slate-500 transition-colors duration-150 hover:bg-slate-50 hover:text-slate-700">Экраны</Link>
                    <Link :href="route('custom-fields.index')" class="whitespace-nowrap rounded-t-lg border-b-2 border-transparent px-3 py-2 text-sm font-medium text-slate-500 transition-colors duration-150 hover:bg-slate-50 hover:text-slate-700">Доп. поля</Link>
                    <Link :href="route('translations.index')" class="whitespace-nowrap rounded-t-lg border-b-2 border-transparent px-3 py-2 text-sm font-medium text-slate-500 transition-colors duration-150 hover:bg-slate-50 hover:text-slate-700">Переводы</Link>
                </nav>
            </template>

            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
                    <h3 class="text-sm font-semibold text-slate-900">Общие параметры</h3>
                </div>

                <div class="max-w-xl p-6">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <InputLabel value="Название компании" class="mb-1 block text-xs font-medium text-slate-500" />
                            <TextInput v-model="form.company_name" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                        </div>
                        <div>
                            <InputLabel value="Валюта" class="mb-1 block text-xs font-medium text-slate-500" />
                            <TextInput v-model="form.currency" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                        </div>
                        <div>
                            <InputLabel value="Язык по умолчанию" class="mb-1 block text-xs font-medium text-slate-500" />
                            <select v-model="form.default_locale" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20">
                                <option value="ru">Русский</option>
                                <option value="kk">Қазақша</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel value="Налог, % с суммы сделок" class="mb-1 block text-xs font-medium text-slate-500" />
                            <TextInput v-model="form.tax_percent" type="number" step="0.1" class="mt-1 w-full rounded-md border-slate-300 text-sm tabular-nums shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                        </div>
                        <div class="rounded-lg bg-slate-50 p-3 text-xs text-slate-500 ring-1 ring-slate-200">
                            <div class="font-semibold text-slate-600">Бонус сотрудника — по марже сделки (фиксировано):</div>
                            до 10% — нет · 11–15% — 5% · 16–20% — 7% · 21–30% — 10% · 31–40% — 13% · от 41% — 15% от остатка
                        </div>
                        <label class="flex items-center gap-2 text-sm text-slate-600 sm:col-span-2">
                            <input type="checkbox" v-model="form.auto_create_project" class="rounded border-slate-300 text-indigo-600" />
                            Автоматически создавать проект при выигрыше сделки
                        </label>
                    </div>

                    <div class="mt-6 flex justify-end">
                        <PrimaryButton :disabled="form.processing" @click="save">Сохранить</PrimaryButton>
                    </div>
                </div>
            </div>
        </PageLayout>
    </AppLayout>
</template>
