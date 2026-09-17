import http from 'node:http';
import crypto from 'node:crypto';
import { spawn } from 'node:child_process';
import { URL } from 'node:url';

const VERSION = '0.1.5-back4app';
const CONTRACT = 'jksh-relay-v1';
const PORT = Number(process.env.PORT || 8080);
const PUBLIC_KEY_B64 = process.env.JKSH_PUBLIC_KEY_B64 || '';
const KEY_ID = process.env.JKSH_KEY_ID || '';
const ALLOWED_SOURCE_HOSTS = new Set((process.env.ALLOWED_SOURCE_HOSTS || '').split(',').map(v => v.trim().toLowerCase()).filter(Boolean));
const MAX_BODY = 256 * 1024;
const MAX_CLOCK_SKEW = 300;
const jobs = new Map();
const seenNonces = new Map();

function publicKeyPem() {
  if (!PUBLIC_KEY_B64) return '';
  try { return Buffer.from(PUBLIC_KEY_B64, 'base64').toString('utf8'); } catch { return ''; }
}
const PUBLIC_KEY_PEM = publicKeyPem();

function json(res, status, body) {
  const data = Buffer.from(JSON.stringify(body));
  res.writeHead(status, {
    'content-type': 'application/json; charset=utf-8',
    'content-length': data.length,
    'cache-control': 'no-store',
    'x-content-type-options': 'nosniff'
  });
  res.end(data);
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    let total = 0;
    const chunks = [];
    req.on('data', chunk => {
      total += chunk.length;
      if (total > MAX_BODY) {
        reject(new Error('request_too_large'));
        req.destroy();
        return;
      }
      chunks.push(chunk);
    });
    req.on('end', () => resolve(Buffer.concat(chunks)));
    req.on('error', reject);
  });
}

function cleanupNonces(now = Date.now()) {
  for (const [nonce, ts] of seenNonces) {
    if (now - ts > (MAX_CLOCK_SKEW * 2 * 1000)) seenNonces.delete(nonce);
  }
}

function verifySignedRequest(req, raw) {
  if (!PUBLIC_KEY_PEM) return { ok: false, message: 'worker_not_paired' };
  if (req.headers['x-jksh-contract'] !== CONTRACT) return { ok: false, message: 'bad_contract' };
  const keyId = String(req.headers['x-jksh-key-id'] || '');
  if (KEY_ID && keyId !== KEY_ID) return { ok: false, message: 'bad_key_id' };
  const timestamp = String(req.headers['x-jksh-timestamp'] || '');
  const nonce = String(req.headers['x-jksh-nonce'] || '');
  const signature = String(req.headers['x-jksh-signature'] || '');
  if (!/^\d{10,}$/.test(timestamp) || nonce.length < 16 || !signature) return { ok: false, message: 'missing_signature_headers' };
  const now = Math.floor(Date.now() / 1000);
  if (Math.abs(now - Number(timestamp)) > MAX_CLOCK_SKEW) return { ok: false, message: 'stale_request' };
  cleanupNonces();
  if (seenNonces.has(nonce)) return { ok: false, message: 'replayed_nonce' };
  const payloadHash = crypto.createHash('sha256').update(raw).digest('hex');
  const signedText = `${CONTRACT}\n${timestamp}\n${nonce}\n${payloadHash}`;
  let valid = false;
  try {
    valid = crypto.verify('RSA-SHA256', Buffer.from(signedText), PUBLIC_KEY_PEM, Buffer.from(signature, 'base64'));
  } catch {
    valid = false;
  }
  if (!valid) return { ok: false, message: 'invalid_signature' };
  seenNonces.set(nonce, Date.now());
  return { ok: true };
}

function parseJson(raw) {
  try { return JSON.parse(raw.toString('utf8')); } catch { return null; }
}

function validStreamUrl(value, source = false) {
  if (typeof value !== 'string' || value.length < 8 || value.length > 4096) return false;
  let url;
  try { url = new URL(value); } catch { return false; }
  const protocols = source ? new Set(['rtmp:', 'rtmps:', 'srt:', 'http:', 'https:']) : new Set(['rtmp:', 'rtmps:']);
  if (!protocols.has(url.protocol)) return false;
  if (source && ALLOWED_SOURCE_HOSTS.size && !ALLOWED_SOURCE_HOSTS.has(url.hostname.toLowerCase())) return false;
  return true;
}

function safeJob(job) {
  return {
    job_id: job.jobId,
    live_stream_id: job.liveStreamId,
    state: job.state,
    mode: job.mode,
    started_at: job.startedAt,
    stopped_at: job.stoppedAt || null,
    destinations: [...job.outputs.keys()].map(platform => {
      const item = job.outputs.get(platform);
      return { platform, state: item.state, restarts: item.restarts, last_exit_code: item.lastExitCode ?? null };
    })
  };
}

function ffmpegArgs(sourceUrl, outputUrl, mode) {
  const base = ['-hide_banner', '-loglevel', 'warning', '-nostdin', '-reconnect', '1', '-reconnect_streamed', '1', '-reconnect_delay_max', '5', '-i', sourceUrl, '-map', '0:v:0?', '-map', '0:a:0?'];
  if (mode === 'transcode') {
    base.push('-c:v', 'libx264', '-preset', 'veryfast', '-tune', 'zerolatency', '-profile:v', 'high', '-pix_fmt', 'yuv420p', '-r', '30', '-g', '60', '-keyint_min', '60', '-sc_threshold', '0', '-b:v', '2500k', '-maxrate', '2500k', '-bufsize', '5000k', '-c:a', 'aac', '-b:a', '128k', '-ar', '44100', '-ac', '2');
  } else {
    base.push('-c', 'copy');
  }
  base.push('-f', 'flv', outputUrl);
  return base;
}

function spawnDestination(job, platform, url) {
  const entry = job.outputs.get(platform);
  if (!entry || job.state === 'stopping' || job.state === 'stopped') return;
  const args = ffmpegArgs(job.sourceUrl, url, job.mode);
  const child = spawn('ffmpeg', args, { stdio: ['ignore', 'ignore', 'pipe'] });
  entry.child = child;
  entry.state = 'running';
  entry.lastExitCode = null;
  let stderrTail = '';
  child.stderr.on('data', chunk => {
    const text = chunk.toString('utf8').replace(/(?:rtmps?|srt):\/\/\S+/gi, '[stream-url-redacted]');
    stderrTail = (stderrTail + text).slice(-4096);
  });
  child.on('exit', (code, signal) => {
    entry.child = null;
    entry.lastExitCode = code;
    entry.lastSignal = signal;
    entry.stderrTail = stderrTail;
    if (job.state === 'stopping' || job.state === 'stopped') {
      entry.state = 'stopped';
      maybeFinalizeStopped(job);
      return;
    }
    entry.state = 'failed';
    if (job.restartOnFailure && entry.restarts < 6) {
      const delay = Math.min(30000, 1000 * (2 ** entry.restarts));
      entry.restarts += 1;
      entry.state = 'restarting';
      entry.timer = setTimeout(() => spawnDestination(job, platform, url), delay);
    } else {
      job.state = [...job.outputs.values()].some(v => ['running', 'restarting'].includes(v.state)) ? 'degraded' : 'failed';
    }
  });
}

function maybeFinalizeStopped(job) {
  const alive = [...job.outputs.values()].some(v => v.child || v.timer);
  if (!alive) {
    job.state = 'stopped';
    job.stoppedAt = new Date().toISOString();
  }
}

function startJob(payload) {
  const jobId = String(payload?.job_id || '');
  const liveStreamId = Number(payload?.live_stream_id || 0);
  const sourceUrl = String(payload?.source_url || '');
  const outputs = Array.isArray(payload?.outputs) ? payload.outputs : [];
  const mode = payload?.profile?.mode === 'transcode' ? 'transcode' : 'copy';
  const restartOnFailure = payload?.profile?.restart_on_failure !== false;
  if (!/^[a-zA-Z0-9._:-]{8,160}$/.test(jobId) || !Number.isInteger(liveStreamId) || liveStreamId < 1) return { error: 'invalid_job_identity' };
  if (!validStreamUrl(sourceUrl, true)) return { error: 'invalid_source_url' };
  if (!outputs.length || outputs.length > 6) return { error: 'invalid_outputs' };
  if (jobs.has(jobId) && !['stopped', 'failed'].includes(jobs.get(jobId).state)) return { error: 'job_already_active' };
  const map = new Map();
  for (const output of outputs) {
    const platform = String(output?.platform || '').toLowerCase();
    const url = String(output?.url || '');
    if (!/^[a-z0-9_-]{2,32}$/.test(platform) || !validStreamUrl(url, false) || map.has(platform)) return { error: 'invalid_output' };
    map.set(platform, { url, child: null, timer: null, state: 'starting', restarts: 0, lastExitCode: null });
  }
  const job = { jobId, liveStreamId, sourceUrl, mode, restartOnFailure, outputs: map, state: 'starting', startedAt: new Date().toISOString(), stoppedAt: null };
  jobs.set(jobId, job);
  for (const [platform, entry] of map) spawnDestination(job, platform, entry.url);
  job.state = 'running';
  return { job };
}

function stopJob(jobId) {
  const job = jobs.get(jobId);
  if (!job) return { error: 'job_not_found' };
  job.state = 'stopping';
  for (const entry of job.outputs.values()) {
    if (entry.timer) { clearTimeout(entry.timer); entry.timer = null; }
    if (entry.child && !entry.child.killed) entry.child.kill('SIGINT');
    else entry.state = 'stopped';
  }
  setTimeout(() => {
    for (const entry of job.outputs.values()) {
      if (entry.child && !entry.child.killed) entry.child.kill('SIGKILL');
    }
    maybeFinalizeStopped(job);
  }, 5000).unref();
  maybeFinalizeStopped(job);
  return { job };
}

function normalizeRoute(method, path, routeHeader) {
  if (method === 'POST' && routeHeader) return routeHeader;
  if (path === '/') return '/';
  const aliases = [
    ['/v1/health', '/v1/health'], ['/health', '/v1/health'],
    ['/v1/ping', '/v1/ping'], ['/ping', '/v1/ping'],
    ['/v1/jobs/start', '/v1/jobs/start'], ['/jobs/start', '/v1/jobs/start'],
    ['/v1/jobs/stop', '/v1/jobs/stop'], ['/jobs/stop', '/v1/jobs/stop'],
    ['/v1/jobs/status', '/v1/jobs/status'], ['/jobs/status', '/v1/jobs/status']
  ];
  for (const [suffix, canonical] of aliases) {
    if (path === suffix || path.endsWith(suffix)) return canonical;
  }
  return path;
}

async function handler(req, res) {
  const path = new URL(req.url || '/', `http://${req.headers.host || 'localhost'}`).pathname;
  const routeHeader = String(req.headers['x-jksh-route'] || '');
  const route = normalizeRoute(req.method, path, routeHeader);
  if (req.method === 'GET' && ['/', '/v1/health'].includes(route)) {
    return json(res, 200, {
      ok: true,
      service: 'jksh-live-relay-worker',
      runtime: 'back4app-container',
      version: VERSION,
      contract: CONTRACT,
      paired: Boolean(PUBLIC_KEY_PEM),
      key_id: KEY_ID || null,
      active_jobs: [...jobs.values()].filter(j => !['stopped', 'failed'].includes(j.state)).length,
      capabilities: ['http-source', 'https-source', 'rtmp-pull', 'rtmps-pull', 'srt-pull', 'copy', 'transcode', 'multi-destination', 'root-route-fallback'],
      limitation: 'Back4App public ingress is HTTP(S); this container does not provide a public RTMP ingest listener.'
    });
  }
  if (req.method !== 'POST' || !['/v1/ping', '/v1/jobs/start', '/v1/jobs/stop', '/v1/jobs/status'].includes(route)) return json(res, 404, { error: 'not_found' });
  let raw;
  try { raw = await readBody(req); } catch { return json(res, 413, { error: 'request_too_large' }); }
  const auth = verifySignedRequest(req, raw);
  if (!auth.ok) return json(res, 401, { error: auth.message });
  const payload = parseJson(raw);
  if (!payload) return json(res, 400, { error: 'invalid_json' });
  if (route === '/v1/ping') {
    return json(res, 200, {
      ok: true,
      message: 'signed_handshake_ok',
      service: 'jksh-live-relay-worker',
      runtime: 'back4app-container',
      version: VERSION,
      contract: CONTRACT,
      paired: Boolean(PUBLIC_KEY_PEM),
      active_jobs: [...jobs.values()].filter(j => !['stopped', 'failed'].includes(j.state)).length
    });
  }
  if (route === '/v1/jobs/start') {
    const out = startJob(payload);
    if (out.error) return json(res, out.error === 'job_already_active' ? 409 : 400, { error: out.error });
    return json(res, 202, { ok: true, message: 'relay_job_started', job: safeJob(out.job) });
  }
  const jobId = String(payload.job_id || '');
  if (route === '/v1/jobs/stop') {
    const out = stopJob(jobId);
    if (out.error) return json(res, 404, { error: out.error });
    return json(res, 200, { ok: true, message: 'relay_job_stopping', job: safeJob(out.job) });
  }
  const job = jobs.get(jobId);
  if (!job) return json(res, 404, { error: 'job_not_found' });
  return json(res, 200, { ok: true, job: safeJob(job) });
}

const server = http.createServer((req, res) => {
  handler(req, res).catch(() => json(res, 500, { error: 'internal_error' }));
});
server.listen(PORT, '0.0.0.0', () => console.log(`JKSH Back4App relay worker ${VERSION} listening on :${PORT}`));

function shutdown() {
  for (const job of jobs.values()) stopJob(job.jobId);
  server.close(() => process.exit(0));
  setTimeout(() => process.exit(1), 7000).unref();
}
process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);
