// composables/useOrderV2.js - Database-driven version
import { ref, computed } from "vue";
import axios from "axios";
import { usePage } from "@inertiajs/vue3";
import { useTransactionTimeout } from "./useTransactionTimeout";
import { useOfflineMode } from "./useOfflineMode";
import { useHoldTransaction } from "./useHoldTransaction";
import { useDomainRoutes } from "./useDomainRoutes";

// Initialize domain routes at module level
const { getRoute } = useDomainRoutes();

// No longer needed - backend handles domain filtering

// Database-driven state - no localStorage dependency
const orders = ref([]);
const orderId = ref(null);
const orderDiscountAmount = ref(0);
const orderDiscountId = ref('');
const isCreatingDraft = ref(false);
const isLoadingCart = ref(false);
let draftPromise = null;

/** 🔹 Utility: transform database items to frontend format */
function transformCartItems(items) {
    if (!items || !Array.isArray(items)) {
        console.warn('transformCartItems received invalid items:', items);
        return [];
    }
    
    return items.map(item => {
        console.log('Transforming item:', item);
        console.log('Product data:', item.product);
        console.log('Product name:', item.product?.name);
        console.log('Product ID:', item.product_id);
        
        // Try different ways to get the product name
        let productName = 'Unknown Product';
        
        if (item.product && item.product.name) {
            productName = item.product.name;
        } else if (item.product_name) {
            productName = item.product_name;
        } else if (item.name) {
            productName = item.name;
        }
        
        console.log('Final product name:', productName);
        
        return {
            id: item.product_id,
            name: productName,
            price: item.unit_price,
            quantity: item.quantity,
            subtotal: item.unit_price * item.quantity,
            discount_id: item.discount_id,
            discount_type: item.discount_type,
            discount: item.discount_value,
            discount_amount: item.discount_amount
        };
    });
}

/** 🔹 Utility: calculate discount + subtotal */
function applyDiscountToLine(product, discount) {
    const price = parseFloat(product.price) || 0;
    const quantity = parseFloat(product.quantity) || 0;
    const lineSubtotal = price * quantity;

    if (!discount) {
        return {
            ...product,
            discount_id: null,
            discount_type: null,
            discount: 0,
            discount_amount: 0,
            subtotal: lineSubtotal,
        };
    }

    let discountAmount = 0;
    const discountValue = parseFloat(discount.value) || 0;
    
    if (discount.type === "percentage") {
        const percentage = Math.min(Math.max(discountValue, 0), 100);
        discountAmount = lineSubtotal * (percentage / 100);
    } else if (discount.type === "amount") {
        // 🔹 Multiply by quantity so discount applies per item
        const totalDiscount = discountValue * quantity;
        discountAmount = Math.min(Math.max(totalDiscount, 0), lineSubtotal);
    }

    // Ensure discountAmount is a valid number
    discountAmount = isNaN(discountAmount) ? 0 : discountAmount;

    return {
        ...product,
        discount_id: discount.id,
        discount_type: discount.type,
        discount: discountValue,
        discount_amount: discountAmount,
        subtotal: lineSubtotal - discountAmount,
    };
}

/** 🔹 Load current user's pending sale from database */
const loadCurrentPendingSale = async () => {
    isLoadingCart.value = true;
    
    // Don't use localStorage at all - let backend handle user lookup
    try {
        // Get current user ID from page props
        const page = usePage();
        const userId = page.props.auth.user.data.id;
        
        const route = getRoute("users.sales.current-pending", { user: userId });
        console.log('Loading user pending sale, Route:', route);
        const response = await axios.get(route);
        console.log('User pending sale API response:', response.data);
        
        if (response.data.sale) {
            const { sale, items, discounts, totals } = response.data;
            
            // Set the orderId from the found sale
            orderId.value = sale.id;
            // Don't store in localStorage - let backend handle user lookup
            
            // Transform database response to match frontend expectations
            orders.value = transformCartItems(items);
            orderDiscountAmount.value = totals?.discount_amount || 0;
            orderDiscountId.value = discounts?.map(d => d.discount_id).join(',') || '';
            
            console.log('User pending sale loaded:', { sale, items, totals });
            console.log('Updated orders array from loadCurrentPendingSale:', orders.value);
        } else {
            console.log('No pending sale found for user - this is normal for new customers');
            // Don't reset everything - just don't set orderId
            // The addItemToUserCart will handle creating a new sale
        }
    } catch (error) {
        console.error('Failed to load user pending sale:', error);
        // Don't reset everything on error - just log it
    } finally {
        isLoadingCart.value = false;
    }
};

/** 🔹 Load cart state from database */
const loadCartState = async (saleId) => {
    if (!saleId) return;
    
    isLoadingCart.value = true;
    try {
        const route = getRoute("sales.cart.state", { sale: saleId });
        console.log('Loading cart state for sale:', saleId, 'Route:', route);
        const response = await axios.get(route);
        console.log('Cart state API response:', response.data);
        const { items, discounts, totals } = response.data;
        
        console.log('Extracted from response:', { items, discounts, totals });
        
        // Transform database response to match frontend expectations
        orders.value = transformCartItems(items);
        orderDiscountAmount.value = totals?.discount_amount || 0;
        orderDiscountId.value = discounts?.map(d => d.discount_id).join(',') || '';
        
        console.log('Cart state loaded from database:', { items, totals });
        console.log('Updated orders array from loadCartState:', orders.value);
    } catch (error) {
        console.error('Failed to load cart state:', error);
        // Reset to empty state on error
        orders.value = [];
        orderDiscountAmount.value = 0;
        orderDiscountId.value = '';
    } finally {
        isLoadingCart.value = false;
    }
};

/** 🔹 Step 1: Create draft (now handled by user-specific routes) */
const createDraft = async () => {
    console.log('createDraft called - now handled by user-specific routes');
    // This function is no longer needed as user-specific routes handle sale creation automatically
    return orderId.value;
};

// Removed syncDraft - now using direct database operations

/** 🔹 Step 3: Finalize */
const finalizeOrder = () => {
    // Clear local state and localStorage
    orderId.value = null;
    orders.value = [];
    orderDiscountAmount.value = 0;
    orderDiscountId.value = '';
    localStorage.removeItem("current_order");
};

/** 🔹 Add item - Database-driven */
const handleAddOrder = async (product, discount = null) => {
    try {
        // Get current user ID from page props
        const page = usePage();
        const userId = page.props.auth.user.data.id;
        
        console.log('Adding product to user cart:', { userId, product_id: product.id, quantity: 1 });
        console.log('User ID type:', typeof userId, 'User ID value:', userId);
        
        const route = getRoute("users.sales.cart.add", { user: userId });
        console.log('Generated route URL:', route);
        console.log('Route parameters:', { user: userId, routeName: "users.sales.cart.add" });
        
        // Temporary debugging: Check if the route contains the correct user ID
        if (route.includes('default-store')) {
            console.error('ERROR: Route still contains "default-store" instead of user ID:', userId);
            console.log('This indicates a route generation issue');
        }
        
        const response = await axios.post(route, {
            product_id: product.id,
            quantity: 1,
            unit_price: product.price
        });
        
        // Update local state with response
        orders.value = transformCartItems(response.data.items || []);
        orderDiscountAmount.value = response.data.totals.discount_amount || 0;
        orderDiscountId.value = response.data.discounts.map(d => d.discount_id).join(',');
        
        // Set orderId from the sale
        if (response.data.sale) {
            orderId.value = response.data.sale.id;
        }
        
        console.log('Item added to user cart:', response.data);
        console.log('Raw items from API:', response.data.items);
        console.log('First raw item:', response.data.items?.[0]);
        console.log('Updated orders array:', orders.value);
        console.log('First item structure:', orders.value[0]);
    } catch (error) {
        console.error('Failed to add item to user cart:', error);
        throw error;
    }
};

/** 🔹 Subtract item - Database-driven */
const handleSubtractOrder = async (product, discount = null) => {
    try {
        // Get current user ID from page props
        const page = usePage();
        const userId = page.props.auth.user.data.id;
        
        const response = await axios.patch(getRoute("users.sales.cart.update-quantity", { user: userId }), {
            product_id: product.id,
            quantity: Math.max(0, (product.quantity || 1) - 1)
        });

        // Transform database response to match frontend expectations
        orders.value = transformCartItems(response.data.items || []);
        orderDiscountAmount.value = response.data.totals.discount_amount || 0;
        orderDiscountId.value = response.data.discounts.map(d => d.discount_id).join(',');
        console.log('Item quantity updated:', response.data);
    } catch (error) {
        console.error('Failed to update item quantity:', error);
        throw error;
    }
};

/** 🔹 Remove item - Database-driven */
const removeOrder = async (product) => {
    try {
        // Get current user ID from page props
        const page = usePage();
        const userId = page.props.auth.user.data.id;
        
        const response = await axios.delete(getRoute("users.sales.cart.remove", { user: userId }), {
            data: { product_id: product.id }
        });

        // Transform database response to match frontend expectations
        orders.value = transformCartItems(response.data.items || []);
        orderDiscountAmount.value = response.data.totals.discount_amount || 0;
        orderDiscountId.value = response.data.discounts.map(d => d.discount_id).join(',');
        
        // If cart is empty, finalize the order
        if (orders.value.length === 0) {
            finalizeOrder();
        }
        
        console.log('Item removed from cart:', response.data);
    } catch (error) {
        console.error('Failed to remove item from cart:', error);
        throw error;
    }
};

/** Totals */
const totalAmount = computed(() =>
    orders.value.reduce(
        (sum, i) => {
            const quantity = parseFloat(i.quantity) || 0;
            const price = parseFloat(i.price) || 0;
            const subtotal = parseFloat(i.subtotal);
            
            // Use subtotal if it's a valid number, otherwise calculate from price * quantity
            const itemTotal = !isNaN(subtotal) ? subtotal : quantity * price;
            
            return sum + itemTotal;
        },
        0
    )
);

const formattedTotal = (total) =>
    new Intl.NumberFormat("en-PH", {
        style: "currency",
        currency: "PHP",
    }).format(total);

export function useOrders() {
    // Initialize enhanced features
    const timeout = useTransactionTimeout();
    const offline = useOfflineMode();
    const hold = useHoldTransaction();
    
    // Set up timeout callback
    timeout.setClearTransactionCallback(() => {
        finalizeOrder();
    });
    
    // Enhanced finalize with timeout management
    const enhancedFinalizeOrder = () => {
        timeout.stopTimeout();
        finalizeOrder();
    };
    
    // Enhanced add order with activity tracking
    const enhancedHandleAddOrder = async (product, discount = null) => {
        await handleAddOrder(product, discount);
        timeout.updateActivity();
        if (orders.value.length === 1) {
            timeout.startTimeout(); // Start timeout on first item
        }
    };
    
    // Enhanced subtract order with activity tracking
    const enhancedHandleSubtractOrder = async (product, discount = null) => {
        await handleSubtractOrder(product, discount);
        timeout.updateActivity();
    };
    
    // Enhanced remove order with activity tracking
    const enhancedRemoveOrder = async (product) => {
        await removeOrder(product);
        timeout.updateActivity();
        if (orders.value.length === 0) {
            timeout.stopTimeout();
        }
    };
    
    // Hold current transaction
    const holdCurrentTransaction = () => {
        const transactionData = {
            orderId: orderId.value,
            orders: orders.value,
            orderDiscountAmount: orderDiscountAmount.value,
            orderDiscountId: orderDiscountId.value,
        };
        
        const heldId = hold.holdTransaction(transactionData);
        if (heldId) {
            enhancedFinalizeOrder();
        }
        return heldId;
    };
    
    // Recall held transaction
    const recallHeldTransaction = async (transactionId) => {
        const transaction = hold.recallTransaction(transactionId);
        if (transaction) {
            // Clear current transaction if any
            if (orders.value.length > 0) {
                enhancedFinalizeOrder();
            }
            
            // Restore transaction
            orderId.value = transaction.orderId;
            
            // Load cart state from database
            await loadCartState(orderId.value);
            
            // Start timeout for recalled transaction
            timeout.startTimeout();
        }
        return transaction;
    };

    return {
        // Original exports
        orders,
        orderId,
        orderDiscountAmount,
        orderDiscountId,
        handleAddOrder: enhancedHandleAddOrder,
        handleSubtractOrder: enhancedHandleSubtractOrder,
        removeOrder: enhancedRemoveOrder,
        createDraft,
        finalizeOrder: enhancedFinalizeOrder,
        totalAmount,
        formattedTotal,
        applyDiscountToLine,
        
        // Database-driven functions
        loadCartState,
        loadCurrentPendingSale,
        isLoadingCart,
        
        // Enhanced features
        timeout,
        offline,
        hold,
        holdCurrentTransaction,
        recallHeldTransaction,
        
        // Convenience exports
        isOffline: offline.isOffline,
        offlineQueue: offline.offlineQueue,
        heldTransactions: hold.heldTransactions,
        lastActivity: timeout.lastActivity,
    };
}
