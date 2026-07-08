<script setup>
import { ref } from 'vue';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { confirmDialog } from '@/composables/useConfirm';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({ dealStages: Array, projectStages: Array, currentCompanyName: String });

const newForm = useForm({ kind: 'deal', name: '', color: '#6B7280' });
const addFor = ref(null); // 'deal' | 'project'

const startAdd = (kind) => { addFor.value = kind; newForm.reset(); newForm.kind = kind; newForm.color = '#6B7280'; };
const add = () => newForm.post(route('stages.store'), { preserveScroll: true, onSuccess: () => { addFor.value = null; } });

const rename = (kind, stage) => {
    const name = prompt('Новое название этапа:', stage.name);
    if (name && name !== stage.name) router.put(route('stages.update', [kind, stage.id]), { name }, { preserveScroll: true });
};
const recolor = (kind, stage, e) => router.put(route('stages.update', [kind, stage.id]), { color: e.target.value }, { preserveScroll: true });
const move = (kind, stage, direction) => router.patch(route('stages.move', [kind, stage.id]), { direction }, { preserveScroll: true });
const remove = async (kind, stage) => {
    if (await confirmDialog({ title: 'Удалить этап', message: `Этап «${stage.name}» будет удалён.`, confirmText: 'Удалить', danger: true })) {
        router.delete(route('stages.destroy', [kind, stage.id]), { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Этапы" />
    <AppLayout>
        <template #header>{{ $t('page.settings_stages', 'Настройки · Этапы') }}</template>

        <div class="mb-4 flex gap-2 border-b">
            <Link :href="route('settings.index')" class="px-3 py-2 text-sm text-slate-500 hover:text-slate-700">Общие</Link>
            <Link :href="route('stages.index')" class="border-b-2 border-indigo-600 px-3 py-2 text-sm font-medium text-indigo-600">Этапы</Link>
            <Link :href="route('custom-fields.index')" class="px-3 py-2 text-sm text-slate-500 hover:text-slate-700">Доп. поля</Link>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div v-for="group in [{ kind: 'deal', title: 'Этапы сделок' + (currentCompanyName ? ' · ' + currentCompanyName : ''), list: dealStages }, { kind: 'project', title: 'Этапы цеха (общие)', list: projectStages }]" :key="group.kind"
                class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="font-semibold text-slate-700">{{ group.title }}</h3>
                    <button class="text-sm text-indigo-600 hover:underline" @click="startAdd(group.kind)">+ Добавить</button>
                </div>

                <div v-if="addFor === group.kind" class="mb-3 flex items-center gap-2 rounded-md border border-dashed p-2">
                    <input type="color" v-model="newForm.color" class="h-8 w-8 rounded border-0" />
                    <TextInput v-model="newForm.name" placeholder="Название этапа" class="flex-1" @keyup.enter="add" />
                    <PrimaryButton :disabled="newForm.processing || !newForm.name" @click="add">ОК</PrimaryButton>
                    <button class="text-sm text-slate-500" @click="addFor = null">✕</button>
                </div>

                <div class="space-y-2">
                    <div v-for="stage in group.list" :key="stage.id" class="flex items-center gap-2 rounded-md bg-slate-50 px-3 py-2">
                        <div class="flex flex-col">
                            <button class="text-[10px] leading-3 text-slate-400 hover:text-indigo-600 disabled:opacity-30" :disabled="stage.id === group.list[0]?.id" @click="move(group.kind, stage, 'up')" title="Выше">▲</button>
                            <button class="text-[10px] leading-3 text-slate-400 hover:text-indigo-600 disabled:opacity-30" :disabled="stage.id === group.list[group.list.length - 1]?.id" @click="move(group.kind, stage, 'down')" title="Ниже">▼</button>
                        </div>
                        <input type="color" :value="stage.color" class="h-6 w-6 rounded border-0" @change="recolor(group.kind, stage, $event)" />
                        <span class="flex-1 text-sm font-medium text-slate-800">{{ stage.order }}. {{ stage.name }}</span>
                        <button class="text-xs text-indigo-600 hover:underline" @click="rename(group.kind, stage)">Переименовать</button>
                        <button class="text-xs text-red-500 hover:underline" @click="remove(group.kind, stage)">Удалить</button>
                    </div>
                    <div v-if="!group.list.length" class="py-4 text-center text-sm text-slate-400">Этапов нет</div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
