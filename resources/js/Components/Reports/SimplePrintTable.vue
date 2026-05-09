<script setup>
/**
 * Semantic HTML table for print / export (default slot = tbody rows as <tr>).
 * Use the `footer` slot for <tr> inside <tfoot> (period totals, etc.).
 */
defineProps({
    title: {
        type: String,
        default: '',
    },
    subtitle: {
        type: String,
        default: '',
    },
    columns: {
        type: Array,
        default: () => [],
    },
});
</script>

<template>
    <div class="simple-print-table vat-print-sheet text-gray-900">
        <h1
            v-if="title"
            class="mb-1 text-lg font-semibold text-gray-900"
        >
            {{ title }}
        </h1>
        <p
            v-if="subtitle"
            class="mb-3 text-sm text-gray-600"
        >
            {{ subtitle }}
        </p>
        <table class="w-full border-collapse border border-gray-300 text-sm">
            <thead>
                <tr class="bg-gray-100">
                    <th
                        v-for="col in columns"
                        :key="col.key"
                        class="border border-gray-300 px-2 py-1.5 text-left font-medium text-gray-800"
                    >
                        {{ col.title }}
                    </th>
                </tr>
            </thead>
            <tbody>
                <slot />
            </tbody>
            <tfoot v-if="$slots.footer">
                <slot name="footer" />
            </tfoot>
        </table>
    </div>
</template>

<style scoped>
@media print {
    .simple-print-table table {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
</style>

<!-- Unscoped: vue3-print-nb clones #vat-print-area into an iframe; these rules travel with the main bundle and help if utility-class copy is incomplete -->
<style>
.vat-print-sheet table {
    border-collapse: collapse;
    width: 100%;
}
.vat-print-sheet th,
.vat-print-sheet td {
    border: 1px solid #d1d5db;
    padding: 0.35rem 0.5rem;
}
.vat-print-sheet thead th {
    background: #f3f4f6;
    font-weight: 600;
}
</style>
