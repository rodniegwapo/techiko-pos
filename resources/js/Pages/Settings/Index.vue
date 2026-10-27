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
            allow_overselling: false,
            hide_out_of_stock: false,
            allow_new_shift_while_review: true,
        }),
    },
    /** { name, plan, subscription_ends_at, locations, users, created_at } */
    account: {
        type: Object,
        default: null,
    },
    currency: {
        type: Object,
        default: () => ({ code: "PHP", options: ["PHP"] }),
    },
});

const CURRENCY_NAMES = {
    PHP: "Philippine peso (₱)",
    USD: "US dollar ($)",
    EUR: "Euro (€)",
    GBP: "British pound (£)",
    JPY: "Japanese yen (¥)",
};
const currencyOptions = (props.currency?.options ?? ["PHP"]).map((code) => ({
    value: code,
    label: CURRENCY_NAMES[code] ?? code,
}));

const form = useForm({
    apply_vat_automatically: !!props.salesSettings?.apply_vat_automatically,
    vat_rate_percent: Number(props.salesSettings?.vat_rate_percent) || 12,
    vat_pricing_mode:
        props.salesSettings?.vat_pricing_mode === "inclusive"
            ? "inclusive"
            : "exclusive",
    allow_overselling: props.salesSettings?.allow_overselling === true,
    hide_out_of_stock: props.salesSettings?.hide_out_of_stock === true,
    allow_new_shift_while_review: props.salesSettings?.allow_new_shift_while_review !== false,
    currency_code: props.currency?.code ?? "PHP",
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
        <ContentLayout v-if="account" title="Account overview" class="mb-6">
            <template #table>
                <div class="px-6 pt-2 pb-6" data-testid="account-overview">
                    <dl class="grid max-w-2xl grid-cols-1 gap-x-8 gap-y-3 sm:grid-cols-2">
                        <div>
                            <dt class="text-xs uppercase text-gray-500">Organization</dt>
                            <dd class="mb-0 font-medium text-gray-900">{{ account.name }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase text-gray-500">Plan</dt>
                            <dd class="mb-0 font-medium text-gray-900">
                                {{ account.plan || "—" }}
                                <span v-if="account.subscription_ends_at" class="text-sm font-normal text-gray-500">
                                    · until {{ account.subscription_ends_at }}
                                </span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase text-gray-500">Active locations</dt>
                            <dd class="mb-0 font-medium text-gray-900">{{ account.locations }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase text-gray-500">Users</dt>
                            <dd class="mb-0 font-medium text-gray-900">{{ account.users }}</dd>
                        </div>
                        <div v-if="account.created_at">
                            <dt class="text-xs uppercase text-gray-500">Customer since</dt>
                            <dd class="mb-0 font-medium text-gray-900">{{ account.created_at }}</dd>
                        </div>
                    </dl>
                </div>
            </template>
        </ContentLayout>
        <ContentLayout title="Currency" class="mb-6">
            <template #table>
                <div class="px-6 pt-2 pb-6">
                    <a-form layout="vertical" class="max-w-2xl" @submit.prevent="submit">
                        <a-form-item label="Show money in" :help="form.errors.currency_code">
                            <a-select
                                v-model:value="form.currency_code"
                                class="w-full max-w-xs"
                                data-testid="currency-select"
                                :options="currencyOptions"
                            />
                            <p class="mb-0 mt-1 text-xs text-gray-500">
                                Totals across the app use this currency. Prices aren't converted.
                            </p>
                        </a-form-item>
                        <a-form-item class="mb-0">
                            <a-button type="primary" html-type="submit" :loading="form.processing">Save</a-button>
                        </a-form-item>
                    </a-form>
                </div>
            </template>
        </ContentLayout>
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
                        <a-form-item
                            label="Allow selling when out of stock at the location"
                        >
                            <a-switch
                                v-model:checked="form.allow_overselling"
                            />
                            <span class="ml-2 text-sm text-gray-600">
                                Off by default: checkout blocks selling below
                                available quantity at this location. Turn on to
                                allow overselling and reconcile via automatic
                                adjustments.
                            </span>
                        </a-form-item>
                        <a-form-item
                            label="Hide out-of-stock products at the POS"
                        >
                            <a-switch
                                v-model:checked="form.hide_out_of_stock"
                                aria-label="Hide out-of-stock products at the POS"
                            />
                            <span class="ml-2 text-sm text-gray-600">
                                When on, products with nothing left at the
                                store are left out of the sales screen.
                                Scanning a barcode still finds them.
                            </span>
                        </a-form-item>
                        <a-form-item
                            label="New shift while the last one is in review"
                        >
                            <a-switch
                                v-model:checked="form.allow_new_shift_while_review"
                                aria-label="New shift while the last one is in review"
                                data-testid="setting-new-shift-while-review"
                            />
                            <span class="ml-2 text-sm text-gray-600">
                                On by default: a cashier whose count came out
                                short or over can start a new shift while a
                                manager reviews it. Turn off to make them wait.
                            </span>
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
