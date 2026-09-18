#!/usr/bin/env python3
from pathlib import Path
import sys

if len(sys.argv) != 2:
    raise SystemExit("usage: build_jkssb_083_autopilot.py <plugin-root>")

root = Path(sys.argv[1])
php = root / "jk-social-supabase-bridge.php"
text = php.read_text()

def req(old, new, count=1):
    global text
    found = text.count(old)
    if found != count:
        raise SystemExit(f"patch target count {found} != {count}: {old[:100]!r}")
    text = text.replace(old, new, count)

req(" * Version: 0.8.2", " * Version: 0.8.3")
req("const VERSION  = '0.8.2';", "const VERSION  = '0.8.3';")

req(
    "    private function render_upload_app( $standalone = false ) {",
    "    private function render_upload_app_legacy( $standalone = false ) {",
)

legacy_marker = "    private function render_upload_app_legacy( $standalone = false ) {"
new_ui = r'''    private function render_upload_app( $standalone = false ) {
        $nonce = wp_create_nonce( 'jkssb_social_upload' );
        $return_url = '';
        if ( 'compose' === sanitize_key( $_GET['return'] ?? '' ) ) {
            $ref = wp_get_referer();
            if ( $ref && 0 === strpos( $ref, admin_url() ) ) $return_url = remove_query_arg( 'bundle_id', $ref );
        }
        $ajax_url = admin_url( 'admin-ajax.php' );
        $admin_upload_url = admin_url( 'admin.php?page=jksh-social-upload' );
        $portal_class = $standalone ? ' jkssb-standalone' : ' jkssb-admin-embed';
        ?>
        <style>
        :root{--jk-card:#fff;--jk-ink:#101828;--jk-muted:#667085;--jk-line:#e4e7ec;--jk-red:#b42318;--jk-red2:#d92d20;--jk-green:#067647;--jk-blue:#175cd3;--jk-shadow:0 18px 55px rgba(16,24,40,.08)}
        .jkssb-portal{font-family:Inter,ui-sans-serif,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:var(--jk-ink)}
        .jkssb-standalone{min-height:100vh;background:radial-gradient(circle at 15% 0%,rgba(217,45,32,.08),transparent 31%),linear-gradient(180deg,#fafafa,#f2f4f7);padding:clamp(14px,3vw,36px);box-sizing:border-box}.jkssb-admin-embed{max-width:1240px;margin-top:18px}.jkssb-shell{max-width:1240px;margin:0 auto}
        .jkssb-topbar{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:18px}.jkssb-brand{display:flex;align-items:center;gap:12px}.jkssb-mark{width:46px;height:46px;border-radius:15px;background:linear-gradient(145deg,#8f1711,#d92d20);display:grid;place-items:center;color:#fff;font-weight:800;box-shadow:0 8px 22px rgba(180,35,24,.22)}.jkssb-brand h1{font-size:clamp(22px,2.2vw,30px);line-height:1.1;margin:0;font-weight:780;letter-spacing:-.03em}.jkssb-brand p{margin:4px 0 0;color:var(--jk-muted);font-size:12px}.jkssb-top-actions{display:flex;gap:8px}.jkssb-pill,.jkssb-link{height:38px;border:1px solid var(--jk-line);background:#fff;border-radius:12px;padding:0 12px;display:inline-flex;align-items:center;gap:7px;font-size:12px;font-weight:650;color:#344054;text-decoration:none}.jkssb-pill:before{content:"";width:8px;height:8px;border-radius:50%;background:#12b76a;box-shadow:0 0 0 3px #d1fadf}
        .jkssb-grid{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(330px,.55fr);gap:16px;align-items:start}.jkssb-card{background:#fff;border:1px solid var(--jk-line);border-radius:20px;box-shadow:var(--jk-shadow);overflow:hidden}.jkssb-card-head{padding:17px 20px;border-bottom:1px solid var(--jk-line);display:flex;justify-content:space-between;gap:10px}.jkssb-card-head h2{font-size:16px;margin:0}.jkssb-card-head span{font-size:11px;color:var(--jk-muted)}.jkssb-card-body{padding:20px}.jkssb-section{margin-bottom:20px}.jkssb-label{display:block;font-size:12px;font-weight:720;color:#344054;margin:0 0 8px}.jkssb-help{font-size:10px;color:var(--jk-muted);line-height:1.5;margin:6px 0 0}
        .jkssb-type-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}.jkssb-type-btn{border:1px solid var(--jk-line);background:#fff;border-radius:13px;padding:11px 10px;text-align:left;cursor:pointer;color:#344054;min-height:64px}.jkssb-type-btn strong{display:block;font-size:12px}.jkssb-type-btn small{display:block;font-size:10px;color:var(--jk-muted);margin-top:3px}.jkssb-type-btn.is-active{border-color:#d92d20;background:#fff6f5;box-shadow:0 0 0 2px rgba(217,45,32,.08)}
        .jkssb-platforms{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}.jkssb-platform-card{border:1px solid var(--jk-line);border-radius:13px;padding:11px 12px;display:flex;align-items:center;gap:9px;background:#fff}.jkssb-platform-card:has(input:checked){background:#f9fafb;border-color:#98a2b3}.jkssb-platform-card input{margin:0}.jkssb-platform-card span{font-size:12px;font-weight:680}
        .jkssb-drop{position:relative;border:1.5px dashed #c7ccd4;border-radius:16px;background:linear-gradient(180deg,#fbfcfe,#f8fafc);min-height:160px;display:flex;align-items:center;justify-content:center;text-align:center;padding:20px;cursor:pointer}.jkssb-drop.is-drag,.jkssb-drop:hover{border-color:#d92d20;background:#fff7f6}.jkssb-drop input{position:absolute;inset:0;opacity:0;width:100%;height:100%;cursor:pointer}.jkssb-drop-icon{width:44px;height:44px;border:1px solid var(--jk-line);background:#fff;border-radius:14px;margin:0 auto 9px;display:grid;place-items:center;font-size:20px}.jkssb-drop strong{display:block;font-size:14px}.jkssb-drop p{margin:4px 0 0;font-size:11px;color:var(--jk-muted)}.jkssb-storage-note{display:flex;justify-content:center;gap:8px;margin-top:10px;flex-wrap:wrap}.jkssb-storage-note span{font-size:10px;background:#f2f4f7;color:#475467;padding:5px 8px;border-radius:999px}
        .jkssb-order{display:grid;gap:9px;margin-top:11px}.jkssb-file-row{display:grid;grid-template-columns:30px 64px minmax(0,1fr) auto;gap:9px;align-items:center;border:1px solid var(--jk-line);border-radius:13px;background:#fff;padding:8px}.jkssb-file-num{width:30px;height:30px;border-radius:9px;background:#f2f4f7;display:grid;place-items:center;font-size:11px;font-weight:760}.jkssb-preview{width:64px;height:64px;border-radius:10px;overflow:hidden;background:#f2f4f7;border:1px solid #eaecf0;display:grid;place-items:center}.jkssb-preview img,.jkssb-preview video{width:100%;height:100%;object-fit:cover}.jkssb-file-meta{min-width:0}.jkssb-file-name{font-size:11px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.jkssb-file-size{font-size:10px;color:var(--jk-muted);margin-top:2px}.jkssb-alt{width:100%;margin-top:6px;border:1px solid #d0d5dd;border-radius:8px;padding:6px 8px;font-size:10px;box-sizing:border-box}.jkssb-file-actions{display:flex;gap:4px;align-items:center}.jkssb-mini{border:1px solid var(--jk-line);background:#fff;border-radius:8px;min-width:30px;height:30px;padding:0 7px;cursor:pointer;color:#475467}.jkssb-mini.is-delete{color:#b42318;background:#fff6f5}.jkssb-mini:disabled{opacity:.35;cursor:default}
        .jkssb-cover-box{border:1px solid var(--jk-line);border-radius:14px;padding:13px;background:#f9fafb}.jkssb-cover-preview{display:none;align-items:center;gap:10px;margin:10px 0}.jkssb-cover-preview img{width:88px;height:88px;border-radius:12px;object-fit:cover;border:1px solid var(--jk-line)}.jkssb-cover-preview strong{font-size:11px;display:block}.jkssb-cover-preview small{font-size:10px;color:var(--jk-muted)}.jkssb-cover-box input[type=file]{font-size:11px;max-width:100%}
        .jkssb-field{width:100%;border:1px solid #d0d5dd!important;border-radius:11px!important;background:#fff!important;min-height:42px!important;padding:9px 11px!important;font-size:12px!important;box-sizing:border-box!important}.jkssb-field:focus{outline:none!important;border-color:#f04438!important;box-shadow:0 0 0 3px rgba(240,68,56,.1)!important}.jkssb-field[readonly]{background:#f9fafb!important;color:#475467}.jkssb-textarea{min-height:126px!important;resize:vertical}.jkssb-inline-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.jkssb-auto{border:1px solid #d1e9ff;background:#eff8ff;border-radius:14px;padding:12px}.jkssb-auto-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}.jkssb-auto-head strong{font-size:12px;color:#175cd3}.jkssb-auto-head span{font-size:9px;background:#d1e9ff;color:#175cd3;border-radius:999px;padding:4px 7px;font-weight:750}.jkssb-auto-grid{display:grid;gap:9px}.jkssb-toggle-row{display:flex;align-items:center;justify-content:space-between;gap:12px;border:1px solid var(--jk-line);border-radius:12px;padding:10px 12px}.jkssb-toggle-copy strong{display:block;font-size:11px}.jkssb-toggle-copy small{font-size:10px;color:var(--jk-muted)}.jkssb-switch{width:38px;height:22px;position:relative}.jkssb-switch input{opacity:0;width:0;height:0}.jkssb-slider{position:absolute;inset:0;background:#d0d5dd;border-radius:999px}.jkssb-slider:after{content:"";position:absolute;width:16px;height:16px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.2s}.jkssb-switch input:checked+.jkssb-slider{background:#12b76a}.jkssb-switch input:checked+.jkssb-slider:after{transform:translateX(16px)}
        .jkssb-submit-wrap{position:sticky;bottom:0;background:linear-gradient(180deg,rgba(255,255,255,0),#fff 22%);padding-top:20px}.jkssb-submit{width:100%;border:0;border-radius:13px;background:linear-gradient(135deg,#b42318,#d92d20);color:#fff;min-height:48px;font-size:13px;font-weight:750;cursor:pointer;box-shadow:0 10px 24px rgba(180,35,24,.2)}.jkssb-submit:disabled{opacity:.55}.jkssb-progress{height:7px;background:#f2f4f7;border-radius:999px;overflow:hidden;margin-top:12px;display:none}.jkssb-progress-fill{height:100%;width:0;background:linear-gradient(90deg,#d92d20,#f97066)}.jkssb-status{font-size:11px;color:#475467;margin-top:9px;min-height:16px;white-space:pre-wrap}.jkssb-status.is-error{color:#b42318}.jkssb-status.is-good{color:#067647;font-weight:650}.jkssb-ready-card{border:1px solid #abefc6;background:#ecfdf3;border-radius:14px;padding:14px;margin-top:14px}.jkssb-ready-card h3{margin:0 0 8px;font-size:14px;color:#05603a}.jkssb-ready-card p{font-size:10px;margin:5px 0;color:#067647}.jkssb-ready-link{display:inline-flex;margin-top:8px;padding:8px 10px;background:#067647;color:#fff!important;border-radius:9px;text-decoration:none;font-size:11px;font-weight:700}.jkssb-advanced{border:1px solid var(--jk-line);border-radius:12px;padding:0 12px;background:#fcfcfd}.jkssb-advanced summary{cursor:pointer;padding:11px 0;font-size:11px;font-weight:680;color:#475467}.jkssb-advanced p{font-size:10px;color:var(--jk-muted)}
        @media(max-width:900px){.jkssb-grid{grid-template-columns:1fr}.jkssb-type-grid{grid-template-columns:1fr 1fr}.jkssb-platforms{grid-template-columns:1fr}.jkssb-meta-card{order:-1}.jkssb-top-actions .jkssb-link{display:none}}@media(max-width:560px){.jkssb-standalone{padding:10px}.jkssb-card{border-radius:16px}.jkssb-card-body{padding:15px}.jkssb-card-head{padding:14px 15px}.jkssb-file-row{grid-template-columns:28px 54px minmax(0,1fr)}.jkssb-preview{width:54px;height:54px}.jkssb-file-actions{grid-column:2/4;justify-content:flex-end}.jkssb-inline-grid{grid-template-columns:1fr}.jkssb-topbar{align-items:flex-start}}
        </style>
        <main class="jkssb-portal<?php echo esc_attr( $portal_class ); ?>">
          <div class="jkssb-shell">
            <div class="jkssb-topbar">
              <div class="jkssb-brand"><div class="jkssb-mark">JK</div><div><h1>Social Upload Studio</h1><p>Autopilot native fields for Instagram, Facebook and YouTube.</p></div></div>
              <div class="jkssb-top-actions"><span class="jkssb-pill">IST · Autopilot</span><a class="jkssb-link" href="<?php echo esc_url( $admin_upload_url ); ?>">Admin view</a></div>
            </div>
            <div class="jkssb-grid">
              <section class="jkssb-card">
                <div class="jkssb-card-head"><h2>Media & destinations</h2><span>Preview · reorder · alt text · delete</span></div>
                <div class="jkssb-card-body">
                  <div class="jkssb-section">
                    <span class="jkssb-label">Content type</span><input id="jkssb-type" type="hidden" value="single_post">
                    <div class="jkssb-type-grid">
                      <button type="button" class="jkssb-type-btn is-active" data-type="single_post"><strong>Single post</strong><small>One image</small></button>
                      <button type="button" class="jkssb-type-btn" data-type="carousel"><strong>Carousel</strong><small>2–10 ordered images</small></button>
                      <button type="button" class="jkssb-type-btn" data-type="reel"><strong>Reel / Short</strong><small>Vertical video</small></button>
                      <button type="button" class="jkssb-type-btn" data-type="long_video"><strong>Long video</strong><small>YouTube landscape</small></button>
                    </div>
                  </div>
                  <div class="jkssb-section"><span class="jkssb-label">Publish to</span><div class="jkssb-platforms">
                    <label class="jkssb-platform-card"><input class="jkssb-platform" type="checkbox" value="instagram" checked><span>Instagram</span></label>
                    <label class="jkssb-platform-card"><input class="jkssb-platform" type="checkbox" value="facebook" checked><span>Facebook</span></label>
                    <label class="jkssb-platform-card"><input class="jkssb-platform" type="checkbox" value="youtube" checked><span>YouTube</span></label>
                  </div></div>
                  <div class="jkssb-section">
                    <span class="jkssb-label">Images / video</span>
                    <label class="jkssb-drop" id="jkssb-dropzone" for="jkssb-files"><input id="jkssb-files" type="file" multiple accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,video/webm">
                      <div><div class="jkssb-drop-icon">↑</div><strong>Drop media here or browse</strong><p>JPEG, PNG, WebP, GIF, MP4, MOV or WebM</p><div class="jkssb-storage-note"><span>≤50 MB · Supabase</span><span>&gt;50 MB · WordPress Media</span></div></div>
                    </label>
                    <div id="jkssb-order" class="jkssb-order"></div>
                  </div>
                  <div id="jkssb-cover-wrap" class="jkssb-section jkssb-cover-box" style="display:none">
                    <span class="jkssb-label">Cover / thumbnail</span>
                    <div id="jkssb-cover-preview" class="jkssb-cover-preview"><img id="jkssb-cover-img" alt=""><div><strong id="jkssb-cover-name">Automatic cover</strong><small>Used for Instagram Reel + YouTube video/Short thumbnail.</small><br><button id="jkssb-cover-remove" type="button" class="jkssb-mini is-delete" style="margin-top:6px;width:auto">Remove</button></div></div>
                    <input id="jkssb-cover" type="file" accept="image/jpeg,image/png,image/webp">
                    <p class="jkssb-help">If you do not choose one, the Studio automatically captures a frame from the video. Short/Reel covers keep the vertical frame.</p>
                  </div>
                  <details class="jkssb-advanced"><summary>Advanced / diagnostics</summary><p>The Studio generates per-image alt text, a JustKalinga location query, a relevant product-search URL, a devotional music search suggestion, and a video cover automatically. Explicit platform fields supplied later by ChatGPT can safely override these defaults.</p></details>
                </div>
              </section>
              <aside class="jkssb-card jkssb-meta-card">
                <div class="jkssb-card-head"><h2>Post brief</h2><span>Native-field autopilot</span></div>
                <div class="jkssb-card-body">
                  <div class="jkssb-section"><label class="jkssb-label" for="jkssb-title">Master title</label><input id="jkssb-title" class="jkssb-field" placeholder="Optional. ChatGPT can generate the final platform title."></div>
                  <div class="jkssb-section"><label class="jkssb-label" for="jkssb-instructions">Content idea / caption / notes</label><textarea id="jkssb-instructions" class="jkssb-field jkssb-textarea" placeholder="Paste the post idea or finished caption. Native fields below are generated automatically."></textarea></div>
                  <div class="jkssb-section jkssb-auto">
                    <div class="jkssb-auto-head"><strong>Autopilot native fields</strong><span>AUTO</span></div>
                    <div class="jkssb-auto-grid">
                      <div><label class="jkssb-label" for="jkssb-location">Location search</label><input id="jkssb-location" class="jkssb-field" value="JustKalinga" readonly><p class="jkssb-help">Instagram publisher searches Meta Places and uses the first valid result. YouTube retains this as location text because its recording-location write fields are deprecated.</p></div>
                      <div><label class="jkssb-label" for="jkssb-product-url">Product link</label><input id="jkssb-product-url" class="jkssb-field" readonly></div>
                      <div><label class="jkssb-label" for="jkssb-music">Devotional audio search</label><input id="jkssb-music" class="jkssb-field" readonly><p class="jkssb-help">Saved as the recommended Instagram audio search. Meta's publishing API does not expose licensed-library music selection.</p></div>
                    </div>
                  </div>
                  <div class="jkssb-section jkssb-inline-grid"><div><label class="jkssb-label" for="jkssb-highlight">Highlight</label><input id="jkssb-highlight" class="jkssb-field" placeholder="Optional"></div><div><label class="jkssb-label" for="jkssb-products">Specific refs</label><input id="jkssb-products" class="jkssb-field" placeholder="Optional SKU / exact product URL"></div></div>
                  <div class="jkssb-section jkssb-toggle-row"><div class="jkssb-toggle-copy"><strong>Prepare Instagram Story</strong><small>Keep a Story companion intent with this bundle.</small></div><label class="jkssb-switch"><input id="jkssb-story" type="checkbox" checked><span class="jkssb-slider"></span></label></div>
                  <div class="jkssb-submit-wrap"><button class="jkssb-submit" id="jkssb-upload" type="button">Upload media</button><div class="jkssb-progress" id="jkssb-progress"><div class="jkssb-progress-fill" id="jkssb-progress-fill"></div></div><div id="jkssb-status" class="jkssb-status"></div><div id="jkssb-ready"></div></div>
                </div>
              </aside>
            </div>
          </div>
        </main>
        <script>(()=>{
          const ajax=<?php echo wp_json_encode( $ajax_url ); ?>,nonce=<?php echo wp_json_encode( $nonce ); ?>,returnBase=<?php echo wp_json_encode( $return_url ); ?>;
          const $=id=>document.getElementById(id),status=$('jkssb-status'),ready=$('jkssb-ready'),filesEl=$('jkssb-files'),typeEl=$('jkssb-type'),coverWrap=$('jkssb-cover-wrap'),coverEl=$('jkssb-cover'),coverPreview=$('jkssb-cover-preview'),coverImg=$('jkssb-cover-img'),coverName=$('jkssb-cover-name'),orderEl=$('jkssb-order'),drop=$('jkssb-dropzone'),uploadBtn=$('jkssb-upload'),progress=$('jkssb-progress'),progressFill=$('jkssb-progress-fill'),titleEl=$('jkssb-title'),briefEl=$('jkssb-instructions'),locationEl=$('jkssb-location'),productEl=$('jkssb-product-url'),musicEl=$('jkssb-music');
          let selectedFiles=[],autoCoverFile=null,coverObjectUrl='',previewUrls=[],altTexts=new Map(),altTouched=new Set();
          const setStatus=(txt,kind='')=>{status.textContent=txt||'';status.className='jkssb-status'+(kind?' is-'+kind:'');};
          const setProgress=p=>{progress.style.display='block';progressFill.style.width=Math.max(0,Math.min(100,p||0))+'%';};
          const post=async fd=>{fd.append('nonce',nonce);const r=await fetch(ajax,{method:'POST',body:fd,credentials:'same-origin'});let j;try{j=await r.json();}catch(e){throw new Error('Server returned an unreadable response.');}if(!j.success)throw new Error((j.data&&j.data.message)||'Request failed');return j.data;};
          const human=n=>n<1024?n+' B':n<1048576?(n/1024).toFixed(1)+' KB':(n/1048576).toFixed(1)+' MB';
          const fmtDur=ms=>ms?Math.round(ms/1000)+'s':'';
          const topic=()=>((titleEl.value||briefEl.value||'JustKalinga devotional post').trim().split(/\n/)[0]||'JustKalinga devotional post').slice(0,110);
          const altFor=(f,i)=>topic()+(typeEl.value==='carousel'?' · slide '+(i+1):'')+' · JustKalinga';
          const autoProduct=()=>{const t=(titleEl.value+' '+briefEl.value).toLowerCase();let q='Jagannath';if(/dress|vastra|besha|navaratri|navratri/.test(t))q='Jagannath dress';else if(/murti|idol|vigraha/.test(t))q='Jagannath murti';else if(/kundal|mali|ornament|alankara|alankāra/.test(t))q='Jagannath ornaments';else if(/rath|chariot/.test(t))q='Rath Yatra';else if(/lamp|diya|deepa/.test(t))q='devotional lamp';else if(/oil|fragrance/.test(t))q='Jagannath devotional oil';return 'https://justkalinga.com/?s='+encodeURIComponent(q)+'&post_type=product';};
          const autoMusic=()=>{const t=(titleEl.value+' '+briefEl.value).toLowerCase();if(/mangal|arati|aarti/.test(t))return 'Jagannath Mangal Arati devotional';if(/navaratri|navratri|durga/.test(t))return 'Jagannath Durga devotional bhajan';if(/rath|chariot/.test(t))return 'Jai Jagannath Rath Yatra bhajan';if(/krishna|gita govinda/.test(t))return 'Jagannath Krishna devotional bhajan';return 'Jai Jagannath devotional bhajan';};
          const refreshAuto=()=>{locationEl.value='JustKalinga';productEl.value=autoProduct();musicEl.value=autoMusic();selectedFiles.forEach((f,i)=>{if(!altTouched.has(f))altTexts.set(f,altFor(f,i));});renderOrder(false);};
          const probe=file=>new Promise(resolve=>{const out={filename:file.name,mime_type:file.type,bytes:file.size,width:0,height:0,duration_ms:0};const u=URL.createObjectURL(file);let done=false;const finish=()=>{if(done)return;done=true;URL.revokeObjectURL(u);resolve(out);};setTimeout(finish,5000);if(file.type.startsWith('image/')){const im=new Image();im.onload=()=>{out.width=im.naturalWidth||0;out.height=im.naturalHeight||0;finish();};im.onerror=finish;im.src=u;}else if(file.type.startsWith('video/')){const v=document.createElement('video');v.preload='metadata';v.muted=true;v.onloadedmetadata=()=>{out.width=v.videoWidth||0;out.height=v.videoHeight||0;out.duration_ms=isFinite(v.duration)?Math.round(v.duration*1000):0;finish();};v.onerror=finish;v.src=u;}else finish();});
          const clearPreviewUrls=()=>{previewUrls.forEach(u=>URL.revokeObjectURL(u));previewUrls=[];};
          const renderOrder=(regen=true)=>{clearPreviewUrls();orderEl.innerHTML='';selectedFiles.forEach((f,i)=>{if(regen&&!altTouched.has(f))altTexts.set(f,altFor(f,i));const row=document.createElement('div');row.className='jkssb-file-row';const n=document.createElement('div');n.className='jkssb-file-num';n.textContent=String(i+1);const pv=document.createElement('div');pv.className='jkssb-preview';const u=URL.createObjectURL(f);previewUrls.push(u);if(f.type.startsWith('image/')){const im=document.createElement('img');im.src=u;im.alt='Preview';pv.appendChild(im);}else{const v=document.createElement('video');v.src=u;v.muted=true;v.playsInline=true;v.preload='metadata';pv.appendChild(v);}const meta=document.createElement('div');meta.className='jkssb-file-meta';const name=document.createElement('div');name.className='jkssb-file-name';name.textContent=f.name;const size=document.createElement('div');size.className='jkssb-file-size';size.textContent=human(f.size)+(typeEl.value==='carousel'?' · slide '+(i+1):'');meta.append(name,size);if(f.type.startsWith('image/')){const alt=document.createElement('input');alt.className='jkssb-alt';alt.placeholder='Alt text';alt.value=altTexts.get(f)||altFor(f,i);alt.oninput=()=>{altTouched.add(f);altTexts.set(f,alt.value);};meta.appendChild(alt);}const acts=document.createElement('div');acts.className='jkssb-file-actions';if(typeEl.value==='carousel'){const up=document.createElement('button');up.type='button';up.className='jkssb-mini';up.textContent='↑';up.disabled=i===0;up.onclick=()=>{[selectedFiles[i-1],selectedFiles[i]]=[selectedFiles[i],selectedFiles[i-1]];renderOrder();};const down=document.createElement('button');down.type='button';down.className='jkssb-mini';down.textContent='↓';down.disabled=i===selectedFiles.length-1;down.onclick=()=>{[selectedFiles[i+1],selectedFiles[i]]=[selectedFiles[i],selectedFiles[i+1]];renderOrder();};acts.append(up,down);}const del=document.createElement('button');del.type='button';del.className='jkssb-mini is-delete';del.textContent='×';del.title='Remove media';del.onclick=()=>{const removed=selectedFiles.splice(i,1)[0];altTexts.delete(removed);altTouched.delete(removed);renderOrder();if(!selectedFiles.length){autoCoverFile=null;renderCover();}};acts.appendChild(del);row.append(n,pv,meta,acts);orderEl.appendChild(row);});};
          const renderCover=()=>{if(coverObjectUrl){URL.revokeObjectURL(coverObjectUrl);coverObjectUrl='';}const f=(coverEl.files&&coverEl.files[0])||autoCoverFile;if(!f){coverPreview.style.display='none';coverImg.removeAttribute('src');return;}coverObjectUrl=URL.createObjectURL(f);coverImg.src=coverObjectUrl;coverName.textContent=(coverEl.files&&coverEl.files[0])?f.name:'Automatic frame · '+f.name;coverPreview.style.display='flex';};
          const autoCover=async file=>{if(!file||!file.type.startsWith('video/'))return null;return await new Promise(resolve=>{const url=URL.createObjectURL(file),v=document.createElement('video');let done=false;const finish=x=>{if(done)return;done=true;URL.revokeObjectURL(url);resolve(x);};v.muted=true;v.playsInline=true;v.preload='auto';v.onloadedmetadata=()=>{try{v.currentTime=Math.min(Math.max(.15,(isFinite(v.duration)?v.duration*.12:.5)),2);}catch(e){v.currentTime=.1;}};v.onseeked=()=>{try{const c=document.createElement('canvas');c.width=v.videoWidth||1080;c.height=v.videoHeight||1920;const ctx=c.getContext('2d');ctx.drawImage(v,0,0,c.width,c.height);c.toBlob(blob=>{if(!blob)return finish(null);const base=(file.name||'video').replace(/\.[^.]+$/,'');finish(new File([blob],base+'-auto-cover.jpg',{type:'image/jpeg',lastModified:Date.now()}));},'image/jpeg',.92);}catch(e){finish(null);}};v.onerror=()=>finish(null);setTimeout(()=>finish(null),8000);v.src=url;v.load();});};
          const syncType=async()=>{const v=typeEl.value;coverWrap.style.display=(v==='reel'||v==='long_video')?'block':'none';if(v!=='carousel'&&selectedFiles.length>1)selectedFiles=[selectedFiles[0]];if((v==='reel'||v==='long_video')&&selectedFiles[0]?.type.startsWith('video/')&&!(coverEl.files&&coverEl.files[0])){setStatus('Preparing automatic video cover…');autoCoverFile=await autoCover(selectedFiles[0]);renderCover();setStatus('');}else if(v!=='reel'&&v!=='long_video'){autoCoverFile=null;renderCover();}renderOrder();refreshAuto();};
          document.querySelectorAll('.jkssb-type-btn').forEach(btn=>btn.addEventListener('click',async()=>{document.querySelectorAll('.jkssb-type-btn').forEach(x=>x.classList.remove('is-active'));btn.classList.add('is-active');typeEl.value=btn.dataset.type;await syncType();}));
          filesEl.addEventListener('change',async()=>{selectedFiles=[...filesEl.files];await syncType();});
          coverEl.addEventListener('change',renderCover);$('jkssb-cover-remove').onclick=()=>{coverEl.value='';autoCoverFile=null;renderCover();};
          ['dragenter','dragover'].forEach(ev=>drop.addEventListener(ev,e=>{e.preventDefault();drop.classList.add('is-drag');}));['dragleave','drop'].forEach(ev=>drop.addEventListener(ev,e=>{e.preventDefault();drop.classList.remove('is-drag');}));drop.addEventListener('drop',async e=>{const fs=[...(e.dataTransfer?.files||[])];if(!fs.length)return;selectedFiles=typeEl.value==='carousel'?fs:fs.slice(0,1);await syncType();});
          titleEl.addEventListener('input',refreshAuto);briefEl.addEventListener('input',refreshAuto);
          async function uploadFile(file,index,total,meta,label='Media'){setStatus('Uploading '+label+' '+(index+1)+'/'+total+': '+file.name);let fd=new FormData();fd.append('action','jkssb_ui_begin');fd.append('filename',file.name);fd.append('mime_type',file.type);fd.append('bytes',file.size);fd.append('width',meta.width||0);fd.append('height',meta.height||0);fd.append('duration_ms',meta.duration_ms||0);const b=await post(fd);const target=b.storage_target==='wordpress_media'?'WordPress Media':'Supabase';let off=0,chunk=b.max_chunk_bytes||4194304;while(off<file.size){const blob=file.slice(off,Math.min(file.size,off+chunk));fd=new FormData();fd.append('action','jkssb_ui_chunk');fd.append('upload_id',b.upload_id);fd.append('offset',String(off));fd.append('chunk',blob,file.name+'.part');const x=await post(fd);off=x.next_offset;const filePct=off/file.size;setProgress(((index+filePct)/total)*100);setStatus('Uploading '+label+' to '+target+' · '+Math.round(filePct*100)+'%');}fd=new FormData();fd.append('action','jkssb_ui_finalize');fd.append('upload_id',b.upload_id);return await post(fd);}
          const composeUrl=bundle=>{if(!returnBase)return'';try{const u=new URL(returnBase,window.location.href);if(u.origin!==window.location.origin||u.pathname===window.location.pathname)return'';u.searchParams.set('bundle_id',bundle);return u.toString();}catch(e){return'';}};
          const renderReady=(saved,metas,coverFile)=>{ready.innerHTML='';const box=document.createElement('div');box.className='jkssb-ready-card';const h=document.createElement('h3');h.textContent='Media ready · native-field autopilot attached';box.appendChild(h);metas.forEach((m,i)=>{const p=document.createElement('p');p.textContent=(i+1)+'. '+m.filename+' · '+(m.width&&m.height?m.width+'×'+m.height+' · ':'')+(m.duration_ms?fmtDur(m.duration_ms)+' · ':'')+human(m.bytes)+(m.alt_text?' · alt ✓':'');box.appendChild(p);});if(coverFile){const p=document.createElement('p');p.textContent='Cover / thumbnail · '+coverFile.name+' · '+human(coverFile.size);box.appendChild(p);}const p=document.createElement('p');p.textContent='Location: '+saved.location+' · Music search: '+saved.music_suggestion;box.appendChild(p);const u=composeUrl(saved.bundle_id);if(u){const a=document.createElement('a');a.className='jkssb-ready-link';a.href=u;a.textContent='Use in Composer';box.appendChild(a);}ready.appendChild(box);};
          uploadBtn.onclick=async()=>{const files=selectedFiles;ready.innerHTML='';progress.style.display='none';progressFill.style.width='0';if(!files.length){setStatus('Choose at least one file.','error');return;}const oversize=files.find(f=>f.size>524288000);if(oversize){setStatus(oversize.name+' exceeds 500 MB.','error');return;}const type=typeEl.value;if(type==='carousel'&&(files.length<2||files.length>10)){setStatus('Carousel requires 2–10 images.','error');return;}if(type!=='carousel'&&files.length!==1){setStatus('This content type requires one main file.','error');return;}if((type==='single_post'||type==='carousel')&&files.some(f=>!f.type.startsWith('image/'))){setStatus('Image posts and carousels require images only.','error');return;}if((type==='reel'||type==='long_video')&&!files[0].type.startsWith('video/')){setStatus('Reel / Long Video requires a video file.','error');return;}const platforms=[...document.querySelectorAll('.jkssb-platform:checked')].map(x=>x.value);if(!platforms.length){setStatus('Choose at least one platform.','error');return;}uploadBtn.disabled=true;uploadBtn.textContent='Uploading…';try{const metas=[],ids=[];setProgress(1);for(let i=0;i<files.length;i++){const meta=await probe(files[i]);meta.alt_text=files[i].type.startsWith('image/')?(altTexts.get(files[i])||altFor(files[i],i)):'';const r=await uploadFile(files[i],i,files.length,meta);const id=r.attachment_id||r.external_attachment_id||(r.shell&&r.shell.attachment_id);meta.attachment_id=id;meta.storage_source=r.storage||((r.asset&&r.asset.id)?'supabase':'wordpress_media');metas.push(meta);ids.push(id);}let coverFile=(coverEl.files&&coverEl.files[0])||autoCoverFile,coverId=0,coverMeta={};if((type==='reel'||type==='long_video')&&!coverFile){coverFile=await autoCover(files[0]);autoCoverFile=coverFile;renderCover();}if(coverFile){coverMeta=await probe(coverFile);coverMeta.alt_text=topic()+' · cover · JustKalinga';const cr=await uploadFile(coverFile,0,1,coverMeta,'Cover');coverId=cr.attachment_id||cr.external_attachment_id||(cr.shell&&cr.shell.attachment_id)||0;coverMeta.storage_source=cr.storage||((cr.asset&&cr.asset.id)?'supabase':'wordpress_media');}refreshAuto();const refs=$('jkssb-products').value.split(',').map(x=>x.trim()).filter(Boolean);refs.unshift(productEl.value);const p={content_type:type,title:titleEl.value,instructions:briefEl.value,add_to_story:$('jkssb-story').checked,highlight_name:$('jkssb-highlight').value,location:locationEl.value,product_url:productEl.value,product_refs:[...new Set(refs.filter(Boolean))],music_suggestion:musicEl.value,autopilot_native_fields:true,attachment_ids:ids,media_meta:metas,selected_platforms:platforms,cover_attachment_id:coverId,cover_meta:coverMeta};let fd=new FormData();fd.append('action','jkssb_ui_save_bundle');fd.append('payload',JSON.stringify(p));const saved=await post(fd);setProgress(100);setStatus('Media ready. Autopilot fields saved.','good');renderReady(saved,metas,coverFile);}catch(err){setStatus(err.message||'Upload failed.','error');}finally{uploadBtn.disabled=false;uploadBtn.textContent='Upload media';}};
          refreshAuto();renderOrder();renderCover();
        })();</script>
        <?php
    }

'''
if legacy_marker not in text:
    raise SystemExit("legacy render marker missing")
text = text.replace(legacy_marker, new_ui + legacy_marker, 1)

req(
"""                'duration_ms' => absint( $m['duration_ms'] ?? 0 ),
                'storage_source' => in_array( sanitize_key( $m['storage_source'] ?? '' ), array( 'supabase','wordpress_media' ), true ) ? sanitize_key( $m['storage_source'] ) : '',
            );""",
"""                'duration_ms' => absint( $m['duration_ms'] ?? 0 ),
                'alt_text' => sanitize_text_field( $m['alt_text'] ?? '' ),
                'storage_source' => in_array( sanitize_key( $m['storage_source'] ?? '' ), array( 'supabase','wordpress_media' ), true ) ? sanitize_key( $m['storage_source'] ) : '',
            );
            $alt_id = absint( $m['attachment_id'] ?? ( $ids[$i] ?? 0 ) );
            if ( $alt_id && ! empty( $m['alt_text'] ) ) update_post_meta( $alt_id, '_wp_attachment_image_alt', sanitize_text_field( $m['alt_text'] ) );""",
)

req(
"""            'instructions' => sanitize_textarea_field( $payload['instructions'] ?? '' ), 'title' => sanitize_text_field( $payload['title'] ?? '' ),
            'add_to_story' => ! empty( $payload['add_to_story'] ), 'highlight_name' => sanitize_text_field( $payload['highlight_name'] ?? '' ),
            'location' => sanitize_text_field( $payload['location'] ?? '' ),
            'product_refs' => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $payload['product_refs'] ?? array() ) ) ),
            'created_at_utc' => gmdate( 'c' ), 'status' => 'uploaded',""",
"""            'instructions' => sanitize_textarea_field( $payload['instructions'] ?? '' ), 'title' => sanitize_text_field( $payload['title'] ?? '' ),
            'add_to_story' => ! empty( $payload['add_to_story'] ), 'highlight_name' => sanitize_text_field( $payload['highlight_name'] ?? '' ),
            'location' => sanitize_text_field( $payload['location'] ?? 'JustKalinga' ) ?: 'JustKalinga',
            'product_url' => esc_url_raw( $payload['product_url'] ?? 'https://justkalinga.com/' ),
            'product_refs' => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $payload['product_refs'] ?? array() ) ) ) ),
            'music_suggestion' => sanitize_text_field( $payload['music_suggestion'] ?? 'Jai Jagannath devotional bhajan' ),
            'autopilot_native_fields' => ! empty( $payload['autopilot_native_fields'] ),
            'created_at_utc' => gmdate( 'c' ), 'status' => 'uploaded',""",
)

queue_marker = "    public function ability_queue_upload_draft( $input ) {"
helpers = r'''    private function jkssb_extract_hashtags( $text ) {
        preg_match_all( '/#[\p{L}\p{N}_]+/u', (string) $text, $m );
        return isset( $m[0] ) ? implode( ' ', array_slice( array_values( array_unique( $m[0] ) ), 0, 30 ) ) : '';
    }

    private function jkssb_youtube_tags( $title, $body ) {
        $out = array();
        preg_match_all( '/#([\p{L}\p{N}_]+)/u', (string) $body, $m );
        foreach ( (array) ( $m[1] ?? array() ) as $tag ) {
            $tag = sanitize_text_field( str_replace( '_', ' ', $tag ) );
            if ( $tag && ! in_array( $tag, $out, true ) ) $out[] = $tag;
        }
        foreach ( preg_split( '/[^\p{L}\p{N}]+/u', (string) $title ) as $word ) {
            $word = sanitize_text_field( trim( $word ) );
            $len = function_exists( 'mb_strlen' ) ? mb_strlen( $word ) : strlen( $word );
            if ( $len >= 4 && ! in_array( $word, $out, true ) ) $out[] = $word;
        }
        foreach ( array( 'Jagannath', 'JustKalinga', 'Puri' ) as $tag ) if ( ! in_array( $tag, $out, true ) ) $out[] = $tag;
        return array_slice( $out, 0, 20 );
    }

    private function jkssb_apply_autopilot_fields( $bundle, $platform_fields, $title, $body ) {
        $location = sanitize_text_field( $bundle['location'] ?? 'JustKalinga' );
        if ( '' === $location ) $location = 'JustKalinga';
        $product_url = esc_url_raw( $bundle['product_url'] ?? 'https://justkalinga.com/' );
        if ( ! $product_url ) $product_url = 'https://justkalinga.com/';
        $music = sanitize_text_field( $bundle['music_suggestion'] ?? 'Jai Jagannath devotional bhajan' );
        $hashtags = $this->jkssb_extract_hashtags( $body );
        $media_fields = array();
        foreach ( array_values( (array) ( $bundle['media_meta'] ?? array() ) ) as $i => $m ) {
            if ( ! is_array( $m ) ) continue;
            $alt = sanitize_text_field( $m['alt_text'] ?? '' );
            if ( '' === $alt ) $alt = sanitize_text_field( $title . ( 'carousel' === ( $bundle['content_type'] ?? '' ) ? ' · slide ' . ( $i + 1 ) : '' ) . ' · JustKalinga' );
            $id = absint( $m['attachment_id'] ?? 0 );
            $media_fields[ (string) $i ] = array( 'alt_text' => $alt );
            if ( $id ) $media_fields[ (string) $id ] = array( 'alt_text' => $alt );
        }
        $first_alt = isset( $media_fields['0']['alt_text'] ) ? $media_fields['0']['alt_text'] : '';
        $ig_defaults = array( 'caption' => $body, 'hashtags' => $hashtags, 'location_query' => $location, 'product_url' => $product_url, 'music_suggestion' => $music, 'share_to_feed' => true, 'alt_text' => $first_alt, 'media_fields' => $media_fields );
        $fb_defaults = array( 'title' => $title, 'message' => $body, 'description' => $body, 'hashtags' => $hashtags, 'link' => $product_url, 'product_refs' => array_values( (array) ( $bundle['product_refs'] ?? array() ) ), 'place_query' => $location );
        $yt_defaults = array( 'title' => $title, 'description' => $body, 'tags' => $this->jkssb_youtube_tags( $title, $body ), 'category_id' => '22', 'default_language' => 'en', 'privacy_status' => 'public', 'embeddable' => true, 'license' => 'youtube', 'public_stats_viewable' => true, 'notify_subscribers' => false, 'product_url' => $product_url, 'location_text' => $location );
        $platform_fields = is_array( $platform_fields ) ? $platform_fields : array();
        $platform_fields['instagram'] = array_replace_recursive( $ig_defaults, is_array( $platform_fields['instagram'] ?? null ) ? $platform_fields['instagram'] : array() );
        $platform_fields['facebook'] = array_replace_recursive( $fb_defaults, is_array( $platform_fields['facebook'] ?? null ) ? $platform_fields['facebook'] : array() );
        $platform_fields['youtube'] = array_replace_recursive( $yt_defaults, is_array( $platform_fields['youtube'] ?? null ) ? $platform_fields['youtube'] : array() );
        return $platform_fields;
    }

    private function jkssb_bundle_location( $bundle, $input ) {
        if ( is_array( $input['location'] ?? null ) && ! empty( $input['location'] ) ) return $input['location'];
        $q = sanitize_text_field( $bundle['location'] ?? 'JustKalinga' );
        if ( '' === $q ) $q = 'JustKalinga';
        return array( 'query' => $q, 'label' => $q );
    }

    private function jkssb_bundle_products( $bundle, $input ) {
        if ( is_array( $input['products'] ?? null ) && ! empty( $input['products'] ) ) return $input['products'];
        $out = array();
        $url = esc_url_raw( $bundle['product_url'] ?? '' );
        if ( $url ) $out[] = array( 'url' => $url, 'source' => 'autopilot' );
        foreach ( array_values( (array) ( $bundle['product_refs'] ?? array() ) ) as $ref ) {
            $ref = sanitize_text_field( $ref );
            if ( $ref && $ref !== $url ) $out[] = array( 'reference' => $ref );
        }
        return $out;
    }

'''
if queue_marker not in text:
    raise SystemExit("queue marker missing")
text = text.replace(queue_marker, helpers + queue_marker, 1)

req(
"""        $platform_fields = is_array( $input['platform_fields'] ?? null ) ? $input['platform_fields'] : array();
        $cover_id = absint( $b['cover_attachment_id'] ?? 0 );""",
"""        $platform_fields = is_array( $input['platform_fields'] ?? null ) ? $input['platform_fields'] : array();
        $platform_fields = $this->jkssb_apply_autopilot_fields( $b, $platform_fields, $title, $body );
        $cover_id = absint( $b['cover_attachment_id'] ?? 0 );""",
)

req(
"""            $req = array( 'confirm_live' => true, 'request_id' => 'upload-' . sanitize_key( $b['bundle_id'] ) . '-' . gmdate( 'YmdHis' ), 'title' => $title, 'body' => $body, 'content_type' => $ct, 'platforms' => $live_platforms, 'scheduled_at_utc' => $scheduled_utc, 'media_attachment_ids' => $ids, 'platform_fields' => $platform_fields, 'location' => is_array( $input['location'] ?? null ) ? $input['location'] : array(), 'products' => is_array( $input['products'] ?? null ) ? $input['products'] : array(), 'cta' => sanitize_text_field( $input['cta'] ?? 'Jai Jagannātha 🙏' ) );""",
"""            $req = array( 'confirm_live' => true, 'request_id' => 'upload-' . sanitize_key( $b['bundle_id'] ) . '-' . gmdate( 'YmdHis' ), 'title' => $title, 'body' => $body, 'content_type' => $ct, 'platforms' => $live_platforms, 'scheduled_at_utc' => $scheduled_utc, 'media_attachment_ids' => $ids, 'platform_fields' => $platform_fields, 'location' => $this->jkssb_bundle_location( $b, $input ), 'products' => $this->jkssb_bundle_products( $b, $input ), 'cta' => sanitize_text_field( $input['cta'] ?? 'Jai Jagannātha 🙏' ) );""",
)

req(
"""        $yt_fields = isset( $input['platform_fields']['youtube'] ) && is_array( $input['platform_fields']['youtube'] ) ? $this->sanitize_live_value( $input['platform_fields']['youtube'] ) : array();
        $hashtags = isset( $yt_fields['hashtags'] ) ? sanitize_text_field( $yt_fields['hashtags'] ) : '';""",
"""        $yt_fields = isset( $input['platform_fields']['youtube'] ) && is_array( $input['platform_fields']['youtube'] ) ? $this->sanitize_live_value( $input['platform_fields']['youtube'] ) : array();
        $auto_fields = $this->jkssb_apply_autopilot_fields( $bundle, array( 'youtube' => $yt_fields ), $title, $body );
        $yt_fields = isset( $auto_fields['youtube'] ) ? $auto_fields['youtube'] : $yt_fields;
        $hashtags = isset( $yt_fields['hashtags'] ) ? sanitize_text_field( $yt_fields['hashtags'] ) : '';""",
)

start = text.find("    public function render_community_page() {")
end = text.find("\n    public function render_live_supervisor_page()", start)
if start < 0 or end < 0:
    raise SystemExit("community page boundaries missing")
community = r'''    public function render_community_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $data = $this->ability_list_youtube_community_handoffs();
        $nonce = wp_create_nonce( 'jkssb_community' );
        echo '<div class="wrap"><h1>YouTube Community Handoff</h1><p><strong>Prepared handoff:</strong> text is copied automatically and every original image is kept ready. YouTube does not expose an official Community-post creation/prefill API, so the final media selection remains inside YouTube.</p>';
        if ( empty( $data['items'] ) ) { echo '<div class="notice notice-info"><p>No Community handoffs yet. Queue an image or carousel with YouTube selected.</p></div></div>'; return; }
        foreach ( $data['items'] as $item ) {
            $posted = 'completed' === $item['status'];
            $fields = is_array( $item['fields'] ?? null ) ? $item['fields'] : array();
            $location = sanitize_text_field( $fields['location_text'] ?? 'JustKalinga' );
            $product = esc_url_raw( $fields['product_url'] ?? 'https://justkalinga.com/' );
            $full = trim( (string) $item['title'] . "\n\n" . (string) $item['body'] );
            if ( $location && false === stripos( $full, $location ) ) $full .= "\n\n📍 " . $location;
            if ( $product && false === strpos( $full, $product ) ) $full .= "\n🔗 " . $product;
            echo '<div class="card" style="max-width:1000px;margin:16px 0;padding:16px;border-radius:14px"><h2>' . esc_html( $item['title'] ) . '</h2><p><strong>Status:</strong> ' . esc_html( $item['status'] ) . ' &nbsp; <strong>Scheduled IST:</strong> ' . esc_html( $item['scheduled_at_ist'] ?: 'Not set' ) . '</p>';
            echo '<textarea id="jkssb-community-text-' . absint( $item['job_id'] ) . '" rows="10" style="width:100%" readonly>' . esc_textarea( $full ) . '</textarea>';
            echo '<div class="jkssb-community-media" data-job="' . absint( $item['job_id'] ) . '" style="display:flex;flex-wrap:wrap;gap:8px;margin:12px 0">';
            foreach ( $item['media'] as $index => $media ) {
                if ( empty( $media['url'] ) || 0 !== strpos( (string) $media['mime_type'], 'image/' ) ) continue;
                echo '<a href="' . esc_url( $media['url'] ) . '" target="_blank" rel="noopener" data-media-url="' . esc_url( $media['url'] ) . '" data-media-name="jk-community-' . absint( $item['job_id'] ) . '-' . ( absint( $index ) + 1 ) . '.jpg" style="display:block"><img src="' . esc_url( $media['url'] ) . '" alt="' . esc_attr( $item['title'] . ' slide ' . ( $index + 1 ) ) . '" style="width:120px;height:120px;object-fit:cover;border-radius:8px;border:1px solid #ddd"></a>';
            }
            echo '</div><p><button class="button jkssb-copy-community" data-job="' . absint( $item['job_id'] ) . '">Copy prepared text</button> <button class="button jkssb-download-community" data-job="' . absint( $item['job_id'] ) . '">Download media</button> <button class="button button-primary jkssb-open-community" data-job="' . absint( $item['job_id'] ) . '" data-url="' . esc_url( $item['youtube_community_url'] ) . '">Prepare + open YouTube</button>';
            if ( ! $posted ) echo ' <button class="button jkssb-mark-community" data-job="' . absint( $item['job_id'] ) . '">Mark posted</button>';
            else echo ' <span style="color:#008a20;font-weight:600">✓ Posted receipt recorded</span>';
            echo '</p><p style="color:#667085;font-size:12px">The Prepare button copies the full post text before opening YouTube. Media buttons keep the original images one tap away. This is the maximum supported flow without pretending YouTube has a Community creation API.</p></div>';
        }
        ?><script>(()=>{const nonce=<?php echo wp_json_encode( $nonce ); ?>;
        const copy=async job=>{const t=document.getElementById('jkssb-community-text-'+job);if(!t)return;try{await navigator.clipboard.writeText(t.value);}catch(e){t.focus();t.select();document.execCommand('copy');}};
        const download=async job=>{const box=document.querySelector('.jkssb-community-media[data-job="'+job+'"]');if(!box)return;const links=[...box.querySelectorAll('[data-media-url]')];for(const a of links){try{const r=await fetch(a.dataset.mediaUrl,{credentials:'omit'});if(!r.ok)throw new Error('fetch');const blob=await r.blob();const u=URL.createObjectURL(blob),x=document.createElement('a');x.href=u;x.download=a.dataset.mediaName||'jk-community.jpg';document.body.appendChild(x);x.click();x.remove();setTimeout(()=>URL.revokeObjectURL(u),1500);}catch(e){window.open(a.dataset.mediaUrl,'_blank','noopener');}await new Promise(res=>setTimeout(res,250));}};
        document.querySelectorAll('.jkssb-copy-community').forEach(b=>b.onclick=async()=>{await copy(b.dataset.job);b.textContent='Copied ✓';});
        document.querySelectorAll('.jkssb-download-community').forEach(b=>b.onclick=async()=>{b.disabled=true;b.textContent='Preparing…';await download(b.dataset.job);b.textContent='Media ready ✓';b.disabled=false;});
        document.querySelectorAll('.jkssb-open-community').forEach(b=>b.onclick=async()=>{await copy(b.dataset.job);window.open(b.dataset.url,'_blank','noopener');b.textContent='Text copied · YouTube opened ✓';});
        document.querySelectorAll('.jkssb-mark-community').forEach(b=>b.onclick=async()=>{const url=prompt('Paste the public YouTube Community post URL if available, or leave blank:','')||'';const fd=new FormData();fd.append('action','jkssb_community_mark_done');fd.append('nonce',nonce);fd.append('job_id',b.dataset.job);fd.append('external_url',url);const r=await fetch(ajaxurl,{method:'POST',body:fd,credentials:'same-origin'});const j=await r.json();if(!j.success){alert((j.data&&j.data.message)||'Could not mark posted');return;}location.reload();});})();</script><?php
        echo '</div>';
    }
'''
text = text[:start] + community + text[end:]

php.write_text(text)
print("Bridge 0.8.3 autopilot patch applied")
