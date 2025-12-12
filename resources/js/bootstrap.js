import axios from 'axios';
import { useToasts } from './stores/toasts';

window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
// Ensure cookies are sent with requests (needed for Sanctum session auth)
window.axios.defaults.withCredentials = true;

// Global interceptors -> toasts
try {
    const toasts = useToasts();
    window.axios.interceptors.response.use(
        (response) => {
            try {
                const cfg = response?.config || {};
                const method = (cfg.method || 'GET').toUpperCase();
                const url = cfg.url || '';
                // Success toast for order placement
                const isPlace = method === 'POST' && /\/api\/orders(?:\?.*)?$/.test(url || '');
                const isCancel = method === 'POST' && /\/api\/orders\/(\d+)\/cancel(?:\?.*)?$/.test(url || '');
                if (isPlace || isCancel) {
                    const order = response?.data?.order || response?.data;
                    const sym = (order?.symbol || '').toString().toUpperCase();
                    const side = (order?.side || '').toString().toUpperCase();
                    const price = order?.price;
                    const amount = order?.amount;
                    const msgBase = isPlace ? 'Order placed' : 'Order cancelled';
                    const msg = sym ? `${msgBase}: ${sym} ${side}${price ? ` @ ${price}` : ''}${amount ? ` x ${amount}` : ''}` : `${msgBase}.`;
                    const key = (cfg.headers && (cfg.headers['Idempotency-Key'] || cfg.headers['idempotency-key'])) || (order?.id ? `${isPlace ? 'placed' : 'cancel'}:${order.id}` : `${method}:${url}:ok`);
                    toasts.success({ message: msg, idempotencyKey: key });
                }
            } catch { /* noop */ }
            return response;
        },
        (error) => {
            try {
                const status = error?.response?.status;
                const message = error?.response?.data?.message || error?.message || 'Request failed';
                const code = error?.response?.data?.code || status;
                let details;
                const data = error?.response?.data;
                if (data && typeof data === 'object') {
                    try { details = JSON.stringify(data); } catch { /* noop */ }
                } else if (typeof data === 'string') {
                    details = data;
                }
                const method = (error?.config?.method || 'GET').toUpperCase();
                const url = error?.config?.url || '';
                const key = `http:${method}:${url}:${status}:${message}`;
                toasts.error({ message, code, details, idempotencyKey: key });
            } catch { /* noop */ }
            return Promise.reject(error);
        }
    );
} catch { /* noop */ }
