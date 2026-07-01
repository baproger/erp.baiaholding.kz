<script setup>
import { ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';

const props = defineProps({ fields: Array, entities: Object, types: Object });

const typeLabels = { text: 'Текст', number: 'Число', date: 'Дата', boolean: 'Да/Нет', select: 'Список', radio: 'Радио', email: 'Email', phone: 'Телефон', url: 'Ссылка' };
const show = ref(false);
const editing = ref(null);
const optionsText = ref('');
const form = useForm({ entity_type: 'deal', name: '', type: 'text', required: false, unique: false, options: [], order: 0 });

const openCreate = () => { editing.value = null; form.reset(); optionsText.value = ''; show.value = true; };
const openEdit = (f) => {
    editing.value = f;
    Object.assign(form, { entity_type: f.entity_type, name: f.name, type: f.type, required: f.required, unique: f.unique, options: f.options ?? [], order: f.order });
    optionsText.value = (f.options ?? []).join(', ');
    show.value = true;
};
const submit = () => {
    form.options = optionsText.value.split(',').map((s) => s.trim()).filter(Boolean);
    const opts = { preserveScroll: true, onSuccess: () => (show.value = false) };
    if (editing.value) form.put(route('custom-fields.update', editing.value.id), opts);
    else form.post(route('custom-fields.store'), opts);
};
const destroy = (f) => { if (confirm(`Удалить поле «${f.name}»?`)) router.delete(route('custom-fields.destroy', f.id), { preserveScroll: true }); };
const needsOptions = () => form.type === 'select' || form.type === 'radio';
</script>

<template>
    <Head title="Доп. поля" />
    <AppLayout>
        <template #header>Настройки · Дополнительные поля</template>
        <div class="mb-4 flex justify-end"><PrimaryButton @click="openCreate">+ Новое поле</PrimaryButton></div>

        <div class="overflow-hidden rounded-lg bg-white shadow">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                    <tr><th class="px-4 py-3">Сущность</th><th class="px-4 py-3">Название</th><th class="px-4 py-3">Тип</th><th class="px-4 py-3">Обязательное</th><th class="px-4 py-3 text-right">Действия</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="f in fields" :key="f.id" class="hover:bg-gray-50">
                        <td class="px-4 py-3">{{ entities[f.entity_type] }}</td>
                        <td class="px-4 py-3 font-medium text-gray-900">{{ f.name }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ typeLabels[f.type] }}</td>
                        <td class="px-4 py-3">{{ f.required ? 'Да' : 'Нет' }}</td>
                        <td class="px-4 py-3 text-right space-x-2">
                            <button class="text-indigo-600 hover:underline" @click="openEdit(f)">Изменить</button>
                            <button class="text-red-600 hover:underline" @click="destroy(f)">Удалить</button>
                        </td>
                    </tr>
                    <tr v-if="!fields.length"><td colspan="5" class="px-4 py-8 text-center text-gray-400">Полей нет</td></tr>
                </tbody>
            </table>
        </div>

        <Modal :show="show" @close="show = false">
            <div class="p-6">
                <h2 class="mb-4 text-lg font-semibold">{{ editing ? 'Изменить поле' : 'Новое поле' }}</h2>
                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <InputLabel value="Сущность" />
                            <select v-model="form.entity_type" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                                <option v-for="(label, key) in entities" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel value="Тип" />
                            <select v-model="form.type" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                                <option v-for="t in types" :key="t" :value="t">{{ typeLabels[t] }}</option>
                            </select>
                        </div>
                    </div>
                    <div><InputLabel value="Название" /><TextInput v-model="form.name" class="mt-1 w-full" /></div>
                    <div v-if="needsOptions()">
                        <InputLabel value="Варианты (через запятую)" />
                        <TextInput v-model="optionsText" class="mt-1 w-full" placeholder="Вариант1, Вариант2" />
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" v-model="form.required" class="rounded border-gray-300 text-indigo-600" /> Обязательное
                    </label>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="show = false">Отмена</SecondaryButton>
                    <PrimaryButton :disabled="form.processing" @click="submit">Сохранить</PrimaryButton>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
