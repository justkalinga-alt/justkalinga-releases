<?php
/**
 * Plugin Name: JKSH YouTube Selector Guard
 * Description: Keeps YouTube selectable and selected for Reel/Short and Long Video in Social Upload, including mobile/admin duplicate renderers.
 * Version: 1.0.0
 * Author: JustKalinga
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'admin_footer', function () {
    if ( ! current_user_can( 'manage_options' ) ) { return; }
    $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
    if ( 'jksh-social-upload' !== $page ) { return; }
    ?>
    <script id="jksh-youtube-selector-guard-v100">
    (()=>{
      const syncPortal=(portal)=>{
        if(!portal) return;
        const active=portal.querySelector('.jkssb-type-btn.is-active');
        const type=(active&&active.dataset&&active.dataset.type)||portal.querySelector('#jkssb-type')?.value||'';
        const yt=portal.querySelector('.jkssb-platform[value="youtube"]');
        if(!yt) return;
        const card=yt.closest('.jkssb-platform-card');
        const video=(type==='reel'||type==='long_video'||type==='video'||type==='short');
        const imageOnly=(type==='single_post'||type==='carousel');
        if(video){
          yt.disabled=false;
          yt.checked=true;
          yt.removeAttribute('disabled');
          if(card){
            card.classList.remove('is-disabled');
            card.style.pointerEvents='auto';
            card.style.opacity='1';
            card.removeAttribute('title');
            card.setAttribute('data-jksh-youtube-ready','1');
          }
        } else if(imageOnly){
          yt.checked=false;
          yt.disabled=true;
          if(card){
            card.classList.add('is-disabled');
            card.style.pointerEvents='';
            card.style.opacity='';
            card.title='YouTube is skipped for image-only posts and carousels.';
            card.removeAttribute('data-jksh-youtube-ready');
          }
        }
      };
      const syncAll=()=>document.querySelectorAll('.jkssb-portal').forEach(syncPortal);
      const settle=(portal)=>{
        syncPortal(portal);
        requestAnimationFrame(()=>syncPortal(portal));
        setTimeout(()=>syncPortal(portal),60);
        setTimeout(()=>syncPortal(portal),250);
      };
      document.addEventListener('click',(e)=>{
        const btn=e.target.closest('.jkssb-type-btn');
        if(btn) settle(btn.closest('.jkssb-portal'));
      },true);
      document.addEventListener('change',(e)=>{
        if(e.target && e.target.id==='jkssb-files'){
          settle(e.target.closest('.jkssb-portal'));
        }
      },true);
      syncAll();
      setTimeout(syncAll,100);
      setTimeout(syncAll,500);
    })();
    </script>
    <?php
}, 99999 );