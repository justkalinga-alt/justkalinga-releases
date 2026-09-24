<?php
/**
 * Plugin Name: JKSH YouTube Native Type Fix
 * Description: One-time guarded patch for native Social Upload YouTube selection on video content types.
 * Version: 2.1.0
 * Author: JustKalinga
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class JKSH_YT_Native_Patcher_V210 {
    const MARKER = 'JKSH_YT_NATIVE_TYPE_FIX_V210';

    public static function init() : void {
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
    }

    private static function bridge_file() : string {
        $candidates = array(
            WP_PLUGIN_DIR . '/jk-social-supabase-bridge-disabled/jk-social-supabase-bridge.php',
            WP_PLUGIN_DIR . '/jk-social-supabase-bridge/jk-social-supabase-bridge.php',
        );
        foreach ( $candidates as $file ) {
            if ( is_file( $file ) && is_readable( $file ) && is_writable( $file ) ) return $file;
        }
        return '';
    }

    public static function routes() : void {
        register_rest_route( 'jksh-yt-native/v2', '/status', array(
            'methods' => 'GET',
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'callback' => function() {
                $file = self::bridge_file();
                if ( ! $file ) return new WP_Error( 'bridge_missing', 'Writable JK Social Supabase Bridge file not found.', array( 'status' => 404 ) );
                $src = file_get_contents( $file );
                return rest_ensure_response( array(
                    'ok' => true,
                    'version' => '2.1.0',
                    'file' => str_replace( WP_PLUGIN_DIR . '/', '', $file ),
                    'patched' => false !== strpos( (string) $src, self::MARKER ),
                    'sha256' => hash_file( 'sha256', $file ),
                ) );
            },
        ) );

        register_rest_route( 'jksh-yt-native/v2', '/apply', array(
            'methods' => 'POST',
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'callback' => function( WP_REST_Request $request ) {
                if ( true !== (bool) $request->get_param( 'confirm' ) ) {
                    return new WP_Error( 'confirm_required', 'confirm=true is required.', array( 'status' => 400 ) );
                }
                $file = self::bridge_file();
                if ( ! $file ) return new WP_Error( 'bridge_missing', 'Writable JK Social Supabase Bridge file not found.', array( 'status' => 404 ) );
                $src = file_get_contents( $file );
                if ( false === $src ) return new WP_Error( 'read_failed', 'Could not read bridge file.', array( 'status' => 500 ) );
                if ( false !== strpos( $src, self::MARKER ) ) {
                    return rest_ensure_response( array( 'ok' => true, 'already_patched' => true, 'sha256' => hash_file( 'sha256', $file ) ) );
                }

                $old = <<<'JS'
document.querySelectorAll('.jkssb-type-btn').forEach(btn=>btn.addEventListener('click',async()=>{document.querySelectorAll('.jkssb-type-btn').forEach(x=>x.classList.remove('is-active'));btn.classList.add('is-active');typeEl.value=btn.dataset.type;await syncType();}));
JS;
                $new = <<<'JS'
document.querySelectorAll('.jkssb-type-btn').forEach(btn=>btn.addEventListener('click',async()=>{document.querySelectorAll('.jkssb-type-btn').forEach(x=>x.classList.remove('is-active'));btn.classList.add('is-active');typeEl.value=btn.dataset.type;await syncType();const yt=document.querySelector('.jkssb-platform[value="youtube"]');if(yt&&(btn.dataset.type==='reel'||btn.dataset.type==='long_video')){yt.disabled=false;yt.checked=true;const card=yt.closest('.jkssb-platform-card');if(card){card.classList.remove('is-disabled');card.style.pointerEvents='auto';card.style.opacity='1';card.removeAttribute('title');}}}));/* JKSH_YT_NATIVE_TYPE_FIX_V210 */
JS;

                $count = substr_count( $src, $old );
                if ( 1 !== $count ) {
                    return new WP_Error( 'target_mismatch', 'Expected exactly one native Social Upload type handler; found ' . $count . '.', array( 'status' => 409 ) );
                }
                $backup = $file . '.jkshbak-yt-native-' . gmdate( 'YmdHis' );
                if ( ! copy( $file, $backup ) ) return new WP_Error( 'backup_failed', 'Could not create bridge backup.', array( 'status' => 500 ) );
                $patched = str_replace( $old, $new, $src, $replaced );
                if ( 1 !== $replaced ) return new WP_Error( 'replace_failed', 'Guarded replacement failed.', array( 'status' => 500 ) );
                if ( false === file_put_contents( $file, $patched, LOCK_EX ) ) return new WP_Error( 'write_failed', 'Could not write patched bridge.', array( 'status' => 500 ) );
                clearstatcache( true, $file );
                return rest_ensure_response( array(
                    'ok' => true,
                    'message' => 'Native YouTube video-type selection patched.',
                    'backup' => basename( $backup ),
                    'sha256' => hash_file( 'sha256', $file ),
                ) );
            },
        ) );
    }
}
JKSH_YT_Native_Patcher_V210::init();
