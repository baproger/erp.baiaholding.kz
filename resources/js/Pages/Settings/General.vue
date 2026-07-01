<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';

const props = defineProps({ settings: Object });
const form = useForm({
    company_name: props.settings.company_name,
    currency: props.settings.currency,
    auto_create_project: !!props.settings.auto_create_project,
    default_locale: props.settings.default_locale,
});
const save = () => form.put(route('settings.update'), { preserveScroll: true });
</script>

<template>
    <Head title="Настройки" />
    <AppLayout>
        <template #header>Настройки системы</template>

        <div class="mb-4 flex gap-2 border-b">
            <Link :href="route('settings.index')" class="border-b-2 border-indigo-600 px-3 py-2 text-sm font-medium text-indigo-600">Общие</Link>
            <Link :href="route('custom-fields.index')" class="px-3 py-2 text-sm text-gray-500 hover:text-gray-700">Доп. поля</Link>
        </div>

        <div class="max-w-xl rounded-lg bg-white p-6 shadow space-y-4">
            <div>
                <InputLabel value="Название компании" />
                <TextInput v-model="form.company_name" class="mt-1 w-full" />
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <InputLabel value="Валюта" />
                    <TextInput v-model="form.currency" class="mt-1 w-full" />
                </div>
                <div>
                    <InputLabel value="Язык по умолчанию" />
                    <select v-model="form.default_locale" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                        <option value="ru">Русский</option>
                        <option value="kk">Қазақша</option>
                    </select>
                </div>
            </div>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" v-model="form.auto_create_project" class="rounded border-gray-300 text-indigo-600" />
                Автоматически создавать проект при выигрыше сделки
            </label>
            <div class="pt-2"><PrimaryButton :disabled="form.processing" @click="save">Сохранить</PrimaryButton></div>
        </div>
    </AppLayout>
</template>
