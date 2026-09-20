from pathlib import Path

root = Path("build/jk-social-hub")
main = root / "jk-social-hub.php"
lib = root / "includes/class-jksh-content-library-v091.php"

s = main.read_text()
pairs = [
    (" * Version:     0.13.17", " * Version:     0.13.18"),
    ("define( 'JKSH_VERSION', '0.13.17' );", "define( 'JKSH_VERSION', '0.13.18' );"),
    (
        " * Description: Internal social-media operating system for JustKalinga. v0.13.17 marks Pinterest Standard access as pending and locks production publishing until Pinterest approval.",
        " * Description: Internal social-media operating system for JustKalinga. v0.13.18 surfaces Pinterest Sandbox Published receipts, Pin IDs and Open links in Content Library."
    ),
]
for old,new in pairs:
    if old not in s:
        raise SystemExit(f"Missing main pattern: {old}")
    s=s.replace(old,new,1)
main.write_text(s)

s = lib.read_text()

old = "$published = false; $sandbox_published = false; $queued = false; $failed = false; $cancelled = false; $community = false;"
new = "$published = false; $sandbox_published = false; $sandbox_pin_id = ''; $sandbox_pin_url = ''; $sandbox_board_name = ''; $queued = false; $failed = false; $cancelled = false; $community = false;"
if old not in s:
    raise SystemExit("Missing state init")
s=s.replace(old,new,1)

old = """            $vf = json_decode((string)($v['fields_json'] ?? '{}'), true);
            if ($s === 'published' && is_array($vf) && !empty($vf['sandbox_published'])) $sandbox_published = true;"""
new = """            $vf = json_decode((string)($v['fields_json'] ?? '{}'), true);
            if ($s === 'published' && is_array($vf) && !empty($vf['sandbox_published'])) {
                $sandbox_published = true;
                if (!$sandbox_pin_id) $sandbox_pin_id = sanitize_text_field((string)($vf['sandbox_pin_id'] ?? $v['external_id'] ?? ''));
                if (!$sandbox_pin_url) $sandbox_pin_url = esc_url_raw((string)($vf['sandbox_pin_url'] ?? $v['external_url'] ?? ''));
                if (!$sandbox_board_name) $sandbox_board_name = sanitize_text_field((string)($vf['sandbox_board_name'] ?? ''));
            }"""
if old not in s:
    raise SystemExit("Missing variant sandbox detection")
s=s.replace(old,new,1)

old = """        foreach ($jobs as $j) {
            $s = strtolower((string)$j['status']);
            if ($s === 'published') $published = true;"""
new = """        foreach ($jobs as $j) {
            $s = strtolower((string)$j['status']);
            if ($s === 'published') $published = true;
            $jr = json_decode((string)($j['last_response_json'] ?? '{}'), true);
            if ($s === 'published' && is_array($jr) && !empty($jr['sandbox_published'])) {
                $sandbox_published = true;
                if (!$sandbox_pin_id) $sandbox_pin_id = sanitize_text_field((string)($jr['sandbox_pin_id'] ?? $jr['id'] ?? ''));
                if (!$sandbox_pin_url) $sandbox_pin_url = esc_url_raw((string)($jr['sandbox_pin_url'] ?? ''));
            }"""
if old not in s:
    raise SystemExit("Missing jobs loop")
s=s.replace(old,new,1)

old = """            'created_sort' => $created, 'next_schedule' => $next, 'bundle' => $bundle, 'content' => $content,
            'variants' => $variants, 'jobs' => $jobs, 'receipt' => $receipt,"""
new = """            'created_sort' => $created, 'next_schedule' => $next, 'bundle' => $bundle, 'content' => $content,
            'sandbox_pin_id' => $sandbox_pin_id, 'sandbox_pin_url' => $sandbox_pin_url, 'sandbox_board_name' => $sandbox_board_name,
            'variants' => $variants, 'jobs' => $jobs, 'receipt' => $receipt,"""
if old not in s:
    raise SystemExit("Missing normalize return")
s=s.replace(old,new,1)

old = """            echo '<td data-label="Workflow"><span class="badge state-'.$statecls.'">'.esc_html($i['state']).'</span></td>';"""
new = """            $workflow='<span class="badge state-'.$statecls.'">'.esc_html($i['state']).'</span>';
            if(!empty($i['sandbox_pin_id'])){
                $workflow.='<small style="display:block;margin-top:6px;font-weight:600;">Pin ID: '.esc_html($i['sandbox_pin_id']).'</small>';
                if(!empty($i['sandbox_pin_url'])) $workflow.='<small style="display:block;margin-top:3px;"><a target="_blank" rel="noopener" href="'.esc_url($i['sandbox_pin_url']).'">Open Pin ↗</a></small>';
                if(!empty($i['sandbox_board_name'])) $workflow.='<small style="display:block;margin-top:3px;color:#667085;">'.esc_html($i['sandbox_board_name']).'</small>';
            }
            echo '<td data-label="Workflow">'.$workflow.'</td>';"""
if old not in s:
    raise SystemExit("Missing workflow cell")
s=s.replace(old,new,1)

old = "foreach(['instagram'=>'Instagram','facebook'=>'Facebook','youtube'=>'YouTube'] as $k=>$v)"
new = "foreach(['instagram'=>'Instagram','facebook'=>'Facebook','youtube'=>'YouTube','threads'=>'Threads','pinterest'=>'Pinterest'] as $k=>$v)"
if old in s:
    s=s.replace(old,new,1)

lib.write_text(s)
