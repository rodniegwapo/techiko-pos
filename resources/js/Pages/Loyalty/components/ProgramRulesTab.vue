<template>
  <div class="px-8">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
      <!-- Points System -->
      <div class="border p-4 rounded-lg">
        <h3 class="text-lg font-medium text-gray-900 mb-4">
          <gift-outlined class="mr-2" />
          Points System
        </h3>
        <div class="bg-gray-50 rounded-lg p-4 space-y-3">
          <div class="flex justify-between">
            <span class="text-sm text-gray-600">Base Rate:</span>
            <span class="text-sm font-medium">1 point per ₱10 spent</span>
          </div>
          <div class="flex justify-between">
            <span class="text-sm text-gray-600">Minimum Purchase:</span>
            <span class="text-sm font-medium">₱10</span>
          </div>
          <div class="flex justify-between">
            <span class="text-sm text-gray-600">Point Calculation:</span>
            <span class="text-sm font-medium"
              >Floor(Amount ÷ 10) × Tier Multiplier</span
            >
          </div>
        </div>

        <!-- Example Calculation -->
        <div class="mt-4 p-4 bg-blue-50 rounded-lg border border-blue-200">
          <h4 class="font-semibold text-blue-900 mb-2">Example Calculation</h4>
          <div class="text-sm text-blue-800 space-y-1">
            <div><strong>Purchase:</strong> ₱1,250</div>
            <div>
              <strong>Base Points:</strong> Floor(1,250 ÷ 10) = 125 points
            </div>
            <div>
              <strong>Silver Tier (1.25x):</strong> 125 × 1.25 =
              <strong class="text-blue-700">156 points</strong>
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
        <div class="space-y-3">
          <div
            v-for="tier in tiers"
            :key="tier.name"
            class="bg-gray-50 rounded-lg p-4 border border-gray-200"
          >
            <div class="flex items-center justify-between mb-2">
              <div class="flex items-center">
                <div
                  class="w-5 h-5 rounded-full mr-3 border border-gray-300"
                  :style="{ backgroundColor: tier.color }"
                ></div>
                <span class="font-semibold text-gray-900">{{ tier.name }}</span>
              </div>
              <span
                class="text-sm font-semibold text-blue-600 bg-blue-100 px-2 py-1 rounded"
                >{{ tier.multiplier }}</span
              >
            </div>
            <div class="text-sm text-gray-700">{{ tier.requirement }}</div>
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
            <span class="text-sm font-medium">100 points</span>
          </div>
          <div class="flex justify-between">
            <span class="text-sm text-gray-600">Point Value:</span>
            <span class="text-sm font-medium">1 point = ₱0.10</span>
          </div>
          <div class="flex justify-between">
            <span class="text-sm text-gray-600">Maximum per Transaction:</span>
            <span class="text-sm font-medium">50% of total</span>
          </div>
          <div class="flex justify-between">
            <span class="text-sm text-gray-600">Expiration:</span>
            <span class="text-sm font-medium">2 years from earning</span>
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
            <div><strong>Available Points:</strong> 1,500</div>
            <div><strong>Redemption Value:</strong> 1,500 × ₱0.10 = ₱150</div>
            <div><strong>Purchase Total:</strong> ₱500</div>
            <div><strong>Max Redeemable:</strong> ₱250 (50% of ₱500)</div>
            <div
              class="font-bold text-emerald-700 bg-emerald-100 px-2 py-1 rounded mt-2"
            >
              Final Discount: ₱150
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

// Tier configuration
const tiers = [
  {
    name: "Bronze",
    requirement: "Default tier",
    multiplier: "1.0x",
    color: "#CD7F32",
  },
  {
    name: "Silver",
    requirement: "₱20,000+ spent",
    multiplier: "1.25x",
    color: "#C0C0C0",
  },
  {
    name: "Gold",
    requirement: "₱50,000+ spent",
    multiplier: "1.5x",
    color: "#FFD700",
  },
  {
    name: "Platinum",
    requirement: "₱100,000+ spent",
    multiplier: "2.0x",
    color: "#E5E4E2",
  },
];
</script>
