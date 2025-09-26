<script setup>
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useHelpers } from "@/Composables/useHelpers";
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

const { formattedTotal } = useHelpers();
const { openViewModal } = useGlobalVariables();

const props = defineProps({
  selectedMandatoryDiscount: {
    type: Object,
    default: () => ({}),
  },
});

const getTypeColor = (type) => {
  return type === "percentage"
    ? "bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-300"
    : "bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-300";
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
    title="Mandatory Discount Details"
    @cancel="openViewModal = false"
    width="700px"
  >
    <div>
      <!-- Body -->
      <div class="space-y-4">
        <!-- Status + Type Tags -->
        <div class="flex flex-wrap items-center gap-2">
          <a-tag :color="selectedMandatoryDiscount.is_active ? 'green' : 'gray'">
            {{ selectedMandatoryDiscount.is_active ? "Active" : "Inactive" }}
          </a-tag>
          <a-tag color="green">
            {{
              selectedMandatoryDiscount.type === "percentage"
                ? "Percentage"
                : "Fixed Amount"
            }}
          </a-tag>
          <a-tag color="purple">
            <IconCategory class="h-3 w-3" />
            Mandatory Level
          </a-tag>
        </div>

        <!-- Value Display -->
        <div class="grid gap-4 md:grid-cols-1">
          <div
            class="flex items-center gap-4 bg-gradient-to-br from-primary/5 to-primary/10 rounded-xl border border-primary/20 p-4"
          >
            <div class="p-2 bg-primary/20 rounded-lg">
              <IconCurrencyDollar class="h-6 w-6 text-primary" />
            </div>
            <div class="flex flex-col">
              <span class="text-sm text-muted-foreground">Discount Value</span>
              <span class="text-2xl font-bold text-primary text-green-700">
                {{ formatValue(selectedMandatoryDiscount.type, selectedMandatoryDiscount.value) }}
              </span>
            </div>
          </div>
        </div>

        <!-- Dates -->
        <div class="">
          <h3
            class="text-lg font-semibold flex items-center gap-2 text-green-700 mb-4"
          >
            <IconClock class="h-5 w-5 text-primary" /> Record Information
          </h3>
          <div class="grid gap-4 md:grid-cols-2">
            <div
              class="flex items-center gap-4 border rounded-xl hover:border-primary/30 p-3"
            >
              <div class="p-2 bg-primary/10 rounded-lg">
                <IconCalendar class="h-5 w-5 text-primary" />
              </div>
              <div class="space-y-1">
                <div class="text-sm text-muted-foreground">Created Date</div>
                <div class="font-semibold">
                  {{ formatDate(selectedMandatoryDiscount.created_at) }}
                </div>
              </div>
            </div>
            <div
              class="flex items-center gap-4 border rounded-xl hover:border-primary/30 p-3"
            >
              <div class="p-2 bg-primary/10 rounded-lg">
                <IconCalendar class="h-5 w-5 text-primary" />
              </div>
              <div class="space-y-1">
                <div class="text-sm text-muted-foreground">Last Updated</div>
                <div class="font-semibold">
                  {{ formatDate(selectedMandatoryDiscount.updated_at) }}
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
            This mandatory
            {{
              selectedMandatoryDiscount.type === "percentage"
                ? "percentage"
                : "fixed amount"
            }}
            discount is designed for eligible customers (Senior Citizens, PWD, Students, etc.), 
            offering
            <span class="font-semibold text-primary text-green-700">{{
              formatValue(selectedMandatoryDiscount.type, selectedMandatoryDiscount.value)
            }}</span>
            off the total order amount.
            {{
              selectedMandatoryDiscount.is_active
                ? " Currently active and available for use."
                : " Currently inactive and not available."
            }}
          </p>
        </div>
      </div>
    </div>

    <template #footer>
      <a-button @click="openViewModal = false">Close</a-button>
    </template>
  </a-modal>
</template>
