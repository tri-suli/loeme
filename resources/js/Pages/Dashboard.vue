<script setup lang="ts">
import { onMounted, reactive, computed } from 'vue';
import axios from 'axios';
import { Link } from '@inertiajs/vue3';

type Profile = {
    id: number;
    name: string;
    email: string;
    balance: string;
    assets: { symbol: string; amount: string; locked_amount: string }[];
};

const state = reactive<{ loading: boolean; error: string | null; profile: Profile | null }>({
    loading: true,
    error: null,
    profile: null,
});

const cancelState = reactive<{
    orderId: string;
    loading: boolean;
    result: string | null;
    error: string | null;
}>({
    orderId: '',
    loading: false,
    result: null,
    error: null,
});

const fetchProfile = async () => {
    state.loading = true;
    state.error = null;
    try {
        const { data } = await axios.get('/api/profile');
        state.profile = data;
    } catch (err: any) {
        if (err?.response?.status === 401) {
            // Session expired or not authenticated; navigate to login
            window.location.href = '/login';
            return;
        }
        state.error = err?.response?.data?.message || 'Failed to load profile.';
    } finally {
        state.loading = false;
    }
};

const formattedBalance = computed(() => {
    const bal = state.profile?.balance ?? '0.00';
    // Keep it string-safe and format to USD with two decimals
    const [whole, frac = '00'] = bal.split('.');
    const withCommas = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return `USD ${withCommas}.${frac.padEnd(2, '0').slice(0, 2)}`;
});

onMounted(fetchProfile);

const cancelOrder = async () => {
    cancelState.error = null;
    cancelState.result = null;
    const id = cancelState.orderId.trim();
    if (!id || !/^\d+$/.test(id)) {
        cancelState.error = 'Please enter a valid numeric order ID.';
        return;
    }
    cancelState.loading = true;
    try {
        const { data } = await axios.post(`/api/orders/${id}/cancel`);
        cancelState.result = `Order #${data.order.id} status: ${data.order.status}`;
        await fetchProfile();
    } catch (err: any) {
        if (err?.response?.status === 404) {
            cancelState.error = 'Order not found.';
        } else if (err?.response?.status === 401) {
            window.location.href = '/login';
            return;
        } else {
            cancelState.error = err?.response?.data?.message || 'Failed to cancel order.';
        }
    } finally {
        cancelState.loading = false;
    }
};
</script>

<template>
    <div class="p-6">
        <h1 class="text-2xl font-bold mb-2">Dashboard</h1>
        <p class="text-gray-600">Welcome to your dashboard</p>

    <div class="mt-4">
      <Link
        href="/trade"
        class="inline-block bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-500"
      >
        Go to Trade
      </Link>
      <Link
        href="/orders"
        class="inline-block ml-3 bg-gray-700 text-white px-4 py-2 rounded hover:bg-gray-600"
      >
        View Orders & Wallet
      </Link>
    </div>

        <div class="mt-6">
            <div v-if="state.loading" class="text-gray-500">Loading profile…</div>
            <div v-else-if="state.error" class="text-red-600">{{ state.error }}</div>
            <div v-else class="grid gap-6 md:grid-cols-2">
                <!-- Profile Section -->
                <section class="border rounded p-4">
                    <h2 class="font-semibold mb-2">Profile</h2>
                    <div class="text-sm text-gray-700">
                        <div><span class="font-medium">Name:</span> {{ state.profile?.name }}</div>
                        <div>
                            <span class="font-medium">Email:</span> {{ state.profile?.email }}
                        </div>
                    </div>
                </section>

                <!-- Wallet Section -->
                <section class="border rounded p-4">
                    <h2 class="font-semibold mb-2">Wallet</h2>
                    <div class="text-sm text-gray-700">
                        <div class="mb-2">
                            <span class="font-medium">USD Balance:</span> {{ formattedBalance }}
                        </div>
                        <div v-if="state.profile?.assets?.length">
                            <h3 class="font-medium mb-1">Assets</h3>
                            <ul class="space-y-1">
                                <li
                                    v-for="a in state.profile!.assets"
                                    :key="a.symbol"
                                    class="flex justify-between"
                                >
                                    <span class="text-gray-800">{{ a.symbol }}</span>
                                    <span class="text-gray-600"
                                        >Amt: {{ a.amount }} | Locked: {{ a.locked_amount }}</span
                                    >
                                </li>
                            </ul>
                        </div>
                        <div v-else class="text-gray-500">No assets</div>
                    </div>
                </section>
            </div>
        </div>

        <!-- Cancel Order Section -->
        <section class="mt-6 border rounded p-4">
            <h2 class="font-semibold mb-2">Cancel Order</h2>
            <div class="flex items-center gap-2">
                <input
                    v-model="cancelState.orderId"
                    type="text"
                    inputmode="numeric"
                    pattern="\\d*"
                    placeholder="Enter Order ID"
                    class="border rounded px-2 py-1 w-48"
                />
                <button
                    :disabled="cancelState.loading"
                    @click="cancelOrder"
                    class="bg-red-600 disabled:opacity-50 text-white px-3 py-1 rounded hover:bg-red-500"
                >
                    {{ cancelState.loading ? 'Cancelling…' : 'Cancel Order' }}
                </button>
            </div>
            <div v-if="cancelState.result" class="text-green-700 mt-2">
                {{ cancelState.result }}
            </div>
            <div v-if="cancelState.error" class="text-red-600 mt-2">{{ cancelState.error }}</div>
        </section>

        <form method="post" action="/logout" class="mt-6">
            <input type="hidden" name="_token" :value="$page.props.csrf_token" />
            <button
                type="submit"
                class="bg-gray-800 text-white px-4 py-2 rounded hover:bg-gray-700"
            >
                Log out
            </button>
        </form>
    </div>
</template>
