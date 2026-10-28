<script setup>
import { computed, ref } from "vue";
import { Link, usePage } from "@inertiajs/vue3";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";

/**
 * Asks the people who can change Settings what the business sells, until someone picks it
 * (Settings → Business type). Until then the product form works as for a retail store. Closing it
 * hides it for this browser session; it comes back next time if the type is still unpicked.
 */
const page = usePage();
const { hasPermission } = usePermissionsV2();
const { getRoute } = useDomainRoutes();

const KEY = "techiko.businessTypeBanner.closed";
const closed = ref(false);
try {
    closed.value = sessionStorage.getItem(KEY) === "1";
} catch {
    // Storage blocked: the banner just shows again.
}

const show = computed(
    () =>
        !closed.value &&
        page.props.businessTypeChosen === false &&
        !route().current("domains.settings.index") &&
        hasPermission("settings.update"),
);

function close() {
    closed.value = true;
    try {
        sessionStorage.setItem(KEY, "1");
    } catch {
        // Nothing to remember it in; closed for this page only.
    }
}
</script>

<template>
    <a-alert
        v-if="show"
        type="info"
        show-icon
        closable
        class="mb-3"
        data-testid="business-type-banner"
        @close="close"
    >
        <template #message>
            What does your business sell? Pick it in
            <Link :href="getRoute('settings.index')" class="font-medium text-blue-600" data-testid="business-type-banner-link">Settings → Business type</Link>
            so new products start with the fields that fit.
        </template>
    </a-alert>
</template>
