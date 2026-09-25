<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive');

function jksh_lv_out($success, $data, $status = 200) {
    http_response_code($status);
    echo json_encode(array('success'=>$success,'data'=>$data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function jksh_lv_wp_content() { return dirname(__DIR__, 2); }
function jksh_lv_stage() { return jksh_lv_wp_content() . '/uploads/jksh-large-upload-staging'; }
function jksh_lv_secret() {
    $f = __DIR__ . '/secret.php';
    if (!is_file($f)) return '';
    $s = include $f;
    return is_string($s) ? $s : '';
}
function jksh_lv_parse($id) {
    if (!preg_match('/^jklg1\.([a-f0-9-]{36})\.(\d{10})\.([a-f0-9]{64})$/', $id, $m)) return false;
    $secret = jksh_lv_secret();
    if ($secret === '') return false;
    $uuid = $m[1]; $exp = (int)$m[2];
    if ($exp < time()) return false;
    $sig = hash_hmac('sha256', $uuid . '|' . $exp, $secret);
    if (!hash_equals($sig, $m[3])) return false;
    return array('uuid'=>$uuid,'exp'=>$exp);
}
function jksh_lv_session($uuid) {
    $f = jksh_lv_stage() . '/' . $uuid . '.json';
    if (!is_file($f)) return false;
    $j = json_decode((string)file_get_contents($f), true);
    return is_array($j) ? $j : false;
}
function jksh_lv_save_session($uuid, $s) {
    $f = jksh_lv_stage() . '/' . $uuid . '.json';
    return false !== file_put_contents($f, json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

$uploadId = isset($_POST['upload_id']) ? (string)$_POST['upload_id'] : '';
$p = jksh_lv_parse($uploadId);
if (!$p) jksh_lv_out(false, array('message'=>'Invalid or expired large-video upload token.'), 403);

$stage = jksh_lv_stage();
if (!is_dir($stage) || !is_writable($stage)) jksh_lv_out(false, array('message'=>'Large-video staging directory is unavailable.'), 500);

$s = jksh_lv_session($p['uuid']);
if (!$s) jksh_lv_out(false, array('message'=>'Large-video session not found.'), 404);
$part = $stage . '/' . $p['uuid'] . '.part';
$mode = isset($_POST['mode']) ? (string)$_POST['mode'] : '';

if ($mode === 'finalize') {
    if (!is_file($part)) jksh_lv_out(false, array('message'=>'Large-video staging file is missing.'), 404);
    $size = filesize($part);
    if ($size === false || (int)$size !== (int)$s['bytes']) {
        jksh_lv_out(false, array('message'=>'Large-video upload is incomplete.','received_bytes'=>$size===false?0:(int)$size,'expected_bytes'=>(int)$s['bytes']), 409);
    }
    $sha = hash_file('sha256', $part);
    if (!$sha) jksh_lv_out(false, array('message'=>'Could not hash the completed large video.'), 500);
    $s['sha256'] = $sha;
    $s['ready'] = true;
    $s['finalized_at'] = time();
    if (!jksh_lv_save_session($p['uuid'], $s)) jksh_lv_out(false, array('message'=>'Could not save large-video finalization state.'), 500);
    jksh_lv_out(true, array('ok'=>true,'ready'=>true,'sha256'=>$sha,'bytes'=>(int)$size));
}

if (!isset($_FILES['chunk']) || !is_array($_FILES['chunk']) || (int)($_FILES['chunk']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    jksh_lv_out(false, array('message'=>'Missing large-video chunk.'), 400);
}
$tmp = (string)$_FILES['chunk']['tmp_name'];
$chunkSize = @filesize($tmp);
if ($chunkSize === false || $chunkSize < 1 || $chunkSize > (int)$s['max_chunk_bytes']) {
    jksh_lv_out(false, array('message'=>'Large-video chunk is invalid or too large.'), 400);
}
$offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;

$src = @fopen($tmp, 'rb');
$dst = @fopen($part, 'c+b');
if (!$src || !$dst) {
    if (is_resource($src)) fclose($src);
    if (is_resource($dst)) fclose($dst);
    jksh_lv_out(false, array('message'=>'Could not open large-video staging streams.'), 500);
}
if (!flock($dst, LOCK_EX)) {
    fclose($src); fclose($dst);
    jksh_lv_out(false, array('message'=>'Could not lock large-video staging file.'), 503);
}
fseek($dst, 0, SEEK_END);
$current = ftell($dst);
if ($current === false || (int)$current !== $offset) {
    flock($dst, LOCK_UN); fclose($src); fclose($dst);
    jksh_lv_out(false, array('message'=>'Large-video chunk offset mismatch.','next_offset'=>$current===false?null:(int)$current), 409);
}
if ($offset + $chunkSize > (int)$s['bytes']) {
    flock($dst, LOCK_UN); fclose($src); fclose($dst);
    jksh_lv_out(false, array('message'=>'Large-video chunk would exceed declared file size.'), 400);
}
$written = stream_copy_to_stream($src, $dst);
fflush($dst);
flock($dst, LOCK_UN);
fclose($src); fclose($dst);
if ($written === false || (int)$written !== (int)$chunkSize) {
    jksh_lv_out(false, array('message'=>'Large-video chunk write failed.'), 500);
}
jksh_lv_out(true, array('next_offset'=>$offset + (int)$written,'direct_transport'=>true));
