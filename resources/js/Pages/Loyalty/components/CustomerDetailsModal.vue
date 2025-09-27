<template>
  <a-modal
    :visible="visible"
    title="Customer Details"
    :footer="null"
    @cancel="$emit('close')"
    width="600px"
  >
    <div v-if="customer" class="space-y-6  max-h-[500px] overflow-scroll overflow-x-hidden">
      <!-- Customer Info -->
      <div class="bg-gray-50 rounded-lg p-4 border">
        <h4 class="font-medium text-gray-900 mb-3">Customer Information</h4>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="text-sm text-gray-500">Name</label>
            <div class="font-medium">{{ customer.name }}</div>
          </div>
          <div>
            <label class="text-sm text-gray-500">Email</label>
            <div class="font-medium">{{ customer.email || 'N/A' }}</div>
          </div>
          <div>
            <label class="text-sm text-gray-500">Phone</label>
            <div class="font-medium">{{ customer.phone || 'N/A' }}</div>
          </div>
          <div>
            <label class="text-sm text-gray-500">Date of Birth</label>
            <div class="font-medium">{{ formatDate(customer.date_of_birth) || 'N/A' }}</div>
          </div>
        </div>
      </div>

      <!-- Loyalty Status -->
      <div class="bg-gray-50 rounded-lg p-4 border">
        <h4 class="font-medium text-gray-900 mb-3">Loyalty Status</h4>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div class="text-center">
            <div class="text-2xl font-bold text-blue-600">
              {{ customer.loyalty_points?.toLocaleString() || 0 }}
            </div>
            <div class="text-sm text-gray-500">Current Points</div>
          </div>
          <div class="text-center">
            <div class="text-2xl font-bold text-green-600">
              ₱{{ customer.lifetime_spent?.toLocaleString() || 0 }}
            </div>
            <div class="text-sm text-gray-500">Lifetime Spent</div>
          </div>
          <div class="text-center">
            <div class="text-2xl font-bold" :style="{ color: getTierColor(customer.tier) }">
              {{ customer.tier ? customer.tier.charAt(0).toUpperCase() + customer.tier.slice(1) : 'Bronze' }}
            </div>
            <div class="text-sm text-gray-500">Current Tier</div>
          </div>
        </div>
      </div>

      <!-- Tier Progress -->
      <div class="bg-gray-50 rounded-lg p-4 border">
        <h4 class="font-medium text-gray-900 mb-3">Tier Progress</h4>
        <div class="space-y-3">
          <div v-for="tier in tierProgress" :key="tier.name" class="flex items-center justify-between">
            <div class="flex items-center">
              <div 
                class="w-4 h-4 rounded-full mr-3"
                :style="{ backgroundColor: tier.color }"
              ></div>
              <span class="font-medium">{{ tier.name }}</span>
              <span v-if="tier.current" class="ml-2 px-2 py-1 bg-blue-100 text-blue-800 text-xs rounded-full">
                Current
              </span>
            </div>
            <div class="text-sm text-gray-500">
              ₱{{ tier.threshold.toLocaleString() }}
            </div>
          </div>
        </div>
      </div>

      <!-- Recent Activity -->
      <div class="bg-gray-50 rounded-lg p-4 border">
        <h4 class="font-medium text-gray-900 mb-3">Recent Activity</h4>
        <div class="text-sm text-gray-500">
          <div>Member since: {{ formatDate(customer.created_at) }}</div>
          <div>Last purchase: {{ formatDate(customer.updated_at) }}</div>
          <div>Total purchases: {{ customer.total_purchases || 0 }}</div>
          <div v-if="customer.tier_achieved_date">
            Tier achieved: {{ formatDate(customer.tier_achieved_date) }}
          </div>
        </div>
      </div>
    </div>
  </a-modal>
</template>

<script setup>
import { computed } from 'vue';

// Props
const props = defineProps({
  visible: {
    type: Boolean,
    default: false
  },
  customer: {
    type: Object,
    default: null
  }
});

// Emits
defineEmits(['close']);

// Computed
const tierProgress = computed(() => {
  if (!props.customer) return [];
  
  const tiers = [
    { name: 'Bronze', threshold: 0, color: '#CD7F32' },
    { name: 'Silver', threshold: 20000, color: '#C0C0C0' },
    { name: 'Gold', threshold: 50000, color: '#FFD700' },
    { name: 'Platinum', threshold: 100000, color: '#E5E4E2' }
  ];
  
  const currentTier = props.customer.tier || 'bronze';
  
  return tiers.map(tier => ({
    ...tier,
    current: tier.name.toLowerCase() === currentTier
  }));
});

// Methods
const getTierColor = (tier) => {
  const tierColors = {
    bronze: '#CD7F32',
    silver: '#C0C0C0',
    gold: '#FFD700',
    platinum: '#E5E4E2'
  };
  return tierColors[tier] || tierColors.bronze;
};

const formatDate = (date) => {
  if (!date) return null;
  return new Date(date).toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'long',
    day: 'numeric'
  });
};
</script>
