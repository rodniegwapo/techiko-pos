import { ref, computed } from "vue";
import { useAuth } from "./useAuth";
import { useDebounceFn, watchDebounced } from "@vueuse/core";
import axios from "axios";

export function useOrders() {
    const { user } = useAuth();

    // local state
    const orders = ref(JSON.parse(localStorage.getItem("orders")) || []);

    const orderId = ref(localStorage.getItem("current_order") || null);

    // ✅ Step 1: Create a draft in DB when new order starts
    const createDraft = async () => {
        if (orderId.value) return; // already have one
        const { data } = await axios.post("/api/sales/draft");
        orderId.value = data.order.id;
        localStorage.setItem("current_order", orderId.value);
    };

    // ✅ Step 2: Sync to DB (debounced)
    const syncDraft = useDebounceFn(async () => {
        if (!orderId.value) return;

        await axios.post(`/api/sales/${orderId.value}/sync`, {
            items: orders.value,
        });
    }, 1000); // sync after 1s idle

    // watch local orders → sync to DB
    watchDebounced(
        orders,
        () => {
            syncDraft();
            localStorage.setItem("orders", JSON.stringify(orders.value));
        },
        { deep: true, debounce: 1000 }
    );

    // ✅ Step 3: Finalize order
    const finalizeOrder = async () => {
        if (!orderId.value) return;
        await axios.post(`/api/sales/${orderId.value}/finalize`);
        localStorage.removeItem("orders");
        localStorage.removeItem("current_order");
        orderId.value = null;
        orders.value = [];
    };

    // Actions
    const handleAddOrder = async (product) => {
        if (!orderId.value) {
            await createDraft();
        }

        const index = orders.value.findIndex((p) => p.id === product.id);
        if (index >= 0) {
            orders.value[index].quantity += 1;
        } else {
            orders.value.push({ ...product, quantity: 1 });
        }
    };

    const handleSubtractOrder = (product) => {
        const index = orders.value.findIndex((p) => p.id === product.id);
        if (index >= 0 && orders.value[index].quantity > 1) {
            orders.value[index].quantity -= 1;
        }
    };

    const removeOrder = (product) => {
        orders.value = orders.value.filter((p) => p.id !== product.id);
    };

    // Computed
    const totalAmount = computed(() =>
        orders.value.reduce((sum, i) => sum + i.quantity * i.price, 0)
    );

    return {
        orders,
        orderId,
        handleAddOrder,
        handleSubtractOrder,
        removeOrder,
        createDraft,
        finalizeOrder,
        totalAmount,
    };
}
