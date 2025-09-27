<script setup>
import { ref, reactive, computed } from 'vue';
import {
  PlusOutlined,
  MinusOutlined
} from '@ant-design/icons-vue';

// Props
const props = defineProps({
  visible: {
    type: Boolean,
    default: false
  },
  customer: {
    type: Object,
    default: null
  },
  adjusting: {
    type: Boolean,
    default: false
  }
});

// Emits
const emit = defineEmits(['close', 'save']);

// Form reference
const formRef = ref();

// Form data
const form = reactive({
  type: 'add',
  amount: null,
  reason: ''
});

// Form validation rules
const rules = {
  type: [
    { required: true, message: 'Please select adjustment type' }
  ],
  amount: [
    { required: true, message: 'Please enter points amount' },
    { type: 'number', min: 1, message: 'Amount must be at least 1' }
  ],
  reason: [
    { required: true, message: 'Please enter reason for adjustment' }
  ]
};

// Methods
const calculateNewTotal = () => {
  const currentPoints = props.customer?.loyalty_points || 0;
  const amount = form.amount || 0;
  
  if (form.type === 'add') {
    return currentPoints + amount;
  } else {
    return Math.max(0, currentPoints - amount);
  }
};

const getTierColor = (tier) => {
  const tierColors = {
    bronze: '#CD7F32',
    silver: '#C0C0C0',
    gold: '#FFD700',
    platinum: '#E5E4E2'
  };
  return tierColors[tier] || tierColors.bronze;
};

const handleSave = async () => {
  try {
    await formRef.value.validate();
    emit('save', { ...form });
  } catch (error) {
    console.log('Validation failed:', error);
  }
};
</script>


<template>
  <a-modal
    :visible="visible"
    title="Adjust Customer Points"
    :confirm-loading="adjusting"
    @ok="handleSave"
    @cancel="$emit('close')"
    width="500px"
  >
    <div v-if="customer" class="space-y-4 max-h-[450px] overflow-scroll overflow-x-hidden">
      <!-- Customer Info -->
      <div class="bg-gray-50 rounded-lg p-3 border">
        <div class="flex items-center justify-between">
          <div>
            <div class="font-medium">{{ customer.name }}</div>
            <div class="text-sm text-gray-500">Current Points: {{ customer.loyalty_points?.toLocaleString() || 0 }}</div>
          </div>
          <a-tag :color="getTierColor(customer.tier)">
            {{ customer.tier ? customer.tier.charAt(0).toUpperCase() + customer.tier.slice(1) : 'Bronze' }}
          </a-tag>
        </div>
      </div>

      <!-- Adjustment Form -->
      <a-form
        ref="formRef"
        :model="form"
        :rules="rules"
        layout="vertical"
      >
        <a-form-item
          label="Adjustment Type"
          name="type"
        >
          <a-radio-group v-model:value="form.type">
            <a-radio-button value="add">
              <plus-outlined class="mr-1" />
              Add Points
            </a-radio-button>
            <a-radio-button value="deduct">
              <minus-outlined class="mr-1" />
              Deduct Points
            </a-radio-button>
          </a-radio-group>
        </a-form-item>

        <a-form-item
          label="Points Amount"
          name="amount"
        >
          <a-input-number
            v-model:value="form.amount"
            :min="1"
            :max="form.type === 'deduct' ? (customer.loyalty_points || 0) : 999999"
            style="width: 100%"
            placeholder="Enter points amount"
          />
          <div v-if="form.type === 'deduct'" class="text-xs text-gray-500 mt-1">
            Maximum deductible: {{ customer.loyalty_points?.toLocaleString() || 0 }} points
          </div>
        </a-form-item>

        <a-form-item
          label="Reason"
          name="reason"
        >
          <a-textarea
            v-model:value="form.reason"
            placeholder="Enter reason for adjustment..."
            :rows="3"
          />
        </a-form-item>
      </a-form>

      <!-- Preview -->
      <div class="bg-blue-50 rounded-lg p-3 border">
        <h4 class="text-sm font-medium text-blue-900 mb-2">Preview</h4>
        <div class="flex items-center justify-between text-sm">
          <span>Current Points:</span>
          <span class="font-medium">{{ customer.loyalty_points?.toLocaleString() || 0 }}</span>
        </div>
        <div class="flex items-center justify-between text-sm">
          <span>{{ form.type === 'add' ? 'Adding:' : 'Deducting:' }}</span>
          <span class="font-medium" :class="form.type === 'add' ? 'text-green-600' : 'text-red-600'">
            {{ form.type === 'add' ? '+' : '-' }}{{ form.amount?.toLocaleString() || 0 }}
          </span>
        </div>
        <div class="border-t border-blue-200 mt-2 pt-2 flex items-center justify-between font-medium">
          <span>New Total:</span>
          <span class="text-blue-600">
            {{ calculateNewTotal().toLocaleString() }}
          </span>
        </div>
      </div>
    </div>
  </a-modal>
</template>

