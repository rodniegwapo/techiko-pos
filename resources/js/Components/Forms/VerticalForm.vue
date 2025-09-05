<script setup>
import { reactive, ref, watch } from "vue";
import dayjs from "dayjs";

const props = defineProps({
  modelValue: {
    type: Object,
    default: () => ({}),
  },
  fields: {
    type: Array,
    required: true,
  },
});

const emit = defineEmits(["update:modelValue"]);

const formState = reactive({ ...props.modelValue });

// keep formState in sync with parent
watch(
  () => props.modelValue,
  (val) => Object.assign(formState, val)
);

function updateValue(key, val) {
  formState[key] = val;
  emit("update:modelValue", { ...formState });
}

// handle "Check all"
function handleCheckAll(field, e) {
  const allValues = field.options.map((o) =>
    typeof o === "object" ? o.id ?? o.value : o
  );
  updateValue(field.key, e.target.checked ? allValues : []);
}

function isCheckAllChecked(field) {
  return (
    Array.isArray(formState[field.key]) &&
    formState[field.key].length === field.options.length
  );
}

function isCheckAllIndeterminate(field) {
  return (
    Array.isArray(formState[field.key]) &&
    formState[field.key].length > 0 &&
    formState[field.key].length < field.options.length
  );
}

// controls for open/close
const dateOpen = ref(false);
const timeOpen = ref(false);
const datetimeOpen = ref(false);
</script>

<template>
  <a-form
    layout="vertical"
    class="max-h-[400px] overflow-scroll overflow-x-hidden"
  >
    <template v-for="field in fields" :key="field.key">
      <a-form-item :label="field.label">
        <!-- text -->
        <a-input
          v-if="field.type === 'text'"
          :value="formState[field.key]"
          :placeholder="field.placeholder"
          @input="updateValue(field.key, $event.target.value)"
        />

        <!-- number -->
        <a-input-number
          v-else-if="field.type === 'number'"
          :value="formState[field.key]"
          :placeholder="field.placeholder"
          @change="(val) => updateValue(field.key, val)"
          style="width: 100%"
          type="number"
        />

        <!-- textarea -->
        <a-textarea
          v-else-if="field.type === 'textarea'"
          :value="formState[field.key]"
          :placeholder="field.placeholder"
          @input="updateValue(field.key, $event.target.value)"
          :rows="field.rows || 3"
        />

        <!-- select -->
        <a-select
          v-else-if="field.type === 'select'"
          :value="
            field.multiple
              ? Array.isArray(formState[field.key])
                ? formState[field.key]
                : []
              : formState[field.key]
          "
          :options="
            field.options.map((o) =>
              typeof o === 'object'
                ? {
                    label: o.label ?? o.name ?? o.value,
                    value: o.id ?? o.value,
                  }
                : { label: o, value: o }
            )
          "
          :mode="field.multiple ? 'multiple' : undefined"
          @change="(val) => updateValue(field.key, val)"
          allowClear
          class="w-full"
        />

        <!-- date -->
        <a-date-picker
          v-else-if="field.type === 'date'"
          v-model:value="formState[field.key]"
          :placeholder="field.placeholder || 'Select Date'"
          inputReadOnly
          class="w-full"
          :format="field.format || 'ddd, MMM DD, YYYY'"
          :showToday="false"
          :open="dateOpen"
          @openChange="(status) => (dateOpen = status)"
          @change="(val) => updateValue(field.key, val)"
        >
          >
          <template #renderExtraFooter>
            <div class="flex justify-between mt-2">
              <span
                class="cursor-pointer text-blue-400"
                @click="
                  () => {
                    updateValue(field.key, dayjs());
                    dateOpen = false;
                  }
                "
              >
                Today
              </span>
            </div>
          </template>
        </a-date-picker>

        <a-date-picker
          v-else-if="field.type === 'datetime'"
          v-model:value="formState[field.key]"
          :placeholder="field.placeholder || 'Select Date & Time'"
          inputReadOnly
          class="w-full"
          show-time
          :format="field.format || 'ddd, MMM DD, YYYY hh:mm a'"
          @change="(val) => updateValue(field.key, val)"
        />

        <!-- time -->
        <a-time-picker
          v-else-if="field.type === 'time'"
          v-model:value="formState[field.key]"
          :placeholder="field.placeholder || 'Select Time'"
          use12-hours
          :showNow="false"
          :format="field.format || 'hh:mm a'"
          class="w-full"
          :open="timeOpen"
          @openChange="(status) => (timeOpen = status)"
        >
        </a-time-picker>

        <!-- radio -->
        <a-radio-group
          v-else-if="field.type === 'radio'"
          :value="formState[field.key]"
          @change="(e) => updateValue(field.key, e.target.value)"
        >
          <a-radio
            v-for="opt in field.options"
            :key="typeof opt === 'object' ? opt.id ?? opt.value : opt"
            :value="typeof opt === 'object' ? opt.id ?? opt.value : opt"
          >
            {{
              typeof opt === "object" ? opt.label ?? opt.name ?? opt.value : opt
            }}
          </a-radio>
        </a-radio-group>

        <!-- checkbox group with checkAll -->
        <template v-else-if="field.type === 'checkbox-group'">
          <div v-if="field.checkAll" class="mb-2">
            <a-checkbox
              :checked="isCheckAllChecked(field)"
              :indeterminate="isCheckAllIndeterminate(field)"
              @change="(e) => handleCheckAll(field, e)"
            >
              {{ field.checkAllLabel || "Check all" }}
            </a-checkbox>
            <a-divider class="my-2" />
          </div>

          <a-checkbox-group
            :value="formState[field.key]"
            @change="(val) => updateValue(field.key, val)"
          >
            <a-checkbox
              v-for="opt in field.options"
              :key="typeof opt === 'object' ? opt.id ?? opt.value : opt"
              :value="typeof opt === 'object' ? opt.id ?? opt.value : opt"
              :disabled="
                typeof opt === 'object' ? opt.disabled ?? false : false
              "
            >
              {{
                typeof opt === "object"
                  ? opt.label ?? opt.name ?? opt.value
                  : opt
              }}
            </a-checkbox>
          </a-checkbox-group>
        </template>

        <!-- single checkbox -->
        <a-checkbox
          v-else-if="field.type === 'checkbox-single'"
          :checked="!!formState[field.key]"
          @change="(e) => updateValue(field.key, e.target.checked)"
          :disabled="field.disabled ?? false"
        >
          {{ field.text || field.label }}
        </a-checkbox>
      </a-form-item>
    </template>
  </a-form>
</template>
