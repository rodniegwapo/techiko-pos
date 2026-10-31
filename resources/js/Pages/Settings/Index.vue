<script setup>
import { computed, defineComponent, h, ref } from "vue";
import { Head, useForm, usePage } from "@inertiajs/vue3";
import { Checkbox, Modal } from "ant-design-vue";
import dayjs from "dayjs";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { BUSINESS_TYPES, isBusinessTypeAvailable } from "@/Composables/useBusinessType";

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
            business_type: "retail",
        }),
    },
    /** The plan includes dine-in: food businesses are pointed to it. */
    hasDineIn: {
        type: Boolean,
        default: false,
    },
    /** Owners and admins change the business type; others see it read-only. */
    canChangeBusinessType: {
        type: Boolean,
        default: false,
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
    business_type: BUSINESS_TYPES[props.salesSettings?.business_type] ? props.salesSettings.business_type : "retail",
    show_all_menu: props.salesSettings?.show_all_menu === true,
});
// Pages this type tidies out of the sidebar, named for the switch below.
const HIDDEN_PAGE_NAMES = {
    "dining-tables": "Tables",
    "inventory-movements": "Movements",
    "inventory-adjustments": "Stock adjustments",
    "inventory-valuation": "Valuation report",
    purchasing: "Purchasing",
};
// Tables are coming soon and out of every menu (config features.dine_in), so they aren't named.
const hiddenMenuKeys = (type) =>
    (BUSINESS_TYPES[type]?.hiddenMenuKeys ?? []).filter((k) => k !== "dining-tables" || page.props.features?.dine_in);
const hiddenPages = computed(() => hiddenMenuKeys(form.business_type).map((k) => HIDDEN_PAGE_NAMES[k] ?? k));

// Pages the type leaves out of the menu although the business has records there: named on the card
// and in the confirmation, with "Show all menu items" to keep them (useNavMenu.js hides them anyway).
const IN_USE = {
    "dining-tables": { use: "dining_tables", why: "a store has dine-in on or has tables set up" },
    "inventory-movements": { use: "stock", why: "some of your products have Track stock on" },
    "inventory-adjustments": { use: "stock", why: "some of your products have Track stock on" },
    "inventory-valuation": { use: "stock", why: "some of your products have Track stock on" },
    purchasing: { use: "stock", why: "some of your products have Track stock on" },
};
const hiddenWithRecords = computed(() => {
    const inUse = page.props.menuInUse ?? {};
    const groups = {};
    for (const key of hiddenMenuKeys(form.business_type)) {
        const rule = IN_USE[key];
        if (rule && inUse[rule.use]) (groups[rule.why] ??= []).push(HIDDEN_PAGE_NAMES[key] ?? key);
    }
    return Object.entries(groups).map(([why, pages]) => ({ why, pages }));
});

// The other cards save their own settings; the business type and the menu switch only go with the
// Business type card's Save, so saving VAT never picks (or changes) the type.
function submit() {
    form.transform(({ business_type, show_all_menu, ...rest }) => rest).patch(getRoute("settings.update"), {
        preserveScroll: true,
    });
}

// Changing what the business sells changes the menu for everyone and how new products start, so it
// is confirmed first. Saving it as it was asks nothing.
// Compared with what's saved now: the page stays open after a save and its props refresh, so a
// second change in a row is still confirmed.
const typeChanging = computed(
    () =>
        form.business_type !== (props.salesSettings?.business_type ?? "retail") ||
        form.show_all_menu !== (props.salesSettings?.show_all_menu === true) ||
        page.props.businessTypeChosen === false,
);
function presetSummary(type) {
    const p = BUSINESS_TYPES[type];
    if (!p) return "";
    const parts = [
        p.trackStock ? "stock tracked" : "stock not tracked",
        p.soldType ? `sold by the ${p.soldType.toLowerCase()}` : "no sold type picked",
        p.showModifiers ? "modifiers shown" : "modifiers one click away",
    ];
    return parts.join(", ");
}
function saveBusinessType() {
    const save = () =>
        form.transform((data) => data).patch(getRoute("settings.update"), { preserveScroll: true });
    if (!typeChanging.value) return save();

    const label = BUSINESS_TYPES[form.business_type]?.label ?? form.business_type;
    const leaving = form.show_all_menu ? [] : hiddenPages.value;
    const withRecords = form.show_all_menu ? [] : hiddenWithRecords.value.flatMap((k) => k.pages);
    // "Keep these pages in my menu": off, so the type decides, unless the owner ticks it.
    const keep = ref(false);
    const Body = defineComponent({
        setup: () => () =>
            h("div", { class: "space-y-2 text-sm" }, [
                h("p", { class: "mb-0" }, leaving.length
                    ? `The menu changes for everyone in ${domainName}: ${leaving.join(", ")} leave it (page search still opens them).`
                    : `The menu stays the same for everyone in ${domainName}.`),
                withRecords.length
                    ? h("div", { class: "rounded bg-amber-50 p-2 text-amber-900", "data-testid": "confirm-records" }, [
                        h("p", { class: "mb-1" }, `You have records in ${withRecords.join(", ")}. They stay, and you can keep these pages in the menu with Show all menu items.`),
                        h(Checkbox, {
                            checked: keep.value,
                            "onUpdate:checked": (v) => (keep.value = v),
                            "data-testid": "confirm-keep-pages",
                        }, () => "Keep these pages in my menu"),
                    ])
                    : null,
                h("p", { class: "mb-0" }, `New products start with ${presetSummary(form.business_type)}.`),
                h("p", { class: "mb-0 text-gray-500" }, "Products and sales you already have don't change."),
            ]),
    });
    const typeIsNew = form.business_type !== (props.salesSettings?.business_type ?? "retail") || page.props.businessTypeChosen === false;
    Modal.confirm({
        title: typeIsNew ? `Change the business type to ${label}?` : "Change the menu for everyone?",
        okText: "Change",
        cancelText: "Cancel",
        content: h(Body),
        onOk: () => {
            if (keep.value) form.show_all_menu = true;
            return save();
        },
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
        <ContentLayout title="Business type" class="mb-6">
            <template #table>
                <div class="px-6 pt-2 pb-6">
                    <a-form layout="vertical" class="max-w-2xl" @submit.prevent="saveBusinessType">
                        <p class="mb-3 text-sm text-gray-600">
                            What you sell. New products start with the fields that fit, and the rest stay one click away.
                            Changing it never changes products you already have.
                        </p>
                        <a-radio-group
                            v-model:value="form.business_type"
                            :disabled="!canChangeBusinessType"
                            class="grid w-full grid-cols-1 gap-2 sm:grid-cols-2"
                            data-testid="business-type"
                        >
                            <!-- Types not open yet show "Coming soon" and can't be picked (Domain::availableBusinessTypes). -->
                            <label
                                v-for="(t, key) in BUSINESS_TYPES"
                                :key="key"
                                class="flex items-start gap-2 rounded-lg border p-3"
                                :class="[
                                    form.business_type === key ? 'border-green-500 bg-green-50' : 'border-gray-200',
                                    isBusinessTypeAvailable(key) ? 'cursor-pointer' : 'cursor-not-allowed opacity-60',
                                ]"
                            >
                                <a-radio :value="key" :disabled="!isBusinessTypeAvailable(key)" :data-testid="`business-type-${key}`" />
                                <span>
                                    <span class="flex items-center gap-2 text-sm font-medium text-gray-900">
                                        {{ t.label }}
                                        <a-tag v-if="!isBusinessTypeAvailable(key)" class="m-0" :data-testid="`business-type-coming-soon-${key}`">Coming soon</a-tag>
                                    </span>
                                    <span class="block text-xs text-gray-500">{{ t.description }}</span>
                                </span>
                            </label>
                        </a-radio-group>
                        <p v-if="form.business_type === 'food' && hasDineIn" class="mb-0 mt-3 text-xs text-gray-600">
                            Serving at tables? Turn on dine-in for a store under <b>Tables</b>.
                        </p>
                        <div class="mt-4 flex items-start gap-3">
                            <a-switch v-model:checked="form.show_all_menu" :disabled="!canChangeBusinessType" data-testid="show-all-menu" />
                            <div class="text-sm">
                                <div class="font-medium text-gray-900">Show all menu items</div>
                                <div v-if="hiddenPages.length" class="text-xs text-gray-500">
                                    Off: the menu leaves out {{ hiddenPages.join(", ") }}, which this kind of business rarely uses.
                                    They still open from page search (Ctrl+K).
                                </div>
                                <div v-else class="text-xs text-gray-500">This kind of business sees every page already.</div>
                            </div>
                        </div>
                        <a-alert
                            v-if="!form.show_all_menu && hiddenWithRecords.length"
                            type="warning"
                            show-icon
                            class="mt-3"
                            data-testid="menu-hidden-with-records"
                        >
                            <template #message>
                                <div class="text-sm">You have records in pages this kind of business leaves out of the menu:</div>
                                <ul class="mb-0 mt-1 list-disc pl-4 text-xs">
                                    <li v-for="k in hiddenWithRecords" :key="k.why">
                                        <b>{{ k.pages.join(", ") }}</b> ({{ k.why }})
                                    </li>
                                </ul>
                                <div class="mt-1 text-xs text-gray-600">
                                    Your records stay. To keep these pages in the menu, turn on <b>Show all menu items</b><template v-if="canChangeBusinessType">
                                        (<a role="button" class="text-blue-600" data-testid="menu-show-them" @click="form.show_all_menu = true">show them</a>, then Save)</template>.
                                    They still open from page search (Ctrl+K).
                                </div>
                            </template>
                        </a-alert>
                        <p v-if="salesSettings.business_type_change" class="mb-0 mt-3 text-xs text-gray-500" data-testid="business-type-changed">
                            <template v-if="salesSettings.business_type_change.from && salesSettings.business_type_change.from !== salesSettings.business_type_change.to">
                                Changed from {{ BUSINESS_TYPES[salesSettings.business_type_change.from]?.label }} to
                                {{ BUSINESS_TYPES[salesSettings.business_type_change.to]?.label }}
                            </template>
                            <template v-else>Last changed</template>
                            by {{ salesSettings.business_type_change.by }} on
                            {{ dayjs(salesSettings.business_type_change.at).format("MMM D, YYYY") }}.
                        </p>
                        <p v-if="!canChangeBusinessType" class="mb-0 mt-3 text-xs text-gray-500" data-testid="business-type-read-only">
                            Only an owner or admin can change this.
                        </p>
                        <a-form-item v-else class="mb-0 mt-4" :help="form.errors.business_type">
                            <a-button type="primary" html-type="submit" :loading="form.processing" data-testid="save-business-type">Save</a-button>
                        </a-form-item>
                    </a-form>
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
