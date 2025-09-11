import { ref, watch, computed } from "vue";
import { useGlobalVariables } from "./useGlobalVariable";
import { v4 as uuidv4 } from "uuid";
import { useAuth } from "./useAuth";

export function useOrders() {
    const { user } = useAuth();
    const { orders } = useGlobalVariables();

    const orderId = ref(localStorage.getItem("current_order") || uuidv4());
    localStorage.setItem("current_order", orderId.value);

    const logs = ref(JSON.parse(localStorage.getItem("order_logs")) || []);

    // Load saved orders
    const savedOrders = localStorage.getItem("orders");
    if (savedOrders) orders.value = JSON.parse(savedOrders);

    // Persist orders on change
    watch(orders, (newOrders) => persist("orders", newOrders), { deep: true });

    /** -----------------
   * Helpers
   ------------------*/
    const persist = (key, data) =>
        localStorage.setItem(key, JSON.stringify(data));

    const addLog = ({ productId, action, oldQty = null, newQty = null }) => {
        logs.value.push({
            order_id: orderId.value,
            product_id: productId,
            action,
            old_quantity: oldQty,
            new_quantity: newQty,
            cashier_id: user.value.id,
            timestamp: new Date().toISOString(),
        });
        persist("order_logs", logs.value);
    };

    const updateOrder = (product, updater, action) => {
        const index = orders.value.findIndex((item) => item.id === product.id);
        if (index < 0) return false;

        const current = orders.value[index];
        const updated = updater(current);

        orders.value.splice(index, 1, updated);
        addLog({
            productId: product.id,
            action,
            oldQty: current.quantity,
            newQty: updated.quantity,
        });
        return true;
    };

    /** -----------------
   * Actions
   ------------------*/
    const handleAddOrder = (product) => {
        const updated = updateOrder(
            product,
            (item) => ({ ...item, quantity: item.quantity + 1 }),
            "update_quantity"
        );

        if (!updated) {
            orders.value.push({ ...product, quantity: 1 });
            addLog({
                productId: product.id,
                action: "add_product",
                newQty: 1,
            });
        }
    };

    const handleSubtractOrder = (product) => {
        updateOrder(
            product,
            (item) => ({ ...item, quantity: Math.max(item.quantity - 1, 1) }),
            "update_quantity"
        );
    };

    const removeOrder = (product) => {
        orders.value = orders.value.filter((item) => item.id !== product.id);
        addLog({
            productId: product.id,
            action: "delete_item",
            oldQty: product.quantity,
        });
    };

    const clearOrders = () => {
        orders.value = [];
        localStorage.removeItem("orders");
    };

    /** -----------------
   * Computed
   ------------------*/
    const totalAmount = computed(() =>
        orders.value.reduce(
            (sum, item) => sum + item.quantity * (item.price ?? 0),
            0
        )
    );

    const formattedTotal = computed(() =>
        new Intl.NumberFormat("en-PH", {
            style: "currency",
            currency: "PHP",
        }).format(totalAmount.value)
    );

    return {
        orders,
        handleAddOrder,
        handleSubtractOrder,
        removeOrder,
        clearOrders,
        totalAmount,
        formattedTotal,
    };
}
