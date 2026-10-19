<script setup>
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from "vue";
import { IconLock, IconSearch, IconCornerDownLeft } from "@tabler/icons-vue";
import { useNavMenu } from "@/Composables/useNavMenu";
import { OPEN_NAV_SEARCH_EVENT } from "@/Composables/useNavSearch";

// Quick page search: Ctrl+K (⌘K) anywhere, or the search button in the sidebar.
// Lists the same pages the sidebar shows this user, and opens them the same way.
const { searchEntries, navigate, menuLocked } = useNavMenu();

const MAX_RESULTS = 8;

const open = ref(false);
const query = ref("");
const active = ref(0);
const input = ref(null);

const results = computed(() => {
    const words = query.value.toLowerCase().trim().split(/\s+/).filter(Boolean);
    const entries = searchEntries.value;
    if (!words.length) {
        return entries.slice(0, MAX_RESULTS);
    }

    return entries
        .map((entry) => {
            const label = entry.title.toLowerCase();
            const haystack = [label, entry.parent ?? "", entry.keywords ?? ""].join(" ").toLowerCase();
            if (!words.every((word) => haystack.includes(word))) {
                return null;
            }
            // Pages whose own name matches come first, then those starting with what was typed.
            const score =
                (words.every((word) => label.includes(word)) ? 2 : 0) +
                (label.startsWith(words[0]) ? 1 : 0);
            return { entry, score };
        })
        .filter(Boolean)
        .sort((a, b) => b.score - a.score)
        .slice(0, MAX_RESULTS)
        .map(({ entry }) => entry);
});

watch(results, () => {
    active.value = 0;
});

function show() {
    if (menuLocked.value) return;
    query.value = "";
    active.value = 0;
    open.value = true;
    // The modal renders its content on open, so focus once it is there.
    nextTick(() => setTimeout(() => input.value?.focus(), 50));
}

function choose(entry) {
    if (!entry) return;
    open.value = false;
    navigate(entry);
}

function onInputKeydown(e) {
    if (e.key === "ArrowDown") {
        e.preventDefault();
        active.value = Math.min(active.value + 1, results.value.length - 1);
    } else if (e.key === "ArrowUp") {
        e.preventDefault();
        active.value = Math.max(active.value - 1, 0);
    } else if (e.key === "Enter") {
        e.preventDefault();
        choose(results.value[active.value]);
    }
}

function onGlobalKeydown(e) {
    if ((e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey && e.key.toLowerCase() === "k") {
        e.preventDefault();
        if (open.value) {
            open.value = false;
        } else {
            show();
        }
    }
}

onMounted(() => {
    window.addEventListener("keydown", onGlobalKeydown);
    window.addEventListener(OPEN_NAV_SEARCH_EVENT, show);
});
onBeforeUnmount(() => {
    window.removeEventListener("keydown", onGlobalKeydown);
    window.removeEventListener(OPEN_NAV_SEARCH_EVENT, show);
});
</script>

<template>
    <a-modal
        v-model:visible="open"
        :footer="null"
        :closable="false"
        :width="520"
        :body-style="{ padding: 0 }"
        :style="{ top: '12vh' }"
        destroy-on-close
        wrap-class-name="nav-search-modal"
        @after-close="query = ''"
    >
        <div data-testid="nav-search">
            <div class="flex items-center gap-2 border-b border-gray-100 px-4 py-3">
                <IconSearch :size="18" class="shrink-0 text-gray-400" />
                <input
                    ref="input"
                    v-model="query"
                    type="text"
                    class="w-full border-0 bg-transparent p-0 text-base text-gray-800 outline-none ring-0 placeholder:text-gray-400 focus:ring-0"
                    placeholder="Search pages… e.g. cash flow, users, stores"
                    aria-label="Search pages"
                    data-testid="nav-search-input"
                    @keydown="onInputKeydown"
                />
                <kbd class="rounded border border-gray-200 px-1.5 text-[10px] text-gray-400">Esc</kbd>
            </div>

            <ul v-if="results.length" class="max-h-[50vh] overflow-y-auto py-1" role="listbox">
                <li
                    v-for="(entry, i) in results"
                    :key="entry.key"
                    role="option"
                    :aria-selected="i === active"
                    class="flex cursor-pointer items-center gap-3 px-4 py-2"
                    :class="i === active ? 'bg-sky-50' : 'hover:bg-gray-50'"
                    data-testid="nav-search-item"
                    @mouseenter="active = i"
                    @click="choose(entry)"
                >
                    <component :is="entry.icon" v-if="entry.icon" :size="18" class="shrink-0 text-gray-500" />
                    <span class="flex-1 truncate text-sm text-gray-800">
                        <span v-if="entry.parent" class="text-gray-400">{{ entry.parent }} › </span>{{ entry.title }}
                    </span>
                    <span
                        v-if="entry.upgrade"
                        class="flex items-center gap-0.5 text-[11px] text-amber-600"
                        data-testid="nav-search-upgrade"
                    >
                        <IconLock :size="12" /> Upgrade
                    </span>
                    <IconCornerDownLeft v-else-if="i === active" :size="14" class="text-gray-400" />
                </li>
            </ul>
            <p v-else class="px-4 py-6 text-center text-sm text-gray-500">
                No pages match “{{ query }}”.
            </p>
        </div>
    </a-modal>
</template>
