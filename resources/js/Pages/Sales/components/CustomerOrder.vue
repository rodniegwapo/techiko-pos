<script setup>
import VerticalForm from "@/Components/Forms/VerticalForm.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import ApplyProductDiscountModal from "./ApplyProductDiscountModal.vue";
import ApplyOrderDiscountModal from "./ApplyOrderDiscountModal.vue";
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import CustomerLoyaltyCard from "@/Components/Loyalty/CustomerLoyaltyCard.vue";
import { ref, inject, computed, createVNode } from "vue";
import {
  IconArmchair,
  IconUsers,
} from "@tabler/icons-vue";
import {
  CloseOutlined,
  PlusSquareOutlined,
  MinusSquareOutlined,
  ExclamationCircleOutlined,
  PlusOutlined
} from "@ant-design/icons-vue";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useOrders } from "@/Composables/useOrderV2";
import { useHelpers } from "@/Composables/useHelpers";
import axios from "axios";
import { Modal, notification } from "ant-design-vue";
import { usePage } from "@inertiajs/vue3";
import { useDebounceFn } from "@vueuse/core";

const { formData, errors } = useGlobalVariables();
const page = usePage();
const {
  orders,
  orderDiscountAmount,
  orderDiscountId,
  handleAddOrder,
  handleSubtractOrder,
  totalAmount,
  formattedTotal,
  removeOrder,
  orderId,
  finalizeOrder,
} = useOrders();

const { formattedPercent } = useHelpers();

const formFields = [
  { key: "amount", label: "Amount", type: "text", disabled: true },
  { key: "sale_item", label: "Item", type: "text", disabled: true },
  { key: "pin_code", label: "Enter Pin", type: "password" },
  {
    key: "reason",
    label: "Reason",
    type: "textarea",
  },
];

const openvoidModal = ref(false);

const showVoidItem = async (product) => {
  errors.value = {};
  openvoidModal.value = true;
  formData.value = {
    ...product,
    sale_item: product.name,
    amount: product.price,
    product_id: product.id,
  };
};

const loading = ref(false);
const handleSubmitVoid = async () => {
  try {
    loading.value = true;
    await axios.post(
      route("sales.items.void", {
        sale: orderId.value,
      }),
      formData.value
    );
    removeOrder(formData.value);
    openvoidModal.value = false;
    clearForm();
    notification["success"]({
      message: "Success",
      description: "The item was successfully voided.",
    });
  } catch ({ response }) {
    errors.value = response?.data?.errors;
  } finally {
    loading.value = false;
  }
};


const clearForm = () => {
  formData.value = {
    amount: "",
    sale_item: "",
    pin_code: "",
    reason: "",
    product_id: null,
  };
};

const currentProduct = ref({});
const openApplyDiscountModal = ref(false);
const handleShowProductDiscountModal = (product) => {
  formData.value = {
    discount: product.discount_id,
  };
  currentProduct.value = product;
  openApplyDiscountModal.value = true;
};

const isLoyalCustomer = ref(false);
const customer = ref("");

// Customer loyalty state
const selectedCustomer = ref(null);
const customerSearchQuery = ref('');
const customerOptions = ref([]);
const searchingCustomers = ref(false);
const showAddCustomerModal = ref(false);
const showCustomerDetailsModal = ref(false);
const addingCustomer = ref(false);

// New customer form
const newCustomerForm = ref({
  name: '',
  phone: '',
  email: '',
  date_of_birth: null,
});

const openOrderDicountModal = ref(false);

const showDiscountOrder = () => {
  if (orders.value.length == 0) return;
  
  // Get stored regular and mandatory discount IDs
  const regularDiscountIds = localStorage.getItem("regular_discount_ids") || "";
  const mandatoryDiscountIds = localStorage.getItem("mandatory_discount_ids") || "";
  
  // Convert stored IDs back to option objects for the select components
  const regularDiscountOptions = regularDiscountIds 
    ? regularDiscountIds.split(",")
        .map(id => Number(id))
        .filter(id => id)
        .map(id => {
          // Find the matching option from available discounts
          const discount = (page.props.discounts || []).find(d => d.id === id);
          return discount ? {
            label: `${discount.name} (${discount.type === 'percentage' ? discount.value + '%' : '₱' + discount.value})`,
            value: discount.id,
            amount: discount.value,
            type: discount.type,
          } : null;
        })
        .filter(Boolean)
    : [];
  
  const mandatoryDiscountId = mandatoryDiscountIds 
    ? Number(mandatoryDiscountIds.split(",")[0])
    : null;
    
  const mandatoryDiscountOption = mandatoryDiscountId 
    ? (() => {
        const discount = (page.props.mandatoryDiscounts || []).find(d => d.id === mandatoryDiscountId);
        return discount ? {
          label: `${discount.name} (${discount.type === 'percentage' ? discount.value + '%' : '₱' + discount.value})`,
          value: discount.id,
          amount: discount.value,
          type: discount.type,
        } : null;
      })()
    : null;
  
  formData.value = {
    orderDiscount: regularDiscountOptions,
    mandatoryDiscount: mandatoryDiscountOption,
  };
  openOrderDicountModal.value = true;
};

const cardClass =
  "w-1/2 border shadow text-center flex justify-center rounded-lg items-center gap-2 p-2 hover:bg-blue-400 hover:text-white  cursor-pointer";


const showPayment = ref(false);

// Customer search with debounce
const handleCustomerSearch = useDebounceFn(async (query) => {
  if (!query || query.length < 2) {
    customerOptions.value = [];
    return;
  }

  searchingCustomers.value = true;
  
  try {
    const response = await axios.get('/api/customers/search', {
      params: { q: query }
    });
    
    customerOptions.value = response.data.map(customer => ({
      value: customer.id,
      label: customer.display_text,
      customer: customer,
    }));
  } catch (error) {
    console.error('Customer search error:', error);
    notification.error({
      message: 'Search Error',
      description: 'Failed to search customers',
    });
  } finally {
    searchingCustomers.value = false;
  }
}, 300);

// Handle customer selection
const handleCustomerSelect = (customerId) => {
  const option = customerOptions.value.find(opt => opt.value === customerId);
  if (option) {
    selectedCustomer.value = option.customer;
    customerSearchQuery.value = '';
    customerOptions.value = [];
    
    notification.success({
      message: 'Customer Selected',
      description: `${option.customer.name} selected for this order`,
    });
  }
};

// Handle customer type change
const handleCustomerTypeChange = (isLoyal) => {
  if (!isLoyal) {
    clearCustomer();
  }
};

// Clear selected customer
const clearCustomer = () => {
  selectedCustomer.value = null;
  customerSearchQuery.value = '';
  customerOptions.value = [];
};

// Show customer details
const showCustomerDetails = () => {
  showCustomerDetailsModal.value = true;
};


// Add new customer
const handleAddCustomer = async () => {
  if (!newCustomerForm.value.name) {
    notification.error({
      message: 'Validation Error',
      description: 'Customer name is required',
    });
    return;
  }

  addingCustomer.value = true;
  
  try {
    const response = await axios.post('/api/customers', newCustomerForm.value);
    
    selectedCustomer.value = response.data.customer;
    showAddCustomerModal.value = false;
    
    // Reset form
    newCustomerForm.value = {
      name: '',
      phone: '',
      email: '',
      date_of_birth: null,
    };
    
    notification.success({
      message: 'Customer Added',
      description: `${response.data.customer.name} has been added and selected`,
    });
  } catch (error) {
    notification.error({
      message: 'Error',
      description: error.response?.data?.message || 'Failed to add customer',
    });
  } finally {
    addingCustomer.value = false;
  }
};


// Export customer data for parent component
defineExpose({
  selectedCustomer,
  isLoyalCustomer,
});
</script>

<template>
  <div class="flex items-center justify-between">
    <div class="font-semibold text-lg">Current Order</div>
    <a-switch
      v-model:checked="isLoyalCustomer"
      checked-children="Loyal"
      un-checked-children="Walk-in"
      @change="handleCustomerTypeChange"
    />
  </div>
  
  <!-- Customer Search/Display -->
  <div class="mt-1">
    <div v-if="isLoyalCustomer" class="space-y-2 max-h-52 overflow-y-auto">
      <!-- Customer Search -->
      <a-auto-complete
        v-if="!selectedCustomer"
        v-model:value="customerSearchQuery"
        :options="customerOptions"
        placeholder="Search customer by name, phone, or email"
        :loading="searchingCustomers"
        @search="handleCustomerSearch"
        @select="handleCustomerSelect"
        class="w-full"
      >
        <template #option="{ value, label, customer }">
          <div class="flex justify-between items-center py-2 px-1 hover:bg-gray-50 rounded">
            <div class="flex-1">
              <div class="font-medium text-sm">{{ customer.name }}</div>
              <div class="text-xs text-gray-500">
                {{ customer.phone || customer.email }}
              </div>
            </div>
            <div class="text-right ml-2">
              <div class="text-xs font-medium px-2 py-0.5 rounded-full text-white" :style="{ backgroundColor: customer.tier_info.color }">
                {{ customer.tier_info.name }}
              </div>
              <div class="text-xs text-purple-600 mt-0.5">
                {{ customer.loyalty_points?.toLocaleString() }} pts
              </div>
            </div>
          </div>
        </template>
      </a-auto-complete>

      <!-- Selected Customer Card -->
      <CustomerLoyaltyCard
        v-if="selectedCustomer"
        :customer="selectedCustomer"
        :total-amount="totalAmount"
        :show-points-preview="true"
        @view-details="showCustomerDetails"
        @clear-customer="clearCustomer"
      />

      <!-- Quick Add Customer -->
      <div class="flex gap-2">
        <a-button size="small" @click="showAddCustomerModal = true">
          <plus-outlined />
          Add New Customer
        </a-button>
      </div>
    </div>

    <!-- Walk-in Customer Display -->
    <a-input v-else value="Walk-in Customer" disabled />
  </div>
  <!-- <div class="mt-2">
    <div class="font-semibold">Quick Discounts</div>
    <div class="flex items-center gap-2 mt-1">
      <div :class="cardClass">
        <div><IconArmchair size="20" /></div>
        <div class="text-left text-xs">
          <div>PWD</div>
          <div>20% Off</div>
        </div>
      </div>
      <div :class="cardClass">
        <div><IconUsers size="20" /></div>
        <div class="text-left text-xs">
          <div>Senior</div>
          <div>20% Off</div>
        </div>
      </div>
    </div>
  </div> -->

  <div class="relative">
    <!-- Transition wrapper -->
    <Transition name="slide-x" mode="out-in">
      <!-- 🟥 ORDER SUMMARY PAGE -->
      <div v-if="!showPayment" key="order" >
        <div
          :class="[
            'scrollable-orders relative flex flex-col gap-2 mt-4 overflow-auto overflow-x-hidden transition-all duration-300',
            {
              'h-[calc(100vh-430px)]': !isLoyalCustomer,
              'h-[calc(100vh-440px)]': isLoyalCustomer && !selectedCustomer,
              'h-[calc(100vh-570px)]': isLoyalCustomer && selectedCustomer && totalAmount <= 0,
              'h-[calc(100vh-600px)]': isLoyalCustomer && selectedCustomer && totalAmount > 0
            }
          ]"
        >
          <div
            v-if="orders.length == 0"
            class="text-[40px] text-nowrap uppercase font-bold text-gray-200 -rotate-45 absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2"
          >
            No Order
          </div>

          <div v-else class="flex flex-col gap-2">
            <div
              v-for="(order, index) in orders"
              :key="index"
              class="flex justify-between items-center border relative px-4 rounded-lg bg-white hover:shadow cursor-pointer"
              @click="handleShowProductDiscountModal(order)"
            >
              <div class="flex flex-col gap-1 py-1">
                <div class="text-sm font-semibold">{{ order.name }}</div>

                <div class="text-md flex items-center gap-1 text-gray-800">
                  <a-tooltip title="Add item">
                    <a-button 
                      type="text" 
                      size="small" 
                      @click.stop="handleAddOrder(order)"
                      class="p-0 h-auto border-0 text-green-600 hover:text-green-700"
                    >
                      <template #icon>
                        <PlusSquareOutlined />
                      </template>
                    </a-button>
                  </a-tooltip>
                  <span class="mx-2 font-medium">{{ order.quantity }}</span>
                  <a-tooltip title="Remove item">
                    <a-button 
                      type="text" 
                      size="small" 
                      @click.stop="handleSubtractOrder(order)"
                      class="p-0 h-auto border-0 text-red-600 hover:text-red-700"
                    >
                      <template #icon>
                        <MinusSquareOutlined />
                      </template>
                    </a-button>
                  </a-tooltip>
                </div>
                <div class="text-[11px]">
                  {{ order.price }} x {{ order.quantity }}
                </div>
              </div>

              <div class="text-right flex flex-col py-1 items-end gap-1">
                <a-tooltip title="Remove item from order">
                  <a-button 
                    type="text" 
                    size="small" 
                    @click.stop="showVoidItem(order)"
                    class="p-1 h-auto border-0 text-red-600 hover:text-red-700 hover:bg-red-50"
                  >
                    <template #icon>
                      <CloseOutlined />
                    </template>
                  </a-button>
                </a-tooltip>

                <div class="text-xs" v-if="order.discount">
                  <div
                    class="text-gray-600 border-b px-2"
                    v-if="order.discount_type == 'amount'"
                  >
                    Disc : {{ formattedTotal(order?.discount) }} -
                    {{ formattedTotal(order.discount_amount) }}
                  </div>
                  <div class="text-gray-600 border-b px-2" v-else>
                    Disc: {{ formattedPercent(order?.discount) }} -
                    {{ formattedTotal(order?.discount_amount) }}
                  </div>
                </div>
                <div
                  class="text-xs text-gray-600 line-through invisible"
                  v-else
                >
                  Discount
                </div>

                <div
                  class="text-md font-semibold text-green-700"
                  v-if="order.discount"
                >
                  {{ formattedTotal(order.subtotal) }}
                </div>
                <div v-else class="text-md font-semibold text-green-700">
                  {{ formattedTotal(order.price * order.quantity) }}
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

    </Transition>
  </div>

  <a-modal
    v-model:visible="openvoidModal"
    title="Void Product"
    @cancel="openvoidModal = false"
    :maskClosable="false"
    width="450px"
  >
    <vertical-form v-model="formData" :fields="formFields" :errors="errors" />
    <template #footer>
      <a-button @click="openvoidModal = false">Cancel</a-button>

      <primary-button :loading="loading" @click="handleSubmitVoid"
        >Submit
      </primary-button>
    </template>
  </a-modal>

  <apply-product-discount-modal
    :openModal="openApplyDiscountModal"
    :product="currentProduct"
    @close="openApplyDiscountModal = false"
  />

  <apply-order-discount-modal
    :openModal="openOrderDicountModal"
    :product="currentProduct"
    @close="openOrderDicountModal = false"
  />

  <!-- Add Customer Modal -->
  <a-modal
    v-model:visible="showAddCustomerModal"
    title="Add New Customer"
    @ok="handleAddCustomer"
    @cancel="showAddCustomerModal = false"
    :confirm-loading="addingCustomer"
  >
    <a-form :model="newCustomerForm" layout="vertical">
      <a-form-item label="Name" required>
        <a-input v-model:value="newCustomerForm.name" placeholder="Customer name" />
      </a-form-item>
      <a-form-item label="Phone">
        <a-input v-model:value="newCustomerForm.phone" placeholder="Phone number" />
      </a-form-item>
      <a-form-item label="Email">
        <a-input v-model:value="newCustomerForm.email" placeholder="Email address" />
      </a-form-item>
      <a-form-item label="Date of Birth">
        <a-date-picker v-model:value="newCustomerForm.date_of_birth" class="w-full" />
      </a-form-item>
    </a-form>
  </a-modal>

  <!-- Customer Details Modal -->
  <a-modal
    v-model:visible="showCustomerDetailsModal"
    title="Customer Details"
    :footer="null"
    width="600px"
  >
    <div v-if="selectedCustomer" class="space-y-4">
      <div class="grid grid-cols-2 gap-4">
        <div>
          <h4 class="font-medium text-gray-700">Contact Information</h4>
          <p><strong>Name:</strong> {{ selectedCustomer.name }}</p>
          <p><strong>Phone:</strong> {{ selectedCustomer.phone || 'N/A' }}</p>
          <p><strong>Email:</strong> {{ selectedCustomer.email || 'N/A' }}</p>
        </div>
        <div>
          <h4 class="font-medium text-gray-700">Loyalty Status</h4>
          <p><strong>Tier:</strong> {{ selectedCustomer.tier_info.name }}</p>
          <p><strong>Points:</strong> {{ selectedCustomer.loyalty_points?.toLocaleString() || 0 }}</p>
          <p><strong>Lifetime Spent:</strong> ₱{{ selectedCustomer.lifetime_spent?.toLocaleString() || 0 }}</p>
          <p><strong>Total Purchases:</strong> {{ selectedCustomer.total_purchases || 0 }}</p>
        </div>
      </div>
    </div>
  </a-modal>
</template>
<style>
/* Slide horizontal animation */
.slide-x-enter-active,
.slide-x-leave-active {
  transition: all 0.3s ease;
  position: absolute;
  width: 100%;
}
.slide-x-enter-from {
  opacity: 0;
  transform: translateX(100%);
}
.slide-x-leave-to {
  opacity: 0;
  transform: translateX(-100%);
}

</style>
