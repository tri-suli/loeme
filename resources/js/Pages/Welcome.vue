<script setup lang="ts">
defineProps<{ appName?: string }>();
</script>

<template>
    <div class="min-h-screen flex items-center justify-center bg-white text-gray-900">
        <div class="p-8 text-center">
            <h1 class="text-3xl font-semibold mb-2">Welcome to {{ appName ?? 'Laravel' }}</h1>
            <p class="text-gray-600">This page is rendered via Inertia + Vue 3 (TypeScript).</p>
        </div>
    </div>
</template>

<style scoped></style>
