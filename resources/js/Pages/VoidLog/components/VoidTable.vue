<script setup>
import { computed, ref } from "vue";
import { usePage } from "@inertiajs/vue3";
import { useMediaQuery } from "@vueuse/core";
import { IconEye, IconTrash } from "@tabler/icons-vue";
import dayjs from "dayjs";

import { useHelpers } from "@/Composables/useHelpers";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";

const page = usePage();
const isMdUp = useMediaQuery("(min-width: 768px)");

const { formattedTotal, formatDateTime } = useHelpers();
const { spinning } = useGlobalVariables();

const emit = defineEmits(["handleTableChange"]);

const props = defineProps({
  pagination: {
    type: Object,
    default: {},
  },
  isGlobalView: {
    type: Boolean,
    default: false,
  },
});

const showSuperUserDomain = computed(
  () => page.props.auth?.user?.data?.is_super_user && props.isGlobalView,
);

const detailsModalVisible = ref(false);
const selectedRecord = ref(null);

const columns = computed(() => [
  { title: "User", dataIndex: "user", key: "user", align: "left" },
  { title: "Product", dataIndex: "product", key: "product", align: "left" },
  {
    title: "Approved By",
    dataIndex: "approved_by",
    key: "approved_by",
    align: "left",
  },
  { title: "Amount", dataIndex: "amount", key: "amount", align: "left" },
  {
    title: "Transaction Date",
    dataIndex: "created_at",
    key: "created_at",
    align: "left",
  },
  ...(showSuperUserDomain.value
    ? [
        {
          title: "Domain",
          dataIndex: "domain",
          key: "domain",
          align: "left",
          sorter: (a, b) => (a.domain || "").localeCompare(b.domain || ""),
        },
      ]
    : []),
]);

const dataSource = computed(
  () =>
    page.props?.items?.data?.map((record) => ({
      ...record,
      key: record.id,
    })) ?? [],
);

function getSaleRef(record) {
  return (
    record?.sale_item?.sale?.invoice_number ??
    (record?.sale_item?.sale?.id ? `#${record.sale_item.sale.id}` : "N/A")
  );
}

function showDetails(record) {
  selectedRecord.value = record;
  detailsModalVisible.value = true;
}

function onMobilePaginationChange(pageNum) {
  emit("handleTableChange", {
    current: pageNum,
    pageSize: props.pagination?.pageSize ?? 10,
  });
}
</script>

<template>
  <a-table
    v-if="isMdUp"
    class="ant-table-striped"
    :columns="columns"
    :data-source="dataSource"
    :row-class-name="
      (_, index) => (index % 2 === 1 ? 'bg-gray-50 group' : 'group')
    "
    @change="$emit('handleTableChange', $event)"
    :pagination="pagination"
    :loading="spinning"
  >
    <template #bodyCell="{ column, record }">
      <template v-if="column.key == 'amount'">
        {{ formattedTotal(Number(record?.amount)) }}
      </template>
      <template v-if="column.key == 'user'">
        {{ record?.user?.name }}
      </template>
      <template v-if="column.key == 'product'">
        {{ record?.sale_item?.product?.name }}
      </template>
      <template v-if="column.key == 'approved_by'">
        {{ record?.approver?.name }}
      </template>
      <template v-if="column.key == 'created_at'">
        {{ dayjs(record.created_at).format("dddd, MMMM D, YYYY HH:mm:ss") }}
      </template>
      <template v-if="column.key == 'domain'">
        {{ record?.domain || "N/A" }}
      </template>
    </template>

    <template #emptyText>
      <div class="py-8 text-center">
        <IconTrash :size="48" class="mx-auto mb-4 text-gray-400" />
        <p class="text-gray-500">No void logs found</p>
      </div>
    </template>
  </a-table>

  <div v-else class="px-2 py-2 md:px-0">
    <a-spin :spinning="spinning">
      <div
        v-if="!dataSource.length"
        class="py-12 text-center text-sm text-gray-500"
      >
        <IconTrash :size="48" class="mx-auto mb-4 text-gray-400" />
        <p>No void logs found</p>
      </div>
      <div v-else class="flex flex-col gap-3">
        <div
          v-for="record in dataSource"
          :key="record.id"
          class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm"
        >
          <div class="flex flex-wrap items-start justify-between gap-2 px-4 py-3">
            <div>
              <p class="text-sm font-medium text-gray-900">
                {{ formatDateTime(record.created_at) }}
              </p>
              <p class="text-xs text-gray-500">Void #{{ record.id }}</p>
            </div>
            <span class="text-base font-semibold text-red-600">
              {{ formattedTotal(Number(record?.amount)) }}
            </span>
          </div>

          <div class="mx-4 mb-3 rounded-lg bg-gray-50 p-3">
            <div class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-2 text-sm">
              <span class="text-gray-500">Product</span>
              <span class="text-right font-semibold text-gray-900">
                {{ record?.sale_item?.product?.name || "N/A" }}
              </span>
              <span class="text-gray-500">Reason</span>
              <span class="text-right font-medium text-gray-900">
                {{ record?.reason || "N/A" }}
              </span>
              <span class="text-gray-500">Cashier</span>
              <span class="text-right font-medium text-gray-900">
                {{ record?.user?.name || "N/A" }}
              </span>
              <span class="text-gray-500">Sale ref</span>
              <span class="text-right font-medium text-gray-900">
                {{ getSaleRef(record) }}
              </span>
              <template v-if="showSuperUserDomain">
                <span class="text-gray-500">Domain</span>
                <span class="text-right font-medium text-gray-900">
                  {{ record?.domain || "N/A" }}
                </span>
              </template>
            </div>
          </div>

          <div class="border-t border-gray-100 px-4 py-3">
            <a-button
              class="flex w-full items-center justify-center gap-2"
              @click="showDetails(record)"
            >
              <template #icon>
                <IconEye size="18" />
              </template>
              View details
            </a-button>
          </div>
        </div>
      </div>
      <a-pagination
        v-if="
          pagination?.total &&
          pagination.total > (pagination.pageSize ?? 10)
        "
        class="mt-4 justify-center pt-2"
        show-less-items
        :current="pagination.current"
        :page-size="pagination.pageSize"
        :total="pagination.total"
        :show-size-changer="false"
        @change="onMobilePaginationChange"
      />
    </a-spin>
  </div>

  <a-modal
    v-model:open="detailsModalVisible"
    title="Void log details"
    :footer="null"
    width="min(480px, 100vw)"
  >
    <div v-if="selectedRecord" class="space-y-3 text-sm">
      <div class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-2">
        <span class="text-gray-500">Date & time</span>
        <span class="font-medium">
          {{ formatDateTime(selectedRecord.created_at) }}
        </span>
        <span class="text-gray-500">Product</span>
        <span class="font-medium">
          {{ selectedRecord?.sale_item?.product?.name || "N/A" }}
        </span>
        <span class="text-gray-500">Reason</span>
        <span class="font-medium">{{ selectedRecord?.reason || "N/A" }}</span>
        <span class="text-gray-500">Cashier</span>
        <span class="font-medium">{{ selectedRecord?.user?.name || "N/A" }}</span>
        <span class="text-gray-500">Approved by</span>
        <span class="font-medium">
          {{ selectedRecord?.approver?.name || "N/A" }}
        </span>
        <span class="text-gray-500">Amount</span>
        <span class="font-semibold text-red-600">
          {{ formattedTotal(Number(selectedRecord?.amount)) }}
        </span>
        <span class="text-gray-500">Sale ref</span>
        <span class="font-medium">{{ getSaleRef(selectedRecord) }}</span>
        <template v-if="showSuperUserDomain">
          <span class="text-gray-500">Domain</span>
          <span class="font-medium">{{ selectedRecord?.domain || "N/A" }}</span>
        </template>
      </div>
    </div>
  </a-modal>
</template>
