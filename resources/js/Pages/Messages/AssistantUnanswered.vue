<script setup>
import { computed } from "vue";
import { Head, Link, router } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentHeader from "@/Components/ContentHeader.vue";

/**
 * Questions the AI assistant couldn't help with, newest first. The most-asked topics show which
 * guides and tools to add next; mark a question resolved once help for it exists.
 */
const props = defineProps({
    questions: { type: Object, required: true }, // Laravel paginator
    topTopics: { type: Array, default: () => [] },
    status: { type: String, default: "open" },
    openCount: { type: Number, default: 0 },
});

const columns = [
    { title: "When", key: "created_at", width: 150 },
    { title: "Business", key: "business", width: 160 },
    { title: "Asked by", key: "user", width: 170 },
    { title: "Question", key: "question" },
    { title: "Topic", key: "topic", width: 170 },
    { title: "", key: "actions", width: 130 },
];

const pagination = computed(() => ({
    current: props.questions.current_page,
    pageSize: props.questions.per_page,
    total: props.questions.total,
    showSizeChanger: false,
}));

function load(params) {
    router.get(route("assistant-unanswered.index"), params, { preserveState: true, preserveScroll: true });
}

function onTableChange(page) {
    load({ status: props.status, page: page.current });
}

function setStatus(status) {
    load({ status });
}

function toggleResolved(question) {
    router.post(route("assistant-unanswered.resolve", question.id), {}, { preserveScroll: true });
}

function formatWhen(iso) {
    try {
        return new Date(iso).toLocaleString();
    } catch {
        return iso;
    }
}
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Unanswered by AI" />
        <ContentHeader class="mb-6" title="Unanswered by AI">
            <template #actions>
                <Link :href="route('messages.index')">
                    <a-button>Back to inquiries</a-button>
                </Link>
            </template>
            <template #meta>
                Questions the AI assistant couldn't help with. Add a guide or tool for the common ones, then mark them resolved.
            </template>
        </ContentHeader>

        <div class="space-y-4">
            <section v-if="topTopics.length" class="rounded-lg bg-white p-4 shadow">
                <h2 class="mb-2 text-sm font-semibold text-gray-800">Most asked (open)</h2>
                <div class="flex flex-wrap gap-2">
                    <a-tag v-for="t in topTopics" :key="t.topic" color="blue">
                        {{ t.topic }} · {{ t.total }}
                    </a-tag>
                </div>
            </section>

            <section class="rounded-lg bg-white p-4 shadow">
                <div class="mb-3 flex items-center justify-between gap-2">
                    <a-radio-group :value="status" button-style="solid" size="small" @change="(e) => setStatus(e.target.value)">
                        <a-radio-button value="open">Open ({{ openCount }})</a-radio-button>
                        <a-radio-button value="resolved">Resolved</a-radio-button>
                    </a-radio-group>
                </div>

                <a-table
                    :columns="columns"
                    :data-source="questions.data"
                    :pagination="pagination"
                    row-key="id"
                    size="middle"
                    :scroll="{ x: 900 }"
                    @change="onTableChange"
                >
                    <template #bodyCell="{ column, record }">
                        <template v-if="column.key === 'created_at'">
                            <span class="text-xs text-gray-600">{{ formatWhen(record.created_at) }}</span>
                        </template>
                        <template v-else-if="column.key === 'business'">
                            {{ record.organization?.name || record.domain }}
                        </template>
                        <template v-else-if="column.key === 'user'">
                            <div>{{ record.user?.name || "Deleted user" }}</div>
                            <div class="text-xs text-gray-500">{{ record.role }}</div>
                        </template>
                        <template v-else-if="column.key === 'question'">
                            <p class="mb-0 whitespace-pre-wrap break-words">{{ record.question }}</p>
                            <p v-if="record.page" class="mb-0 mt-1 text-xs text-gray-500">on {{ record.page }}</p>
                        </template>
                        <template v-else-if="column.key === 'topic'">
                            <a-tag v-if="record.topic">{{ record.topic }}</a-tag>
                        </template>
                        <template v-else-if="column.key === 'actions'">
                            <a-button size="small" @click="toggleResolved(record)">
                                {{ record.resolved_at ? "Reopen" : "Mark resolved" }}
                            </a-button>
                        </template>
                    </template>
                </a-table>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
