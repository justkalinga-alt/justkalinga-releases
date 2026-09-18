#!/usr/bin/env python3
from pathlib import Path
import sys

if len(sys.argv) != 2:
    raise SystemExit("usage: build_jkssb_084_story_community.py <plugin-root>")

root = Path(sys.argv[1])
php = root / "jk-social-supabase-bridge.php"
text = php.read_text()

def req(old, new, count=1):
    global text
    found = text.count(old)
    if found != count:
        raise SystemExit(f"patch target count {found} != {count}: {old[:140]!r}")
    text = text.replace(old, new, count)

req(" * Version: 0.8.3", " * Version: 0.8.4")
req("const VERSION  = '0.8.3';", "const VERSION  = '0.8.4';")

req(
    "        add_action( 'wp_ajax_jkssb_ui_save_bundle', array( $this, 'ajax_ui_save_bundle' ) );\n        add_action( 'wp_ajax_jkssb_community_mark_done', array( $this, 'ajax_community_mark_done' ) );",
    "        add_action( 'wp_ajax_jkssb_ui_save_bundle', array( $this, 'ajax_ui_save_bundle' ) );\n        add_action( 'wp_ajax_jkssb_community_prepare_bundle', array( $this, 'ajax_community_prepare_bundle' ) );\n        add_action( 'wp_ajax_jkssb_community_mark_done', array( $this, 'ajax_community_mark_done' ) );",
)

req(
    '<div><label class="jkssb-label" for="jkssb-music">Devotional audio search</label><input id="jkssb-music" class="jkssb-field" readonly><p class="jkssb-help">Saved as the recommended Instagram audio search. Meta\'s publishing API does not expose licensed-library music selection.</p></div>',
    '<div id="jkssb-music-wrap"><label class="jkssb-label" for="jkssb-music">Devotional audio search</label><input id="jkssb-music" class="jkssb-field" readonly><p class="jkssb-help">Single-image and carousel posts only. Saved as the recommended Instagram audio search because Meta\'s publishing API does not expose licensed-library music selection.</p></div>',
)

req(
    "locationEl=$('jkssb-location'),productEl=$('jkssb-product-url'),musicEl=$('jkssb-music');",
    "locationEl=$('jkssb-location'),productEl=$('jkssb-product-url'),musicEl=$('jkssb-music'),musicWrap=$('jkssb-music-wrap');",
)

req(
    "const refreshAuto=()=>{locationEl.value='JustKalinga';productEl.value=autoProduct();musicEl.value=autoMusic();selectedFiles.forEach((f,i)=>{if(!altTouched.has(f))altTexts.set(f,altFor(f,i));});renderOrder(false);};",
    "const refreshAuto=()=>{locationEl.value='JustKalinga';productEl.value=autoProduct();const imageMusic=typeEl.value==='single_post'||typeEl.value==='carousel';musicEl.value=imageMusic?autoMusic():'';if(musicWrap)musicWrap.style.display=imageMusic?'':'none';selectedFiles.forEach((f,i)=>{if(!altTouched.has(f))altTexts.set(f,altFor(f,i));});renderOrder(false);};",
)

req(
    "const renderReady=(saved,metas,coverFile)=>{ready.innerHTML='';const box=document.createElement('div');box.className='jkssb-ready-card';const h=document.createElement('h3');h.textContent='Media ready · native-field autopilot attached';box.appendChild(h);metas.forEach((m,i)=>{const p=document.createElement('p');p.textContent=(i+1)+'. '+m.filename+' · '+(m.width&&m.height?m.width+'×'+m.height+' · ':'')+(m.duration_ms?fmtDur(m.duration_ms)+' · ':'')+human(m.bytes)+(m.alt_text?' · alt ✓':'');box.appendChild(p);});if(coverFile){const p=document.createElement('p');p.textContent='Cover / thumbnail · '+coverFile.name+' · '+human(coverFile.size);box.appendChild(p);}const p=document.createElement('p');p.textContent='Location: '+saved.location+' · Music search: '+saved.music_suggestion;box.appendChild(p);const u=composeUrl(saved.bundle_id);if(u){const a=document.createElement('a');a.className='jkssb-ready-link';a.href=u;a.textContent='Use in Composer';box.appendChild(a);}ready.appendChild(box);};",
    "const prepareCommunity=async saved=>{let fd=new FormData();fd.append('action','jkssb_community_prepare_bundle');fd.append('bundle_id',saved.bundle_id);fd.append('title',titleEl.value||saved.title||'');fd.append('body',briefEl.value||saved.instructions||'');return await post(fd);};\n          const renderReady=(saved,metas,coverFile)=>{ready.innerHTML='';const box=document.createElement('div');box.className='jkssb-ready-card';const h=document.createElement('h3');h.textContent='Media ready · native-field autopilot attached';box.appendChild(h);metas.forEach((m,i)=>{const p=document.createElement('p');p.textContent=(i+1)+'. '+m.filename+' · '+(m.width&&m.height?m.width+'×'+m.height+' · ':'')+(m.duration_ms?fmtDur(m.duration_ms)+' · ':'')+human(m.bytes)+(m.alt_text?' · alt ✓':'');box.appendChild(p);});if(coverFile){const p=document.createElement('p');p.textContent='Cover / thumbnail · '+coverFile.name+' · '+human(coverFile.size);box.appendChild(p);}const p=document.createElement('p');p.textContent='Location: '+saved.location+(saved.music_suggestion?' · Music search: '+saved.music_suggestion:'');box.appendChild(p);const u=composeUrl(saved.bundle_id);if(u){const a=document.createElement('a');a.className='jkssb-ready-link';a.href=u;a.textContent='Use in Composer';box.appendChild(a);}const canCommunity=(saved.content_type==='single_post'||saved.content_type==='carousel')&&Array.isArray(saved.selected_platforms)&&saved.selected_platforms.includes('youtube');if(canCommunity){const btn=document.createElement('button');btn.type='button';btn.className='jkssb-ready-link';btn.style.marginLeft='8px';btn.textContent='Prepare YouTube Community';btn.onclick=async()=>{btn.disabled=true;btn.textContent='Preparing…';try{const out=await prepareCommunity(saved);window.location.href=out.url||<?php echo wp_json_encode( admin_url( 'admin.php?page=jkssb-youtube-community' ) ); ?>;}catch(e){setStatus(e.message||'Could not prepare YouTube Community handoff.','error');btn.disabled=false;btn.textContent='Prepare YouTube Community';}};box.appendChild(btn);}ready.appendChild(box);};",
)

req(
    "music_suggestion:musicEl.value,autopilot_native_fields:true",
    "music_suggestion:((type==='single_post'||type==='carousel')?musicEl.value:''),autopilot_native_fields:true",
)

req(
    "'music_suggestion' => sanitize_text_field( $payload['music_suggestion'] ?? 'Jai Jagannath devotional bhajan' ),",
    "'music_suggestion' => in_array( $type, array( 'single_post','carousel' ), true ) ? sanitize_text_field( $payload['music_suggestion'] ?? 'Jai Jagannath devotional bhajan' ) : '',",
)

old_auto = """        $music = sanitize_text_field( $bundle['music_suggestion'] ?? 'Jai Jagannath devotional bhajan' );
        $hashtags = $this->jkssb_extract_hashtags( $body );"""
new_auto = """        $image_music = in_array( sanitize_key( $bundle['content_type'] ?? '' ), array( 'single_post', 'carousel' ), true );
        $music = $image_music ? sanitize_text_field( $bundle['music_suggestion'] ?? 'Jai Jagannath devotional bhajan' ) : '';
        $hashtags = $this->jkssb_extract_hashtags( $body );"""
req(old_auto,new_auto)

old_ig = """        $ig_defaults = array( 'caption' => $body, 'hashtags' => $hashtags, 'location_query' => $location, 'product_url' => $product_url, 'music_suggestion' => $music, 'share_to_feed' => true, 'alt_text' => $first_alt, 'media_fields' => $media_fields );
        $fb_defaults = array( 'title' => $title, 'message' => $body, 'description' => $body, 'hashtags' => $hashtags, 'link' => $product_url, 'product_refs' => array_values( (array) ( $bundle['product_refs'] ?? array() ) ), 'place_query' => $location );"""
new_ig = """        $ig_defaults = array( 'caption' => $body, 'hashtags' => $hashtags, 'location_query' => $location, 'product_url' => $product_url, 'share_to_feed' => true, 'alt_text' => $first_alt, 'media_fields' => $media_fields );
        if ( $image_music && '' !== $music ) $ig_defaults['music_suggestion'] = $music;
        $fb_defaults = array( 'title' => $title, 'message' => $body, 'description' => $body, 'hashtags' => $hashtags, 'link' => $product_url, 'product_refs' => array_values( (array) ( $bundle['product_refs'] ?? array() ) ), 'place_query' => $location );"""
req(old_ig,new_ig)

old_merge = """        $platform_fields['instagram'] = array_replace_recursive( $ig_defaults, is_array( $platform_fields['instagram'] ?? null ) ? $platform_fields['instagram'] : array() );
        $platform_fields['facebook'] = array_replace_recursive( $fb_defaults, is_array( $platform_fields['facebook'] ?? null ) ? $platform_fields['facebook'] : array() );"""
new_merge = """        $platform_fields['instagram'] = array_replace_recursive( $ig_defaults, is_array( $platform_fields['instagram'] ?? null ) ? $platform_fields['instagram'] : array() );
        if ( ! $image_music ) unset( $platform_fields['instagram']['music_suggestion'] );
        $platform_fields['facebook'] = array_replace_recursive( $fb_defaults, is_array( $platform_fields['facebook'] ?? null ) ? $platform_fields['facebook'] : array() );"""
req(old_merge,new_merge)

marker = "    public function ajax_community_mark_done() {"
method = """    public function ajax_community_prepare_bundle() {
        $this->ui_check();
        $bundle_id = sanitize_text_field( wp_unslash( $_POST['bundle_id'] ?? '' ) );
        if ( '' === $bundle_id ) wp_send_json_error( array( 'message' => 'Missing bundle ID.' ), 400 );
        $got = $this->ability_get_upload_draft( array( 'bundle_id' => $bundle_id ) );
        if ( is_wp_error( $got ) ) wp_send_json_error( array( 'message' => $got->get_error_message() ), 422 );
        $bundle = $got['item'];
        if ( ! in_array( $bundle['content_type'] ?? '', array( 'single_post','carousel' ), true ) ) {
            wp_send_json_error( array( 'message' => 'YouTube Community handoff is only for single-image or carousel posts.' ), 422 );
        }
        if ( ! in_array( 'youtube', (array) ( $bundle['selected_platforms'] ?? array() ), true ) ) {
            wp_send_json_error( array( 'message' => 'YouTube is not selected for this bundle.' ), 422 );
        }
        $out = $this->ability_create_youtube_community_handoff( array(
            'bundle_id' => $bundle_id,
            'title' => sanitize_text_field( wp_unslash( $_POST['title'] ?? ( $bundle['title'] ?? '' ) ) ),
            'body' => sanitize_textarea_field( wp_unslash( $_POST['body'] ?? ( $bundle['instructions'] ?? '' ) ) ),
            'scheduled_at_ist' => '',
            'platform_fields' => array(),
            'cta' => 'Jai Jagannātha 🙏',
            'confirm' => true,
        ) );
        if ( is_wp_error( $out ) ) wp_send_json_error( array( 'message' => $out->get_error_message() ), 422 );
        $handoff = is_array( $out['handoff'] ?? null ) ? $out['handoff'] : array();
        $job_id = absint( $handoff['job_id'] ?? 0 );
        $url = admin_url( 'admin.php?page=jkssb-youtube-community' . ( $job_id ? '&job_id=' . $job_id : '' ) );
        wp_send_json_success( array(
            'ok' => true,
            'duplicate' => ! empty( $out['duplicate'] ),
            'job_id' => $job_id,
            'url' => $url,
        ) );
    }

"""
if marker not in text:
    raise SystemExit("community AJAX marker missing")
text = text.replace(marker, method + marker, 1)

req(
    "document.querySelectorAll('.jkssb-open-community').forEach(b=>b.onclick=async()=>{await copy(b.dataset.job);window.open(b.dataset.url,'_blank','noopener');b.textContent='Text copied · YouTube opened ✓';});",
    "document.querySelectorAll('.jkssb-open-community').forEach(b=>b.onclick=()=>{const target=b.dataset.url||'https://www.youtube.com/';let w=null;try{w=window.open('about:blank','_blank');if(w){try{w.opener=null;}catch(e){}w.location.href=target;}}catch(e){}copy(b.dataset.job).finally(()=>{if(!w)window.location.href=target;b.textContent='Text copied · YouTube opened ✓';});});",
)

req(
    "<p><strong>Prepared handoff:</strong> text is copied automatically and every original image is kept ready. YouTube does not expose an official Community-post creation/prefill API, so the final media selection remains inside YouTube.</p>",
    "<p><strong>Prepared handoff:</strong> text is copied automatically and every original image is kept ready. The button opens YouTube synchronously so mobile popup blockers do not swallow it. YouTube does not expose an official Community-post creation/prefill API, so the final media selection remains inside YouTube.</p>",
)

php.write_text(text)
print("Bridge 0.8.4 Story/music/Community patch applied")
