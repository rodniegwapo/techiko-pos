<template>
  <div class="space-y-3">
    <!-- Multiple Eligibility Warning -->
    <div v-if="hasMultipleEligibilities" class="p-3 bg-orange-50 border-l-4 border-orange-400 rounded">
      <div class="flex">
        <div class="flex-shrink-0">
          <svg class="h-5 w-5 text-orange-400" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
          </svg>
        </div>
        <div class="ml-3">
          <h3 class="text-sm font-medium text-orange-800">Multiple Discount Eligibility Detected</h3>
          <div class="mt-2 text-sm text-orange-700">
            <p>Customer qualifies for multiple mandatory discounts. Please help them choose the most beneficial option:</p>
            <ul class="mt-2 list-disc list-inside space-y-1">
              <li v-for="discount in availableDiscounts" :key="discount.id" class="flex items-center justify-between">
                <span>{{ discount.name }}</span>
                <span class="font-medium">{{ formatDiscount(discount) }}</span>
              </li>
            </ul>
            <p class="mt-2 text-xs italic">💡 Tip: If discounts are equal, let customer choose their preferred ID type.</p>
          </div>
        </div>
      </div>
    </div>

    <!-- Best Discount Recommendation -->
    <div v-if="recommendedDiscount" class="p-3 bg-green-50 border-l-4 border-green-400 rounded">
      <div class="flex">
        <div class="flex-shrink-0">
          <svg class="h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
          </svg>
        </div>
        <div class="ml-3">
          <h3 class="text-sm font-medium text-green-800">Recommended Discount</h3>
          <p class="text-sm text-green-700 mt-1">
            <strong>{{ recommendedDiscount.name }}</strong> - {{ formatDiscount(recommendedDiscount) }}
            <span class="text-xs block mt-1">This provides the best value for this order total.</span>
          </p>
        </div>
      </div>
    </div>

    <!-- Cashier Instructions -->
    <div class="p-3 bg-blue-50 border border-blue-200 rounded">
      <h4 class="text-sm font-medium text-blue-900 mb-2">📋 Cashier Instructions</h4>
      <div class="text-xs text-blue-800 space-y-1">
        <p><strong>1. Verify ID:</strong> Check valid government-issued identification</p>
        <p><strong>2. Explain Rule:</strong> "Only one mandatory discount allowed per transaction"</p>
        <p><strong>3. Guide Selection:</strong> Help customer choose the most beneficial option</p>
        <p><strong>4. Apply Discount:</strong> Select chosen discount in system</p>
        <p><strong>5. Document:</strong> Ensure receipt shows applied discount clearly</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  availableDiscounts: {
    type: Array,
    default: () => []
  },
  orderTotal: {
    type: Number,
    default: 0
  }
});

const hasMultipleEligibilities = computed(() => {
  return props.availableDiscounts.length > 1;
});

const recommendedDiscount = computed(() => {
  if (props.availableDiscounts.length <= 1) return null;
  
  // Calculate actual savings for each discount
  return props.availableDiscounts.reduce((best, current) => {
    const currentSavings = calculateSavings(current);
    const bestSavings = calculateSavings(best);
    
    return currentSavings > bestSavings ? current : best;
  });
});

const calculateSavings = (discount) => {
  if (discount.type === 'percentage') {
    return (props.orderTotal * discount.amount) / 100;
  }
  return Math.min(discount.amount, props.orderTotal);
};

const formatDiscount = (discount) => {
  if (discount.type === 'percentage') {
    return `${discount.amount}% off`;
  }
  return `₱${discount.amount} off`;
};
</script>
