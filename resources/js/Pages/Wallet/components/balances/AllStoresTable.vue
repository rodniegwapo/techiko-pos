<script setup>
import { computed } from "vue";
import { useHelpers } from "@/Composables/useHelpers";

/**
 * Every store's money right now: its cash drawer (today's Expected) and each payment channel,
 * one row per store, for owners.
 */
const props = defineProps({
    /** { stores: [{ id, name, drawer, channels: [{ id, name, kind, balance }], total }], total } */
    allStores: { type: Object, required: true },
});

const { formattedTotal } = useHelpers();

// Channels by name across stores, so stores with the same channel (e.g. GCash) line up.
const channelNames = computed(() => [...new Set(props.allStores.stores.flatMap((s) => s.channels.map((c) => c.name)))].sort());
const channelAt = (store, name) => store.channels.filter((c) => c.name === name).reduce((sum, c) => sum + c.balance, 0);
const hasChannel = (store, name) => store.channels.some((c) => c.name === name);
const columnTotal = (name) => props.allStores.stores.reduce((sum, s) => sum + channelAt(s, name), 0);
const drawerTotal = computed(() => props.allStores.stores.reduce((sum, s) => sum + s.drawer, 0));
</script>

<template>
    <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-sm" data-testid="all-stores-money">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500">
                <tr>
                    <th class="px-4 py-2">Store</th>
                    <th class="px-4 py-2 text-right">Cash drawer</th>
                    <th v-for="name in channelNames" :key="name" class="px-4 py-2 text-right">{{ name }}</th>
                    <th class="px-4 py-2 text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="store in allStores.stores" :key="store.id" class="border-t border-gray-100" data-testid="all-stores-row">
                    <td class="px-4 py-2 font-medium text-gray-800">{{ store.name }}</td>
                    <td class="px-4 py-2 text-right font-mono">{{ formattedTotal(store.drawer) }}</td>
                    <td v-for="name in channelNames" :key="name" class="px-4 py-2 text-right font-mono">
                        <template v-if="hasChannel(store, name)">{{ formattedTotal(channelAt(store, name)) }}</template>
                        <span v-else class="text-gray-300">—</span>
                    </td>
                    <td class="px-4 py-2 text-right font-mono font-semibold">{{ formattedTotal(store.total) }}</td>
                </tr>
                <tr v-if="!allStores.stores.length">
                    <td :colspan="channelNames.length + 3" class="px-4 py-6 text-center text-gray-500">No stores yet.</td>
                </tr>
            </tbody>
            <tfoot v-if="allStores.stores.length > 1" class="border-t-2 border-gray-200 font-semibold">
                <tr>
                    <td class="px-4 py-2">All stores</td>
                    <td class="px-4 py-2 text-right font-mono">{{ formattedTotal(drawerTotal) }}</td>
                    <td v-for="name in channelNames" :key="name" class="px-4 py-2 text-right font-mono">{{ formattedTotal(columnTotal(name)) }}</td>
                    <td class="px-4 py-2 text-right font-mono" data-testid="all-stores-total">{{ formattedTotal(allStores.total) }}</td>
                </tr>
            </tfoot>
        </table>
        <p class="m-0 border-t border-gray-100 px-4 py-2 text-xs text-gray-500">
            Cash drawer is what should be in each drawer today; a channel is what its sales and transfers add up to.
        </p>
    </div>
</template>
