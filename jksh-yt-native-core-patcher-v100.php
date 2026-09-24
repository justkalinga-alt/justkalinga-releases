<?php
/**
 * Plugin Name: JKSH YouTube Native Core Patcher
 * Description: One-time guarded patch for both Social Upload native syncType renderers.
 * Version: 1.0.0
 * Author: JustKalinga
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class JKSH_YT_Native_Core_Patcher_V100 {
    public static function init() : void {
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
    }

    private static function bridge_file() : string {
        foreach ( array(
            WP_PLUGIN_DIR . '/jk-social-supabase-bridge-disabled/jk-social-supabase-bridge.php',
            WP_PLUGIN_DIR . '/jk-social-supabase-bridge/jk-social-supabase-bridge.php',
        ) as $file ) {
            if ( is_file( $file ) && is_readable( $file ) && is_writable( $file ) ) return $file;
        }
        return '';
    }

    public static function routes() : void {
        register_rest_route( 'jksh-yt-native-core/v1', '/apply', array(
            'methods' => 'POST',
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'callback' => function( WP_REST_Request $request ) {
                if ( true !== (bool) $request->get_param( 'confirm' ) ) {
                    return new WP_Error( 'confirm_required', 'confirm=true is required.', array( 'status' => 400 ) );
                }
                $file = self::bridge_file();
                if ( ! $file ) return new WP_Error( 'bridge_missing', 'Writable bridge file not found.', array( 'status' => 404 ) );

                $src = file_get_contents( $file );
                if ( false === $src ) return new WP_Error( 'read_failed', 'Could not read bridge file.', array( 'status' => 500 ) );

                $old = "else{if(wasDisabled)yt.checked=true;if(card){card.classList.remove('is-disabled');card.removeAttribute('title');}}";
                $new = "else{yt.checked=true;yt.removeAttribute('disabled');if(card){card.classList.remove('is-disabled');card.style.pointerEvents='auto';card.style.opacity='1';card.removeAttribute('title');}}";

                $count = substr_count( $src, $old );
                if ( 0 === $count && false !== strpos( $src, $new ) ) {
                    return rest_ensure_response( array( 'ok' => true, 'already_patched' => true, 'sha256' => hash_file( 'sha256', $file ) ) );
                }
                if ( 2 !== $count ) {
                    return new WP_Error( 'target_mismatch', 'Expected exactly 2 native syncType targets; found ' . $count . '.', array( 'status' => 409 ) );
                }

                $backup = $file . '.jkshbak-yt-sync-' . gmdate( 'YmdHis' );
                if ( ! copy( $file, $backup ) ) return new WP_Error( 'backup_failed', 'Could not create bridge backup.', array( 'status' => 500 ) );

                $patched = str_replace( $old, $new, $src, $replaced );
                if ( 2 !== $replaced ) return new WP_Error( 'replace_failed', 'Expected 2 replacements.', array( 'status' => 500 ) );
                if ( false === file_put_contents( $file, $patched, LOCK_EX ) ) return new WP_Error( 'write_failed', 'Could not write bridge file.', array( 'status' => 500 ) );

                clearstatcache( true, $file );
                return rest_ensure_response( array(
                    'ok' => true,
                    'replaced' => $replaced,
                    'backup' => basename( $backup ),
                    'sha256' => hash_file( 'sha256', $file ),
                ) );
            },
        ) );
    }
}
JKSH_YT_Native_Core_Patcher_V100::init();