<script setup>
import { ref, computed } from "vue";
import { Head, router } from "@inertiajs/vue3";
import axios from "axios";
import { notification } from "ant-design-vue";

import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";

const { getRoute } = useDomainRoutes();
const { hasPermission } = usePermissionsV2();

const props = defineProps({
    cardTypes: {
        type: Array,
        default: () => [],
    },
});

const rows = computed(() => props.cardTypes ?? []);

const modalOpen = ref(false);
const editing = ref(null);
const formName = ref("");
const formSortOrder = ref(0);
const formActive = ref(true);
const saving = ref(false);

function openCreate() {
    editing.value = null;
    formName.value = "";
    formSortOrder.value = 0;
    formActive.value = true;
    modalOpen.value = true;
}

function openEdit(row) {
    editing.value = row;
    formName.value = row.name;
    formSortOrder.value = row.sort_order ?? 0;
    formActive.value = !!row.is_active;
    modalOpen.value = true;
}

function closeModal() {
    modalOpen.value = false;
    editing.value = null;
}

async function save() {
    const name = String(formName.value || "").trim();
    if (!name) {
        notification.warning({ message: "Name is required." });
        return;
    }
    saving.value = true;
    try {
        if (editing.value?.id) {
            await axios.put(
                getRoute("payment-card-types.update", {
                    paymentCardType: editing.value.id,
                }),
                {
                    name,
                    sort_order: Number(formSortOrder.value) || 0,
                    is_active: formActive.value,
                },
            );
            notification.success({ message: "Card type updated." });
        } else {
            await axios.post(getRoute("payment-card-types.store"), {
                name,
                sort_order: Number(formSortOrder.value) || 0,
            });
            notification.success({ message: "Card type created." });
        }
        closeModal();
        router.reload({ only: ["cardTypes"] });
    } catch (e) {
        const msg =
            e.response?.data?.message ||
            e.message ||
            "Could not save card type.";
        notification.error({ message: msg });
    } finally {
        saving.value = false;
    }
}

const deletingId = ref(null);

async function remove(row) {
    deletingId.value = row.id;
    try {
        await axios.delete(
            getRoute("payment-card-types.destroy", {
                paymentCardType: row.id,
            }),
        );
        notification.success({ message: "Done." });
        router.reload({ only: ["cardTypes"] });
    } catch (e) {
        const msg =
            e.response?.data?.message ||
            e.message ||
            "Could not remove card type.";
        notification.error({ message: msg });
    } finally {
        deletingId.value = null;
    }
}

const columns = [
    { title: "Name", dataIndex: "name", key: "name" },
    { title: "Sort", dataIndex: "sort_order", key: "sort_order", width: 90 },
    {
        title: "Status",
        key: "is_active",
        width: 100,
    },
    { title: "", key: "actions", width: 160 },
];
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Payment wallet" />
        <ContentHeader class="mb-8" title="Payment wallet" />
        <ContentLayout title="Card payment types">
            <template #filters>
                <a-button
                    v-if="hasPermission('payment-card-types.store')"
                    type="primary"
                    class="bg-green-600 border-green-600"
                    @click="openCreate"
                >
                    Add card type
                </a-button>
            </template>

            <template #table>
                <a-table
                    :columns="columns"
                    :data-source="rows"
                    :pagination="false"
                    row-key="id"
                    :locale="{ emptyText: 'No card types yet. Add one to use Pay in Card on Sales.' }"
                >
                    <template #bodyCell="{ column, record }">
                        <template v-if="column.key === 'is_active'">
                            <a-tag :color="record.is_active ? 'green' : 'default'">
                                {{ record.is_active ? "Active" : "Inactive" }}
                            </a-tag>
                        </template>
                        <template v-else-if="column.key === 'actions'">
                            <a-space>
                                <a-button
                                    v-if="hasPermission('payment-card-types.update')"
                                    type="link"
                                    size="small"
                                    @click="openEdit(record)"
                                >
                                    Edit
                                </a-button>
                                <a-button
                                    v-if="hasPermission('payment-card-types.destroy')"
                                    type="link"
                                    danger
                                    size="small"
                                    :loading="deletingId === record.id"
                                    @click="remove(record)"
                                >
                                    Remove
                                </a-button>
                            </a-space>
                        </template>
                    </template>
                </a-table>
            </template>
        </ContentLayout>

        <a-modal
            v-model:open="modalOpen"
            :title="editing ? 'Edit card type' : 'Add card type'"
            :confirm-loading="saving"
            ok-text="Save"
            destroy-on-close
            @ok="save"
            @cancel="closeModal"
        >
            <div class="flex flex-col gap-4 pt-2">
                <div>
                    <div class="text-sm text-gray-600 mb-1">Display name</div>
                    <a-input
                        v-model:value="formName"
                        placeholder="e.g. BDO POS, Visa terminal"
                        maxlength="255"
                    />
                </div>
                <div>
                    <div class="text-sm text-gray-600 mb-1">Sort order</div>
                    <a-input-number
                        v-model:value="formSortOrder"
                        :min="0"
                        :max="65535"
                        class="w-full"
                    />
                </div>
                <div
                    v-if="editing"
                    class="flex items-center justify-between gap-4"
                >
                    <span class="text-sm text-gray-600">Active</span>
                    <a-switch v-model:checked="formActive" />
                </div>
            </div>
        </a-modal>
    </AuthenticatedLayout>
</template>
