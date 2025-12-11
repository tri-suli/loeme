<script setup lang="ts">
import { reactive, computed, onMounted, ref, nextTick } from 'vue'
import axios from 'axios'
import { Link } from '@inertiajs/vue3'

type Profile = {
  balance: string
}

const state = reactive({
  loading: true,
  profile: null as Profile | null,
  error: null as string | null,
})

const symbols = [
  { label: 'BTC', value: 'btc' },
  { label: 'ETH', value: 'eth' },
]

const form = reactive({
  symbol: 'BTC',
  side: 'buy' as 'buy',
  price: '',
  amount: '',
  submitting: false,
  errors: {} as Record<string, string>,
})

const firstInvalidRef = ref<HTMLInputElement | null>(null)
const priceRef = ref<HTMLInputElement | null>(null)
const amountRef = ref<HTMLInputElement | null>(null)

const isPositiveDecimal = (v: string) => /^\d+(?:\.\d+)?$/.test(v) && Number(v) > 0

const estimatedCost = computed(() => {
  const p = form.price.trim()
  const a = form.amount.trim()
  if (!p || !a || !isPositiveDecimal(p) || !isPositiveDecimal(a)) return '—'
  // Avoid JS float; show simple string-based product with limited decimals
  try {
    const [pi, pf = ''] = p.split('.')
    const [ai, af = ''] = a.split('.')
    const scale = Math.min(8, (pf?.length || 0) + (af?.length || 0))
    const big = BigInt((pi + pf.padEnd(18, '0')).replace(/^0+/, '') || '0') *
      BigInt((ai + af.padEnd(18, '0')).replace(/^0+/, '') || '0')
    const s = big.toString().padStart(36, '0')
    const whole = s.slice(0, s.length - 36) || '0'
    const frac = s.slice(s.length - 36, s.length - 36 + scale).padEnd(2, '0').slice(0, Math.max(2, scale))
    return `${whole}.${frac}`.replace(/^\./, '0.')
  } catch {
    // Fallback to simple multiply for display only
    const v = Number(p) * Number(a)
    return v.toFixed(2)
  }
})

const canSubmit = computed(() => {
  return (
    !state.loading &&
    !form.submitting &&
    !!form.symbol &&
    isPositiveDecimal(form.price.trim()) &&
    isPositiveDecimal(form.amount.trim())
  )
})

const loadProfile = async () => {
  state.loading = true
  state.error = null
  try {
    const { data } = await axios.get('/api/profile')
    state.profile = data
  } catch (err: any) {
    if (err?.response?.status === 401) {
      window.location.href = '/login'
      return
    }
    state.error = err?.response?.data?.message || 'Failed to load profile.'
  } finally {
    state.loading = false
  }
}

onMounted(() => {
  loadProfile().then(() => priceRef.value?.focus())
})

const focusFirstInvalid = async () => {
  await nextTick()
  if (form.errors.price) {
    priceRef.value?.focus()
    return
  }
  if (form.errors.amount) {
    amountRef.value?.focus()
  }
}

const submit = async () => {
  if (!canSubmit.value) return
  form.submitting = true
  form.errors = {}
  try {
    const payload = {
      symbol: form.symbol,
      side: 'buy',
      price: form.price,
      amount: form.amount,
    }
    const { data } = await axios.post('/api/orders', payload)
    // Refresh profile to reflect deducted balance
    await loadProfile()
    // Reset only price and amount, keep symbol
    form.price = ''
    form.amount = ''
    priceRef.value?.focus()
  } catch (err: any) {
    if (err?.response?.status === 422) {
      const errs = err.response.data.errors || {}
      const map: Record<string, string> = {}
      Object.keys(errs).forEach((k) => (map[k] = Array.isArray(errs[k]) ? errs[k][0] : String(errs[k])))
      form.errors = map
      await focusFirstInvalid()
      return
    }
    state.error = err?.response?.data?.message || 'Failed to place order.'
  } finally {
    form.submitting = false
  }
}
</script>

<template>
  <div class="p-6">
    <h1 class="text-2xl font-bold mb-2">Trade</h1>
    <p class="text-gray-600 mb-4">Place a Buy Limit Order</p>

    <div class="mb-4">
      <Link
        href="/dashboard"
        class="inline-block bg-gray-200 text-gray-800 px-3 py-1 rounded hover:bg-gray-300"
      >
        ← Back to Dashboard
      </Link>
    </div>

    <div v-if="state.loading" class="text-gray-500">Loading…</div>
    <div v-else>
      <div v-if="state.error" class="text-red-600 mb-4">{{ state.error }}</div>

      <div class="grid md:grid-cols-2 gap-6">
        <!-- Buy Form -->
        <section class="border rounded p-4">
          <h2 class="font-semibold mb-3">Limit Order (Buy)</h2>

          <div class="mb-3">
            <label class="block text-sm font-medium mb-1" for="symbol">Symbol</label>
            <select id="symbol" v-model="form.symbol" class="border rounded px-3 py-2 w-full">
              <option v-for="s in symbols" :key="s.value" :value="s.value">{{ s.label }}</option>
            </select>
            <p class="text-xs text-gray-500 mt-1">Choose the asset to buy.</p>
          </div>

          <input type="hidden" name="side" value="buy" />

          <div class="mb-3">
            <label class="block text-sm font-medium mb-1" for="price">Price (USD)</label>
            <input
              id="price"
              ref="priceRef"
              v-model="form.price"
              type="text"
              inputmode="decimal"
              placeholder="Price per unit in USD"
              class="border rounded px-3 py-2 w-full"
            />
            <p class="text-xs text-gray-500 mt-1">Limit price per unit (e.g., 62000.00)</p>
            <p v-if="form.errors.price" class="text-xs text-red-600 mt-1">{{ form.errors.price }}</p>
          </div>

          <div class="mb-3">
            <label class="block text-sm font-medium mb-1" for="amount">Amount</label>
            <input
              id="amount"
              ref="amountRef"
              v-model="form.amount"
              type="text"
              inputmode="decimal"
              placeholder="Amount to buy"
              class="border rounded px-3 py-2 w-full"
            />
            <p class="text-xs text-gray-500 mt-1">Asset amount (e.g., 0.005)</p>
            <p v-if="form.errors.amount" class="text-xs text-red-600 mt-1">{{ form.errors.amount }}</p>
          </div>

          <div class="mb-3 text-sm text-gray-700">
            <div>USD Balance: <span class="font-medium">{{ state.profile?.balance ?? '0.00' }}</span></div>
            <div>Estimated Cost: <span class="font-medium">{{ estimatedCost }}</span></div>
          </div>

          <button
            :disabled="!canSubmit"
            @click.prevent="submit"
            class="bg-blue-600 disabled:bg-blue-300 text-white px-4 py-2 rounded"
          >
            {{ form.submitting ? 'Placing…' : 'Place Buy Order' }}
          </button>
        </section>

        <!-- Help / Info -->
        <section class="border rounded p-4 text-sm text-gray-700">
          <h2 class="font-semibold mb-2">Notes</h2>
          <ul class="list-disc list-inside space-y-1">
            <li>Only BUY side is enabled in this step.</li>
            <li>Order cost is price × amount. USD balance is deducted immediately.</li>
            <li>All numbers are treated as precise decimals on the server.</li>
          </ul>
        </section>
      </div>
    </div>
  </div>
</template>
