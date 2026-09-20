import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '..');
const configPath = path.join(root, 'worker-config.json');

if (!fs.existsSync(configPath)) {
  throw new Error('worker-config.json not found. Run setup.ps1 first.');
}

const configRaw = fs.readFileSync(configPath, 'utf8').replace(/^\\uFEFF/, '');
const config = JSON.parse(configRaw);

const hubUrl = String(config.hub_url || '').replace(/\/$/, '');
const token = String(config.worker_token || '').trim();
const workerId = String(config.worker_id || 'jk-community-windows-01').trim();
const profileDir = path.resolve(config.profile_dir || path.join(root, '.youtube-profile'));
const pollSeconds = Math.max(10, Number(config.poll_seconds || 20));
const headless = Boolean(config.headless);

const stateDir = path.join(root, '.state');
const diagnosticDir = path.join(root, 'diagnostics');
const ledgerPath = path.join(stateDir, 'community-worker-ledger.json');
const logPath = path.join(stateDir, 'worker.log');
const tempRoot = path.join(os.tmpdir(), 'jk-youtube-community-worker');

if (!/^https:\/\//i.test(hubUrl)) throw new Error('hub_url must be HTTPS.');
if (!token || token === 'PASTE_TOKEN_FROM_JK_SOCIAL_HUB') {
  throw new Error('Paste the Community Worker Token into worker-config.json first.');
}

for (const dir of [profileDir, stateDir, diagnosticDir, tempRoot]) {
  fs.mkdirSync(dir, { recursive: true });
}

let stopped = false;
let working = false;

function log(level, message, extra = null) {
  const stamp = new Date().toISOString();
  const line = '[' + stamp + '] [' + level + '] ' + message + (extra ? ' ' + JSON.stringify(extra) : '');
  console.log(line);
  try { fs.appendFileSync(logPath, line + os.EOL); } catch {}
}

function readLedger() {
  try { return JSON.parse(fs.readFileSync(ledgerPath, 'utf8')); }
  catch { return {}; }
}

function writeLedger(data) {
  const tmp = ledgerPath + '.tmp';
  fs.writeFileSync(tmp, JSON.stringify(data, null, 2));
  fs.renameSync(tmp, ledgerPath);
}

function setLedger(key, patch) {
  const data = readLedger();
  data[key] = {
    ...(data[key] || {}),
    ...patch,
    updated_at: new Date().toISOString()
  };
  writeLedger(data);
  return data[key];
}

function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

function safeName(value) {
  return String(value || '').replace(/[^a-zA-Z0-9._-]+/g, '_').slice(0, 100);
}

async function diagnostic(page, job, label) {
  if (!page) return '';
  const stamp = new Date().toISOString().replace(/[:.]/g, '-');
  const file = path.join(
    diagnosticDir,
    'job-' + safeName(job?.job_id) + '-' + safeName(label) + '-' + stamp + '.png'
  );
  try {
    await page.screenshot({ path: file, fullPage: true });
    log('INFO', 'Diagnostic screenshot saved', { file });
    return file;
  } catch (err) {
    log('WARN', 'Could not save diagnostic screenshot', { error: String(err?.message || err) });
    return '';
  }
}

async function hubPost(url, body, useWorkerToken = true) {
  const headers = { 'content-type': 'application/json' };
  if (useWorkerToken) headers['x-jksh-worker-token'] = token;

  const response = await fetch(url, {
    method: 'POST',
    headers,
    body: JSON.stringify(body)
  });

  let data = {};
  try { data = await response.json(); } catch {}

  if (!response.ok) {
    throw new Error(data?.message || data?.code || ('HTTP ' + response.status));
  }
  return data;
}

async function pullJob() {
  return hubPost(
    hubUrl + '/wp-json/jksh/v1/community-worker/pull',
    { worker_id: workerId }
  );
}

async function sendReceipt(job, body) {
  return hubPost(
    job.receipt_callback_url,
    {
      job_id: job.job_id,
      idempotency_key: job.idempotency_key,
      token: job.receipt_callback_token,
      completed_at: new Date().toISOString(),
      ...body
    },
    false
  );
}

async function downloadMedia(url, dir, index) {
  const response = await fetch(url, { redirect: 'follow' });
  if (!response.ok) throw new Error('Media download failed HTTP ' + response.status);

  const type = String(response.headers.get('content-type') || '').toLowerCase();
  const ext = type.includes('png') ? '.png'
    : type.includes('webp') ? '.webp'
    : type.includes('gif') ? '.gif'
    : type.includes('jpeg') || type.includes('jpg') ? '.jpg'
    : '.jpg';

  const file = path.join(dir, String(index + 1).padStart(2, '0') + ext);
  fs.writeFileSync(file, Buffer.from(await response.arrayBuffer()));
  return file;
}

async function visible(locator) {
  try {
    return await locator.first().isVisible({ timeout: 1500 });
  } catch {
    return false;
  }
}

async function clickFirst(candidates) {
  for (const locator of candidates) {
    if (await visible(locator)) {
      await locator.first().click();
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
  } catch {
    return [];
  }
}

async function ensureLoggedIn(page) {
  if (/accounts\.google\.com|\/signin/i.test(page.url())) return false;

  for (const locator of [
    page.getByRole('link', { name: /sign in/i }),
    page.getByRole('button', { name: /sign in/i })
  ]) {
    if (await visible(locator)) return false;
  }
  return true;
}

async function openComposer(page) {
  // IMPORTANT: Use YouTube's creator-wide Create > Create post flow.
  // Do not post from /community, because that creates Community-only posts
  // which do not appear on the channel's normal Published/Posts surface.
  await page.goto('https://www.youtube.com/', {
    waitUntil: 'domcontentloaded',
    timeout: 60000
  });
  await sleep(1800);

  if (!(await ensureLoggedIn(page))) {
    throw new Error('youtube_login_required');
  }

  const createOpened = await clickFirst([
    page.getByRole('button', { name: /^create$/i }),
    page.locator('button[aria-label="Create"]'),
    page.locator('[aria-label="Create"]').filter({ has: page.locator('svg, yt-icon') })
  ]).catch(() => false);

  if (!createOpened) throw new Error('youtube_create_menu_not_found');

  await sleep(900);

  const postOpened = await clickFirst([
    page.getByRole('menuitem', { name: /create post/i }),
    page.getByText(/create post/i, { exact: true }),
    page.locator('tp-yt-paper-item').filter({ hasText: /create post/i }),
    page.locator('ytd-compact-link-renderer').filter({ hasText: /create post/i })
  ]).catch(() => false);

  if (!postOpened) throw new Error('youtube_create_post_action_not_found');

  await sleep(1400);

  // Guard against accidentally landing in the Community-only composer.
  const communityOnly = [
    page.getByText(/^my community$/i, { exact: true }),
    page.getByText(/community-only/i, { exact: false })
  ];
  for (const locator of communityOnly) {
    if (await visible(locator)) {
      throw new Error('wrong_surface_community_only');
    }
  }

  const editors = [
    page.locator('ytd-backstage-post-dialog-renderer [contenteditable="true"]'),
    page.locator('[role="dialog"] [contenteditable="true"]'),
    page.locator('div[role="textbox"][contenteditable="true"]'),
    page.getByRole('textbox'),
    page.locator('[contenteditable="true"]'),
    page.locator('textarea')
  ];

  for (const locator of editors) {
    try {
      const count = await locator.count();
      for (let i = 0; i < count; i++) {
        const candidate = locator.nth(i);
        if (await candidate.isVisible()) return candidate;
      }
    } catch {}
  }

  throw new Error('public_post_composer_not_found');
}

async function attachImages(page, files) {
  if (!files.length) return;
  if (files.length > 10) throw new Error('public_post_image_limit_exceeded');

  const openImagePicker = async () => {
    return await clickFirst([
      page.getByRole('button', { name: /^image$/i }),
      page.getByRole('button', { name: /add image|image|photo/i }),
      page.getByText(/^image$/i, { exact: true }),
      page.locator('[aria-label*="image" i]'),
      page.locator('[title*="image" i]')
    ]).catch(() => false);
  };

  await openImagePicker();
  await sleep(700);

  const inputs = page.locator('input[type="file"][accept*="image" i], input[type="file"]');
  const count = await inputs.count();
  if (!count) throw new Error('public_post_image_input_not_found');

  let input = null;
  for (let i = count - 1; i >= 0; i--) {
    const candidate = inputs.nth(i);
    const acceptsImages = await candidate.getAttribute('accept').catch(() => '');
    if (!acceptsImages || /image/i.test(acceptsImages)) {
      input = candidate;
      break;
    }
  }
  if (!input) throw new Error('public_post_image_input_not_found');

  const multiple = await input.evaluate(el => !!el.multiple).catch(() => false);
  if (!multiple && files.length > 1) {
    throw new Error('public_post_multi_image_input_not_available');
  }

  log('INFO', 'Uploading public YouTube post images', {
    count: files.length,
    mode: multiple ? 'multi-select' : 'single'
  });

  await input.setInputFiles(multiple ? files : files[0]);
  await sleep(Math.min(20000, 3500 + files.length * 1100));

  const selectedCount = await input.evaluate(el => el.files ? el.files.length : 0).catch(() => 0);
  if (selectedCount !== files.length) {
    throw new Error('public_post_image_count_mismatch_expected_' + files.length + '_got_' + selectedCount);
  }

  log('INFO', 'All public post images attached', {
    expected: files.length,
    selected: selectedCount
  });
}

async function findNewPostUrl(page, before, caption) {
  const deadline = Date.now() + 35000;

  while (Date.now() < deadline) {
    const hrefs = await getPostHrefs(page);
    const fresh = hrefs.find(href => !before.includes(href));
    if (fresh) return fresh;
    await sleep(1500);
  }

  try {
    await page.reload({ waitUntil: 'domcontentloaded', timeout: 60000 });
    await sleep(3000);

    const hrefs = await getPostHrefs(page);
    const fresh = hrefs.find(href => !before.includes(href));
    if (fresh) return fresh;

    const snippet = String(caption || '')
      .replace(/\s+/g, ' ')
      .trim()
      .slice(0, 60);

    if (snippet) {
      const text = page.getByText(snippet, { exact: false }).first();
      if (await visible(text)) {
        const postLink = text.locator(
          'xpath=ancestor::*[.//a[contains(@href,"/post/")]][1]//a[contains(@href,"/post/")]'
        ).first();
        if (await visible(postLink)) {
          const href = await postLink.getAttribute('href');
          if (href) return new URL(href, 'https://www.youtube.com').href;
        }
      }
    }
  } catch {}

  return '';
}

async function publishJob(job) {
  const key = String(job.idempotency_key);
  const ledger = readLedger()[key] || {};

  if (ledger.status === 'published' && ledger.external_url) {
    log('INFO', 'Replaying verified receipt instead of reposting', { job_id: job.job_id });
    await sendReceipt(job, {
      status: 'published',
      external_url: ledger.external_url
    });
    return;
  }

  if (ledger.status === 'clicked_unverified' || ledger.status === 'uncertain') {
    log('WARN', 'Duplicate-safe block: prior Post click may have happened', { job_id: job.job_id });
    await sendReceipt(job, {
      status: 'uncertain',
      safe_to_retry: false,
      error: 'Local worker ledger shows Post may already have been clicked. Duplicate-safe retry is blocked.'
    });
    return;
  }

  const jobDir = path.join(
    tempRoot,
    'job-' + job.job_id + '-' + crypto.randomBytes(4).toString('hex')
  );
  fs.mkdirSync(jobDir, { recursive: true });

  let context;
  let page;
  let clicked = false;

  try {
    log('INFO', 'Starting YouTube public post job', {
      job_id: job.job_id,
      media: (job.media_urls || []).length
    });

    setLedger(key, { status: 'starting', job_id: job.job_id });

    const media = [];
    for (let i = 0; i < (job.media_urls || []).length; i++) {
      media.push(await downloadMedia(job.media_urls[i], jobDir, i));
    }

    context = await chromium.launchPersistentContext(profileDir, {
      channel: 'chrome',
      headless,
      viewport: headless ? { width: 1440, height: 1000 } : null,
      args: headless ? [] : ['--start-maximized']
    });

    page = context.pages()[0] || await context.newPage();

    await page.goto('https://www.youtube.com/', {
      waitUntil: 'domcontentloaded',
      timeout: 60000
    });
    await sleep(1200);

    if (!(await ensureLoggedIn(page))) {
      await diagnostic(page, job, 'login-required');
      throw new Error('youtube_login_required');
    }

    const before = await getPostHrefs(page);
    const editor = await openComposer(page);

    await editor.click();
    try {
      await editor.fill(job.caption);
    } catch {
      await editor.press('Control+A').catch(() => {});
      await editor.pressSequentially(job.caption, { delay: 1 });
    }

    await attachImages(page, media);

    const postCandidates = [
      page.getByRole('button', { name: /^post$/i }),
      page.locator('button').filter({ hasText: /^post$/i }),
      page.getByText(/^post$/i, { exact: true })
    ];

    let postButton = null;
    for (const locator of postCandidates) {
      if (await visible(locator)) {
        postButton = locator.first();
        break;
      }
    }

    if (!postButton) {
      await diagnostic(page, job, 'post-button-missing');
      throw new Error('community_post_button_not_found');
    }

    await diagnostic(page, job, 'ready-to-post');
    setLedger(key, { status: 'ready_to_click' });

    await postButton.click();
    clicked = true;

    setLedger(key, {
      status: 'clicked_unverified',
      clicked_at: new Date().toISOString()
    });

    const externalUrl = await findNewPostUrl(page, before, job.caption);

    if (!externalUrl || !/youtube\.com\/post\//i.test(externalUrl)) {
      await diagnostic(page, job, 'clicked-url-unverified');
      setLedger(key, {
        status: 'uncertain',
        reason: 'public_post_url_not_found'
      });

      await sendReceipt(job, {
        status: 'uncertain',
        safe_to_retry: false,
        error: 'Post click completed but the public YouTube /post/ URL could not be verified. Automatic retry blocked.'
      });
      return;
    }

    setLedger(key, {
      status: 'published',
      external_url: externalUrl
    });

    await sendReceipt(job, {
      status: 'published',
      external_url: externalUrl
    });

    log('INFO', 'Public YouTube post verified', {
      job_id: job.job_id,
      external_url: externalUrl
    });
  } catch (err) {
    const message = String(err?.message || err);
    await diagnostic(page, job, clicked ? 'uncertain-error' : 'safe-error');

    if (clicked) {
      setLedger(key, { status: 'uncertain', reason: message });
      await sendReceipt(job, {
        status: 'uncertain',
        safe_to_retry: false,
        error: message
      }).catch(() => {});
    } else {
      setLedger(key, {
        status: 'failed_safe_retry',
        reason: message
      });

      await sendReceipt(job, {
        status: 'failed',
        safe_to_retry: true,
        error: message
      }).catch(() => {});
    }

    log('ERROR', 'Community job failed', {
      job_id: job.job_id,
      clicked,
      error: message
    });
  } finally {
    if (context) await context.close().catch(() => {});
    fs.rmSync(jobDir, { recursive: true, force: true });
  }
}

async function loop() {
  log('INFO', 'JK YouTube Community Worker v0.9.3.1 starting', {
    hub: hubUrl,
    worker: workerId,
    poll_seconds: pollSeconds,
    profile: profileDir
  });

  while (!stopped) {
    if (!working) {
      working = true;
      try {
        const result = await pullJob();
        if (result?.has_job && result.job) {
          await publishJob(result.job);
        }
      } catch (err) {
        log('WARN', 'Poll error', { error: String(err?.message || err) });
      } finally {
        working = false;
      }
    }

    for (let i = 0; i < pollSeconds && !stopped; i++) {
      await sleep(1000);
    }
  }

  log('INFO', 'Worker stopped');
}

process.on('SIGINT', () => { stopped = true; });
process.on('SIGTERM', () => { stopped = true; });

await loop();
