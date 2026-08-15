<script setup>
import {
    ref,
    computed,
    onMounted,
    onBeforeUnmount,
    provide,
    watch,
    h,
} from "vue";
import axios from "axios";
import { v4 as uuidv4 } from "uuid";
import { Modal, Button, notification } from "ant-design-vue";
import {
    getOfflineCart,
    putOfflineCart,
    clearOfflineCart,
    addPendingSale,
    getOfflineCatalogSnapshot,
    putOfflineCatalogSnapshot,
} from "@/offline/db.js";

import ContentHeader from "@/Components/ContentHeader.vue";
import ContentLayoutV2 from "@/Components/ContentLayoutV2.vue";
import ContentLayoutV3 from "@/Components/ContentLayoutV3.vue";
import ContentLayoutV4 from "@/Components/ContentLayoutV4.vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import RefreshButton from "@/Components/buttons/Refresh.vue";
import FilterDropdown from "@/Components/filters/FilterDropdown.vue";
import ActiveFilters from "@/Components/filters/ActiveFilters.vue";
import ProductTable from "./components/ProductTable.vue";
import CustomerOrder from "./components/CustomerOrder.vue";
import TotalAmountSection from "./components/TotalAmountSection.vue";
import SalesMobileCheckoutBar from "./components/SalesMobileCheckoutBar.vue";
import SalesCartDrawer from "./components/SalesCartDrawer.vue";
import { useMediaQuery, useStorage, watchDebounced } from "@vueuse/core";
import { useSalesCartDrawer } from "@/Composables/useSalesCartDrawer";
import { useHelpers } from "@/Composables/useHelpers";
import { useSaleTotals } from "@/Composables/useSaleTotals";

import {
    CloseOutlined,
    PlusSquareOutlined,
    MinusSquareOutlined,
    CloudDownloadOutlined,
    LeftOutlined,
} from "@ant-design/icons-vue";
import { message } from "ant-design-vue";

import { usePage, Head, Link } from "@inertiajs/vue3";
import { useFilters, toLabel } from "@/Composables/useFilters";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { notifyInsufficientStock } from "@/Composables/useCartStockNotification";

const page = usePage();
const { getRoute, getLocationQueryFromPage } = useDomainRoutes();
const isMdUp = useMediaQuery("(min-width: 768px)");
const {
    closeCartDrawer,
    goToPaymentStep,
    goToOrderStep,
    resetCheckoutStep,
    checkoutStep,
} = useSalesCartDrawer();

watch(isMdUp, (matches) => {
    if (matches) {
        closeCartDrawer();
    }
});

const isOnline = ref(
    typeof navigator !== "undefined" ? navigator.onLine : true,
);
/** When true while browser is online, keep using Dexie cart (user chose "Keep offline draft"). */
const forceOfflineCartMode = ref(false);
const salesCartIsOnline = computed(
    () => isOnline.value && !forceOfflineCartMode.value,
);
provide("isSalesOnline", salesCartIsOnline);

const domainSlug = computed(
    () => page.props.domain?.name_slug ?? page.props.domain?.nameSlug,
);

/** classic | coffeeshop — persisted per domain (key set at page load) */
const salesLayoutMode = useStorage(
    `sales_layout_mode_${domainSlug.value || "default"}`,
    "classic",
);
const isCoffeeshopLayout = computed({
    get: () => salesLayoutMode.value === "coffeeshop",
    set: (checked) => {
        salesLayoutMode.value = checked ? "coffeeshop" : "classic";
    },
});
const productTableVariant = computed(() =>
    isCoffeeshopLayout.value ? "coffeeshop" : "classic",
);
const salesLayoutComponent = computed(() => {
    if (isCoffeeshopLayout.value) return ContentLayoutV4;
    return isMdUp.value ? ContentLayoutV2 : ContentLayoutV3;
});
const productTableLayout = computed(() =>
    isMdUp.value ? "desktop" : "mobile",
);
/** Desktop coffeeshop cart panel collapse — shared by V4 widths + CustomerOrder */
const orderCollapsed = useStorage("sales_v4_order_collapsed", false);

/** Collapse only on order step; payment always uses the full rail */
const railOrderCollapsed = computed(
    () =>
        isCoffeeshopLayout.value &&
        orderCollapsed.value &&
        checkoutStep.value === "order",
);

function onRailOrderCollapsedUpdate(collapsed) {
    if (checkoutStep.value === "payment") {
        goToOrderStep();
    }
    orderCollapsed.value = collapsed;
}

function onCoffeeshopPaymentSuccess() {
    goToOrderStep();
    orderCollapsed.value = false;
}

function onCoffeeshopChargeClick() {
    orderCollapsed.value = false;
    goToPaymentStep();
}

watch(checkoutStep, (step) => {
    if (step === "payment") {
        orderCollapsed.value = false;
    }
});

watch(isCoffeeshopLayout, () => {
    resetCheckoutStep();
});

const cashierFirstName = computed(() => {
    const name = page.props.auth?.user?.data?.name ?? "";
    const first = String(name).trim().split(/\s+/)[0];
    return first || "there";
});

const timeGreeting = computed(() => {
    const hour = new Date().getHours();
    if (hour < 12) return "Good morning";
    if (hour < 17) return "Good afternoon";
    return "Good evening";
});

const coffeeshopDateLabel = computed(() =>
    new Intl.DateTimeFormat("en-US", {
        weekday: "long",
        month: "long",
        day: "numeric",
        year: "numeric",
    })
        .format(new Date())
        .toUpperCase(),
);

const salesSettings = computed(
    () =>
        page.props.salesSettings ?? {
            apply_vat_automatically: false,
            vat_rate_percent: 12,
            vat_pricing_mode: "exclusive",
        },
);
const loyaltyRedemptionSettings = computed(
    () =>
        page.props.loyaltyRedemptionSettings ?? {
            points_per_currency_unit: 100,
            max_redemption_percent_of_eligible_net: 50,
            min_points_redemption: 1,
        },
);
const activeLocationId = computed(() => page.props.currentLocation?.id);
const cashierUserId = computed(() => page.props.auth?.user?.data?.id);

const offlinePaymentMethod = ref("cash");
const offlinePaymentCardTypeId = ref(null);
const offlineProductLookup = ref([]);
const cachedPaymentCardTypes = ref([]);

async function refreshPaymentCardTypesCache() {
    if (!domainSlug.value || !salesCartIsOnline.value) return;
    try {
        const { data } = await axios.get(getRoute("payment-card-types.list"));
        cachedPaymentCardTypes.value = data?.data ?? [];
    } catch (e) {
        console.warn("Could not refresh card types cache:", e);
    }
}

const orderId = ref(null);
const orders = ref([]);
const currentSale = ref(null);
const orderDiscountAmount = ref(0);
const orderDiscountId = ref("");
const selectedCustomer = ref(null);

const { formattedTotal } = useHelpers();
const { grandTotalDisplay } = useSaleTotals({
    orders,
    orderDiscountAmount,
    salesSettings,
    currentSale,
    salesCartIsOnline,
});

const offlineCatalogSyncedAt = ref(null);
const offlineCustomersCache = ref([]);
const syncingOffline = ref(false);

const MAX_OFFLINE_PRODUCT_LOOKUP = 500;

function discountOptionsFromSnapshot(snap) {
    if (
        !snap ||
        (!snap.regular_discounts?.length &&
            !snap.product_discounts?.length &&
            !snap.mandatory_discounts?.length &&
            !snap.success)
    ) {
        return {
            product_discount_options: [],
            promotional_discount_options: [],
            mandatory_discount_options: [],
        };
    }
    const mapRows = (rows) =>
        (rows || []).map((d) => ({
            id: d.id,
            name: d.name,
            type: d.type,
            value: d.value,
            ...d,
        }));
    return {
        product_discount_options: mapRows(snap.product_discounts),
        promotional_discount_options: mapRows(snap.regular_discounts),
        mandatory_discount_options: mapRows(snap.mandatory_discounts),
    };
}

async function loadOfflineCatalogMetadata() {
    if (!domainSlug.value) return;
    const row = await getOfflineCatalogSnapshot(
        domainSlug.value,
        activeLocationId.value,
    );
    offlineCatalogSyncedAt.value = row?.synced_at ?? null;
}

async function getOfflineScanProductPool() {
    const poolMap = new Map(
        (offlineProductLookup.value || []).map((p) => [p.id, p]),
    );
    const row = await getOfflineCatalogSnapshot(
        domainSlug.value,
        activeLocationId.value,
    );
    for (const p of row?.products || []) {
        if (!poolMap.has(p.id)) {
            poolMap.set(p.id, {
                id: p.id,
                name: p.name ?? "",
                price: parseFloat(p.price) || 0,
                barcode: p.barcode ?? "",
                code: p.code ?? "",
            });
        }
    }
    return [...poolMap.values()];
}

async function syncOfflineDataForSales() {
    if (!salesCartIsOnline.value) {
        message.warning("Connect to the internet to sync offline data.");
        return;
    }
    if (!domainSlug.value || !activeLocationId.value) {
        message.error("Select an active location first.");
        return;
    }
    syncingOffline.value = true;
    let hideLoading = message.loading("Downloading data for offline use…", 0);
    try {
        const allProducts = [];
        let catalogPage = 1;
        const perPage = 200;
        let lastPage = 1;
        let catalogMetaKnown = false;
        const catalogUrl = getRoute("sales.offline-catalog");
        if (!catalogUrl || catalogUrl === "#") {
            throw new Error("Could not resolve offline catalog route.");
        }
        do {
            hideLoading();
            const progressText = catalogMetaKnown
                ? `page ${catalogPage} of ${lastPage}`
                : `page ${catalogPage}…`;
            hideLoading = message.loading(
                `Downloading catalog: ${progressText}`,
                0,
            );
            const res = await axios.get(catalogUrl, {
                params: {
                    page: catalogPage,
                    per_page: perPage,
                    location_id: activeLocationId.value,
                },
            });
            const batch = res.data?.data ?? [];
            const meta = res.data?.meta;
            allProducts.push(...batch);
            lastPage = meta?.last_page ?? 1;
            catalogMetaKnown = true;
            catalogPage += 1;
        } while (catalogPage <= lastPage);

        let discount_snapshot = null;
        try {
            const d = await axios.get(getRoute("sales.discounts.current"));
            if (d.data?.success) {
                discount_snapshot = {
                    regular_discounts: d.data.regular_discounts ?? [],
                    product_discounts: d.data.product_discounts ?? [],
                    mandatory_discounts: d.data.mandatory_discounts ?? [],
                };
            }
        } catch {
            /* optional */
        }

        let customers = [];
        try {
            const c = await axios.get("/api/customers", {
                params: { per_page: 100, page: 1 },
            });
            customers = c.data?.data ?? [];
        } catch {
            /* optional */
        }

        hideLoading();
        hideLoading = message.loading("Saving catalog to this device…", 0);

        await putOfflineCatalogSnapshot({
            domain_slug: domainSlug.value,
            location_id: activeLocationId.value,
            products: allProducts,
            discount_snapshot,
            customers,
        });

        offlineCatalogSyncedAt.value = new Date().toISOString();
        mergeProductLookup(allProducts);
        message.success(
            `Saved for offline: ${allProducts.length} products, ${customers.length} customers (sample), discount rules snapshot.`,
        );
    } catch (e) {
        console.error(e);
        message.error(
            e.response?.data?.message ||
                e.message ||
                "Failed to sync offline data.",
        );
    } finally {
        hideLoading();
        syncingOffline.value = false;
    }
}

const lastOfflineSyncLabel = computed(() => {
    if (!offlineCatalogSyncedAt.value) return null;
    try {
        return new Date(offlineCatalogSyncedAt.value).toLocaleString();
    } catch {
        return offlineCatalogSyncedAt.value;
    }
});

function mergeProductLookup(entries) {
    const byId = new Map(
        (offlineProductLookup.value || []).map((p) => [p.id, p]),
    );
    for (const p of entries) {
        if (!p?.id) continue;
        byId.set(p.id, {
            id: p.id,
            name: p.name ?? "",
            price: parseFloat(p.price) || 0,
            barcode: p.barcode ?? "",
            code: p.code ?? "",
        });
    }
    const arr = [...byId.values()];
    offlineProductLookup.value = arr.slice(-MAX_OFFLINE_PRODUCT_LOOKUP);
}

function ordersToLineItems(ordersList) {
    return (ordersList || []).map((o) => ({
        product_id: o.id,
        quantity: parseInt(o.quantity, 10) || 0,
        unit_price: parseFloat(o.price) || 0,
        name: o.name,
        representation_type: o.representation_type ?? null,
        representation: o.representation ?? null,
    }));
}

function lineItemsToOrders(lines) {
    return (lines || []).map((li) => ({
        id: li.product_id,
        name: li.name || "Unknown Product",
        price: li.unit_price,
        quantity: li.quantity,
        subtotal: li.unit_price * li.quantity,
        discount_id: null,
        discount_type: null,
        discount: null,
        discount_amount: 0,
        discounts: [],
        representation_type: li.representation_type ?? null,
        representation: li.representation ?? null,
    }));
}

async function persistOfflineCartToDexie() {
    if (
        !domainSlug.value ||
        cashierUserId.value == null ||
        salesCartIsOnline.value
    ) {
        return;
    }
    await putOfflineCart({
        domain_slug: domainSlug.value,
        user_id: cashierUserId.value,
        line_items: ordersToLineItems(orders.value),
        payment_method: offlinePaymentMethod.value,
        payment_card_type_id:
            offlinePaymentMethod.value === "card"
                ? offlinePaymentCardTypeId.value ?? null
                : null,
        location_id: activeLocationId.value ?? null,
        customer_id: selectedCustomer.value?.id ?? null,
        customer_snapshot: selectedCustomer.value
            ? {
                  id: selectedCustomer.value.id,
                  name: selectedCustomer.value.name,
              }
            : null,
        notes: null,
        product_lookup: offlineProductLookup.value,
    });
}

async function hydrateFromOfflineCart() {
    if (!domainSlug.value || cashierUserId.value == null) return;
    const row = await getOfflineCart(domainSlug.value, cashierUserId.value);
    if (!row) {
        orderId.value = null;
        orders.value = [];
        orderDiscountAmount.value = 0;
        orderDiscountId.value = "";
        currentSale.value = null;
        offlinePaymentMethod.value = "cash";
        offlinePaymentCardTypeId.value = null;
        selectedCustomer.value = null;
        return;
    }
    orderId.value = null;
    orders.value = lineItemsToOrders(row.line_items);
    orderDiscountAmount.value = 0;
    orderDiscountId.value = "";
    currentSale.value = null;
    offlinePaymentMethod.value = row.payment_method || "cash";
    offlinePaymentCardTypeId.value = row.payment_card_type_id ?? null;
    if (row.product_lookup?.length) {
        offlineProductLookup.value = row.product_lookup;
    }
    if (row.customer_snapshot) {
        selectedCustomer.value = {
            id: row.customer_snapshot.id,
            name: row.customer_snapshot.name,
        };
    } else {
        selectedCustomer.value = null;
    }
}

function onWindowOnline() {
    isOnline.value = true;
    onReconnectPrompt();
}

async function onWindowOffline() {
    isOnline.value = false;
    forceOfflineCartMode.value = false;
    await persistOfflineCartToDexie();
}

async function onReconnectPrompt() {
    if (!domainSlug.value || cashierUserId.value == null) return;
    const row = await getOfflineCart(domainSlug.value, cashierUserId.value);
    const hasDraft = row?.line_items?.length > 0;
    if (!hasDraft) {
        forceOfflineCartMode.value = false;
        await loadCurrentPendingSale();
        return;
    }
    Modal.confirm({
        title: "Back online",
        content:
            "You have an offline cart draft. Discard it and load your server cart?",
        okText: "Discard offline & load server",
        cancelText: "Keep offline draft",
        async onOk() {
            forceOfflineCartMode.value = false;
            await clearOfflineCart(domainSlug.value, cashierUserId.value);
            await loadCurrentPendingSale();
        },
        async onCancel() {
            forceOfflineCartMode.value = true;
            await hydrateFromOfflineCart();
        },
    });
}

const search = ref("");
/** Skip next debounced search-triggered getProducts (scan-driven search updates). */
const skipNextSearchWatchFetch = ref(false);
const category = ref();
const spinning = ref(false);
import { useBarcodeScanner } from "@/Composables/useBarcodeScanner";

// ... (other refs)

const clearSearchWithoutProductFetch = () => {
    skipNextSearchWatchFetch.value = true;
    search.value = "";
};

const processScan = (code) => {
    const trimmed = String(code ?? "").trim();
    skipNextSearchWatchFetch.value = true;
    search.value = trimmed;
    handleScanAndAdd(trimmed);
};

useBarcodeScanner(processScan);

const handleScanAndAdd = async (scannedCode) => {
    const code = String(scannedCode ?? "").trim();
    const hide = message.loading("Processing scan...", 0);
    try {
        if (!code) {
            message.warning("Empty scan");
            return;
        }

        if (!salesCartIsOnline.value) {
            const localOffline = findProductForScan(code);
            if (localOffline) {
                const added = await addToCart(localOffline, {
                    suppressPageLoading: true,
                });
                if (!added) {
                    return;
                }
                message.success(`Added ${localOffline.name} to cart`);
                clearSearchWithoutProductFetch();
                return;
            }

            const pool = await getOfflineScanProductPool();
            const exactMatch = pool.find(
                (p) => p.code === code || p.barcode === code,
            );
            if (exactMatch) {
                const added = await addToCart(
                    {
                        id: exactMatch.id,
                        name: exactMatch.name,
                        price: exactMatch.price,
                        barcode: exactMatch.barcode,
                        code: exactMatch.code,
                    },
                    { suppressPageLoading: true },
                );
                if (!added) {
                    return;
                }
                message.success(`Added ${exactMatch.name} to cart`);
                clearSearchWithoutProductFetch();
            } else {
                message.error(
                    "Product not found in your offline catalog. While online, use “Sync for offline” on Sales to download products, then try again.",
                );
            }
            return;
        }

        const localOnline = findProductForScan(code);
        if (localOnline) {
            const added = await addToCart(localOnline, {
                suppressPageLoading: true,
            });
            if (!added) {
                return;
            }
            message.success(`Added ${localOnline.name} to cart`);
            clearSearchWithoutProductFetch();
            return;
        }

        loading.value = true;
        const locQ = getLocationQueryFromPage();
        const locationId = locQ.location_id ?? activeLocationId.value ?? undefined;
        const items = await axios.get(getRoute("sales.products"), {
            params: {
                page: 1,
                per_page: 100,
                search: code || undefined,
                category: category.value || undefined,
                ...(locationId != null && locationId !== ""
                    ? { location_id: locationId }
                    : {}),
            },
        });
        const results = items.data.data ?? [];

        const meta = items.data.meta;
        if (meta) {
            productsLastPage.value = meta.last_page ?? 1;
            productsTotal.value = meta.total ?? 0;
            productsPage.value = meta.current_page ?? 1;
        }

        products.value = results;
        mergeProductLookup(results);

        if (results.length === 1) {
            const product = results[0];
            const added = await addToCart(product, {
                suppressPageLoading: true,
            });
            if (!added) {
                return;
            }
            message.success(`Added ${product.name} to cart`);
            clearSearchWithoutProductFetch();
        } else if (results.length > 1) {
            const exactMatch = results.find(
                (p) => p.code === code || p.barcode === code,
            );

            if (exactMatch) {
                const added = await addToCart(exactMatch, {
                    suppressPageLoading: true,
                });
                if (!added) {
                    return;
                }
                message.success(`Added ${exactMatch.name} to cart`);
                clearSearchWithoutProductFetch();
            } else {
                message.warning("Multiple products found, please select one.");
            }
        } else {
            message.error(`Product not found: ${code}`);
        }
    } catch (e) {
        console.error("Scan error:", e);
        message.error("Error processing scan");
    } finally {
        hide();
        loading.value = false;
    }
};

const addToCart = async (product, options = {}) => {
    const suppressPageLoading = options.suppressPageLoading === true;
    try {
        if (!suppressPageLoading) {
            loading.value = true;
        }
        mergeProductLookup([product]);

        if (!salesCartIsOnline.value) {
            const idx = orders.value.findIndex((o) => o.id === product.id);
            if (idx === -1) {
                const price = parseFloat(product.price) || 0;
                orders.value.push({
                    id: product.id,
                    name: product.name,
                    price,
                    quantity: 1,
                    subtotal: price,
                    discount_id: null,
                    discount_type: null,
                    discount: null,
                    discount_amount: 0,
                    discounts: [],
                    representation_type: product.representation_type ?? null,
                    representation: product.representation ?? null,
                });
            } else {
                const o = orders.value[idx];
                o.quantity = (parseInt(o.quantity, 10) || 0) + 1;
                o.subtotal = (parseFloat(o.price) || 0) * o.quantity;
            }
            await persistOfflineCartToDexie();
            return true;
        }

        const userId = page.props.auth.user.data.id;
        const route = getRoute("users.sales.cart.add", { user: userId });

        await axios.post(route, {
            product_id: product.id,
            quantity: 1,
        });

        await loadCurrentPendingSale();
        return true;
    } catch (error) {
        notifyInsufficientStock(error);
        console.error("Failed to add item to cart:", error);
        return false;
    } finally {
        if (!suppressPageLoading) {
            loading.value = false;
        }
    }
};

const isLoadingCart = ref(false);
const discountOptions = ref({
    product_discount_options: [],
    promotional_discount_options: [],
    mandatory_discount_options: [],
});

async function applyOfflineCatalogForOfflineMode() {
    if (!domainSlug.value) return;
    const row = await getOfflineCatalogSnapshot(
        domainSlug.value,
        activeLocationId.value,
    );
    if (!row) {
        offlineCustomersCache.value = [];
        return;
    }
    offlineCatalogSyncedAt.value = row.synced_at;
    offlineCustomersCache.value = row.customers || [];
    mergeProductLookup(row.products || []);
    if (row.discount_snapshot) {
        discountOptions.value = discountOptionsFromSnapshot({
            success: true,
            regular_discounts: row.discount_snapshot.regular_discounts,
            product_discounts: row.discount_snapshot.product_discounts,
            mandatory_discounts: row.discount_snapshot.mandatory_discounts,
        });
    }
    await getProducts();
}

// Direct API functions
const loadCurrentPendingSale = async () => {
    if (!salesCartIsOnline.value) {
        await persistOfflineCartToDexie();
        isLoadingCart.value = false;
        return;
    }

    isLoadingCart.value = true;

    try {
        const userId = page.props.auth.user.data.id;
        const route = getRoute("users.sales.current-pending", { user: userId });
        const response = await axios.get(route);

        if (response.data.sale) {
            const { sale, items, discounts, totals, discount_options } =
                response.data;

            // Set the orderId from the found sale
            orderId.value = sale.id;

            // Store the full sale object for the modal
            currentSale.value = sale;

            // Store discount options for modals
            if (discount_options) {
                discountOptions.value = discount_options;
            }

            // Transform database response to match frontend expectations
            orders.value = transformCartItems(items);
            orderDiscountAmount.value =
                parseFloat(totals?.discount_amount) || 0;
            orderDiscountId.value =
                discounts?.map((d) => d.discount_id).join(",") || "";

            // Log product-level discounts for debugging
            const productsWithDiscounts = orders.value.filter(
                (order) => order.discount_id || order.discount_amount > 0,
            );
        } else {
            orderId.value = null;
            orders.value = [];
            orderDiscountAmount.value = 0;
            orderDiscountId.value = "";
            currentSale.value = null;
        }
    } catch (error) {
        orderId.value = null;
        orders.value = [];
        orderDiscountAmount.value = 0;
        orderDiscountId.value = "";
        currentSale.value = null;
    } finally {
        isLoadingCart.value = false;
    }
};

const createDraft = async () => {
    console.log("createDraft called - now handled by user-specific routes");
    // This function is no longer needed as user-specific routes handle sale creation automatically
    return orderId.value;
};

// Utility function to transform cart items
const transformCartItems = (items) => {
    if (!items || !Array.isArray(items)) {
        console.warn("transformCartItems received invalid items:", items);
        return [];
    }

    return items.map((item) => {
        const productName = item.product?.name || "Unknown Product";

        return {
            id: item.product_id,
            name: productName,
            price: item.unit_price,
            quantity: item.quantity,
            subtotal: item.unit_price * item.quantity,
            discount_id: item.discount_id,
            discount_type: item.discount_type,
            discount: item.discount,
            discount_amount: item.discount_amount,
            discounts: item.discounts || [], // Include the discounts relationship
            representation_type: item.product?.representation_type ?? null,
            representation: item.product?.representation ?? null,
        };
    });
};

// Handle customer changes from CustomerOrder component
const handleCustomerChanged = async (customer) => {
    selectedCustomer.value = customer;

    if (!salesCartIsOnline.value) {
        await persistOfflineCartToDexie();
        return;
    }

    if (customer) {
        try {
            await loadCurrentPendingSale();

            if (!orderId.value) {
                await createDraft();
            }

            if (!orderId.value) {
                throw new Error("No order ID available");
            }

            const route = getRoute("sales.sales.assignCustomer", {
                sale: orderId.value,
            });

            await axios.post(route, {
                customer_id: customer?.id || null,
            });
        } catch (error) {
            console.error("Error handling customer selection:", error);
        }
    }
};

function onOfflineCartAdd(product) {
    const idx = orders.value.findIndex((o) => o.id === product.id);
    if (idx === -1) {
        const price = parseFloat(product.price) || 0;
        orders.value.push({
            id: product.id,
            name: product.name,
            price,
            quantity: 1,
            subtotal: price,
            discount_id: null,
            discount_type: null,
            discount: null,
            discount_amount: 0,
            discounts: [],
            representation_type: product.representation_type ?? null,
            representation: product.representation ?? null,
        });
    } else {
        const o = orders.value[idx];
        o.quantity = (parseInt(o.quantity, 10) || 0) + 1;
        o.subtotal = (parseFloat(o.price) || 0) * o.quantity;
    }
    persistOfflineCartToDexie();
}

function onOfflineCartSubtract(product) {
    const idx = orders.value.findIndex((o) => o.id === product.id);
    if (idx === -1) return;
    const o = orders.value[idx];
    const next = Math.max(0, (parseInt(o.quantity, 10) || 0) - 1);
    if (next === 0) {
        orders.value.splice(idx, 1);
    } else {
        o.quantity = next;
        o.subtotal = (parseFloat(o.price) || 0) * next;
    }
    persistOfflineCartToDexie();
}

function onOfflineCartSetQty(product, quantity) {
    const idx = orders.value.findIndex((o) => o.id === product.id);
    if (idx === -1) return;
    const q = parseInt(quantity, 10);
    if (isNaN(q) || q < 0) return;
    if (q === 0) {
        orders.value.splice(idx, 1);
    } else {
        const o = orders.value[idx];
        o.quantity = q;
        o.subtotal = (parseFloat(o.price) || 0) * q;
    }
    persistOfflineCartToDexie();
}

function onOfflineCartRemove(product) {
    const idx = orders.value.findIndex((o) => o.id === product.id);
    if (idx !== -1) {
        orders.value.splice(idx, 1);
        persistOfflineCartToDexie();
    }
}

async function resumeServerCartMode() {
    forceOfflineCartMode.value = false;
    if (!domainSlug.value || cashierUserId.value == null) return;
    await clearOfflineCart(domainSlug.value, cashierUserId.value);
    await loadCurrentPendingSale();
    message.success("Switched to server cart.");
}

function syncOfflinePaymentMethod(v) {
    offlinePaymentMethod.value = v;
    if (v !== "card") {
        offlinePaymentCardTypeId.value = null;
    }
}

function syncOfflinePaymentCardTypeId(v) {
    offlinePaymentCardTypeId.value = v;
}

async function completeOfflineSale() {
    if (salesCartIsOnline.value) {
        message.error("Only available while offline or in offline cart mode.");
        return;
    }
    if (!domainSlug.value || !cashierUserId.value) {
        message.error("Missing session context.");
        return;
    }
    if (!activeLocationId.value) {
        message.error(
            "No active location. Select a store location, then try again.",
        );
        return;
    }
    const lines = ordersToLineItems(orders.value).filter((l) => l.quantity > 0);
    if (!lines.length) {
        message.error("Add at least one line item.");
        return;
    }
    let payment = offlinePaymentMethod.value || "cash";
    if (payment === "credit") {
        message.error(
            "Credit payments cannot be saved offline. Choose cash or card.",
        );
        return;
    }
    if (payment !== "cash" && payment !== "card") {
        payment = "cash";
    }
    if (payment === "card" && !offlinePaymentCardTypeId.value) {
        message.error(
            "Select a card payment type (Pay in Card) before saving offline.",
        );
        return;
    }
    const clientMutationId = uuidv4();
    const payload = {
        items: lines.map((l) => ({
            product_id: Number(l.product_id),
            quantity: Number(l.quantity),
            unit_price: Number(l.unit_price),
        })),
        payment_method: payment,
        payment_card_type_id:
            payment === "card"
                ? Number(offlinePaymentCardTypeId.value)
                : null,
        location_id: activeLocationId.value,
        cashier_user_id: cashierUserId.value,
        notes: null,
        customer_id: selectedCustomer.value?.id
            ? Number(selectedCustomer.value.id)
            : null,
        recorded_at: new Date().toISOString(),
    };

    try {
        await addPendingSale({
            client_mutation_id: clientMutationId,
            status: "pending_review",
            domain_slug: domainSlug.value,
            location_id: activeLocationId.value,
            payload,
        });
        await clearOfflineCart(domainSlug.value, cashierUserId.value);
        orders.value = [];
        orderId.value = null;
        selectedCustomer.value = null;
        currentSale.value = null;
        orderDiscountAmount.value = 0;
        orderDiscountId.value = "";
        offlinePaymentMethod.value = "cash";
        offlinePaymentCardTypeId.value = null;
        offlineProductLookup.value = [];
        forceOfflineCartMode.value = false;
        message.success("Saved locally. Review under Offline transactions.");
        closeCartDrawer();
    } catch (e) {
        console.error(e);
        message.error("Could not save offline sale.");
    }
}

const NOTIFICATION_KEY_OFFLINE = "sales-offline-warning";
const NOTIFICATION_KEY_OFFLINE_DRAFT = "sales-offline-draft-mode";

function closeOfflineWarningNotification() {
    notification.close(NOTIFICATION_KEY_OFFLINE);
}

function showOfflineWarningNotification() {
    const offlineTxUrl = getRoute("sales.offline-transactions");
    notification.warning({
        key: NOTIFICATION_KEY_OFFLINE,
        message: "You are offline",
        description: h("div", { class: "text-sm space-y-2" }, [
            h(
                "p",
                { class: "mb-0" },
                "The cart is stored on this device. Product search and discounts need a connection. Use a barcode if the product was loaded or added while online.",
            ),
            h(
                Link,
                {
                    href: offlineTxUrl,
                    class: "font-medium underline",
                },
                () => "Offline transactions",
            ),
        ]),
        duration: 0,
        placement: "topRight",
    });
}

function closeDraftModeNotification() {
    notification.close(NOTIFICATION_KEY_OFFLINE_DRAFT);
}

function showDraftModeNotification() {
    const offlineTxUrl = getRoute("sales.offline-transactions");
    notification.info({
        key: NOTIFICATION_KEY_OFFLINE_DRAFT,
        message: "Offline cart draft",
        description: h("div", { class: "text-sm space-y-2" }, [
            h(
                "p",
                { class: "mb-0" },
                "You are online but still using the offline cart draft on this device.",
            ),
            h(
                Link,
                {
                    href: offlineTxUrl,
                    class: "font-medium underline",
                },
                () => "Offline transactions",
            ),
        ]),
        duration: 0,
        placement: "topRight",
        btn: h(
            Button,
            {
                type: "primary",
                size: "small",
                onClick: () => {
                    void resumeServerCartMode();
                },
            },
            () => "Discard draft & use server cart",
        ),
    });
}

// Filters setup

// FilterDropdown configuration
const filtersConfig = [
    {
        key: "category",
        label: "Category",
        type: "select",
        options: (page.props?.categories ?? []).map((item) => ({
            label: item.name,
            value: item.name,
        })),
    },
];

const products = ref([]);
const loading = ref(false);
const loadingMore = ref(false);
const productsPage = ref(1);
const productsLastPage = ref(1);
const productsTotal = ref(0);
const PRODUCTS_PER_PAGE = 30;

const hasMoreProducts = computed(
    () =>
        salesCartIsOnline.value && productsPage.value < productsLastPage.value,
);

function matchesScanCode(p, code) {
    const q = String(code ?? "").trim();
    if (!q || !p) {
        return false;
    }
    const bc = String(p.barcode ?? "").trim();
    const cd = String(p.code ?? "").trim();

    return bc === q || cd === q;
}

/** Exact barcode/SKU match on current grid or merged lookup cache (no network). */
function findProductForScan(code) {
    const q = String(code ?? "").trim();
    if (!q) {
        return null;
    }
    for (const p of products.value || []) {
        if (matchesScanCode(p, q)) {
            return p;
        }
    }
    for (const p of offlineProductLookup.value || []) {
        if (matchesScanCode(p, q)) {
            return p;
        }
    }

    return null;
}

onMounted(async () => {
    if (typeof window !== "undefined") {
        window.addEventListener("online", onWindowOnline);
        window.addEventListener("offline", onWindowOffline);
    }
    try {
        if (salesCartIsOnline.value) {
            await getProducts();
            await loadCurrentPendingSale();
            await loadOfflineCatalogMetadata();
            await refreshPaymentCardTypesCache();
        } else {
            isLoadingCart.value = true;
            await hydrateFromOfflineCart();
            await applyOfflineCatalogForOfflineMode();
            isLoadingCart.value = false;
        }
    } catch (error) {
        console.error("Failed to initialize sales screen:", error);
    }
});

onBeforeUnmount(() => {
    closeOfflineWarningNotification();
    closeDraftModeNotification();
    if (typeof window !== "undefined") {
        window.removeEventListener("online", onWindowOnline);
        window.removeEventListener("offline", onWindowOffline);
    }
});

function displayCategoryNameForSales(product) {
    return product?.category?.name ?? "Uncategorized";
}

const getProducts = async ({ append = false } = {}) => {
    if (!salesCartIsOnline.value) {
        loading.value = true;
        try {
            const row = await getOfflineCatalogSnapshot(
                domainSlug.value,
                activeLocationId.value,
            );
            const list = row?.products || [];
            let filtered = [...list];
            if (category.value) {
                filtered = filtered.filter(
                    (p) => displayCategoryNameForSales(p) === category.value,
                );
            }
            const q = String(search.value || "")
                .trim()
                .toLowerCase();
            if (q) {
                filtered = filtered.filter(
                    (p) =>
                        (p.name && p.name.toLowerCase().includes(q)) ||
                        String(p.barcode || "")
                            .toLowerCase()
                            .includes(q) ||
                        String(p.code || "")
                            .toLowerCase()
                            .includes(q),
                );
            }
            products.value = filtered.slice(0, 500);
        } finally {
            loading.value = false;
        }
        return;
    }

    const nextPage = append ? productsPage.value + 1 : 1;
    if (append) {
        loadingMore.value = true;
    } else {
        loading.value = true;
    }
    try {
        const locQ = getLocationQueryFromPage();
        const locationId = locQ.location_id ?? activeLocationId.value ?? undefined;
        const res = await axios.get(getRoute("sales.products"), {
            params: {
                page: nextPage,
                per_page: PRODUCTS_PER_PAGE,
                search: search.value || undefined,
                category: category.value || undefined,
                ...(locationId != null && locationId !== ""
                    ? { location_id: locationId }
                    : {}),
            },
        });
        const rows = res.data.data ?? [];
        const meta = res.data.meta;
        if (meta) {
            productsLastPage.value = meta.last_page ?? 1;
            productsTotal.value = meta.total ?? 0;
            productsPage.value = meta.current_page ?? nextPage;
        }
        if (append) {
            products.value = [...products.value, ...rows];
        } else {
            products.value = rows;
        }
        mergeProductLookup(rows);
    } finally {
        loading.value = false;
        loadingMore.value = false;
    }
};

const loadMoreProducts = () => {
    if (!hasMoreProducts.value || loadingMore.value || loading.value) return;
    return getProducts({ append: true });
};
const { filters, activeFilters, handleClearSelectedFilter } = useFilters({
    getItems: getProducts,
    configs: [
        {
            key: "category",
            ref: category,
            getLabel: toLabel(
                computed(() =>
                    (page.props?.categories ?? []).map((item) => ({
                        label: item.name,
                        value: item.name,
                    })),
                ),
            ),
        },
    ],
});

watchDebounced(
    search,
    () => {
        if (skipNextSearchWatchFetch.value) {
            skipNextSearchWatchFetch.value = false;

            return;
        }
        getProducts();
    },
    { debounce: 300 },
);

watch(
    [orders, selectedCustomer, offlinePaymentMethod, offlinePaymentCardTypeId],
    () => {
        if (!salesCartIsOnline.value) persistOfflineCartToDexie();
    },
    { deep: true },
);

watch(salesCartIsOnline, (online) => {
    if (online) {
        refreshPaymentCardTypesCache();
    }
});

watch(
    isOnline,
    (online) => {
        if (online) {
            closeOfflineWarningNotification();
        } else {
            closeDraftModeNotification();
            showOfflineWarningNotification();
        }
    },
    { immediate: true },
);

watch(
    [isOnline, forceOfflineCartMode],
    () => {
        if (isOnline.value && forceOfflineCartMode.value) {
            showDraftModeNotification();
        } else {
            closeDraftModeNotification();
        }
    },
    { immediate: true },
);
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Sales" />
        <div
            :class="{
                'sales-coffeeshop': isCoffeeshopLayout,
                'sales-coffeeshop--viewport': isCoffeeshopLayout,
            }"
        >        <ContentHeader v-if="!isCoffeeshopLayout" title="Sales">
            <template #actions>
                <div class="flex items-center gap-2">
                    <span
                        class="text-xs font-medium"
                        :class="
                            isCoffeeshopLayout
                                ? 'text-gray-400'
                                : 'text-gray-800'
                        "
                    >
                        Classic
                    </span>
                    <a-switch
                        v-model:checked="isCoffeeshopLayout"
                        size="small"
                        aria-label="Switch between classic and coffeeshop layout"
                    />
                    <span
                        class="text-xs font-medium"
                        :class="
                            isCoffeeshopLayout
                                ? 'text-green-700'
                                : 'text-gray-400'
                        "
                    >
                        Coffeeshop
                    </span>
                </div>
            </template>
            <template v-if="lastOfflineSyncLabel" #meta>
                Last offline sync: {{ lastOfflineSyncLabel }}
            </template>
        </ContentHeader>

        <component
            :is="salesLayoutComponent"
            title="Create Transaction"
            :order-collapsed="railOrderCollapsed"
        >
            <template v-if="isCoffeeshopLayout" #hero>
                <div
                    class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between"
                >
                    <div class="min-w-0">
                        <p
                            class="mb-1 text-[10px] font-semibold tracking-[0.14em] text-[var(--cs-muted)]"
                        >
                            {{ coffeeshopDateLabel }}
                        </p>
                        <h1
                            class="cs-display m-0 text-3xl font-semibold leading-tight text-[var(--cs-ink)] sm:text-4xl"
                        >
                            {{ timeGreeting }}, {{ cashierFirstName }}.
                        </h1>
                        <p class="mt-1 mb-0 text-sm text-[var(--cs-muted)]">
                            What can we make for you?
                        </p>
                    </div>
                    <div
                        class="flex w-full flex-col items-stretch gap-2 sm:max-w-md lg:items-end"
                    >
                        <div class="flex items-center justify-end gap-2">
                            <span
                                class="text-xs font-medium text-gray-400"
                            >
                                Classic
                            </span>
                            <a-switch
                                v-model:checked="isCoffeeshopLayout"
                                size="small"
                                aria-label="Switch between classic and coffeeshop layout"
                            />
                            <span
                                class="text-xs font-medium text-green-700"
                            >
                                Coffeeshop
                            </span>
                        </div>
                        <p
                            v-if="lastOfflineSyncLabel"
                            class="mb-0 text-right text-[10px] text-[var(--cs-muted)]"
                        >
                            Last offline sync: {{ lastOfflineSyncLabel }}
                        </p>
                    </div>
                </div>
            </template>
            <template #filters>
                <a-input
                    v-if="isCoffeeshopLayout"
                    v-model:value="search"
                    placeholder="Search menu..."
                    allow-clear
                    class="min-w-[140px] max-w-[320px]"
                />
                <a-input-search
                    v-else
                    v-model:value="search"
                    placeholder="Search Product"
                    class="min-w-[100px] max-w-[300px]"
                />
                <RefreshButton
                    v-if="!isCoffeeshopLayout"
                    :loading="loading"
                    @click="getProducts"
                />
                <a-button
                    v-if="!isCoffeeshopLayout"
                    type="default"
                    :loading="syncingOffline"
                    :disabled="!salesCartIsOnline || !activeLocationId"
                    @click="syncOfflineDataForSales"
                >
                    <template #icon>
                        <CloudDownloadOutlined />
                    </template>
                    Sync for offline
                </a-button>

                <FilterDropdown
                    v-if="!isCoffeeshopLayout"
                    v-model="filters"
                    :filters="filtersConfig"
                />
            </template>
            <template #activeFilters>
                <ActiveFilters
                    :filters="activeFilters"
                    @remove-filter="handleClearSelectedFilter"
                    @clear-all="
                        () =>
                            Object.keys(filters).forEach(
                                (k) => (filters[k] = null),
                            )
                    "
                    :always-show="true"
                />
            </template>

            <template #table>
                <ProductTable
                    :products="products"
                    :loading="loading"
                    :loading-more="loadingMore"
                    :has-more-products="hasMoreProducts"
                    :products-total="productsTotal"
                    :sales-cart-is-online="salesCartIsOnline"
                    :orders="orders"
                    :orderId="orderId"
                    :layout="productTableLayout"
                    :variant="productTableVariant"
                    @cart-updated="loadCurrentPendingSale"
                    @offline-add-product="addToCart"
                    @load-more="loadMoreProducts"
                />
            </template>
            <template #right-side-content>
                <!-- Coffeeshop desktop: 2-step order → payment rail -->
                <div
                    v-if="isCoffeeshopLayout"
                    class="flex h-full min-h-0 flex-col overflow-hidden"
                >
                    <div
                        v-if="checkoutStep === 'payment'"
                        class="mb-3 flex shrink-0 items-center gap-1"
                    >
                        <a-button
                            type="text"
                            class="flex h-8 min-w-8 items-center justify-center !p-0 text-[var(--cs-ink)]"
                            aria-label="Back to order"
                            @click="goToOrderStep"
                        >
                            <LeftOutlined class="text-base" />
                        </a-button>
                        <span
                            class="cs-display text-xl font-semibold text-[var(--cs-ink)]"
                        >
                            Checkout
                        </span>
                    </div>

                    <div class="relative min-h-0 flex-1 overflow-hidden">
                        <div
                            class="absolute inset-0 flex min-h-0 flex-col overflow-hidden transition-transform duration-300 ease-out will-change-transform"
                            :class="[
                                checkoutStep === 'payment'
                                    ? '-translate-x-full'
                                    : 'translate-x-0',
                                checkoutStep !== 'order'
                                    ? 'pointer-events-none'
                                    : '',
                            ]"
                        >
                            <div
                                class="flex min-h-0 flex-1 flex-col overflow-hidden"
                            >
                                <customer-order
                                    layout="coffeeshop"
                                    :order-collapsed="orderCollapsed"
                                    @update:order-collapsed="
                                        onRailOrderCollapsedUpdate
                                    "
                                    @charge-request="onCoffeeshopChargeClick"
                                    @customer-changed="handleCustomerChanged"
                                    @discount-applied="loadCurrentPendingSale"
                                    @cart-updated="loadCurrentPendingSale"
                                    @offline-cart-add="onOfflineCartAdd"
                                    @offline-cart-subtract="
                                        onOfflineCartSubtract
                                    "
                                    @offline-cart-set-qty="
                                        (e) =>
                                            onOfflineCartSetQty(
                                                e.product,
                                                e.quantity,
                                            )
                                    "
                                    @offline-cart-remove="onOfflineCartRemove"
                                    :loading="isLoadingCart"
                                    :orders="orders"
                                    :orderId="orderId"
                                    :orderDiscountAmount="orderDiscountAmount"
                                    :orderDiscountId="orderDiscountId"
                                    :discountOptions="discountOptions"
                                    :offline-cached-customers="
                                        offlineCustomersCache
                                    "
                                    :current-sale="currentSale"
                                    :sales-settings="salesSettings"
                                />
                            </div>
                        </div>

                        <div
                            class="absolute inset-0 flex min-h-0 flex-col overflow-hidden transition-transform duration-300 ease-out will-change-transform"
                            :class="[
                                checkoutStep === 'payment'
                                    ? 'translate-x-0'
                                    : 'translate-x-full',
                                checkoutStep !== 'payment'
                                    ? 'pointer-events-none'
                                    : '',
                            ]"
                        >
                            <div
                                class="min-h-0 flex-1 overflow-y-auto overflow-x-hidden"
                            >
                                <total-amount-section
                                    layout="compact"
                                    coffeeshop-skin
                                    :selected-customer="selectedCustomer"
                                    :orders="orders"
                                    :currentSale="currentSale"
                                    :orderDiscountAmount="orderDiscountAmount"
                                    :orderDiscountId="orderDiscountId"
                                    :orderId="orderId"
                                    :discountOptions="discountOptions"
                                    :sales-settings="salesSettings"
                                    :loyalty-redemption-settings="
                                        loyaltyRedemptionSettings
                                    "
                                    :offline-payment-method="
                                        offlinePaymentMethod
                                    "
                                    :cached-payment-card-types="
                                        cachedPaymentCardTypes
                                    "
                                    :offline-payment-card-type-id="
                                        offlinePaymentCardTypeId
                                    "
                                    @discount-applied="loadCurrentPendingSale"
                                    @cart-updated="loadCurrentPendingSale"
                                    @payment-success="onCoffeeshopPaymentSuccess"
                                    @update:offline-payment-method="
                                        syncOfflinePaymentMethod
                                    "
                                    @update:offline-payment-card-type-id="
                                        syncOfflinePaymentCardTypeId
                                    "
                                    @save-offline-sale="completeOfflineSale"
                                />
                            </div>
                        </div>
                    </div>

                    <div
                        v-show="checkoutStep === 'order' && !orderCollapsed"
                        class="cs-rail-charge mt-3 flex shrink-0 items-center justify-between gap-3 border-t border-[var(--cs-border)] bg-[var(--cs-panel)] pt-3"
                    >
                        <div class="min-w-0">
                            <p
                                class="mb-0 text-[10px] font-semibold tracking-[0.12em] text-[var(--cs-muted)]"
                            >
                                TOTAL
                            </p>
                            <p
                                class="cs-display mb-0 truncate text-xl font-semibold text-[var(--cs-ink)]"
                            >
                                {{ formattedTotal(grandTotalDisplay) }}
                            </p>
                        </div>
                        <a-button
                            type="primary"
                            size="large"
                            class="cs-charge-btn shrink-0 rounded-full px-5"
                            :disabled="orders.length === 0"
                            aria-label="Continue to checkout"
                            @click="onCoffeeshopChargeClick"
                        >
                            Charge {{ formattedTotal(grandTotalDisplay) }}
                        </a-button>
                    </div>
                </div>

                <!-- Classic desktop: single cart sidebar -->
                <customer-order
                    v-else
                    layout="sidebar"
                    @customer-changed="handleCustomerChanged"
                    @discount-applied="loadCurrentPendingSale"
                    @cart-updated="loadCurrentPendingSale"
                    @offline-cart-add="onOfflineCartAdd"
                    @offline-cart-subtract="onOfflineCartSubtract"
                    @offline-cart-set-qty="
                        (e) => onOfflineCartSetQty(e.product, e.quantity)
                    "
                    @offline-cart-remove="onOfflineCartRemove"
                    :loading="isLoadingCart"
                    :orders="orders"
                    :orderId="orderId"
                    :orderDiscountAmount="orderDiscountAmount"
                    :orderDiscountId="orderDiscountId"
                    :discountOptions="discountOptions"
                    :offline-cached-customers="offlineCustomersCache"
                />
            </template>
            <template #mobile-actions>
                <SalesMobileCheckoutBar
                    v-if="!isMdUp"
                    :orders="orders"
                    :order-discount-amount="orderDiscountAmount"
                    :sales-settings="salesSettings"
                    :current-sale="currentSale"
                />
            </template>
        </component>

        <SalesCartDrawer v-if="!isMdUp">
            <template #cart>
                <customer-order
                    layout="drawer"
                    :current-sale="currentSale"
                    :sales-settings="salesSettings"
                    @customer-changed="handleCustomerChanged"
                    @discount-applied="loadCurrentPendingSale"
                    @cart-updated="loadCurrentPendingSale"
                    @offline-cart-add="onOfflineCartAdd"
                    @offline-cart-subtract="onOfflineCartSubtract"
                    @offline-cart-set-qty="
                        (e) => onOfflineCartSetQty(e.product, e.quantity)
                    "
                    @offline-cart-remove="onOfflineCartRemove"
                    :loading="isLoadingCart"
                    :orders="orders"
                    :orderId="orderId"
                    :orderDiscountAmount="orderDiscountAmount"
                    :orderDiscountId="orderDiscountId"
                    :discountOptions="discountOptions"
                    :offline-cached-customers="offlineCustomersCache"
                />
            </template>
            <template #payment>
                <total-amount-section
                    layout="compact"
                    :selected-customer="selectedCustomer"
                    :orders="orders"
                    :currentSale="currentSale"
                    :orderDiscountAmount="orderDiscountAmount"
                    :orderDiscountId="orderDiscountId"
                    :orderId="orderId"
                    :discountOptions="discountOptions"
                    :sales-settings="salesSettings"
                    :loyalty-redemption-settings="loyaltyRedemptionSettings"
                    :offline-payment-method="offlinePaymentMethod"
                    :cached-payment-card-types="cachedPaymentCardTypes"
                    :offline-payment-card-type-id="offlinePaymentCardTypeId"
                    @discount-applied="loadCurrentPendingSale"
                    @cart-updated="loadCurrentPendingSale"
                    @payment-success="closeCartDrawer"
                    @update:offline-payment-method="syncOfflinePaymentMethod"
                    @update:offline-payment-card-type-id="
                        syncOfflinePaymentCardTypeId
                    "
                    @save-offline-sale="completeOfflineSale"
                />
            </template>
            <template #drawer-footer>
                <div
                    class="flex items-center justify-between gap-3 px-3 py-3"
                    style="
                        padding-bottom: max(
                            0.75rem,
                            env(safe-area-inset-bottom, 0px)
                        );
                    "
                >
                    <div class="min-w-0">
                        <p class="text-xs text-gray-500">Total</p>
                        <p class="truncate text-lg font-bold text-green-600">
                            {{ formattedTotal(grandTotalDisplay) }}
                        </p>
                    </div>
                    <a-button
                        type="primary"
                        size="large"
                        class="shrink-0"
                        :disabled="orders.length === 0"
                        aria-label="Continue to checkout"
                        @click="goToPaymentStep"
                    >
                        Pay
                    </a-button>
                </div>
            </template>
        </SalesCartDrawer>
        </div>

        <template v-if="isMdUp && !isCoffeeshopLayout" #content-footer>
            <total-amount-section
                :selected-customer="selectedCustomer"
                :orders="orders"
                :currentSale="currentSale"
                :orderDiscountAmount="orderDiscountAmount"
                :orderDiscountId="orderDiscountId"
                :orderId="orderId"
                :discountOptions="discountOptions"
                :sales-settings="salesSettings"
                :loyalty-redemption-settings="loyaltyRedemptionSettings"
                :offline-payment-method="offlinePaymentMethod"
                :cached-payment-card-types="cachedPaymentCardTypes"
                :offline-payment-card-type-id="offlinePaymentCardTypeId"
                @discount-applied="loadCurrentPendingSale"
                @cart-updated="loadCurrentPendingSale"
                @update:offline-payment-method="syncOfflinePaymentMethod"
                @update:offline-payment-card-type-id="syncOfflinePaymentCardTypeId"
                @save-offline-sale="completeOfflineSale"
            />
        </template>
    </AuthenticatedLayout>
</template>
