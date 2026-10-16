// The quick page search (Ctrl+K, NavSearch.vue in AuthenticatedLayout) listens for this event.
export const OPEN_NAV_SEARCH_EVENT = "techiko:open-nav-search";

export function openNavSearch() {
    window.dispatchEvent(new CustomEvent(OPEN_NAV_SEARCH_EVENT));
}
