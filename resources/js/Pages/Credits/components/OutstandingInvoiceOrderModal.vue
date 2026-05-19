<template>
  <a-modal
    :visible="visible"
    :title="modalTitle"
    width="720px"
    :footer="null"
    @cancel="handleClose"
  >
    <template v-if="!invoice">
      <a-empty description="No invoice selected" />
    </template>
    <template v-else-if="!invoice.sale">
      <p class="text-gray-600">No sale linked to this credit line.</p>
    </template>
    <template v-else>
      <div class="mb-4 text-sm text-gray-600 space-y-1">
        <div v-if="customer?.name">
          <span class="font-medium text-gray-700">Customer:</span>
          {{ customer.name }}
        </div>
        <div v-if="invoice.sale?.invoice_number">
          <span class="font-medium text-gray-700">Sale invoice:</span>
          {{ invoice.sale.invoice_number }}
        </div>
        <div>
          <span class="font-medium text-gray-700">Credit line amount:</span>
          ₱{{ formatMoney(invoice.amount) }}
        </div>
        <div v-if="invoice.due_date">
          <span class="font-medium text-gray-700">Due:</span>
          {{ formatDate(invoice.due_date) }}
        </div>
      </div>
      <a-table
        :columns="columns"
        :data-source="lineItems"
        :pagination="false"
        size="small"
        row-key="id"
      />
    </template>
  </a-modal>
</template>

<script setup>
import { computed } from "vue";

const props = defineProps({
  visible: Boolean,
  invoice: {
    type: Object,
    default: null,
  },
  customer: {
    type: Object,
    default: null,
  },
});

const emit = defineEmits(["close"]);

const lineItems = computed(() => {
  const sale = props.invoice?.sale;
  if (!sale) return [];
  const items = sale.sale_items ?? sale.saleItems ?? [];
  return Array.isArray(items) ? items : [];
});

const modalTitle = computed(() => {
  if (!props.invoice) return "Order details";
  const refNo =
    props.invoice.reference_number ||
    props.invoice.sale?.invoice_number ||
    "Invoice";
  return `Order — ${refNo}`;
});

const formatMoney = (value) => {
  const n = Number(value ?? 0);
  return n.toLocaleString("en-US", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
};

const formatDate = (date) => {
  if (!date) return "—";
  return new Date(date).toLocaleDateString("en-US", {
    year: "numeric",
    month: "short",
    day: "numeric",
  });
};

const columns = [
  {
    title: "Product",
    key: "product",
    dataIndex: "product",
    customRender: ({ record }) =>
      record.product?.name ?? record.product_name ?? "—",
  },
  {
    title: "Qty",
    key: "quantity",
    dataIndex: "quantity",
    align: "right",
    width: 72,
  },
  {
    title: "Unit price",
    key: "unit_price",
    dataIndex: "unit_price",
    align: "right",
    customRender: ({ text }) => `₱${formatMoney(text)}`,
  },
  {
    title: "Discount",
    key: "discount",
    dataIndex: "discount",
    align: "right",
    customRender: ({ text }) => `₱${formatMoney(text ?? 0)}`,
  },
  {
    title: "Subtotal",
    key: "subtotal",
    dataIndex: "subtotal",
    align: "right",
    customRender: ({ text }) => `₱${formatMoney(text)}`,
  },
];

const handleClose = () => {
  emit("close");
};
</script>
