<script setup>
import { computed } from "vue";
import {
    IconEdit,
    IconTrash,
    IconEye,
    IconWorld,
    IconMapPin,
    IconCurrencyDollar,
    IconClock,
    IconExternalLink,
} from "@tabler/icons-vue";
import { Modal } from "ant-design-vue";
import IconTooltipButton from "@/Components/buttons/IconTooltip.vue";
import { useHelpers } from "@/Composables/useHelpers";

const { formatDate } = useHelpers();

const props = defineProps({
    domains: {
        type: Array,
        required: true,
    },
    loading: {
        type: Boolean,
        default: false,
    },
    pagination: {
        type: Object,
        default: () => ({}),
    },
});

const emit = defineEmits(["edit", "view", "delete", "change"]);

// Table change handler
const handleChange = (pagination, filters, sorter) => {
    emit("change", { pagination, filters, sorter });
};

// Table columns
const columns = [
    {
        title: "Domain",
        dataIndex: "name",
        key: "name",
        width: "25%",
    },
    {
        title: "Slug",
        dataIndex: "name_slug",
        key: "name_slug",
        width: "15%",
    },
    {
        title: "Country",
        dataIndex: "country_code",
        key: "country_code",
        width: "15%",
    },
    {
        title: "Currency",
        dataIndex: "currency_code",
        key: "currency_code",
        width: "10%",
    },
    {
        title: "Timezone",
        dataIndex: "timezone",
        key: "timezone",
        width: "15%",
    },
    {
        title: "Status",
        dataIndex: "is_active",
        key: "is_active",
        width: "10%",
    },
    {
        title: "Actions",
        key: "actions",
        width: "10%",
    },
];

// Methods
const handleEdit = (domain) => {
    emit("edit", domain);
};

const handleView = (domain) => {
    emit("view", domain);
};

const handleDelete = (domain) => {
    Modal.confirm({
        title: "Delete Domain",
        content: `Are you sure you want to delete "${domain.name}"? This action cannot be undone and will affect all associated data.`,
        okText: "Delete",
        okType: "danger",
        cancelText: "Cancel",
        onOk() {
            return new Promise((resolve) => {
                emit("delete", domain);
                resolve();
            });
        },
        onCancel() {
            // Do nothing on cancel
        },
    });
};

const getStatusColor = (isActive) => {
    return isActive ? "green" : "red";
};

const getStatusText = (isActive) => {
    return isActive ? "Active" : "Inactive";
};
</script>

<template>
    <a-table
        class="ant-table-striped"
        :columns="columns"
        :data-source="domains"
        :row-class-name="
            (_, index) => (index % 2 === 1 ? 'bg-gray-50 group' : 'group')
        "
        :loading="loading"
        :pagination="pagination"
        row-key="id"
        @change="handleChange"
    >
        <!-- Domain Name Column -->
        <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'name'">
                <div class="flex items-center gap-3">
                    <div class="flex-shrink-0">
                        <div
                            class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center"
                        >
                            <IconWorld class="h-5 w-5 text-blue-600" />
                        </div>
                    </div>
                    <div>
                        <div class="font-semibold text-gray-900">
                            <a 
                                :href="`/domains/${record.name_slug}/dashboard`"
                                class="text-blue-600 hover:text-blue-800 hover:underline transition-colors duration-200 flex items-center gap-1"
                                target="_blank"
                                :title="`Go to ${record.name} dashboard`"
                            >
                                {{ record.name }}
                                <IconExternalLink class="h-3 w-3 opacity-70" />
                            </a>
                        </div>
                        <div
                            v-if="record.description"
                            class="text-sm text-gray-500 truncate max-w-xs"
                        >
                            {{ record.description }}
                        </div>
                    </div>
                </div>
            </template>

            <!-- Slug Column -->
            <template v-else-if="column.key === 'name_slug'">
                <span class="font-mono text-sm bg-gray-100 px-2 py-1 rounded">
                    {{ record.name_slug }}
                </span>
            </template>

            <!-- Country Column -->
            <template v-else-if="column.key === 'country_code'">
                <div v-if="record.country_code" class="flex items-center gap-2">
                    <IconMapPin class="h-4 w-4 text-gray-400" />

                    <span>{{ record.country_code }}</span>
                </div>
                <span v-else class="text-gray-400">-</span>
            </template>

            <!-- Currency Column -->
            <template v-else-if="column.key === 'currency_code'">
                <div v-if="record.currency_code" class="flex items-center gap-2">
                    <IconCurrencyDollar class="h-4 w-4 text-gray-400" />
                    <span>{{ record.currency_code }}</span>
                </div>
                <span v-else class="text-gray-400">-</span>
            </template>

            <!-- Timezone Column -->
            <template v-else-if="column.key === 'timezone'">
                <div v-if="record.timezone" class="flex items-center gap-2">
                    <IconClock class="h-4 w-4 text-gray-400" />
                    <span class="text-sm">{{ record.timezone }}</span>
                </div>
                <span v-else class="text-gray-400">-</span>
            </template>

            <!-- Status Column -->
            <template v-else-if="column.key === 'is_active'">
                <a-tag :color="getStatusColor(record.is_active)">
                    {{ getStatusText(record.is_active) }}
                </a-tag>
            </template>

            <!-- Actions Column -->
            <template v-else-if="column.key === 'actions'">
                <div class="flex items-center gap-2">
                    <IconTooltipButton
                        hover="group-hover:bg-blue-500"
                        name="View Details"
                        @click="handleView(record)"
                    >
                        <IconEye size="20" class="mx-auto" />
                    </IconTooltipButton>

                    <IconTooltipButton
                        hover="group-hover:bg-green-500"
                        name="Edit Domain"
                        @click="handleEdit(record)"
                    >
                        <IconEdit size="20" class="mx-auto" />
                    </IconTooltipButton>

                    <IconTooltipButton
                        hover="group-hover:bg-red-500"
                        name="Delete Domain"
                        @click="handleDelete(record)"
                    >
                        <IconTrash size="20" class="mx-auto" />
                    </IconTooltipButton>
                </div>
            </template>
        </template>
    </a-table>
</template>
