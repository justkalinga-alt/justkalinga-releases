<?php
/**
 * Plugin Name: JKSH Current Error Repair
 * Description: One-time guarded repair for Content 108 Threads/YouTube jobs plus future video storage routing.
 * Version: 1.0.0
 * Author: JustKalinga
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class JKSH_Current_Error_Repair_V100 {
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
        register_rest_route( 'jksh-current-error-repair/v1', '/apply', array(
            'methods' => 'POST',
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'callback' => function( WP_REST_Request $request ) {
                global $wpdb;
                if ( true !== (bool) $request->get_param( 'confirm' ) ) {
                    return new WP_Error( 'confirm_required', 'confirm=true is required.', array( 'status' => 400 ) );
                }

                $variant_table = $wpdb->prefix . 'jksh_variants';
                $job_table = $wpdb->prefix . 'jksh_jobs';
                $content_table = $wpdb->prefix . 'jksh_content';

                $ytv = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$variant_table} WHERE id=%d AND content_id=%d AND platform='youtube' LIMIT 1", 173, 108 ), ARRAY_A );
                $ytj = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$job_table} WHERE id=%d AND content_id=%d AND variant_id=%d AND platform='youtube' LIMIT 1", 173, 108, 173 ), ARRAY_A );
                $thv = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$variant_table} WHERE id=%d AND content_id=%d AND platform='threads' LIMIT 1", 172, 108 ), ARRAY_A );
                $thj = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$job_table} WHERE id=%d AND content_id=%d AND variant_id=%d AND platform='threads' LIMIT 1", 172, 108, 172 ), ARRAY_A );

                if ( ! $ytv || ! $ytj || ! $thv || ! $thj ) {
                    return new WP_Error( 'records_missing', 'Expected Content 108 failed variant/job records were not found.', array( 'status' => 409 ) );
                }
                if ( 'failed' !== $ytj['status'] || 'failed' !== $thj['status'] ) {
                    return new WP_Error( 'status_changed', 'One or more target jobs are no longer failed; repair aborted.', array( 'status' => 409 ) );
                }
                if ( 'attachment' !== get_post_type( 504 ) || ! is_readable( (string) get_attached_file( 504 ) ) ) {
                    return new WP_Error( 'video_missing', 'Local YouTube video attachment 504 is missing or unreadable.', array( 'status' => 409 ) );
                }
                if ( 'attachment' !== get_post_type( 505 ) || ! is_readable( (string) get_attached_file( 505 ) ) ) {
                    return new WP_Error( 'cover_missing', 'Local YouTube cover attachment 505 is missing or unreadable.', array( 'status' => 409 ) );
                }

                $thread_text = 'Padma Nabha Mūrti of Śrī Jagannātha, Balabhadra & Devī Subhadrā for your home temple. 🙏❤️ Handcrafted for daily sevā, darśana & śṛṅgāra. Includes complimentary Nirmalya Mahaprasadam + 30 days of Sanjua. ✨ Use code PURIDHAM11 for 11% OFF. 🌐 justkalinga.com  JAI JAGANNĀTHA. 🙏 #JustKalinga #Jagannath #PadmaNabha';
                if ( function_exists( 'mb_strlen' ) && mb_strlen( $thread_text ) > 500 ) {
                    return new WP_Error( 'threads_text_long', 'Generated Threads text still exceeds 500 characters.', array( 'status' => 500 ) );
                }

                $yt_fields = json_decode( (string) $ytv['fields_json'], true );
                if ( ! is_array( $yt_fields ) ) $yt_fields = array();
                $yt_fields['thumbnail_attachment_id'] = 505;

                $th_fields = json_decode( (string) $thv['fields_json'], true );
                if ( ! is_array( $th_fields ) ) $th_fields = array();
                $th_fields['text'] = $thread_text;

                $now = gmdate( 'Y-m-d H:i:s' );

                $wpdb->query( 'START TRANSACTION' );
                try {
                    $ok1 = $wpdb->update( $variant_table, array(
                        'media_json' => wp_json_encode( array( 504 ) ),
                        'fields_json' => wp_json_encode( $yt_fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
                        'status' => 'queued',
                        'publish_at' => $now,
                        'updated_at' => $now,
                    ), array( 'id' => 173, 'content_id' => 108 ) );

                    $ok2 = $wpdb->update( $job_table, array(
                        'status' => 'queued',
                        'last_error' => '',
                        'last_response_json' => wp_json_encode( array() ),
                        'started_at' => null,
                        'completed_at' => null,
                        'scheduled_at' => $now,
                        'updated_at' => $now,
                    ), array( 'id' => 173, 'content_id' => 108, 'variant_id' => 173 ) );

                    $ok3 = $wpdb->update( $variant_table, array(
                        'caption' => $thread_text,
                        'description' => $thread_text,
                        'fields_json' => wp_json_encode( $th_fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
                        'status' => 'queued',
                        'publish_at' => $now,
                        'updated_at' => $now,
                    ), array( 'id' => 172, 'content_id' => 108 ) );

                    $ok4 = $wpdb->update( $job_table, array(
                        'status' => 'queued',
                        'last_error' => '',
                        'last_response_json' => wp_json_encode( array() ),
                        'started_at' => null,
                        'completed_at' => null,
                        'scheduled_at' => $now,
                        'updated_at' => $now,
                    ), array( 'id' => 172, 'content_id' => 108, 'variant_id' => 172 ) );

                    $ok5 = $wpdb->update( $content_table, array(
                        'status' => 'scheduled',
                        'updated_at' => $now,
                    ), array( 'id' => 108 ) );

                    foreach ( array( $ok1, $ok2, $ok3, $ok4, $ok5 ) as $ok ) {
                        if ( false === $ok ) throw new Exception( $wpdb->last_error ?: 'Database update failed.' );
                    }
                    $wpdb->query( 'COMMIT' );
                } catch ( Throwable $e ) {
                    $wpdb->query( 'ROLLBACK' );
                    return new WP_Error( 'db_update_failed', $e->getMessage(), array( 'status' => 500 ) );
                }

                $bridge = self::bridge_file();
                $routing = array( 'patched' => false );
                if ( $bridge ) {
                    $src = file_get_contents( $bridge );
                    if ( false !== $src ) {
                        $old = "$bytes > self::MAX_BYTES ? 'wordpress_media' : 'supabase'";
                        $new = "( 0 === strpos( $mime, 'video/' ) || $bytes > self::MAX_BYTES ) ? 'wordpress_media' : 'supabase'";
                        $count = substr_count( $src, $old );
                        if ( $count > 0 ) {
                            $backup = $bridge . '.jkshbak-video-route-' . gmdate( 'YmdHis' );
                            if ( ! copy( $bridge, $backup ) ) {
                                return new WP_Error( 'backup_failed', 'Current jobs repaired, but future video routing backup could not be created.', array( 'status' => 500 ) );
                            }
                            $patched = str_replace( $old, $new, $src, $replaced );
                            if ( false === file_put_contents( $bridge, $patched, LOCK_EX ) ) {
                                return new WP_Error( 'bridge_write_failed', 'Current jobs repaired, but future video routing could not be written.', array( 'status' => 500 ) );
                            }
                            $routing = array(
                                'patched' => true,
                                'replaced' => $replaced,
                                'backup' => basename( $backup ),
                                'sha256' => hash_file( 'sha256', $bridge ),
                            );
                        } elseif ( false !== strpos( $src, $new ) ) {
                            $routing = array( 'patched' => true, 'already_patched' => true, 'sha256' => hash_file( 'sha256', $bridge ) );
                        }
                    }
                }

                return rest_ensure_response( array(
                    'ok' => true,
                    'content_id' => 108,
                    'youtube' => array( 'job_id' => 173, 'variant_id' => 173, 'video_attachment_id' => 504, 'thumbnail_attachment_id' => 505, 'status' => 'queued' ),
                    'threads' => array( 'job_id' => 172, 'variant_id' => 172, 'text_length' => function_exists( 'mb_strlen' ) ? mb_strlen( $thread_text ) : strlen( $thread_text ), 'status' => 'queued' ),
                    'future_video_storage' => $routing,
                ) );
            },
        ) );
    }
}
JKSH_Current_Error_Repair_V100::init();