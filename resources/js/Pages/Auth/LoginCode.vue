<script setup>
import { Head, useForm, router } from '@inertiajs/vue3';
import AuthSplitLayout from '@/Layouts/AuthSplitLayout.vue';

const props = defineProps({
    name: String,
    hasCode: Boolean,
    canEmail: Boolean,
    status: String,
});

const form = useForm({ code: '' });
const submit = () => form.post(route('login.code.verify'), { onError: () => form.reset('code') });
const emailForm = useForm({});
const sendEmail = () => emailForm.post(route('login.code.email'), { preserveScroll: true });
const logout = () => router.post(route('logout'));
// Только цифры, максимум 6 — вставка «123 456» из мессенджера тоже сработает.
const onInput = (e) => { form.code = e.target.value.replace(/\D/g, '').slice(0, 6); };
</script>

<template>
    <Head title="Код входа" />
    <AuthSplitLayout>
        <h2 class="text-3xl font-bold tracking-tight text-slate-900">Код входа</h2>
        <p class="mt-2 text-sm text-slate-400">{{ name }}, введите код, который выдал администратор</p>

        <div v-if="status" class="mt-4 rounded-lg bg-emerald-50 px-4 py-2.5 text-sm font-medium text-emerald-700 ring-1 ring-emerald-200">{{ status }}</div>
        <div v-if="!hasCode && !status" class="mt-4 rounded-lg bg-amber-50 px-4 py-2.5 text-sm text-amber-800 ring-1 ring-amber-200">
            Действующего кода для вас нет — попросите администратора выпустить код (Сотрудники → «Код входа»).
        </div>

        <form @submit.prevent="submit" class="mt-8 space-y-5">
            <div class="auth-reveal" style="animation-delay: 200ms">
                <label for="code" class="mb-1.5 block text-sm font-semibold text-slate-700">6-значный код</label>
                <input id="code" :value="form.code" @input="onInput" type="text" inputmode="numeric" autocomplete="one-time-code" required autofocus
                    placeholder="••••••" maxlength="6"
                    class="auth-input w-full py-3 text-center text-2xl font-bold tracking-[0.5em] tabular-nums" />
                <div v-if="form.errors.code" class="mt-1.5 text-xs font-medium text-rose-600">{{ form.errors.code }}</div>
            </div>

            <button type="submit" :disabled="form.processing || form.code.length !== 6" class="auth-reveal auth-btn w-full rounded-xl bg-gradient-to-r from-emerald-500 to-emerald-600 py-3.5 text-sm font-semibold text-white transition-all duration-200 hover:brightness-105 active:scale-[0.99] disabled:opacity-60" style="animation-delay: 300ms">
                <span class="relative z-10">{{ form.processing ? 'Проверка…' : 'Подтвердить' }}</span>
            </button>
        </form>

        <div class="auth-reveal mt-7 flex flex-col items-center gap-3 text-xs text-slate-400" style="animation-delay: 400ms">
            <p>Код действует 24 часа. После ввода это устройство запомнится на 30 дней.</p>
            <button v-if="canEmail" type="button" :disabled="emailForm.processing" @click="sendEmail"
                class="font-semibold text-indigo-600 transition-colors hover:text-indigo-800 disabled:opacity-60">
                Отправить код на мою почту (администратор)
            </button>
            <button type="button" @click="logout" class="text-slate-400 underline-offset-2 transition-colors hover:text-slate-600 hover:underline">Выйти</button>
        </div>
    </AuthSplitLayout>
</template>
