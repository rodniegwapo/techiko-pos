<script setup>
import { reactive, ref, watch } from "vue";
import dayjs from "dayjs";
import { useGlobalVariables } from "@/Composables/useGlobalVariable";

const { errors } = useGlobalVariables();

const props = defineProps({
  modelValue: {
    type: Object,
    default: () => ({}),
  },
  fields: {
    type: Array,
    required: true,
    /**
     * Reusable Dynamic Form Component
     *
     * Example fields config:
     * [
     *   { key: "name", label: "Name", type: "text" },
     *   { key: "email", label: "Email", type: "text", placeholder: "Enter your email" },
     *   { key: "role", label: "Role", type: "select", options: ["Admin", "User"] },
     *   { key: "dob", label: "Date of Birth", type: "date" },
     *   { key: "bio", label: "Biography", type: "textarea", rows: 4 },
     *   { key: "active", label: "Active", type: "checkbox-single", text: "Is Active?" },
     *   {
     *     key: "permissions",
     *     label: "Permissions",
     *     type: "checkbox-group",
     *     checkAll: true,
     *     options: ["Read", "Write", "Delete"],
     *   },
     * ]
     *
     * Usage:
     * <DynamicForm v-model="formData" :fields="fields" :errors="errors" />
     *
     * // In parent:
     * const formData = ref({})
     * const errors = ref({})
     *
     * // After submit (Laravel backend example):
     * errors.value = {
     *   name: "The name field is required.",
     *   email: "The email must be a valid email address."
     * }
     */
  },
});

const emit = defineEmits(["update:modelValue"]);

const formState = reactive({ ...props.modelValue });

// keep formState in sync with parent
watch(
  () => props.modelValue,
  (val) => {
    if (!val || Object.keys(val).length === 0) {
      Object.keys(formState).forEach((k) => (formState[k] = undefined));
    } else {
      Object.assign(formState, val);
    }
  },
  { deep: true }
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
</script>

<template>
  <a-form
    layout="vertical"
    class="max-h-[400px] overflow-scroll overflow-x-hidden"
  >
    <template v-for="field in fields" :key="field.key">
      <a-form-item
        :label="field.label"
        :validate-status="errors[field.key] ? 'error' : ''"
        :help="errors[field.key] || ''"
      >
        <!-- text -->
        <a-input
          v-if="field.type === 'text'"
          :value="formState[field.key]"
          :placeholder="field.placeholder"
          @input="updateValue(field.key, $event.target.value)"
          size="large"
          :disabled="field.disabled ?? false"
        />

        <a-input
          v-else-if="field.type === 'password'"
          :value="formState[field.key]"
          :placeholder="field.placeholder"
          @input="updateValue(field.key, $event.target.value)"
          size="large"
          type="password"
        />

        <!-- number -->
        <a-input-number
          v-else-if="field.type === 'number'"
          :value="formState[field.key]"
          :placeholder="field.placeholder"
          @change="(val) => updateValue(field.key, val)"
          style="width: 100%"
          type="number"
          size="large"
          :disabled="field.disabled ?? false"
        />

        <!-- textarea -->
        <a-textarea
          v-else-if="field.type === 'textarea'"
          :value="formState[field.key]"
          :placeholder="field.placeholder"
          @input="updateValue(field.key, $event.target.value)"
          :rows="field.rows || 3"
          size="large"
        />

        <!-- select -->
        <a-select
          v-else-if="field.type === 'select'"
          show-search
          :value="
            field.multiple
              ? (formState[field.key] || []).map((v) => v.id ?? v.value ?? v)
              : formState[field.key]?.id ??
                formState[field.key]?.value ??
                formState[field.key]
          "
          :options="
            field.options.map((o) =>
              typeof o === 'object'
                ? {
                    label: o.label ?? o.name ?? o.value,
                    value: o.id ?? o.value ?? o,
                  }
                : { label: o, value: o }
            )
          "
          :mode="field.multiple ? 'multiple' : undefined"
          :allowClear="field.isAllowClear ?? true"
          class="w-full"
          @change="
            (val) => {
              if (field.multiple) {
                updateValue(
                  field.key,
                  val.map((v) =>
                    field.options.find((o) => (o.id ?? o.value ?? o) === v)
                  )
                );
              } else {
                updateValue(
                  field.key,
                  field.options.find((o) => (o.id ?? o.value ?? o) === val)
                );
              }
            }
          "
          :filterOption="
            (input, option) =>
              option.label.toLowerCase().includes(input.toLowerCase())
          "
          size="large"
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
          size="large"
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

        <!-- datetime -->
        <a-date-picker
          v-else-if="field.type === 'datetime'"
          v-model:value="formState[field.key]"
          :placeholder="field.placeholder || 'Select Date & Time'"
          inputReadOnly
          class="w-full"
          show-time
          :format="field.format || 'ddd, MMM DD, YYYY hh:mm a'"
          @change="(val) => updateValue(field.key, val)"
          size="large"
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
          size="large"
        />

        <!-- radio -->
        <a-radio-group
          v-else-if="field.type === 'radio'"
          :value="formState[field.key]?.value ?? formState[field.key]"
          @change="
            (e) => {
              updateValue(
                field.key,
                field.options.find(
                  (o) => (o.value ?? o.id ?? o) === e.target.value
                )
              );
            }
          "
          size="large"
        >
          <a-radio
            v-for="opt in field.options"
            :key="typeof opt === 'object' ? opt.value ?? opt.id : opt"
            :value="typeof opt === 'object' ? opt.value ?? opt.id : opt"
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
              size="large"
            >
              {{ field.checkAllLabel || "Check all" }}
            </a-checkbox>
            <a-divider class="my-2" />
          </div>

          <a-checkbox-group
            :value="
              (formState[field.key] || []).map((v) => v.value ?? v.id ?? v)
            "
            @change="
              (val) =>
                updateValue(
                  field.key,
                  val.map((v) =>
                    field.options.find((o) => (o.value ?? o.id ?? o) === v)
                  )
                )
            "
            size="large"
          >
            <a-checkbox
              v-for="opt in field.options"
              :key="typeof opt === 'object' ? opt.value ?? opt.id : opt"
              :value="typeof opt === 'object' ? opt.value ?? opt.id : opt"
              :disabled="
                typeof opt === 'object' ? opt.disabled ?? false : false
              "
              size="large"
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
          :checked="formState[field.key] === (field.value ?? true)"
          @change="
            (e) =>
              updateValue(
                field.key,
                e.target.checked
                  ? field.value ?? true
                  : field.uncheckedValue ?? null
              )
          "
          :disabled="field.disabled ?? false"
          size="large"
        >
          {{ field.text || field.label }}
        </a-checkbox>
      </a-form-item>
    </template>
  </a-form>
</template>
