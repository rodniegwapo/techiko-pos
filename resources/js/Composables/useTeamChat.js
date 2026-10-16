/**
 * Opens the "Chat with the team" drawer (InquiryChatWidget, in AuthenticatedLayout) from anywhere,
 * e.g. a "Contact us" button. An optional message is put in the box, ready to edit and send.
 */
export const OPEN_TEAM_CHAT_EVENT = "techiko:open-team-chat";

export function openTeamChat(message = "") {
    window.dispatchEvent(new CustomEvent(OPEN_TEAM_CHAT_EVENT, { detail: { message } }));
}
