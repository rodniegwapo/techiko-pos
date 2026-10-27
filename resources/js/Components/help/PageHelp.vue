<script setup>
import { computed, ref } from "vue";
import { usePage } from "@inertiajs/vue3";
import { IconHelpCircle, IconSparkles } from "@tabler/icons-vue";
import { usePermissionsV2 } from "@/Composables/usePermissionV2";
import { openAssistant } from "@/Composables/useAssistant";
import PAGE_HELP from "@/help/page-help.json";

/**
 * The "?" next to a page's title: what the page is for, in English and Tagalog, with its
 * how-to guides and a question for the assistant. Looked up by the page's component
 * (e.g. "Wallet/Drawer"), or by `helpKey` where one component serves several jobs.
 */
const props = defineProps({
    helpKey: { type: String, default: null },
});

const page = usePage();
const { hasPermission } = usePermissionsV2();
const open = ref(false);

const key = computed(() => props.helpKey ?? page.component);
const help = computed(() => PAGE_HELP[key.value] ?? null);

if (import.meta.env.DEV && !PAGE_HELP[props.helpKey ?? page.component]) {
    console.warn(`[PageHelp] No help text for "${props.helpKey ?? page.component}" in resources/js/help/page-help.json`);
}

// The same rule as the chat drawer's assistant tab.
const canAsk = computed(
    () => !!page.props.domainFeatures?.ai && !!page.props.currentDomain && hasPermission("assistant.ask"),
);
// Guides this user may follow (helpGuides: { key: title } from the server).
const guides = computed(() =>
    (help.value?.guides ?? [])
        .map((g) => ({ key: g, title: page.props.helpGuides?.[g] }))
        .filter((g) => g.title)
        .slice(0, 3),
);
const question = computed(() => help.value?.ask?.[0] ?? null);

function ask(text) {
    open.value = false;
    openAssistant(text);
}
</script>

<template>
    <a-popover
        v-if="help"
        v-model:visible="open"
        trigger="click"
        placement="bottomLeft"
        :overlay-style="{ maxWidth: 'min(360px, calc(100vw - 32px))' }"
    >
        <template #content>
            <div class="space-y-3 text-sm" data-testid="page-help-content">
                <div>
                    <div class="mb-1 text-xs font-semibold uppercase text-gray-500">What this page is for</div>
                    <p class="m-0 text-gray-800" data-testid="page-help-en">{{ help.en }}</p>
                </div>
                <div>
                    <div class="mb-1 text-xs font-semibold uppercase text-gray-500">Tagalog</div>
                    <p class="m-0 text-gray-600" data-testid="page-help-tl">{{ help.tl }}</p>
                </div>
                <div v-if="canAsk && guides.length">
                    <div class="mb-1 text-xs font-semibold uppercase text-gray-500">How to</div>
                    <ul class="m-0 list-none space-y-1 p-0">
                        <li v-for="g in guides" :key="g.key">
                            <a
                                href="#"
                                class="text-teal-700 hover:underline"
                                data-testid="page-help-guide"
                                @click.prevent="ask(`How do I: ${g.title}?`)"
                            >
                                {{ g.title }}
                            </a>
                        </li>
                    </ul>
                </div>
                <a-button
                    v-if="canAsk"
                    size="small"
                    class="inline-flex items-center gap-1"
                    data-testid="page-help-ask"
                    @click="ask(question ?? `What is the ${help.title} page for?`)"
                >
                    <IconSparkles class="h-4 w-4" /> Ask about this page
                </a-button>
            </div>
        </template>
        <button
            type="button"
            class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-green-50 text-green-600 ring-1 ring-green-200 transition hover:bg-green-600 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-green-500"
            aria-label="What is this page for?"
            title="What is this page for?"
            data-testid="page-help"
        >
            <IconHelpCircle class="h-5 w-5" stroke-width="2.25" />
        </button>
    </a-popover>
</template>
