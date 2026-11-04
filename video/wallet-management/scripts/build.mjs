// Writes the HyperFrames composition from the voice's timings: index.html (scene hosts, the
// narration, the captions host), compositions/scene-N.html (one per script.md scene) and
// compositions/captions.html. Every reveal is timed to the word that names it (anchors.mjs).
// Run after tts.mjs, captions.mjs and clips.mjs:
//   node scripts/build.mjs
import { copyFileSync, existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { ROOT, AUDIO_DIR } from "./groq.mjs";
import { at, slot, timeline, reportMissed } from "./anchors.mjs";
import { SCENES, ROLES } from "./scenes.config.mjs";

const W = 1920;
const H = 1080;
const END_HOLD = 2.5; // the end card stays this long after the last word
const LEAD = 0.15; // a reveal lands this long before its word
const COMP = join(ROOT, "compositions");
mkdirSync(COMP, { recursive: true });
mkdirSync(join(ROOT, "assets", "audio"), { recursive: true });
copyFileSync(join(AUDIO_DIR, "narration.wav"), join(ROOT, "assets", "audio", "narration.wav"));

const windows = existsSync(join(ROOT, "assets", "footage", "windows.json"))
    ? JSON.parse(readFileSync(join(ROOT, "assets", "footage", "windows.json"), "utf8"))
    : {};
const esc = (s) => String(s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
const t = (v) => Math.max(0, v - LEAD).toFixed(2);
const total = Number((timeline.total + END_HOLD).toFixed(2));

// Layout: the browser frame on the left, the key points on the right, captions along the bottom.
const FRAME = { x: 72, y: 150, w: 1240, bar: 36 };
FRAME.h = Math.round((FRAME.w * 9) / 16);
const PANEL = { x: 1352, y: 150, w: 496 };

const ICONS = {
    peso: `<svg viewBox="0 0 48 48"><circle cx="24" cy="24" r="20" fill="none" stroke="currentColor" stroke-width="3.5"/><path d="M18 34V14h7a6 6 0 0 1 0 12h-7M14 19h20M14 23h20" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round"/></svg>`,
    drawer: `<svg viewBox="0 0 48 48"><rect x="6" y="12" width="36" height="26" rx="4" fill="none" stroke="currentColor" stroke-width="3.5"/><path d="M6 22h36M20 30h8" stroke="currentColor" stroke-width="3.5" stroke-linecap="round"/></svg>`,
    bank: `<svg viewBox="0 0 48 48"><path d="M6 18 24 8l18 10M10 20v14M19 20v14M29 20v14M38 20v14M6 38h36" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/></svg>`,
    phone: `<svg viewBox="0 0 48 48"><rect x="14" y="5" width="20" height="38" rx="4" fill="none" stroke="currentColor" stroke-width="3.5"/><path d="M21 36h6" stroke="currentColor" stroke-width="3.5" stroke-linecap="round"/></svg>`,
    owner: `<svg viewBox="0 0 48 48"><circle cx="24" cy="16" r="8" fill="none" stroke="currentColor" stroke-width="3.5"/><path d="M8 42c2-9 8-13 16-13s14 4 16 13" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round"/></svg>`,
    safe: `<svg viewBox="0 0 48 48"><rect x="6" y="8" width="36" height="32" rx="4" fill="none" stroke="currentColor" stroke-width="3.5"/><circle cx="24" cy="24" r="7" fill="none" stroke="currentColor" stroke-width="3.5"/><path d="M24 17v3M24 28v3M10 40v4M38 40v4" stroke="currentColor" stroke-width="3.5" stroke-linecap="round"/></svg>`,
    store: `<svg viewBox="0 0 48 48"><path d="M8 20 11 8h26l3 12M8 20h32M10 20v20h28V20M20 40V29h8v11" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linejoin="round"/></svg>`,
};
const icon = (name, cls = "icon") => `<span class="${cls}">${ICONS[name]}</span>`;

const BASE_CSS = `
      #root { position: absolute; inset: 0; font-family: "Inter", sans-serif; color: #10231a; overflow: hidden; }
      .bg { position: absolute; inset: 0; background: linear-gradient(180deg, #f6f8f5 0%, #edf2ec 100%); }
      .hdr { position: absolute; left: 72px; top: 44px; right: 72px; height: 72px; display: flex; align-items: center; gap: 20px; }
      .hdr .num { font-weight: 900; font-size: 26px; color: #ffffff; background: #1f7a3f; border-radius: 12px; padding: 10px 16px; letter-spacing: 0.02em; }
      .hdr .ttl { font-weight: 700; font-size: 44px; letter-spacing: -0.02em; white-space: nowrap; }
      .hdr .role { margin-left: auto; font-weight: 700; font-size: 28px; letter-spacing: 0.08em; text-transform: uppercase; border-radius: 999px; padding: 12px 26px; white-space: nowrap; }
      .chrome { position: absolute; left: ${FRAME.x}px; top: ${FRAME.y}px; width: ${FRAME.w}px; height: ${FRAME.bar + FRAME.h}px; background: #ffffff; border-radius: 16px; border: 1px solid #d3dbd2; box-shadow: 0 24px 60px rgba(16, 35, 26, 0.14); }
      .chrome .bar { height: ${FRAME.bar}px; display: flex; align-items: center; gap: 8px; padding: 0 16px; border-bottom: 1px solid #e3e8e2; }
      .chrome .dot { width: 11px; height: 11px; border-radius: 50%; background: #cfd8ce; }
      .chrome .url { margin: 0 auto; font-size: 16px; color: #5b6b62; background: #f1f4f0; border-radius: 8px; padding: 4px 18px; }
      .shot { position: absolute; left: ${FRAME.x}px; top: ${FRAME.y + FRAME.bar}px; width: ${FRAME.w}px; height: ${FRAME.h}px; object-fit: cover; border-radius: 0 0 16px 16px; }
      .panel { position: absolute; left: ${PANEL.x}px; top: ${PANEL.y}px; width: ${PANEL.w}px; height: ${FRAME.bar + FRAME.h}px; display: flex; flex-direction: column; gap: 14px; }
      .card { position: relative; width: 100%; background: #ffffff; border-radius: 16px; border: 1px solid #dbe3da; padding: 18px 22px 18px 26px; box-shadow: 0 10px 28px rgba(16, 35, 26, 0.08); }
      .card .bar2 { position: absolute; left: 0; top: 16px; bottom: 16px; width: 6px; border-radius: 0 6px 6px 0; }
      .card .t1 { font-weight: 700; font-size: 31px; line-height: 1.2; letter-spacing: -0.01em; }
      .card .t2 { font-size: 28px; line-height: 1.3; color: #4a5a51; margin-top: 6px; }
      .icon { display: inline-flex; width: 56px; height: 56px; }
      .icon svg { width: 100%; height: 100%; }
      .node { position: absolute; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; background: #ffffff; border: 2px solid #d3dbd2; border-radius: 22px; box-shadow: 0 12px 30px rgba(16, 35, 26, 0.08); text-align: center; }
      .node .lbl { font-weight: 700; font-size: 30px; letter-spacing: -0.01em; line-height: 1.15; }
      .node .sub { font-size: 26px; color: #4a5a51; line-height: 1.3; padding: 0 18px; }
      .arrow { position: absolute; height: 6px; background: #6b8574; border-radius: 3px; }
      .arrow::after { content: ""; position: absolute; right: -4px; top: -9px; border-left: 18px solid #6b8574; border-top: 12px solid transparent; border-bottom: 12px solid transparent; }
      .chip { display: inline-block; font-weight: 600; font-size: 24px; border-radius: 999px; padding: 8px 18px; background: #eef3ee; color: #1f3a2b; }`;

function header(scene, n) {
    const role = scene.role ? ROLES[scene.role] : null;
    return `
    <div id="${scene.id}-hdr" class="hdr">
      <span class="num" data-layout-ignore>${String(n).padStart(2, "0")}</span>
      <span class="ttl">${esc(scene.title)}</span>
      ${role ? `<span class="role" style="color: ${role.color}; background: ${role.soft}">${esc(scene.role === "Owner" ? "Owner / Admin" : scene.role)}</span>` : ""}
    </div>`;
}

/** The browser frame and its timed footage. `shown` false: the frame starts hidden (a diagram scene). */
function footage(scene, shown) {
    const vids = (scene.footage ?? [])
        .map((f, i) => {
            const w = windows[f.clip];
            if (!w || !existsSync(join(ROOT, "assets", "footage", `${f.clip}.mp4`))) return "";
            return `
    <video id="${scene.id}-v${i}" class="clip shot" data-start="${w.start}" data-duration="${w.duration}" data-track-index="${2 + i}" src="assets/footage/${f.clip}.mp4" muted playsinline></video>`;
        })
        .join("");
    if (!vids) return { html: "", tweens: "" };
    let tweens = "";
    if (!shown) {
        // Shown only while one of the scene's footage windows plays.
        tweens += `      tl.set("#${scene.id}-frame", { opacity: 0 }, 0);\n`;
        for (const f of scene.footage) {
            const w = windows[f.clip];
            if (!w) continue;
            tweens += `      tl.fromTo("#${scene.id}-frame", { opacity: 0 }, { opacity: 1, duration: 0.3 }, ${(w.start - 0.05).toFixed(2)});\n`;
            tweens += `      tl.to("#${scene.id}-frame", { opacity: 0, duration: 0.25 }, ${(w.start + w.duration - 0.1).toFixed(2)});\n`;
        }
    }
    return {
        html: `
    <div id="${scene.id}-frame" class="chrome"><div class="bar"><span class="dot"></span><span class="dot"></span><span class="dot"></span><span class="url" data-layout-ignore>techiko-pos · Cash &amp; wallet</span></div></div>${vids}`,
        tweens,
    };
}

/** Key-point cards down the right side, each revealed when it is said. */
function points(scene) {
    const color = scene.role ? ROLES[scene.role].color : "#1f7a3f";
    const list = scene.points ?? [];
    const html = list
        .map((p, i) => `
    <div id="${scene.id}-p${i}" class="card"><span class="bar2" style="background: ${color}"></span><div class="t1">${esc(p.text)}</div>${p.sub ? `<div class="t2">${esc(p.sub)}</div>` : ""}</div>`)
        .join("");
    const tweens = list
        .map((p, i) => `      tl.fromTo("#${scene.id}-p${i}", { opacity: 0, x: 40 }, { opacity: 1, x: 0, duration: 0.5, ease: "power3.out" }, ${t(at(scene.id, p.at))});\n`)
        .join("");
    return { html: list.length ? `
    <div class="panel">${html}
    </div>` : "", tweens };
}

const reveal = (sel, time, from = "y: 30") => `      tl.fromTo("${sel}", { opacity: 0, ${from} }, { opacity: 1, ${from.replace(/-?\d+/, "0")}, duration: 0.55, ease: "power3.out" }, ${t(time)});\n`;
const hide = (sel, time, d = 0.35) => `      tl.to("${sel}", { opacity: 0, duration: ${d} }, ${Math.max(0, time - d).toFixed(2)});\n`;

// The diagrams. Each returns { html, tweens } for its scene; `s(step)` is when that step is said.
const DIAGRAMS = {
    intro(scene, s) {
        const p = scene.id;
        const flow = [
            ["pays", "peso", "Customer pays"],
            ["drawer", "drawer", "Cash drawer"],
            ["bank", "bank", "Bank & GCash"],
            ["owner", "owner", "Owner"],
        ];
        const tabs = ["Today's drawer", "Where money is", "Cash in & out", "Shifts & counts", "Cash on hand"];
        let html = `
    <div id="${p}-kicker" style="position: absolute; left: 0; right: 0; top: 120px; text-align: center; font-weight: 700; font-size: 28px; letter-spacing: 0.18em; text-transform: uppercase; color: #1f7a3f">Techiko POS</div>
    <div id="${p}-title" style="position: absolute; left: 0; right: 0; top: 168px; text-align: center; font-weight: 900; font-size: 124px; letter-spacing: -0.04em">Cash &amp; wallet</div>
    <div id="${p}-q" style="position: absolute; left: 0; right: 0; top: 330px; text-align: center; font-weight: 600; font-size: 46px; color: #4a5a51">Where is my money right now?</div>`;
        let tweens = reveal(`#${p}-q`, s("question"));
        flow.forEach(([step, ic, label], i) => {
            const x = 150 + i * 430;
            html += `
    <div id="${p}-f${i}" class="node" style="left: ${x}px; top: 450px; width: 330px; height: 200px; color: #1f7a3f">${icon(ic)}<div class="lbl" style="color: #10231a">${esc(label)}</div></div>`;
            if (i) html += `
    <div id="${p}-a${i}" class="arrow" style="left: ${x - 88}px; top: 547px; width: 70px"></div>`;
            tweens += reveal(`#${p}-f${i}`, s(step));
            if (i) tweens += reveal(`#${p}-a${i}`, s(step), "x: -20");
        });
        html += `
    <div id="${p}-tabs" style="position: absolute; left: 0; right: 0; top: 730px; text-align: center; font-weight: 700; font-size: 28px; letter-spacing: 0.12em; text-transform: uppercase; color: #4a5a51">Five tabs</div>`;
        tweens += reveal(`#${p}-tabs`, s("tabs"));
        tabs.forEach((label, i) => {
            html += `
    <div id="${p}-t${i}" style="position: absolute; left: ${110 + i * 344}px; top: 790px; width: 320px; height: 84px; display: flex; align-items: center; justify-content: center; border-radius: 16px; background: ${i === 0 ? "#1f7a3f" : "#ffffff"}; color: ${i === 0 ? "#ffffff" : "#10231a"}; border: 2px solid ${i === 0 ? "#1f7a3f" : "#d3dbd2"}; font-weight: 700; font-size: 28px">${esc(label)}</div>`;
            tweens += reveal(`#${p}-t${i}`, s(`tab${i + 1}`), "y: 20");
        });
        return { html, tweens };
    },

    places(scene, s) {
        const p = scene.id;
        const cards = [
            ["drawer", "drawer", "Cash drawer", "The physical cash in the store", "#1f7a3f"],
            ["channels", "phone", "Payment channels", "Where non-cash payments land", "#1d4ed8"],
            ["onhand", "safe", "Cash on hand", "The business's cash the owner holds, outside every store", "#b45309"],
        ];
        let html = "";
        let tweens = "";
        cards.forEach(([step, ic, label, sub, color], i) => {
            const x = 72 + i * 600;
            html += `
    <div id="${p}-c${i}" class="node" style="left: ${x}px; top: 150px; width: 576px; height: 420px; justify-content: flex-start; padding-top: 34px; color: ${color}">${icon(ic)}<div class="lbl" style="color: #10231a">${esc(label)}</div><div class="sub">${esc(sub)}</div></div>`;
            tweens += reveal(`#${p}-c${i}`, s(step));
        });
        // Inside the drawer: two trays make the store's drawer. Inside channels: the kinds.
        html += `
    <div id="${p}-trays" style="position: absolute; left: 96px; top: 452px; width: 528px; text-align: center"><span class="chip">Tray · Cashier 1</span> <span class="chip">Tray · Cashier 2</span><div style="margin-top: 10px; font-weight: 700; font-size: 28px; color: #1f7a3f">= the store's drawer</div></div>
    <div id="${p}-kinds" style="position: absolute; left: 696px; top: 470px; width: 528px; text-align: center"><span class="chip">Card terminal</span> <span class="chip">GCash</span> <span class="chip">Bank</span></div>`;
        tweens += reveal(`#${p}-trays`, s("trays"), "y: 16") + reveal(`#${p}-kinds`, s("channels") + 1.2, "y: 16");
        // Expected and counted, then short or over.
        const eq = [
            ["expected", "Expected", "₱700.00", "What should be there", "#10231a"],
            ["counted", "Counted", "₱690.00", "What you actually count", "#10231a"],
        ];
        eq.forEach(([step, label, amount, sub, color], i) => {
            const x = 72 + i * 470;
            html += `
    <div id="${p}-e${i}" class="node" style="left: ${x}px; top: 610px; width: 440px; height: 260px; color: ${color}"><div class="sub" style="font-weight: 700; font-size: 24px; letter-spacing: 0.08em; text-transform: uppercase">${label}</div><div style="font-weight: 900; font-size: 64px; letter-spacing: -0.02em">${amount}</div><div class="sub">${esc(sub)}</div></div>`;
            tweens += reveal(`#${p}-e${i}`, s(step));
        });
        html += `
    <div id="${p}-short" class="node" style="left: 1012px; top: 610px; width: 404px; height: 260px; border-color: #fca5a5; background: #fef2f2"><div class="sub" style="font-weight: 700; font-size: 24px; letter-spacing: 0.08em; text-transform: uppercase; color: #b91c1c">Counted less</div><div style="font-weight: 900; font-size: 60px; color: #b91c1c">Short ₱10</div></div>
    <div id="${p}-over" class="node" style="left: 1440px; top: 610px; width: 408px; height: 260px; border-color: #fcd34d; background: #fffbeb"><div class="sub" style="font-weight: 700; font-size: 24px; letter-spacing: 0.08em; text-transform: uppercase; color: #b45309">Counted more</div><div style="font-weight: 900; font-size: 60px; color: #b45309">Over</div></div>`;
        tweens += reveal(`#${p}-short`, s("short"), "y: 20") + reveal(`#${p}-over`, s("over"), "y: 20");
        return { html, tweens };
    },

    roles(scene, s) {
        const p = scene.id;
        const lines = {
            Cashier: ["Only their own shift", "Start, sell, count their tray"],
            Supervisor: ["The whole store drawer", "Checks the cashiers' counts"],
            Manager: ["Everything a supervisor does", "Ends the store's day", "Records cash in & out"],
            Owner: ["Every store", "Holds Cash on hand", "Moves money between stores"],
        };
        let html = "";
        let tweens = "";
        Object.entries(lines).forEach(([role, items], i) => {
            const r = ROLES[role];
            const x = 72 + i * 450;
            html += `
    <div id="${p}-r${i}" style="position: absolute; left: ${x}px; top: 170px; width: 426px; height: 700px; background: #ffffff; border-radius: 24px; border: 2px solid ${r.soft}; box-shadow: 0 16px 40px rgba(16, 35, 26, 0.08); overflow: hidden">
      <div style="background: ${r.color}; color: #ffffff; padding: 34px 32px; font-weight: 900; font-size: 44px; letter-spacing: -0.02em">${role === "Owner" ? "Owner / Admin" : role}</div>
      <div style="padding: 30px 32px; display: flex; flex-direction: column; gap: 22px">${items.map((it) => `<div style="font-size: 30px; font-weight: 600; line-height: 1.25; padding-left: 22px; border-left: 6px solid ${r.soft}">${esc(it)}</div>`).join("")}</div>
    </div>`;
            tweens += reveal(`#${p}-r${i}`, s(role), "y: 40");
        });
        return { html, tweens };
    },

    lifecycle(scene, s) {
        const p = scene.id;
        const states = [
            ["open", "Open", "#0f766e", "#ccfbf1"],
            ["ended", "Ended", "#1d4ed8", "#dbeafe"],
            ["review", "Needs review", "#b91c1c", "#fee2e2"],
            ["approved", "Approved", "#15803d", "#dcfce7"],
        ];
        let html = `
    <div id="${p}-dia" style="position: absolute; inset: 0">`;
        let tweens = "";
        states.forEach(([step, label, color, soft], i) => {
            const x = 100 + i * 450;
            html += `
      <div id="${p}-s${i}" class="node" style="left: ${x}px; top: 300px; width: 340px; height: 170px; border-color: ${color}; background: ${soft}"><div class="lbl" style="color: ${color}; font-size: 38px">${label}</div></div>`;
            if (i) html += `
      <div id="${p}-sa${i}" class="arrow" style="left: ${x - 96}px; top: 382px; width: 76px"></div>`;
            tweens += reveal(`#${p}-s${i}`, s(step));
            if (i) tweens += reveal(`#${p}-sa${i}`, s(step), "x: -20");
        });
        html += `
      <svg id="${p}-loop" style="position: absolute; left: 0; top: 0" width="${W}" height="${H}" viewBox="0 0 ${W} ${H}"><path d="M 1120 476 C 1120 700, 270 700, 270 476" fill="none" stroke="#b45309" stroke-width="6" stroke-dasharray="14 12" stroke-linecap="round"/><path d="M 252 500 L 270 470 L 288 500" fill="none" stroke="#b45309" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"/></svg>
      <div id="${p}-loopl" style="position: absolute; left: 0; right: ${W - 1390}px; top: 690px; text-align: center; font-weight: 700; font-size: 32px; color: #b45309">Sent back for a recount</div>
    </div>`;
        tweens += `      tl.fromTo("#${p}-loop", { opacity: 0 }, { opacity: 1, duration: 0.6 }, ${t(s("recount"))});\n` + reveal(`#${p}-loopl`, s("recount"), "y: 16");
        return { html, tweens };
    },

    ways(scene, s) {
        const p = scene.id;
        const rows = [
            ["one", "Transfer", "Straight to the other store's drawer or channel", [["store", "Store A"], ["store", "Store B"]]],
            ["two", "Owner took cash", "Into the other store's drawer · from the cash drawer only", [["drawer", "Store A drawer"], ["owner", "Owner carries it"], ["drawer", "Store B drawer"]]],
            ["three", "Through Cash on hand", "Collected first · later Move to a store", [["store", "Store A"], ["safe", "Cash on hand"], ["store", "Store B"]]],
        ];
        let html = `
    <div id="${p}-dia" style="position: absolute; inset: 0">`;
        let tweens = "";
        rows.forEach(([step, name, sub, nodes], r) => {
            const y = 160 + r * 250;
            html += `
      <div id="${p}-row${r}" style="position: absolute; left: 72px; top: ${y}px; width: 1776px; height: 220px; background: #ffffff; border-radius: 22px; border: 2px solid ${r === 2 ? "#fcd34d" : "#d3dbd2"}">
        <div style="position: absolute; left: 32px; top: 34px; width: 64px; height: 64px; border-radius: 50%; background: #1f7a3f; color: #ffffff; display: flex; align-items: center; justify-content: center; font-weight: 900; font-size: 34px">${r + 1}</div>
        <div style="position: absolute; left: 120px; top: 30px; width: 520px; font-weight: 800; font-size: 38px; letter-spacing: -0.02em">${esc(name)}</div>
        <div style="position: absolute; left: 120px; top: 84px; width: 520px; font-size: 24px; line-height: 1.35; color: #4a5a51">${esc(sub)}</div>`;
            const span = 1060;
            const nx = (k) => 680 + (nodes.length === 2 ? k * (span - 260) : k * ((span - 260) / 2));
            nodes.forEach(([ic, label], k) => {
                html += `
        <div class="node" style="left: ${nx(k)}px; top: 26px; width: 260px; height: 168px; color: ${ic === "safe" ? "#b45309" : "#1f7a3f"}">${icon(ic)}<div class="lbl" style="font-size: 25px; color: #10231a">${esc(label)}</div></div>`;
                if (k) html += `
        <div class="arrow" style="left: ${nx(k - 1) + 278}px; top: 107px; width: ${nx(k) - nx(k - 1) - 300}px"></div>`;
            });
            html += `
      </div>`;
            tweens += reveal(`#${p}-row${r}`, s(step), "x: -40");
        });
        html += `
    </div>`;
        // The diagram steps aside while each way's footage plays.
        for (const f of scene.footage ?? []) {
            const w = windows[f.clip];
            if (!w) continue;
            tweens += `      tl.to("#${p}-dia", { opacity: 0, duration: 0.3 }, ${(w.start - 0.3).toFixed(2)});\n`;
            if (w.start + w.duration < slot(scene.id).duration - 0.5) tweens += `      tl.to("#${p}-dia", { opacity: 1, duration: 0.3 }, ${(w.start + w.duration - 0.1).toFixed(2)});\n`;
        }
        return { html, tweens };
    },

    recap(scene, s) {
        const p = scene.id;
        const table = [
            ["now", "The other store needs it now", "Transfer"],
            ["carry", "You carry cash to another drawer", "Owner took cash"],
            ["later", "Collect first, decide later", "Cash on hand"],
        ];
        let html = `
    <div id="${p}-tbl" style="position: absolute; inset: 0">
      <div id="${p}-q" style="position: absolute; left: 72px; top: 160px; font-weight: 800; font-size: 40px">Which way to move money?</div>`;
        let tweens = reveal(`#${p}-q`, 0.2);
        table.forEach(([step, need, use], i) => {
            html += `
      <div id="${p}-tr${i}" style="position: absolute; left: 72px; top: ${250 + i * 150}px; width: 1776px; height: 126px; background: #ffffff; border-radius: 20px; border: 2px solid #d3dbd2; display: flex; align-items: center; padding: 0 40px; gap: 30px">
        <div style="flex: 1; font-size: 36px; font-weight: 600">${esc(need)}</div>
        <div style="font-size: 36px; color: #6b8574">→</div>
        <div style="width: 520px; font-size: 38px; font-weight: 800; color: #1f7a3f">${esc(use)}</div>
      </div>`;
            tweens += reveal(`#${p}-tr${i}`, s(step), "x: -40");
        });
        html += `
    </div>`;
        tweens += hide(`#${p}-tbl`, s("recap"));
        const recap = {
            Cashier: "Start, sell, count their own tray",
            Supervisor: "Check the drawer and the counts",
            Manager: "End the store's day · cash in & out",
            Owner: "Every store · Cash on hand · moves money between stores",
        };
        Object.entries(recap).forEach(([role, line], i) => {
            const r = ROLES[role];
            html += `
    <div id="${p}-rc${i}" style="position: absolute; left: ${72 + i * 450}px; top: 260px; width: 426px; height: 420px; background: #ffffff; border-radius: 24px; border-top: 14px solid ${r.color}; box-shadow: 0 16px 40px rgba(16, 35, 26, 0.08); padding: 36px 32px">
      <div style="font-weight: 900; font-size: 42px; color: ${r.color}">${role === "Owner" ? "Owner / Admin" : role}</div>
      <div style="margin-top: 22px; font-size: 32px; font-weight: 600; line-height: 1.3">${esc(line)}</div>
    </div>`;
            tweens += reveal(`#${p}-rc${i}`, s(role), "y: 40");
        });
        // End card over everything.
        html += `
    <div id="${p}-end" style="position: absolute; inset: 0; background: #1f7a3f; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 18px; color: #ffffff">
      <div style="font-weight: 700; font-size: 30px; letter-spacing: 0.18em; text-transform: uppercase; opacity: 0.85">Techiko POS</div>
      <div style="font-weight: 900; font-size: 120px; letter-spacing: -0.04em">Cash &amp; wallet</div>
      <div style="font-weight: 600; font-size: 38px; opacity: 0.9">Every peso, from the till to the owner.</div>
    </div>`;
        tweens += `      tl.fromTo("#${p}-end", { opacity: 0 }, { opacity: 1, duration: 0.6, ease: "power2.out" }, ${t(s("end"))});\n`;
        return { html, tweens };
    },
};

// Scenes.
SCENES.forEach((scene, i) => {
    const n = i + 1;
    const s = (step) => at(scene.id, scene.steps[step]);
    const isTitle = scene.diagram === "intro";
    const dia = scene.diagram ? DIAGRAMS[scene.diagram](scene, s) : { html: "", tweens: "" };
    const foot = footage(scene, !scene.diagram);
    const pts = points(scene);
    // A diagram scene with footage: the diagram makes way while it plays (ways does its own).
    let swap = "";
    if (scene.diagram === "lifecycle") {
        const w = windows[scene.footage[0].clip];
        if (w) swap = hide(`#${scene.id}-dia`, w.start, 0.3);
    }

    const html = `<template>
  <style>${BASE_CSS}
  </style>
  <div id="root" data-composition-id="${scene.id}" data-width="${W}" data-height="${H}">
    <div class="bg"></div>${isTitle ? "" : header(scene, n)}${dia.html}${foot.html}${pts.html}
  </div>
  <script>
    (() => {
      const tl = gsap.timeline({ paused: true });
${isTitle ? "" : `      tl.fromTo("#${scene.id}-hdr", { opacity: 0, y: -16 }, { opacity: 1, y: 0, duration: 0.45, ease: "power3.out" }, 0.05);\n`}${dia.tweens}${swap}${foot.tweens}${pts.tweens}      window.__timelines["${scene.id}"] = tl;
    })();
  </script>
</template>
`;
    writeFileSync(join(COMP, `${scene.id}.html`), html);
});

// Captions: one group at a time, a scrim sized to the text, along the bottom.
const caps = JSON.parse(readFileSync(join(ROOT, "captions.json"), "utf8"));
const capHtml = caps
    .map((c, i) => `
    <div id="cap-${i}" class="cap"><span>${esc(c.text)}</span></div>`)
    .join("");
const capTweens = caps
    .map((c, i) => {
        const start = Math.max(0.15, c.start - 0.05);
        const next = caps[i + 1]?.start ?? c.end + 1;
        const end = Math.min(c.end + 0.35, next - 0.02);
        return `      tl.fromTo("#cap-${i}", { opacity: 0 }, { opacity: 1, duration: 0.1 }, ${start.toFixed(2)});
      tl.set("#cap-${i}", { opacity: 0, visibility: "hidden" }, ${Math.max(start + 0.2, end).toFixed(2)});\n`;
    })
    .join("");
writeFileSync(join(COMP, "captions.html"), `<template>
  <style>
      #root { position: absolute; inset: 0; font-family: "Inter", sans-serif; }
      .cap { position: absolute; left: 0; right: 0; bottom: 54px; text-align: center; opacity: 0; overflow: visible; }
      .cap span { display: inline-block; max-width: 1500px; background: rgba(16, 35, 26, 0.86); color: #ffffff; font-weight: 600; font-size: 38px; line-height: 1.3; padding: 10px 26px; border-radius: 14px; }
  </style>
  <div id="root" data-composition-id="captions" data-width="${W}" data-height="${H}" data-layout-allow-caption-zone>${capHtml}
  </div>
  <script>
    (() => {
      const tl = gsap.timeline({ paused: true });
${capTweens}      window.__timelines["captions"] = tl;
    })();
  </script>
</template>
`);

// The root: scene hosts, the narration, the captions.
const hosts = SCENES.map((scene, i) => {
    const sl = slot(scene.id);
    const d = i === SCENES.length - 1 ? sl.duration + END_HOLD : sl.duration;
    return `
      <div id="${scene.id}" data-composition-id="${scene.id}" data-composition-src="compositions/${scene.id}.html" data-start="${sl.start}" data-duration="${d.toFixed(3)}" data-track-index="1" data-track-kind="graphics" data-width="${W}" data-height="${H}"></div>`;
}).join("");

writeFileSync(join(ROOT, "index.html"), `<!doctype html>
<html lang="en" data-resolution="landscape">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=${W}, height=${H}" />
    <title>Cash &amp; wallet: how money moves, by role</title>
    <script src="https://cdn.jsdelivr.net/npm/gsap@3.14.2/dist/gsap.min.js"></script>
    <style>
      * { margin: 0; padding: 0; box-sizing: border-box; }
      html, body { margin: 0; width: ${W}px; height: ${H}px; overflow: hidden; background: #f6f8f5; }
      #main { position: relative; width: 100%; height: 100%; }
      #captions { z-index: 10; }
    </style>
  </head>
  <body>
    <!-- Generated by scripts/build.mjs from script.md's voiceover timings: edit scenes.config.mjs and rebuild. -->
    <div id="main" data-composition-id="main" data-start="0" data-duration="${total}" data-width="${W}" data-height="${H}">${hosts}
      <div id="captions" data-composition-id="captions" data-composition-src="compositions/captions.html" data-start="0" data-duration="${total}" data-track-index="5" data-track-kind="captions" data-width="${W}" data-height="${H}"></div>
      <audio id="narration" class="clip" data-start="0" data-duration="${timeline.total}" data-track-index="6" data-volume="1" src="assets/audio/narration.wav"></audio>
    </div>
    <script>
      window.__timelines = window.__timelines || {};
      window.__timelines["main"] = gsap.timeline({ paused: true });
    </script>
  </body>
</html>
`);

console.log(`index.html: ${SCENES.length} scenes, ${caps.length} captions, ${total}s`);
reportMissed();
