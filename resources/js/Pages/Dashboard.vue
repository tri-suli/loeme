<script setup lang="ts">
import { onMounted, reactive, computed } from 'vue'
import axios from 'axios'

type Profile = {
  id: number
  name: string
  email: string
  balance: string
  assets: { symbol: string; amount: string; locked_amount: string }[]
}

const state = reactive<{ loading: boolean; error: string | null; profile: Profile | null }>(
  {
    loading: true,
    error: null,
    profile: null,
  }
)

const fetchProfile = async () => {
  state.loading = true
  state.error = null
  try {
    const { data } = await axios.get('/api/profile')
    state.profile = data
  } catch (err: any) {
    if (err?.response?.status === 401) {
      // Session expired or not authenticated; navigate to login
      window.location.href = '/login'
      return
    }
    state.error = err?.response?.data?.message || 'Failed to load profile.'
  } finally {
    state.loading = false
  }
}

const formattedBalance = computed(() => {
  const bal = state.profile?.balance ?? '0.00'
  // Keep it string-safe and format to USD with two decimals
  const [whole, frac = '00'] = bal.split('.')
  const withCommas = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')
  return `USD ${withCommas}.${frac.padEnd(2, '0').slice(0, 2)}`
})

onMounted(fetchProfile)
</script>

<template>
  <div class="p-6">
    <h1 class="text-2xl font-bold mb-2">Dashboard</h1>
    <p class="text-gray-600">Welcome to your dashboard</p>

    <div class="mt-6">
      <div v-if="state.loading" class="text-gray-500">Loading profile…</div>
      <div v-else-if="state.error" class="text-red-600">{{ state.error }}</div>
      <div v-else class="grid gap-6 md:grid-cols-2">
        <!-- Profile Section -->
        <section class="border rounded p-4">
          <h2 class="font-semibold mb-2">Profile</h2>
          <div class="text-sm text-gray-700">
            <div><span class="font-medium">Name:</span> {{ state.profile?.name }}</div>
            <div><span class="font-medium">Email:</span> {{ state.profile?.email }}</div>
          </div>
        </section>

        <!-- Wallet Section -->
        <section class="border rounded p-4">
          <h2 class="font-semibold mb-2">Wallet</h2>
          <div class="text-sm text-gray-700">
            <div class="mb-2"><span class="font-medium">USD Balance:</span> {{ formattedBalance }}</div>
            <div v-if="state.profile?.assets?.length">
              <h3 class="font-medium mb-1">Assets</h3>
              <ul class="space-y-1">
                <li v-for="a in state.profile!.assets" :key="a.symbol" class="flex justify-between">
                  <span class="text-gray-800">{{ a.symbol }}</span>
                  <span class="text-gray-600">Amt: {{ a.amount }} | Locked: {{ a.locked_amount }}</span>
                </li>
              </ul>
            </div>
            <div v-else class="text-gray-500">No assets</div>
          </div>
        </section>
      </div>
    </div>

    <form method="post" action="/logout" class="mt-6">
      <input type="hidden" name="_token" :value="$page.props.csrf_token" />
      <button type="submit" class="bg-gray-800 text-white px-4 py-2 rounded hover:bg-gray-700">Log out</button>
    </form>
  </div>
  </template>
