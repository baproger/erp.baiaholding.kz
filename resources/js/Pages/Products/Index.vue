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

const props = defineProps({ products: Object, filters: Object, can: Object });

const showModal = ref(false);
const editing = ref(null);
const search = ref(props.filters.search ?? '');
const form = useForm({ name: '', code: '', unit: 'шт', price: 0, description: '', is_service: false });

const openCreate = () => { editing.value = null; form.reset(); form.unit = 'шт'; showModal.value = true; };
const openEdit = (p) => {
    editing.value = p;
    Object.assign(form, { name: p.name, code: p.code ?? '', unit: p.unit, price: p.price, description: p.description ?? '', is_service: p.is_service });
    showModal.value = true;
};
const submit = () => {
    const opts = { onSuccess: () => (showModal.value = false), preserveScroll: true };
    if (editing.value) form.put(route('products.update', editing.value.id), opts);
    else form.post(route('products.store'), opts);
};
const destroy = (p) => { if (confirm(`Удалить «${p.name}»?`)) router.delete(route('products.destroy', p.id), { preserveScroll: true }); };
const doSearch = () => router.get(route('products.index'), { search: search.value }, { preserveState: true, replace: true });
const money = (v) => new Intl.NumberFormat('ru-RU').format(v) + ' ₸';
</script>

<template>
    <Head title="Номенклатура" />
    <AppLayout>
        <template #header>Номенклатура</template>

        <div class="mb-4 flex items-center justify-between gap-3">
            <TextInput v-model="search" placeholder="Поиск по названию/коду..." class="w-72" @keyup.enter="doSearch" />
            <PrimaryButton v-if="can.create" @click="openCreate">+ Добавить</PrimaryButton>
        </div>

        <div class="overflow-hidden rounded-lg bg-white shadow">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Название</th>
                        <th class="px-4 py-3">Код</th>
                        <th class="px-4 py-3">Ед.</th>
                        <th class="px-4 py-3">Цена</th>
                        <th class="px-4 py-3">Тип</th>
                        <th class="px-4 py-3 text-right">Действия</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <tr v-for="p in products.data" :key="p.id" class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ p.name }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ p.code }}</td>
                        <td class="px-4 py-3">{{ p.unit }}</td>
                        <td class="px-4 py-3">{{ money(p.price) }}</td>
                        <td class="px-4 py-3">
                            <span :class="p.is_service ? 'text-purple-600' : 'text-blue-600'">
                                {{ p.is_service ? 'Услуга' : 'Товар' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right space-x-2">
                            <button class="text-indigo-600 hover:underline" @click="openEdit(p)">Изменить</button>
                            <button class="text-red-600 hover:underline" @click="destroy(p)">Удалить</button>
                        </td>
                    </tr>
                    <tr v-if="!products.data.length"><td colspan="6" class="px-4 py-8 text-center text-gray-400">Нет данных</td></tr>
                </tbody>
            </table>
        </div>
        <div class="mt-4"><Pagination :links="products.links" /></div>

        <Modal :show="showModal" @close="showModal = false">
            <div class="p-6">
                <h2 class="mb-4 text-lg font-semibold">{{ editing ? 'Изменить' : 'Новая позиция' }}</h2>
                <div class="grid grid-cols-2 gap-4">
                    <div class="col-span-2">
                        <InputLabel value="Название" />
                        <TextInput v-model="form.name" class="mt-1 w-full" />
                        <InputError :message="form.errors.name" class="mt-1" />
                    </div>
                    <div><InputLabel value="Код" /><TextInput v-model="form.code" class="mt-1 w-full" /></div>
                    <div><InputLabel value="Единица" /><TextInput v-model="form.unit" class="mt-1 w-full" /></div>
                    <div>
                        <InputLabel value="Цена" />
                        <TextInput v-model="form.price" type="number" step="0.01" class="mt-1 w-full" />
                        <InputError :message="form.errors.price" class="mt-1" />
                    </div>
                    <label class="col-span-2 flex items-center gap-2 text-sm">
                        <input type="checkbox" v-model="form.is_service" class="rounded border-gray-300 text-indigo-600" />
                        Это услуга
                    </label>
                    <div class="col-span-2">
                        <InputLabel value="Описание" />
                        <textarea v-model="form.description" rows="2" class="mt-1 w-full rounded-md border-gray-300 shadow-sm"></textarea>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="showModal = false">Отмена</SecondaryButton>
                    <PrimaryButton :disabled="form.processing" @click="submit">Сохранить</PrimaryButton>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>
