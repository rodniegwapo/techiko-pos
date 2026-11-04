// What each scene shows, timed to the words that name it (see anchors.mjs). build.mjs turns this
// into compositions/; clips.mjs fits the footage to the `footage` windows.
//
//   footage: [{ clip, from, to, maxRate }]   clips/<clip>*.webm shown from phrase `from` (or the
//                                            scene's start) to phrase `to` (or its end)
//   points:  [{ at, text, sub }]             cards in the side panel, each when its phrase is said
//   diagram: a scene's own picture, built in build.mjs, its parts revealed at `steps`

export const ROLES = {
    Cashier: { color: "#0f766e", soft: "#ccfbf1" },
    Supervisor: { color: "#6d28d9", soft: "#ede9fe" },
    Manager: { color: "#1d4ed8", soft: "#dbeafe" },
    Owner: { color: "#b45309", soft: "#fef3c7" },
};

export const SCENES = [
    {
        id: "scene-1",
        title: "Where is my money right now?",
        diagram: "intro",
        steps: {
            question: "Where is my money",
            pays: "from the moment",
            drawer: "to the drawer",
            bank: "to the bank",
            owner: "and to the owner",
            tabs: "It lives under",
            tab1: "Today's drawer",
            tab2: "Where money is",
            tab3: "Cash in and out",
            tab4: "Shifts and counts",
            tab5: "And Cash on hand",
        },
    },
    {
        id: "scene-2",
        title: "Three places money lives",
        diagram: "places",
        steps: {
            drawer: "The cash drawer",
            trays: "Each cashier has",
            channels: "Payment channels are",
            onhand: "And Cash on hand",
            expected: "Expected is what",
            counted: "Counted is what",
            short: "you are short",
            over: "If it is more",
        },
    },
    {
        id: "scene-3",
        title: "Four roles, four views",
        diagram: "roles",
        steps: { Cashier: "A cashier handles", Supervisor: "A supervisor watches", Manager: "A manager does", Owner: "The owner, or" },
    },
    {
        id: "scene-4",
        title: "Start, sell, end and count",
        role: "Cashier",
        footage: [{ clip: "scene-4" }],
        points: [
            { at: "choose Start my shift", text: "Start my shift", sub: "Enter your opening cash" },
            { at: "Every cash sale", text: "Cash sales add to Expected", sub: "GCash and cards go to their channel" },
            { at: "choose End my shift", text: "End my shift", sub: "Count only your own tray" },
            { at: "You don't need", text: "No PIN needed" },
            { at: "If it is short", text: "Short or over → Needs review", sub: "A supervisor checks it" },
        ],
    },
    {
        id: "scene-5",
        title: "How a shift moves along",
        diagram: "lifecycle",
        steps: { open: "It starts Open", ended: "it becomes Ended", review: "it goes to Needs", approved: "approves it", recount: "or sends it back" },
        footage: [{ clip: "scene-5", from: "When it is sent", maxRate: 2.2 }],
        points: [
            { at: "When it is sent", text: "Sent back → Open again", sub: "The cashier counts again" },
            { at: "If a cashier forgets", text: "Forgotten shifts", sub: "Someone can end it for them" },
            { at: "And if a shift", text: "Ended on another device", sub: "Sales tells you, so no one sells on a closed shift" },
        ],
    },
    {
        id: "scene-6",
        title: "The store's drawer and the counts",
        role: "Supervisor",
        footage: [{ clip: "scene-6" }],
        points: [
            { at: "Cash in drawers is", text: "Cash in drawers", sub: "Every tray added up" },
            { at: "is money that went", text: "Card, e-wallet & bank", sub: "Went past the drawer" },
            { at: "A spot check counts", text: "Spot check", sub: "Counts without ending anything" },
            { at: "Look at Expected", text: "Expected · Counted · Difference" },
            { at: "Approve it with", text: "Approve, or send back", sub: "Supervisors see only their team" },
        ],
    },
    {
        id: "scene-7",
        title: "End the store's day",
        role: "Manager",
        footage: [{ clip: "scene-7" }],
        points: [
            { at: "When every cashier", text: "After every shift has ended" },
            { at: "enter the final count", text: "Final count of the drawer" },
            { at: "Cash out sends", text: "Cash out", sub: "→ Cash on hand. Tomorrow starts empty" },
            { at: "Keep as opening", text: "Keep as opening", sub: "Stays in the drawer for tomorrow" },
            { at: "a manager or admin can reopen", text: "Reopen the day", sub: "With a reason" },
        ],
    },
    {
        id: "scene-8",
        title: "Cash in & out at a store",
        role: "Manager",
        footage: [{ clip: "scene-8" }],
        points: [
            { at: "Cash in, for example", text: "Cash in", sub: "Added float: change or small bills" },
            { at: "Cash out, for example", text: "Cash out", sub: "Owner took cash" },
            { at: "Move, to send", text: "Move", sub: "Drawer ⇄ the store's own channels" },
            { at: "the app shows the balance", text: "Before → after", sub: "See what changes before you save" },
            { at: "Other pages add", text: "Expenses, suppliers, loans", sub: "Show up here too" },
        ],
    },
    {
        id: "scene-9",
        title: "Where money is",
        role: "Owner",
        footage: [{ clip: "scene-9" }],
        points: [
            { at: "For each store", text: "Per store", sub: "Drawer, e-wallets, banks & card terminals" },
            { at: "The owner sees every", text: "Every store side by side", sub: "Plus Cash on hand" },
            { at: "Where is my money", text: "Where is my money right now?" },
        ],
    },
    {
        id: "scene-10",
        title: "Allow money between stores",
        role: "Owner",
        footage: [{ clip: "scene-10" }],
        points: [
            { at: "Go to Inventory", text: "Inventory → Locations" },
            { at: "turn on Can transfer", text: "Can transfer money", sub: "Turn it on for both stores" },
            { at: "One more rule", text: "Not into an ended day", sub: "Or out of one" },
        ],
    },
    {
        id: "scene-11",
        title: "Three ways to move money to another store",
        role: "Owner",
        diagram: "ways",
        steps: { one: "One. Transfer", two: "Two. Owner", three: "Three. Through" },
        footage: [
            { clip: "scene-11a", from: "Pick the other store", to: "Two. Owner", maxRate: 3 },
            { clip: "scene-11b", from: "The owner carries", to: "Three. Through", maxRate: 3 },
            { clip: "scene-11c", from: "The cash comes in", maxRate: 3 },
        ],
    },
    {
        id: "scene-12",
        title: "Cash on hand: the owner's safe",
        role: "Owner",
        footage: [{ clip: "scene-12" }],
        points: [
            { at: "Money gets in", text: "In: Cash out at end of day", sub: "Or collect an approved shift" },
            { at: "Money goes out", text: "Out: Move to a store", sub: "Back to a drawer or channel" },
            { at: "Correction fixes", text: "Correction", sub: "Fixes a mistake" },
            { at: "And Owner used it", text: "Owner used it", sub: "Spent outside the business" },
            { at: "Only Owner used", text: "Only “Owner used it” leaves the business" },
        ],
    },
    {
        id: "scene-13",
        title: "Cash flow: money in & out of the business",
        role: "Owner",
        footage: [{ clip: "scene-13" }],
        points: [
            { at: "Money in is", text: "Money in", sub: "Sales, credit payments, loans, owner's money" },
            { at: "Money out is", text: "Money out", sub: "Expenses, suppliers, loans, equipment, draws" },
            { at: "Moving money between", text: "Moves between your own places", sub: "Not on the list: it never left" },
            { at: "You can make", text: "Profit ≠ cash", sub: "Tied up in stock or customer credit" },
        ],
    },
    {
        id: "scene-14",
        title: "Which one, and recap",
        diagram: "recap",
        steps: {
            now: "If the other store",
            carry: "If you are carrying",
            later: "If you collect first",
            recap: "To recap",
            Cashier: "Cashiers start",
            Supervisor: "Supervisors check",
            Manager: "Managers end",
            Owner: "And the owner sees",
            end: "That's Cash and wallet",
        },
    },
];
