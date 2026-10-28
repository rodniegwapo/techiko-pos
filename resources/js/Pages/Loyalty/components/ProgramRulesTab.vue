<template>
  <div class="px-4 md:px-8">
    <div class="grid grid-cols-1 gap-6 md:gap-8 lg:grid-cols-2">
      <!-- Points System -->
      <div class="border p-4 rounded-lg">
        <h3 class="text-lg font-medium text-gray-900 mb-4">
          <gift-outlined class="mr-2" />
          Points System
        </h3>
        <div class="bg-gray-50 rounded-lg p-4 space-y-3">
          <div class="flex justify-between">
            <span class="text-sm text-gray-600">Base Rate:</span>
            <span class="text-sm font-medium">1 point per {{ peso(rules.pesos_per_point) }} spent</span>
          </div>
          <div class="flex justify-between">
            <span class="text-sm text-gray-600">Minimum Purchase:</span>
            <span class="text-sm font-medium">{{ peso(rules.pesos_per_point) }}</span>
          </div>
          <div class="flex justify-between">
            <span class="text-sm text-gray-600">Point Calculation:</span>
            <span class="text-sm font-medium"
              >Floor(Amount ÷ {{ rules.pesos_per_point }}) × Tier Multiplier</span
            >
          </div>
        </div>

        <!-- Example Calculation -->
        <div class="mt-4 p-4 bg-blue-50 rounded-lg border border-blue-200">
          <h4 class="font-semibold text-blue-900 mb-2">Example Calculation</h4>
          <div class="text-sm text-blue-800 space-y-1">
            <div><strong>Purchase:</strong> {{ peso(EXAMPLE_PURCHASE) }}</div>
            <div>
              <strong>Base Points:</strong> Floor({{ EXAMPLE_PURCHASE.toLocaleString() }} ÷ {{ rules.pesos_per_point }}) =
              {{ examplePoints.toLocaleString() }} points
            </div>
            <div v-if="exampleTier">
              <strong>{{ tierName(exampleTier) }} Tier ({{ multiplier(exampleTier) }}):</strong>
              {{ examplePoints.toLocaleString() }} × {{ Number(exampleTier.multiplier) }} =
              <strong class="text-blue-700">{{ Math.floor(examplePoints * Number(exampleTier.multiplier)).toLocaleString() }} points</strong>
            </div>
          </div>
        </div>
      </div>

      <!-- Tier System -->
      <div class="border p-4 rounded-lg">
        <div class="text-lg flex gap-2 items-center font-medium text-gray-900 mb-4">
          <crown-outlined  />
          Tier System
        </div>
        <p v-if="!tiers.length" class="text-sm text-gray-500" data-testid="rules-no-tiers">
          No active tiers. Add them on Tier Management; every customer earns at 1.0x until then.
        </p>
        <div class="space-y-3">
          <div
            v-for="tier in tiers"
            :key="tier.id"
            data-testid="rules-tier"
            class="bg-gray-50 rounded-lg p-4 border border-gray-200"
          >
            <div class="flex items-center justify-between mb-2">
              <div class="flex items-center">
                <div
                  class="w-5 h-5 rounded-full mr-3 border border-gray-300"
                  :style="{ backgroundColor: tier.color }"
                ></div>
                <span class="font-semibold text-gray-900">{{ tierName(tier) }}</span>
              </div>
              <span
                class="text-sm font-semibold text-blue-600 bg-blue-100 px-2 py-1 rounded"
                >{{ multiplier(tier) }}</span
              >
            </div>
            <div class="text-sm text-gray-700">
              {{ Number(tier.spending_threshold) > 0 ? `${peso(tier.spending_threshold)}+ spent` : "Starting tier" }}
            </div>
          </div>
        </div>
      </div>

      <!-- Redemption Rules -->
      <div class="border rounded-lg p-4">
        <div class="flex items-center gap-2 text-lg font-medium text-gray-900">
          <dollar-circle-outlined />
          Redemption Rules
        </div>

        <div class="bg-gray-50 rounded-lg p-4 space-y-3">
          <div class="flex justify-between">
            <span class="text-sm text-gray-600">Minimum Redemption:</span>
            <span class="text-sm font-medium">{{ rules.min_points_redemption.toLocaleString() }} {{ rules.min_points_redemption === 1 ? "point" : "points" }}</span>
          </div>
          <div class="flex justify-between">
            <span class="text-sm text-gray-600">Point Value:</span>
            <span class="text-sm font-medium">{{ rules.points_per_peso_redeemed.toLocaleString() }} points = ₱1.00</span>
          </div>
          <div class="flex justify-between">
            <span class="text-sm text-gray-600">Maximum per Transaction:</span>
            <span class="text-sm font-medium">{{ rules.max_redemption_percent }}% of the total after discounts</span>
          </div>
          <div class="flex justify-between">
            <span class="text-sm text-gray-600">Expiration:</span>
            <span class="text-sm font-medium">Points don't expire</span>
          </div>
        </div>

        <!-- Redemption Example -->
        <div
          class="mt-4 p-4 bg-emerald-50 rounded-lg border border-emerald-200"
        >
          <div class="flex items-center gap-2 font-semibold text-emerald-900 mb-2">
            Redemption Example
          </div>
          <div class="text-sm text-emerald-800 space-y-1">
            <div><strong>Available Points:</strong> {{ EXAMPLE_POINTS.toLocaleString() }}</div>
            <div>
              <strong>Redemption Value:</strong> {{ EXAMPLE_POINTS.toLocaleString() }} ÷ {{ rules.points_per_peso_redeemed.toLocaleString() }} =
              {{ peso(redeemValue) }}
            </div>
            <div><strong>Purchase Total:</strong> {{ peso(EXAMPLE_TOTAL) }}</div>
            <div><strong>Max Redeemable:</strong> {{ peso(maxRedeemable) }} ({{ rules.max_redemption_percent }}% of {{ peso(EXAMPLE_TOTAL) }})</div>
            <div
              class="font-bold text-emerald-700 bg-emerald-100 px-2 py-1 rounded mt-2"
            >
              Final Discount: {{ peso(Math.min(redeemValue, maxRedeemable)) }}
            </div>
          </div>
        </div>
      </div>

      <!-- Program Benefits -->
      <div class="border p-4 rounded-lg">
        <div class="flex items-center gap-2 text-lg font-medium text-gray-900 mb-4">
          <user-outlined class="mr-2" />
          Program Benefits
        </div>
        <div class="space-y-4">
          <div class="bg-gray-50 rounded-lg p-4 border">
            <h4 class="font-medium mb-2">For Customers</h4>
            <ul class="text-sm text-gray-600 space-y-1">
              <li>• Earn points on every purchase</li>
              <li>• Redeem points for discounts</li>
              <li>• Tier-based bonus multipliers</li>
              <li>• Exclusive member offers</li>
              <li>• Birthday rewards</li>
            </ul>
          </div>

          <div class="bg-gray-50 rounded-lg p-4 border">
            <h4 class="font-medium mb-2">For Business</h4>
            <ul class="text-sm text-gray-600 space-y-1">
              <li>• Increased customer retention</li>
              <li>• Higher average transaction value</li>
              <li>• Customer data insights</li>
              <li>• Targeted marketing opportunities</li>
              <li>• Competitive advantage</li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import {
  GiftOutlined,
  CrownOutlined,
  DollarCircleOutlined,
  UserOutlined,
} from "@ant-design/icons-vue";

import { computed } from "vue";
import { usePage } from "@inertiajs/vue3";

// The rules points are really earned and redeemed by, and the organization's active tiers from
// Tier Management (sent by the page, not written here, so the two can't drift apart).
const page = usePage();
const rules = computed(() => ({
  pesos_per_point: 10,
  points_per_peso_redeemed: 100,
  max_redemption_percent: 50,
  min_points_redemption: 1,
  ...(page.props.rules ?? {}),
}));
const tiers = computed(() => page.props.tiers ?? []);

const peso = (amount) =>
  `₱${Number(amount).toLocaleString("en-PH", { minimumFractionDigits: 0, maximumFractionDigits: 2 })}`;
const tierName = (tier) => tier.display_name || tier.name;
const multiplier = (tier) => `${Number(tier.multiplier)}x`;

const EXAMPLE_PURCHASE = 1250;
const examplePoints = computed(() => Math.floor(EXAMPLE_PURCHASE / rules.value.pesos_per_point));
// The second tier, so the example shows a multiplier above 1x when there is one.
const exampleTier = computed(() => tiers.value[1] ?? tiers.value[0] ?? null);

const EXAMPLE_POINTS = 1500;
const EXAMPLE_TOTAL = 500;
const redeemValue = computed(() => Math.round((EXAMPLE_POINTS / rules.value.points_per_peso_redeemed) * 100) / 100);
const maxRedeemable = computed(() => Math.round(EXAMPLE_TOTAL * rules.value.max_redemption_percent) / 100);
</script>
