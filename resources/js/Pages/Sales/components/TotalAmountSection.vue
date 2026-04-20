<script setup>
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import ApplyOrderDiscountModal from "./ApplyOrderDiscountModal.vue";
import CardPaymentTypeModal from "./CardPaymentTypeModal.vue";
import { IconDiscount, IconArrowRightToArc } from "@tabler/icons-vue";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useHelpers } from "@/Composables/useHelpers";
import { useCredit } from "@/Composables/useCredit";
import { ref, computed, createVNode, toRefs, watch, inject } from "vue";
import { Modal, notification } from "ant-design-vue";
import { ExclamationCircleOutlined } from "@ant-design/icons-vue";
import axios from "axios";
import { usePage } from "@inertiajs/vue3";

const { formData, errors } = useGlobalVariables();
const { getRoute } = useDomainRoutes();
const { formattedTotal } = useHelpers();
const { checkCreditAvailability } = useCredit();
const page = usePage();

const salesCartIsOnline = inject(
    "isSalesOnline",
    computed(() => true),
);

// Props for direct data passing
const props = defineProps({
    selectedCustomer: {
        type: Object,
        default: null,
    },
    orders: { type: Array, default: () => [] },
    currentSale: { type: Object, default: () => null },
    orderDiscountAmount: { type: Number, default: 0 },
    orderDiscountId: { type: String, default: "" },
    orderId: { type: [String, Number], default: null },
    discountOptions: { type: Object, default: () => ({}) },
    offlinePaymentMethod: { type: String, default: "cash" },
    /** Cached card types from parent (refreshed while online) for offline modal */
    cachedPaymentCardTypes: { type: Array, default: () => [] },
    offlinePaymentCardTypeId: { type: [Number, String], default: null },
    salesSettings: {
        type: Object,
        default: () => ({
            apply_vat_automatically: false,
            vat_rate_percent: 12,
            vat_pricing_mode: "exclusive",
        }),
    },
});

const {
    orders,
    currentSale,
    orderDiscountAmount,
    orderDiscountId,
    orderId,
    discountOptions,
} = toRefs(props);

// Emit events to parent
const emit = defineEmits([
    "discount-applied",
    "cart-updated",
    "save-offline-sale",
    "update:offlinePaymentMethod",
    "update:offlinePaymentCardTypeId",
]);

// Computed values
const totalAmount = computed(() => {
    return orders.value.reduce((sum, order) => {
        const price = parseFloat(order.price) || 0;
        const quantity = parseInt(order.quantity) || 0;
        const subtotal = !isNaN(price * quantity)
            ? price * quantity
            : quantity * price;
        return sum + subtotal;
    }, 0);
});

const salesSettingsResolved = computed(
    () =>
        props.salesSettings ?? {
            apply_vat_automatically: false,
            vat_rate_percent: 12,
            vat_pricing_mode: "exclusive",
        },
);

const isInclusive = computed(
    () => salesSettingsResolved.value.vat_pricing_mode === "inclusive",
);

const netAfterOrderDiscount = computed(() =>
    Math.max(
        0,
        Number(totalAmount.value) -
            (parseFloat(orderDiscountAmount.value) || 0),
    ),
);

const taxAmountDisplay = computed(() => {
    if (!salesSettingsResolved.value.apply_vat_automatically) {
        return 0;
    }
    if (
        salesCartIsOnline.value &&
        currentSale.value &&
        currentSale.value.tax_amount != null
    ) {
        return Number(currentSale.value.tax_amount) || 0;
    }
    const rate =
        (Number(salesSettingsResolved.value.vat_rate_percent) || 12) / 100;
    const net = netAfterOrderDiscount.value;
    if (isInclusive.value) {
        return Math.round(net * (rate / (1 + rate)) * 100) / 100;
    }
    return Math.round(net * rate * 100) / 100;
});

const grandTotalDisplay = computed(() => {
    if (
        salesCartIsOnline.value &&
        currentSale.value &&
        currentSale.value.grand_total != null
    ) {
        return Number(currentSale.value.grand_total) || 0;
    }
    if (
        salesSettingsResolved.value.apply_vat_automatically &&
        isInclusive.value
    ) {
        return netAfterOrderDiscount.value;
    }
    return netAfterOrderDiscount.value + taxAmountDisplay.value;
});

const netExVatDisplay = computed(() => {
    if (!salesSettingsResolved.value.apply_vat_automatically) {
        return 0;
    }
    if (!isInclusive.value) {
        return 0;
    }
    return Math.max(
        0,
        Number(grandTotalDisplay.value) - Number(taxAmountDisplay.value),
    );
});

// Using formattedTotal from useHelpers composable

const amountReceived = ref(0);

const openOrderDicountModal = ref(false);

const showDiscountOrder = async () => {
    if (!salesCartIsOnline.value) {
        notification.warning({
            message: "Requires connection",
            description: "Order discounts are not available offline.",
        });
        return;
    }
    // Check if there's an active order/draft OR if there are items in the cart
    // (orderId might be null briefly while draft is being created)
    if (!orderId.value && orders.value.length === 0) return;

    // Load current discounts from database instead of localStorage
    let regularDiscountOptions = [];
    let mandatoryDiscountOption = null;
    let currentPromotionalDiscounts = [];
    let currentMandatoryDiscount = null;

    try {
        // Use consolidated discount data from props instead of API call
        console.log(
            "TotalAmountSection - discountOptions:",
            discountOptions.value,
        );
        const { promotional_discount_options, mandatory_discount_options } =
            discountOptions.value;
        console.log(
            "TotalAmountSection - promotional_discount_options:",
            promotional_discount_options,
        );
        console.log(
            "TotalAmountSection - mandatory_discount_options:",
            mandatory_discount_options,
        );

        // Convert database discounts to option objects for the select components
        regularDiscountOptions = (promotional_discount_options || []).map(
            (discount) => ({
                label: `${discount.name} (${
                    discount.type === "percentage"
                        ? discount.value + "%"
                        : "₱" + discount.value
                })`,
                value: discount.id,
                amount: discount.value,
                type: discount.type,
            }),
        );

        // Get the first active mandatory discount
        mandatoryDiscountOption =
            mandatory_discount_options && mandatory_discount_options.length > 0
                ? {
                      label: `${mandatory_discount_options[0].name} (${
                          mandatory_discount_options[0].type === "percentage"
                              ? mandatory_discount_options[0].value + "%"
                              : "₱" + mandatory_discount_options[0].value
                      })`,
                      value: mandatory_discount_options[0].id,
                      amount: mandatory_discount_options[0].value,
                      type: mandatory_discount_options[0].type,
                  }
                : null;

        // Load currently applied discounts from the sale (if any)
        if (orderId.value) {
            try {
                const saleResponse = await axios.get(
                    getRoute("sales.discounts.sale", { sale: orderId.value }),
                );
                console.log("Sale discounts response:", saleResponse.data);

                // Handle different response structures - backend returns 'discounts'
                const sale_discounts =
                    saleResponse.data?.discounts ||
                    saleResponse.data?.sale_discounts ||
                    [];

                if (sale_discounts && Array.isArray(sale_discounts)) {
                    // Get currently applied promotional discounts
                    const appliedPromotional = sale_discounts.filter(
                        (item) => item.discount_type === "regular",
                    );
                    currentPromotionalDiscounts = appliedPromotional.map(
                        (item) => ({
                            label: `${item.discount?.name || "Unknown"} (${
                                item.discount?.type === "percentage"
                                    ? item.discount.value + "%"
                                    : "₱" + item.discount.value
                            })`,
                            value: item.discount_id,
                            amount: item.discount?.value,
                            type: item.discount?.type,
                        }),
                    );

                    console.log(
                        "Current promotional discounts loaded:",
                        currentPromotionalDiscounts,
                    );

                    // Get currently applied mandatory discount
                    const appliedMandatory = sale_discounts.filter(
                        (item) => item.discount_type === "mandatory",
                    );
                    if (appliedMandatory.length > 0) {
                        const mandatory = appliedMandatory[0];
                        currentMandatoryDiscount = {
                            label: `${
                                mandatory.mandatoryDiscount?.name || "Unknown"
                            } (${
                                mandatory.mandatoryDiscount?.type ===
                                "percentage"
                                    ? mandatory.mandatoryDiscount.value + "%"
                                    : "₱" + mandatory.mandatoryDiscount.value
                            })`,
                            value: mandatory.mandatory_discount_id,
                            amount: mandatory.mandatoryDiscount?.value,
                            type: mandatory.mandatoryDiscount?.type,
                        };
                    }

                    console.log(
                        "Current mandatory discount loaded:",
                        currentMandatoryDiscount,
                    );
                }
            } catch (saleError) {
                console.log(
                    "No current discounts found or error loading sale discounts:",
                    saleError,
                );
                // This is not an error - just means no discounts are currently applied
            }
        }
    } catch (error) {
        console.error("Failed to load discounts:", error);
        notification.error({
            message: "Error",
            description: "Failed to load discount options",
        });
        return;
    }

    formData.value = {
        orderDiscount: currentPromotionalDiscounts, // Show currently applied promotional discounts
        mandatoryDiscount: currentMandatoryDiscount, // Show currently applied mandatory discount
    };
    openOrderDicountModal.value = true;
};

const customerChange = computed(() => {
    const received = Number(amountReceived.value) || 0;
    const total = Number(grandTotalDisplay.value) || 0;

    if (received < 1) return 0;
    return received - total;
});

const proceedPaymentLoading = ref(false);

const cardTypeModalOpen = ref(false);
const selectedPaymentCardTypeId = ref(null);
const paymentMethod = ref("cash");

watch(
    () => props.offlinePaymentCardTypeId,
    (v) => {
        if (v != null && v !== "") {
            selectedPaymentCardTypeId.value = Number(v);
        }
    },
    { immediate: true },
);

watch(selectedPaymentCardTypeId, (id) => {
    if (!salesCartIsOnline.value) {
        emit("update:offlinePaymentCardTypeId", id);
    }
});

function onCardTypeModalConfirm(id) {
    selectedPaymentCardTypeId.value = id;
}

function onCardTypeModalCancel() {
    paymentMethod.value = "cash";
    selectedPaymentCardTypeId.value = null;
    if (!salesCartIsOnline.value) {
        emit("update:offlinePaymentMethod", "cash");
        emit("update:offlinePaymentCardTypeId", null);
    }
}

const handleProceedPaymentConfirmation = () => {
    Modal.confirm({
        title: "Are you sure you would like to proceed?",
        icon: createVNode(ExclamationCircleOutlined),
        okText: "Submit",
        cancelText: "Cancel",
        onOk() {
            return new Promise(async (innerResolve, innerReject) => {
                try {
                    await handleProceedPayment();
                    innerResolve();
                } catch (error) {
                    innerReject(error);
                }
            });
        },
        onCancel() {
            console.log("Cancel");
        },
    });
};

const handleProceedPayment = async () => {
    if (!salesCartIsOnline.value) {
        notification.warning({
            message: "Offline",
            description: "Use “Save as offline sale” instead.",
        });
        return;
    }
    try {
        proceedPaymentLoading.value = true;

        // Validate credit payment
        if (paymentMethod.value === "credit") {
            if (!props.selectedCustomer?.id) {
                notification.error({
                    message: "Error",
                    description: "Customer is required for credit payments.",
                });
                throw new Error("Customer required for credit payment");
            }

            if (!creditLimitSufficient.value) {
                notification.error({
                    message: "Credit Limit Exceeded",
                    description: `Available credit (₱${creditInfo.value?.availableCredit.toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}) is insufficient for this transaction.`,
                });
                throw new Error("Credit limit exceeded");
            }
        }

        if (
            paymentMethod.value === "card" &&
            !selectedPaymentCardTypeId.value
        ) {
            notification.error({
                message: "Card type required",
                description:
                    "Choose a card payment type before completing checkout.",
            });
            cardTypeModalOpen.value = true;
            throw new Error("Card type required");
        }

        // Single API call to process payment and loyalty together
        const body = {
            customer_id: props.selectedCustomer?.id || null,
            sale_amount: grandTotalDisplay.value,
            payment_method: paymentMethod.value,
        };
        if (paymentMethod.value === "card" && selectedPaymentCardTypeId.value) {
            body.payment_card_type_id = selectedPaymentCardTypeId.value;
        }
        const response = await axios.post(
            getRoute("sales.payment.store", {
                sale: orderId.value,
            }),
            body,
        );

        // Clean up and finalize
        amountReceived.value = 0;
        selectedPaymentCardTypeId.value = null;
        paymentMethod.value = "cash";

        // Show success notification based on response
        const loyaltyResults = response.data.loyalty_results;
        const creditResults = response.data.credit_results;

        if (creditResults) {
            notification.success({
                message: "Credit Sale Processed!",
                description: `Transaction completed on credit. Remaining credit: ₱${creditResults.available_credit.toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`,
                duration: 5,
            });
        } else if (loyaltyResults && loyaltyResults.points_earned) {
            notification.success({
                message: "Payment Successful!",
                description: `Transaction completed. ${props.selectedCustomer.name} earned ${loyaltyResults.points_earned} points!`,
                duration: 5,
            });

            // Show tier upgrade notification if applicable
            if (loyaltyResults.tier_upgraded) {
                setTimeout(() => {
                    notification.info({
                        message: "Tier Upgraded!",
                        description: `🎉 ${
                            props.selectedCustomer.name
                        } is now ${loyaltyResults.new_tier.toUpperCase()} tier!`,
                        duration: 8,
                    });
                }, 1000);
            }
        } else {
            notification.success({
                message: "Payment Successful!",
                description: "Transaction completed successfully.",
            });
        }

        // Refresh current pending sale data to show updated state
        emit("cart-updated");

        localStorage.setItem("order_discount_amount", 0);
        localStorage.setItem("order_discount_ids", "");
        orderDiscountAmount.value = 0;
        orderDiscountId.value = "";
    } catch (error) {
        const errorMessage =
            error.response?.data?.message ||
            error.message ||
            "Please try again or contact support.";
        notification.error({
            message: "Payment failed",
            description: errorMessage,
        });
        throw error;
    } finally {
        proceedPaymentLoading.value = false;
    }
};

const disabledPaymentButtonColor = computed(() => {
    if (paymentMethod.value === "credit") {
        if (!creditLimitSufficient.value) return "";
        if (orders.value.length == 0) return "";
        return "bg-green-700 border-green-700 hover:bg-green-600";
    }
    if (amountReceived.value < grandTotalDisplay.value) return "";
    if (orders.value.length == 0) return "";
    return "bg-green-700 border-green-700 hover:bg-green-600";
});

const creditInfo = ref(null);
const checkingCredit = ref(false);

watch(
    () => props.offlinePaymentMethod,
    (v) => {
        if (v && paymentMethod.value !== v) {
            paymentMethod.value = v;
        }
    },
    { immediate: true },
);

watch(paymentMethod, (v) => {
    if (!salesCartIsOnline.value) {
        emit("update:offlinePaymentMethod", v);
    }
    if (v === "card") {
        cardTypeModalOpen.value = true;
    } else {
        selectedPaymentCardTypeId.value = null;
    }
});

watch(
    salesCartIsOnline,
    (online) => {
        if (!online && paymentMethod.value === "credit") {
            paymentMethod.value = "cash";
            emit("update:offlinePaymentMethod", "cash");
        }
    },
    { immediate: true },
);

// Watch for customer changes and check credit availability
watch(
    () => props.selectedCustomer,
    async (customer) => {
        if (customer && customer.id) {
            checkingCredit.value = true;
            // Use customer data directly instead of API call
            const availableCredit = Math.max(
                0,
                (customer.credit_limit || 0) - (customer.credit_balance || 0),
            );
            creditInfo.value = {
                available: availableCredit >= grandTotalDisplay.value,
                availableCredit: availableCredit,
                creditLimit: customer.credit_limit || 0,
                creditBalance: customer.credit_balance || 0,
                creditEnabled: customer.credit_enabled || false,
            };
            checkingCredit.value = false;
        } else {
            creditInfo.value = null;
        }
    },
    { immediate: true },
);

// Watch payable total changes to re-check credit
watch(
    () => grandTotalDisplay.value,
    async (amount) => {
        if (props.selectedCustomer?.id && creditInfo.value) {
            // Recalculate availability based on new amount
            const availableCredit = Math.max(
                0,
                (props.selectedCustomer.credit_limit || 0) -
                    (props.selectedCustomer.credit_balance || 0),
            );
            creditInfo.value.available = availableCredit >= amount;
        }
    },
);

// Check if credit payment is available
const canUseCredit = computed(() => {
    return (
        props.selectedCustomer?.credit_enabled &&
        creditInfo.value?.creditEnabled
    );
});

// Check if credit limit is sufficient
const creditLimitSufficient = computed(() => {
    if (paymentMethod.value !== "credit" || !creditInfo.value) return true;
    return creditInfo.value.availableCredit >= grandTotalDisplay.value;
});
</script>

<template>
    <div class="bg-white">
        <div class="px-6 max-w-7xl mx-auto py-4 shadow-sm">
            <!-- Horizontal Layout - Single Row -->
            <div class="flex items-center justify-between gap-6">
                <!-- Order Discount -->
                <div class="flex items-center gap-2">
                    <span class="text-gray-700 whitespace-nowrap"
                        >Order Discount:</span
                    >
                    <span class="font-medium">{{
                        formattedTotal(orderDiscountAmount)
                    }}</span>
                    <icon-tooltip-button
                        name="Apply Order Discount"
                        :class="{
                            'hover:bg-green-700 p-1': orders.length !== 0,
                        }"
                        :disabled="orders.length == 0 || !salesCartIsOnline"
                        @click="showDiscountOrder"
                    >
                        <IconDiscount size="20" class="mx-auto" />
                    </icon-tooltip-button>
                </div>

                <!-- Subtotal -->
                <div class="flex items-center gap-2">
                    <span class="text-gray-700 whitespace-nowrap"
                        >Subtotal:</span
                    >
                    <span class="font-medium">{{
                        formattedTotal(totalAmount)
                    }}</span>
                </div>

                <!-- Tax (VAT) -->
                <div
                    v-if="salesSettingsResolved.apply_vat_automatically"
                    class="flex flex-col gap-0.5"
                >
                    <div class="flex items-center gap-2">
                        <span class="text-gray-700 whitespace-nowrap"
                            >Tax (VAT):</span
                        >
                        <span class="font-medium">{{
                            formattedTotal(taxAmountDisplay)
                        }}</span>
                    </div>
                    <span v-if="isInclusive" class="text-xs text-gray-500 pl-0"
                        >Included in total below</span
                    >
                </div>

                <!-- Net ex-VAT (inclusive pricing) -->
                <div
                    v-if="
                        salesSettingsResolved.apply_vat_automatically &&
                        isInclusive
                    "
                    class="flex items-center gap-2"
                >
                    <span class="text-gray-700 whitespace-nowrap"
                        >Net (ex-VAT):</span
                    >
                    <span class="font-medium">{{
                        formattedTotal(netExVatDisplay)
                    }}</span>
                </div>

                <!-- Total -->
                <div class="flex flex-col gap-0.5">
                    <div class="flex items-center gap-2">
                        <span
                            class="text-gray-900 font-semibold whitespace-nowrap"
                            >Total:</span
                        >
                        <span class="font-bold text-green-600 text-lg">
                            {{ formattedTotal(grandTotalDisplay) }}
                        </span>
                    </div>
                    <span
                        v-if="
                            salesSettingsResolved.apply_vat_automatically &&
                            isInclusive
                        "
                        class="text-xs text-gray-500"
                        >Amount includes VAT</span
                    >
                </div>
            </div>

            <hr class="mt-4" />
            <div class="p-2 flex gap-8">
                <!-- Payment Method -->
                <div class="flex items-start flex-col gap-2">
                    <span class="text-gray-700 whitespace-nowrap"
                        >Payment Method</span
                    >
                    <a-radio-group
                        v-model:value="paymentMethod"
                        button-style="solid"
                    >
                        <a-radio-button value="cash">Cash</a-radio-button>
                        <a-radio-button value="card">Card</a-radio-button>
                        <a-radio-button
                            value="credit"
                            :disabled="!salesCartIsOnline || !canUseCredit"
                        >
                            Credit
                        </a-radio-button>
                    </a-radio-group>
                    <!-- Credit Information Display -->
                    <div
                        v-if="paymentMethod === 'credit' && creditInfo"
                        class="mt-2 text-sm"
                    >
                        <div v-if="checkingCredit" class="text-gray-500">
                            Checking credit...
                        </div>
                        <div v-else>
                            <div class="text-gray-600">
                                Available Credit:
                                <span class="font-medium text-green-600"
                                    >₱{{
                                        creditInfo.availableCredit.toLocaleString(
                                            "en-US",
                                            {
                                                minimumFractionDigits: 2,
                                                maximumFractionDigits: 2,
                                            },
                                        )
                                    }}</span
                                >
                            </div>
                            <div class="text-gray-600">
                                Current Balance:
                                <span class="font-medium text-red-600"
                                    >₱{{
                                        creditInfo.creditBalance.toLocaleString(
                                            "en-US",
                                            {
                                                minimumFractionDigits: 2,
                                                maximumFractionDigits: 2,
                                            },
                                        )
                                    }}</span
                                >
                            </div>
                            <div
                                v-if="!creditLimitSufficient"
                                class="text-red-600 font-medium mt-1"
                            >
                                ⚠️ Insufficient credit for this transaction
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Amount Received (only show for non-credit payments) -->
                <div
                    v-if="paymentMethod !== 'credit'"
                    class="flex items-start flex-col gap-2"
                >
                    <span class="text-gray-700 whitespace-nowrap"
                        >Amount Received:</span
                    >
                    <a-input
                        v-model:value="amountReceived"
                        type="number"
                        placeholder="0"
                        :class="{
                            'border-red-400':
                                amountReceived < grandTotalDisplay &&
                                orders.length > 0,
                        }"
                        class="w-34 text-center"
                    />
                </div>

                <!-- Change (only show for non-credit payments) -->
                <div
                    v-if="paymentMethod !== 'credit'"
                    class="flex items-start flex-col gap-2"
                >
                    <span class="text-gray-700 whitespace-nowrap">Change:</span>
                    <a-input readonly :value="formattedTotal(customerChange)" />
                </div>

                <!-- Proceed Payment / Offline save -->
                <div class="flex flex-col gap-2">
                    <div class="invisible">Proceed Payment</div>
                    <a-button
                        v-if="salesCartIsOnline"
                        type="primary"
                        class="w-[300px]"
                        :class="disabledPaymentButtonColor"
                        @click="handleProceedPaymentConfirmation"
                        :disabled="
                            proceedPaymentLoading ||
                            (paymentMethod !== 'credit' &&
                                amountReceived < grandTotalDisplay) ||
                            (paymentMethod === 'credit' &&
                                !creditLimitSufficient) ||
                            (paymentMethod === 'card' &&
                                !selectedPaymentCardTypeId) ||
                            orders.length == 0
                        "
                        :loading="proceedPaymentLoading"
                    >
                        Proceed Payment
                    </a-button>
                    <a-button
                        v-else
                        type="primary"
                        class="w-[300px] bg-amber-700 border-amber-700 hover:bg-amber-600"
                        :disabled="
                            orders.length == 0 ||
                            (paymentMethod === 'card' &&
                                !selectedPaymentCardTypeId)
                        "
                        @click="emit('save-offline-sale')"
                    >
                        Save as offline sale
                    </a-button>
                </div>
            </div>

            <!-- Order Discount Modal -->
            <apply-order-discount-modal
                :openModal="openOrderDicountModal"
                :orderId="orderId"
                :orders="orders"
                :currentSale="currentSale"
                :orderDiscountAmount="orderDiscountAmount"
                :orderDiscountId="orderDiscountId"
                :discountOptions="discountOptions"
                @close="openOrderDicountModal = false"
                @discount-applied="emit('discount-applied')"
            />

            <card-payment-type-modal
                v-model:visible="cardTypeModalOpen"
                :use-network="salesCartIsOnline"
                :cached-types="cachedPaymentCardTypes"
                :initial-selected-id="selectedPaymentCardTypeId"
                @confirm="onCardTypeModalConfirm"
                @cancel="onCardTypeModalCancel"
            />
        </div>
    </div>
</template>
