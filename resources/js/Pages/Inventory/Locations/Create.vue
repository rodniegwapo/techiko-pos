<script setup>
import { reactive, ref, computed } from "vue";
import { router, Head, usePage } from "@inertiajs/vue3";
import { SaveOutlined, ArrowLeftOutlined } from "@ant-design/icons-vue";
import {
  IconBuilding,
  IconBuildingWarehouse,
  IconTruck,
  IconUser,
} from "@tabler/icons-vue";

import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";

const page = usePage();
const { getRoute } = useDomainRoutes();

const props = defineProps({
  locationTypes: Array,
  domains: Array,
});

// Form state
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
  domain: page.props.isGlobalView ? null : (page.props.currentDomain?.name_slug || null),
});

const loading = ref(false);
const errors = ref({});

// Methods
const goBack = () => {
  router.visit(getRoute("inventory.locations.index"));
};

const submit = () => {
  loading.value = true;
  errors.value = {};

  router.post(getRoute("inventory.locations.store"), form, {
    onSuccess: () => {
      // Success handled by redirect
    },
    onError: (formErrors) => {
      errors.value = formErrors;
    },
    onFinish: () => {
      loading.value = false;
    },
  });
};

// Get icon for location type
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

// Auto-generate code from name
const generateCode = () => {
  if (form.name && !form.code) {
    form.code = form.name
      .toUpperCase()
      .replace(/[^A-Z0-9]/g, "")
      .substring(0, 10);
  }
};

// Domain options computed
const domainOptions = computed(() => 
  (props.domains || []).map(domain => ({ 
    label: domain.name, 
    value: domain.name_slug 
  }))
);
</script>

<template>
  <Head title="Create Location" />

  <AuthenticatedLayout>
    <ContentHeader title="Create Location" />

    <div class="max-w-4xl mx-auto p-6 space-y-6">
      <!-- Basic Information -->
      <a-card  class="shadow-sm">
        <div class="w-full">
          <a-form :model="form" layout="vertical" @finish="submit">
            <a-card title="Basic Information" class="mb-6">
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
                      @blur="generateCode"
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
                <a-select
                  v-model:value="form.type"
                  placeholder="Select location type"
                >
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

              <!-- Domain field for global view -->
              <a-form-item v-if="page.props.isGlobalView" label="Domain" required>
                <a-select
                  v-model:value="form.domain"
                  placeholder="Select domain"
                >
                  <a-select-option
                    v-for="domain in domainOptions"
                    :key="domain.value"
                    :value="domain.value"
                  >
                    {{ domain.label }}
                  </a-select-option>
                </a-select>
              </a-form-item>
            </a-card>

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

            <a-card title="Settings" class="mb-6">
              <a-form-item>
                <a-checkbox v-model:checked="form.is_active">
                  Active Location
                </a-checkbox>
                <div class="text-sm text-gray-600 mt-1">
                  Only active locations can be used for inventory operations
                </div>
              </a-form-item>

              <a-form-item>
                <a-checkbox v-model:checked="form.is_default">
                  Set as Default Location
                </a-checkbox>
                <div class="text-sm text-gray-600 mt-1">
                  The default location will be pre-selected in forms
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
                Create Location
              </a-button>
            </div>
          </a-form>
        </div>
      </a-card>
    </div>
  </AuthenticatedLayout>
</template>
