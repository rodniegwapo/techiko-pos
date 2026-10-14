<script setup>
import { ref } from "vue";
import { router } from "@inertiajs/vue3";
import { notification } from "ant-design-vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";

defineProps({
    open: { type: Boolean, default: false },
    categories: { type: Array, default: () => [] },
});
const emit = defineEmits(["close"]);

const { getRoute } = useDomainRoutes();

const newName = ref("");
const newType = ref("operating");
const editingId = ref(null);

// Operating costs come before operating profit on the income statement; "other" ones (interest,
// losses, one-off costs) come after it.
const typeOptions = [
    { value: "operating", label: "Operating" },
    { value: "other", label: "Other expense" },
];
const editingName = ref("");
const saving = ref(false);

const handleResult = {
    preserveScroll: true,
    preserveState: true,
    only: ["options", "flash", "errors"],
    onStart: () => (saving.value = true),
    onFinish: () => (saving.value = false),
    onSuccess: (page) => {
        if (page.props.flash?.error) {
            notification.error({ message: "Error", description: page.props.flash.error });
        } else if (page.props.flash?.success) {
            notification.success({ message: "Success", description: page.props.flash.success });
        }
    },
    onError: (errors) => {
        notification.error({ message: "Error", description: Object.values(errors)[0] });
    },
};

const add = () => {
    if (!newName.value.trim()) return;
    router.post(getRoute("expenses.categories.store"), { name: newName.value, type: newType.value }, {
        ...handleResult,
        onSuccess: (page) => {
            handleResult.onSuccess(page);
            newName.value = "";
            newType.value = "operating";
        },
    });
};

const changeType = (category, type) => {
    router.put(getRoute("expenses.categories.update", { category: category.id }), { type }, handleResult);
};

const startEdit = (category) => {
    editingId.value = category.id;
    editingName.value = category.name;
};

const saveEdit = (category) => {
    router.put(getRoute("expenses.categories.update", { category: category.id }), { name: editingName.value }, {
        ...handleResult,
        onSuccess: (page) => {
            handleResult.onSuccess(page);
            editingId.value = null;
        },
    });
};

const toggleActive = (category) => {
    router.put(getRoute("expenses.categories.update", { category: category.id }), { is_active: !category.is_active }, handleResult);
};

const remove = (category) => {
    router.delete(getRoute("expenses.categories.destroy", { category: category.id }), handleResult);
};
</script>

<template>
    <a-modal :visible="open" title="Expense Categories" :footer="null" centered @cancel="emit('close')">
        <div class="mb-4 flex gap-2">
            <a-input
                v-model:value="newName"
                placeholder="New category name"
                :maxlength="100"
                data-testid="new-category-name"
                @press-enter="add"
            />
            <a-select v-model:value="newType" :options="typeOptions" style="width: 150px" data-testid="new-category-type" />
            <a-button type="primary" :loading="saving" :disabled="!newName.trim()" @click="add">Add</a-button>
        </div>

        <ul class="max-h-96 divide-y overflow-y-auto rounded-lg border">
            <li v-for="category in categories" :key="category.id" class="flex items-center gap-2 px-3 py-2">
                <template v-if="editingId === category.id">
                    <a-input v-model:value="editingName" size="small" :maxlength="100" @press-enter="saveEdit(category)" />
                    <a-button size="small" type="primary" @click="saveEdit(category)">Save</a-button>
                    <a-button size="small" @click="editingId = null">Cancel</a-button>
                </template>
                <template v-else>
                    <span class="flex-1" :class="{ 'text-gray-400 line-through': !category.is_active }">
                        {{ category.name }}
                    </span>
                    <a-select
                        :value="category.type ?? 'operating'"
                        :options="typeOptions"
                        size="small"
                        style="width: 130px"
                        @change="(type) => changeType(category, type)"
                    />
                    <span class="text-xs text-gray-400">{{ category.expenses_count }} used</span>
                    <a-button size="small" type="link" @click="startEdit(category)">Rename</a-button>
                    <a-button size="small" type="link" @click="toggleActive(category)">
                        {{ category.is_active ? "Deactivate" : "Activate" }}
                    </a-button>
                    <a-popconfirm
                        v-if="!category.expenses_count"
                        title="Delete this category?"
                        ok-text="Delete"
                        @confirm="remove(category)"
                    >
                        <a-button size="small" type="link" danger>Delete</a-button>
                    </a-popconfirm>
                </template>
            </li>
        </ul>
        <p class="mb-0 mt-3 text-xs text-gray-500">
            Operating categories are day-to-day running costs. “Other expense” categories (interest, losses, one-off costs)
            are shown after operating profit on the income statement.
        </p>
        <p class="mb-0 mt-2 text-xs text-gray-500">
            Categories already used by expenses can't be deleted, because past reports still need them. Deactivate them to
            hide them from new expenses.
        </p>
    </a-modal>
</template>
