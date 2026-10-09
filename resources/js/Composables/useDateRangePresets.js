import dayjs from "dayjs";

/**
 * Quick picks for <a-range-picker :ranges="..."> ("Today", "This week", "This month", ...).
 * Built on each call so "today" is never stale on a page left open overnight.
 */
export function dateRangePresets() {
    const today = dayjs();

    return {
        Today: [today.startOf("day"), today.endOf("day")],
        Yesterday: [
            today.subtract(1, "day").startOf("day"),
            today.subtract(1, "day").endOf("day"),
        ],
        "This week": [today.startOf("week"), today.endOf("day")],
        "Last 7 days": [today.subtract(6, "day").startOf("day"), today.endOf("day")],
        "This month": [today.startOf("month"), today.endOf("day")],
        "Last month": [
            today.subtract(1, "month").startOf("month"),
            today.subtract(1, "month").endOf("month"),
        ],
        "This year": [today.startOf("year"), today.endOf("day")],
    };
}
