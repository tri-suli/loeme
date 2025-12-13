import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

// Make Pusher available for Echo
// @ts-ignore
window.Pusher = Pusher

// Pull config from Vite env
const enabled = (import.meta.env.VITE_ECHO_ENABLED ?? 'true') !== 'false'
const key = (import.meta.env.VITE_PUSHER_KEY ?? '').toString()
const cluster = (import.meta.env.VITE_PUSHER_CLUSTER ?? 'mt1').toString()
const host = (import.meta.env.VITE_PUSHER_HOST ?? '').toString()
const scheme = (import.meta.env.VITE_PUSHER_SCHEME ?? 'https').toString()
const portStr = (import.meta.env.VITE_PUSHER_PORT ?? '').toString()
const port = portStr ? Number(portStr) : (scheme === 'https' ? 443 : 80)
const forceTlsEnv = (import.meta.env.VITE_ECHO_FORCE_TLS ?? '').toString().toLowerCase()
const forceTLS = forceTlsEnv ? forceTlsEnv === 'true' : scheme === 'https'

let echo: Echo | null = null

try {
    if (enabled && key) {
        // Use a custom authorizer to ensure Sanctum session cookies are sent
        const authorizer = (channel: any) => {
            return {
                authorize: (socketId: string, callback: (error: boolean, data: any) => void) => {
                    try {
                        // Prefer global axios configured with withCredentials
                        // @ts-ignore
                        const ax = (window as any).axios
                        const payload = { socket_id: socketId, channel_name: channel.name }
                        if (ax) {
                            ax.post('/broadcasting/auth', payload, { withCredentials: true })
                                .then((res: any) => callback(false, res.data))
                                .catch((err: any) => callback(true, err))
                        } else {
                            // Fallback to fetch
                            fetch('/broadcasting/auth', {
                                method: 'POST',
                                credentials: 'include',
                                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                body: JSON.stringify(payload),
                            })
                                .then(async (r) => {
                                    if (!r.ok) throw new Error(`Auth failed: ${r.status}`)
                                    const data = await r.json()
                                    callback(false, data)
                                })
                                .catch((e) => callback(true, e))
                        }
                    } catch (e) {
                        callback(true, e)
                    }
                },
            }
        }

        // Configure Echo
        echo = new Echo({
            broadcaster: 'pusher',
            key,
            cluster,
            wsHost: host || undefined,
            wsPort: port,
            wssPort: port,
            forceTLS,
            enabledTransports: ['ws', 'wss'],
            authorizer,
        })

        // @ts-ignore
        ;(window as any).Echo = echo
        console.info('[Echo] Initialized')
    } else {
        console.info('[Echo] Disabled or missing VITE_PUSHER_KEY')
    }
} catch (e) {
    console.warn('[Echo] init failed', e)
}

export default echo
