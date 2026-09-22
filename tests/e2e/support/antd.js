import { expect } from "@playwright/test";

/**
 * Picks an option in the `selectIndex`-th antd select inside `scope` whose label contains `optionText`.
 */
export async function pickSelectOption(page, scope, selectIndex, optionText) {
    const select = scope.locator(".ant-select").nth(selectIndex);
    await select.click();
    const dropdown = page.locator(".ant-select-dropdown:not(.ant-select-dropdown-hidden)").last();
    await expect(dropdown).toBeVisible();
    // The virtual list re-renders while the dropdown animates open; clicking too early hits a detached node.
    await page.waitForTimeout(300);

    // Options are virtualized, so far-down ones aren't in the DOM until scrolled to.
    // (Typing doesn't always help: some selects filter on option values, not labels.)
    const option = dropdown.locator(`.ant-select-item-option[title*="${optionText.replaceAll('"', '\\"')}"]`).first();
    const holder = dropdown.locator(".rc-virtual-list-holder");
    for (let i = 0; i < 30 && !(await option.isVisible()); i++) {
        await holder.evaluate((el) => (el.scrollTop += 120));
        await page.waitForTimeout(80);
    }
    await option.click();
}

/** antd notification by title. */
export const notice = (page, title) => page.locator(".ant-notification-notice").filter({ hasText: title });

/** antd message toast (antMessage.success/warning/error). */
export const toast = (page, text) => page.locator(".ant-message-notice").filter({ hasText: text });
