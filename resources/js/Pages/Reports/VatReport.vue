<script setup>
import { ref, watch } from "vue";
import { Head, router, usePage } from "@inertiajs/vue3";
import dayjs from "dayjs";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useHelpers } from "@/Composables/useHelpers";

const page = usePage();
const { getRoute } = useDomainRoutes();
const { formattedTotal } = useHelpers();

const props = defineProps({
    filters: {
        type: Object,
        default: () => ({}),
    },
    summary: {
        type: Object,
        default: () => ({
            total_vat: 0,
            gross_sales: 0,
            sales_count: 0,
        }),
    },
    locations: {
        type: Array,
        default: () => [],
    },
});

const domainName =
    page.props.currentDomain?.name ?? page.props.domain?.name ?? "Organization";

const dateRange = ref([
    props.filters?.start_date ? dayjs(props.filters.start_date) : null,
    props.filters?.end_date ? dayjs(props.filters.end_date) : null,
]);
const locationId = ref(props.filters?.location_id ?? null);

watch(
    () => props.filters,
    (f) => {
        dateRange.value = [
            f?.start_date ? dayjs(f.start_date) : null,
            f?.end_date ? dayjs(f.end_date) : null,
        ];
        locationId.value = f?.location_id ?? null;
    },
    { deep: true },
);

function applyFilters() {
    const start = dateRange.value?.[0]?.format?.("YYYY-MM-DD");
    const end = dateRange.value?.[1]?.format?.("YYYY-MM-DD");
    router.get(
        getRoute("vat-report.index"),
        {
            start_date: start,
            end_date: end,
            location_id: locationId.value || undefined,
        },
        { preserveState: true, replace: true },
    );
}
</script>

<template>
    <AuthenticatedLayout>
        <Head title="VAT report" />
        <ContentHeader class="mb-6" :title="`VAT report — ${domainName}`" />
        <ContentLayout title="Output VAT (paid sales)">
            <template #table>
                <div class="px-6 pt-2 pb-8 space-y-6 max-w-5xl">
                    <p class="text-sm text-gray-600">
                        Totals use
                        <strong>paid</strong> sales only, filtered by
                        <strong>transaction date</strong>. This supports
                        reconciliation and filing prep; it is not a substitute
                        for full input VAT unless you track purchases separately.
                    </p>

                    <div class="flex flex-wrap items-end gap-4">
                        <div class="flex flex-col gap-1">
                            <span class="text-xs text-gray-600">Date range</span>
                            <a-range-picker
                                v-model:value="dateRange"
                                format="YYYY-MM-DD"
                                class="min-w-[260px]"
                            />
                        </div>
                        <div class="flex flex-col gap-1">
                            <span class="text-xs text-gray-600">Location</span>
                            <a-select
                                v-model:value="locationId"
                                allow-clear
                                placeholder="All locations"
                                class="min-w-[200px]"
                                :options="
                                    (locations || []).map((l) => ({
                                        value: l.id,
                                        label: l.name,
                                    }))
                                "
                            />
                        </div>
                        <a-button type="primary" @click="applyFilters">
                            Apply
                        </a-button>
                    </div>

                    <div
                        class="grid grid-cols-1 sm:grid-cols-3 gap-4"
                    >
                        <div
                            class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm"
                        >
                            <div class="text-xs font-medium text-gray-500">
                                Total output VAT
                            </div>
                            <div class="mt-1 text-xl font-semibold text-gray-900">
                                {{ formattedTotal(summary.total_vat) }}
                            </div>
                        </div>
                        <div
                            class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm"
                        >
                            <div class="text-xs font-medium text-gray-500">
                                Gross sales (paid)
                            </div>
                            <div class="mt-1 text-xl font-semibold text-gray-900">
                                {{ formattedTotal(summary.gross_sales) }}
                            </div>
                        </div>
                        <div
                            class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm"
                        >
                            <div class="text-xs font-medium text-gray-500">
                                Number of sales
                            </div>
                            <div class="mt-1 text-xl font-semibold text-gray-900">
                                {{ summary.sales_count }}
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </ContentLayout>
    </AuthenticatedLayout>
</template>
