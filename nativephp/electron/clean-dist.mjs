/**
 * Optional manual cleanup of nativephp/electron dist, Vite out, and packager release.
 * Retries to work around Windows EPERM when Explorer or another process holds DLLs under win-unpacked.
 */
import fs from 'fs/promises';
import path from 'path';
import { fileURLToPath } from 'url';

const root = path.dirname(fileURLToPath(import.meta.url));
const targets = [path.join(root, 'dist'), path.join(root, 'out'), path.join(root, 'release')];
const delay = (ms) => new Promise((r) => setTimeout(r, ms));

async function tryRemoveOne(dir) {
    for (let i = 0; i < 5; i++) {
        try {
            await fs.rm(dir, { recursive: true, force: true });
            return 'removed';
        } catch (e) {
            const code = e?.code;
            if (code === 'ENOENT') {
                return 'absent';
            }
            if (code !== 'EPERM' && code !== 'EBUSY') {
                throw e;
            }
            if (i === 4) {
                return 'locked';
            }
            console.warn(`clean-dist: ${path.basename(dir)} ${code} (attempt ${i + 1}/5), retry in 1s…`);
            await delay(1000);
        }
    }
    return 'locked';
}

let failed = false;
for (const dir of targets) {
    const result = await tryRemoveOne(dir);
    if (result === 'removed') {
        console.log(`Removed ${path.relative(root, dir)}`);
    } else if (result === 'locked') {
        failed = true;
        console.error(`Could not remove ${path.relative(root, dir)}.`);
    }
}

if (failed) {
    console.error(
        'Close File Explorer in those folders, stop any running packaged app, then run: npm run clean:dist',
    );
    process.exit(1);
}
process.exit(0);
