import { test, expect, apiJson } from "../support/fixtures.js";
import { fixtureIds } from "../support/sales.js";
import {
    dateWindow,
    expectSaved,
    futureYmd,
    ledgerUrl,
    postCounted,
    postEndShift,
    postLedger,
    postOpening,
    postReopen,
    walletFixture,
    walletState,
    walletUrl,
} from "../support/wallet.js";

/** Wallet endpoints over HTTP as this worker's store manager. Each row asserts 422 and the field. */
test.describe("Wallet API validation", () => {
    let api;
    let day;
    let store;

    test.beforeEach(async ({ serverAs }, testInfo) => {
        api = await serverAs("wallet-manager");
        ({ day } = dateWindow());
        store = walletFixture(testInfo);
    });

    const expect422 = (res, field) => {
        expect(res.status, `expected 422, got ${res.status}: ${JSON.stringify(res.body)?.slice(0, 200)}`).toBe(422);
        expect(Object.keys(res.body.errors ?? {}), `error reported on "${field}"`).toContain(field);
    };

    test.describe("ledger entry", () => {
        const valid = () => ({ direction: "in", amount: 10, kind: "adjustment", movement_date: day(0) });

        const cases = [
            { name: "a missing direction", patch: { direction: undefined }, field: "direction" },
            { name: "an unknown direction", patch: { direction: "sideways" }, field: "direction" },
            { name: "a missing amount", patch: { amount: undefined }, field: "amount" },
            { name: "a zero amount", patch: { amount: 0 }, field: "amount" },
            { name: "a negative amount", patch: { amount: -5 }, field: "amount" },
            { name: "an amount over the maximum", patch: { amount: 100_000_000 }, field: "amount" },
            { name: "an unknown kind", patch: { kind: "lottery" }, field: "kind" },
            { name: "a missing movement date", patch: { movement_date: undefined }, field: "movement_date" },
            { name: "a future movement date", patch: { movement_date: futureYmd() }, field: "movement_date" },
            { name: "notes over 2000 characters", patch: { notes: "x".repeat(2001) }, field: "notes" },
            { name: "an owner withdrawal without a source", patch: { kind: "owner_draw", direction: "out" }, field: "draw_source" },
            { name: "an unknown withdrawal source", patch: { kind: "owner_draw", direction: "out", draw_source: "piggy_bank" }, field: "draw_source" },
            {
                name: "a card-rail withdrawal without a card type",
                patch: { kind: "owner_draw", direction: "out", draw_source: "card_type" },
                field: "payment_card_type_id",
            },
            { name: "a nonexistent card type", patch: { payment_card_type_id: 999999 }, field: "payment_card_type_id" },
        ];

        for (const { name, patch, field } of cases) {
            test(`rejects ${name}`, async () => {
                const body = { ...valid(), ...patch };
                for (const key of Object.keys(body)) if (body[key] === undefined) delete body[key];

                expect422(await postLedger(api, body), field);
            });
        }

        test("rejects a card type on a cash-register withdrawal", async () => {
            expect422(
                await postLedger(api, { ...valid(), kind: "owner_draw", direction: "out", draw_source: "cash_register", payment_card_type_id: store.cardTypeId }),
                "payment_card_type_id",
            );
        });

        test("rejects another store's card type", async () => {
            const res = await postLedger(api, { ...valid(), payment_card_type_id: fixtureIds().wallet.mainCardTypeId });

            expect(res.status, "card type from JB-MAIN on this store").toBe(403);
        });

        test("accepts a valid entry and it appears in the ledger", async () => {
            expectSaved(await postLedger(api, { ...valid(), amount: 12.34, notes: "API valid" }), "valid entry");

            const state = await walletState(api, day(0), { date_from: day(0), date_to: day(0) });
            expect(state.ledger.movements.data.map((m) => m.notes)).toContain("API valid");
            expect(state.ledger.ledgerBalance).toBe(12.34);
        });

        test("an entry posted by a manager always lands in their own store", async ({}, testInfo) => {
            const other = Object.values(fixtureIds().wallet.workers).find((w) => w.locationId !== store.locationId);

            expectSaved(await postLedger(api, { ...valid(), notes: "Wrong-store attempt", location_id: other.locationId }), "entry");

            const own = await walletState(api, day(0), { date_from: day(0), date_to: day(0) });
            expect(own.activeLocation.id).toBe(walletFixture(testInfo).locationId);
            expect(own.ledger.movements.data.map((m) => m.notes)).toContain("Wrong-store attempt");
        });
    });

    test.describe("opening and counted cash", () => {
        const cases = [
            { name: "opening without a date", url: "/opening-cash", body: { opening_cash: 10 }, field: "business_date" },
            { name: "opening for a future date", url: "/opening-cash", body: () => ({ business_date: futureYmd(), opening_cash: 10 }), field: "business_date" },
            { name: "a missing opening amount", url: "/opening-cash", body: () => ({ business_date: day(0) }), field: "opening_cash" },
            { name: "a negative opening amount", url: "/opening-cash", body: () => ({ business_date: day(0), opening_cash: -1 }), field: "opening_cash" },
            { name: "a non-numeric opening amount", url: "/opening-cash", body: () => ({ business_date: day(0), opening_cash: "lots" }), field: "opening_cash" },
            { name: "a reason over 2000 characters", url: "/opening-cash", body: () => ({ business_date: day(0), opening_cash: 1, reason: "x".repeat(2001) }), field: "reason" },
            { name: "a count for a future date", url: "/counted-cash", body: () => ({ business_date: futureYmd(), counted_cash: 10 }), field: "business_date" },
            { name: "a missing counted amount", url: "/counted-cash", body: () => ({ business_date: day(0) }), field: "counted_cash" },
            { name: "a negative counted amount", url: "/counted-cash", body: () => ({ business_date: day(0), counted_cash: -1 }), field: "counted_cash" },
        ];

        for (const { name, url, body, field } of cases) {
            test(`rejects ${name}`, async () => {
                expect422(await apiJson(api, "POST", ledgerUrl(url), typeof body === "function" ? body() : body), field);
            });
        }

        test("counting zero cash is accepted", async () => {
            expectSaved(await postCounted(api, { business_date: day(0), counted_cash: 0 }), "zero count");

            expect((await walletState(api, day(0))).cashControl.counted_cash).toBe(0);
        });
    });

    test.describe("end and reopen shift", () => {
        test("rejects an unknown end-shift action", async () => {
            await postCounted(api, { business_date: day(0), counted_cash: 10 });

            expect422(await postEndShift(api, { business_date: day(0), end_shift_action: "vanish" }), "end_shift_action");
        });

        test("rejects ending a shift with no count", async () => {
            expect422(await postEndShift(api, { business_date: day(0), end_shift_action: "save_as_opening_cash" }), "counted_cash");
        });

        test("rejects cashing out a zero count", async () => {
            expectSaved(await postCounted(api, { business_date: day(0), counted_cash: 0 }), "zero count");

            expect422(await postEndShift(api, { business_date: day(0), end_shift_action: "cashout_now" }), "counted_cash");
        });

        test("a closed date rejects opening, counted and ledger changes", async () => {
            expectSaved(await postCounted(api, { business_date: day(0), counted_cash: 20 }), "count");
            expectSaved(await postEndShift(api, { business_date: day(0), end_shift_action: "save_as_opening_cash" }), "close");

            expect422(await postOpening(api, { business_date: day(0), opening_cash: 5 }), "business_date");
            expect422(await postCounted(api, { business_date: day(0), counted_cash: 5 }), "business_date");
            expect422(await postLedger(api, { direction: "in", amount: 5, kind: "adjustment", movement_date: day(0) }), "business_date");
        });

        test("other dates stay editable while one date is closed", async () => {
            expectSaved(await postCounted(api, { business_date: day(0), counted_cash: 20 }), "count");
            expectSaved(await postEndShift(api, { business_date: day(0), end_shift_action: "save_as_opening_cash" }), "close");

            expectSaved(await postOpening(api, { business_date: day(1), opening_cash: 5 }), "opening next day");
        });

        test("reopening a date that was never closed is harmless", async () => {
            const res = await postReopen(api, { business_date: day(0) });

            expect(res.status, "no reconciliation yet").toBe(404);
        });
    });

    test.describe("page filters", () => {
        const cases = [
            { name: "date_to before date_from", query: () => ({ date_from: day(5), date_to: day(1) }), field: "date_to" },
            { name: "an unknown kind", query: () => ({ kind: "lottery" }), field: "kind" },
            { name: "an unknown rail", query: () => ({ rail: "vault" }), field: "rail" },
            { name: "per_page over 100", query: () => ({ per_page: 101 }), field: "per_page" },
            { name: "a nonexistent card type", query: () => ({ payment_card_type_id: 999999 }), field: "payment_card_type_id" },
        ];

        for (const { name, query, field } of cases) {
            test(`rejects ${name}`, async () => {
                const params = new URLSearchParams({ business_date: day(0), ...query() });

                expect422(await apiJson(api, "GET", `${walletUrl}?${params}`), field);
            });
        }

        test("filtering by another store's card type is denied", async () => {
            const params = new URLSearchParams({ business_date: day(0), payment_card_type_id: fixtureIds().wallet.mainCardTypeId });

            const res = await apiJson(api, "GET", `${walletUrl}?${params}`);

            expect(res.status).toBe(403);
        });
    });
});

test.describe("Wallet access across stores and organizations", () => {
    test("an admin can work in a chosen store of their organization", async ({ serverAs }, testInfo) => {
        const admin = await serverAs("admin");
        const { day } = dateWindow();
        const store = walletFixture(testInfo);

        expectSaved(await postOpening(admin, { business_date: day(0), opening_cash: 77, location_id: store.locationId }), "admin opening");

        const manager = await serverAs("wallet-manager");
        expect((await walletState(manager, day(0))).cashControl.opening_cash).toBe(77);
    });

    test("an admin can't target another organization's store", async ({ serverAs }) => {
        const admin = await serverAs("admin");
        const { day } = dateWindow();

        const res = await postOpening(admin, { business_date: day(0), opening_cash: 5, location_id: fixtureIds().wallet.otherOrgLocationId });

        expect(res.status, `got ${res.status}`).toBeGreaterThanOrEqual(400);
        expect(res.status).toBeLessThan(500);
    });
});
