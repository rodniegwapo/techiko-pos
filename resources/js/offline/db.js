import Dexie from "dexie";

/**
 * IndexedDB (Dexie) outbox for offline-completed sales.
 * Status: draft | pending_review | accepted | syncing | synced | failed | rejected
 */
class OfflineSalesDB extends Dexie {
    constructor() {
        super("techiko-pos-offline-sales");
        this.version(1).stores({
            pending_sales:
                "client_mutation_id, status, domain_slug, location_id, created_at",
        });
        this.version(2).stores({
            pending_sales:
                "client_mutation_id, status, domain_slug, location_id, created_at",
            offline_cart: "key, domain_slug, user_id",
        });
    }
}

export const offlineDb = new OfflineSalesDB();

/**
 * @param {object} row
 * @param {string} row.client_mutation_id
 * @param {'draft'|'pending_review'|'accepted'|'syncing'|'synced'|'failed'|'rejected'} row.status
 * @param {string} row.domain_slug
 * @param {number} row.location_id
 * @param {object} row.payload
 * @param {number|null} [row.server_sale_id]
 * @param {string|null} [row.error_message]
 */
export async function addPendingSale(row) {
    const now = new Date().toISOString();
    const record = {
        client_mutation_id: row.client_mutation_id,
        status: row.status,
        domain_slug: row.domain_slug,
        location_id: row.location_id,
        payload: row.payload,
        server_sale_id: row.server_sale_id ?? null,
        error_message: row.error_message ?? null,
        created_at: row.created_at ?? now,
        updated_at: now,
    };
    await offlineDb.pending_sales.put(record);
    return record;
}

export async function listPendingForDomain(domainSlug) {
    return offlineDb.pending_sales
        .where("domain_slug")
        .equals(domainSlug)
        .sortBy("created_at");
}

/** Rows still needing owner action or upload (hide synced + rejected). */
export async function listNeedsAttentionForDomain(domainSlug) {
    const rows = await offlineDb.pending_sales
        .where("domain_slug")
        .equals(domainSlug)
        .toArray();
    return rows
        .filter((r) => r.status !== "synced" && r.status !== "rejected")
        .sort((a, b) =>
            String(a.created_at || "").localeCompare(
                String(b.created_at || ""),
            ),
        );
}

export async function getPendingByMutationId(clientMutationId) {
    return offlineDb.pending_sales.get(clientMutationId);
}

export async function markAccepted(clientMutationId) {
    await offlineDb.pending_sales.update(clientMutationId, {
        status: "accepted",
        updated_at: new Date().toISOString(),
        error_message: null,
    });
}

export async function markSyncing(clientMutationId) {
    await offlineDb.pending_sales.update(clientMutationId, {
        status: "syncing",
        updated_at: new Date().toISOString(),
    });
}

export async function markSynced(clientMutationId, serverSaleId) {
    await offlineDb.pending_sales.update(clientMutationId, {
        status: "synced",
        server_sale_id: serverSaleId,
        error_message: null,
        updated_at: new Date().toISOString(),
    });
}

export async function markFailed(clientMutationId, errorMessage) {
    await offlineDb.pending_sales.update(clientMutationId, {
        status: "failed",
        error_message: errorMessage,
        updated_at: new Date().toISOString(),
    });
}

export async function markRejected(clientMutationId) {
    await offlineDb.pending_sales.update(clientMutationId, {
        status: "rejected",
        error_message: null,
        updated_at: new Date().toISOString(),
    });
}

/** Reset a failed row back to accepted for another sync attempt. */
export async function markRetry(clientMutationId) {
    await offlineDb.pending_sales.update(clientMutationId, {
        status: "accepted",
        error_message: null,
        updated_at: new Date().toISOString(),
    });
}

export async function removePending(clientMutationId) {
    await offlineDb.pending_sales.delete(clientMutationId);
}

/** @param {string} domainSlug @param {number|string} userId */
export function offlineCartKey(domainSlug, userId) {
    return `${domainSlug}:${userId}`;
}

/**
 * @param {string} domainSlug
 * @param {number|string} userId
 * @returns {Promise<object|undefined>}
 */
export async function getOfflineCart(domainSlug, userId) {
    if (!domainSlug || userId == null) return undefined;
    return offlineDb.offline_cart.get(offlineCartKey(domainSlug, userId));
}

/**
 * @param {object} payload
 * @param {string} payload.domain_slug
 * @param {number|string} payload.user_id
 * @param {Array<{product_id:number,quantity:number,unit_price:number,name?:string}>} payload.line_items
 * @param {string} [payload.payment_method]
 * @param {number|null} [payload.location_id]
 * @param {number|null} [payload.customer_id]
 * @param {object|null} [payload.customer_snapshot]
 * @param {string|null} [payload.notes]
 * @param {Array<{id:number,barcode?:string,code?:string,name:string,price:number}>} [payload.product_lookup]
 */
export async function putOfflineCart(payload) {
    const now = new Date().toISOString();
    const record = {
        key: offlineCartKey(payload.domain_slug, payload.user_id),
        domain_slug: payload.domain_slug,
        user_id: payload.user_id,
        line_items: payload.line_items ?? [],
        payment_method: payload.payment_method ?? "cash",
        location_id: payload.location_id ?? null,
        customer_id: payload.customer_id ?? null,
        customer_snapshot: payload.customer_snapshot ?? null,
        notes: payload.notes ?? null,
        product_lookup: payload.product_lookup ?? [],
        updated_at: now,
    };
    await offlineDb.offline_cart.put(record);
    return record;
}

/** @param {string} domainSlug @param {number|string} userId */
export async function clearOfflineCart(domainSlug, userId) {
    if (!domainSlug || userId == null) return;
    await offlineDb.offline_cart.delete(offlineCartKey(domainSlug, userId));
}
