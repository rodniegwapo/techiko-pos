<template>
  <div class="p-6">
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
      <!-- Tier Distribution -->
      <div class="bg-white border rounded-lg p-4">
        <h3 class="text-lg font-medium text-gray-900 mb-4">
          Tier Distribution
        </h3>
        <div class="space-y-3">
          <div
            v-for="tier in tierStats"
            :key="tier.tier"
            class="flex items-center justify-between p-3 bg-gray-50 rounded-lg"
          >
            <div class="flex items-center">
              <div
                class="w-4 h-4 rounded-full mr-3"
                :style="{ backgroundColor: getTierColor(tier.tier) }"
              ></div>
              <span class="font-medium capitalize">{{ tier.tier }}</span>
            </div>
            <div class="text-right">
                <div class="font-medium">{{ tier.count || 0 }}</div>
                <div class="text-xs text-gray-500">
                  {{ 
                    (() => {
                      const count = Number(tier.count) || 0;
                      const total = Number(totalCustomers) || 0;
                      if (total === 0) return '0.0';
                      return ((count / total) * 100).toFixed(1);
                    })()
                  }}%
                </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Points Overview -->
      <div class="bg-white border rounded-lg p-4">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Points Overview</h3>
        <div class="grid grid-cols-2 gap-4">
          <div class="bg-blue-50 rounded-lg p-4 text-center border">
            <div class="text-2xl font-bold text-blue-600">
              {{ stats.total_points_issued?.toLocaleString() || 0 }}
            </div>
            <div class="text-sm text-blue-800">Total Points Issued</div>
          </div>
          <div class="bg-green-50 rounded-lg p-4 text-center border">
            <div class="text-2xl font-bold text-green-600">
              {{ stats.total_points_redeemed?.toLocaleString() || 0 }}
            </div>
            <div class="text-sm text-green-800">Points Redeemed</div>
          </div>
          <div class="bg-purple-50 rounded-lg p-4 text-center border">
            <div class="text-2xl font-bold text-purple-600">
              {{ stats.active_points?.toLocaleString() || 0 }}
            </div>
            <div class="text-sm text-purple-800">Active Points</div>
          </div>
          <div class="bg-orange-50 rounded-lg p-4 text-center border">
            <div class="text-2xl font-bold text-orange-600">
              {{
                (() => {
                  const issued = Number(stats.total_points_issued) || 0;
                  const redeemed = Number(stats.total_points_redeemed) || 0;
                  if (issued === 0) return '0.0';
                  return ((redeemed / issued) * 100).toFixed(1);
                })()
              }}%
            </div>
            <div class="text-sm text-orange-800">Redemption Rate</div>
          </div>
        </div>
      </div>

      <!-- Revenue Impact -->
      <div class="bg-white border rounded-lg p-4">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Revenue Impact</h3>
        <div class="space-y-4">
          <div class="bg-gray-50 rounded-lg p-4 border">
            <div class="flex justify-between items-center mb-2">
              <span class="text-sm text-gray-600">Loyalty Member Sales</span>
              <span class="font-medium"
                >₱{{ stats.loyalty_member_sales?.toLocaleString() || 0 }}</span
              >
            </div>
            <div class="flex justify-between items-center mb-2">
              <span class="text-sm text-gray-600">Non-Member Sales</span>
              <span class="font-medium"
                >₱{{ stats.non_member_sales?.toLocaleString() || 0 }}</span
              >
            </div>
            <div class="border-t pt-2 flex justify-between items-center">
              <span class="font-medium">Member Contribution</span>
              <span class="font-bold text-green-600">
                {{
                  (() => {
                    const memberSales = Number(stats.loyalty_member_sales) || 0;
                    const nonMemberSales = Number(stats.non_member_sales) || 0;
                    const totalSales = memberSales + nonMemberSales;
                    if (totalSales === 0) return '0.0';
                    return ((memberSales / totalSales) * 100).toFixed(1);
                  })()
                }}%
              </span>
            </div>
          </div>

          <div class="bg-gray-50 rounded-lg p-4 border">
            <div class="flex justify-between items-center mb-2 ">
              <span class="text-sm text-gray-600">Avg. Member Transaction</span>
              <span class="font-medium"
                >₱{{
                  stats.avg_member_transaction?.toLocaleString() || 0
                }}</span
              >
            </div>
            <div class="flex justify-between items-center">
              <span class="text-sm text-gray-600"
                >Avg. Non-Member Transaction</span
              >
              <span class="font-medium"
                >₱{{
                  stats.avg_non_member_transaction?.toLocaleString() || 0
                }}</span
              >
            </div>
          </div>
        </div>
      </div>

      <!-- Recent Activity -->
      <div class="bg-white border rounded-lg p-4">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Recent Activity</h3>
        <div class="space-y-3 max-h-64 overflow-y-auto">
          <div
            v-for="activity in recentActivity"
            :key="activity.id"
            class="flex items-center justify-between p-3 bg-gray-50 rounded-lg border"
          >
            <div class="flex items-center">
              <div
                class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center mr-3"
              >
                <user-outlined class="text-blue-600 text-sm" />
              </div>
              <div>
                <div class="font-medium text-sm">
                  {{ activity.customer_name }}
                </div>
                <div class="text-xs text-gray-500">{{ activity.action }}</div>
              </div>
            </div>
            <div class="text-right">
              <div
                class="text-sm font-medium"
                :class="activity.points > 0 ? 'text-green-600' : 'text-red-600'"
              >
                {{ activity.points > 0 ? "+" : "" }}{{ activity.points }}
              </div>
              <div class="text-xs text-gray-500">
                {{ formatDate(activity.created_at) }}
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from "vue";
import { UserOutlined } from "@ant-design/icons-vue";

// Props
const props = defineProps({
  stats: {
    type: Object,
    default: () => ({}),
  },
  tierStats: {
    type: Array,
    default: () => [],
  },
  recentActivity: {
    type: Array,
    default: () => [],
  },
});

// Computed
const totalCustomers = computed(() => {
  return props.tierStats.reduce((sum, tier) => sum + tier.count, 0);
});

// Methods
const getTierColor = (tier) => {
  const tierColors = {
    bronze: "#CD7F32",
    silver: "#C0C0C0",
    gold: "#FFD700",
    platinum: "#E5E4E2",
  };
  return tierColors[tier] || tierColors.bronze;
};

const formatDate = (date) => {
  return new Date(date).toLocaleDateString("en-US", {
    month: "short",
    day: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
};
</script>
