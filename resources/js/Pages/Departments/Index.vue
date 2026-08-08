<script setup>
import { ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { confirmDialog } from '@/composables/useConfirm';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import Pagination from '@/Components/Pagination.vue';

const props = defineProps({
    departments: Object,
    filters: Object,
    can: Object,
    users: { type: Array, default: () => [] },
    companies: { type: Array, default: () => [] },
    currentCompanyId: { type: Number, default: null },
});

const showModal = ref(false);
const editing = ref(null);
const search = ref(props.filters.search ?? '');

const form = useForm({ name: '', description: '', head_user_id: '', is_active: true, company_ids: [] });

// Отделы свои у каждой фирмы. В режиме «Все» отдел по умолчанию заводится
// сразу в обеих — иначе он появится только в одной и половина людей окажется
// «Без отдела».
const defaultCompanyIds = () => (props.currentCompanyId ? [props.currentCompanyId] : props.companies.map((c) => c.id));

const openCreate = () => {
    editing.value = null;
    form.reset();
    form.is_active = true;
    form.company_ids = defaultCompanyIds();
    showModal.value = true;
};
const toggleCompany = (id) => {
    form.company_ids = form.company_ids.includes(id)
        ? form.company_ids.filter((c) => c !== id)
        : [...form.company_ids, id];
};
const openEdit = (d) => {
    editing.value = d;
    form.name = d.name;
    form.description = d.description ?? '';
    form.head_user_id = d.head_user_id ?? '';
    form.is_active = d.is_active;
    showModal.value = true;
};
const submit = () => {
    const opts = { onSuccess: () => (showModal.value = false), preserveScroll: true };
    if (editing.value) form.put(route('departments.update', editing.value.id), opts);
    else form.post(route('departments.store'), opts);
};
const destroy = async (d) => {
    if (await confirmDialog({ title: 'Удалить отдел', message: `Отдел «${d.name}» будет удалён.`, confirmText: 'Удалить', danger: true })) {
        router.delete(route('departments.destroy', d.id), { preserveScroll: true });
    }
};
const doSearch = () => router.get(route('departments.index'), { search: search.value }, { preserveState: true, replace: true });
</script>

<template>
    <Head title="Отделы" />
    <AppLayout>
        <template #header>{{ $t('page.departments', 'Отделы') }}</template>

        <div class="mb-4 flex items-center justify-between gap-3">
            <TextInput v-model="search" placeholder="Поиск..." class="w-64" @keyup.enter="doSearch" />
            <PrimaryButton v-if="can.create" @click="openCreate">+ Добавить отдел</PrimaryButton>
        </div>

        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-slate-100 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th class="px-4 py-3">Компания</th>
                        <th class="px-4 py-3">Название</th>
                        <th class="px-4 py-3">Описание</th>
                        <th class="px-4 py-3">Руководитель</th>
                        <th class="px-4 py-3">Сотрудников</th>
                        <th class="px-4 py-3">Статус</th>
                        <th class="px-4 py-3 text-right">Действия</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="d in departments.data" :key="d.id" class="hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <span class="rounded bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-600">{{ d.company?.name ?? '—' }}</span>
                        </td>
                        <td class="px-4 py-3 font-medium text-slate-900">{{ d.name }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ d.description }}</td>
                        <td class="px-4 py-3 text-slate-600">
                            <span v-if="d.head">⭐ {{ d.head.name }}</span>
                            <span v-else class="text-slate-400">—</span>
                        </td>
                        <td class="px-4 py-3">{{ d.members_count }}</td>
                        <td class="px-4 py-3">
                            <span :class="d.is_active ? 'text-green-600' : 'text-slate-400'">
                                {{ d.is_active ? 'Активен' : 'Отключён' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right space-x-2">
                            <button v-if="can.update" class="text-indigo-600 hover:underline" @click="openEdit(d)">Изменить</button>
                            <button v-if="can.delete" class="text-red-600 hover:underline" @click="destroy(d)">Удалить</button>
                        </td>
                    </tr>
                    <tr v-if="!departments.data.length">
                        <td colspan="7" class="px-4 py-8 text-center text-slate-400">Нет данных</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            <Pagination :links="departments.links" />
        </div>

        <Modal :show="showModal" @close="showModal = false">
            <div class="p-6">
                <h2 class="mb-4 text-lg font-semibold">{{ editing ? 'Изменить отдел' : 'Новый отдел' }}</h2>
                <div class="space-y-4">
                    <!-- Фирма отдела: у BAIA и ASU свои отделы. При правке не меняется —
                         перенос отдела между фирмами утащил бы за собой сотрудников. -->
                    <div v-if="!editing && companies.length > 1">
                        <InputLabel value="Фирма (можно обе — отдел заведётся в каждой)" />
                        <div class="mt-1 flex flex-wrap gap-2">
                            <button v-for="c in companies" :key="c.id" type="button" @click="toggleCompany(c.id)"
                                class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                                :class="form.company_ids.includes(c.id) ? 'border-emerald-500 bg-emerald-50 text-emerald-700 ring-1 ring-emerald-500' : 'border-slate-200 bg-white text-slate-500 hover:border-slate-300'">
                                {{ c.name }}
                            </button>
                        </div>
                        <InputError :message="form.errors.company_ids" class="mt-1" />
                    </div>
                    <div v-else-if="editing" class="text-xs text-slate-500">
                        Фирма: <b class="text-slate-700">{{ editing.company?.name ?? '—' }}</b>
                    </div>
                    <div>
                        <InputLabel value="Название" />
                        <TextInput v-model="form.name" class="mt-1 w-full" />
                        <InputError :message="form.errors.name" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Описание" />
                        <textarea v-model="form.description" rows="3" class="mt-1 w-full rounded-md border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                    </div>
                    <div>
                        <InputLabel value="Руководитель отдела (⭐ + уведомления о просрочках отдела)" />
                        <select v-model="form.head_user_id" class="mt-1 w-full rounded-md border-slate-300 shadow-sm">
                            <option value="">—</option>
                            <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                        </select>
                        <InputError :message="form.errors.head_user_id" class="mt-1" />
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" v-model="form.is_active" class="rounded border-slate-300 text-indigo-600" />
                        Активен
                    </label>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="showModal = false">Отмена</SecondaryButton>
                    <PrimaryButton :disabled="form.processing" @click="submit">Сохранить</PrimaryButton>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
