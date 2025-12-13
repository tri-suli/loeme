import { reactive } from 'vue'

export type ToastType = 'success' | 'error' | 'info'

export interface ToastItem {
  id: string
  type: ToastType
  message: string
  code?: number | string
  details?: string
  idempotencyKey?: string
  createdAt: number
  timeout?: number
}

const state = reactive({
  items: [] as ToastItem[],
  // track keys we've already shown to de-duplicate
  seenKeys: new Set<string>(),
})

function genId() {
  return Math.random().toString(36).slice(2) + Date.now().toString(36)
}

function push(item: Omit<ToastItem, 'id' | 'createdAt'>) {
  const id = genId()
  const createdAt = Date.now()
  const key = item.idempotencyKey
  if (key && state.seenKeys.has(key)) return id
  if (key) state.seenKeys.add(key)
  const toast: ToastItem = { id, createdAt, ...item }
  state.items.unshift(toast)
  // auto-dismiss (5s success/info, 8s error)
  const ms = item.type === 'error' ? 8000 : 5000
  setTimeout(() => remove(id), item.timeout ?? ms)
  // cap list length
  if (state.items.length > 5) state.items.splice(5)
  return id
}

export function remove(id: string) {
  const idx = state.items.findIndex((t) => t.id === id)
  if (idx >= 0) state.items.splice(idx, 1)
}

export function clear() {
  state.items.splice(0)
}

export function useToasts() {
  return {
    state,
    success(payload: { message: string; idempotencyKey?: string; timeout?: number }) {
      return push({ type: 'success', message: payload.message, idempotencyKey: payload.idempotencyKey, timeout: payload.timeout })
    },
    error(payload: { message: string; code?: number | string; details?: string; idempotencyKey?: string; timeout?: number }) {
      return push({ type: 'error', message: payload.message, code: payload.code, details: payload.details, idempotencyKey: payload.idempotencyKey, timeout: payload.timeout })
    },
    info(payload: { message: string; idempotencyKey?: string; timeout?: number }) {
      return push({ type: 'info', message: payload.message, idempotencyKey: payload.idempotencyKey, timeout: payload.timeout })
    },
    remove,
    clear,
  }
}
