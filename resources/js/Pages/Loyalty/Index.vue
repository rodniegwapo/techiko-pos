<template>
  <Head title="Loyalty Program" />

  <AuthenticatedLayout>
    <ContentHeader class="mb-4" title="Loyalt Programs" />
    <!-- Enhanced Stats Overview with Animations -->
    <div class=" relative z-10">
      <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6 mb-6">
        <!-- Total Members Card -->
        <div
          class="group hover:shadow-2xl transition-all duration-500 hover:-translate-y-2"
        >
          <div
            class="bg-white rounded-2xl p-6 shadow-lg border border-gray-100 relative overflow-hidden"
          >
            <div
              class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-br from-blue-400/10 to-blue-600/10 rounded-full -translate-y-8 translate-x-8"
            ></div>
            <div class="relative">
              <div class="flex items-center justify-between mb-4">
                <div
                  class="w-12 h-12 bg-gradient-to-r from-blue-500 to-blue-600 rounded-xl flex items-center justify-center shadow-lg"
                >
                  <user-outlined class="text-xl text-white" />
                </div>
                <div class="text-right">
                  <div
                    class="text-2xl font-bold text-gray-900 group-hover:text-blue-600 transition-colors"
                  >
                    {{ stats.total_customers?.toLocaleString() || 0 }}
                  </div>
                  <div class="text-sm text-gray-500">Total Members</div>
                </div>
              </div>
              <div class="flex items-center text-sm">
                <div class="w-2 h-2 bg-green-400 rounded-full mr-2"></div>
                <span class="text-green-600 font-medium">+12% this month</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Active Points Card -->
        <div
          class="group hover:shadow-2xl transition-all duration-500 hover:-translate-y-2"
        >
          <div
            class="bg-white rounded-2xl p-6 shadow-lg border border-gray-100 relative overflow-hidden"
          >
            <div
              class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-br from-green-400/10 to-green-600/10 rounded-full -translate-y-8 translate-x-8"
            ></div>
            <div class="relative">
              <div class="flex items-center justify-between mb-4">
                <div
                  class="w-12 h-12 bg-gradient-to-r from-green-500 to-green-600 rounded-xl flex items-center justify-center shadow-lg"
                >
                  <gift-outlined class="text-xl text-white" />
                </div>
                <div class="text-right">
                  <div
                    class="text-2xl font-bold text-gray-900 group-hover:text-green-600 transition-colors"
                  >
                    {{ stats.total_points?.toLocaleString() || 0 }}
                  </div>
                  <div class="text-sm text-gray-500">Active Points</div>
                </div>
              </div>
              <div class="flex items-center text-sm">
                <div class="w-2 h-2 bg-green-400 rounded-full mr-2"></div>
                <span class="text-green-600 font-medium">+8% this month</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Total Spending Card -->
        <div
          class="group hover:shadow-2xl transition-all duration-500 hover:-translate-y-2"
        >
          <div
            class="bg-white rounded-2xl p-6 shadow-lg border border-gray-100 relative overflow-hidden"
          >
            <div
              class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-br from-purple-400/10 to-purple-600/10 rounded-full -translate-y-8 translate-x-8"
            ></div>
            <div class="relative">
              <div class="flex items-center justify-between mb-4">
                <div
                  class="w-12 h-12 bg-gradient-to-r from-purple-500 to-purple-600 rounded-xl flex items-center justify-center shadow-lg"
                >
                  <dollar-circle-outlined class="text-xl text-white" />
                </div>
                <div class="text-right">
                  <div
                    class="text-2xl font-bold text-gray-900 group-hover:text-purple-600 transition-colors"
                  >
                    ₱{{ stats.total_spending?.toLocaleString() || 0 }}
                  </div>
                  <div class="text-sm text-gray-500">Total Spending</div>
                </div>
              </div>
              <div class="flex items-center text-sm">
                <div class="w-2 h-2 bg-green-400 rounded-full mr-2"></div>
                <span class="text-green-600 font-medium">+15% this month</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Average Transaction Card -->
        <div
          class="group hover:shadow-2xl transition-all duration-500 hover:-translate-y-2"
        >
          <div
            class="bg-white rounded-2xl p-6 shadow-lg border border-gray-100 relative overflow-hidden"
          >
            <div
              class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-br from-orange-400/10 to-orange-600/10 rounded-full -translate-y-8 translate-x-8"
            ></div>
            <div class="relative">
              <div class="flex items-center justify-between mb-4">
                <div
                  class="w-12 h-12 bg-gradient-to-r from-orange-500 to-orange-600 rounded-xl flex items-center justify-center shadow-lg"
                >
                  <crown-outlined class="text-xl text-white" />
                </div>
                <div class="text-right">
                  <div
                    class="text-2xl font-bold text-gray-900 group-hover:text-orange-600 transition-colors"
                  >
                    ₱{{ stats.avg_transaction?.toLocaleString() || 0 }}
                  </div>
                  <div class="text-sm text-gray-500">Avg. Transaction</div>
                </div>
              </div>
              <div class="flex items-center text-sm">
                <div class="w-2 h-2 bg-green-400 rounded-full mr-2"></div>
                <span class="text-green-600 font-medium">+5% this month</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <ContentLayout>
      <template #table>
        <!-- Enhanced Tabs with Modern Design -->

        <div class="loyalty-programs">
          <a-tabs v-model:activeKey="activeTab" size="large">
            <!-- Program Rules Tab -->
            <a-tab-pane key="rules">
              <template #tab>
                <div class="flex items-center space-x-2 px-2">
                  <gift-outlined class="text-lg" />
                  <span class="font-medium">Program Rules</span>
                </div>
              </template>
              <ProgramRulesTab />
            </a-tab-pane>

            <!-- Customer Management Tab -->
            <a-tab-pane key="customers">
              <template #tab>
                <div class="flex items-center space-x-2 px-2">
                  <user-outlined class="text-lg" />
                  <span class="font-medium">Customer Management</span>
                </div>
              </template>
              <CustomerManagementTab />
            </a-tab-pane>

            <!-- Tier Management Tab -->
            <a-tab-pane key="tier-management">
              <template #tab>
                <div class="flex items-center space-x-2 px-2">
                  <crown-outlined class="text-lg" />
                  <span class="font-medium">Tier Management</span>
                </div>
              </template>
              <TierManagementTab />
            </a-tab-pane>

            <!-- Analytics Tab -->
            <a-tab-pane key="analytics">
              <template #tab>
                <div class="flex items-center space-x-2 px-2">
                  <dollar-circle-outlined class="text-lg" />
                  <span class="font-medium">Analytics</span>
                </div>
              </template>
              <AnalyticsTab
                :stats="stats"
                :tier-stats="tierStats"
                :recent-activity="recentActivity"
              />
            </a-tab-pane>
          </a-tabs>
        </div>
      </template>
    </ContentLayout>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, onMounted, computed } from "vue";
import { Head } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import ProgramRulesTab from "./components/ProgramRulesTab.vue";
import CustomerManagementTab from "./components/CustomerManagementTab.vue";
import TierManagementTab from "./components/TierManagementTab.vue";
import AnalyticsTab from "./components/AnalyticsTab.vue";
import {
  UserOutlined,
  CrownOutlined,
  GiftOutlined,
  DollarCircleOutlined,
} from "@ant-design/icons-vue";
import { notification } from "ant-design-vue";
import axios from "axios";

// Reactive data
const activeTab = ref("rules");
const stats = ref({});

// Analytics state
const tierStats = ref([]);
const recentActivity = ref([]);

// Detect if we're in a domain context
const isDomainContext = computed(() => {
  const match = window.location.pathname.match(/\/domains\/([^/]+)/);
  return match ? match[1] : null;
});

// Build API URLs based on context
const getApiUrl = (endpoint) => {
  if (isDomainContext.value) {
    return `/domains/${isDomainContext.value}/loyalty/${endpoint}`;
  }
  return `/api/loyalty/${endpoint}`;
};

// Methods
const loadStats = async () => {
  try {
    const response = await axios.get(getApiUrl("stats"));
    stats.value = response.data;
  } catch (error) {
    console.error("Failed to load stats:", error);
  }
};

// Removed loadCustomers - now handled in CustomerManagementTab

const loadAnalytics = async () => {
  try {
    const response = await axios.get(getApiUrl("analytics"));
    tierStats.value = response.data.tier_distribution || [];
    recentActivity.value = response.data.recent_activity || [];
  } catch (error) {
    console.error("Failed to load analytics:", error);
  }
};

// Lifecycle
onMounted(() => {
  loadStats();
  loadAnalytics();
});
</script>

<style scoped>
/* Background Grid Pattern */
.bg-grid-pattern {
  background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%236366f1' fill-opacity='0.1'%3E%3Ccircle cx='30' cy='30' r='2'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
}

/* Enhanced Tab Styling */
.loyalty-tabs :deep(.ant-tabs-nav) {
  background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
  border-radius: 1rem 1rem 0 0;
  padding: 0 1.5rem;
  margin: 0;
  border-bottom: 1px solid #e2e8f0;
}

.loyalty-tabs :deep(.ant-tabs-tab) {
  border: none !important;
  background: transparent !important;
  margin: 0.5rem 0.25rem 0 0.25rem !important;
  padding: 0.75rem 1rem !important;
  border-radius: 0.75rem !important;
  transition: all 0.3s ease !important;
  font-weight: 500 !important;
  color: #64748b !important;
}

.loyalty-tabs :deep(.ant-tabs-tab:hover) {
  background: rgba(99, 102, 241, 0.1) !important;
  color: #6366f1 !important;
  transform: translateY(-1px);
}

.loyalty-tabs :deep(.ant-tabs-tab-active) {
  background: linear-gradient(135deg, #6366f1, #8b5cf6) !important;
  color: white !important;
  box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3) !important;
  transform: translateY(-2px);
}

.loyalty-tabs :deep(.ant-tabs-tab-active:hover) {
  background: linear-gradient(135deg, #5b5bd6, #7c3aed) !important;
  color: white !important;
}

.loyalty-tabs :deep(.ant-tabs-content-holder) {
  background: white;
  padding: 0;
}

.loyalty-tabs :deep(.ant-tabs-ink-bar) {
  display: none !important;
}

/* Card Hover Effects */
.group:hover .absolute {
  transform: scale(1.1) rotate(5deg);
  transition: transform 0.5s ease;
}

/* Smooth Animations */
@keyframes fadeInUp {
  from {
    opacity: 0;
    transform: translateY(30px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.group {
  animation: fadeInUp 0.6s ease forwards;
}

.group:nth-child(1) {
  animation-delay: 0.1s;
}
.group:nth-child(2) {
  animation-delay: 0.2s;
}
.group:nth-child(3) {
  animation-delay: 0.3s;
}
.group:nth-child(4) {
  animation-delay: 0.4s;
}

/* Gradient Text Effect */
.gradient-text {
  background: linear-gradient(135deg, #6366f1, #8b5cf6, #ec4899);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
}

/* Enhanced Shadow Effects */
.shadow-luxury {
  box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25),
    0 0 0 1px rgba(255, 255, 255, 0.05);
}

/* Pulse Animation for Icons */
@keyframes pulse-glow {
  0%,
  100% {
    box-shadow: 0 0 0 0 rgba(99, 102, 241, 0.4);
  }
  50% {
    box-shadow: 0 0 0 8px rgba(99, 102, 241, 0);
  }
}

.group:hover .w-12.h-12 {
  animation: pulse-glow 2s infinite;
}

/* Responsive Improvements */
@media (max-width: 768px) {
  .loyalty-tabs :deep(.ant-tabs-nav) {
    padding: 0 1rem;
  }

  .loyalty-tabs :deep(.ant-tabs-tab) {
    margin: 0.25rem 0.125rem 0 0.125rem !important;
    padding: 0.5rem 0.75rem !important;
    font-size: 0.875rem !important;
  }
}

/* Loading Skeleton Effect */
@keyframes shimmer {
  0% {
    background-position: -200px 0;
  }
  100% {
    background-position: calc(200px + 100%) 0;
  }
}

.loading-shimmer {
  background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
  background-size: 200px 100%;
  animation: shimmer 1.5s infinite;
}
</style>