import fs from 'node:fs';
import path from 'node:path';
import readline from 'node:readline/promises';
import { stdin as input, stdout as output } from 'node:process';
import { chromium } from 'playwright';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname.replace(/^\/(?:[A-Za-z]:)/, m => m.slice(1))), '..');
const configPath = path.join(root, 'worker-config.json');
if (!fs.existsSync(configPath)) {
  throw new Error('worker-config.json not found. Run setup.ps1 first.');
}
const config = JSON.parse(fs.readFileSync(configPath, 'utf8'));
const profileDir = path.resolve(config.profile_dir || path.join(root, '.youtube-profile'));
fs.mkdirSync(profileDir, { recursive: true });

const context = await chromium.launchPersistentContext(profileDir, {
  headless: false,
  viewport: null,
  args: ['--start-maximized']
});
const page = context.pages()[0] || await context.newPage();
const channelUrl = String(config.channel_url || 'https://www.youtube.com').replace(/\/$/, '');
await page.goto(channelUrl + '/community', { waitUntil: 'domcontentloaded', timeout: 60000 });

console.log('');
console.log('A dedicated Chromium window is open.');
console.log('Sign in to the correct JustKalinga YouTube account if needed.');
console.log('Open the Community tab and confirm you can see the Create post composer.');
console.log('');
const rl = readline.createInterface({ input, output });
await rl.question('When the YouTube account is ready, press ENTER here to save the session...');
rl.close();
await context.close();
console.log('YouTube session saved in: ' + profileDir);
