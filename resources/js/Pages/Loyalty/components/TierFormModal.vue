<template>
  <a-modal
    :visible="visible"
    :title="editingTier ? 'Edit Tier' : 'Add New Tier'"
    :confirm-loading="saving"
    @ok="handleSave"
    @cancel="handleCancel"
    width="600px"
  >
    <a-form
      ref="formRef"
      :model="form"
      :rules="rules"
      layout="vertical"
      @finish="handleSave"
    >
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <a-form-item
          label="Tier Name"
          name="name"
          :class="editingTier ? 'md:col-span-2' : ''"
        >
          <a-input
            v-model:value="form.name"
            placeholder="e.g., bronze, silver, gold"
            :disabled="!!editingTier"
          />
          <div v-if="editingTier" class="text-xs text-gray-500 mt-1">
            Tier name cannot be changed after creation
          </div>
        </a-form-item>

        <a-form-item
          label="Display Name"
          name="display_name"
          :class="editingTier ? '' : 'md:col-span-1'"
        >
          <a-input
            v-model:value="form.display_name"
            placeholder="e.g., Bronze, Silver, Gold"
          />
        </a-form-item>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <a-form-item
          label="Points Multiplier"
          name="multiplier"
        >
          <a-input-number
            v-model:value="form.multiplier"
            :min="1"
            :max="10"
            :step="0.25"
            :precision="2"
            style="width: 100%"
            addon-after="x"
          />
          <div class="text-xs text-gray-500 mt-1">
            Points earned = Base points × Multiplier
          </div>
        </a-form-item>

        <a-form-item
          label="Spending Threshold"
          name="spending_threshold"
        >
          <a-input-number
            v-model:value="form.spending_threshold"
            :min="0"
            :step="1000"
            :formatter="value => `₱ ${value}`.replace(/\B(?=(\d{3})+(?!\d))/g, ',')"
            :parser="value => value.replace(/₱\s?|(,*)/g, '')"
            style="width: 100%"
          />
          <div class="text-xs text-gray-500 mt-1">
            Minimum lifetime spending to reach this tier
          </div>
        </a-form-item>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <a-form-item
          label="Tier Color"
          name="color"
        >
          <div class="flex items-center space-x-3">
            <input
              v-model="form.color"
              type="color"
              class="w-12 h-10 rounded border border-gray-300 cursor-pointer"
            />
            <a-input
              v-model:value="form.color"
              placeholder="#CD7F32"
              class="font-mono"
            />
          </div>
        </a-form-item>

        <a-form-item
          label="Sort Order"
          name="sort_order"
        >
          <a-input-number
            v-model:value="form.sort_order"
            :min="1"
            :max="100"
            style="width: 100%"
          />
          <div class="text-xs text-gray-500 mt-1">
            Lower numbers appear first
          </div>
        </a-form-item>
      </div>

      <a-form-item
        label="Description"
        name="description"
      >
        <a-textarea
          v-model:value="form.description"
          placeholder="Describe the benefits of this tier..."
          :rows="3"
        />
      </a-form-item>

      <a-form-item
        name="is_active"
        v-if="editingTier"
      >
        <a-checkbox v-model:checked="form.is_active">
          Active (customers can be assigned to this tier)
        </a-checkbox>
      </a-form-item>
    </a-form>

    <!-- Preview Section -->
    <div class="mt-6 p-4 bg-gray-50 rounded-lg">
      <h4 class="text-sm font-medium text-gray-900 mb-3">Preview</h4>
      <div class="flex items-center space-x-4">
        <div 
          class="w-8 h-8 rounded-full border-2 border-white shadow-sm"
          :style="{ backgroundColor: form.color || '#CD7F32' }"
        ></div>
        <div>
          <div class="font-medium">{{ form.display_name || 'Tier Name' }}</div>
          <div class="text-sm text-gray-500">
            {{ form.multiplier || 1 }}x points • 
            ₱{{ (form.spending_threshold || 0).toLocaleString() }}+ spending
          </div>
        </div>
      </div>
      <div v-if="form.description" class="mt-2 text-sm text-gray-600">
        {{ form.description }}
      </div>
    </div>
  </a-modal>
</template>

<script setup>
import { ref, watch, reactive, computed } from 'vue';

// Props
const props = defineProps({
  visible: {
    type: Boolean,
    default: false
  },
  editingTier: {
    type: Object,
    default: null
  },
  saving: {
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
  name: '',
  display_name: '',
  multiplier: 1.0,
  spending_threshold: 0,
  color: '#CD7F32',
  description: '',
  sort_order: 1,
  is_active: true
});

// Form validation rules
const rules = computed(() => ({
  name: props.editingTier ? [] : [
    { required: true, message: 'Please enter tier name' },
    { pattern: /^[a-z]+$/, message: 'Tier name must be lowercase letters only' }
  ],
  display_name: [
    { required: true, message: 'Please enter display name' }
  ],
  multiplier: [
    { required: true, message: 'Please enter multiplier' },
    { 
      validator: (rule, value) => {
        const num = Number(value);
        if (isNaN(num)) {
          return Promise.reject('Multiplier must be a number');
        }
        if (num < 1 || num > 10) {
          return Promise.reject('Multiplier must be between 1 and 10');
        }
        return Promise.resolve();
      }
    }
  ],
  spending_threshold: [
    { required: true, message: 'Please enter spending threshold' },
    { 
      validator: (rule, value) => {
        const num = Number(value);
        if (isNaN(num)) {
          return Promise.reject('Spending threshold must be a number');
        }
        if (num < 0) {
          return Promise.reject('Spending threshold must be 0 or greater');
        }
        return Promise.resolve();
      }
    }
  ],
  color: [
    { required: true, message: 'Please select a color' },
    { pattern: /^#[0-9A-Fa-f]{6}$/, message: 'Please enter a valid hex color' }
  ],
  sort_order: [
    { required: true, message: 'Please enter sort order' },
    { 
      validator: (rule, value) => {
        const num = Number(value);
        if (isNaN(num)) {
          return Promise.reject('Sort order must be a number');
        }
        if (num < 1) {
          return Promise.reject('Sort order must be 1 or greater');
        }
        return Promise.resolve();
      }
    }
  ]
}));

// Watch for editing tier changes
watch(() => props.editingTier, (newTier) => {
  if (newTier) {
    Object.assign(form, {
      name: newTier.name,
      display_name: newTier.display_name,
      multiplier: Number(newTier.multiplier),
      spending_threshold: Number(newTier.spending_threshold),
      color: newTier.color,
      description: newTier.description || '',
      sort_order: Number(newTier.sort_order),
      is_active: Boolean(newTier.is_active)
    });
  } else {
    // Reset form for new tier
    Object.assign(form, {
      name: '',
      display_name: '',
      multiplier: 1.0,
      spending_threshold: 0,
      color: '#CD7F32',
      description: '',
      sort_order: 1,
      is_active: true
    });
  }
}, { immediate: true });

// Methods
const handleSave = async () => {
  try {
    await formRef.value.validate();
    
    // Ensure proper data types before emitting
    const tierData = {
      ...form,
      multiplier: Number(form.multiplier),
      spending_threshold: Number(form.spending_threshold),
      sort_order: Number(form.sort_order),
      is_active: Boolean(form.is_active)
    };
    
    console.log('Form data being submitted:', tierData);
    emit('save', tierData);
  } catch (error) {
    console.log('Validation failed:', error);
  }
};

const handleCancel = () => {
  emit('close');
};
</script>
