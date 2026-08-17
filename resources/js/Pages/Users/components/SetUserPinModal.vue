<script setup>
import { ref, watch, computed } from "vue";
import { useMediaQuery } from "@vueuse/core";
import axios from "axios";
import { usePage } from "@inertiajs/vue3";
import { notification } from "ant-design-vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";

const props = defineProps({
    visible: { type: Boolean, default: false },
    user: { type: Object, default: null },
});

const emit = defineEmits(["update:visible", "saved"]);

const page = usePage();
const { getRoute } = useDomainRoutes();

const isMdUp = useMediaQuery("(min-width: 768px)");
const modalWidth = computed(() =>
    isMdUp.value ? 420 : "calc(100vw - 24px)",
);
const modalRootStyle = computed(() =>
    isMdUp.value ? {} : { maxWidth: "100vw", top: "12px", paddingBottom: 0 },
);
const modalBodyStyle = computed(() =>
    isMdUp.value ? {} : { maxHeight: "calc(100vh - 120px)", overflowY: "auto" },
);

const pinCode = ref("");
const pinConfirm = ref("");
const saving = ref(false);
const fieldErrors = ref({});

const userData = computed(() => props.user?.data || props.user);

const domainSlug = computed(() => {
    return (
        page.props.currentDomain?.name_slug || userData.value?.domain || null
    );
});

watch(
    () => props.visible,
    (open) => {
        if (open) {
            pinCode.value = "";
            pinConfirm.value = "";
            fieldErrors.value = {};
        }
    },
);

function close() {
    emit("update:visible", false);
}

async function handleSubmit() {
    fieldErrors.value = {};
    const uid = userData.value?.id;
    const domain = domainSlug.value;
    if (!uid || !domain) {
        notification.error({
            message: "Cannot set PIN",
            description: "Missing user or domain context.",
        });
        return;
    }

    saving.value = true;
    try {
        const url = getRoute("users.pin.update", {
            domain,
            user: uid,
        });
        if (!url || url === "#") {
            throw new Error("Invalid route for set PIN");
        }
        await axios.put(url, {
            pin_code: pinCode.value,
            pin_code_confirmation: pinConfirm.value,
        });
        notification.success({
            message: "PIN saved",
            description: `PIN was updated for ${userData.value?.name || "user"}.`,
        });
        emit("saved");
        close();
    } catch (e) {
        const status = e.response?.status;
        const errs = e.response?.data?.errors;
        if (errs) {
            fieldErrors.value = errs;
        }
        if (status !== 422) {
            notification.error({
                message: "Could not save PIN",
                description:
                    e.response?.data?.message ||
                    e.message ||
                    "Please check the form and try again.",
            });
        }
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <a-modal
        :visible="visible"
        title="Set user PIN"
        :confirm-loading="saving"
        ok-text="Save PIN"
        :width="modalWidth"
        :style="modalRootStyle"
        :body-style="modalBodyStyle"
        wrap-class-name="modal-footer-full-mobile"
        centered
        :mask-closable="false"
        @update:visible="emit('update:visible', $event)"
        @ok="handleSubmit"
        @cancel="close"
    >
        <p v-if="userData" class="text-sm text-gray-600 mb-4">
            Set a 4–6 digit PIN for
            <strong>{{ userData.name }}</strong>
            (POS void and approvals).
        </p>
        <a-form layout="vertical" @submit.prevent="handleSubmit">
            <a-form-item
                label="PIN"
                :validate-status="fieldErrors.pin_code ? 'error' : ''"
                :help="
                    Array.isArray(fieldErrors.pin_code)
                        ? fieldErrors.pin_code[0]
                        : fieldErrors.pin_code || ''
                "
            >
                <a-input-password
                    v-model:value="pinCode"
                    placeholder="4–6 digits"
                    maxlength="6"
                    autocomplete="new-password"
                    @press-enter="handleSubmit"
                />
            </a-form-item>
            <a-form-item
                label="Confirm PIN"
                :validate-status="fieldErrors.pin_code_confirmation ? 'error' : ''"
                :help="
                    Array.isArray(fieldErrors.pin_code_confirmation)
                        ? fieldErrors.pin_code_confirmation[0]
                        : fieldErrors.pin_code_confirmation || ''
                "
            >
                <a-input-password
                    v-model:value="pinConfirm"
                    placeholder="Repeat PIN"
                    maxlength="6"
                    autocomplete="new-password"
                    @press-enter="handleSubmit"
                />
            </a-form-item>
        </a-form>
    </a-modal>
</template>
