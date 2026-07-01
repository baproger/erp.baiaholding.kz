<script setup>
import { usePage, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    comments: { type: Array, default: () => [] },
    entityType: String,
    entityId: Number,
});

const me = computed(() => usePage().props.auth.user);
const isAdmin = computed(() => me.value?.roles?.includes('admin'));
const form = useForm({ commentable_type: props.entityType, commentable_id: props.entityId, body: '' });
const add = () => form.post(route('comments.store'), { preserveScroll: true, onSuccess: () => form.reset('body') });
const remove = (c) => { if (confirm('Удалить комментарий?')) router.delete(route('comments.destroy', c.id), { preserveScroll: true }); };
const fmt = (t) => new Date(t).toLocaleString('ru-RU');
</script>

<template>
    <div class="space-y-4">
        <div class="flex gap-2">
            <textarea v-model="form.body" rows="2" placeholder="Написать комментарий…"
                class="w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
        </div>
        <div><PrimaryButton :disabled="form.processing || !form.body" @click="add">Отправить</PrimaryButton></div>

        <div class="space-y-3">
            <div v-for="c in comments" :key="c.id" class="rounded-md bg-gray-50 p-3 text-sm">
                <div class="flex items-center justify-between">
                    <span class="font-medium text-gray-800">{{ c.user?.name }}</span>
                    <div class="flex items-center gap-2 text-xs text-gray-400">
                        <span>{{ fmt(c.created_at) }}<span v-if="c.edited_at"> (изм.)</span></span>
                        <button v-if="c.user_id === me?.id || isAdmin" class="text-red-500" @click="remove(c)">✕</button>
                    </div>
                </div>
                <p class="mt-1 whitespace-pre-line text-gray-700">{{ c.body }}</p>
            </div>
            <div v-if="!comments.length" class="py-4 text-center text-sm text-gray-400">Комментариев нет</div>
        </div>
    </div>
</template>
