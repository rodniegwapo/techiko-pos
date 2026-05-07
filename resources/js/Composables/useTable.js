import { computed } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { useGlobalVariables } from "./useGlobalVariable";

/**
 * @param {string} resourceKey
 * @param {Record<string, import('vue').Ref>} filters
 * @param {{ preserveQueryKeys?: string[] }} [options]
 */
export function useTable(resourceKey = "items", filters = {}, options = {}) {
    const { spinning } = useGlobalVariables();
    const page = usePage();
    const preserveQueryKeys = options.preserveQueryKeys ?? [];

    const meta = computed(() => page.props?.[resourceKey]?.meta ?? {});

    const pagination = computed(() => ({
        total: meta.value.total ?? 0,
        current: meta.value.current_page ?? 1,
        pageSize: meta.value.per_page ?? 10,
        showTotal: (total, range) => `${range[0]}-${range[1]} of ${total} items`,
        showSizeChanger: false,
    }));

    const preservedQueryParams = () => {
        if (!preserveQueryKeys.length || typeof window === "undefined") {
            return {};
        }
        const url = new URL(page.url, window.location.origin);
        const out = {};
        for (const key of preserveQueryKeys) {
            const v = url.searchParams.get(key);
            if (v != null && v !== "") {
                out[key] = v;
            }
        }
        return out;
    };

    const buildData = (event = {}) => {
        // clean up empty filters
        const filterData = Object.fromEntries(
            Object.entries(filters).map(([k, v]) => [k, v?.value || undefined])
        );

        return {
            ...preservedQueryParams(),
            ...filterData,
            per_page: event.pageSize ?? pagination.value.pageSize,
            page: event.current ?? pagination.value.current,
        };
    };

    const handleTableChange = (event) => {
        router.reload({
            data: buildData(event),
            onStart: () => (spinning.value = true),
            onFinish: () => (spinning.value = false),
        });
    };

    const reload = () => {
        router.reload({
            data: buildData(),
            onStart: () => (spinning.value = true),
            onFinish: () => (spinning.value = false),
        });
    };

    return { spinning, pagination, handleTableChange, reload };
}
