<script setup>
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({ code: '' });
const submit = () => form.post(route('screen.enter'), { onError: () => form.reset('code') });
</script>

<template>
    <Head title="Экран цеха" />
    <div class="flex min-h-screen items-center justify-center bg-slate-950 px-4">
        <div class="w-full max-w-sm text-center">
            <div class="text-5xl">🏭</div>
            <h1 class="mt-4 text-2xl font-bold text-white">Экран цеха</h1>
            <p class="mt-2 text-sm text-slate-400">Введите код экрана — его выдаёт администратор в Настройки → Этапы → Цех</p>
            <form @submit.prevent="submit" class="mt-6">
                <input v-model="form.code" type="text" inputmode="numeric" autocomplete="one-time-code" placeholder="••••••"
                    class="w-full rounded-2xl border-slate-700 bg-slate-900 py-4 text-center text-3xl font-bold tracking-[0.5em] text-white placeholder-slate-600 focus:border-emerald-500 focus:ring-emerald-500" autofocus />
                <div v-if="form.errors.code" class="mt-3 text-sm text-rose-400">{{ form.errors.code }}</div>
                <button :disabled="form.processing || !form.code"
                    class="mt-4 w-full rounded-2xl bg-emerald-600 py-3.5 text-base font-semibold text-white transition hover:bg-emerald-500 disabled:opacity-40">Открыть экран</button>
            </form>
        </div>
    </div>
</template>
