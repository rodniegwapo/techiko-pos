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
        }),
    },
});

const form = useForm({
    apply_vat_automatically: !!props.salesSettings?.apply_vat_automatically,
    vat_rate_percent: Number(props.salesSettings?.vat_rate_percent) || 12,
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
        <ContentHeader class="mb-8" :title="`Settings — ${domainName}`" />
        <ContentLayout title="Sales">
            <template #table>
                <div class="max-w-xl space-y-6">
                    <p class="text-sm text-gray-600">
                        VAT is calculated on the net amount after order
                        discounts, assuming shelf prices are
                        <strong>VAT-exclusive</strong>. Grand total includes tax
                        when automatic VAT is enabled.
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
            </template>
        </ContentLayout>
    </AuthenticatedLayout>
</template>
