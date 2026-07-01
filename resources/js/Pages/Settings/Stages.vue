<script setup>
import { ref } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({ dealStages: Array, projectStages: Array });

const newForm = useForm({ kind: 'deal', name: '', color: '#6B7280' });
const addFor = ref(null); // 'deal' | 'project'

const startAdd = (kind) => { addFor.value = kind; newForm.reset(); newForm.kind = kind; newForm.color = '#6B7280'; };
const add = () => newForm.post(route('stages.store'), { preserveScroll: true, onSuccess: () => { addFor.value = null; } });

const rename = (kind, stage) => {
    const name = prompt('Новое название этапа:', stage.name);
    if (name && name !== stage.name) router.put(route('stages.update', [kind, stage.id]), { name }, { preserveScroll: true });
};
const recolor = (kind, stage, e) => router.put(route('stages.update', [kind, stage.id]), { color: e.target.value }, { preserveScroll: true });
const remove = (kind, stage) => { if (confirm(`Удалить этап «${stage.name}»?`)) router.delete(route('stages.destroy', [kind, stage.id]), { preserveScroll: true }); };
</script>

<template>
    <Head title="Этапы" />
    <AppLayout>
        <template #header>Настройки · Этапы</template>

        <div class="mb-4 flex gap-2 border-b">
            <Link :href="route('settings.index')" class="px-3 py-2 text-sm text-gray-500 hover:text-gray-700">Общие</Link>
            <Link :href="route('stages.index')" class="border-b-2 border-indigo-600 px-3 py-2 text-sm font-medium text-indigo-600">Этапы</Link>
            <Link :href="route('custom-fields.index')" class="px-3 py-2 text-sm text-gray-500 hover:text-gray-700">Доп. поля</Link>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div v-for="group in [{ kind: 'deal', title: 'Этапы сделок', list: dealStages }, { kind: 'project', title: 'Этапы цеха', list: projectStages }]" :key="group.kind"
                class="rounded-lg bg-white p-6 shadow">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="font-semibold text-gray-700">{{ group.title }}</h3>
                    <button class="text-sm text-indigo-600 hover:underline" @click="startAdd(group.kind)">+ Добавить</button>
                </div>

                <div v-if="addFor === group.kind" class="mb-3 flex items-center gap-2 rounded-md border border-dashed p-2">
                    <input type="color" v-model="newForm.color" class="h-8 w-8 rounded border-0" />
                    <TextInput v-model="newForm.name" placeholder="Название этапа" class="flex-1" @keyup.enter="add" />
                    <PrimaryButton :disabled="newForm.processing || !newForm.name" @click="add">ОК</PrimaryButton>
                    <button class="text-sm text-gray-500" @click="addFor = null">✕</button>
                </div>

                <div class="space-y-2">
                    <div v-for="stage in group.list" :key="stage.id" class="flex items-center gap-2 rounded-md bg-gray-50 px-3 py-2">
                        <input type="color" :value="stage.color" class="h-6 w-6 rounded border-0" @change="recolor(group.kind, stage, $event)" />
                        <span class="flex-1 text-sm font-medium text-gray-800">{{ stage.order }}. {{ stage.name }}</span>
                        <button class="text-xs text-indigo-600 hover:underline" @click="rename(group.kind, stage)">Переименовать</button>
                        <button class="text-xs text-red-500 hover:underline" @click="remove(group.kind, stage)">Удалить</button>
                    </div>
                    <div v-if="!group.list.length" class="py-4 text-center text-sm text-gray-400">Этапов нет</div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
