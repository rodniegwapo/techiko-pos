import { computed } from "vue";
import {
    IconDashboard,
    IconCategory,
    IconUsers,
    IconHeartHandshake,
    IconHistory,
    IconGift,
    IconUserCog,
    IconPackage,
    IconUserCheck,
    IconWorld,
    IconKey,
    IconCreditCard,
    IconWallet,
    IconReportAnalytics,
    IconReceipt,
    IconReceipt2,
    IconCash,
    IconListDetails,
    IconAccessPointOff,
    IconSettings,
    IconMessages,
    IconMail,
    IconSparkles,
    IconTruck,
    IconClock,
    IconArmchair,
} from "@tabler/icons-vue";
import { router, usePage } from "@inertiajs/vue3";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";
import { useDomainRoutes } from "@/Composables/useDomainRoutes";
import { useSidebar } from "@/Composables/useSidebar";
import { useSalesLayoutMode } from "@/Composables/useSalesLayoutMode";
import { BUSINESS_TYPES } from "@/Composables/useBusinessType";

// The sidebar's sections, in order. Top-level items without a section (Dashboard) come first.
export const NAV_SECTIONS = [
    { key: "sell", title: "Sell" },
    { key: "stock", title: "Catalog & stock" },
    { key: "money", title: "Money" },
    { key: "team", title: "Team & settings" },
    { key: "admin", title: "Techiko admin" },
];

// ===================================
// MENU ITEMS DEFINITION
// ===================================
// `keywords` only help the quick search (Ctrl+K) find a page by other names.
const menuItems = [
    {
        key: "dashboard",
        title: "Dashboard",
        icon: IconDashboard,
        routeName: "dashboard",
        path: "/dashboard",
        keywords: "home overview",
        // Dashboard should be available in both global and domain contexts
    },
    {
        key: "sales",
        title: "Sales",
        icon: IconHeartHandshake,
        routeName: "sales.index",
        path: "/sales",
        section: "sell",
        keywords: "pos checkout sell order cashier",
        domainOnly: true, // Sales are now domain-only
    },
    {
        key: "offline-sales",
        title: "Offline sales",
        icon: IconAccessPointOff,
        routeName: "sales.offline-transactions",
        path: "/sales/offline-transactions",
        section: "sell",
        keywords: "sync offline transactions",
        domainOnly: true,
    },
    {
        key: "sales-history",
        title: "Sales History",
        icon: IconReceipt,
        routeName: "sales-history.index",
        path: "/sales-history",
        section: "sell",
        keywords: "receipts transactions orders refund",
        domainOnly: true,
    },
    {
        key: "sales-import",
        title: "Import Sales",
        icon: IconReceipt2,
        routeName: "sales-import.index",
        path: "/sales-import",
        section: "sell",
        keywords: "import csv backdate past old sales upload template",
        domainOnly: true,
    },
    {
        key: "customers",
        title: "Customers",
        icon: IconUsers,
        routeName: "customers.index",
        path: "/customers",
        section: "sell",
        hideForSuperOutsideDomain: true,
    },
    {
        key: "credits",
        title: "Credit Management",
        icon: IconCreditCard,
        routeName: "credits.index",
        path: "/credits",
        section: "sell",
        keywords: "utang credit balance",
        domainOnly: true,
    },
    {
        key: "loyalty",
        title: "Loyalty Program",
        icon: IconGift,
        routeName: "loyalty.index",
        path: "/loyalty",
        section: "sell",
        keywords: "points rewards",
        hideForSuperOutsideDomain: true,
    },
    {
        key: "voids",
        title: "Void Logs",
        icon: IconHistory,
        routeName: "voids.index",
        path: "/void-logs",
        section: "sell",
        keywords: "voided cancelled",
        hideForSuperOutsideDomain: true,
    },
    {
        key: "domains",
        title: "Domains",
        icon: IconWorld,
        routeName: "domains.index",
        path: "/domains",
        section: "admin",
        keywords: "organizations orgs tenants",
        superUserOnly: true,
        globalOnly: true,
    },
    {
        key: "products",
        title: "Products",
        icon: IconCategory,
        section: "stock",
        children: [
            {
                key: "products-items",
                title: "Items",
                routeName: "products.index",
                path: "/products",
                keywords: "products menu catalog",
                hideForSuperOutsideDomain: true,
            },
            {
                key: "products-categories",
                title: "Categories",
                routeName: "categories.index",
                path: "/categories",
                hideForSuperOutsideDomain: true,
            },
            {
                key: "products-discounts",
                title: "Discounts",
                routeName: "products.discounts.index",
                path: "/products/discounts",
                keywords: "promo",
                hideForSuperOutsideDomain: true,
            },
            {
                key: "catalog-shared-products",
                title: "Shared catalog",
                routeName: "catalog.shared-products.index",
                path: "/catalog/shared-products",
                superUserOnly: true,
                globalOnly: true,
            },
            {
                key: "catalog-suggestions",
                title: "Catalog suggestions",
                routeName: "catalog.shared-product-suggestions.index",
                path: "/catalog/shared-product-suggestions",
                superUserOnly: true,
                globalOnly: true,
            },
            {
                key: "mandatory-discounts",
                title: "Mandatory Discounts",
                routeName: "mandatory-discounts.index",
                path: "/mandatory-discounts",
                keywords: "senior pwd",
                hideForSuperOutsideDomain: true,
            },
        ],
    },
    {
        key: "inventory",
        title: "Inventory",
        icon: IconPackage,
        section: "stock",
        hideForSuperOutsideDomain: true,
        children: [
            {
                key: "inventory-dashboard",
                title: "Dashboard",
                routeName: "inventory.index",
                path: "/inventory",
                keywords: "stock overview",
            },
            {
                key: "inventory-products",
                title: "Products",
                routeName: "inventory.products",
                path: "/inventory/products",
                keywords: "stock levels receive",
            },
            {
                key: "inventory-movements",
                title: "Movements",
                routeName: "inventory.movements",
                path: "/inventory/movements",
                keywords: "stock history transfers",
                feature: "advanced_inventory",
            },
            {
                key: "inventory-adjustments",
                title: "Stock Adjustments",
                routeName: "inventory.adjustments.index",
                path: "/inventory/adjustments",
                keywords: "count correction",
            },
            {
                key: "inventory-locations",
                title: "Locations",
                routeName: "inventory.locations.index",
                path: "/inventory/locations",
                keywords: "stores warehouse branch",
            },
            {
                key: "inventory-valuation",
                title: "Valuation Report",
                routeName: "inventory.valuation",
                path: "/inventory/valuation",
                keywords: "stock value cost",
                feature: "advanced_inventory",
            },
        ],
    },
    {
        key: "purchasing",
        title: "Purchasing",
        icon: IconTruck,
        routeName: "purchase-orders.index",
        path: "/purchasing",
        section: "stock",
        domainOnly: true,
        hideForSuperOutsideDomain: true,
        keywords: "purchase order supplier receive stock delivery",
    },
    {
        key: "finance-group",
        title: "Finance",
        icon: IconReportAnalytics,
        section: "money",
        domainOnly: true,
        children: [
            {
                key: "finance-dashboard",
                title: "Overview",
                routeName: "finance.dashboard",
                path: "/finance",
                feature: "finance",
            },
            {
                // Menu labels are everyday words; the accounting names stay findable as keywords.
                key: "profit-loss",
                title: "Profit & loss",
                routeName: "profit-loss.index",
                path: "/profit-loss",
                keywords: "income statement profit loss p&l pnl",
                feature: "finance",
            },
            {
                key: "finance-cash-flow",
                title: "Money in & out",
                routeName: "finance.cash-flow",
                path: "/finance/cash-flow",
                keywords: "cash flow",
                feature: "finance",
            },
            {
                key: "finance-balance-sheet",
                title: "What you own & owe",
                routeName: "finance.balance-sheet",
                path: "/finance/balance-sheet",
                keywords: "balance sheet assets liabilities",
                feature: "finance",
            },
            {
                key: "finance-receivables",
                title: "Customers' utang",
                routeName: "finance.receivables",
                path: "/finance/receivables",
                keywords: "customer credit receivables utang",
                feature: "finance",
            },
            {
                key: "finance-payables",
                title: "Bills to pay",
                routeName: "finance.payables.index",
                path: "/finance/payables",
                keywords: "supplier bills payables suppliers",
                feature: "finance",
            },
            {
                key: "expenses",
                title: "Expenses",
                routeName: "expenses.index",
                path: "/expenses",
                keywords: "spending costs",
                feature: "expenses",
            },
            {
                key: "finance-other-income",
                title: "Other income",
                routeName: "finance.other-income.index",
                path: "/finance/other-income",
                feature: "finance",
            },
            {
                key: "finance-balance-items",
                title: "Bank, loans & equipment",
                routeName: "finance.balance-items.index",
                path: "/finance/assets-and-loans",
                keywords: "accounts loans assets bank equipment",
                feature: "finance",
            },
            {
                key: "finance-reviews",
                title: "Monthly review",
                routeName: "finance.reviews.index",
                path: "/finance/reviews",
                keywords: "close month",
                feature: "finance",
            },
            {
                key: "vat-report",
                title: "VAT report",
                routeName: "vat-report.index",
                path: "/vat-report",
                keywords: "tax bir",
                feature: "finance",
            },
        ],
    },
    {
        key: "wallet-group",
        title: "Cash & wallet",
        icon: IconWallet,
        section: "money",
        domainOnly: true,
        children: [
            {
                key: "wallet-drawer",
                title: "Today's drawer",
                routeName: "wallet.drawer",
                path: "/wallet/drawer",
                keywords: "cash drawer shift opening count end shift money movement",
            },
            {
                key: "wallet-cash-in-out",
                title: "Cash in & out",
                routeName: "wallet.cash-in-out",
                path: "/wallet/cash-in-out",
                keywords: "owner draw float gcash transfer ledger",
            },
            {
                key: "wallet-balances",
                title: "Where money is",
                routeName: "wallet.balances",
                path: "/wallet/balances",
                keywords: "gcash bank card terminals payment channels balance",
            },
            {
                key: "wallet-history",
                title: "Drawer history",
                routeName: "wallet.history",
                path: "/wallet/history",
                keywords: "counts opening changes closed shifts",
            },
            {
                key: "wallet-counts",
                title: "Counted money",
                routeName: "register-shifts.index",
                path: "/wallet/shifts",
                keywords: "cashier shift count approve review short over variance",
            },
        ],
    },
    {
        key: "users",
        title: "Users",
        icon: IconUserCog,
        routeName: "users.index",
        path: "/users",
        section: "team",
        keywords: "staff employees cashiers",
    },
    {
        key: "timesheets",
        title: "Timesheets",
        icon: IconClock,
        routeName: "timesheets.index",
        path: "/timesheets",
        section: "team",
        domainOnly: true,
        hideForSuperOutsideDomain: true,
        keywords: "timeclock clock in out hours attendance payroll",
    },
    {
        key: "dining-tables",
        title: "Tables",
        icon: IconArmchair,
        routeName: "dining-tables.index",
        path: "/dining-tables",
        section: "team",
        domainOnly: true,
        hideForSuperOutsideDomain: true,
        feature: "dine_in",
        keywords: "dine-in restaurant tables floor plan tabs kitchen",
    },
    {
        key: "roles",
        title: "Roles",
        icon: IconUserCheck,
        routeName: "roles.index",
        path: "/roles",
        section: "team",
        globalOnly: true, // Roles are now global-only
    },
    {
        key: "organization-settings",
        title: "Settings",
        icon: IconSettings,
        routeName: "settings.index",
        path: "/settings",
        section: "team",
        keywords: "organization receipt",
        domainOnly: true,
    },
    {
        key: "billing-servicing",
        title: "Billing",
        icon: IconReceipt2,
        routeName: "billing.servicing.index",
        path: "/billing/servicing",
        section: "team",
        keywords: "plan upgrade subscription pay",
        domainOnly: true,
    },
    {
        key: "ai-usage",
        title: "AI usage",
        icon: IconSparkles,
        routeName: "ai-usage.index",
        path: "/ai-usage",
        section: "team",
        keywords: "credits assistant",
        domainOnly: true,
        feature: "ai",
    },
    {
        key: "plans",
        title: "Plans",
        icon: IconListDetails,
        routeName: "service-tiers.index",
        path: "/plans",
        section: "admin",
        keywords: "tiers pricing",
        superUserOnly: true,
        globalOnly: true,
    },
    {
        key: "manual-gcash-payments",
        title: "Manual GCash payments",
        icon: IconCash,
        routeName: "billing.manual-payments.index",
        path: "/billing/manual-payments",
        section: "admin",
        superUserOnly: true,
        globalOnly: true,
    },
    {
        key: "messages",
        title: "Messages",
        icon: IconMessages,
        routeName: "messages.index",
        path: "/messages",
        section: "admin",
        keywords: "inbox chat inquiries",
        superUserOnly: true,
        globalOnly: true,
    },
    {
        key: "permissions",
        title: "Permissions",
        icon: IconKey,
        routeName: "permissions.index",
        path: "/permissions",
        section: "admin",
        superUserOnly: true,
        globalOnly: true,
    },
    {
        key: "mail-test",
        title: "Test email",
        icon: IconMail,
        routeName: "mail.test",
        path: "/mail/test",
        section: "admin",
        superUserOnly: true,
        globalOnly: true,
    },
];

// The menu the current user may see, and how to open its pages. Shared by the sidebar and the quick search.
export function useNavMenu() {
    const page = usePage();
    const { hasPermission } = usePermissionsV2();
    const { getRoute, getLocationQueryFromPage } = useDomainRoutes();
    const { closeMobileDrawer } = useSidebar();
    const { isCoffeeshopLayout } = useSalesLayoutMode();

    const isSuperUser = computed(() => !!page.props.auth?.user?.data?.is_super_user);

    // Helper function to detect domain from current URL
    const getCurrentDomainFromUrl = () => {
        // The Domains admin pages (/domains/{slug}, /domains/{slug}/edit) only look at an organization.
        if (page.props.inOrganization === false) return null;
        const currentPath = window.location.pathname;
        const domainMatch = currentPath.match(/\/domains\/([^\/]+)/);
        return domainMatch ? domainMatch[1] : null;
    };

    // Whether current URL is inside a domain context
    const isInDomainContext = computed(() => {
        void page.url;
        return !!getCurrentDomainFromUrl();
    });

    // Plans are enforced and this user can pay for the organization, so paid pages show locked with "Upgrade".
    const canUpgrade = computed(
        () =>
            !!page.props.features?.plan_gating &&
            !!page.props.inOrganization &&
            hasPermission("billing.servicing.index"),
    );

    // A locked organization (subscription on hold): the menu shows but nothing opens (the server blocks the pages too).
    const menuLocked = computed(() => !!page.props.subscriptionStatus?.locked && !isSuperUser.value);

    // ===================================
    // FILTER MENUS BASED ON PERMISSIONS
    // ===================================
    const inHiddenArea = (routeName) => {
        const areas = page.props.locationAreas;
        return (areas?.hidden ?? []).some((area) =>
            (areas?.prefixes?.[area] ?? []).some((prefix) => routeName.startsWith(prefix)),
        );
    };

    // Pages this business type rarely needs (useBusinessType.js) leave the menu, even when the business
    // has records there, until it turns on "Show all menu items" (Settings says which have records).
    // Page search still finds them.
    const tidiedAway = (key) => {
        if (!isInDomainContext.value || page.props.showAllMenu !== false) return false;
        return (BUSINESS_TYPES[page.props.businessType]?.hiddenMenuKeys ?? []).includes(key);
    };

    const openableMenus = computed(() => {
        const filterMenuItems = (items) =>
            items
                .filter((item) => {
                    // Hide global-only menus when browsing inside a domain
                    if (isInDomainContext.value && item.globalOnly) {
                        return false;
                    }

                    // Hide domain-only menus when browsing in global context
                    if (!isInDomainContext.value && item.domainOnly) {
                        return false;
                    }

                    // Check if item is super user only
                    if (item.superUserOnly && !isSuperUser.value) {
                        return false;
                    }

                    // Tenant ops: hide from super when browsing global URLs (show again under /domains/{slug}/)
                    if (isSuperUser.value && !isInDomainContext.value && item.hideForSuperOutsideDomain) {
                        return false;
                    }

                    // Hide Sales for super users (Sales page not relevant for super user role)
                    if (item.key === "sales" && isSuperUser.value) {
                        return false;
                    }

                    // Coffeeshop layout: Offline sales page is hidden
                    if (item.key === "offline-sales" && isCoffeeshopLayout.value) {
                        return false;
                    }

                    // A warehouse doesn't sell, so there's no Sales screen there (Sales History stays).
                    if (
                        ["sales", "offline-sales"].includes(item.key) &&
                        page.props.currentLocation?.type === "warehouse"
                    ) {
                        return false;
                    }

                    // Parts this location doesn't show (config/location_areas.php): the books at a store,
                    // customers and card terminals at a warehouse. The server turns these pages away too.
                    if (item.routeName && inHiddenArea(item.routeName)) {
                        return false;
                    }

                    // Dine-in is coming soon (config features.dine_in): no plan has it, so it's hidden from
                    // everyone, not shown locked. The server turns its pages away too.
                    if (item.feature === "dine_in" && !page.props.features?.dine_in) {
                        return false;
                    }

                    // A feature the organization doesn't have (the server blocks it too): shown locked to
                    // whoever can upgrade the plan, hidden from everyone else.
                    if (item.feature && !page.props.domainFeatures?.[item.feature] && !canUpgrade.value) {
                        return false;
                    }

                    if (item.key === "billing-servicing" && !page.props.features?.domain_servicing_sidebar_visible) {
                        return false;
                    }

                    // Check permission for items with routeName (the server denies these routes too)
                    if (item.routeName && !hasPermission(item.routeName)) {
                        return false;
                    }

                    return true;
                })
                .map((item) => {
                    const filteredItem = {
                        ...item,
                        upgrade: !!item.feature && !page.props.domainFeatures?.[item.feature],
                        tidied: tidiedAway(item.key),
                    };

                    if (item.children) {
                        const filteredChildren = filterMenuItems(item.children);
                        if (filteredChildren.length === 0) {
                            // A group with no permitted pages leads nowhere, so hide it
                            return null;
                        }
                        filteredItem.children = filteredChildren;
                        filteredItem.upgrade = filteredChildren.every((child) => child.upgrade);
                    }

                    return filteredItem;
                })
                .filter(Boolean);

        return filterMenuItems(menuItems);
    });

    // The sidebar: every page the user can open, less the ones this business type tidies away
    // (a group left empty goes too). Page search keeps them all (searchEntries).
    const menus = computed(() =>
        openableMenus.value
            .filter((item) => !item.tidied)
            .map((item) => (item.children ? { ...item, children: item.children.filter((child) => !child.tidied) } : item))
            .filter((item) => !item.children || item.children.length),
    );

    // Items without a section (Dashboard) on top, then each section that has something in it.
    const topItems = computed(() => menus.value.filter((item) => !item.section));
    const sections = computed(() =>
        NAV_SECTIONS.map((section) => ({
            ...section,
            items: menus.value.filter((item) => item.section === section.key),
        })).filter((section) => section.items.length),
    );

    // Every page the user can open, flat, for the quick search.
    const searchEntries = computed(() =>
        openableMenus.value.flatMap((item) =>
            item.children
                ? item.children.map((child) => ({ ...child, parent: item.title, icon: item.icon }))
                : [{ ...item, parent: null }],
        ),
    );

    // ===================
    // WALLET MENU QUERY (optional business_date from current URL only)
    // ===================
    function walletMenuQuery() {
        const url = page.url || "";
        const idx = url.indexOf("?");
        if (idx === -1) {
            return {};
        }
        const bd = new URLSearchParams(url.slice(idx + 1)).get("business_date");
        return bd ? { business_date: bd } : {};
    }

    // Opens a menu page. Returns the key to mark selected, or null when nothing was opened.
    function navigate(menu) {
        if (menuLocked.value) return null;
        // Not part of the plan: open the plans instead.
        if (menu.upgrade) {
            router.get(getRoute("billing.servicing.index"));
            closeMobileDrawer();
            return null;
        }
        if (!menu.routeName) {
            console.warn("No routeName for menu:", menu);
            return null;
        }

        try {
            localStorage.setItem("selectedMenuForMobile", menu.title);
        } catch {
            // ignore
        }
        try {
            const routePath = getRoute(menu.routeName);
            if (!routePath || routePath === "#") {
                console.error("Invalid route generated for menu:", menu.title, "routeName:", menu.routeName);
                return null;
            }

            if (menu.key.startsWith("wallet-") && menu.key !== "wallet-group") {
                router.get(routePath, { ...getLocationQueryFromPage(), ...walletMenuQuery() });
            } else if (getCurrentDomainFromUrl()) {
                router.get(routePath, getLocationQueryFromPage());
            } else {
                router.visit(routePath);
            }
            closeMobileDrawer();
            return menu.key;
        } catch (error) {
            console.error("Navigation error:", error, "for menu:", menu);
            return null;
        }
    }

    return {
        menus,
        topItems,
        sections,
        searchEntries,
        navigate,
        menuLocked,
        isSuperUser,
        getCurrentDomainFromUrl,
        getRoute,
    };
}
