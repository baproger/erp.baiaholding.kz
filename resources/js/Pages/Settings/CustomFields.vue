<script setup>
import { ref } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageLayout from '@/Layouts/PageLayout.vue';
import { confirmDialog } from '@/composables/useConfirm';
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
const form = useForm({ entity_type: 'deal', name: '', type: 'text', required: false, unique: false, is_visible: true, options: [], order: 0 });

const openCreate = () => { editing.value = null; form.reset(); optionsText.value = ''; show.value = true; };
const openEdit = (f) => {
    editing.value = f;
    Object.assign(form, { entity_type: f.entity_type, name: f.name, type: f.type, required: f.required, unique: f.unique, is_visible: f.is_visible, options: f.options ?? [], order: f.order });
    optionsText.value = (f.options ?? []).join(', ');
    show.value = true;
};
const submit = () => {
    form.options = optionsText.value.split(',').map((s) => s.trim()).filter(Boolean);
    const opts = { preserveScroll: true, onSuccess: () => (show.value = false) };
    if (editing.value) form.put(route('custom-fields.update', editing.value.id), opts);
    else form.post(route('custom-fields.store'), opts);
};
const destroy = async (f) => {
    if (await confirmDialog({ title: 'Удалить поле', message: `Поле «${f.name}» будет удалено.`, confirmText: 'Удалить', danger: true })) {
        router.delete(route('custom-fields.destroy', f.id), { preserveScroll: true });
    }
};
const needsOptions = () => form.type === 'select' || form.type === 'radio';
</script>

<template>
    <Head title="Доп. поля" />
    <AppLayout>
        <template #header>{{ $t('page.settings_fields', 'Настройки · Дополнительные поля') }}</template>

        <PageLayout title="Доп. поля" subtitle="дополнительные поля карточек">
            <template #tabs>
                <nav class="mb-4 flex gap-1 overflow-x-auto border-b border-slate-200 pb-px">
                    <Link :href="route('settings.index')" class="whitespace-nowrap rounded-t-lg border-b-2 border-transparent px-3 py-2 text-sm font-medium text-slate-500 transition-colors duration-150 hover:bg-slate-50 hover:text-slate-700">Общие</Link>
                    <Link :href="route('stages.index')" class="whitespace-nowrap rounded-t-lg border-b-2 border-transparent px-3 py-2 text-sm font-medium text-slate-500 transition-colors duration-150 hover:bg-slate-50 hover:text-slate-700">Этапы</Link>
                    <Link :href="route('screens.index')" class="whitespace-nowrap rounded-t-lg border-b-2 border-transparent px-3 py-2 text-sm font-medium text-slate-500 transition-colors duration-150 hover:bg-slate-50 hover:text-slate-700">Экраны</Link>
                    <Link :href="route('custom-fields.index')" class="whitespace-nowrap rounded-t-lg border-b-2 border-indigo-600 px-3 py-2 text-sm font-medium text-indigo-700 transition-colors duration-150">Доп. поля</Link>
                    <Link :href="route('translations.index')" class="whitespace-nowrap rounded-t-lg border-b-2 border-transparent px-3 py-2 text-sm font-medium text-slate-500 transition-colors duration-150 hover:bg-slate-50 hover:text-slate-700">Переводы</Link>
                </nav>
            </template>

            <template #actions>
                <PrimaryButton @click="openCreate">+ Новое поле</PrimaryButton>
            </template>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                            <tr>
                                <th class="px-6 py-2.5">Сущность</th>
                                <th class="px-4 py-2.5">Название</th>
                                <th class="px-4 py-2.5">Тип</th>
                                <th class="px-4 py-2.5">Обязательное</th>
                                <th class="px-4 py-2.5 text-right">Действия</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <tr v-for="f in fields" :key="f.id" class="transition-colors duration-150 hover:bg-slate-50/60">
                                <td class="px-6 py-2.5 text-slate-500">{{ entities[f.entity_type] }}</td>
                                <td class="px-4 py-2.5 font-medium text-slate-800">{{ f.name }}</td>
                                <td class="px-4 py-2.5">
                                    <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-500">{{ typeLabels[f.type] }}</span>
                                </td>
                                <td class="px-4 py-2.5">
                                    <span class="rounded-full px-2.5 py-0.5 text-xs font-medium" :class="f.required ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-500'">{{ f.required ? 'Да' : 'Нет' }}</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right">
                                    <button class="rounded-lg px-2.5 py-1 text-xs font-semibold text-slate-600 transition-colors duration-150 hover:bg-indigo-50 hover:text-indigo-700" @click="openEdit(f)">Изменить</button>
                                    <button class="rounded-lg px-2.5 py-1 text-xs font-semibold text-slate-400 transition-colors duration-150 hover:bg-rose-50 hover:text-rose-600" @click="destroy(f)">Удалить</button>
                                </td>
                            </tr>
                            <tr v-if="!fields.length"><td colspan="5" class="px-6 py-10 text-center text-sm text-slate-400">Полей нет</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <Modal :show="show" @close="show = false">
                <div class="p-6">
                    <h2 class="mb-4 text-lg font-semibold text-slate-900">{{ editing ? 'Изменить поле' : 'Новое поле' }}</h2>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Сущность" class="mb-1 block text-xs font-medium text-slate-500" />
                            <select v-model="form.entity_type" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                                <option v-for="(label, key) in entities" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </div>
                        <div>
                            <InputLabel value="Тип" class="mb-1 block text-xs font-medium text-slate-500" />
                            <select v-model="form.type" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm">
                                <option v-for="t in types" :key="t" :value="t">{{ typeLabels[t] }}</option>
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <InputLabel value="Название" class="mb-1 block text-xs font-medium text-slate-500" />
                            <TextInput v-model="form.name" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm" />
                        </div>
                        <div v-if="needsOptions()" class="sm:col-span-2">
                            <InputLabel value="Варианты (через запятую)" class="mb-1 block text-xs font-medium text-slate-500" />
                            <TextInput v-model="optionsText" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm" placeholder="Вариант1, Вариант2" />
                        </div>
                        <label class="flex items-center gap-2 text-sm text-slate-600 sm:col-span-2">
                            <input type="checkbox" v-model="form.required" class="rounded border-slate-300 text-indigo-600" /> Обязательное
                        </label>
                        <label class="flex items-center gap-2 text-sm text-slate-600 sm:col-span-2">
                            <input type="checkbox" v-model="form.is_visible" class="rounded border-slate-300 text-indigo-600" /> Показывать в карточке всегда
                        </label>
                    </div>
                    <div class="mt-6 flex justify-end gap-2">
                        <SecondaryButton @click="show = false">Отмена</SecondaryButton>
                        <PrimaryButton :disabled="form.processing" @click="submit">Сохранить</PrimaryButton>
                    </div>
                </div>
            </Modal>
        </PageLayout>
    </AppLayout>
</template>
