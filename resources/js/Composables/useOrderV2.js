// composables/useOrderV2.js
import { ref, computed, watch } from "vue";
import { useDebounceFn, watchDebounced } from "@vueuse/core";
import axios from "axios";

const orders = ref(JSON.parse(localStorage.getItem("orders")) || []);
const orderId = ref(localStorage.getItem("current_order") || null);
const isCreatingDraft = ref(false);
let draftPromise = null;

// ✅ Step 1: Create draft
const createDraft = async () => {
    if (orderId.value) return orderId.value;
    if (isCreatingDraft.value && draftPromise) return draftPromise;

    isCreatingDraft.value = true;
    draftPromise = axios
        .post("/api/sales/draft")
        .then(({ data }) => {
            orderId.value = data.order.id;
            localStorage.setItem("current_order", orderId.value);
            return orderId.value;
        })
        .finally(() => {
            isCreatingDraft.value = false;
            draftPromise = null;
        });

    return draftPromise;
};

// ✅ Step 2: Sync to DB (debounced once globally)
const syncDraft = useDebounceFn(async () => {
    if (!orderId.value) return;
    await axios.post(`/api/sales/${orderId.value}/sync`, {
        items: orders.value.map((item) => ({
            id: item.id,
            quantity: item.quantity,
            price: item.price,
            discount_id: item.discount_id || null,
            discount_amount: item.discount_amount || 0,
            subtotal: item.subtotal ?? item.quantity * item.price,
        })),
    });
}, 1000);

// ✅ Global watcher (runs only once)
watchDebounced(
    orders,
    () => {
        syncDraft();
        localStorage.setItem("orders", JSON.stringify(orders.value));
    },
    { debounce: 1000, deep: true }
);

// ✅ Step 3: Finalize order
const finalizeOrder = async () => {
    localStorage.removeItem("orders");
    localStorage.removeItem("current_order");
    orderId.value = null;
    orders.value = [];
};

// Add item
const handleAddOrder = async (product, selectedDiscount = null) => {
    if (!orderId.value) {
        await createDraft();
    }

    const index = orders.value.findIndex((p) => p.id === product.id);

    if (index >= 0) {
        // Increase quantity
        orders.value[index].quantity += 1;

        const lineSubtotal =
            orders.value[index].price * orders.value[index].quantity;

        let discountAmount = orders.value[index].discount_amount || 0;

        // ✅ Recalculate discount if exists or passed in
        const discount = selectedDiscount || orders.value[index];
        if (discount?.discount_type === "percentage") {
            const percentage = Math.min(Math.max(discount.discount, 0), 100);
            discountAmount = lineSubtotal * (percentage / 100);
        } else if (discount?.discount_type === "amount") {
            discountAmount = Math.min(
                Math.max(discount.discount, 0),
                lineSubtotal
            );
        }

        orders.value[index] = {
            ...orders.value[index],
            discount_id: discount?.id || null,
            discount_type: discount?.discount_type || null,
            discount: discount?.discount || 0,
            discount_amount: discountAmount,
            subtotal: lineSubtotal - discountAmount,
        };
    } else {
        // New order line
        orders.value.push({
            ...product,
            quantity: 1,
            discount_id: selectedDiscount?.id || null,
            discount_type: selectedDiscount?.type || null,
            discount: selectedDiscount?.value || 0,
            discount_amount: selectedDiscount
                ? selectedDiscount.type === "percentage"
                    ? product.price * (selectedDiscount.value / 100)
                    : Math.min(selectedDiscount.value, product.price)
                : 0,
            subtotal: selectedDiscount
                ? product.price -
                  (selectedDiscount.type === "percentage"
                      ? product.price * (selectedDiscount.value / 100)
                      : Math.min(selectedDiscount.value, product.price))
                : product.price,
        });
    }
};

// Subtract item
const handleSubtractOrder = (product, selectedDiscount = null) => {
    const index = orders.value.findIndex((p) => p.id === product.id);
    if (index >= 0 && orders.value[index].quantity > 1) {
        // Decrease quantity
        orders.value[index].quantity -= 1;

        const lineSubtotal =
            orders.value[index].price * orders.value[index].quantity;

        let discountAmount = 0;

        // ✅ Recalculate discount if exists or passed in
        const discount = selectedDiscount || orders.value[index];
        if (discount?.discount_type === "percentage") {
            const percentage = Math.min(Math.max(discount.discount, 0), 100);
            discountAmount = lineSubtotal * (percentage / 100);
        } else if (discount?.discount_type === "amount") {
            discountAmount = Math.min(
                Math.max(discount.discount, 0),
                lineSubtotal
            );
        }

        orders.value[index] = {
            ...orders.value[index],
            discount_id: discount?.id || null,
            discount_type: discount?.discount_type || null,
            discount: discount?.discount || 0,
            discount_amount: discountAmount,
            subtotal: lineSubtotal - discountAmount,
        };
    }
};

// Remove item
const removeOrder = (product) => {
    orders.value = orders.value.filter((p) => p.id !== product.id);
    if (orders.value.length == 0) {
        localStorage.removeItem("orders");
        localStorage.removeItem("current_order");
    }
};

// ✅ Total including discounts
const totalAmount = computed(() => {
    return orders.value.reduce((sum, i) => {
        if (i.subtotal !== undefined) {
            return sum + i.subtotal;
        }
        return sum + i.quantity * i.price;
    }, 0);
});

const formattedTotal = (total) => {
    return new Intl.NumberFormat("en-PH", {
        style: "currency",
        currency: "PHP",
    }).format(total);
};

export function useOrders() {
    return {
        orders,
        orderId,
        handleAddOrder,
        handleSubtractOrder,
        removeOrder,
        createDraft,
        finalizeOrder,
        totalAmount,
        formattedTotal,
    };
}
