<script setup>
import { ref, computed } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageLayout from '@/Layouts/PageLayout.vue';
import { confirmDialog } from '@/composables/useConfirm';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';

const props = defineProps({ items: Array });

// Editable copy of all rows.
const rows = ref(props.items.map((i) => ({ ...i })));
const saveForm = useForm({ items: [] });
const save = () => {
    saveForm.items = rows.value.map((r) => ({ id: r.id, ru: r.ru, kk: r.kk }));
    saveForm.put(route('translations.update'), { preserveScroll: true });
};

// Grouped view.
const groups = computed(() => {
    const map = {};
    for (const r of rows.value) (map[r.group] ??= []).push(r);
    return map;
});

// Add new key.
const addForm = useForm({ key: '', group: 'common', ru: '', kk: '' });
const showAdd = ref(false);
const add = () => addForm.post(route('translations.store'), { preserveScroll: true, onSuccess: () => { showAdd.value = false; addForm.reset(); router.reload({ only: ['items'] }); } });

const destroy = async (r) => {
    if (await confirmDialog({ title: 'Удалить ключ', message: `Ключ «${r.key}» будет удалён.`, confirmText: 'Удалить', danger: true })) {
        router.delete(route('translations.destroy', r.id), { preserveScroll: true, onSuccess: () => router.reload({ only: ['items'] }) });
    }
};
</script>

<template>
    <Head title="Переводы" />
    <AppLayout>
        <template #header>{{ $t('page.translations', 'Переводы интерфейса') }}</template>

        <PageLayout title="Переводы" subtitle="тексты интерфейса для русского и казахского языков — применяются сразу после сохранения" :wide="false">
            <template #tabs>
                <nav class="mb-4 flex gap-1 overflow-x-auto border-b border-slate-200 pb-px">
                    <Link :href="route('settings.index')" class="whitespace-nowrap rounded-t-lg border-b-2 border-transparent px-3 py-2 text-sm font-medium text-slate-500 transition-colors duration-150 hover:bg-slate-50 hover:text-slate-700">Общие</Link>
                    <Link :href="route('stages.index')" class="whitespace-nowrap rounded-t-lg border-b-2 border-transparent px-3 py-2 text-sm font-medium text-slate-500 transition-colors duration-150 hover:bg-slate-50 hover:text-slate-700">Этапы</Link>
                    <Link :href="route('screens.index')" class="whitespace-nowrap rounded-t-lg border-b-2 border-transparent px-3 py-2 text-sm font-medium text-slate-500 transition-colors duration-150 hover:bg-slate-50 hover:text-slate-700">Экраны</Link>
                    <Link :href="route('custom-fields.index')" class="whitespace-nowrap rounded-t-lg border-b-2 border-transparent px-3 py-2 text-sm font-medium text-slate-500 transition-colors duration-150 hover:bg-slate-50 hover:text-slate-700">Доп. поля</Link>
                    <Link :href="route('translations.index')" class="whitespace-nowrap rounded-t-lg border-b-2 border-indigo-600 px-3 py-2 text-sm font-medium text-indigo-700 transition-colors duration-150">Переводы</Link>
                </nav>
            </template>

            <template #actions>
                <button @click="showAdd = !showAdd" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 transition-colors duration-150 hover:bg-slate-50">+ Ключ</button>
            </template>

            <div class="space-y-6">
                <!-- Add key -->
                <div v-if="showAdd" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
                        <div>
                            <InputLabel value="Ключ" class="mb-1 block text-xs font-medium text-slate-500" />
                            <TextInput v-model="addForm.key" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" placeholder="напр. deals.title" />
                            <div v-if="addForm.errors.key" class="mt-1 text-xs text-red-600">{{ addForm.errors.key }}</div>
                        </div>
                        <div>
                            <InputLabel value="Группа" class="mb-1 block text-xs font-medium text-slate-500" />
                            <TextInput v-model="addForm.group" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" placeholder="common" />
                        </div>
                        <div>
                            <InputLabel value="RU" class="mb-1 block text-xs font-medium text-slate-500" />
                            <TextInput v-model="addForm.ru" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                        </div>
                        <div>
                            <InputLabel value="KK" class="mb-1 block text-xs font-medium text-slate-500" />
                            <TextInput v-model="addForm.kk" class="mt-1 w-full rounded-md border-slate-300 text-sm shadow-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" />
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end"><PrimaryButton :disabled="addForm.processing" @click="add">Добавить</PrimaryButton></div>
                </div>

                <!-- Groups -->
                <div v-for="(list, group) in groups" :key="group" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-6 py-4 text-sm font-semibold text-slate-900">{{ group }}</div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
                                <tr>
                                    <th class="w-1/4 px-6 py-2.5">Ключ</th>
                                    <th class="px-4 py-2.5">Русский</th>
                                    <th class="px-4 py-2.5">Қазақша</th>
                                    <th class="w-10 px-4 py-2.5"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <tr v-for="r in list" :key="r.id" class="transition-colors duration-150 hover:bg-slate-50/60">
                                    <td class="px-6 py-2.5 font-mono text-xs text-slate-400">{{ r.key }}</td>
                                    <td class="px-4 py-2.5"><input v-model="r.ru" class="w-full rounded-md border-slate-200 py-1.5 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" /></td>
                                    <td class="px-4 py-2.5"><input v-model="r.kk" class="w-full rounded-md border-slate-200 py-1.5 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20" /></td>
                                    <td class="whitespace-nowrap px-4 py-2.5 text-right"><button @click="destroy(r)" class="rounded p-1 text-slate-300 transition-colors hover:text-rose-600" title="Удалить">✕</button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Sticky save -->
                <div class="sticky bottom-4 flex items-center justify-end gap-3">
                    <transition enter-active-class="transition duration-300" enter-from-class="opacity-0" leave-active-class="transition" leave-to-class="opacity-0">
                        <span v-if="saveForm.recentlySuccessful" class="text-sm font-medium text-emerald-600">✓ Сохранено</span>
                    </transition>
                    <PrimaryButton :disabled="saveForm.processing" @click="save">Сохранить переводы</PrimaryButton>
                </div>
            </div>
        </PageLayout>
    </AppLayout>
</template>
