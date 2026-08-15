<script setup>
import { onMounted, ref } from 'vue';

// type="number" даёт числовое значение — String здесь ронял бы Vue warn.
const model = defineModel({
    type: [String, Number],
    required: true,
});

const input = ref(null);

onMounted(() => {
    if (input.value.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <input
        class="rounded-md border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
        v-model="model"
        ref="input"
    />
</template>
