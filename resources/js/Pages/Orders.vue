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
function compareDecimalStrings(a: string, b: string): number {
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

// Decimal-safe subtraction using BigInt scaling (scale up to 18)
function subtractDecimalStrings(a: string, b: string): string {
  const SCALE = 18
  const norm = (v: string) => {
    const s = String(v || '0').trim()
    if (!s.includes('.')) return { w: s.replace(/^\+/, ''), f: '' }
    const [w, f] = s.split('.')
    return { w: w.replace(/^\+/, ''), f }
  }
  const A = norm(a)
  const B = norm(b)
  const fa = (A.f || '').slice(0, SCALE).padEnd(SCALE, '0')
  const fb = (B.f || '').slice(0, SCALE).padEnd(SCALE, '0')
  const ia = (A.w || '0').replace(/^0+(?=\d)/, '') + fa
  const ib = (B.w || '0').replace(/^0+(?=\d)/, '') + fb
  let res = BigInt(ia || '0') - BigInt(ib || '0')
  if (res < 0n) res = 0n
  const s = res.toString().padStart(SCALE + 1, '0')
  const whole = s.slice(0, -SCALE) || '0'
  const frac = s.slice(-SCALE).replace(/0+$/, '')
  return frac ? `${whole}.${frac}` : whole
}

// Idempotency tracking for processed events
const processedEventKeys = new Set<string>()
const markEventProcessed = (key: string): boolean => {
  if (processedEventKeys.has(key)) return false
  processedEventKeys.add(key)
  return true
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
  const sortedRows = [...rows]
  sortedRows.sort((a, b) => {
    let cmp = 0
    if (field === 'price' || field === 'amount' || field === 'remaining') {
      cmp = compareDecimalStrings(a[field] as string, b[field] as string)
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
  return sortedRows
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

const getSideColorClass = (side: string) => (side === 'buy' ? 'text-green-600' : 'text-red-600')

const formattedUsd = computed(() => {
  const bal = state.profile?.balance ?? '0.00'
  const [w, f = '00'] = bal.split('.')
  const wc = w.replace(/\B(?=(\d{3})+(?!\d))/g, ',')
  return `USD ${wc}.${f.padEnd(2, '0').slice(0, 2)}`
})

let userChannel: any = null
let orderbookChannel: any = null
const subscribeToEchoChannels = () => {
  try {
    const uid = state.profile?.id
    // @ts-ignore
    const Echo = (window as any).Echo
    if (!uid || !Echo) return
    if (userChannel) {
      try { userChannel.stopListening('OrderMatched') } catch {}
      try { userChannel.stopListening('OrderCancelled') } catch {}
      try { Echo.leave(`private-user.${uid}`) } catch {}
    }
    userChannel = Echo.private(`private-user.${uid}`)
    userChannel.listen('.OrderMatched', (event: any) => {
      // Idempotency by trade id
      const tradeKey = event?.trade_id ? `match:${event.trade_id}` : null
      if (tradeKey && !markEventProcessed(tradeKey)) return
      const currentUserId = state.profile?.id
      if (!currentUserId) return
      const participantSection = event?.buyer_id === currentUserId ? event?.buyer : event?.seller
      if (!participantSection) return
      // Update wallet
      if (participantSection.balance) {
        state.profile = { ...(state.profile as any), balance: participantSection.balance }
      }
      if (participantSection.asset) {
        const assetData = participantSection.asset
        const assetList = state.profile?.assets || []
        const assetIndex = assetList.findIndex((x) => x.symbol === assetData.symbol)
        const updatedAssetEntry = { symbol: assetData.symbol, amount: assetData.amount, locked_amount: assetData.locked_amount }
        if (assetIndex >= 0) assetList.splice(assetIndex, 1, updatedAssetEntry)
        else assetList.push(updatedAssetEntry)
      }
      // Update orders lists (full fills move from open to history)
      const updatedOrderId = participantSection?.orders?.buy?.id || participantSection?.orders?.sell?.id
      const updatedOrderStatus = participantSection?.orders?.buy?.status || participantSection?.orders?.sell?.status
      if (updatedOrderId) {
        // Try to locate order in open list
        const openIndex = state.open.findIndex((o) => o.id === updatedOrderId)
        if (openIndex >= 0) {
          // If event has remaining value or trade amount, apply decrement safely; default to 0 when filled
          const currentOrder = state.open[openIndex]
          let nextRemainingAmount = currentOrder.remaining
          if (updatedOrderStatus === 2) {
            nextRemainingAmount = '0'
          } else if (participantSection?.orders?.buy?.remaining || participantSection?.orders?.sell?.remaining) {
            nextRemainingAmount = String(participantSection?.orders?.buy?.remaining || participantSection?.orders?.sell?.remaining)
          } else if (event?.amount) {
            nextRemainingAmount = subtractDecimalStrings(currentOrder.remaining, String(event.amount))
          }
          const updatedOrder = { ...currentOrder, status: updatedOrderStatus as any, remaining: nextRemainingAmount }
          // If filled or cancelled, move to history, else patch in place
          if (updatedOrder.status === 2 || updatedOrder.status === 3) {
            state.open.splice(openIndex, 1)
            state.history.unshift({ ...updatedOrder, remaining: updatedOrder.remaining })
          } else {
            state.open.splice(openIndex, 1, updatedOrder)
          }
        } else {
          // If it was already not in open, patch history entry if exists
          const historyIndex = state.history.findIndex((o) => o.id === updatedOrderId)
          if (historyIndex >= 0) state.history.splice(historyIndex, 1, { ...state.history[historyIndex], status: updatedOrderStatus as any })
        }
      }
    })
    // If backend emits OrderCancelled, update lists accordingly
    userChannel.listen('.OrderCancelled', (event: any) => {
      const orderId = event?.order_id || event?.id
      if (!orderId) return
      // Idempotency by order id
      const cancelKey = `cancel:${orderId}`
      if (!markEventProcessed(cancelKey)) return
      // Patch wallet if provided
      const portfolioUpdate = event?.portfolio
      if (portfolioUpdate) {
        const nextProf: any = { ...(state.profile as any) }
        if (portfolioUpdate.balance) nextProf.balance = portfolioUpdate.balance
        if (portfolioUpdate.asset) {
          const assetData = portfolioUpdate.asset
          const assetList = nextProf.assets || []
          const assetIndex = assetList.findIndex((x: any) => x.symbol === assetData.symbol)
          const updatedAssetEntry = { symbol: assetData.symbol, amount: assetData.amount, locked_amount: assetData.locked_amount }
          if (assetIndex >= 0) assetList.splice(assetIndex, 1, updatedAssetEntry)
          else assetList.push(updatedAssetEntry)
          nextProf.assets = assetList
        }
        state.profile = nextProf
      }
      // Move from open to history as cancelled
      const openIndex = state.open.findIndex((o) => o.id === orderId)
      if (openIndex >= 0) {
        const updatedOrder = { ...state.open[openIndex], status: 3 as any, remaining: '0' }
        state.open.splice(openIndex, 1)
        state.history.unshift(updatedOrder)
      } else {
        const historyIndex = state.history.findIndex((o) => o.id === orderId)
        if (historyIndex >= 0) state.history.splice(historyIndex, 1, { ...state.history[historyIndex], status: 3 as any, remaining: '0' })
      }
    })

    // Subscribe to orderbook channel for current symbol filter (if not 'all')
    const selectedSymbol = filters.symbol
    if (selectedSymbol && selectedSymbol !== 'all') {
      if (orderbookChannel) {
        try { orderbookChannel.stopListening('OrderCancelled') } catch {}
        try {
          const prevName = orderbookChannel?.name
          if (prevName) Echo.leave(prevName)
        } catch {}
      }
      orderbookChannel = Echo.private(`orderbook.${selectedSymbol}`)
      orderbookChannel.listen('.OrderCancelled', (event: any) => {
        // Only apply if it is my order
        const currentUserId = state.profile?.id
        if (!currentUserId) return
        if ((event?.user_id || event?.owner_id) !== currentUserId) return
        const orderId = event?.order_id || event?.id
        if (!orderId) return
        const cancelKey = `cancel:${orderId}`
        if (!markEventProcessed(cancelKey)) return
        // Apply the same cancellation patch logic
        const openIndex = state.open.findIndex((o) => o.id === orderId)
        if (openIndex >= 0) {
          const updatedOrder = { ...state.open[openIndex], status: 3 as any, remaining: '0' }
          state.open.splice(openIndex, 1)
          state.history.unshift(updatedOrder)
        } else {
          const historyIndex = state.history.findIndex((o) => o.id === orderId)
          if (historyIndex >= 0) state.history.splice(historyIndex, 1, { ...state.history[historyIndex], status: 3 as any, remaining: '0' })
        }
      })
    } else if (orderbookChannel && Echo) {
      // If switched to 'all', leave any previous orderbook channel
      try { orderbookChannel.stopListening('OrderCancelled') } catch {}
      try { Echo.leaveChannel ? Echo.leaveChannel(orderbookChannel.name) : Echo.leave(orderbookChannel.name) } catch {}
      orderbookChannel = null
    }
  } catch (e) {
    console.warn('Echo subscribe failed', e)
  }
}

const fetchInitialData = async () => {
  await Promise.all([fetchProfile(), fetchMyOrders()])
}

const handleSymbolFilterChange = async () => {
  await fetchMyOrders()
  // Re-subscribe orderbook channel for the new symbol
  try {
    const uid = state.profile?.id
    // @ts-ignore
    const Echo = (window as any).Echo
    if (!Echo || !uid) return
    // Reuse subscribeToEchoChannels to refresh both private-user and orderbook channels
    subscribeToEchoChannels()
  } catch {}
}

onMounted(async () => {
  await fetchInitialData()
  subscribeToEchoChannels()
  await nextTick()
})

onUnmounted(() => {
  try {
    const uid = state.profile?.id
    // @ts-ignore
    const Echo = (window as any).Echo
    if (uid && Echo) {
      if (userChannel) {
        try { userChannel.stopListening('OrderMatched') } catch {}
        try { userChannel.stopListening('OrderCancelled') } catch {}
      }
      Echo.leave(`private-user.${uid}`)
      if (orderbookChannel) {
        try { orderbookChannel.stopListening('OrderCancelled') } catch {}
        try {
          const name = orderbookChannel?.name || (filters.symbol !== 'all' ? `orderbook.${filters.symbol}` : null)
          if (name) Echo.leave(name)
        } catch {}
      }
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
              <select v-model="filters.symbol" @change="handleSymbolFilterChange" class="border rounded px-2 py-1">
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
              <button class="px-3 py-1 border rounded" @click="fetchInitialData">Refresh</button>
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
                      <td class="py-1 pr-2" :class="getSideColorClass(o.side)">{{ o.side.toUpperCase() }}</td>
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
                      <td class="py-1 pr-2" :class="getSideColorClass(o.side)">{{ o.side.toUpperCase() }}</td>
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
