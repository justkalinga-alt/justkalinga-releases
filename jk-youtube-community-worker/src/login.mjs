import fs from 'node:fs';
import path from 'node:path';
import readline from 'node:readline/promises';
import { stdin as input, stdout as output } from 'node:process';
import { fileURLToPath } from 'node:url';
import { spawn } from 'node:child_process';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '..');
const configPath = path.join(root, 'worker-config.json');

if (!fs.existsSync(configPath)) {
  throw new Error('worker-config.json not found. Run setup.ps1 first.');
}

const config = JSON.parse(fs.readFileSync(configPath, 'utf8'));
const profileDir = path.resolve(config.profile_dir || path.join(root, '.youtube-profile'));
fs.mkdirSync(profileDir, { recursive: true });

const candidates = [
  process.env.ProgramFiles ? path.join(process.env.ProgramFiles, 'Google', 'Chrome', 'Application', 'chrome.exe') : '',
  process.env['ProgramFiles(x86)'] ? path.join(process.env['ProgramFiles(x86)'], 'Google', 'Chrome', 'Application', 'chrome.exe') : '',
  process.env.LOCALAPPDATA ? path.join(process.env.LOCALAPPDATA, 'Google', 'Chrome', 'Application', 'chrome.exe') : ''
].filter(Boolean);

const chrome = candidates.find(p => fs.existsSync(p));
if (!chrome) {
  throw new Error('Google Chrome was not found. Install Chrome or update login.mjs with its path.');
}

const channelUrl = String(config.channel_url || 'https://www.youtube.com').replace(/\/$/, '');
const target = channelUrl + '/community';

console.log('');
console.log('Opening normal Google Chrome with the dedicated JK worker profile.');
console.log('This avoids Google blocking automated Chromium sign-in.');
console.log('Profile: ' + profileDir);
console.log('');

const child = spawn(chrome, [
  '--user-data-dir=' + profileDir,
  '--no-first-run',
  '--no-default-browser-check',
  target
], {
  detached: true,
  stdio: 'ignore'
});
child.unref();

console.log('Sign in to the correct JustKalinga Google/YouTube account in that Chrome window.');
console.log('Confirm the Community page loads while signed in.');
console.log('Then CLOSE ALL Chrome windows using this dedicated profile.');
console.log('');

const rl = readline.createInterface({ input, output });
await rl.question('After closing that dedicated Chrome window, press ENTER here...');
rl.close();

console.log('YouTube session saved in: ' + profileDir);
console.log('Next: run .\\start.ps1');
