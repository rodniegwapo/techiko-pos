/**
 * Opens the AI assistant (InquiryChatWidget, in AuthenticatedLayout) from anywhere, e.g. a page's
 * "?" help, and asks it a question.
 */
export const OPEN_ASSISTANT_EVENT = "techiko:open-assistant";

export function openAssistant(question = "") {
    window.dispatchEvent(new CustomEvent(OPEN_ASSISTANT_EVENT, { detail: { question } }));
}
