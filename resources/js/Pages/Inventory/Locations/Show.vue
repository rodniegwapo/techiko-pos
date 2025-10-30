<script setup>
import { ref, computed } from "vue";
import { router, Head } from "@inertiajs/vue3";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { EditOutlined, ArrowLeftOutlined, PhoneOutlined, MailOutlined, EnvironmentOutlined, ShoppingCartOutlined, WarningOutlined, StopOutlined, DollarOutlined } from "@ant-design/icons-vue";
import { IconBuilding, IconBuildingWarehouse, IconTruck, IconUser } from "@tabler/icons-vue";

import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";

const props = defineProps({
  location: Object,
  stats: Object,
});

const { getRoute } = useDomainRoutes();

// Methods
const goBack = () => {
  router.visit(getRoute('inventory.locations.index'));
};

const editLocation = () => {
  router.visit(getRoute('inventory.locations.edit', props.location?.data?.id));
};

const viewProducts = () => {
  router.visit(getRoute('inventory.products'), {
    data: { location_id: props.location?.data?.id }
  });
};

const viewMovements = () => {
  router.visit(getRoute('inventory.movements'), {
    data: { location_id: props.location?.data?.id }
  });
};

// Get icon for location type
const getTypeIcon = (type) => {
  switch (type) {
    case 'store': return IconBuilding;
    case 'warehouse': return IconBuildingWarehouse;
    case 'supplier': return IconTruck;
    case 'customer': return IconUser;
    default: return IconBuilding;
  }
};

// Format currency
const formatCurrency = (amount) => {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD'
  }).format(amount || 0);
};
</script>

<template>
  <Head :title="location?.data?.name || 'Location'" />

  <AuthenticatedLayout>
    <ContentHeader :title="location?.data?.name || 'Location'" />

    <div class="max-w-6xl mx-auto p-6 space-y-6">
      <!-- Location Info -->
      <a-card class="shadow-sm">
        <div class="p-2">
        <div class="flex items-start gap-6">
          <div class="flex-shrink-0">
            <div class="w-16 h-16 bg-blue-100 rounded-lg flex items-center justify-center">
              <component :is="getTypeIcon(location?.data?.type)" class="text-2xl text-blue-600" />
            </div>
          </div>
          
          <div class="flex-1">
            <div class="flex items-center gap-3 mb-2">
              <h2 class="text-2xl font-semibold">{{ location?.data?.name }}</h2>
              <a-tag :color="location?.data?.type_badge?.color || 'default'">
                {{ location?.data?.type_badge?.text || location?.data?.type }}
              </a-tag>
              <a-tag :color="location?.data?.status_badge?.color || (location?.data?.is_active ? 'green' : 'red')">
                {{ location?.data?.status_badge?.text || (location?.data?.is_active ? 'Active' : 'Inactive') }}
              </a-tag>
              <a-tag v-if="location?.data?.is_default" color="processing">
                Default
              </a-tag>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
              <div>
                <div class="font-medium text-gray-700 mb-1">Location Code</div>
                <div class="font-mono bg-gray-100 px-2 py-1 rounded inline-block">
                  {{ location?.data?.code }}
                </div>
              </div>
              
              <div v-if="location?.data?.address">
                <div class="font-medium text-gray-700 mb-1">
                  <EnvironmentOutlined class="mr-1" />
                  Address
                </div>
                <div>{{ location?.data?.address }}</div>
              </div>
              
              <div v-if="location?.data?.contact_person">
                <div class="font-medium text-gray-700 mb-1">Contact Person</div>
                <div>{{ location?.data?.contact_person }}</div>
              </div>
              
              <div v-if="location?.data?.phone">
                <div class="font-medium text-gray-700 mb-1">
                  <PhoneOutlined class="mr-1" />
                  Phone
                </div>
                <div>{{ location?.data?.phone }}</div>
              </div>
              
              <div v-if="location?.data?.email" class="md:col-span-2">
                <div class="font-medium text-gray-700 mb-1">
                  <MailOutlined class="mr-1" />
                  Email
                </div>
                <div>{{ location?.data?.email }}</div>
              </div>
            </div>
            
            <div v-if="location?.data?.notes" class="mt-4">
              <div class="font-medium text-gray-700 mb-1">Notes</div>
              <div class="text-gray-600">{{ location?.data?.notes }}</div>
            </div>
          </div>
        </div>
        </div>
      </a-card>

      <!-- Statistics Cards -->
      <a-card title="Statistics" class="shadow-sm">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <!-- Total Products -->
        <div class="bg-white p-4 rounded-lg border cursor-pointer hover:shadow-md transition-shadow" @click="viewProducts">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-2xl font-bold text-blue-600">
                {{ stats.total_products }}
              </div>
              <div class="text-sm text-gray-600">Total Products</div>
            </div>
            <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
              <ShoppingCartOutlined class="text-xl text-blue-600" />
            </div>
          </div>
        </div>

        <!-- In Stock -->
        <div class="bg-white p-4 rounded-lg border cursor-pointer hover:shadow-md transition-shadow" @click="viewProducts">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-2xl font-bold text-green-600">
                {{ stats.in_stock_products }}
              </div>
              <div class="text-sm text-gray-600">In Stock</div>
            </div>
            <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
              <ShoppingCartOutlined class="text-xl text-green-600" />
            </div>
          </div>
        </div>

        <!-- Low Stock -->
        <div class="bg-white p-4 rounded-lg border cursor-pointer hover:shadow-md transition-shadow" @click="viewProducts">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-2xl font-bold text-orange-600">
                {{ stats.low_stock_products }}
              </div>
              <div class="text-sm text-gray-600">Low Stock</div>
            </div>
            <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center">
              <WarningOutlined class="text-xl text-orange-600" />
            </div>
          </div>
        </div>

        <!-- Out of Stock -->
        <div class="bg-white p-4 rounded-lg border cursor-pointer hover:shadow-md transition-shadow" @click="viewProducts">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-2xl font-bold text-red-600">
                {{ stats.out_of_stock_products }}
              </div>
              <div class="text-sm text-gray-600">Out of Stock</div>
            </div>
            <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center">
              <StopOutlined class="text-xl text-red-600" />
            </div>
          </div>
        </div>
      </div>

      <!-- Additional Stats -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        <!-- Inventory Value -->
        <div class="bg-white p-4 rounded-lg border mt-4">
          <h3 class="text-lg font-semibold mb-4 pb-2 border-b">Inventory Value</h3>
          <div class="flex items-center justify-between">
            <div>
              <div class="text-3xl font-bold text-green-600">
                {{ formatCurrency(stats.total_inventory_value) }}
              </div>
              <div class="text-sm text-gray-600">Total Inventory Value</div>
            </div>
            <div class="w-16 h-16 bg-green-100 rounded-lg flex items-center justify-center">
              <DollarOutlined class="text-2xl text-green-600" />
            </div>
          </div>
        </div>

        <!-- Recent Activity -->
        <div class="bg-white p-4 mt-4 rounded-lg border cursor-pointer hover:shadow-md transition-shadow" @click="viewMovements">
          <h3 class="text-lg font-semibold mb-4 pb-2 border-b">Recent Activity</h3>
          <div class="flex items-center justify-between">
            <div>
              <div class="text-3xl font-bold text-purple-600">
                {{ stats.recent_movements_count }}
              </div>
              <div class="text-sm text-gray-600">Movements (Last 7 days)</div>
            </div>
            <div class="w-16 h-16 bg-purple-100 rounded-lg flex items-center justify-center">
              <i class="fas fa-exchange-alt text-2xl text-purple-600"></i>
            </div>
          </div>
        </div>
        </div>
      </a-card>

      <!-- Quick Actions -->
      <a-card title="Quick Actions" class="shadow-sm">
        <div class="p-2">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <a-button size="large" block @click="viewProducts">
            <template #icon>
              <ShoppingCartOutlined />
            </template>
            View Products
          </a-button>
          
          <a-button size="large" block @click="viewMovements">
            <template #icon>
              <i class="fas fa-exchange-alt"></i>
            </template>
            View Movements
          </a-button>
          
          <a-button size="large" block @click="editLocation">
            <template #icon>
              <EditOutlined />
            </template>
            Edit Location
          </a-button>
        </div>
        </div>
      </a-card>

      <!-- Actions -->
      <div class="flex justify-end space-x-4">
        <a-button @click="goBack">
          <template #icon>
            <ArrowLeftOutlined />
          </template>
          Back to Locations
        </a-button>
        <a-button type="primary" @click="editLocation">
          <template #icon>
            <EditOutlined />
          </template>
          Edit Location
        </a-button>
      </div>
    </div>
  </AuthenticatedLayout>
</template>
