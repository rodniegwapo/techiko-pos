<script setup>
import { computed, ref } from "vue";
import { Head, router } from "@inertiajs/vue3";
import { notification, Modal } from "ant-design-vue";
import { PlusSquareOutlined, PlusOutlined, DeleteOutlined } from "@ant-design/icons-vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useHelpers } from "@/Composables/useHelpers";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";

/**
 * Product modifiers (add-ons): option groups such as Size or Add-ons, picked at the POS when a
 * product they're attached to is rung up.
 */
const props = defineProps({
    groups: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
});

const { getRoute } = useDomainRoutes();
const { formattedTotal } = useHelpers();
const { hasPermission } = usePermissionsV2();

const canEdit = computed(() => hasPermission("products.modifier-groups.update"));
const canCreate = computed(() => hasPermission("products.modifier-groups.store"));
const canDelete = computed(() => hasPermission("products.modifier-groups.destroy"));

const columns = [
    { title: "Group", key: "name" },
    { title: "Options", key: "options" },
    { title: "Pick", key: "pick", width: 160 },
    { title: "Products", key: "products", width: 120, align: "center" },
    { title: "", key: "actions", width: 160, align: "right" },
];

const blankOption = () => ({ id: null, name: "", price_delta: 0, cost_delta: 0, is_active: true });
const blankGroup = () => ({
    id: null,
    name: "",
    selection: "single",
    is_required: false,
    max_select: null,
    modifiers: [blankOption(), blankOption()],
    product_ids: [],
});

const open = ref(false);
const saving = ref(false);
const form = ref(blankGroup());
const errors = ref({});

const productOptions = computed(() =>
    props.products.map((p) => ({ value: p.id, label: p.name })),
);

function openCreate() {
    form.value = blankGroup();
    errors.value = {};
    open.value = true;
}

function openEdit(group) {
    form.value = {
        id: group.id,
        name: group.name,
        selection: group.selection,
        is_required: !!group.is_required,
        max_select: group.max_select,
        modifiers: group.modifiers.map((m) => ({
            id: m.id,
            name: m.name,
            price_delta: Number(m.price_delta),
            cost_delta: Number(m.cost_delta),
            is_active: !!m.is_active,
        })),
        product_ids: (group.products || []).map((p) => p.id),
    };
    errors.value = {};
    open.value = true;
}

function addOption() {
    form.value.modifiers.push(blankOption());
}

function removeOption(i) {
    form.value.modifiers.splice(i, 1);
}

function pickLabel(group) {
    const base =
        group.selection === "single"
            ? "One"
            : group.max_select
              ? `Up to ${group.max_select}`
              : "Any";
    return group.is_required ? `${base} · required` : `${base} · optional`;
}

function priceLabel(delta) {
    const n = Number(delta);
    if (!n) return "";
    return n > 0 ? ` +${formattedTotal(n)}` : ` −${formattedTotal(-n)}`;
}

function save() {
    saving.value = true;
    errors.value = {};
    const payload = {
        ...form.value,
        max_select: form.value.selection === "multiple" ? form.value.max_select : null,
    };
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            notification.success({ message: form.value.id ? "Modifier group updated" : "Modifier group created" });
        },
        onError: (e) => (errors.value = e),
        onFinish: () => (saving.value = false),
    };

    if (form.value.id) {
        router.put(getRoute("products.modifier-groups.update", { modifier_group: form.value.id }), payload, options);
    } else {
        router.post(getRoute("products.modifier-groups.store"), payload, options);
    }
}

function confirmDelete(group) {
    Modal.confirm({
        title: `Delete “${group.name}”?`,
        content: "Products lose these options. Sales already made keep them.",
        okText: "Delete",
        okType: "danger",
        onOk: () =>
            new Promise((resolve) => {
                router.delete(getRoute("products.modifier-groups.destroy", { modifier_group: group.id }), {
                    preserveScroll: true,
                    onFinish: resolve,
                });
            }),
    });
}

const optionError = (i, field) => errors.value[`modifiers.${i}.${field}`];
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Product Modifiers" />
        <ContentHeader class="mb-4 md:mb-8" title="Product Modifiers" />
        <ContentLayout
            title="Modifiers"
            filter-class="flex flex-wrap items-center justify-end gap-2 w-full min-w-0"
        >
            <template #filters>
                <a-button
                    v-if="canCreate"
                    type="primary"
                    class="flex w-full items-center justify-center border border-green-500 bg-white text-green-500 md:inline-flex md:w-auto"
                    @click="openCreate"
                >
                    <template #icon><PlusSquareOutlined /></template>
                    Create Modifier Group
                </a-button>
            </template>

            <template #table>
                <p class="mb-3 text-sm text-gray-500">
                    Option groups such as Size or Add-ons. Attach a group to products and the
                    cashier picks the options when ringing them up; each option can add to the price.
                </p>
                <a-table
                    :columns="columns"
                    :data-source="groups"
                    :pagination="false"
                    row-key="id"
                    :scroll="{ x: 720 }"
                >
                    <template #emptyText>
                        No modifier groups yet. Create one such as “Size” with Small, Medium and Large.
                    </template>
                    <template #bodyCell="{ column, record }">
                        <template v-if="column.key === 'name'">
                            <span class="font-medium">{{ record.name }}</span>
                        </template>
                        <template v-else-if="column.key === 'options'">
                            <span
                                v-for="(m, i) in record.modifiers"
                                :key="m.id"
                                :class="m.is_active ? '' : 'text-gray-400 line-through'"
                            >{{ m.name }}{{ priceLabel(m.price_delta) }}<span v-if="i < record.modifiers.length - 1">, </span></span>
                        </template>
                        <template v-else-if="column.key === 'pick'">
                            {{ pickLabel(record) }}
                        </template>
                        <template v-else-if="column.key === 'products'">
                            <a-tooltip :title="(record.products || []).map((p) => p.name).join(', ') || 'None'">
                                {{ (record.products || []).length }}
                            </a-tooltip>
                        </template>
                        <template v-else-if="column.key === 'actions'">
                            <a-button v-if="canEdit" size="small" type="link" @click="openEdit(record)">Edit</a-button>
                            <a-button v-if="canDelete" size="small" type="link" danger @click="confirmDelete(record)">Delete</a-button>
                        </template>
                    </template>
                </a-table>
            </template>
        </ContentLayout>

        <a-modal
            v-model:visible="open"
            :title="form.id ? 'Edit Modifier Group' : 'Create Modifier Group'"
            :confirm-loading="saving"
            ok-text="Save"
            width="720px"
            @ok="save"
        >
            <a-form layout="vertical">
                <a-form-item
                    label="Group name"
                    :validate-status="errors.name ? 'error' : ''"
                    :help="errors.name"
                >
                    <a-input v-model:value="form.name" placeholder="e.g. Size, Sugar level, Add-ons" :maxlength="100" />
                </a-form-item>

                <div class="grid grid-cols-1 gap-x-4 sm:grid-cols-3">
                    <a-form-item label="Cashier picks">
                        <a-radio-group v-model:value="form.selection" button-style="solid">
                            <a-radio-button value="single">One</a-radio-button>
                            <a-radio-button value="multiple">Several</a-radio-button>
                        </a-radio-group>
                    </a-form-item>
                    <a-form-item
                        v-if="form.selection === 'multiple'"
                        label="At most (blank: no limit)"
                        :validate-status="errors.max_select ? 'error' : ''"
                        :help="errors.max_select"
                    >
                        <a-input-number v-model:value="form.max_select" :min="1" :max="50" :precision="0" style="width: 100%" />
                    </a-form-item>
                    <a-form-item label="Required">
                        <a-switch v-model:checked="form.is_required" />
                        <span class="ml-2 text-sm text-gray-500">{{ form.is_required ? "Must pick" : "Optional" }}</span>
                    </a-form-item>
                </div>

                <div class="mb-2 text-sm font-medium">Options</div>
                <div
                    v-if="errors.modifiers"
                    class="mb-2 text-sm text-red-600"
                >{{ errors.modifiers }}</div>
                <div class="mb-1 hidden grid-cols-12 gap-2 text-xs text-gray-500 sm:grid">
                    <span class="col-span-5">Name</span>
                    <span class="col-span-3">Adds to price</span>
                    <span class="col-span-2">Adds to cost</span>
                    <span class="col-span-2">On sale</span>
                </div>
                <div
                    v-for="(opt, i) in form.modifiers"
                    :key="i"
                    class="mb-2 grid grid-cols-12 items-start gap-2"
                >
                    <div class="col-span-12 sm:col-span-5">
                        <a-input v-model:value="opt.name" placeholder="e.g. Large" :maxlength="100" :status="optionError(i, 'name') ? 'error' : ''" />
                        <div v-if="optionError(i, 'name')" class="text-xs text-red-600">{{ optionError(i, "name") }}</div>
                    </div>
                    <a-input-number v-model:value="opt.price_delta" class="col-span-5 sm:col-span-3" style="width: 100%" :precision="2" :step="5" prefix="₱" />
                    <a-input-number v-model:value="opt.cost_delta" class="col-span-4 sm:col-span-2" style="width: 100%" :precision="2" :step="5" />
                    <div class="col-span-3 flex items-center gap-1 sm:col-span-2">
                        <a-switch v-model:checked="opt.is_active" size="small" />
                        <a-button
                            v-if="form.modifiers.length > 1"
                            type="text"
                            size="small"
                            danger
                            aria-label="Remove option"
                            @click="removeOption(i)"
                        ><DeleteOutlined /></a-button>
                    </div>
                </div>
                <a-button type="dashed" size="small" class="mb-4" @click="addOption">
                    <PlusOutlined /> Add option
                </a-button>

                <a-form-item
                    label="Products with this group"
                    :validate-status="errors.product_ids ? 'error' : ''"
                    :help="errors.product_ids"
                >
                    <a-select
                        v-model:value="form.product_ids"
                        mode="multiple"
                        :options="productOptions"
                        option-filter-prop="label"
                        placeholder="Pick the products these options apply to"
                        allow-clear
                    />
                </a-form-item>
            </a-form>
        </a-modal>
    </AuthenticatedLayout>
</template>
