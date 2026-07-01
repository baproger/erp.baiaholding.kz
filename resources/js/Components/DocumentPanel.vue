<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    documents: { type: Array, default: () => [] },
    entityType: String,
    entityId: Number,
});

const fileInput = ref(null);
const form = useForm({ documentable_type: props.entityType, documentable_id: props.entityId, name: '', file: null });

const pick = () => fileInput.value.click();
const onFile = (e) => {
    form.file = e.target.files[0];
    if (form.file) form.post(route('documents.store'), { preserveScroll: true, forceFormData: true, onSuccess: () => form.reset('file') });
};
const remove = (d) => { if (confirm('Удалить документ?')) router.delete(route('documents.destroy', d.id), { preserveScroll: true }); };
const kb = (b) => b > 1048576 ? (b / 1048576).toFixed(1) + ' МБ' : Math.max(1, Math.round(b / 1024)) + ' КБ';
</script>

<template>
    <div class="space-y-3">
        <input ref="fileInput" type="file" class="hidden" @change="onFile" />
        <PrimaryButton :disabled="form.processing" @click="pick">{{ form.processing ? 'Загрузка…' : '+ Загрузить документ' }}</PrimaryButton>

        <div class="space-y-2">
            <div v-for="d in documents" :key="d.id" class="flex items-center justify-between rounded-md bg-gray-50 px-3 py-2 text-sm">
                <div class="min-w-0">
                    <a :href="route('documents.download', d.id)" class="font-medium text-indigo-600 hover:underline">{{ d.name }}</a>
                    <div class="text-xs text-gray-400">v{{ d.version }} · {{ kb(d.size) }} · {{ d.user?.name }}</div>
                </div>
                <button class="text-red-500 hover:text-red-700" @click="remove(d)">✕</button>
            </div>
            <div v-if="!documents.length" class="py-4 text-center text-sm text-gray-400">Документов нет</div>
        </div>
    </div>
</template>
