import { computed } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import { useGlobalVariables } from "./useGlobalVariable";

export function useTable(resourceKey = "items", filters = {}) {
    const { spinning } = useGlobalVariables();
    const page = usePage();

    const meta = computed(() => page.props?.[resourceKey]?.meta ?? {});

    const pagination = computed(() => ({
        total: meta.value.total ?? 0,
        current: meta.value.current_page ?? 1,
        pageSize: meta.value.per_page ?? 10,
        showTotal: (total, range) => `${range[0]}-${range[1]} of ${total} items`,
        showSizeChanger: false,
    }));

    const buildData = (event = {}) => {
        // clean up empty filters
        const filterData = Object.fromEntries(
            Object.entries(filters).map(([k, v]) => [k, v?.value || undefined])
        );

        return {
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
