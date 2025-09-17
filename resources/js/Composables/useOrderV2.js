import { ref, computed } from "vue";
import { useDebounceFn, watchDebounced } from "@vueuse/core";
import axios from "axios";

const orders = ref(JSON.parse(localStorage.getItem("orders")) || []);
const orderId = ref(localStorage.getItem("current_order") || null);
const isCreatingDraft = ref(false);
let draftPromise = null;

export function useOrders() {
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

  // ✅ Step 2: Sync to DB
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

  watchDebounced(
    orders,
    () => {
      syncDraft();
      localStorage.setItem("orders", JSON.stringify(orders.value));
    },
    { deep: true, debounce: 1000 }
  );

  // ✅ Step 3: Finalize
  const finalizeOrder = async () => {
    localStorage.removeItem("orders");
    localStorage.removeItem("current_order");
    orderId.value = null;
    orders.value = [];
  };

  // Add item
  const handleAddOrder = async (product) => {
    if (!orderId.value) {
      await createDraft();
    }

    const index = orders.value.findIndex((p) => p.id === product.id);
    if (index >= 0) {
      orders.value[index].quantity += 1;

      // recalc subtotal
      const lineSubtotal = orders.value[index].price * orders.value[index].quantity;
      orders.value[index].subtotal =
        lineSubtotal - (orders.value[index].discount_amount || 0);
    } else {
      orders.value.push({
        ...product,
        quantity: 1,
        discount_id: null,
        discount_amount: 0,
        subtotal: product.price,
      });
    }
  };

  // Subtract item
  const handleSubtractOrder = (product) => {
    const index = orders.value.findIndex((p) => p.id === product.id);
    if (index >= 0 && orders.value[index].quantity > 1) {
      orders.value[index].quantity -= 1;

      // recalc subtotal
      const lineSubtotal = orders.value[index].price * orders.value[index].quantity;
      orders.value[index].subtotal =
        lineSubtotal - (orders.value[index].discount_amount || 0);
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
