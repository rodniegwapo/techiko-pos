<script setup>
import { Head, useForm, usePage } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";

const page = usePage();
const { getRoute } = useDomainRoutes();

const props = defineProps({
    salesSettings: {
        type: Object,
        default: () => ({
            apply_vat_automatically: false,
            vat_rate_percent: 12,
            vat_pricing_mode: "exclusive",
        }),
    },
});

const form = useForm({
    apply_vat_automatically: !!props.salesSettings?.apply_vat_automatically,
    vat_rate_percent: Number(props.salesSettings?.vat_rate_percent) || 12,
    vat_pricing_mode:
        props.salesSettings?.vat_pricing_mode === "inclusive"
            ? "inclusive"
            : "exclusive",
});

function submit() {
    form.patch(getRoute("settings.update"), {
        preserveScroll: true,
    });
}

const domainName =
    page.props.currentDomain?.name ?? page.props.domain?.name ?? "Organization";
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Settings" />
        <ContentHeader class="mb-6" :title="`Settings — ${domainName}`" />
        <ContentLayout title="Sales">
            <template #table>
                <!-- px-6 offsets ContentLayout’s -mx-6 on #table so form aligns with the card title row -->
                <div class="px-6 pt-2 pb-6">
                    <div class="max-w-2xl space-y-6">
                    <p class="mb-0 text-sm leading-relaxed text-gray-600">
                        <template v-if="form.vat_pricing_mode === 'exclusive'">
                            After order discounts, VAT is added on top of the
                            net (VAT-exclusive prices). Grand total = net +
                            VAT when automatic VAT is on.
                        </template>
                        <template v-else>
                            After order discounts, line totals are treated as
                            VAT-inclusive. VAT is extracted as
                            gross × rate ÷ (100 + rate) (e.g. 12/112). Grand
                            total equals the inclusive gross (no add-on).
                        </template>
                    </p>
                    <a-form layout="vertical" @submit.prevent="submit">
                        <a-form-item label="Apply VAT automatically on sales">
                            <a-switch
                                v-model:checked="form.apply_vat_automatically"
                            />
                            <span class="ml-2 text-sm text-gray-600">
                                When on, each pending sale gets
                                <code class="text-xs">tax_amount</code> from
                                your VAT rate; when off, tax is zero.
                            </span>
                        </a-form-item>
                        <a-form-item label="VAT pricing mode">
                            <a-radio-group
                                v-model:value="form.vat_pricing_mode"
                            >
                                <a-radio value="exclusive"
                                    >Exclusive (VAT on top)</a-radio
                                >
                                <a-radio value="inclusive"
                                    >Inclusive (VAT in price)</a-radio
                                >
                            </a-radio-group>
                            <p class="mt-1 text-xs text-gray-500">
                                Exclusive: tax = net × rate%. Inclusive: tax =
                                gross × rate ÷ (100 + rate).
                            </p>
                        </a-form-item>
                        <a-form-item label="VAT rate (%)">
                            <a-input-number
                                v-model:value="form.vat_rate_percent"
                                :min="0"
                                :max="100"
                                :step="0.01"
                                :precision="2"
                                class="w-full max-w-xs"
                            />
                        </a-form-item>
                        <a-form-item>
                            <a-button
                                type="primary"
                                html-type="submit"
                                :loading="form.processing"
                            >
                                Save
                            </a-button>
                        </a-form-item>
                    </a-form>
                    </div>
                </div>
            </template>
        </ContentLayout>
    </AuthenticatedLayout>
</template>
