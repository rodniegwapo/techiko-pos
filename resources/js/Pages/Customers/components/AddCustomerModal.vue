<template>
  <a-modal
    :visible="visible"
    :title="isEdit ? 'Edit Customer' : 'Add New Customer'"
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
          label="Full Name"
          name="name"
          class="md:col-span-2"
        >
          <a-input
            v-model:value="form.name"
            placeholder="Enter customer's full name"
          />
        </a-form-item>

        <a-form-item
          label="Email Address"
          name="email"
        >
          <a-input
            v-model:value="form.email"
            placeholder="customer@example.com"
            type="email"
          />
        </a-form-item>

        <a-form-item
          label="Phone Number"
          name="phone"
        >
          <a-input
            v-model:value="form.phone"
            placeholder="+63 912 345 6789"
          />
        </a-form-item>
      </div>

      <!-- Domain field for global view -->
      <a-form-item v-if="page.props.isGlobalView" label="Domain" name="domain">
        <a-select
          v-model:value="form.domain"
          placeholder="Select domain"
          :options="domainOptions"
        />
      </a-form-item>

      <a-form-item
        label="Address"
        name="address"
      >
        <a-textarea
          v-model:value="form.address"
          placeholder="Enter customer's address"
          :rows="2"
        />
      </a-form-item>

      <a-form-item
        label="Date of Birth"
        name="date_of_birth"
      >
        <a-date-picker
          v-model:value="form.date_of_birth"
          placeholder="Select date of birth"
          style="width: 100%"
          format="YYYY-MM-DD"
        />
      </a-form-item>

      <!-- Loyalty Enrollment Section -->
      <div class="bg-blue-50 rounded-lg p-4 border border-blue-200">
        <h4 class="text-sm font-medium text-blue-900 mb-3 flex items-center">
          <gift-outlined class="mr-2" />
          Loyalty Program Enrollment
        </h4>
        
        <a-form-item name="enroll_in_loyalty" class="mb-0">
          <a-checkbox v-model:checked="form.enroll_in_loyalty">
            Enroll customer in loyalty program
          </a-checkbox>
        </a-form-item>
        
        <div v-if="form.enroll_in_loyalty" class="mt-3 text-sm text-blue-700">
          <div class="flex items-center">
            <check-circle-outlined class="mr-2 text-green-600" />
            Customer will start with Bronze tier and 0 points
          </div>
        </div>
      </div>
    </a-form>

    <!-- Customer Preview -->
    <div class="mt-6 p-4 bg-gray-50 rounded-lg">
      <h4 class="text-sm font-medium text-gray-900 mb-3">Preview</h4>
      <div class="flex items-center space-x-4">
        <a-avatar 
          :size="48"
          :style="{ backgroundColor: getAvatarColor(form.name) }"
        >
          {{ getInitials(form.name) }}
        </a-avatar>
        <div>
          <div class="font-medium">{{ form.name || 'Customer Name' }}</div>
          <div class="text-sm text-gray-500">{{ form.email || 'email@example.com' }}</div>
          <div class="text-sm text-gray-500">{{ form.phone || 'Phone number' }}</div>
          <div v-if="form.enroll_in_loyalty" class="text-xs text-blue-600 font-medium mt-1">
            🎁 Loyalty Member
          </div>
        </div>
      </div>
    </div>
  </a-modal>
</template>

<script setup>
import { ref, watch, reactive, computed } from "vue";
import { notification } from "ant-design-vue";
import { GiftOutlined, CheckCircleOutlined } from "@ant-design/icons-vue";
import axios from "axios";
import dayjs from "dayjs";
import { usePage } from "@inertiajs/vue3";

const page = usePage();

// Domain options
const domainOptions = computed(() => {
  const list = Array.isArray(page?.props?.domains)
    ? page.props.domains
    : [];
  return list.map((item) => ({ label: item.name, value: item.name_slug }));
});

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
  isEdit: {
    type: Boolean,
    default: false,
  },
});

// Emits
const emit = defineEmits(["close", "saved"]);

// Form reference
const formRef = ref();
const saving = ref(false);

// Form data
const form = reactive({
  name: "",
  email: "",
  phone: "",
  address: "",
  date_of_birth: null,
  enroll_in_loyalty: false,
  domain: page.props.isGlobalView ? null : (page.props.currentDomain?.name_slug || null),
});

// Form validation rules
const rules = {
  name: [{ required: true, message: "Please enter customer name" }],
  email: [
    { type: "email", message: "Please enter a valid email address" },
  ],
  phone: [
    { pattern: /^[\+]?[0-9\s\-\(\)]+$/, message: "Please enter a valid phone number" },
  ],
};

// Watch for customer changes
watch(
  () => props.customer,
  (newCustomer) => {
    if (newCustomer && props.isEdit) {
      Object.assign(form, {
        name: newCustomer.name || "",
        email: newCustomer.email || "",
        phone: newCustomer.phone || "",
        address: newCustomer.address || "",
        date_of_birth: newCustomer.date_of_birth ? dayjs(newCustomer.date_of_birth) : null,
        enroll_in_loyalty: newCustomer.loyalty_points !== null,
      });
    } else {
      // Reset form for new customer
      Object.assign(form, {
        name: "",
        email: "",
        phone: "",
        address: "",
        date_of_birth: null,
        enroll_in_loyalty: false,
      });
    }
  },
  { immediate: true }
);

// Methods
const handleSave = async () => {
  try {
    await formRef.value.validate();
    saving.value = true;

    const customerData = {
      name: form.name,
      email: form.email || null,
      phone: form.phone || null,
      address: form.address || null,
      date_of_birth: form.date_of_birth ? form.date_of_birth.format("YYYY-MM-DD") : null,
      enroll_in_loyalty: form.enroll_in_loyalty,
      domain: form.domain || undefined,
    };

    console.log("Saving customer data:", customerData);

    if (props.isEdit && props.customer) {
      // Update existing customer
      await axios.put(`/api/customers/${props.customer.id}`, customerData);
      notification.success({
        message: "Customer Updated",
        description: `${customerData.name} has been updated successfully`,
      });
    } else {
      // Create new customer
      await axios.post("/api/customers", customerData);
      notification.success({
        message: "Customer Created",
        description: `${customerData.name} has been added successfully`,
      });
    }

    emit("saved");
  } catch (error) {
    console.error("Save customer error:", error);

    let errorMessage = "Failed to save customer";
    if (error.response?.data?.errors) {
      const errors = error.response.data.errors;
      const firstError = Object.values(errors)[0];
      errorMessage = Array.isArray(firstError) ? firstError[0] : firstError;
    } else if (error.response?.data?.message) {
      errorMessage = error.response.data.message;
    }

    notification.error({
      message: "Save Failed",
      description: errorMessage,
    });
  } finally {
    saving.value = false;
  }
};

const handleCancel = () => {
  emit("close");
};

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
</script>
