// composables/useTable.js
import { ref, computed } from "vue";
import { router } from "@inertiajs/vue3";
import { useGlobalVariables } from "./useGlobalVariable";

const { spinning } = useGlobalVariables();

export function useTable(props, routeName) {
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
            onStart: () => (spinning.value = true),
            onFinish: () => (spinning.value = false),
            data: {
                per_page: event.pageSize,
                page: event.current,
            },
        });
    };

    return {
        spinning,
        pagination,
        handleTableChange,
    };
}
