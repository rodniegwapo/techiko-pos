<script setup>
import PrimaryButton from "@/Components/PrimaryButton.vue";
import { ref, watch, computed } from "vue";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import axios from "axios";
import dayjs from "dayjs";
import { notification } from "ant-design-vue";

const emit = defineEmits(["close", "discount-applied"]);
const { formData, errors } = useGlobalVariables();
const { getRoute } = useDomainRoutes();

const props = defineProps({
    openModal: Boolean,
    product: { type: Object, default: () => ({}) },
    orderId: { type: [String, Number], default: null },
    orders: { type: Array, default: () => [] },
    discountOptions: { type: Object, default: () => ({}) },
});

const modalVisible = computed({
    get: () => props.openModal,
    set: (value) => {
        if (!value) emit("close");
    },
});

const product = computed(() => props.product);
const orderId = computed(() => props.orderId);
const orders = computed(() => props.orders);

const discounts = computed(
    () => props.discountOptions?.product_discount_options || [],
);

const loading = ref(false);
const discountLoading = ref(false);

watch(
    () => props.openModal,
    (isOpen) => {
        if (!isOpen || !product.value || !orderId.value) return;

        const currentProduct = orders.value.find(
            (order) => order.id === product.value.id,
        );

        if (
            currentProduct &&
            (currentProduct.discount_id || currentProduct.discount_amount > 0)
        ) {
            if (currentProduct.discount_id) {
                formData.value.discount = currentProduct.discount_id;
            }
        } else {
            formData.value.discount = null;
        }
    },
);

const handleSave = async () => {
    try {
        const discountId =
            typeof formData.value?.discount === "object" &&
            formData.value.discount?.value !== undefined
                ? formData.value.discount.value
                : formData.value?.discount;

        if (!discountId) return emit("close");
        loading.value = true;

        const { data: saleItem } = await axios.get(
            getRoute("sales.find-sale-item", { sale: orderId.value }),
            { params: { product_id: product.value.id } },
        );

        if (!saleItem?.id) {
            notification.error({
                message: "Sale Item Not Found",
                description:
                    "The product needs to be added to the sale before applying a discount. Please try again.",
                duration: 5,
            });
            return emit("close");
        }

        const selectedDiscount = discounts.value.find((d) => d.id === discountId);
        if (!selectedDiscount) return emit("close");

        await axios.post(
            getRoute("sales.items.discount.apply", {
                sale: orderId.value,
                saleItem: saleItem.id,
            }),
            { discount_id: selectedDiscount.id },
        );

        notification.success({
            message: "Discount Applied",
            description: `${selectedDiscount.name} has been applied to ${product.value.name}`,
            duration: 3,
        });

        emit("discount-applied");
        emit("close");
    } catch (e) {
        notification.error({
            message: "Discount Application Failed",
            description: e.message || "Failed to apply discount. Please try again.",
            duration: 5,
        });
    } finally {
        loading.value = false;
    }
};

const handleClearDiscount = async () => {
    try {
        discountLoading.value = true;

        const { data: saleItem } = await axios.get(
            getRoute("sales.find-sale-item", { sale: orderId.value }),
            { params: { product_id: product.value.id } },
        );

        if (!saleItem?.id) {
            notification.error({
                message: "Sale Item Not Found",
                description: "Cannot clear discount for this product.",
                duration: 5,
            });
            return emit("close");
        }

        const hasDiscountAmount =
            saleItem.discount && parseFloat(saleItem.discount) > 0;
        const hasDiscountRelationship =
            saleItem.discounts && saleItem.discounts.length > 0;

        if (!hasDiscountAmount && !hasDiscountRelationship) {
            notification.info({
                message: "No Discount Found",
                description: "This product doesn't have any discounts to clear.",
                duration: 3,
            });
            return emit("close");
        }

        const discountToRemove = hasDiscountRelationship
            ? saleItem.discounts[0]
            : null;

        if (!discountToRemove) {
            notification.error({
                message: "Clear Discount Failed",
                description:
                    "Unable to identify the discount to remove. Please refresh and try again.",
                duration: 5,
            });
            return emit("close");
        }

        await axios.delete(
            getRoute("sales.items.discount.remove", {
                sale: orderId.value,
                saleItem: saleItem.id,
            }),
            { data: { discount_id: discountToRemove.id } },
        );

        notification.success({
            message: "Discount Cleared",
            description: "Product discount has been successfully removed.",
            duration: 3,
        });

        emit("discount-applied");
        emit("close");
    } catch (e) {
        notification.error({
            message: "Clear Discount Failed",
            description:
                e.response?.data?.message ||
                "Failed to clear discount. Please try again.",
            duration: 5,
        });
    } finally {
        discountLoading.value = false;
    }
};

const productDiscountSelectOptions = computed(() =>
    discounts.value
        .filter((item) => {
            const isProductScope = item.scope == "product";
            const isActive = item.is_active;
            const startDateValid =
                !item.start_date ||
                dayjs(item.start_date).isBefore(dayjs()) ||
                dayjs(item.start_date).isSame(dayjs(), "day");
            const endDateValid =
                !item.end_date ||
                dayjs(item.end_date).isAfter(dayjs()) ||
                dayjs(item.end_date).isSame(dayjs(), "day");

            return isProductScope && isActive && startDateValid && endDateValid;
        })
        .map((item) => ({
            label: `${item.name} (${item.type === "percentage" || item.type === "percent" ? parseFloat(item.value) + "%" : "₱" + parseFloat(item.value).toFixed(2)})`,
            value: item.id,
            amount: item.value,
        })),
);

const discountValue = computed({
    get: () => {
        const val = formData.value?.discount;
        if (typeof val === "object" && val?.value !== undefined) {
            return val.value;
        }
        return val;
    },
    set: (value) => {
        formData.value.discount = value;
    },
});
</script>
<template>
    <a-modal
        v-model:visible="modalVisible"
        :title="`Apply Discount - ${product.name}`"
        @cancel="$emit('close')"
        width="400px"
        :maskClosable="false"
    >
        <a-form layout="vertical">
            <a-form-item
                label="Select Discount"
                :validate-status="errors.discount ? 'error' : ''"
                :help="errors.discount || ''"
            >
                <a-select
                    v-model:value="discountValue"
                    :options="productDiscountSelectOptions"
                    placeholder="Select discount"
                    :allowClear="false"
                    show-search
                    :filter-option="
                        (input, option) =>
                            option.label.toLowerCase().includes(input.toLowerCase())
                    "
                    size="large"
                />
            </a-form-item>
        </a-form>
        <template #footer>
            <a-button
                v-if="
                    product.discount_id ||
                    (product.discount_amount && product.discount_amount > 0)
                "
                type="danger"
                :loading="discountLoading"
                @click="handleClearDiscount"
            >
                Clear Discount
            </a-button>
            <primary-button :loading="loading" @click="handleSave">
                Submit
            </primary-button>
        </template>
    </a-modal>
</template>
