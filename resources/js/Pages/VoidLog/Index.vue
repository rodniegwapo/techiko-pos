<script setup>
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import ActiveFilters from "@/Components/filters/ActiveFilters.vue";
import FilterDropdown from "@/Components/filters/FilterDropdown.vue";
import VoidTable from "./components/VoidTable.vue";

import { ref } from "vue";
import { Head, router } from "@inertiajs/vue3";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";
import { useFilters } from "@/Composables/useFilters";
import { watchDebounced } from "@vueuse/core";
import { useTable } from "@/Composables/useTable";
import { useHelpers } from "@/Composables/useHelpers";
import dayjs from "dayjs";

const props = defineProps({
  items: Object,
  filters: {
    default: {},
    type: Object,
  },
  isGlobalView: {
    type: Boolean,
    default: false,
  },
});

const { spinning } = useGlobalVariables();
const { startDateFormat, endDateFormat } = useHelpers();
const search = ref("");

const selectedRange = ref(
  props.filters?.start_date || props.filters?.end_date
    ? [
        props.filters?.start_date ? dayjs(props.filters.start_date) : null,
        props.filters?.end_date ? dayjs(props.filters.end_date) : null,
      ]
    : []
);

// FilterDropdown configuration
const filtersConfig = [
  {
    key: "selectedRange",
    label: "Select Date",
    type: "range",
  },
];

const getItems = () => {
  const startDate =
    selectedRange.value.length > 0
      ? startDateFormat(selectedRange.value[0])
      : "";
  const endDate =
    selectedRange.value.length > 0 ? endDateFormat(selectedRange.value[1]) : "";

  router.reload({
    only: ["items"],
    preserveScroll: true,
    data: {
      search: search.value || undefined,
      start_date: startDate || undefined,
      end_date: endDate || undefined,
    },
    onStart: () => (spinning.value = true),
    onFinish: () => (spinning.value = false),
  });
};

const { filters, activeFilters, handleClearSelectedFilter } = useFilters({
  getItems,
  configs: [
    {
      key: "selectedRange",
      ref: selectedRange,
      getLabel: (v) =>
        Array.isArray(v) && v.length === 2 ? `${v[0]} - ${v[1]}` : null,
    },
  ],
});

watchDebounced(search, getItems, { debounce: 300 });
const tableFilters = { search, selectedRange };
const { pagination, handleTableChange } = useTable("items", tableFilters);
</script>

<template>
  <AuthenticatedLayout>
    <Head title="Void Logs" />
    <ContentHeader class="mb-8" title="Void Logs" />
    <ContentLayout title="Void Logs">
      <template #filters>
        <refresh-button :loading="spinning" @click="getItems" />
        <a-input-search
          v-model:value="search"
          placeholder="Input search text"
          style="width: 100%"
          class="min-w-[100px] max-w-[300px]"
        />
        <FilterDropdown v-model="filters" :filters="filtersConfig" />
      </template>

      <template #activeFilters>
        <ActiveFilters
          :filters="activeFilters"
          @remove-filter="handleClearSelectedFilter"
          @clear-all="
            () => Object.keys(filters).forEach((k) => (filters[k] = null))
          "
          :always-show="false"
        />
      </template>

      <template #table>
        <VoidTable
          @handle-table-change="handleTableChange"
          :pagination="pagination"
          :is-global-view="props.isGlobalView"
        />
      </template>
    </ContentLayout>
  </AuthenticatedLayout>
</template>
