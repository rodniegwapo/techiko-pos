import { ref, watch, computed } from "vue";
import { useGlobalVariables } from "./useGlobalVariable";

export function useOrders() {
    const { orders } = useGlobalVariables();

    // Load saved orders on startup
    const saved = localStorage.getItem("orders");
    if (saved) {
        orders.value = JSON.parse(saved);
    }

    // Persist orders to localStorage whenever they change
    watch(
        orders,
        (newOrders) => {
            localStorage.setItem("orders", JSON.stringify(newOrders));
        },
        { deep: true }
    );

    const handleAddOrder = (product) => {
        const findIndex = orders.value.findIndex(
            (item) => item.id == product.id
        );

        if (findIndex >= 0) {
            const find = orders.value[findIndex];
            orders.value.splice(findIndex, 1, {
                ...find,
                quantity: find.quantity + 1,
            });
        } else {
            orders.value.push({ quantity: 1, ...product });
        }
    };

    const handleSubtractOrder = (product) => {
        const findIndex = orders.value.findIndex(
            (item) => item.id == product.id
        );

        if (findIndex >= 0) {
            const find = orders.value[findIndex];
            orders.value.splice(findIndex, 1, {
                ...find,
                quantity: find.quantity > 1 ? find.quantity - 1 : 1,
            });
        }
    };

    // Remove item completely
    const removeOrder = (productId) => {
        orders.value = orders.value.filter((item) => item.id !== productId);
    };

    // Computed total amount
    const totalAmount = computed(() => {
        return orders.value.reduce((sum, item) => {
            return sum + item.quantity * (item.price ?? 0);
        }, 0);
    });

    // ✅ Formatted total with commas (Philippine Peso example)
    const formattedTotal = computed(() => {
        return new Intl.NumberFormat("en-PH", {
            style: "currency",
            currency: "PHP",
        }).format(totalAmount.value);
    });

    const clearOrders = () => {
        orders.value = [];
        localStorage.removeItem("orders");
    };

    return {
        orders,
        handleAddOrder,
        handleSubtractOrder,
        removeOrder,
        clearOrders,
        totalAmount,
        formattedTotal
    };
}
