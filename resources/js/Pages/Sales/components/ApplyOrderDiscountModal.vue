<script setup>
import PrimaryButton from "@/Components/PrimaryButton.vue";
import { computed, ref, toRefs, watch } from "vue";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import axios from "axios";
import dayjs from "dayjs";
import { notification } from "ant-design-vue";

const emit = defineEmits(["close", "discount-applied"]);
const { formData, errors } = useGlobalVariables();
const { getRoute } = useDomainRoutes();

// Props for direct data passing
const props = defineProps({
    openModal: Boolean,
    product: { type: Object, default: () => ({}) },
    orderId: { type: [String, Number], default: null },
    orders: { type: Array, default: () => [] },
    currentSale: { type: Object, default: () => null },
    orderDiscountAmount: { type: Number, default: 0 },
    orderDiscountId: { type: String, default: "" },
    discountOptions: { type: Object, default: () => ({}) },
});

const {
    openModal,
    product,
    orderId,
    orders,
    currentSale,
    orderDiscountAmount,
    orderDiscountId,
    discountOptions,
} = toRefs(props);

// Use consolidated discount data
const discounts = computed(
    () => discountOptions.value.promotional_discount_options || []
);
const mandatoryDiscounts = computed(
    () => discountOptions.value.mandatory_discount_options || []
);

// Watch for modal opening to clear previous errors and load current discounts
watch(openModal, async (newValue) => {
    if (newValue) {
        console.log("Modal opened, orderId:", orderId.value);
        console.log("Current sale data:", currentSale.value);
        console.log(
            "Available promotional discounts:",
            availableDiscounts.value
        );
        console.log(
            "Available mandatory discounts:",
            availableMandatoryDiscounts.value
        );

        errors.value = {};

        // Use sale_discounts from the currentSale prop directly
        if (currentSale.value && currentSale.value.sale_discounts) {
            console.log(
                "Found sale_discounts:",
                currentSale.value.sale_discounts
            );

            // Extract discount IDs from sale_discounts array
            const promotionalIds = currentSale.value.sale_discounts
                .filter((discount) => discount.discount_type === "regular")
                .map((discount) => discount.discount_id);

            const mandatoryIds = currentSale.value.sale_discounts
                .filter((discount) => discount.discount_type === "mandatory")
                .map((discount) => discount.discount_id);

            console.log("Extracted discount IDs:", {
                promotional: promotionalIds,
                mandatory: mandatoryIds,
            });

            // Pre-populate form fields
            selectedPromotionalDiscounts.value = promotionalIds;
            selectedMandatoryDiscount.value =
                mandatoryIds.length > 0 ? mandatoryIds[0] : null;

            console.log("Form populated with:", {
                promotional: selectedPromotionalDiscounts.value,
                mandatory: selectedMandatoryDiscount.value,
            });
        } else {
            console.log(
                "No currentSale or sale_discounts found, clearing form"
            );
            selectedPromotionalDiscounts.value = [];
            selectedMandatoryDiscount.value = null;
        }
    }
});

const loading = ref(false);

/** 🔹 Apply discount */

const handleSave = async () => {
    try {
        // Discounts are optional - no validation required

        // Ensure we have an orderId (should be set by user-specific routes)
        if (!orderId.value) {
            throw new Error(
                "No active order found. Please add items to cart first."
            );
        }

        // Get selected discount IDs directly
        const selectedRegularIds = selectedPromotionalDiscounts.value.filter(
            (id) => id !== null && id !== undefined && id !== "" && !isNaN(id)
        );

        const selectedMandatoryIds = selectedMandatoryDiscount.value
            ? [selectedMandatoryDiscount.value].filter(
                  (id) =>
                      id !== null && id !== undefined && id !== "" && !isNaN(id)
              )
            : [];

        // Debug logging
        console.log(
            "Selected promotional discounts:",
            selectedPromotionalDiscounts.value
        );
        console.log(
            "Selected mandatory discount:",
            selectedMandatoryDiscount.value
        );
        console.log("Selected regular IDs:", selectedRegularIds);
        console.log("Selected mandatory IDs:", selectedMandatoryIds);

        const payload = {
            regular_discount_ids: selectedRegularIds,
            mandatory_discount_ids: selectedMandatoryIds,
        };
        loading.value = true;
        // Use composable for proper domain detection
        const { currentDomain, getCurrentDomainFromUrl } = useDomainRoutes();
        const domain =
            currentDomain.value?.name_slug || getCurrentDomainFromUrl();
        const route = `/domains/${domain}/sales/${orderId.value}/discounts`;
        console.log("Discount update route:", route);

        const { data: sale } = await axios.patch(route, payload);

        console.log("Discount API response:", sale);
        console.log("Sale object structure:", {
            sale: sale?.sale,
            discount_amount: sale?.discount_amount,
            sale_discounts: sale?.sale_discounts,
            discounts: sale?.discounts,
        });
        console.log("Sale discount amount:", sale?.sale?.discount_amount);
        console.log("Sale discounts array:", sale?.discounts);

        // Handle different response structures - backend returns 'discounts'
        const sale_discounts = sale?.discounts || sale?.sale_discounts || [];

        if (!Array.isArray(sale_discounts)) {
            console.error("sale_discounts is not an array:", sale_discounts);
            throw new Error("Invalid response structure from discount API");
        }

        // Update local state with database response
        const regularDiscounts = sale_discounts.filter(
            (item) => item.discount_type === "regular"
        );
        const mandatoryDiscounts = sale_discounts.filter(
            (item) => item.discount_type === "mandatory"
        );

        // Update local state with correct discount amount
        const discountAmount =
            sale?.discount_amount ?? sale?.sale?.discount_amount ?? 0;
        orderDiscountAmount.value = discountAmount;

        // Update discount IDs
        const allDiscountIds = sale_discounts
            .map((item) => item.discount_id)
            .join(",");
        orderDiscountId.value = allDiscountIds;

        console.log(
            "Discount applied - Amount:",
            discountAmount,
            "IDs:",
            allDiscountIds
        );

        // Clear form fields
        selectedPromotionalDiscounts.value = [];
        selectedMandatoryDiscount.value = null;

        // Clear any previous errors on success
        errors.value = {};

        // Show appropriate success message based on whether discounts were applied
        const hasDiscounts = selectedRegularIds.length > 0 || selectedMandatoryIds.length > 0;
        notification["success"]({
            message: "Success",
            description: hasDiscounts 
                ? "Discount(s) applied successfully" 
                : "Order updated successfully",
        });

        // Emit event to parent to refresh data
        emit("discount-applied");

        emit("close");
    } catch (error) {
        console.error("Error applying discount:", error);

        // Handle validation errors from backend
        if (error.response?.status === 422) {
            errors.value = error.response.data.errors || {};
        } else if (error.response?.status === 400) {
            // Handle InvalidArgumentException from Sale model
            const errorMessage =
                error.response.data.message || "Invalid discount application";

            notification["error"]({
                message: "Discount Error",
                description: errorMessage,
                duration: 5,
            });

            // Set form-level error
            errors.value = {
                orderDiscount: [errorMessage],
            };
        } else {
            // Handle other errors
            notification["error"]({
                message: "Error",
                description: "Failed to apply discount. Please try again.",
            });
        }
    } finally {
        loading.value = false;
    }
};

// Direct form validation is now handled in handleSave

/**  Clear discount */
const discountLoading = ref(false);
const handleClearDiscount = async () => {
    try {
        discountLoading.value = true;

        // backend remove - use composable for proper domain detection
        const { currentDomain, getCurrentDomainFromUrl } = useDomainRoutes();
        const domain =
            currentDomain.value?.name_slug || getCurrentDomainFromUrl();
        const route = `/domains/${domain}/sales/${orderId.value}/discounts`;
        console.log("Discount remove route:", route);

        await axios.delete(route);

        // Update local state instead of localStorage
        orderDiscountAmount.value = 0;
        orderDiscountId.value = "";

        // Clear any previous errors on success
        errors.value = {};

        notification["success"]({
            message: "Success",
            description: "Discount(s) cleared successfully",
        });

        // Emit event to parent to refresh data
        emit("discount-applied");

        emit("close");
    } catch (error) {
        console.error("Error clearing discount:", error);

        notification["error"]({
            message: "Error",
            description:
                error.response?.data?.message ||
                "Failed to clear discount. Please try again.",
        });
    } finally {
        discountLoading.value = false;
    }
};

const availableDiscounts = computed(() => {
    return discounts.value
        .filter(
            (item) =>
                item.scope == "order" &&
                item.is_active &&
                (!item.start_date ||
                    dayjs(item.start_date).isBefore(dayjs())) &&
                (!item.end_date || dayjs(item.end_date).isAfter(dayjs()))
        )
        .map((item) => {
            // Fix display logic to show correct format
            let displayValue;
            if (item.type === "percentage") {
                // Parse as float to remove unnecessary decimals, then add %
                displayValue = parseFloat(item.value) + "%";
            } else if (item.type === "amount") {
                displayValue = "₱" + parseFloat(item.value).toFixed(2);
            } else {
                // Handle legacy 'percent' type
                displayValue = parseFloat(item.value) + "%";
            }
            
            return {
                label: `${item.name} (${displayValue})`,
                value: item.id,
                amount: item.value,
                type: item.type,
                min_order_amount: item.min_order_amount,
            };
        });
});

const availableMandatoryDiscounts = computed(() => {
    return mandatoryDiscounts.value.map((item) => {
        // Fix display logic to show correct format
        let displayValue;
        if (item.type === "percentage") {
            // Parse as float to remove unnecessary decimals, then add %
            displayValue = parseFloat(item.value) + "%";
        } else if (item.type === "amount") {
            displayValue = "₱" + parseFloat(item.value).toFixed(2);
        } else {
            // Handle legacy 'percent' type
            displayValue = parseFloat(item.value) + "%";
        }
        
        return {
            label: `${item.name} (${displayValue})`,
            value: item.id,
            amount: item.value,
            type: item.type,
            isMandatory: true,
        };
    });
});

// Helper function to get the best mandatory discount
const getBestMandatoryDiscount = computed(() => {
    if (availableMandatoryDiscounts.value.length === 0) return null;

    // For now, return the one with highest percentage/amount
    return availableMandatoryDiscounts.value.reduce((best, current) => {
        if (current.type === "percentage" && best.type === "percentage") {
            return current.amount > best.amount ? current : best;
        } else if (current.type === "amount" && best.type === "amount") {
            return current.amount > best.amount ? current : best;
        } else if (current.type === "percentage" && best.type === "amount") {
            // Assume percentage is usually better for larger orders
            return current;
        }
        return best;
    });
});

// Check if multiple mandatory discounts are available (PWD + Senior scenario)
const hasMultipleMandatoryOptions = computed(() => {
    return availableMandatoryDiscounts.value.length > 1;
});

// Direct form data for easier debugging
const selectedPromotionalDiscounts = ref([]);
const selectedMandatoryDiscount = ref(null);
</script>
<template>
    <a-modal
        v-model:visible="openModal"
        :title="`Apply Order Discount`"
        @cancel="$emit('close')"
        width="500px"
        :maskClosable="false"
    >
        <div
            v-if="
                availableDiscounts.length === 0 &&
                availableMandatoryDiscounts.length === 0
            "
            class="mb-4 p-3 bg-yellow-50 border border-yellow-200 rounded"
        >
            <p class="text-yellow-800 text-sm">
                <strong>No discounts available:</strong> There are currently no
                active discounts that can be applied.
            </p>
        </div>

        <!-- Enhanced Info Section -->
        <div class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded">
            <details class="text-sm text-blue-800">
                <summary class="cursor-pointer font-medium text-blue-700">
                    📋 Discount Types
                </summary>
                <div class="mt-2 space-y-1">
                    <p>
                        <strong>Promotional:</strong> Regular sales discounts
                        (can select multiple)
                    </p>
                    <p>
                        <strong>Mandatory:</strong> PWD, Senior Citizen, Student
                        discounts
                    </p>
                    <p class="text-xs italic text-blue-600">
                        ⚠️ Regulatory Requirement: Only ONE mandatory discount
                        per transaction
                    </p>
                </div>
            </details>
        </div>

        <!-- PWD + Senior Guidance -->
        <div
            v-if="hasMultipleMandatoryOptions"
            class="mb-4 p-3 bg-orange-50 border border-orange-200 rounded"
        >
            <details class="text-sm text-orange-800">
                <summary class="cursor-pointer font-medium text-orange-700">
                    ⚠️ Multiple Mandatory Discounts Available
                </summary>
                <div class="mt-2 space-y-1">
                    <p class="text-orange-700">
                        If customer qualifies for multiple discounts (PWD +
                        Senior), they must choose ONE.
                    </p>
                    <p class="text-orange-700">
                        Help them select the most beneficial option or their
                        preferred discount.
                    </p>
                    <div
                        v-if="getBestMandatoryDiscount"
                        class="text-orange-600"
                    >
                        <strong>Suggestion:</strong>
                        {{ getBestMandatoryDiscount.label }} might be the best
                        value
                    </div>
                </div>
            </details>
        </div>

        <!-- Business Rules Reference -->
        <div class="mb-4 p-3 bg-gray-50 border border-gray-200 rounded">
            <details class="text-xs text-gray-600">
                <summary class="cursor-pointer font-medium text-gray-700">
                    📋 Cashier Guidelines
                </summary>
                <div class="mt-2 space-y-1">
                    <p>
                        <strong>ID Verification:</strong> Always verify customer
                        ID for mandatory discounts
                    </p>
                    <p>
                        <strong>Multiple Eligibility:</strong> Customer chooses
                        ONE mandatory discount only
                    </p>
                    <p>
                        <strong>Combining:</strong> Promotional + Mandatory
                        discounts can be combined
                    </p>
                    <p>
                        <strong>Documentation:</strong> Ensure proper receipt
                        notation for audit compliance
                    </p>
                </div>
            </details>
        </div>

        <!-- Direct Form Fields for easier debugging -->
        <div class="space-y-4">
            <!-- Promotional Discounts -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Promotional Discounts
                </label>
                <a-select
                    v-model:value="selectedPromotionalDiscounts"
                    mode="multiple"
                    placeholder="Choose promotional discount(s) (optional)"
                    :options="availableDiscounts"
                    class="w-full"
                    size="large"
                    :disabled="availableDiscounts.length === 0"
                />
                <div
                    v-if="errors.promotional"
                    class="text-red-500 text-sm mt-1"
                >
                    {{ errors.promotional[0] }}
                </div>
            </div>

            <!-- Mandatory Discounts -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    Mandatory Discounts (Choose ONE Only)
                </label>
                <a-select
                    v-model:value="selectedMandatoryDiscount"
                    placeholder="Select PWD, Senior, Student, etc. (optional)"
                    :options="availableMandatoryDiscounts"
                    class="w-full"
                    size="large"
                    allowClear
                    :disabled="availableMandatoryDiscounts.length === 0"
                />
                <div v-if="errors.mandatory" class="text-red-500 text-sm mt-1">
                    {{ errors.mandatory[0] }}
                </div>
                <div
                    v-if="hasMultipleMandatoryOptions"
                    class="text-orange-600 text-sm mt-1"
                >
                    ⚠️ Customer with multiple eligibilities (PWD + Senior) can
                    only use ONE discount
                </div>
            </div>
        </div>

        <template #footer>
            <!-- Clear button -->
            <a-button
                type="danger"
                :loading="discountLoading"
                @click="handleClearDiscount"
            >
                Clear All Discounts
            </a-button>

            <primary-button
                :loading="loading"
                @click="handleSave"
            >
                {{ selectedPromotionalDiscounts.length > 0 || selectedMandatoryDiscount ? 'Apply Discount(s)' : 'Update Order' }}
            </primary-button>
        </template>
    </a-modal>
</template>
