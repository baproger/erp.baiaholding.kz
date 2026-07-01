<script setup>
import { reactive } from 'vue';
import { useForm } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
    fields: { type: Array, default: () => [] },
    entityType: String,
    entityId: Number,
});

const model = reactive({});
props.fields.forEach((f) => {
    model[f.id] = f.type === 'boolean' ? (f.value === '1' || f.value === true) : (f.value ?? '');
});

const form = useForm({ entity_type: props.entityType, entity_id: props.entityId, values: model });
const save = () => { form.values = { ...model }; form.post(route('custom-field-values.sync'), { preserveScroll: true }); };
</script>

<template>
    <div v-if="fields.length" class="space-y-4">
        <div v-for="f in fields" :key="f.id">
            <label class="mb-1 block text-sm text-gray-600">{{ f.name }}<span v-if="f.required" class="text-red-500"> *</span></label>

            <select v-if="f.type === 'select' || f.type === 'radio'" v-model="model[f.id]" class="w-full rounded-md border-gray-300 shadow-sm">
                <option value="">—</option>
                <option v-for="opt in f.options" :key="opt" :value="opt">{{ opt }}</option>
            </select>
            <label v-else-if="f.type === 'boolean'" class="flex items-center gap-2 text-sm">
                <input type="checkbox" v-model="model[f.id]" class="rounded border-gray-300 text-indigo-600" /> Да
            </label>
            <TextInput v-else v-model="model[f.id]"
                :type="f.type === 'number' ? 'number' : f.type === 'date' ? 'date' : f.type === 'email' ? 'email' : 'text'"
                class="w-full" />
        </div>
        <PrimaryButton :disabled="form.processing" @click="save">Сохранить поля</PrimaryButton>
    </div>
    <div v-else class="py-6 text-center text-sm text-gray-400">
        Дополнительных полей не настроено. Добавьте их в «Настройки → Доп. поля».
    </div>
</template>
