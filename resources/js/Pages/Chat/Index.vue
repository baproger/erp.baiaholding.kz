<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue';
import { Head, usePage, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({ chats: Array, users: Array });
const me = computed(() => usePage().props.auth.user);

const activeChat = ref(props.chats[0] ?? null);
const messages = ref([]);
const lastId = ref(0);
const scroller = ref(null);
let timer = null;

const form = useForm({ message: '' });

const scrollBottom = () => nextTick(() => { if (scroller.value) scroller.value.scrollTop = scroller.value.scrollHeight; });

const loadMessages = async (reset = false) => {
    if (!activeChat.value) return;
    if (reset) { messages.value = []; lastId.value = 0; }
    const { data } = await window.axios.get(route('chat.messages', activeChat.value.id), { params: { after: lastId.value } });
    if (data.messages.length) {
        messages.value.push(...data.messages);
        lastId.value = data.messages[data.messages.length - 1].id;
        scrollBottom();
    }
};

const selectChat = async (chat) => { activeChat.value = chat; await loadMessages(true); };

const send = () => {
    if (!form.message.trim() || !activeChat.value) return;
    form.post(route('chat.send', activeChat.value.id), {
        preserveScroll: true, preserveState: true,
        onSuccess: () => { form.reset('message'); loadMessages(); },
    });
};

const fmt = (t) => new Date(t).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });

onMounted(() => {
    loadMessages(true);
    timer = setInterval(() => loadMessages(), 4000);
});
onUnmounted(() => clearInterval(timer));
</script>

<template>
    <Head title="Чат" />
    <AppLayout>
        <template #header>Чат</template>

        <div class="flex h-[calc(100vh-9rem)] overflow-hidden rounded-lg bg-white shadow">
            <!-- Chat list -->
            <aside class="w-64 flex-shrink-0 border-r bg-gray-50">
                <div class="border-b px-4 py-3 text-sm font-semibold text-gray-700">Чаты</div>
                <div class="divide-y">
                    <button v-for="c in chats" :key="c.id" @click="selectChat(c)"
                        :class="activeChat?.id === c.id ? 'bg-indigo-50 text-indigo-700' : 'hover:bg-gray-100'"
                        class="flex w-full items-center justify-between px-4 py-3 text-left text-sm">
                        <span class="truncate">{{ c.name }}</span>
                        <span class="text-xs text-gray-400">{{ c.messages_count }}</span>
                    </button>
                </div>
            </aside>

            <!-- Messages -->
            <section class="flex flex-1 flex-col">
                <div class="border-b px-4 py-3 text-sm font-semibold text-gray-700">{{ activeChat?.name ?? 'Выберите чат' }}</div>
                <div ref="scroller" class="flex-1 space-y-2 overflow-y-auto bg-gray-50 p-4">
                    <div v-for="m in messages" :key="m.id" class="flex" :class="m.user_id === me?.id ? 'justify-end' : 'justify-start'">
                        <div :class="m.user_id === me?.id ? 'bg-indigo-600 text-white' : 'bg-white text-gray-800'"
                            class="max-w-[70%] rounded-lg px-3 py-2 text-sm shadow-sm">
                            <div v-if="m.user_id !== me?.id" class="text-xs font-semibold text-indigo-500">{{ m.user_name }}</div>
                            <div class="whitespace-pre-line">{{ m.message }}</div>
                            <div class="mt-0.5 text-right text-[10px] opacity-70">{{ fmt(m.created_at) }}</div>
                        </div>
                    </div>
                    <div v-if="!messages.length" class="pt-10 text-center text-sm text-gray-400">Сообщений пока нет</div>
                </div>
                <div class="flex gap-2 border-t p-3">
                    <input v-model="form.message" @keyup.enter="send" placeholder="Сообщение…"
                        class="flex-1 rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <PrimaryButton :disabled="form.processing || !form.message.trim()" @click="send">Отправить</PrimaryButton>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
