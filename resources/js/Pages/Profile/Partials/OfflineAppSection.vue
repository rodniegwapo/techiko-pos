<script setup>
defineProps({
    offlineInstallerUrl: {
        type: String,
        default: null,
    },
    orgLicenseSummary: {
        type: Object,
        default: null,
    },
});

const download = (url) => {
    window.open(url, '_blank', 'noopener');
};
</script>

<template>
    <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
        <h2 class="text-lg font-semibold text-gray-900 mb-2">Offline desktop app</h2>
        <p class="text-sm text-gray-600 mb-4">
            Download the desktop POS build for your organization when you have an active license with available device seats.
        </p>

        <div v-if="!offlineInstallerUrl" class="rounded-md bg-amber-50 p-4 text-sm text-amber-900">
            Offline installer URL is not configured. Set <code class="text-xs">OFFLINE_INSTALLER_URL</code> in the environment.
        </div>

        <div v-else-if="orgLicenseSummary && orgLicenseSummary.usable" class="space-y-3">
            <p class="text-sm text-gray-700">
                Seats in use: <strong>{{ orgLicenseSummary.seats_used }}</strong>
                <span v-if="orgLicenseSummary.seats_max != null"> / {{ orgLicenseSummary.seats_max }}</span>
                <span v-if="orgLicenseSummary.days_until_expiration != null" class="ml-2">
                    (expires in {{ orgLicenseSummary.days_until_expiration }} days)
                </span>
            </p>
            <button
                type="button"
                class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700"
                @click="download(offlineInstallerUrl)"
            >
                Download offline installer
            </button>
        </div>

        <div v-else class="rounded-md bg-gray-50 p-4 text-sm text-gray-700">
            <span v-if="!orgLicenseSummary">You need an organization assignment to use the offline app.</span>
            <span v-else-if="!orgLicenseSummary.usable">Your organization does not have an active license for offline devices. Contact your administrator.</span>
        </div>
    </div>
</template>
