<script setup>
import { ref } from "vue";
import {
  IconCalendar,
  IconCurrencyDollar,
  IconTarget,
  IconClock,
  IconEye,
  IconPercentage,
  IconTag,
  IconShoppingCart,
  IconCategory,
  IconPackage,
  IconX,
  IconInfoCircle,
} from "@tabler/icons-vue";

// Sample data - replace with your actual data fetching
const sampleDiscounts = [
  {
    id: 1,
    name: "Summer Sale 2024",
    type: "percentage",
    scope: "order",
    value: 25.0,
    min_order_amount: 100.0,
    start_date: "2024-06-01T00:00:00Z",
    end_date: "2024-08-31T23:59:59Z",
    is_active: true,
  },
  {
    id: 2,
    name: "New Customer Welcome",
    type: "fixed",
    scope: "product",
    value: 15.0,
    min_order_amount: null,
    start_date: "2024-01-01T00:00:00Z",
    end_date: null,
    is_active: true,
  },
  {
    id: 3,
    name: "Category Clearance",
    type: "percentage",
    scope: "category",
    value: 40.0,
    min_order_amount: 50.0,
    start_date: "2024-03-15T00:00:00Z",
    end_date: "2024-04-15T23:59:59Z",
    is_active: false,
  },
];

const selectedDiscount = ref(null);

function formatDate(dateString) {
  if (!dateString) return "No end date";
  return new Date(dateString).toLocaleDateString("en-US", {
    year: "numeric",
    month: "long",
    day: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

function formatValue(type, value) {
  return type === "percentage" ? `${value}%` : `$${value.toFixed(2)}`;
}

function getScopeColor(scope) {
  switch (scope) {
    case "order":
      return "bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300";
    case "product":
      return "bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300";
    case "category":
      return "bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300";
    default:
      return "bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-300";
  }
}

function getTypeColor(type) {
  return type === "percentage"
    ? "bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-300"
    : "bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-300";
}
</script>

<template>
  <div class="space-y-6">
    <div class="grid gap-6 sm:grid-cols-1 md:grid-cols-2 xl:grid-cols-3">
      <div
        v-for="discount in sampleDiscounts"
        :key="discount.id"
        class="group hover:shadow-xl transition-all duration-300 hover:-translate-y-1 border-0 shadow-md rounded-xl bg-white dark:bg-gray-900"
      >
        <div class="p-4 border-b border-gray-200 dark:border-gray-700">
          <div class="flex items-start justify-between">
            <div>
              <h2
                class="text-lg font-semibold text-foreground group-hover:text-primary transition-colors"
              >
                {{ discount.name }}
              </h2>
              <div class="flex items-center gap-2 text-muted-foreground">
                <component
                  :is="
                    discount.type === 'percentage'
                      ? IconPercentage
                      : IconCurrencyDollar
                  "
                  class="h-4 w-4"
                />
                <span class="text-sm capitalize"
                  >{{ discount.type }} discount</span
                >
              </div>
            </div>
            <span
              class="px-2 py-1 rounded text-xs font-medium"
              :class="
                discount.is_active
                  ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400'
                  : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400'
              "
            >
              {{ discount.is_active ? "Active" : "Inactive" }}
            </span>
          </div>
          <div class="flex gap-2 mt-3">
            <span
              class="px-2 py-1 rounded text-xs font-medium"
              :class="getTypeColor(discount.type)"
            >
              {{
                discount.type === "percentage" ? "Percentage" : "Fixed Amount"
              }}
            </span>
            <span
              class="px-2 py-1 rounded text-xs font-medium flex items-center gap-1"
              :class="getScopeColor(discount.scope)"
            >
              <IconShoppingCart
                v-if="discount.scope === 'order'"
                class="h-3 w-3"
              />
              <IconPackage
                v-if="discount.scope === 'product'"
                class="h-3 w-3"
              />
              <IconCategory
                v-if="discount.scope === 'category'"
                class="h-3 w-3"
              />
              {{
                discount.scope.charAt(0).toUpperCase() + discount.scope.slice(1)
              }}
            </span>
          </div>
        </div>

        <div class="p-4 space-y-4">
          <div
            class="flex items-center justify-between p-3 bg-gradient-to-r from-primary/5 to-primary/10 rounded-lg border border-primary/20"
          >
            <div class="flex items-center gap-2 text-muted-foreground">
              <IconTag class="h-4 w-4" />
              <span class="text-sm font-medium">Discount Value</span>
            </div>
            <span class="text-2xl font-bold text-primary">
              {{ formatValue(discount.type, discount.value) }}
            </span>
          </div>

          <div
            v-if="discount.min_order_amount"
            class="flex items-center justify-between p-2 bg-muted/50 rounded-md"
          >
            <div class="flex items-center gap-2 text-muted-foreground">
              <IconTarget class="h-4 w-4" />
              <span class="text-sm">Min. Order</span>
            </div>
            <span class="font-semibold"
              >${{ discount.min_order_amount.toFixed(2) }}</span
            >
          </div>

          <div class="space-y-2 p-3 bg-muted/30 rounded-lg">
            <div class="flex items-center gap-2 text-muted-foreground mb-2">
              <IconClock class="h-4 w-4" />
              <span class="text-sm font-medium">Validity Period</span>
            </div>
            <div class="space-y-1.5 text-sm">
              <div class="flex items-center justify-between">
                <span class="flex items-center gap-1 text-muted-foreground">
                  <IconCalendar class="h-3 w-3" /> Start
                </span>
                <span class="font-medium">{{
                  discount.start_date
                    ? formatDate(discount.start_date)
                    : "No start date"
                }}</span>
              </div>
              <div class="flex items-center justify-between">
                <span class="flex items-center gap-1 text-muted-foreground">
                  <IconCalendar class="h-3 w-3" /> End
                </span>
                <span class="font-medium">{{
                  discount.end_date
                    ? formatDate(discount.end_date)
                    : "No end date"
                }}</span>
              </div>
            </div>
          </div>

          <button
            class="w-full bg-primary hover:bg-primary/90 text-primary-foreground rounded-md py-2 flex items-center justify-center gap-2 shadow-sm hover:shadow-md transition-all"
            @click="selectedDiscount = discount"
          >
            <IconEye class="h-4 w-4" />
            View Details
          </button>
        </div>
      </div>
    </div>

    <!-- Detailed View -->
    <div
      v-if="selectedDiscount"
      class="bg-card shadow-2xl border-2 border-primary/20 rounded-xl"
    >
      <div
        class="flex items-center justify-between p-4 bg-gradient-to-r from-primary/10 to-primary/5 border-b border-primary/20"
      >
        <div class="flex items-center gap-3">
          <div class="p-2 bg-primary/20 rounded-lg">
            <IconInfoCircle class="h-6 w-6 text-primary" />
          </div>
          <div>
            <h2 class="text-2xl text-foreground">
              {{ selectedDiscount.name }}
            </h2>
            <p class="text-muted-foreground mt-1">
              Detailed discount information
            </p>
          </div>
        </div>
        <button
          @click="selectedDiscount = null"
          class="hover:bg-destructive/10 hover:text-destructive rounded p-2"
        >
          <IconX class="h-4 w-4" />
        </button>
      </div>

      <div class="p-6 space-y-6">
        <div class="flex flex-wrap items-center gap-3">
          <span
            class="px-3 py-1 rounded text-sm font-medium"
            :class="
              selectedDiscount.is_active
                ? 'bg-emerald-100 text-emerald-700'
                : 'bg-gray-100 text-gray-600'
            "
          >
            {{ selectedDiscount.is_active ? "Active" : "Inactive" }}
          </span>
          <span
            class="px-3 py-1 rounded text-sm flex items-center gap-1"
            :class="getTypeColor(selectedDiscount.type)"
          >
            <IconPercentage
              v-if="selectedDiscount.type === 'percentage'"
              class="h-3 w-3"
            />
            <IconCurrencyDollar v-else class="h-3 w-3" />
            {{
              selectedDiscount.type === "percentage"
                ? "Percentage"
                : "Fixed Amount"
            }}
          </span>
          <span
            class="px-3 py-1 rounded text-sm flex items-center gap-1"
            :class="getScopeColor(selectedDiscount.scope)"
          >
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
              selectedDiscount.scope.charAt(0).toUpperCase() +
              selectedDiscount.scope.slice(1)
            }}
            Level
          </span>
        </div>

        <!-- Value & Min Order -->
        <div class="grid gap-4 md:grid-cols-2">
          <div
            class="flex items-center gap-4 p-4 bg-gradient-to-br from-primary/5 to-primary/10 rounded-xl border border-primary/20"
          >
            <div class="p-3 bg-primary/20 rounded-lg">
              <IconCurrencyDollar class="h-6 w-6 text-primary" />
            </div>
            <div>
              <p class="text-sm text-muted-foreground">Discount Value</p>
              <p class="text-2xl font-bold text-primary">
                {{ formatValue(selectedDiscount.type, selectedDiscount.value) }}
              </p>
            </div>
          </div>

          <div
            class="flex items-center gap-4 p-4 bg-gradient-to-br from-muted/50 to-muted/30 rounded-xl border"
          >
            <div class="p-3 bg-muted rounded-lg">
              <IconTarget class="h-6 w-6 text-muted-foreground" />
            </div>
            <div>
              <p class="text-sm text-muted-foreground">Minimum Order</p>
              <p class="text-2xl font-bold">
                {{
                  selectedDiscount.min_order_amount
                    ? `$${selectedDiscount.min_order_amount.toFixed(2)}`
                    : "No minimum"
                }}
              </p>
            </div>
          </div>
        </div>

        <!-- Dates -->
        <div class="space-y-4">
          <h3 class="text-lg font-semibold flex items-center gap-2">
            <IconClock class="h-5 w-5 text-primary" /> Validity Period
          </h3>
          <div class="grid gap-4 md:grid-cols-2">
            <div
              class="flex items-center gap-4 p-4 border rounded-xl hover:border-primary/30"
            >
              <div class="p-2 bg-primary/10 rounded-lg">
                <IconCalendar class="h-5 w-5 text-primary" />
              </div>
              <div>
                <p class="text-sm text-muted-foreground">Start Date</p>
                <p class="font-semibold">
                  {{
                    selectedDiscount.start_date
                      ? formatDate(selectedDiscount.start_date)
                      : "No start date"
                  }}
                </p>
              </div>
            </div>
            <div
              class="flex items-center gap-4 p-4 border rounded-xl hover:border-primary/30"
            >
              <div class="p-2 bg-primary/10 rounded-lg">
                <IconCalendar class="h-5 w-5 text-primary" />
              </div>
              <div>
                <p class="text-sm text-muted-foreground">End Date</p>
                <p class="font-semibold">
                  {{
                    selectedDiscount.end_date
                      ? formatDate(selectedDiscount.end_date)
                      : "No end date"
                  }}
                </p>
              </div>
            </div>
          </div>
        </div>

        <!-- Summary -->
        <div
          class="p-6 bg-gradient-to-br from-muted/30 to-muted/10 rounded-xl border"
        >
          <h4 class="font-semibold mb-3 flex items-center gap-2">
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
            <span class="font-semibold text-primary">{{
              formatValue(selectedDiscount.type, selectedDiscount.value)
            }}</span>
            off
            <span v-if="selectedDiscount.min_order_amount">
              on orders over ${{
                selectedDiscount.min_order_amount.toFixed(2)
              }} </span
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
  </div>
</template>
