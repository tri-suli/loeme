<script setup lang="ts">
import { computed } from 'vue'
import { useToasts } from '@/stores/toasts'

const { state, remove } = useToasts()

const liveMessage = computed(() => {
  const first = state.items[0]
  return first ? first.message : ''
})

const typeClasses = (type: string) => {
  if (type === 'success') return 'bg-green-50 border-green-400 text-green-800'
  if (type === 'error') return 'bg-red-50 border-red-400 text-red-800'
  return 'bg-blue-50 border-blue-400 text-blue-800'
}
</script>

<template>
  <!-- ARIA live region for screen readers -->
  <div class="sr-only" aria-live="polite" aria-atomic="true">{{ liveMessage }}</div>

  <!-- Visual toasts container -->
  <div class="fixed top-4 right-4 z-50 space-y-2 w-[320px]" role="region" aria-label="Notifications">
    <div
      v-for="t in state.items"
      :key="t.id"
      class="border rounded shadow px-3 py-2 flex items-start gap-2"
      :class="typeClasses(t.type)"
      role="status"
      :aria-label="t.type === 'error' ? 'Error' : 'Notification'"
      tabindex="0"
    >
      <div class="flex-1">
        <div class="font-medium text-sm">{{ t.message }}</div>
        <div v-if="t.code" class="text-xs opacity-80">Code: {{ t.code }}</div>
        <details v-if="t.details" class="text-xs mt-1">
          <summary class="cursor-pointer select-none">Details</summary>
          <pre class="whitespace-pre-wrap">{{ t.details }}</pre>
        </details>
      </div>
      <button
        class="ml-2 text-sm underline focus:outline-none focus:ring-2 focus:ring-blue-500 rounded"
        :aria-label="'Dismiss notification'"
        @click="remove(t.id)"
      >
        Dismiss
      </button>
    </div>
  </div>

</template>

<style scoped>
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
</style>
