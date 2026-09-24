<?php
/**
 * Plugin Name: JKSH Publish Safety Guard
 * Description: Pre-dispatch safety for JK Social Hub: materializes Supabase-backed YouTube media locally and enforces Threads-native text limits.
 * Version: 1.0.0
 * Author: JustKalinga
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class JKSH_Publish_Safety_Guard_V100 {
    const VERSION = '1.0.0';
    const ACTION  = 'jksh_process_job';
    const META_SOURCE = '_jksh_materialized_from_attachment';
    const META_PLATFORM = '_jksh_materialized_for_platform';

    public static function init() : void {
        add_action( self::ACTION, array( __CLASS__, 'prepare_job' ), 5, 1 );
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
    }

    public static function routes() : void {
        register_rest_route( 'jksh-publish-safety/v1', '/status', array(
            'methods' => 'GET',
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'callback' => function() {
                return rest_ensure_response( array(
                    'ok' => true,
                    'version' => self::VERSION,
                    'hook' => self::ACTION,
                    'priority' => 5,
                    'youtube_materialization' => true,
                    'threads_limit_guard' => 500,
                    'bridge_modified' => false,
                ) );
            },
        ) );

        register_rest_route( 'jksh-publish-safety/v1', '/probe-attachment/(?P<id>\d+)', array(
            'methods' => 'GET',
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'callback' => function( WP_REST_Request $request ) {
                $id = absint( $request['id'] );
                $file = get_attached_file( $id );
                $url = wp_get_attachment_url( $id );
                $head = $url ? wp_remote_head( $url, array( 'timeout' => 15, 'redirection' => 5 ) ) : null;
                return rest_ensure_response( array(
                    'ok' => true,
                    'attachment_id' => $id,
                    'mime_type' => (string) get_post_mime_type( $id ),
                    'local_readable' => $file && is_readable( $file ),
                    'source_url_available' => (bool) $url,
                    'source_http_code' => is_wp_error( $head ) || ! $head ? null : wp_remote_retrieve_response_code( $head ),
                    'materialized_copy' => self::find_materialized_copy( $id, 'youtube' ),
                ) );
            },
        ) );
    }

    public static function prepare_job( $job_id ) : void {
        global $wpdb;
        $job_id = absint( $job_id );
        if ( ! $job_id ) { return; }

        $jobs = $wpdb->prefix . 'jksh_jobs';
        $variants = $wpdb->prefix . 'jksh_variants';

        $job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$jobs} WHERE id=%d LIMIT 1", $job_id ), ARRAY_A );
        if ( ! $job ) { return; }

        $platform = sanitize_key( (string) ( $job['platform'] ?? '' ) );
        $status = sanitize_key( (string) ( $job['status'] ?? '' ) );
        if ( ! in_array( $status, array( 'queued', 'scheduled', 'pending' ), true ) ) { return; }
        if ( ! in_array( $platform, array( 'youtube', 'threads' ), true ) ) { return; }

        $variant_id = absint( $job['variant_id'] ?? 0 );
        if ( ! $variant_id ) { return; }

        $variant = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$variants} WHERE id=%d LIMIT 1", $variant_id ), ARRAY_A );
        if ( ! $variant ) { return; }

        if ( 'youtube' === $platform ) {
            self::prepare_youtube_variant( $variants, $variant );
        } elseif ( 'threads' === $platform ) {
            self::prepare_threads_variant( $variants, $variant );
        }
    }

    private static function prepare_youtube_variant( string $table, array $variant ) : void {
        global $wpdb;

        $ids = json_decode( (string) ( $variant['media_json'] ?? '[]' ), true );
        $ids = is_array( $ids ) ? array_values( array_filter( array_map( 'absint', $ids ) ) ) : array();
        if ( ! $ids ) { return; }

        $changed = false;
        foreach ( $ids as $i => $id ) {
            $mime = (string) get_post_mime_type( $id );
            if ( 0 !== strpos( $mime, 'video/' ) ) { continue; }
            $file = get_attached_file( $id );
            if ( $file && is_readable( $file ) ) { continue; }

            $local = self::materialize_attachment( $id, 'youtube' );
            if ( $local ) {
                $ids[ $i ] = $local;
                $changed = true;
            }
        }

        $fields = json_decode( (string) ( $variant['fields_json'] ?? '{}' ), true );
        $fields = is_array( $fields ) ? $fields : array();
        $thumb = absint( $fields['thumbnail_attachment_id'] ?? 0 );
        if ( $thumb ) {
            $thumb_file = get_attached_file( $thumb );
            if ( ! $thumb_file || ! is_readable( $thumb_file ) ) {
                $local_thumb = self::materialize_attachment( $thumb, 'youtube' );
                if ( $local_thumb ) {
                    $fields['thumbnail_attachment_id'] = $local_thumb;
                    $changed = true;
                }
            }
        }

        if ( ! $changed ) { return; }

        $fields['_jksh_publish_safety'] = array(
            'version' => self::VERSION,
            'youtube_materialized_at_utc' => gmdate( 'c' ),
        );

        $wpdb->update(
            $table,
            array(
                'media_json' => wp_json_encode( array_values( $ids ) ),
                'fields_json' => wp_json_encode( $fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
                'updated_at' => current_time( 'mysql', true ),
            ),
            array( 'id' => absint( $variant['id'] ) )
        );
    }

    private static function prepare_threads_variant( string $table, array $variant ) : void {
        global $wpdb;

        $fields = json_decode( (string) ( $variant['fields_json'] ?? '{}' ), true );
        $fields = is_array( $fields ) ? $fields : array();

        $text = (string) ( $fields['text'] ?? $variant['caption'] ?? $variant['description'] ?? '' );
        if ( self::length( $text ) <= 500 ) { return; }

        $text = self::compact_threads_text( $text );
        $fields['text'] = $text;
        $fields['_jksh_publish_safety'] = array(
            'version' => self::VERSION,
            'threads_compacted' => true,
            'threads_length' => self::length( $text ),
            'compacted_at_utc' => gmdate( 'c' ),
        );

        $wpdb->update(
            $table,
            array(
                'caption' => $text,
                'description' => $text,
                'fields_json' => wp_json_encode( $fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
                'updated_at' => current_time( 'mysql', true ),
            ),
            array( 'id' => absint( $variant['id'] ) )
        );
    }

    private static function compact_threads_text( string $text ) : string {
        $text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) );
        if ( self::length( $text ) <= 500 ) { return $text; }

        preg_match_all( '~https?://[^\s]+~u', $text, $url_matches );
        preg_match_all( '/#[\p{L}\p{N}_]+/u', $text, $tag_matches );

        $suffix_parts = array();
        if ( ! empty( $url_matches[0][0] ) ) {
            $suffix_parts[] = $url_matches[0][0];
        }
        if ( ! empty( $tag_matches[0] ) ) {
            $suffix_parts = array_merge( $suffix_parts, array_slice( array_values( array_unique( $tag_matches[0] ) ), 0, 3 ) );
        }
        $suffix = trim( implode( ' ', $suffix_parts ) );

        $reserve = $suffix ? self::length( $suffix ) + 2 : 1;
        $max_body = max( 120, 500 - $reserve );
        $body = self::substr( $text, 0, $max_body );
        if ( self::length( $text ) > $max_body ) {
            $space = self::strrpos( $body, ' ' );
            if ( false !== $space && $space > (int) ( $max_body * 0.7 ) ) {
                $body = self::substr( $body, 0, $space );
            }
            $body = rtrim( $body, " \t\n\r\0\x0B,.;:-" ) . '…';
        }

        $out = trim( $body . ( $suffix ? ' ' . $suffix : '' ) );
        while ( self::length( $out ) > 500 ) {
            $out = rtrim( self::substr( $out, 0, 499 ) ) . '…';
        }
        return $out;
    }

    private static function materialize_attachment( int $source_id, string $platform ) : int {
        $existing = self::find_materialized_copy( $source_id, $platform );
        if ( $existing ) { return $existing; }

        $url = wp_get_attachment_url( $source_id );
        if ( ! $url ) { return 0; }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url( $url, 300 );
        if ( is_wp_error( $tmp ) ) { return 0; }

        $mime = (string) get_post_mime_type( $source_id );
        $ext_map = array(
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        );
        $ext = $ext_map[ $mime ] ?? '';
        $base = sanitize_file_name( (string) get_the_title( $source_id ) );
        if ( ! $base ) { $base = 'jksh-media-' . $source_id; }
        if ( $ext && ! preg_match( '/\.' . preg_quote( $ext, '/' ) . '$/i', $base ) ) {
            $base .= '.' . $ext;
        }

        $file_array = array(
            'name' => $base,
            'tmp_name' => $tmp,
        );
        $new_id = media_handle_sideload( $file_array, 0, (string) get_the_title( $source_id ) );
        if ( is_wp_error( $new_id ) ) {
            @unlink( $tmp );
            return 0;
        }

        update_post_meta( $new_id, self::META_SOURCE, $source_id );
        update_post_meta( $new_id, self::META_PLATFORM, $platform );
        update_post_meta( $new_id, '_jksh_materialized_at_utc', gmdate( 'c' ) );

        $alt = get_post_meta( $source_id, '_wp_attachment_image_alt', true );
        if ( $alt ) {
            update_post_meta( $new_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
        }

        return absint( $new_id );
    }

    private static function find_materialized_copy( int $source_id, string $platform ) : int {
        $ids = get_posts( array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 10,
            'fields' => 'ids',
            'meta_query' => array(
                'relation' => 'AND',
                array( 'key' => self::META_SOURCE, 'value' => $source_id ),
                array( 'key' => self::META_PLATFORM, 'value' => $platform ),
            ),
        ) );

        foreach ( $ids as $id ) {
            $file = get_attached_file( $id );
            if ( $file && is_readable( $file ) ) { return absint( $id ); }
        }
        return 0;
    }

    private static function length( string $text ) : int {
        return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
    }

    private static function substr( string $text, int $start, int $length ) : string {
        return function_exists( 'mb_substr' ) ? mb_substr( $text, $start, $length, 'UTF-8' ) : substr( $text, $start, $length );
    }

    private static function strrpos( string $haystack, string $needle ) {
        return function_exists( 'mb_strrpos' ) ? mb_strrpos( $haystack, $needle, 0, 'UTF-8' ) : strrpos( $haystack, $needle );
    }
}
JKSH_Publish_Safety_Guard_V100::init();