<script setup>
import { ref, computed, watch } from "vue";
import { FilterOutlined } from "@ant-design/icons-vue";
import { useI18n } from "vue-i18n";

const { t } = useI18n();

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

const formState = ref({ ...props.modelValue });

// ✅ sync when parent updates modelValue
watch(
  () => props.modelValue,
  (newVal) => {
    formState.value = { ...newVal };
  },
  { deep: true }
);

const resetFilters = () => {
  formState.value = {};
  emit("update:modelValue", {});
  emit("reset");
};

const updateValue = (key, value) => {
  formState.value[key] = value;
  emit("update:modelValue", { ...formState.value });
};

const countActiveFilters = computed(
  () => Object.values(formState.value).filter((v) => v && v.length !== 0).length
);
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
              <a-input
                v-if="filter.type === 'text'"
                :value="formState[filter.key]"
                @input="updateValue(filter.key, $event.target.value)"
              />

              <a-select
                v-else-if="filter.type === 'select'"
                :value="formState[filter.key]"
                :options="
                  filter.options.map((o) =>
                    typeof o === 'object' ? o : { label: o, value: o }
                  )
                "
                @change="(val) => updateValue(filter.key, val)"
                allowClear
              />
              <a-range-picker
                v-else-if="filter.type === 'range'"
                :value="formState[filter.key]"
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
