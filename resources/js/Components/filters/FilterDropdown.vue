<script setup>
import { ref, computed, watch } from "vue";
import { FilterOutlined, CloseOutlined } from "@ant-design/icons-vue";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";

const { formFilters } = useGlobalVariables();

const props = defineProps({
  title: { type: String, default: "Filters" },
  resetText: { type: String, default: "Reset Filters" },
  width: { type: String, default: "350px" },
  filters: {
    type: Array,
    default: () => [],
  },
  modelValue: {
    type: Object,
    default: () => ({}),
  },
});

const emit = defineEmits(["update:modelValue", "reset"]);

// ✅ Initialize without reassigning
formFilters.value = { ...props.modelValue };

// sync when parent updates modelValue
watch(
  () => props.modelValue,
  (newVal) => {
    formFilters.value = { ...newVal };
  },
  { deep: true }
);

const resetFilters = () => {
  formFilters.value = {};
  emit("update:modelValue", {});
  emit("reset");
};

const updateValue = (key, value) => {
  formFilters.value[key] = value;
  emit("update:modelValue", { ...formFilters.value });
};

const countActiveFilters = computed(
  () =>
    Object.values(formFilters.value).filter(
      (v) => v !== null && v !== undefined && v !== "" && (!Array.isArray(v) || v.length > 0)
    ).length
);

// 🔹 Normalize options so both ["val"] and [{label, value}] work
const normalizeOptions = (options) => {
  return (options ?? []).map((o) =>
    typeof o === "object" && o !== null ? o : { label: String(o), value: o }
  );
};
</script>

<template>
  <a-popover trigger="click" placement="bottomRight">
    <template #title>
      <div class="flex justify-between items-center">
        <span>Filters:</span>
        <a-button
          type="link"
          class="text-red-400 -mr-[18px]"
          @click.prevent="resetFilters"
        >
          {{ resetText }}
        </a-button>
      </div>
    </template>

    <template #content>
      <div :style="{ width: width }">
        <a-form layout="vertical">
          <template v-for="filter in filters" :key="filter.key">
            <a-form-item :label="filter.label">
              <!-- Text -->
              <a-input
                v-if="filter.type === 'text'"
                :value="formFilters[filter.key]"
                @input="updateValue(filter.key, $event.target.value)"
              />

              <!-- Select -->
              <a-select
                v-else-if="filter.type === 'select'"
                :value="formFilters[filter.key]"
                :options="normalizeOptions(filter.options)"
                @change="(val) => updateValue(filter.key, val)"
                allowClear
              />

              <!-- Radio -->
              <a-radio-group
                v-else-if="filter.type === 'radio'"
                :value="formFilters[filter.key]"
                @change="(e) => updateValue(filter.key, e.target.value)"
              >
                <a-radio
                  v-for="opt in normalizeOptions(filter.options)"
                  :key="opt.value"
                  :value="opt.value"
                >
                  {{ opt.label }}
                </a-radio>
              </a-radio-group>

              <!-- Checkbox -->
              <a-checkbox-group
                v-else-if="filter.type === 'checkbox'"
                :value="formFilters[filter.key]"
                :options="normalizeOptions(filter.options)"
                @change="(val) => updateValue(filter.key, val)"
              />

              <!-- Date Range -->
              <a-range-picker
                v-else-if="filter.type === 'range'"
                :value="formFilters[filter.key]"
                @change="(val) => updateValue(filter.key, val)"
                format="ddd, MMM DD, YYYY"
                :allowClear="true"
              />
            </a-form-item>
          </template>
        </a-form>
      </div>
    </template>

    <a-badge :count="countActiveFilters" color="blue">
      <a-button>
        <template #icon>
          <FilterOutlined />
        </template>
      </a-button>
    </a-badge>
  </a-popover>
</template>
