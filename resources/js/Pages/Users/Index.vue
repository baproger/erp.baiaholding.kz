<script setup>
import { ref } from 'vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import Pagination from '@/Components/Pagination.vue';

const props = defineProps({ users: Object, filters: Object, departments: Array, roles: Array });

const roleLabels = { admin: 'Администратор', manager: 'Менеджер', employee: 'Сотрудник' };
const show = ref(false);
const editing = ref(null);
const search = ref(props.filters.search ?? '');

const form = useForm({
    name: '', email: '', password: '', password_confirmation: '',
    department_id: '', phone: '', role: 'employee', is_active: true,
});

const openCreate = () => { editing.value = null; form.reset(); form.role = 'employee'; form.is_active = true; show.value = true; };
const openEdit = (u) => {
    editing.value = u;
    Object.assign(form, {
        name: u.name, email: u.email, password: '', password_confirmation: '',
        department_id: u.department_id ?? '', phone: u.phone ?? '', role: u.role ?? 'employee', is_active: u.is_active,
    });
    show.value = true;
};
const submit = () => {
    const opts = { preserveScroll: true, onSuccess: () => (show.value = false) };
    if (editing.value) form.put(route('users.update', editing.value.id), opts);
    else form.post(route('users.store'), opts);
};
const deactivate = (u) => { if (confirm(`Деактивировать сотрудника «${u.name}»?`)) router.delete(route('users.destroy', u.id), { preserveScroll: true }); };
const doSearch = () => router.get(route('users.index'), { search: search.value }, { preserveState: true, replace: true });
</script>

<template>
    <Head title="Сотрудники" />
    <AppLayout>
        <template #header>Сотрудники</template>

        <div class="mb-4 flex items-center justify-between gap-3">
            <TextInput v-model="search" placeholder="Поиск по имени/email…" class="w-72" @keyup.enter="doSearch" />
            <PrimaryButton @click="openCreate">+ Добавить сотрудника</PrimaryButton>
        </div>

        <div class="overflow-hidden rounded-lg bg-white shadow">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Имя</th><th class="px-4 py-3">Email</th><th class="px-4 py-3">Отдел</th>
                        <th class="px-4 py-3">Роль</th><th class="px-4 py-3">Статус</th><th class="px-4 py-3 text-right">Действия</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="u in users.data" :key="u.id" class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ u.name }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ u.email }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ u.department?.name ?? '—' }}</td>
                        <td class="px-4 py-3">{{ roleLabels[u.role] ?? u.role ?? '—' }}</td>
                        <td class="px-4 py-3"><span :class="u.is_active ? 'text-green-600' : 'text-gray-400'">{{ u.is_active ? 'Активен' : 'Отключён' }}</span></td>
                        <td class="px-4 py-3 text-right space-x-2">
                            <button class="text-indigo-600 hover:underline" @click="openEdit(u)">Изменить</button>
                            <button class="text-red-600 hover:underline" @click="deactivate(u)">Деактивировать</button>
                        </td>
                    </tr>
                    <tr v-if="!users.data.length"><td colspan="6" class="px-4 py-8 text-center text-gray-400">Нет данных</td></tr>
                </tbody>
            </table>
        </div>
        <div class="mt-4"><Pagination :links="users.links" /></div>

        <Modal :show="show" @close="show = false" max-width="2xl">
            <div class="p-6">
                <h2 class="mb-4 text-lg font-semibold">{{ editing ? 'Изменить сотрудника' : 'Новый сотрудник' }}</h2>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <InputLabel value="Имя" />
                        <TextInput v-model="form.name" class="mt-1 w-full" />
                        <InputError :message="form.errors.name" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Email" />
                        <TextInput v-model="form.email" type="email" class="mt-1 w-full" />
                        <InputError :message="form.errors.email" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel :value="editing ? 'Новый пароль (если менять)' : 'Пароль'" />
                        <TextInput v-model="form.password" type="password" class="mt-1 w-full" />
                        <InputError :message="form.errors.password" class="mt-1" />
                    </div>
                    <div>
                        <InputLabel value="Повтор пароля" />
                        <TextInput v-model="form.password_confirmation" type="password" class="mt-1 w-full" />
                    </div>
                    <div>
                        <InputLabel value="Отдел" />
                        <select v-model="form.department_id" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                            <option value="">—</option>
                            <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                        </select>
                    </div>
                    <div>
                        <InputLabel value="Роль" />
                        <select v-model="form.role" class="mt-1 w-full rounded-md border-gray-300 shadow-sm">
                            <option v-for="r in roles" :key="r" :value="r">{{ roleLabels[r] ?? r }}</option>
                        </select>
                    </div>
                    <div><InputLabel value="Телефон" /><TextInput v-model="form.phone" class="mt-1 w-full" /></div>
                    <label class="col-span-2 flex items-center gap-2 text-sm">
                        <input type="checkbox" v-model="form.is_active" class="rounded border-gray-300 text-indigo-600" /> Активен
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
