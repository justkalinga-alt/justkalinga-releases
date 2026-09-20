import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import crypto from 'node:crypto';
import { chromium } from 'playwright';

const here = path.dirname(new URL(import.meta.url).pathname.replace(/^\/(?:[A-Za-z]:)/, m => m.slice(1)));
const root = path.resolve(here, '..');
const configPath = path.join(root, 'worker-config.json');
if (!fs.existsSync(configPath)) throw new Error('worker-config.json not found. Run setup.ps1 first.');
const config = JSON.parse(fs.readFileSync(configPath, 'utf8'));

const hubUrl = String(config.hub_url || '').replace(/\/$/, '');
const token = String(config.worker_token || '').trim();
const workerId = String(config.worker_id || 'jk-community-windows-01').trim();
const profileDir = path.resolve(config.profile_dir || path.join(root, '.youtube-profile'));
const pollSeconds = Math.max(10, Number(config.poll_seconds || 20));
const headless = Boolean(config.headless);
const stateDir = path.join(root, '.state');
const ledgerPath = path.join(stateDir, 'community-worker-ledger.json');
const tempRoot = path.join(os.tmpdir(), 'jk-youtube-community-worker');

if (!/^https:\/\//i.test(hubUrl)) throw new Error('hub_url must be HTTPS.');
if (!token || token === 'PASTE_TOKEN_FROM_JK_SOCIAL_HUB') throw new Error('Paste the Community Worker Token into worker-config.json first.');
fs.mkdirSync(profileDir, { recursive: true });
fs.mkdirSync(stateDir, { recursive: true });
fs.mkdirSync(tempRoot, { recursive: true });

let stopped = false;
let working = false;

function readLedger() {
  try { return JSON.parse(fs.readFileSync(ledgerPath, 'utf8')); } catch { return {}; }
}
function writeLedger(data) {
  const tmp = ledgerPath + '.tmp';
  fs.writeFileSync(tmp, JSON.stringify(data, null, 2));
  fs.renameSync(tmp, ledgerPath);
}
function setLedger(key, patch) {
  const data = readLedger();
  data[key] = { ...(data[key] || {}), ...patch, updated_at: new Date().toISOString() };
  writeLedger(data);
  return data[key];
}
function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

async function hubPost(url, body, useWorkerToken = true) {
  const headers = { 'content-type': 'application/json' };
  if (useWorkerToken) headers['x-jksh-worker-token'] = token;
  const r = await fetch(url, { method: 'POST', headers, body: JSON.stringify(body) });
  let data = {};
  try { data = await r.json(); } catch {}
  if (!r.ok) throw new Error(data?.message || data?.code || ('HTTP ' + r.status));
  return data;
}

async function pullJob() {
  return hubPost(hubUrl + '/wp-json/jksh/v1/community-worker/pull', { worker_id: workerId });
}

async function sendReceipt(job, body) {
  return hubPost(job.receipt_callback_url, {
    job_id: job.job_id,
    idempotency_key: job.idempotency_key,
    token: job.receipt_callback_token,
    completed_at: new Date().toISOString(),
    ...body
  }, false);
}

async function downloadMedia(url, dir, index) {
  const r = await fetch(url, { redirect: 'follow' });
  if (!r.ok) throw new Error('Media download failed HTTP ' + r.status);
  const type = String(r.headers.get('content-type') || '').toLowerCase();
  const ext = type.includes('png') ? '.png'
    : type.includes('webp') ? '.webp'
    : type.includes('gif') ? '.gif'
    : type.includes('jpeg') || type.includes('jpg') ? '.jpg'
    : '.jpg';
  const file = path.join(dir, String(index + 1).padStart(2, '0') + ext);
  fs.writeFileSync(file, Buffer.from(await r.arrayBuffer()));
  return file;
}

async function visible(locator) {
  try { return await locator.first().isVisible({ timeout: 1500 }); } catch { return false; }
}

async function clickFirst(page, candidates) {
  for (const loc of candidates) {
    if (await visible(loc)) {
      await loc.first().click();
      return true;
    }
  }
  return false;
}

async function getPostHrefs(page) {
  try {
    return await page.locator('a[href*="/post/"]').evaluateAll(nodes =>
      [...new Set(nodes.map(a => a.href).filter(Boolean))]
    );
  } catch { return []; }
}

async function ensureLoggedIn(page) {
  const url = page.url();
  if (/accounts\.google\.com|\/signin/i.test(url)) return false;
  const signIn = page.getByRole('link', { name: /sign in/i });
  if (await visible(signIn)) return false;
  return true;
}

async function openComposer(page) {
  const createPostCandidates = [
    page.getByRole('button', { name: /create post/i }),
    page.getByText(/create post/i, { exact: true }),
    page.locator('button').filter({ hasText: /create post/i })
  ];
  await clickFirst(page, createPostCandidates).catch(() => false);
  await sleep(1200);

  const editors = [
    page.locator('[contenteditable="true"]').filter({ hasNot: page.locator('[aria-hidden="true"]') }),
    page.getByRole('textbox'),
    page.locator('textarea')
  ];
  for (const loc of editors) {
    try {
      const count = await loc.count();
      for (let i = 0; i < count; i++) {
        const x = loc.nth(i);
        if (await x.isVisible()) return x;
      }
    } catch {}
  }
  throw new Error('community_composer_not_found');
}

async function attachImages(page, files) {
  if (!files.length) return;
  let input = page.locator('input[type="file"]');
  if (await input.count() === 0) {
    await clickFirst(page, [
      page.getByRole('button', { name: /image|photo/i }),
      page.getByText(/image|photo/i, { exact: true })
    ]).catch(() => false);
    await sleep(800);
    input = page.locator('input[type="file"]');
  }
  if (await input.count() === 0) throw new Error('community_image_input_not_found');
  await input.first().setInputFiles(files);
  await sleep(Math.min(12000, 2500 + files.length * 900));
}

async function findNewPostUrl(page, before, caption) {
  const deadline = Date.now() + 30000;
  while (Date.now() < deadline) {
    const hrefs = await getPostHrefs(page);
    const fresh = hrefs.find(h => !before.includes(h));
    if (fresh) return fresh;
    await sleep(1500);
  }

  try {
    await page.reload({ waitUntil: 'domcontentloaded', timeout: 60000 });
    await sleep(2500);
    const hrefs = await getPostHrefs(page);
    const fresh = hrefs.find(h => !before.includes(h));
    if (fresh) return fresh;

    const snippet = String(caption || '').replace(/\s+/g, ' ').trim().slice(0, 60);
    if (snippet) {
      const text = page.getByText(snippet, { exact: false }).first();
      if (await visible(text)) {
        const postLink = text.locator('xpath=ancestor::*[.//a[contains(@href,"/post/")]][1]//a[contains(@href,"/post/")]').first();
        if (await visible(postLink)) return await postLink.getAttribute('href');
      }
    }
  } catch {}
  return '';
}

async function publishJob(job) {
  const key = String(job.idempotency_key);
  const ledger = readLedger()[key] || {};

  if (ledger.status === 'published' && ledger.external_url) {
    await sendReceipt(job, { status: 'published', external_url: ledger.external_url });
    return;
  }
  if (ledger.status === 'clicked_unverified' || ledger.status === 'uncertain') {
    await sendReceipt(job, {
      status: 'uncertain',
      safe_to_retry: false,
      error: 'Local worker ledger shows Post may already have been clicked. Duplicate-safe retry is blocked.'
    });
    return;
  }

  const jobDir = path.join(tempRoot, 'job-' + job.job_id + '-' + crypto.randomBytes(4).toString('hex'));
  fs.mkdirSync(jobDir, { recursive: true });
  let context;
  let clicked = false;

  try {
    setLedger(key, { status: 'starting', job_id: job.job_id });
    const media = [];
    for (let i = 0; i < (job.media_urls || []).length; i++) {
      media.push(await downloadMedia(job.media_urls[i], jobDir, i));
    }

    context = await chromium.launchPersistentContext(profileDir, {
      headless,
      viewport: headless ? { width: 1440, height: 1000 } : null,
      args: headless ? [] : ['--start-maximized']
    });
    const pages = context.pages();
    const page = pages[0] || await context.newPage();

    await page.goto(job.community_url, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await sleep(1800);
    if (!(await ensureLoggedIn(page))) throw new Error('youtube_login_required');

    const before = await getPostHrefs(page);
    const editor = await openComposer(page);
    await editor.click();
    try { await editor.fill(job.caption); }
    catch {
      await editor.press('Control+A').catch(() => {});
      await editor.pressSequentially(job.caption, { delay: 1 });
    }
    await attachImages(page, media);

    const postButton = page.getByRole('button', { name: /^post$/i }).last();
    if (!(await visible(postButton))) throw new Error('community_post_button_not_found');

    setLedger(key, { status: 'ready_to_click' });
    await postButton.click();
    clicked = true;
    setLedger(key, { status: 'clicked_unverified', clicked_at: new Date().toISOString() });

    const externalUrl = await findNewPostUrl(page, before, job.caption);
    if (!externalUrl || !/youtube\.com\/post\//i.test(externalUrl)) {
      setLedger(key, { status: 'uncertain', reason: 'public_post_url_not_found' });
      await sendReceipt(job, {
        status: 'uncertain',
        safe_to_retry: false,
        error: 'Post click completed but the public YouTube /post/ URL could not be verified. Automatic retry blocked.'
      });
      return;
    }

    setLedger(key, { status: 'published', external_url: externalUrl });
    await sendReceipt(job, { status: 'published', external_url: externalUrl });
    console.log('Published Community job #' + job.job_id + ': ' + externalUrl);
  } catch (err) {
    const message = String(err?.message || err);
    if (clicked) {
      setLedger(key, { status: 'uncertain', reason: message });
      await sendReceipt(job, { status: 'uncertain', safe_to_retry: false, error: message }).catch(()=>{});
    } else {
      setLedger(key, { status: 'failed_safe_retry', reason: message });
      await sendReceipt(job, { status: 'failed', safe_to_retry: true, error: message }).catch(()=>{});
    }
    console.error('Community job #' + job.job_id + ' failed:', message);
  } finally {
    if (context) await context.close().catch(()=>{});
    fs.rmSync(jobDir, { recursive: true, force: true });
  }
}

async function loop() {
  console.log('JK YouTube Community Worker v0.9.3.1');
  console.log('Hub:', hubUrl);
  console.log('Worker:', workerId);
  console.log('Poll:', pollSeconds + 's');
  while (!stopped) {
    if (!working) {
      working = true;
      try {
        const result = await pullJob();
        if (result?.has_job && result.job) await publishJob(result.job);
      } catch (err) {
        console.error('Poll error:', String(err?.message || err));
      } finally {
        working = false;
      }
    }
    for (let i = 0; i < pollSeconds && !stopped; i++) await sleep(1000);
  }
}

process.on('SIGINT', () => { stopped = true; });
process.on('SIGTERM', () => { stopped = true; });
await loop();
