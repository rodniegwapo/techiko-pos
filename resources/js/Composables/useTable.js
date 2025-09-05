// composables/useTable.js
import { ref, computed } from "vue";
import { router } from "@inertiajs/vue3";

export function useTable(props, routeName) {
    const spinning = ref(false);

    const pagination = computed(() => {
        return {
            total: props.items?.meta?.total ?? 0,
            current: props.items?.meta?.current_page ?? 1,
            pageSize: props.items?.meta?.per_page ?? 10,
            showTotal: (total, range) =>
                `${range[0]}-${range[1]} of ${total} items`,
            showSizeChanger: false,
        };
    });

    const handleTableChange = (event) => {
        router.reload({
            onProgress: () => (spinning.value = true),
            onFinish: () => (spinning.value = false),
            data: {
                per_page: event.pageSize,
                page: event.current,
            },
        });
    };

    const getItems = (pageSize = null, current = 1, items = []) => {
        router.get(
            route(routeName),
            {
                page: current,
                per_page: pageSize,
            },
            {
                preserveState: true,
                replace: true,
                only: items,
                onStart: () => (spinning.value = true),
                onFinish: () => (spinning.value = false),
            }
        );
    };

    return {
        spinning,
        pagination,
        handleTableChange,
        getItems,
    };
}
