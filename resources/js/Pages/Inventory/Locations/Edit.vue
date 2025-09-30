<script setup>
import { reactive, ref, onMounted } from "vue";
import { router, Head } from "@inertiajs/vue3";
import { SaveOutlined, ArrowLeftOutlined } from "@ant-design/icons-vue";
import { IconBuilding, IconBuildingWarehouse, IconTruck, IconUser } from "@tabler/icons-vue";

import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";

const props = defineProps({
  location: Object,
  locationTypes: Array,
});

const form = reactive({
  name: "",
  code: "",
  type: "store",
  address: "",
  contact_person: "",
  phone: "",
  email: "",
  is_active: true,
  is_default: false,
  notes: "",
});

const loading = ref(false);
const errors = ref({});

onMounted(() => {
  if (props.location?.data) {
    Object.assign(form, {
      name: props.location.data.name || "",
      code: props.location.data.code || "",
      type: props.location.data.type || "store",
      address: props.location.data.address || "",
      contact_person: props.location.data.contact_person || "",
      phone: props.location.data.phone || "",
      email: props.location.data.email || "",
      is_active: props.location.data.is_active ?? true,
      is_default: props.location.data.is_default ?? false,
      notes: props.location.data.notes || "",
    });
  }
});

const goBack = () => {
  router.visit(route("inventory.locations.index"));
};

const submit = () => {
  loading.value = true;
  errors.value = {};

  router.put(route("inventory.locations.update", props.location?.data?.id), form, {
    onSuccess: () => {},
    onError: (formErrors) => {
      errors.value = formErrors;
    },
    onFinish: () => {
      loading.value = false;
    },
  });
};

const getTypeIcon = (type) => {
  switch (type) {
    case "store":
      return IconBuilding;
    case "warehouse":
      return IconBuildingWarehouse;
    case "supplier":
      return IconTruck;
    case "customer":
      return IconUser;
    default:
      return IconBuilding;
  }
};
</script>

<template>
  <Head :title="`Edit ${location?.data?.name}`" />
  
  <AuthenticatedLayout>
    <ContentHeader :title="`Edit ${location?.data?.name || 'Location'}`"/>
    
    <div class="max-w-4xl mx-auto p-6 space-y-6">
      <!-- Location Info -->
      <a-card class="shadow-sm">
        <div class="flex justify-between items-center">
          <div>
             <h3 class="text-lg font-medium">
               {{ location?.data?.name || "Loading..." }}
             </h3>
             <p class="text-sm text-gray-500">
               Code: {{ location?.data?.code || "N/A" }} • Type:
               {{ location?.data?.type || "N/A" }}
             </p>
           </div>
           <div class="flex items-center space-x-2">
             <a-tag :color="location?.data?.is_active ? 'green' : 'red'">
               {{ location?.data?.is_active ? "Active" : "Inactive" }}
             </a-tag>
             <a-tag v-if="location?.data?.is_default" color="blue">
               Default Location
             </a-tag>
          </div>
        </div>
      </a-card>

      <!-- Form -->
      <a-form :model="form" layout="vertical" @finish="submit">
        <!-- Basic Information -->
        <a-card title="Basic Information" class="shadow-sm mb-6">
          <a-row :gutter="16">
            <a-col :span="12">
              <a-form-item
                label="Location Name"
                :validate-status="errors.name ? 'error' : ''"
                :help="errors.name?.[0]"
                required
              >
                <a-input
                  v-model:value="form.name"
                  placeholder="Enter location name"
                />
              </a-form-item>
            </a-col>
            <a-col :span="12">
              <a-form-item
                label="Location Code"
                :validate-status="errors.code ? 'error' : ''"
                :help="errors.code?.[0]"
                required
              >
                <a-input
                  v-model:value="form.code"
                  placeholder="e.g., MAIN, WH01"
                  :maxlength="10"
                  style="text-transform: uppercase"
                />
              </a-form-item>
            </a-col>
          </a-row>

          <a-form-item
            label="Location Type"
            :validate-status="errors.type ? 'error' : ''"
            :help="errors.type?.[0]"
            required
          >
            <a-select v-model:value="form.type" placeholder="Select location type">
              <a-select-option
                v-for="type in locationTypes"
                :key="type.value"
                :value="type.value"
              >
                <div class="flex items-center gap-2">
                  <component :is="getTypeIcon(type.value)" />
                  {{ type.label }}
                </div>
              </a-select-option>
            </a-select>
          </a-form-item>

          <a-form-item
            label="Address"
            :validate-status="errors.address ? 'error' : ''"
            :help="errors.address?.[0]"
          >
            <a-textarea
              v-model:value="form.address"
              placeholder="Enter full address"
              :rows="3"
            />
          </a-form-item>
        </a-card>

        <!-- Contact Information -->
        <a-card title="Contact Information" class="mb-6">
          <a-row :gutter="16">
            <a-col :span="12">
              <a-form-item
                label="Contact Person"
                :validate-status="errors.contact_person ? 'error' : ''"
                :help="errors.contact_person?.[0]"
              >
                <a-input
                  v-model:value="form.contact_person"
                  placeholder="Enter contact person name"
                />
              </a-form-item>
            </a-col>
            <a-col :span="12">
              <a-form-item
                label="Phone Number"
                :validate-status="errors.phone ? 'error' : ''"
                :help="errors.phone?.[0]"
              >
                <a-input
                  v-model:value="form.phone"
                  placeholder="Enter phone number"
                />
              </a-form-item>
            </a-col>
          </a-row>

          <a-form-item
            label="Email Address"
            :validate-status="errors.email ? 'error' : ''"
            :help="errors.email?.[0]"
          >
            <a-input
              v-model:value="form.email"
              placeholder="Enter email address"
              type="email"
            />
          </a-form-item>
        </a-card>

        <!-- Settings -->
        <a-card title="Settings" class="mb-6">
          <a-form-item>
            <a-checkbox v-model:checked="form.is_active"> Active Location </a-checkbox>
            <div class="text-sm text-gray-600 mt-1">
              Only active locations can be used for inventory operations
            </div>
          </a-form-item>

          <a-form-item>
             <a-checkbox
               v-model:checked="form.is_default"
               :disabled="location?.data?.is_default"
             >
               Set as Default Location
             </a-checkbox>
             <div class="text-sm text-gray-600 mt-1">
               <span v-if="location?.data?.is_default">
                 This is currently the default location
               </span>
               <span v-else>
                 The default location will be pre-selected in forms
               </span>
             </div>
          </a-form-item>

          <a-form-item
            label="Notes"
            :validate-status="errors.notes ? 'error' : ''"
            :help="errors.notes?.[0]"
          >
            <a-textarea
              v-model:value="form.notes"
              placeholder="Enter any additional notes"
              :rows="3"
            />
          </a-form-item>
        </a-card>

        <!-- Actions -->
        <div class="flex justify-end gap-3">
          <a-button @click="goBack"> Cancel </a-button>
          <a-button type="primary" html-type="submit" :loading="loading">
            <template #icon>
              <SaveOutlined />
            </template>
            Update Location
          </a-button>
        </div>
      </a-form>
    </div>
  </AuthenticatedLayout>
</template>
