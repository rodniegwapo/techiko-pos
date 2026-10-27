import { ref, computed, onMounted, onBeforeUnmount } from "vue";
import { usePage } from "@inertiajs/vue3";

export function useCustomerOrder() {
    const page = usePage();
    const order = ref(null);
    const orderItems = ref([]);
    const customer = ref(null);
    const lastUpdated = ref(new Date().toLocaleTimeString());
    const orderId = ref(null);
    const loading = ref(true);
    const paymentSuccessMessage = ref(null);
    // The store this display belongs to: the secret in its link (?display=…), given on the Sales screen.
    const displayToken = new URLSearchParams(window.location.search).get("display") || "";
    const channelName = `customer-display.${displayToken}`;
    const missingDisplay = ref(!displayToken);

    // Computed properties - Use actual sale data from backend
    const subtotal = computed(() => {
        const amount = order.value?.total_amount || order.value?.totals?.subtotal || 0;
        return parseFloat(amount) || 0;
    });

    const itemDiscountAmount = computed(() => {
        return orderItems.value.reduce((sum, item) => {
            return sum + (parseFloat(item.discount) || 0);
        }, 0);
    });

    const orderDiscountAmount = computed(() => {
        const totalDiscounts =
            parseFloat(order.value?.discount_amount) ||
            parseFloat(order.value?.totals?.discount_amount) ||
            0;
        return Math.max(0, totalDiscounts - itemDiscountAmount.value);
    });

    const discountAmount = computed(() => {
        return itemDiscountAmount.value + orderDiscountAmount.value;
    });

    const taxAmount = computed(() => {
        const amount = order.value?.tax_amount || order.value?.totals?.tax_amount || 0;
        return parseFloat(amount) || 0;
    });

    const totalAmount = computed(() => {
        const amount = order.value?.grand_total || order.value?.totals?.grand_total || 0;
        return parseFloat(amount) || 0;
    });

    // Methods
    const formatCurrency = (amount) => {
        return new Intl.NumberFormat("en-PH", {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(amount);
    };

    const handleOrderUpdate = (data) => {
        console.log(`📦 Order update received (${data.event_type || 'unknown'}):`, data);
        order.value = data.order;
        orderItems.value = data.order.saleItems || data.order.sale_items || data.order.items || [];
        customer.value = data.order.customer;
        orderId.value = data.order.id;
        lastUpdated.value = new Date().toLocaleTimeString();
        loading.value = false;
    };

    const handlePaymentCompleted = (data) => {
        console.log(`💳 Payment completed for order ${data.sale_id}:`, data);
        
        // Show success message
        paymentSuccessMessage.value = data.message;
        
        // Clear the order to restart the view
        order.value = null;
        orderItems.value = [];
        customer.value = null;
        orderId.value = null;
        lastUpdated.value = new Date().toLocaleTimeString();
        loading.value = false;
        
        // Clear success message after 5 seconds
        setTimeout(() => {
            paymentSuccessMessage.value = null;
        }, 5000);
    };

    const fetchInitialOrder = async () => {
        try {
            if (!displayToken) {
                loading.value = false;
                return;
            }
            const response = await fetch(`/api/orders/recent-pending?display=${encodeURIComponent(displayToken)}`);
            if (response.status === 404) missingDisplay.value = true;
            if (response.ok) {
                const data = await response.json();
                if (data.order) {
                    handleOrderUpdate(data);
                } else {
                    loading.value = false;
                }
            } else {
                loading.value = false;
            }
        } catch (error) {
            console.error("Error fetching initial order:", error);
            loading.value = false;
        }
    };

    // Lifecycle
    onMounted(() => {
        fetchInitialOrder();

        if (!displayToken) return;
        if (!window.Echo) {
            console.error("Echo is not initialized!");
            return;
        }

        console.log("Setting up Echo listeners for customer order view");

        // Listen to order updates
        window.Echo.channel(channelName).listen(".OrderUpdated", (event) => {
            console.log("🔄 OrderUpdated event received:", event);
            handleOrderUpdate(event);
        });

        // Listen to customer updates
        window.Echo.channel(channelName).listen(".CustomerUpdated", (event) => {
            console.log("👤 CustomerUpdated event received:", event);
            handleOrderUpdate(event);
        });

        // Listen to payment completion
        window.Echo.channel(channelName).listen(".PaymentCompleted", (event) => {
            console.log("💳 PaymentCompleted event received:", event);
            handlePaymentCompleted(event);
        });
    });

    onBeforeUnmount(() => {
        if (window.Echo) {
            window.Echo.leaveChannel(channelName);
        }
    });

    return {
        // State
        order,
        missingDisplay,
        orderItems,
        customer,
        lastUpdated,
        orderId,
        loading,
        paymentSuccessMessage,

        // Computed
        subtotal,
        itemDiscountAmount,
        orderDiscountAmount,
        discountAmount,
        taxAmount,
        totalAmount,

        // Methods
        formatCurrency,
        handleOrderUpdate,
        handlePaymentCompleted,
        fetchInitialOrder,
    };
}
