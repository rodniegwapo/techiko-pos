<script setup>
import { computed, toRefs } from "vue";
import {
  IconArrowUp,
  IconArrowDown,
  IconPackage,
  IconMapPin,
  IconUser,
  IconCalendar,
  IconFileText,
  IconCurrencyDollar,
} from "@tabler/icons-vue";
import { useHelpers } from "@/Composables/useHelpers";

const { formatCurrency, formatDate, formatDateTime } = useHelpers();

const props = defineProps({
  visible: {
    type: Boolean,
    default: false,
  },
  movement: {
    type: Object,
    default: null,
  },
});

const { visible } = toRefs(props);

const emit = defineEmits(["update:visible"]);

const handleClose = () => {
  emit("update:visible", false);
};

const getMovementTypeColor = (type) => {
  const colors = {
    sale: "red",
    purchase: "green",
    adjustment: "blue",
    transfer_in: "cyan",
    transfer_out: "orange",
    return: "purple",
    damage: "volcano",
    theft: "magenta",
    expired: "gold",
    promotion: "lime",
  };
  return colors[type] || "default";
};

const getMovementTypeDisplay = (type) => {
  const displays = {
    sale: "Sale",
    purchase: "Purchase",
    adjustment: "Stock Adjustment",
    transfer_in: "Transfer In",
    transfer_out: "Transfer Out",
    return: "Customer Return",
    damage: "Damaged Goods",
    theft: "Theft/Loss",
    expired: "Expired Products",
    promotion: "Promotional Giveaway",
  };
  return (
    displays[type] ||
    type?.replace("_", " ").replace(/\b\w/g, (l) => l.toUpperCase())
  );
};

const isIncrease = computed(() => {
  return props.movement?.quantity_change > 0;
});

const isDecrease = computed(() => {
  return props.movement?.quantity_change < 0;
});
</script>

<template>
  <a-modal
    v-model:visible="visible"
    title="Movement Details"
    width="700px"
    @cancel="handleClose"
    :footer="null"
  >
    <div v-if="movement" class="space-y-6">
      <!-- Movement Header -->
      <div class="flex items-start justify-between pb-4 border-b">
        <div class="flex items-center space-x-3">
          <div
            class="p-2 rounded-lg"
            :class="{
              'bg-green-100 text-green-600': isIncrease,
              'bg-red-100 text-red-600': isDecrease,
            }"
          >
            <IconArrowUp v-if="isIncrease" :size="24" />
            <IconArrowDown v-if="isDecrease" :size="24" />
          </div>
          <div>
            <h3 class="text-lg font-semibold">
              {{ getMovementTypeDisplay(movement.movement_type) }}
            </h3>
            <p class="text-sm text-gray-500">Movement #{{ movement.id }}</p>
          </div>
        </div>

        <a-tag
          :color="getMovementTypeColor(movement.movement_type)"
          size="large"
        >
          {{ getMovementTypeDisplay(movement.movement_type) }}
        </a-tag>
      </div>

      <!-- Product Information -->
      <div>
        <h4 class="text-md font-semibold mb-3 flex items-center">
          <IconPackage :size="18" class="mr-2" />
          Product Information
        </h4>
        <div class="bg-gray-50 p-4 rounded-lg border">
          <div class="grid grid-cols-2 gap-4">
            <div>
              <p class="text-sm text-gray-600">Product Name</p>
              <p class="font-semibold">
                {{ movement.product?.name || "Unknown Product" }}
              </p>
            </div>
            <div>
              <p class="text-sm text-gray-600">SKU</p>
              <p class="font-semibold">{{ movement.product?.SKU || "N/A" }}</p>
            </div>
            <div>
              <p class="text-sm text-gray-600">Category</p>
              <p class="font-semibold">
                {{ movement.product?.category?.name || "No Category" }}
              </p>
            </div>
            <div>
              <p class="text-sm text-gray-600">Unit of Measure</p>
              <p class="font-semibold">
                {{ movement.product?.unit_of_measure || "pcs" }}
              </p>
            </div>
          </div>
        </div>
      </div>

      <!-- Quantity Changes -->
      <div>
        <h4 class="text-md font-semibold mb-3">Quantity Changes</h4>
        <div class="grid grid-cols-3 gap-4">
          <div class="bg-blue-50 p-3 rounded-lg text-center border">
            <p class="text-sm text-gray-600">Before</p>
            <p class="text-xl font-bold text-blue-600">
              {{ movement.quantity_before }}
            </p>
          </div>
          <div
            class="p-3 rounded-lg text-center border"
            :class="{
              'bg-green-50': isIncrease,
              'bg-red-50': isDecrease,
            }"
          >
            <p class="text-sm text-gray-600">Change</p>
            <p
              class="text-xl font-bold"
              :class="{
                'text-green-600': isIncrease,
                'text-red-600': isDecrease,
              }"
            >
              {{ isIncrease ? "+" : "" }}{{ movement.quantity_change }}
            </p>
          </div>
          <div class="bg-purple-50 p-3 rounded-lg text-center border">
            <p class="text-sm text-gray-600">After</p>
            <p class="text-xl font-bold text-purple-600">
              {{ movement.quantity_after }}
            </p>
          </div>
        </div>
      </div>

      <!-- Location & Cost Information -->
      <div class="grid grid-cols-2 gap-6">
        <!-- Location -->
        <div>
          <h4 class="text-md font-semibold mb-3 flex items-center">
            <IconMapPin :size="18" class="mr-2" />
            Location
          </h4>
          <div class="space-y-2">
            <div class="flex justify-between">
              <span class="text-gray-600">Location:</span>
              <span class="font-semibold">{{
                movement.location?.name || "Unknown"
              }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">Type:</span>
              <span class="font-semibold">{{
                movement.location?.type || "N/A"
              }}</span>
            </div>
          </div>
        </div>

        <!-- Cost Information -->
        <div v-if="movement.unit_cost || movement.total_cost">
          <h4 class="text-md font-semibold mb-3 flex items-center">
            <IconCurrencyDollar :size="18" class="mr-2" />
            Cost Information
          </h4>
          <div class="space-y-2">
            <div v-if="movement.unit_cost" class="flex justify-between">
              <span class="text-gray-600">Unit Cost:</span>
              <span class="font-semibold">{{
                formatCurrency(movement.unit_cost)
              }}</span>
            </div>
            <div v-if="movement.total_cost" class="flex justify-between">
              <span class="text-gray-600">Total Cost:</span>
              <span class="font-semibold">{{
                formatCurrency(movement.total_cost)
              }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Reference Information -->
      <div v-if="movement.reference_type || movement.reference_id">
        <h4 class="text-md font-semibold mb-3 flex items-center">
          <IconFileText :size="18" class="mr-2" />
          Reference Information
        </h4>
        <div class="bg-gray-50 p-3 rounded-lg border">
          <div class="grid grid-cols-2 gap-4">
            <div v-if="movement.reference_type">
              <p class="text-sm text-gray-600">Reference Type</p>
              <p class="font-semibold">{{ movement.reference_type }}</p>
            </div>
            <div v-if="movement.reference_id">
              <p class="text-sm text-gray-600">Reference ID</p>
              <p class="font-semibold">#{{ movement.reference_id }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Additional Details -->
      <div class="grid grid-cols-2 gap-6">
        <!-- Batch & Expiry -->
        <div v-if="movement.batch_number || movement.expiry_date">
          <h4 class="text-md font-semibold mb-3">Batch Information</h4>
          <div class="space-y-2">
            <div v-if="movement.batch_number" class="flex justify-between">
              <span class="text-gray-600">Batch Number:</span>
              <span class="font-semibold">{{ movement.batch_number }}</span>
            </div>
            <div v-if="movement.expiry_date" class="flex justify-between">
              <span class="text-gray-600">Expiry Date:</span>
              <span class="font-semibold">{{
                formatDate(movement.expiry_date)
              }}</span>
            </div>
          </div>
        </div>

        <!-- User & Timestamp -->
        <div>
          <h4 class="text-md font-semibold mb-3 flex items-center">
            <IconCalendar :size="18" class="mr-2" />
            Movement Details
          </h4>
          <div class="space-y-2">
            <div class="flex justify-between">
              <span class="text-gray-600">Created:</span>
              <span class="font-semibold">{{
                formatDateTime(movement.created_at)
              }}</span>
            </div>
            <div v-if="movement.user" class="flex justify-between">
              <span class="text-gray-600">By User:</span>
              <span class="font-semibold">{{ movement.user.name }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Notes -->
      <div v-if="movement.notes || movement.reason">
        <h4 class="text-md font-semibold mb-3">Notes & Reason</h4>
        <div class="bg-gray-50 p-3 rounded-lg border">
          <div v-if="movement.reason" class="mb-2">
            <p class="text-sm text-gray-600">Reason:</p>
            <p class="font-semibold">{{ movement.reason }}</p>
          </div>
          <div v-if="movement.notes">
            <p class="text-sm text-gray-600">Notes:</p>
            <p>{{ movement.notes }}</p>
          </div>
        </div>
      </div>
    </div>

    <div v-else class="text-center py-8">
      <p class="text-gray-500">No movement selected</p>
    </div>
  </a-modal>
</template>
