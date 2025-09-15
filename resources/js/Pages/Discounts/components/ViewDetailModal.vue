<script setup>
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import {
  IconCalendar,
  IconCurrencyDollar,
  IconTarget,
  IconClock,
  IconPercentage,
  IconShoppingCart,
  IconCategory,
  IconPackage,
  IconX,
  IconInfoCircle,
  IconEye,
  IconCurrencyPeso,
} from "@tabler/icons-vue";

import { useHelpers } from "@/Composables/useHelpers";

const { formattedTotal } = useHelpers();

const { openViewModal } = useGlobalVariables();

const props = defineProps({
  selectedDiscount: {
    type: Object,
    default: () => ({}), // ✅ must be a function
  },
});

const getTypeColor = (type) => {
  return type === "percentage"
    ? "bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-300"
    : "bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-300";
};

const getScopeColor = (scope) => {
  switch (scope) {
    case "order":
      return "bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300";
    case "product":
      return "bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300";
    case "category":
      return "bg-pink-100 text-pink-800 dark:bg-pink-900 dark:text-pink-300";
    default:
      return "bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300";
  }
};

const formatValue = (type, value) => {
  if (!value) return "0";
  return type === "percentage" ? `${value}%` : formattedTotal(Number(value));
};

const formatDate = (dateStr) => {
  if (!dateStr) return "—";
  const d = new Date(dateStr);
  return d.toLocaleDateString("en-US", {
    year: "numeric",
    month: "long",
    day: "numeric",
  });
};
</script>

<template>
  <a-modal
    v-model:visible="openViewModal"
    title="Discount Details"
    @cancel="openViewModal = false"
    width="700px"
  >
    <div>
      <!-- Body -->
      <div class="space-y-4">
        <!-- Status + Type + Scope -->
        <div class="flex flex-wrap items-center">
          <a-tag :color="selectedDiscount.is_active ? 'green' : 'gray'">{{
            selectedDiscount.is_active ? "Active" : "Inactive"
          }}</a-tag>
          <a-tag color="green">
            {{
              selectedDiscount.type === "percentage"
                ? "Percentage"
                : "Fixed Amount"
            }}</a-tag
          >

          <a-tag color="purple">
            <IconShoppingCart
              v-if="selectedDiscount.scope === 'order'"
              class="h-3 w-3"
            />
            <IconPackage
              v-if="selectedDiscount.scope === 'product'"
              class="h-3 w-3"
            />
            <IconCategory
              v-if="selectedDiscount.scope === 'category'"
              class="h-3 w-3"
            />
            {{
              selectedDiscount.scope
                ? selectedDiscount.scope.charAt(0).toUpperCase() +
                  selectedDiscount.scope.slice(1)
                : ""
            }}
            Level
          </a-tag>
        </div>

        <!-- Value & Min Order -->
        <div class="grid gap-4 md:grid-cols-2">
          <div
            class="flex items-center gap-4 bg-gradient-to-br from-primary/5 to-primary/10 rounded-xl border border-primary/20"
          >
            <div class="p-2 bg-primary/20 rounded-lg">
              <IconCurrencyDollar class="h-6 w-6 text-primary" />
            </div>
            <div class="flex flex-col ">
              <span class="text-sm text-muted-foreground">Discount Value</span>
              <span class="text-2xl font-bold text-primary text-green-700">
                {{ formatValue(selectedDiscount.type, selectedDiscount.value) }}
              </span>
            </div>
          </div>

          <div
            class="flex items-center gap-4 py-4 bg-gradient-to-br from-muted/50 to-muted/30 rounded-xl border"
          >
            <div class="p-2 bg-muted rounded-lg">
              <IconTarget class="h-6 w-6 text-muted-foreground" />
            </div>
            <div>
              <div class="text-sm text-muted-foreground">Minimum Order</div>
              <div class="text-2xl font-bold text-green-700">
                {{
                  selectedDiscount.min_order_amount
                    ? `$${Number(selectedDiscount.min_order_amount).toFixed(2)}`
                    : "No minimum"
                }}
              </div>
            </div>
          </div>
        </div>

        <!-- Dates -->
        <div class="">
          <h3
            class="text-lg font-semibold flex items-center gap-2 text-green-700"
          >
            <IconClock class="h-5 w-5 text-primary" /> Validity Period
          </h3>
          <div class="grid gap-4 md:grid-cols-2">
            <div
              class="flex items-center gap-4 border rounded-xl hover:border-primary/30"
            >
              <div class="p-2 bg-primary/10 rounded-lg">
                <IconCalendar class="h-5 w-5 text-primary" />
              </div>
              <div class="space-y-2">
                <div class="text-sm text-muted-foreground">Start Date</div>
                <div class="font-semibold">
                  {{
                    selectedDiscount.start_date
                      ? formatDate(selectedDiscount.start_date)
                      : "No start date"
                  }}
                </div>
              </div>
            </div>
            <div
              class="flex items-center gap-4 py-2 border rounded-xl hover:border-primary/30"
            >
              <div class="p-2 bg-primary/10 rounded-lg">
                <IconCalendar class="h-5 w-5 text-primary" />
              </div>
              <div class="space-y-2">
                <div class="text-sm text-muted-foreground">End Date</div>
                <div class="font-semibold">
                  {{
                    selectedDiscount.end_date
                      ? formatDate(selectedDiscount.end_date)
                      : "No end date"
                  }}
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Summary -->
        <div
          class="p-4 bg-gradient-to-br from-muted/30 to-muted/10 rounded-xl border"
        >
          <h4 class="font-semibold mb-3 flex items-center gap-2 text-green-700">
            <IconInfoCircle class="h-4 w-4 text-primary" /> Discount Summary
          </h4>
          <p class="text-sm text-muted-foreground leading-relaxed">
            This
            {{
              selectedDiscount.type === "percentage"
                ? "percentage"
                : "fixed amount"
            }}
            discount applies to {{ selectedDiscount.scope }} level purchases,
            offering
            <span class="font-semibold text-primary text-green-700">{{
              formatValue(selectedDiscount.type, selectedDiscount.value)
            }}</span>
            off
            <span v-if="selectedDiscount.min_order_amount">
              on orders over
              <span class="text-green-700">
                {{ formattedTotal(Number(selectedDiscount.min_order_amount)) }}
              </span> </span
            >.
            {{
              selectedDiscount.is_active
                ? " Currently active."
                : " Currently inactive."
            }}
          </p>
        </div>
      </div>
    </div>

    <template #footer>
      <a-button @click="openViewModal = false">Cancel</a-button>
    </template>
  </a-modal>
</template>
