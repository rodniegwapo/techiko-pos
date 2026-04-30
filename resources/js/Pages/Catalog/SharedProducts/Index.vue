<script setup>
import { ref, computed, watch } from "vue";
import { Head, router, useForm } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import { message } from "ant-design-vue";
import { PlusSquareOutlined } from "@ant-design/icons-vue";
import { watchDebounced } from "@vueuse/core";

const props = defineProps({
    products: { type: Object, required: true },
    sold_by_types: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const search = ref(props.filters.search || "");

const modalOpen = ref(false);
const editingId = ref(null);

const soldTypeOptions = computed(() =>
    props.sold_by_types.map((s) => ({ label: s.name, value: s.name })),
);

const form = useForm({
    name: "",
    barcode: "",
    description: "",
    category_label: "",
    sold_type: null,
    representation_type: null,
    representation: "",
});

watch(modalOpen, (open) => {
    if (!open) {
        editingId.value = null;
        form.reset();
        form.clearErrors();
    }
});

watchDebounced(search, reloadList, { debounce: 300 });

function reloadList() {
    router.get(
        window.route("catalog.shared-products.index"),
        { search: search.value || undefined },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

function openCreate() {
    editingId.value = null;
    form.reset();
    form.clearErrors();
    modalOpen.value = true;
}

function openEdit(row) {
    editingId.value = row.id;
    form.name = row.name;
    form.barcode = row.barcode;
    form.description = row.description || "";
    form.category_label = row.category_label || "";
    form.sold_type = row.sold_type || null;
    form.representation_type = row.representation_type || null;
    form.representation = row.representation || "";
    form.clearErrors();
    modalOpen.value = true;
}

function submitModal() {
    const onOk = () => {
        modalOpen.value = false;
        message.success(
            editingId.value ? "Shared product updated" : "Shared product created",
        );
    };

    if (editingId.value) {
        form.put(
            window.route("catalog.shared-products.update", {
                shared_product: editingId.value,
            }),
            {
                preserveScroll: true,
                onSuccess: onOk,
            },
        );
    } else {
        form.post(window.route("catalog.shared-products.store"), {
            preserveScroll: true,
            onSuccess: onOk,
        });
    }
}

function confirmDestroy(row) {
    router.delete(
        window.route("catalog.shared-products.destroy", {
            shared_product: row.id,
        }),
        {
            preserveScroll: true,
            onSuccess: () => message.success("Deleted"),
        },
    );
}

const columns = [
    { title: "Barcode", dataIndex: "barcode", key: "barcode" },
    { title: "Name", dataIndex: "name", key: "name" },
    {
        title: "Category hint",
        dataIndex: "category_label",
        key: "category_label",
    },
    { title: "Sold type", dataIndex: "sold_type", key: "sold_type" },
    { title: "Actions", key: "actions", width: 160 },
];

const pagination = computed(() => ({
    current: props.products.current_page ?? 1,
    pageSize: props.products.per_page ?? 20,
    total: props.products.total ?? 0,
    showSizeChanger: false,
}));

function handleTableChange(pag) {
    router.get(
        window.route("catalog.shared-products.index"),
        {
            page: pag?.current || 1,
            search: search.value || undefined,
        },
        { preserveScroll: true, preserveState: true },
    );
}
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Shared catalog" />
        <ContentHeader class="mb-8" title="Shared product catalog" />
        <ContentLayout title="Barcode directory (all tenants)">
            <template #filters>
                <a-input-search
                    v-model:value="search"
                    placeholder="Search by name or barcode"
                    style="max-width: 280px"
                />
                <a-button type="primary" @click="openCreate">
                    <template #icon>
                        <PlusSquareOutlined />
                    </template>
                    Add shared product
                </a-button>
            </template>

            <template #table>
                <a-table
                    :columns="columns"
                    :data-source="products.data"
                    :pagination="pagination"
                    row-key="id"
                    @change="handleTableChange"
                >
                    <template #bodyCell="{ column, record }">
                        <template v-if="column.key === 'category_label'">
                            {{ record.category_label || "—" }}
                        </template>
                        <template v-if="column.key === 'sold_type'">
                            {{ record.sold_type || "—" }}
                        </template>
                        <template v-if="column.key === 'actions'">
                            <a-space>
                                <a-button type="link" size="small" @click="openEdit(record)">
                                    Edit
                                </a-button>
                                <a-popconfirm
                                    title="Remove this barcode from the shared catalog?"
                                    ok-text="Delete"
                                    @confirm="confirmDestroy(record)"
                                >
                                    <a-button danger type="link" size="small">
                                        Delete
                                    </a-button>
                                </a-popconfirm>
                            </a-space>
                        </template>
                    </template>
                </a-table>
            </template>
        </ContentLayout>

        <a-modal
            v-model:open="modalOpen"
            :title="editingId ? 'Edit shared product' : 'Add shared product'"
            ok-text="Save"
            :confirm-loading="form.processing"
            @ok="submitModal"
        >
            <a-form layout="vertical" class="mt-4">
                <a-form-item
                    label="Barcode"
                    required
                    :validate-status="form.errors.barcode ? 'error' : ''"
                    :help="form.errors.barcode"
                >
                    <a-input v-model:value="form.barcode" autocomplete="off" />
                </a-form-item>
                <a-form-item
                    label="Name"
                    required
                    :validate-status="form.errors.name ? 'error' : ''"
                    :help="form.errors.name"
                >
                    <a-input v-model:value="form.name" />
                </a-form-item>
                <a-form-item
                    label="Description"
                    :validate-status="form.errors.description ? 'error' : ''"
                    :help="form.errors.description"
                >
                    <a-textarea v-model:value="form.description" :rows="2" />
                </a-form-item>
                <a-form-item
                    label="Category label (hint)"
                    :validate-status="form.errors.category_label ? 'error' : ''"
                    :help="form.errors.category_label"
                >
                    <a-input v-model:value="form.category_label" />
                </a-form-item>
                <a-form-item
                    label="Sold type"
                    :validate-status="form.errors.sold_type ? 'error' : ''"
                    :help="form.errors.sold_type"
                >
                    <a-select
                        v-model:value="form.sold_type"
                        :options="soldTypeOptions"
                        allow-clear
                        placeholder="Optional"
                        style="width: 100%"
                    />
                </a-form-item>
            </a-form>
        </a-modal>
    </AuthenticatedLayout>
</template>
