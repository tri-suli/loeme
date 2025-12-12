<script setup lang="ts">
import { reactive, computed, onMounted, onUnmounted, nextTick } from 'vue'
import axios from 'axios'
import { Link } from '@inertiajs/vue3'

type Asset = { symbol: string; amount: string; locked_amount: string }
type Profile = { id: number; name: string; email: string; balance: string; assets: Asset[] }
type Order = {
  id: number
  user_id: number
  symbol: string
  side: 'buy' | 'sell'
  price: string
  amount: string
  remaining: string
  status: 1 | 2 | 3
  created_at?: string
}

const symbols = [
  { label: 'All', value: 'all' },
  { label: 'BTC', value: 'btc' },
  { label: 'ETH', value: 'eth' },
]

const sides = [
  { label: 'All', value: 'all' },
  { label: 'Buy', value: 'buy' },
  { label: 'Sell', value: 'sell' },
]

const statuses = [
  { label: 'All', value: 'all' },
  { label: 'Open', value: 1 },
  { label: 'Filled', value: 2 },
  { label: 'Cancelled', value: 3 },
]

const state = reactive({
  loading: true,
  loadingOrders: true,
  error: null as string | null,
  ordersError: null as string | null,
  profile: null as Profile | null,
  open: [] as Order[],
  history: [] as Order[],
})

const filters = reactive({
  symbol: 'all' as 'all' | 'btc' | 'eth',
  side: 'all' as 'all' | 'buy' | 'sell',
  status: 'all' as 'all' | 1 | 2 | 3,
  search: '',
})

const sort = reactive({
  field: 'created_at' as keyof Order | 'price' | 'amount' | 'remaining' | 'created_at',
  dir: 'desc' as 'asc' | 'desc',
})

// Decimal-safe comparator for positive decimal strings
function decCompare(a: string, b: string): number {
  if (a === b) return 0
  const [ai, af = ''] = a.split('.')
  const [bi, bf = ''] = b.split('.')
  if (ai.length !== bi.length) return ai.length > bi.length ? 1 : -1
  const ic = ai.localeCompare(bi)
  if (ic !== 0) return ic
  const len = Math.max(af.length, bf.length)
  const ap = af.padEnd(len, '0')
  const bp = bf.padEnd(len, '0')
  return ap.localeCompare(bp)
}

const fetchProfile = async () => {
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

const fetchMyOrders = async () => {
  state.loadingOrders = true
  state.ordersError = null
  try {
    const params: Record<string, any> = {}
    if (filters.symbol !== 'all') params.symbol = filters.symbol
    const { data } = await axios.get('/api/my/orders', { params })
    state.open = data.open || []
    state.history = data.history || []
  } catch (err: any) {
    if (err?.response?.status === 401) {
      window.location.href = '/login'
      return
    }
    state.ordersError = err?.response?.data?.message || 'Failed to load orders.'
  } finally {
    state.loadingOrders = false
  }
}

const applySort = (rows: Order[]) => {
  const dir = sort.dir === 'asc' ? 1 : -1
  const field = sort.field
  const copy = [...rows]
  copy.sort((a, b) => {
    let cmp = 0
    if (field === 'price' || field === 'amount' || field === 'remaining') {
      cmp = decCompare(a[field] as string, b[field] as string)
    } else if (field === 'created_at') {
      cmp = (a.created_at || '').localeCompare(b.created_at || '')
    } else if (field === 'symbol') {
      cmp = (a.symbol || '').localeCompare(b.symbol || '')
    } else if (field === 'side') {
      cmp = (a.side || '').localeCompare(b.side || '')
    } else if (field === 'status') {
      cmp = Number(a.status) - Number(b.status)
    } else {
      cmp = String((a as any)[field] ?? '').localeCompare(String((b as any)[field] ?? ''))
    }
    return dir * cmp
  })
  return copy
}

const filteredOpen = computed(() => {
  let rows = state.open
  if (filters.symbol !== 'all') rows = rows.filter((r) => r.symbol === filters.symbol)
  if (filters.side !== 'all') rows = rows.filter((r) => r.side === filters.side)
  if (filters.status !== 'all') rows = rows.filter((r) => r.status === filters.status)
  if (filters.search.trim()) {
    const q = filters.search.trim().toLowerCase()
    rows = rows.filter((r) => String(r.id).includes(q) || r.price.includes(q) || r.amount.includes(q))
  }
  return applySort(rows)
})

const filteredHistory = computed(() => {
  let rows = state.history
  if (filters.symbol !== 'all') rows = rows.filter((r) => r.symbol === filters.symbol)
  if (filters.side !== 'all') rows = rows.filter((r) => r.side === filters.side)
  if (filters.status !== 'all') rows = rows.filter((r) => r.status === filters.status)
  if (filters.search.trim()) {
    const q = filters.search.trim().toLowerCase()
    rows = rows.filter((r) => String(r.id).includes(q) || r.price.includes(q) || r.amount.includes(q))
  }
  return applySort(rows)
})

const toggleSort = (field: typeof sort.field) => {
  if (sort.field === field) {
    sort.dir = sort.dir === 'asc' ? 'desc' : 'asc'
  } else {
    sort.field = field
    sort.dir = 'desc'
  }
}

const keyToggleSort = (field: typeof sort.field, e: KeyboardEvent) => {
  if (e.key === 'Enter' || e.key === ' ') {
    e.preventDefault()
    toggleSort(field)
  }
}

const sideClass = (side: string) => (side === 'buy' ? 'text-green-600' : 'text-red-600')

const formattedUsd = computed(() => {
  const bal = state.profile?.balance ?? '0.00'
  const [w, f = '00'] = bal.split('.')
  const wc = w.replace(/\B(?=(\d{3})+(?!\d))/g, ',')
  return `USD ${wc}.${f.padEnd(2, '0').slice(0, 2)}`
})

let channel: any = null
const subscribeEcho = () => {
  try {
    const uid = state.profile?.id
    // @ts-ignore
    const Echo = (window as any).Echo
    if (!uid || !Echo) return
    if (channel) {
      try { channel.stopListening('OrderMatched') } catch {}
      try { Echo.leave(`private-user.${uid}`) } catch {}
    }
    channel = Echo.private(`private-user.${uid}`)
    channel.listen('.OrderMatched', (evt: any) => {
      const me = state.profile?.id
      if (!me) return
      const section = evt?.buyer_id === me ? evt?.buyer : evt?.seller
      if (!section) return
      // Update wallet
      if (section.balance) {
        state.profile = { ...(state.profile as any), balance: section.balance }
      }
      if (section.asset) {
        const a = section.asset
        const list = state.profile?.assets || []
        const idx = list.findIndex((x) => x.symbol === a.symbol)
        const next = { symbol: a.symbol, amount: a.amount, locked_amount: a.locked_amount }
        if (idx >= 0) list.splice(idx, 1, next)
        else list.push(next)
      }
      // Update orders lists (full fills move from open to history)
      const updatedId = section?.orders?.buy?.id || section?.orders?.sell?.id
      const updatedStatus = section?.orders?.buy?.status || section?.orders?.sell?.status
      if (updatedId) {
        const oi = state.open.findIndex((o) => o.id === updatedId)
        if (oi >= 0) {
          const row = { ...state.open[oi], status: updatedStatus as any, remaining: '0' }
          state.open.splice(oi, 1)
          // Prepend to history
          state.history.unshift(row)
        } else {
          // If it was already not in open, patch history entry if exists
          const hi = state.history.findIndex((o) => o.id === updatedId)
          if (hi >= 0) state.history.splice(hi, 1, { ...state.history[hi], status: updatedStatus as any })
        }
      }
    })
    // If backend emits OrderCancelled, update lists accordingly
    channel.listen('.OrderCancelled', (evt: any) => {
      const id = evt?.order_id || evt?.id
      if (!id) return
      // Move from open to history as cancelled
      const oi = state.open.findIndex((o) => o.id === id)
      if (oi >= 0) {
        const row = { ...state.open[oi], status: 3 as any }
        state.open.splice(oi, 1)
        state.history.unshift(row)
      } else {
        const hi = state.history.findIndex((o) => o.id === id)
        if (hi >= 0) state.history.splice(hi, 1, { ...state.history[hi], status: 3 as any })
      }
    })
  } catch (e) {
    console.warn('Echo subscribe failed', e)
  }
}

const refreshAll = async () => {
  await Promise.all([fetchProfile(), fetchMyOrders()])
}

const symbolChanged = async () => {
  await fetchMyOrders()
}

onMounted(async () => {
  await refreshAll()
  subscribeEcho()
  await nextTick()
})

onUnmounted(() => {
  try {
    const uid = state.profile?.id
    // @ts-ignore
    const Echo = (window as any).Echo
    if (uid && Echo) {
      if (channel) {
        try { channel.stopListening('OrderMatched') } catch {}
        try { channel.stopListening('OrderCancelled') } catch {}
      }
      Echo.leave(`private-user.${uid}`)
    }
  } catch {}
})

const headerBtnClass = 'px-2 py-1 cursor-pointer focus:outline-none focus:ring-2 focus:ring-blue-500 rounded'
</script>

<template>
  <div class="p-6">
    <h1 class="text-2xl font-bold mb-1">Orders &amp; Wallet</h1>
    <p class="text-gray-600 mb-4">Monitor your balances and manage open orders.</p>

    <div class="flex gap-3 mb-4">
      <Link href="/dashboard" class="text-blue-600 underline">Dashboard</Link>
      <Link href="/trade" class="text-blue-600 underline">Trade</Link>
    </div>

    <div class="grid gap-6 md:grid-cols-3">
      <!-- Wallet -->
      <section class="border rounded p-4 md:col-span-1">
        <h2 class="font-semibold mb-2">Wallet</h2>
        <div v-if="state.loading" class="text-gray-500">Loading…</div>
        <div v-else-if="state.error" class="text-red-600">{{ state.error }}</div>
        <div v-else>
          <div class="mb-2"><span class="font-medium">USD Balance:</span> {{ formattedUsd }}</div>
          <div>
            <h3 class="font-medium mb-1">Assets</h3>
            <div v-if="state.profile?.assets?.length">
              <ul class="space-y-1">
                <li v-for="a in state.profile!.assets" :key="a.symbol" class="flex justify-between">
                  <span>{{ a.symbol }}</span>
                  <span class="text-gray-600">Amt: {{ a.amount }} | Locked: {{ a.locked_amount }}</span>
                </li>
              </ul>
            </div>
            <div v-else class="text-gray-500">No assets</div>
          </div>
        </div>
      </section>

      <!-- Orders & History -->
      <section class="border rounded p-4 md:col-span-2">
        <div class="flex flex-col gap-3">
          <div class="flex flex-wrap gap-2 items-end">
            <div>
              <label class="block text-sm font-medium">Symbol</label>
              <select v-model="filters.symbol" @change="symbolChanged" class="border rounded px-2 py-1">
                <option v-for="s in symbols" :key="s.value" :value="s.value">{{ s.label }}</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium">Side</label>
              <select v-model="filters.side" class="border rounded px-2 py-1">
                <option v-for="s in sides" :key="s.value" :value="s.value">{{ s.label }}</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium">Status</label>
              <select v-model="filters.status" class="border rounded px-2 py-1">
                <option v-for="s in statuses" :key="String(s.value)" :value="s.value">{{ s.label }}</option>
              </select>
            </div>
            <div class="flex-1 min-w-[160px]">
              <label class="block text-sm font-medium">Search</label>
              <input v-model="filters.search" class="border rounded px-2 py-1 w-full" placeholder="Order id / price / amount" />
            </div>
            <div class="ml-auto">
              <button class="px-3 py-1 border rounded" @click="refreshAll">Refresh</button>
            </div>
          </div>

          <div>
            <h3 class="font-semibold mb-2">Open Orders</h3>
            <div v-if="state.loadingOrders" class="text-gray-500">Loading…</div>
            <div v-else-if="state.ordersError" class="text-red-600">{{ state.ordersError }}</div>
            <div v-else>
              <div v-if="filteredOpen.length === 0" class="text-gray-500">No open orders.</div>
              <div v-else class="overflow-x-auto">
                <table class="min-w-full text-sm">
                  <thead>
                    <tr class="text-left">
                      <th>
                        <button :class="headerBtnClass" tabindex="0" @click="toggleSort('created_at')" @keydown="(e)=>keyToggleSort('created_at', e)">Time</button>
                      </th>
                      <th>
                        <button :class="headerBtnClass" tabindex="0" @click="toggleSort('symbol')" @keydown="(e)=>keyToggleSort('symbol', e)">Symbol</button>
                      </th>
                      <th>
                        <button :class="headerBtnClass" tabindex="0" @click="toggleSort('side')" @keydown="(e)=>keyToggleSort('side', e)">Side</button>
                      </th>
                      <th>
                        <button :class="headerBtnClass" tabindex="0" @click="toggleSort('price')" @keydown="(e)=>keyToggleSort('price', e)">Price</button>
                      </th>
                      <th>
                        <button :class="headerBtnClass" tabindex="0" @click="toggleSort('amount')" @keydown="(e)=>keyToggleSort('amount', e)">Amount</button>
                      </th>
                      <th>
                        <button :class="headerBtnClass" tabindex="0" @click="toggleSort('remaining')" @keydown="(e)=>keyToggleSort('remaining', e)">Remaining</button>
                      </th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="o in filteredOpen" :key="o.id" class="border-t">
                      <td class="py-1 pr-2 whitespace-nowrap">{{ o.created_at?.replace('T',' ').replace('Z','') }}</td>
                      <td class="py-1 pr-2">{{ o.symbol.toUpperCase() }}</td>
                      <td class="py-1 pr-2" :class="sideClass(o.side)">{{ o.side.toUpperCase() }}</td>
                      <td class="py-1 pr-2">{{ o.price }}</td>
                      <td class="py-1 pr-2">{{ o.amount }}</td>
                      <td class="py-1 pr-2">{{ o.remaining }}</td>
                      <td class="py-1 pr-2">{{ o.status === 1 ? 'OPEN' : o.status === 2 ? 'FILLED' : 'CANCELLED' }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div class="mt-6">
            <h3 class="font-semibold mb-2">Recent History</h3>
            <div v-if="state.loadingOrders" class="text-gray-500">Loading…</div>
            <div v-else-if="state.ordersError" class="text-red-600">{{ state.ordersError }}</div>
            <div v-else>
              <div v-if="filteredHistory.length === 0" class="text-gray-500">No history.</div>
              <div v-else class="overflow-x-auto">
                <table class="min-w-full text-sm">
                  <thead>
                    <tr class="text-left">
                      <th>
                        <button :class="headerBtnClass" tabindex="0" @click="toggleSort('created_at')" @keydown="(e)=>keyToggleSort('created_at', e)">Time</button>
                      </th>
                      <th>
                        <button :class="headerBtnClass" tabindex="0" @click="toggleSort('symbol')" @keydown="(e)=>keyToggleSort('symbol', e)">Symbol</button>
                      </th>
                      <th>
                        <button :class="headerBtnClass" tabindex="0" @click="toggleSort('side')" @keydown="(e)=>keyToggleSort('side', e)">Side</button>
                      </th>
                      <th>
                        <button :class="headerBtnClass" tabindex="0" @click="toggleSort('price')" @keydown="(e)=>keyToggleSort('price', e)">Price</button>
                      </th>
                      <th>
                        <button :class="headerBtnClass" tabindex="0" @click="toggleSort('amount')" @keydown="(e)=>keyToggleSort('amount', e)">Amount</button>
                      </th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="o in filteredHistory" :key="o.id" class="border-t">
                      <td class="py-1 pr-2 whitespace-nowrap">{{ o.created_at?.replace('T',' ').replace('Z','') }}</td>
                      <td class="py-1 pr-2">{{ o.symbol.toUpperCase() }}</td>
                      <td class="py-1 pr-2" :class="sideClass(o.side)">{{ o.side.toUpperCase() }}</td>
                      <td class="py-1 pr-2">{{ o.price }}</td>
                      <td class="py-1 pr-2">{{ o.amount }}</td>
                      <td class="py-1 pr-2">{{ o.status === 2 ? 'FILLED' : 'CANCELLED' }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>
