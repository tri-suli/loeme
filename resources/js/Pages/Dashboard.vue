<script setup lang="ts">
defineProps<{ message: string }>();
</script>

<template>
  <div class="p-6">
    <h1 class="text-2xl font-bold mb-2">Dashboard</h1>
    <p>{{ message }}</p>

    <form method="post" action="/logout" class="mt-6">
      <input type="hidden" name="_token" :value="$page.props.csrf_token" />
      <button type="submit" class="bg-gray-800 text-white px-4 py-2 rounded hover:bg-gray-700">Log out</button>
    </form>
  </div>
  </template>
