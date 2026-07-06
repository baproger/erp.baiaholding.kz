<script setup>
import { ref, computed, reactive, onMounted, onUnmounted, nextTick, watch } from 'vue';
import { Head, usePage, useForm, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import Avatar from '@/Components/Avatar.vue';
import { confirmDialog } from '@/composables/useConfirm';

const props = defineProps({ chats: Array, users: Array, canCreateGroup: Boolean });
const me = computed(() => usePage().props.auth.user);

// ---- State ----
const activeChat = ref(props.chats[0] ?? null);
const messages = ref([]);
const lastId = ref(0);
const scroller = ref(null);
const textarea = ref(null);
let timer = null;

const search = ref('');
const listOpen = ref(false);   // mobile chat list
const infoOpen = ref(false);   // right info panel
const infoTab = ref('members');
const showEmoji = ref(false);

const form = useForm({ message: '', file: null });

// ---- Persisted UI state (unread + pins) ----
const readState = reactive(JSON.parse(localStorage.getItem('chat_seen') || '{}'));
const pins = ref(JSON.parse(localStorage.getItem('chat_pins') || '[]'));
const persistSeen = () => localStorage.setItem('chat_seen', JSON.stringify(readState));
const persistPins = () => localStorage.setItem('chat_pins', JSON.stringify(pins.value));

const isPinned = (c) => pins.value.includes(c.id);
const togglePin = (c) => {
    pins.value = isPinned(c) ? pins.value.filter((id) => id !== c.id) : [...pins.value, c.id];
    persistPins();
};
const isUnread = (c) => c.last && c.last.id > (readState[c.id] ?? 0) && c.last.author_id !== me.value?.id;
const markSeen = (c) => { if (c && c.last) { readState[c.id] = c.last.id; persistSeen(); } };

// ---- Chat list sections ----
const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return props.chats;
    return props.chats.filter((c) => c.name.toLowerCase().includes(q)
        || (c.participants || []).some((p) => p.name.toLowerCase().includes(q)));
});
const sections = computed(() => {
    const list = filtered.value;
    const pinned = list.filter(isPinned);
    const rest = list.filter((c) => !isPinned(c));
    return [
        { key: 'pinned', title: 'Закреплённые', items: pinned },
        { key: 'global', title: 'Общий', items: rest.filter((c) => c.type === 'global') },
        { key: 'personal', title: 'Личные сообщения', items: rest.filter((c) => c.type === 'personal') },
        { key: 'group', title: 'Групповые чаты', items: rest.filter((c) => c.type === 'group' && !c.deal_id) },
        { key: 'project', title: 'Проектные каналы', items: rest.filter((c) => c.deal_id) },
    ].filter((g) => g.items.length);
});

// ---- Helpers ----
const initial = (name) => (name ?? '?').trim().charAt(0).toUpperCase();
const avatarColor = (name) => {
    const colors = ['bg-indigo-500', 'bg-emerald-500', 'bg-rose-500', 'bg-amber-500', 'bg-sky-500', 'bg-violet-500', 'bg-teal-500'];
    let h = 0; for (const ch of (name ?? '')) h = (h + ch.charCodeAt(0)) % colors.length;
    return colors[h];
};
const fmtTime = (t) => new Date(t).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
const fmtDay = (t) => new Date(t).toLocaleDateString('ru-RU', { day: '2-digit', month: 'long' });
const typeLabel = (c) => c?.deal_id ? 'Проектный канал' : ({ global: 'Общий чат', personal: 'Личный чат', group: 'Групповой чат' }[c?.type] ?? '');
const otherParticipant = (c) => (c?.participants || []).find((p) => p.id !== me.value?.id);

// In-chat message search.
const showSearch = ref(false);
const msgSearch = ref('');
const visibleMessages = computed(() => {
    const q = msgSearch.value.trim().toLowerCase();
    if (!q) return messages.value;
    return messages.value.filter((m) => (m.message || '').toLowerCase().includes(q)
        || (m.attachments || []).some((a) => (a.name || '').toLowerCase().includes(q)));
});

// Messages grouped with day separators.
const grouped = computed(() => {
    const out = [];
    let day = null;
    for (const m of visibleMessages.value) {
        const d = fmtDay(m.created_at);
        if (d !== day) { out.push({ sep: true, id: 's' + m.id, day: d }); day = d; }
        out.push(m);
    }
    return out;
});

// Chat attachments (the «Вложения» tab).
const attachments = ref([]);
const loadAttachments = async () => {
    if (!activeChat.value) { attachments.value = []; return; }
    try {
        const { data } = await window.axios.get(route('chat.attachments', activeChat.value.id));
        attachments.value = data.attachments;
    } catch (e) { attachments.value = []; }
};
watch([() => infoTab.value, () => activeChat.value?.id, infoOpen], () => {
    if (infoOpen.value && infoTab.value === 'files' && activeChat.value) loadAttachments();
});

// ---- Messaging ----
const scrollBottom = () => nextTick(() => { if (scroller.value) scroller.value.scrollTop = scroller.value.scrollHeight; });

const loadMessages = async (reset = false) => {
    if (!activeChat.value) return;
    if (reset) { messages.value = []; lastId.value = 0; }
    try {
        const { data } = await window.axios.get(route('chat.messages', activeChat.value.id), { params: { after: lastId.value } });
        if (data.messages.length) {
            messages.value.push(...data.messages);
            lastId.value = data.messages[data.messages.length - 1].id;
            markSeen(activeChat.value);
            scrollBottom();
        }
    } catch (e) { /* ignore transient poll errors */ }
};

const selectChat = async (chat) => {
    activeChat.value = chat;
    listOpen.value = false;
    showEmoji.value = false;
    markSeen(chat);
    await loadMessages(true);
};

const send = () => {
    if ((!form.message.trim() && !form.file) || !activeChat.value) return;
    showEmoji.value = false;
    form.post(route('chat.send', activeChat.value.id), {
        preserveScroll: true, preserveState: true, forceFormData: true,
        onSuccess: () => { form.reset('message'); form.file = null; resizeInput(); loadMessages(); },
    });
};
const onEnter = (e) => { if (!e.shiftKey) { e.preventDefault(); send(); } };

// ---- File attachment ----
const fileInput = ref(null);
const pickFile = () => fileInput.value?.click();
const onFilePicked = (e) => { const f = e.target.files?.[0]; if (f) form.file = f; e.target.value = ''; };
const fmtSize = (b) => b >= 1048576 ? (b / 1048576).toFixed(1) + ' МБ' : Math.max(1, Math.round(b / 1024)) + ' КБ';

// ---- Delete a message (admin/director any, author own) ----
const deleteMessage = async (m) => {
    if (await confirmDialog({ title: 'Удалить сообщение', message: 'Сообщение будет удалено безвозвратно.', confirmText: 'Удалить', danger: true })) {
        try { await window.axios.delete(route('chat.messages.destroy', m.id)); loadMessages(true); } catch (e) { /* ignore */ }
    }
};

// Auto-resize textarea.
const resizeInput = () => nextTick(() => {
    const el = textarea.value;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 140) + 'px';
});

// Emoji.
const emojis = ['😀', '😁', '😂', '🙂', '😉', '😍', '😎', '🤔', '👍', '🙏', '🔥', '🎉', '✅', '❌', '❤️', '👏', '🤝', '📌', '💰', '⚡'];
const addEmoji = (e) => { form.message += e; resizeInput(); textarea.value?.focus(); };

// ---- New chat / group ----
const showNew = ref(false);
const newForm = useForm({ type: 'personal', name: '', description: '', participants: [] });
const userSearch = ref('');
const filteredUsers = computed(() => {
    const q = userSearch.value.trim().toLowerCase();
    return q ? props.users.filter((u) => u.name.toLowerCase().includes(q)) : props.users;
});
const toggleParticipant = (id) => {
    newForm.participants = newForm.participants.includes(id)
        ? newForm.participants.filter((x) => x !== id)
        : [...newForm.participants, id];
};
const openNew = () => { newForm.reset(); newForm.type = 'personal'; userSearch.value = ''; showNew.value = true; };
// A single Inertia visit already refreshes `chats` (store returns back()), so no extra reload — this fixes the slow double-load.
const createChat = () => newForm.post(route('chat.store'), {
    preserveScroll: true, preserveState: true,
    onSuccess: () => { showNew.value = false; },
});

// Re-point the active chat to its refreshed prop object (updated name/avatar/participants).
const syncActive = (id) => { const c = props.chats.find((x) => x.id === id); if (c) activeChat.value = c; };

// ---- Edit / delete group (admin/director) ----
const canManage = (c) => props.canCreateGroup && c && c.type === 'group';
const showEdit = ref(false);
const editPhoto = ref(null);
const editForm = useForm({ id: null, name: '', description: '', participants: [], photo: null });
const openEdit = (c) => {
    editForm.id = c.id;
    editForm.name = c.name;
    editForm.description = c.description ?? '';
    editForm.photo = null;
    editPhoto.value = null;
    editForm.participants = (c.participants || []).map((p) => p.id).filter((id) => id !== me.value?.id);
    userSearch.value = '';
    showEdit.value = true;
};
const toggleEditParticipant = (id) => {
    editForm.participants = editForm.participants.includes(id)
        ? editForm.participants.filter((x) => x !== id)
        : [...editForm.participants, id];
};
const editPhotoInput = ref(null);
const onEditPhoto = (e) => { const f = e.target.files?.[0]; if (f) { editForm.photo = f; editPhoto.value = URL.createObjectURL(f); } e.target.value = ''; };
const saveEdit = () => {
    editForm.transform((data) => ({ ...data, _method: 'put' })).post(route('chat.update', editForm.id), {
        preserveScroll: true, preserveState: true, forceFormData: true,
        onSuccess: () => { showEdit.value = false; syncActive(editForm.id); },
    });
};
const removeChat = async (c) => {
    if (await confirmDialog({ title: 'Удалить группу', message: `Группа «${c.name}» и все её сообщения будут удалены.`, confirmText: 'Удалить', danger: true })) {
        router.delete(route('chat.destroy', c.id), {
            preserveScroll: true, preserveState: true,
            onSuccess: () => { infoOpen.value = false; activeChat.value = null; messages.value = []; },
        });
    }
};

watch(() => form.message, resizeInput);

onMounted(() => {
    if (activeChat.value) { markSeen(activeChat.value); loadMessages(true); }
    timer = setInterval(() => loadMessages(), 4000);
});
onUnmounted(() => clearInterval(timer));
</script>

<template>
    <Head title="Чат" />
    <AppLayout>
        <template #header>{{ $t('page.chat', 'Чат') }}</template>

        <div class="relative flex h-[calc(100vh-8.5rem)] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <!-- ============ LEFT: chat list ============ -->
            <aside :class="listOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
                class="absolute inset-y-0 left-0 z-20 flex w-72 flex-shrink-0 flex-col border-r border-slate-200 bg-slate-50 transition-transform duration-300 lg:static lg:z-0">
                <div class="flex items-center justify-between gap-2 border-b border-slate-200 px-4 py-3">
                    <h3 class="text-sm font-semibold text-slate-800">Сообщения</h3>
                    <button @click="openNew" title="Новый чат"
                        class="new-btn flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-600 text-white shadow-sm transition-all hover:bg-indigo-700">
                        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>
                    </button>
                </div>
                <div class="border-b border-slate-200 p-3">
                    <div class="relative">
                        <svg viewBox="0 0 24 24" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m21 21-4-4"/></svg>
                        <input v-model="search" placeholder="Поиск чатов и контактов…"
                            class="w-full rounded-lg border-slate-200 bg-white py-2 pl-9 pr-3 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-400" />
                    </div>
                </div>

                <div class="flex-1 overflow-y-auto px-2 py-2">
                    <div v-for="sec in sections" :key="sec.key" class="mb-2">
                        <div class="flex items-center gap-1.5 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-slate-400">
                            <span v-if="sec.key === 'pinned'">📌</span>{{ sec.title }}
                        </div>
                        <button v-for="c in sec.items" :key="c.id" @click="selectChat(c)"
                            :class="activeChat?.id === c.id ? 'bg-white shadow-sm ring-1 ring-indigo-100' : 'hover:bg-white/70'"
                            class="group relative flex w-full items-center gap-3 rounded-xl px-2.5 py-2 text-left transition-all">
                            <span class="relative flex h-10 w-10 flex-shrink-0 items-center justify-center overflow-hidden rounded-full text-sm font-bold text-white" :class="avatarColor(c.name)">
                                <img v-if="c.avatar" :src="c.avatar" class="h-full w-full object-cover" alt="" />
                                <span v-else-if="c.type === 'group' || c.type === 'global'">#</span>
                                <template v-else>{{ initial(c.name) }}</template>
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="truncate text-sm" :class="isUnread(c) ? 'font-bold text-slate-900' : 'font-medium text-slate-700'">{{ c.name }}</span>
                                    <span v-if="c.last" class="flex-shrink-0 text-[10px] text-slate-400">{{ fmtTime(c.last.time) }}</span>
                                </div>
                                <div class="flex items-center justify-between gap-2">
                                    <span class="truncate text-xs" :class="isUnread(c) ? 'font-semibold text-slate-600' : 'text-slate-400'">
                                        {{ c.last ? c.last.text : 'Нет сообщений' }}
                                    </span>
                                    <span v-if="isUnread(c)" class="flex h-2 w-2 flex-shrink-0 rounded-full bg-indigo-500"></span>
                                </div>
                            </div>
                            <button @click.stop="togglePin(c)" :title="isPinned(c) ? 'Открепить' : 'Закрепить'"
                                class="absolute right-1.5 top-1.5 hidden text-xs text-slate-300 hover:text-indigo-500 group-hover:block"
                                :class="{ '!block text-indigo-400': isPinned(c) }">📌</button>
                        </button>
                    </div>
                    <div v-if="!sections.length" class="px-3 py-8 text-center text-sm text-slate-400">Ничего не найдено</div>
                </div>
            </aside>
            <div v-if="listOpen" class="absolute inset-0 z-10 bg-black/20 lg:hidden" @click="listOpen = false"></div>

            <!-- ============ CENTER: conversation ============ -->
            <section class="chat-bg flex min-w-0 flex-1 flex-col">
                <header class="flex items-center justify-between gap-2 border-b border-slate-200 bg-white/80 px-4 py-2.5 backdrop-blur">
                    <div class="flex min-w-0 items-center gap-3">
                        <button class="rounded-md p-1.5 text-slate-500 hover:bg-slate-100 lg:hidden" @click="listOpen = true">☰</button>
                        <span v-if="activeChat" class="flex h-9 w-9 flex-shrink-0 items-center justify-center overflow-hidden rounded-full text-sm font-bold text-white" :class="avatarColor(activeChat.name)">
                            <img v-if="activeChat.avatar" :src="activeChat.avatar" class="h-full w-full object-cover" alt="" />
                            <span v-else-if="activeChat.type === 'group' || activeChat.type === 'global'">#</span><template v-else>{{ initial(activeChat.name) }}</template>
                        </span>
                        <div class="min-w-0">
                            <div class="truncate text-sm font-semibold text-slate-900">{{ activeChat?.name ?? 'Выберите чат' }}</div>
                            <div class="text-[11px] text-slate-400">{{ activeChat ? typeLabel(activeChat) + ' · ' + (activeChat.participants?.length || 0) + ' уч.' : '' }}</div>
                        </div>
                    </div>
                    <div v-if="activeChat" class="flex items-center gap-1.5">
                        <div v-if="showSearch" class="relative">
                            <input v-model="msgSearch" autofocus placeholder="Поиск в чате…"
                                class="w-40 rounded-lg border-slate-200 py-1.5 pl-3 pr-7 text-xs shadow-sm focus:border-indigo-400 focus:ring-indigo-400 sm:w-52" />
                            <button @click="showSearch = false; msgSearch = ''" class="absolute right-1.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600">✕</button>
                        </div>
                        <button v-else @click="showSearch = true" title="Поиск в чате" class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 transition-colors hover:bg-slate-100">
                            <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m21 21-4-4"/></svg>
                        </button>
                        <button @click="infoOpen = !infoOpen" :class="infoOpen ? 'bg-indigo-50 text-indigo-600' : 'text-slate-500 hover:bg-slate-100'"
                            class="flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium transition-colors">
                            <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/></svg>
                            Инфо
                        </button>
                    </div>
                </header>

                <!-- Messages -->
                <div ref="scroller" class="relative flex-1 overflow-y-auto px-4 py-4">
                    <TransitionGroup name="msg" tag="div" class="space-y-1.5">
                        <template v-for="m in grouped" :key="m.id">
                            <div v-if="m.sep" class="my-3 flex justify-center">
                                <span class="rounded-full bg-white/80 px-3 py-0.5 text-[11px] font-medium text-slate-400 shadow-sm ring-1 ring-slate-200">{{ m.day }}</span>
                            </div>
                            <div v-else class="group flex items-end gap-2" :class="m.user_id === me?.id ? 'flex-row-reverse' : ''">
                                <Avatar v-if="m.user_id !== me?.id" :name="m.user_name" :src="m.user_avatar" :size="28" />
                                <div class="max-w-[72%]">
                                    <div v-if="m.user_id !== me?.id && (activeChat?.type !== 'personal')" class="mb-0.5 ml-1 text-[11px] font-semibold text-indigo-500">{{ m.user_name }}</div>
                                    <div :class="m.user_id === me?.id ? 'rounded-br-md bg-indigo-600 text-white' : 'rounded-bl-md bg-white text-slate-800 ring-1 ring-slate-100'"
                                        class="rounded-2xl px-3.5 py-2 text-sm shadow-sm">
                                        <!-- Attachments -->
                                        <div v-for="(a, i) in m.attachments" :key="i" class="mb-1.5">
                                            <a v-if="a.is_image" :href="a.url" target="_blank" class="block overflow-hidden rounded-xl">
                                                <img :src="a.url" class="max-h-56 max-w-full rounded-xl object-cover" alt="" />
                                            </a>
                                            <a v-else :href="a.url" target="_blank" :download="a.name"
                                                :class="m.user_id === me?.id ? 'bg-white/15 hover:bg-white/25' : 'bg-slate-50 hover:bg-slate-100'"
                                                class="flex items-center gap-2 rounded-xl px-2.5 py-2 transition-colors">
                                                <span class="text-xl">📄</span>
                                                <span class="min-w-0 flex-1">
                                                    <span class="block truncate text-xs font-semibold" :class="m.user_id === me?.id ? 'text-white' : 'text-slate-700'">{{ a.name }}</span>
                                                    <span class="block text-[10px]" :class="m.user_id === me?.id ? 'text-indigo-200' : 'text-slate-400'">{{ fmtSize(a.size) }}</span>
                                                </span>
                                                <svg viewBox="0 0 24 24" class="h-4 w-4 flex-shrink-0" :class="m.user_id === me?.id ? 'text-indigo-200' : 'text-slate-400'" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 21h14"/></svg>
                                            </a>
                                        </div>
                                        <div v-if="m.message" class="whitespace-pre-line break-words">{{ m.message }}</div>
                                        <div class="mt-0.5 text-right text-[10px]" :class="m.user_id === me?.id ? 'text-indigo-200' : 'text-slate-400'">{{ fmtTime(m.created_at) }}</div>
                                    </div>
                                </div>
                                <button v-if="m.can_delete" @click="deleteMessage(m)" title="Удалить сообщение"
                                    class="mb-1 hidden h-6 w-6 flex-shrink-0 items-center justify-center rounded-full text-slate-300 transition-colors hover:bg-rose-50 hover:text-rose-500 group-hover:flex">
                                    <svg viewBox="0 0 24 24" class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg>
                                </button>
                            </div>
                        </template>
                    </TransitionGroup>
                    <div v-if="activeChat && !messages.length" class="flex h-full flex-col items-center justify-center text-center text-sm text-slate-400">
                        <div class="mb-2 text-3xl">💬</div>Начните переписку — сообщений пока нет
                    </div>
                    <div v-if="!activeChat" class="flex h-full items-center justify-center text-sm text-slate-400">Выберите чат слева</div>
                </div>

                <!-- Composer -->
                <div v-if="activeChat" class="border-t border-slate-200 bg-white/80 p-3 backdrop-blur">
                    <!-- Pending attachment chip -->
                    <div v-if="form.file" class="mb-2 flex items-center gap-2 rounded-lg border border-indigo-100 bg-indigo-50/60 px-3 py-1.5 text-xs">
                        <span class="text-base">📎</span>
                        <span class="min-w-0 flex-1 truncate font-medium text-slate-700">{{ form.file.name }}</span>
                        <span class="flex-shrink-0 text-slate-400">{{ fmtSize(form.file.size) }}</span>
                        <button @click="form.file = null" class="flex-shrink-0 text-slate-400 hover:text-rose-500">✕</button>
                    </div>
                    <div v-if="form.progress" class="mb-2 h-1 overflow-hidden rounded-full bg-slate-100">
                        <div class="h-1 bg-indigo-500 transition-all" :style="{ width: form.progress.percentage + '%' }"></div>
                    </div>
                    <div class="relative flex items-end gap-2 rounded-2xl border border-slate-200 bg-white px-2 py-1.5 shadow-sm focus-within:border-indigo-300 focus-within:ring-2 focus-within:ring-indigo-100">
                        <button @click="showEmoji = !showEmoji" title="Эмодзи" class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg text-lg text-slate-400 hover:bg-slate-100">😊</button>
                        <input ref="fileInput" type="file" class="hidden" @change="onFilePicked" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.png,.jpg,.jpeg,.gif,.webp,.zip,.rar,.txt,.csv" />
                        <button title="Прикрепить файл" class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100" @click="pickFile">
                            <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21.44 11.05 12.25 20.24a5 5 0 0 1-7.07-7.07l9.19-9.19a3 3 0 0 1 4.24 4.24l-9.2 9.19a1 1 0 0 1-1.41-1.41l8.48-8.49"/></svg>
                        </button>
                        <textarea ref="textarea" v-model="form.message" @keydown.enter="onEnter" @input="resizeInput" rows="1" placeholder="Напишите сообщение…  (Enter — отправить, Shift+Enter — новая строка)"
                            class="max-h-[140px] flex-1 resize-none border-0 bg-transparent py-2 text-sm text-slate-800 placeholder-slate-400 focus:ring-0"></textarea>
                        <button @click="send" :disabled="form.processing || (!form.message.trim() && !form.file)"
                            class="send-btn flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl bg-indigo-600 text-white shadow-sm transition-all hover:bg-indigo-700 disabled:opacity-40">
                            <svg v-if="!form.processing" viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2 11 13M22 2l-7 20-4-9-9-4 20-7Z"/></svg>
                            <svg v-else class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-30"/><path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3"/></svg>
                        </button>

                        <!-- Emoji panel -->
                        <transition enter-active-class="transition duration-150" enter-from-class="opacity-0 translate-y-2" leave-active-class="transition duration-100" leave-to-class="opacity-0">
                            <div v-if="showEmoji" class="absolute bottom-14 left-0 grid grid-cols-8 gap-1 rounded-xl border border-slate-200 bg-white p-2 shadow-lg">
                                <button v-for="e in emojis" :key="e" @click="addEmoji(e)" class="flex h-8 w-8 items-center justify-center rounded-lg text-lg hover:bg-slate-100">{{ e }}</button>
                            </div>
                        </transition>
                    </div>
                </div>
            </section>

            <!-- ============ RIGHT: info panel ============ -->
            <transition enter-active-class="transition-transform duration-300" enter-from-class="translate-x-full" leave-active-class="transition-transform duration-300" leave-to-class="translate-x-full">
                <aside v-if="infoOpen && activeChat" class="absolute inset-y-0 right-0 z-20 flex w-72 flex-col border-l border-slate-200 bg-white shadow-xl lg:static lg:z-0 lg:shadow-none">
                    <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                        <h3 class="text-sm font-semibold text-slate-800">Информация</h3>
                        <button @click="infoOpen = false" class="rounded-md p-1 text-slate-400 hover:bg-slate-100">✕</button>
                    </div>
                    <div class="flex flex-col items-center border-b border-slate-100 px-4 py-5 text-center">
                        <span class="flex h-16 w-16 items-center justify-center overflow-hidden rounded-full text-xl font-bold text-white shadow-sm" :class="avatarColor(activeChat.name)">
                            <img v-if="activeChat.avatar" :src="activeChat.avatar" class="h-full w-full object-cover" alt="" />
                            <span v-else-if="activeChat.type === 'group' || activeChat.type === 'global'">#</span><template v-else>{{ initial(activeChat.name) }}</template>
                        </span>
                        <div class="mt-2 font-semibold text-slate-900">{{ activeChat.name }}</div>
                        <div class="text-xs text-slate-400">{{ typeLabel(activeChat) }}</div>
                        <p v-if="activeChat.description" class="mt-2 text-xs leading-relaxed text-slate-500">{{ activeChat.description }}</p>
                    </div>

                    <div class="flex border-b border-slate-100 text-xs">
                        <button v-for="tabItem in [{ k: 'members', l: 'Участники' }, { k: 'files', l: 'Вложения' }, { k: 'pinned', l: 'Закреплённые' }]" :key="tabItem.k"
                            @click="infoTab = tabItem.k" :class="infoTab === tabItem.k ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-slate-400'"
                            class="flex-1 border-b-2 py-2 font-medium transition-colors">{{ tabItem.l }}</button>
                    </div>

                    <div class="flex-1 overflow-y-auto p-3">
                        <!-- Members -->
                        <div v-if="infoTab === 'members'">
                            <div v-if="activeChat.type === 'personal'" class="rounded-xl bg-slate-50 p-3 text-sm">
                                <div class="font-semibold text-slate-800">{{ otherParticipant(activeChat)?.name ?? activeChat.name }}</div>
                                <div class="mt-1 text-xs text-slate-400">Личный контакт</div>
                            </div>
                            <div v-else class="space-y-1">
                                <div v-for="p in activeChat.participants" :key="p.id" class="flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-slate-50">
                                    <Avatar :name="p.name" :src="p.avatar" :size="32" />
                                    <span class="text-sm text-slate-700">{{ p.name }}</span>
                                    <span v-if="p.id === me?.id" class="ml-auto text-[10px] text-slate-400">вы</span>
                                </div>
                                <div v-if="!activeChat.participants?.length" class="py-4 text-center text-xs text-slate-400">Нет участников</div>
                            </div>
                        </div>
                        <!-- Files -->
                        <div v-else-if="infoTab === 'files'">
                            <div v-if="!attachments.length" class="py-8 text-center text-xs text-slate-400"><div class="mb-1 text-2xl">📎</div>Вложений пока нет</div>
                            <div v-else class="space-y-1.5">
                                <a v-for="(a, i) in attachments" :key="i" :href="a.url" target="_blank" :download="a.is_image ? null : a.name"
                                    class="flex items-center gap-2 rounded-lg border border-slate-100 p-1.5 transition-colors hover:bg-slate-50">
                                    <span v-if="a.is_image" class="h-9 w-9 flex-shrink-0 overflow-hidden rounded-md">
                                        <img :src="a.url" class="h-full w-full object-cover" alt="" />
                                    </span>
                                    <span v-else class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-md bg-slate-100 text-base">📄</span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-xs font-medium text-slate-700">{{ a.name }}</span>
                                        <span class="block truncate text-[10px] text-slate-400">{{ fmtSize(a.size) }} · {{ a.author }}</span>
                                    </span>
                                </a>
                            </div>
                        </div>
                        <!-- Pinned -->
                        <div v-else class="py-8 text-center text-xs text-slate-400">
                            <div class="mb-1 text-2xl">📌</div>Нет закреплённых сообщений
                        </div>
                    </div>

                    <!-- Group management (admin/director) -->
                    <div v-if="canManage(activeChat)" class="space-y-2 border-t border-slate-100 p-3">
                        <button @click="openEdit(activeChat)" class="flex w-full items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50">
                            <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                            Редактировать группу
                        </button>
                        <button @click="removeChat(activeChat)" class="flex w-full items-center justify-center gap-2 rounded-lg border border-rose-200 bg-white py-2 text-sm font-medium text-rose-600 transition-colors hover:bg-rose-50">
                            <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg>
                            Удалить группу
                        </button>
                    </div>
                </aside>
            </transition>
        </div>

        <!-- ============ New chat / group modal ============ -->
        <Modal :show="showNew" @close="showNew = false" max-width="lg">
            <div class="p-6">
                <h2 class="mb-4 text-lg font-semibold text-slate-900">Новый чат</h2>

                <div class="mb-4 inline-flex rounded-lg border border-slate-200 bg-slate-50 p-0.5 text-sm">
                    <button @click="newForm.type = 'personal'" :class="newForm.type === 'personal' ? 'bg-white text-indigo-600 shadow-sm' : 'text-slate-500'" class="rounded-md px-3 py-1 font-medium">Личный</button>
                    <button v-if="canCreateGroup" @click="newForm.type = 'group'" :class="newForm.type === 'group' ? 'bg-white text-indigo-600 shadow-sm' : 'text-slate-500'" class="rounded-md px-3 py-1 font-medium">Группа</button>
                </div>

                <div v-if="newForm.type === 'group'" class="mb-3 space-y-2">
                    <input v-model="newForm.name" placeholder="Название группы" class="w-full rounded-lg border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-400" />
                    <div v-if="newForm.errors.name" class="text-xs text-red-600">{{ newForm.errors.name }}</div>
                    <textarea v-model="newForm.description" rows="2" placeholder="Описание группы (необязательно)" class="w-full rounded-lg border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-400"></textarea>
                </div>

                <div class="mb-1 text-xs font-medium text-slate-500">Участники ({{ newForm.participants.length }})</div>
                <input v-model="userSearch" placeholder="Поиск по имени…" class="mb-2 w-full rounded-lg border-slate-200 py-1.5 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-400" />
                <div class="max-h-56 space-y-0.5 overflow-y-auto rounded-lg border border-slate-100 p-1">
                    <label v-for="u in filteredUsers" :key="u.id" class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-slate-50">
                        <input type="checkbox" :checked="newForm.participants.includes(u.id)" @change="toggleParticipant(u.id)" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
                        <Avatar :name="u.name" :src="u.avatar" :size="28" />
                        <span class="text-slate-700">{{ u.name }}</span>
                    </label>
                    <div v-if="!filteredUsers.length" class="py-3 text-center text-xs text-slate-400">Нет сотрудников</div>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <SecondaryButton @click="showNew = false">Отмена</SecondaryButton>
                    <PrimaryButton :disabled="newForm.processing || (newForm.type === 'personal' && !newForm.participants.length)" @click="createChat">Создать</PrimaryButton>
                </div>
            </div>
        </Modal>

        <!-- ============ Edit group modal ============ -->
        <Modal :show="showEdit" @close="showEdit = false" max-width="lg">
            <div class="p-6">
                <h2 class="mb-4 text-lg font-semibold text-slate-900">Редактировать группу</h2>

                <div class="mb-4 flex items-center gap-3">
                    <span class="flex h-16 w-16 flex-shrink-0 items-center justify-center overflow-hidden rounded-full text-xl font-bold text-white" :class="avatarColor(editForm.name)">
                        <img v-if="editPhoto || activeChat?.avatar" :src="editPhoto || activeChat?.avatar" class="h-full w-full object-cover" alt="" />
                        <template v-else>#</template>
                    </span>
                    <div>
                        <input ref="editPhotoInput" type="file" accept="image/*" class="hidden" @change="onEditPhoto" />
                        <button @click="editPhotoInput?.click()" class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm font-medium text-slate-600 hover:bg-slate-50">Загрузить фото группы</button>
                        <div v-if="editForm.errors.photo" class="mt-1 text-xs text-red-600">{{ editForm.errors.photo }}</div>
                    </div>
                </div>

                <div class="mb-3 space-y-2">
                    <input v-model="editForm.name" placeholder="Название группы" class="w-full rounded-lg border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-400" />
                    <div v-if="editForm.errors.name" class="text-xs text-red-600">{{ editForm.errors.name }}</div>
                    <textarea v-model="editForm.description" rows="2" placeholder="Описание группы" class="w-full rounded-lg border-slate-200 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-400"></textarea>
                </div>

                <div class="mb-1 text-xs font-medium text-slate-500">Участники ({{ editForm.participants.length + 1 }})</div>
                <input v-model="userSearch" placeholder="Поиск по имени…" class="mb-2 w-full rounded-lg border-slate-200 py-1.5 text-sm shadow-sm focus:border-indigo-400 focus:ring-indigo-400" />
                <div class="max-h-56 space-y-0.5 overflow-y-auto rounded-lg border border-slate-100 p-1">
                    <label v-for="u in filteredUsers" :key="u.id" class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-slate-50">
                        <input type="checkbox" :checked="editForm.participants.includes(u.id)" @change="toggleEditParticipant(u.id)" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
                        <Avatar :name="u.name" :src="u.avatar" :size="28" />
                        <span class="text-slate-700">{{ u.name }}</span>
                    </label>
                    <div v-if="!filteredUsers.length" class="py-3 text-center text-xs text-slate-400">Нет сотрудников</div>
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    <SecondaryButton @click="showEdit = false">Отмена</SecondaryButton>
                    <PrimaryButton :disabled="editForm.processing" @click="saveEdit">Сохранить</PrimaryButton>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>

<style scoped>
/* Subtle parallax-free geometric background that stays readable */
.chat-bg {
    background-color: #f8fafc;
    background-image: radial-gradient(circle at 1px 1px, rgba(99, 102, 241, 0.06) 1px, transparent 0);
    background-size: 22px 22px;
}
/* Message bubble entrance */
.msg-enter-active { transition: all 0.28s cubic-bezier(0.16, 1, 0.3, 1); }
.msg-enter-from { opacity: 0; transform: translateY(10px) scale(0.96); }
.msg-move { transition: transform 0.28s; }
/* "+" rotate + send pop */
.new-btn:hover svg { transform: rotate(90deg); transition: transform 0.3s; }
.send-btn:not(:disabled):active { transform: scale(0.9); }
</style>
