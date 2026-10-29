import { request } from "@playwright/test";
import { responseProps } from "./inertia.js";

/**
 * What config/features.php has opened on the server under test (its .env): dine-in tables
 * (FEATURE_DINE_IN) and the business types that can be picked (FEATURE_BUSINESS_TYPES); the rest
 * show "Coming soon". Read once from the sign-in page's shared props. Specs for a part that is
 * coming soon skip themselves, so they come back on their own when the switch opens.
 */
const BASE_URL = process.env.E2E_BASE_URL || "http://techiko-pos.test";
let opened;

export async function openFeatures() {
    if (!opened) {
        const api = await request.newContext({ baseURL: BASE_URL, storageState: { cookies: [], origins: [] } });
        const props = await responseProps(await api.get("/login"));
        await api.dispose();
        opened = {
            dineIn: !!props.features?.dine_in,
            businessTypes: props.availableBusinessTypes ?? ["retail"],
        };
    }
    return opened;
}

export async function dineInOpen() {
    return (await openFeatures()).dineIn;
}

export async function businessTypeOpen(type) {
    return (await openFeatures()).businessTypes.includes(type);
}
