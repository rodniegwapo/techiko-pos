import axios from "axios";
import { ref } from "vue";

/**
 * @param {object} opts
 * @param {import('vue').ComputedRef<boolean> | import('vue').Ref<boolean>} opts.enabled
 * @param {() => string|null|undefined} opts.getDomainSlug
 */
export function useSharedCatalogLookup({ enabled, getDomainSlug }) {
    const lookupLoading = ref(false);
    const catalogFound = ref(false);
    const catalogPayload = ref(null);

    async function lookup(barcodeRaw, assignToForm) {
        const isOn =
            typeof enabled === "object" && "value" in enabled ? enabled.value : !!enabled;

        if (!isOn) {
            catalogFound.value = false;
            catalogPayload.value = null;
            return;
        }
        const slug = getDomainSlug();
        const barcode = String(barcodeRaw || "").trim();
        if (!slug || !barcode) {
            catalogFound.value = false;
            catalogPayload.value = null;
            return;
        }
        lookupLoading.value = true;
        try {
            const url = window.route("domains.shared-catalog.lookup", {
                domain: slug,
            });
            const { data } = await axios.get(url, { params: { barcode } });
            catalogFound.value = !!data?.found;
            catalogPayload.value = data?.data || null;
            if (catalogFound.value && data?.data && typeof assignToForm === "function") {
                assignToForm(data.data);
            }
        } catch (_) {
            catalogFound.value = false;
            catalogPayload.value = null;
        } finally {
            lookupLoading.value = false;
        }
    }

    return { lookupLoading, catalogFound, catalogPayload, lookup };
}
