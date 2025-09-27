<template>
  <a-modal
    :visible="visible"
    :title="`Customer Details - ${customer?.name || 'Unknown'}`"
    @cancel="$emit('close')"
    width="800px"
    :footer="null"
  >
    <div v-if="customer" class="space-y-6">
      <!-- Customer Header -->
      <div class="flex items-center justify-between p-4 bg-gradient-to-r from-blue-50 to-indigo-50 rounded-lg border border-blue-200">
        <div class="flex items-center space-x-4">
          <a-avatar 
            :size="64"
            :style="{ backgroundColor: getAvatarColor(customer.name) }"
          >
            {{ getInitials(customer.name) }}
          </a-avatar>
          <div>
            <h3 class="text-xl font-semibold text-gray-900">{{ customer.name }}</h3>
            <p class="text-gray-600">{{ customer.email || 'No email provided' }}</p>
            <p class="text-gray-600">{{ customer.phone || 'No phone provided' }}</p>
          </div>
        </div>
        <div class="text-right">
          <a-button type="primary" @click="$emit('edit', customer)">
            <edit-outlined class="mr-1" />
            Edit Customer
          </a-button>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Basic Information -->
        <div class="bg-white border rounded-lg p-4">
          <h4 class="text-lg font-medium text-gray-900 mb-4 flex items-center">
            <user-outlined class="mr-2 text-blue-600" />
            Basic Information
          </h4>
          <div class="space-y-3">
            <div class="flex justify-between">
              <span class="text-gray-600">Full Name:</span>
              <span class="font-medium">{{ customer.name }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">Email:</span>
              <span class="font-medium">{{ customer.email || 'Not provided' }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">Phone:</span>
              <span class="font-medium">{{ customer.phone || 'Not provided' }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">Address:</span>
              <span class="font-medium">{{ customer.address || 'Not provided' }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">Date of Birth:</span>
              <span class="font-medium">{{ formatDate(customer.date_of_birth) || 'Not provided' }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">Member Since:</span>
              <span class="font-medium">{{ formatDate(customer.created_at) }}</span>
            </div>
          </div>
        </div>

        <!-- Loyalty Information -->
        <div class="bg-white border rounded-lg p-4">
          <h4 class="text-lg font-medium text-gray-900 mb-4 flex items-center">
            <gift-outlined class="mr-2 text-purple-600" />
            Loyalty Program
          </h4>
          
          <div v-if="customer.loyalty_points !== null" class="space-y-4">
            <!-- Tier Status -->
            <div class="flex items-center justify-between p-3 bg-gradient-to-r from-purple-50 to-pink-50 rounded-lg border border-purple-200">
              <div class="flex items-center">
                <div
                  class="w-6 h-6 rounded-full mr-3 border-2 border-white shadow-sm"
                  :style="{ backgroundColor: getTierColor(customer.tier) }"
                ></div>
                <div>
                  <div class="font-semibold capitalize">{{ customer.tier || 'Bronze' }} Member</div>
                  <div class="text-sm text-gray-600">Current Tier</div>
                </div>
              </div>
            </div>

            <!-- Points & Stats -->
            <div class="grid grid-cols-2 gap-4">
              <div class="text-center p-3 bg-blue-50 rounded-lg border border-blue-200">
                <div class="text-2xl font-bold text-blue-600">
                  {{ customer.loyalty_points?.toLocaleString() || 0 }}
                </div>
                <div class="text-sm text-blue-800">Loyalty Points</div>
              </div>
              <div class="text-center p-3 bg-green-50 rounded-lg border border-green-200">
                <div class="text-2xl font-bold text-green-600">
                  {{ customer.total_purchases || 0 }}
                </div>
                <div class="text-sm text-green-800">Total Purchases</div>
              </div>
            </div>

            <div class="space-y-2">
              <div class="flex justify-between">
                <span class="text-gray-600">Lifetime Spent:</span>
                <span class="font-medium text-green-600">₱{{ (customer.lifetime_spent || 0).toLocaleString() }}</span>
              </div>
              <div class="flex justify-between">
                <span class="text-gray-600">Tier Achieved:</span>
                <span class="font-medium">{{ formatDate(customer.tier_achieved_date) || 'N/A' }}</span>
              </div>
            </div>
          </div>
          
          <div v-else class="text-center py-8">
            <div class="text-gray-400 mb-2">
              <gift-outlined style="font-size: 48px;" />
            </div>
            <p class="text-gray-600">Not enrolled in loyalty program</p>
            <a-button type="link" class="mt-2">
              Enroll Customer
            </a-button>
          </div>
        </div>
      </div>

      <!-- Purchase History -->
      <div class="bg-white border rounded-lg p-4">
        <h4 class="text-lg font-medium text-gray-900 mb-4 flex items-center">
          <shopping-cart-outlined class="mr-2 text-green-600" />
          Recent Purchase History
        </h4>
        
        <div class="text-center py-8 text-gray-500">
          <shopping-cart-outlined style="font-size: 48px;" class="text-gray-300 mb-2" />
          <p>Purchase history feature coming soon</p>
        </div>
      </div>
    </div>
  </a-modal>
</template>

<script setup>
import { 
  UserOutlined, 
  EditOutlined, 
  GiftOutlined, 
  ShoppingCartOutlined 
} from "@ant-design/icons-vue";

// Props
const props = defineProps({
  visible: {
    type: Boolean,
    default: false,
  },
  customer: {
    type: Object,
    default: null,
  },
});

// Emits
const emit = defineEmits(["close", "edit"]);

// Methods
const getInitials = (name) => {
  if (!name) return "?";
  return name
    .split(" ")
    .map((word) => word.charAt(0))
    .join("")
    .toUpperCase()
    .slice(0, 2);
};

const getAvatarColor = (name) => {
  const colors = [
    "#f56565", "#ed8936", "#ecc94b", "#48bb78", "#38b2ac",
    "#4299e1", "#667eea", "#9f7aea", "#ed64a6", "#a0aec0"
  ];
  if (!name) return colors[0];
  const index = name.charCodeAt(0) % colors.length;
  return colors[index];
};

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
  if (!date) return null;
  return new Date(date).toLocaleDateString("en-US", {
    year: "numeric",
    month: "long",
    day: "numeric",
  });
};
</script>
