<script setup lang="ts">
import { ref } from 'vue';
import { Form } from '@inertiajs/vue3';

const email = ref('');
const password = ref('');
const remember = ref(false);
</script>

<template>
  <div class="min-h-screen flex items-center justify-center bg-white text-gray-900">
    <Form method="post" action="/login" class="w-full max-w-sm p-6 border rounded">
      <h1 class="text-xl font-semibold mb-4">Login</h1>
      <input type="hidden" name="_token" :value="$page.props.csrf_token" />

      <div class="mb-3">
        <label class="block text-sm mb-1" for="email">Email</label>
        <input
          id="email"
          name="email"
          v-model="email"
          type="email"
          class="w-full border px-3 py-2 rounded"
          :class="{ 'border-red-500': $page.props.errors && $page.props.errors.email }"
          :aria-invalid="$page.props.errors && $page.props.errors.email ? 'true' : 'false'"
          aria-describedby="email-error"
        />
        <p
          v-if="$page.props.errors && $page.props.errors.email"
          id="email-error"
          class="text-red-600 text-sm mt-1"
        >
          {{$page.props.errors.email}}
        </p>
      </div>

      <div class="mb-3">
        <label class="block text-sm mb-1" for="password">Password</label>
        <input id="password" name="password" v-model="password" type="password" class="w-full border px-3 py-2 rounded" />
      </div>

      <label class="inline-flex items-center gap-2 text-sm mb-4">
        <input type="checkbox" name="remember" v-model="remember" class="border" />
        Remember me
      </label>

      <div>
        <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Sign in</button>
      </div>
    </Form>
  </div>
</template>
