<?php
/**
 * Plugin Name: JKSH Large Video Transport
 * Description: DB-light direct transport for Social Upload videos over 50 MB. Chunk writes and hashing bypass WordPress/MySQL; WordPress only starts and registers the finished attachment.
 * Version: 1.0.0
 * Author: JustKalinga
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class JKSH_Large_Video_Transport_V100 {
    const VERSION = '1.0.0';
    const THRESHOLD = 52428800; // 50 MB
    const PREFIX = 'jklg1.';

    public static function init() : void {
        add_action( 'wp_ajax_jkssb_ui_begin', array( __CLASS__, 'intercept_begin' ), 1 );
        add_action( 'wp_ajax_jksh_large_video_register', array( __CLASS__, 'register_finished' ), 1 );
        add_action( 'admin_footer', array( __CLASS__, 'inject_fetch_router' ), 1 );
        add_action( 'wp_footer', array( __CLASS__, 'inject_fetch_router' ), 1 );
        add_action( 'template_redirect', array( __CLASS__, 'redirect_old_portal' ), 0 );
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function activate() : void {
        self::ensure_secret();
        self::ensure_staging();
    }

    private static function plugin_dir() : string { return __DIR__; }
    private static function secret_file() : string { return self::plugin_dir() . '/secret.php'; }
    private static function wp_content_dir_guess() : string { return dirname( self::plugin_dir(), 2 ); }
    private static function staging_dir() : string { return self::wp_content_dir_guess() . '/uploads/jksh-large-upload-staging'; }
    private static function direct_url() : string { return plugins_url( 'direct-upload.php', __FILE__ ); }

    private static function ensure_secret() : string {
        $file = self::secret_file();
        if ( is_file( $file ) ) {
            $secret = include $file;
            if ( is_string( $secret ) && strlen( $secret ) >= 32 ) return $secret;
        }
        $secret = bin2hex( random_bytes( 32 ) );
        $php = "<?php\nreturn " . var_export( $secret, true ) . ";\n";
        if ( false === file_put_contents( $file, $php, LOCK_EX ) ) return '';
        @chmod( $file, 0600 );
        return $secret;
    }

    private static function secret() : string { return self::ensure_secret(); }

    private static function ensure_staging() : bool {
        $dir = self::staging_dir();
        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) return false;
        $index = $dir . '/index.php';
        if ( ! is_file( $index ) ) @file_put_contents( $index, "<?php http_response_code(404); exit;\n" );
        return is_dir( $dir ) && is_writable( $dir );
    }

    private static function parse_ini_bytes( $value ) : int {
        if ( is_numeric( $value ) ) return (int) $value;
        $value = trim( (string) $value );
        if ( '' === $value || '-1' === $value ) return PHP_INT_MAX;
        $unit = strtolower( substr( $value, -1 ) );
        $num = (float) $value;
        if ( 'g' === $unit ) return (int) round( $num * 1024 * 1024 * 1024 );
        if ( 'm' === $unit ) return (int) round( $num * 1024 * 1024 );
        if ( 'k' === $unit ) return (int) round( $num * 1024 );
        return (int) $num;
    }

    private static function safe_chunk_bytes() : int {
        $upload = self::parse_ini_bytes( ini_get( 'upload_max_filesize' ) );
        $post = self::parse_ini_bytes( ini_get( 'post_max_size' ) );
        $limit = min( $upload, $post, 16 * 1024 * 1024 );
        if ( PHP_INT_MAX === $limit ) $limit = 16 * 1024 * 1024;
        $safe = (int) floor( $limit * 0.70 );
        return max( 4 * 1024 * 1024, min( 16 * 1024 * 1024, $safe ) );
    }

    private static function sign( string $uuid, int $exp ) : string {
        return hash_hmac( 'sha256', $uuid . '|' . $exp, self::secret() );
    }

    private static function make_upload_id( string $uuid, int $exp ) : string {
        return self::PREFIX . $uuid . '.' . $exp . '.' . self::sign( $uuid, $exp );
    }

    private static function parse_upload_id( string $id ) {
        if ( ! preg_match( '/^jklg1\.([a-f0-9-]{36})\.(\d{10})\.([a-f0-9]{64})$/', $id, $m ) ) return false;
        $uuid = $m[1];
        $exp = (int) $m[2];
        if ( $exp < time() ) return false;
        if ( ! hash_equals( self::sign( $uuid, $exp ), $m[3] ) ) return false;
        return array( 'uuid' => $uuid, 'exp' => $exp );
    }

    private static function session_path( string $uuid ) : string { return self::staging_dir() . '/' . $uuid . '.json'; }
    private static function part_path( string $uuid ) : string { return self::staging_dir() . '/' . $uuid . '.part'; }

    private static function cleanup_stale() : void {
        if ( ! self::ensure_staging() ) return;
        $cut = time() - 6 * HOUR_IN_SECONDS;
        foreach ( glob( self::staging_dir() . '/*.{json,part}', GLOB_BRACE ) ?: array() as $file ) {
            if ( @filemtime( $file ) && @filemtime( $file ) < $cut ) @unlink( $file );
        }
    }

    public static function intercept_begin() : void {
        $mime = sanitize_mime_type( wp_unslash( $_POST['mime_type'] ?? '' ) );
        $bytes = absint( $_POST['bytes'] ?? 0 );
        if ( 0 !== strpos( $mime, 'video/' ) || $bytes <= self::THRESHOLD ) return;

        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
        check_ajax_referer( 'jkssb_social_upload', 'nonce' );

        $allowed = array( 'video/mp4', 'video/quicktime', 'video/webm' );
        $filename = sanitize_file_name( wp_unslash( $_POST['filename'] ?? '' ) );
        if ( ! $filename || ! in_array( $mime, $allowed, true ) || $bytes < 1 || $bytes > 524288000 ) {
            wp_send_json_error( array( 'message' => 'Large video metadata is invalid or exceeds 500 MB.' ), 422 );
        }
        if ( ! self::ensure_staging() || ! self::secret() ) {
            wp_send_json_error( array( 'message' => 'Large-video staging is unavailable.' ), 500 );
        }

        self::cleanup_stale();
        $uuid = wp_generate_uuid4();
        $exp = time() + 2 * HOUR_IN_SECONDS;
        $chunk = self::safe_chunk_bytes();

        $session = array(
            'version' => self::VERSION,
            'uuid' => $uuid,
            'expires' => $exp,
            'filename' => $filename,
            'mime_type' => $mime,
            'bytes' => $bytes,
            'width' => absint( $_POST['width'] ?? 0 ),
            'height' => absint( $_POST['height'] ?? 0 ),
            'duration_ms' => absint( $_POST['duration_ms'] ?? 0 ),
            'user_id' => get_current_user_id(),
            'max_chunk_bytes' => $chunk,
            'created_at' => time(),
            'ready' => false,
        );

        if ( false === file_put_contents( self::session_path( $uuid ), wp_json_encode( $session ), LOCK_EX ) ) {
            wp_send_json_error( array( 'message' => 'Could not create large-video session.' ), 500 );
        }
        if ( false === file_put_contents( self::part_path( $uuid ), '' ) ) {
            @unlink( self::session_path( $uuid ) );
            wp_send_json_error( array( 'message' => 'Could not create large-video staging file.' ), 500 );
        }

        wp_send_json_success( array(
            'upload_id' => self::make_upload_id( $uuid, $exp ),
            'max_chunk_bytes' => $chunk,
            'storage_target' => 'wordpress_media',
            'direct_transport' => true,
            'transport_version' => self::VERSION,
        ) );
    }

    public static function register_finished() : void {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
        check_ajax_referer( 'jkssb_social_upload', 'nonce' );

        $upload_id = sanitize_text_field( wp_unslash( $_POST['upload_id'] ?? '' ) );
        $parsed = self::parse_upload_id( $upload_id );
        if ( ! $parsed ) wp_send_json_error( array( 'message' => 'Large-video session is invalid or expired.' ), 400 );

        $session_file = self::session_path( $parsed['uuid'] );
        $part = self::part_path( $parsed['uuid'] );
        $session = json_decode( (string) @file_get_contents( $session_file ), true );
        if ( ! is_array( $session ) || empty( $session['ready'] ) || empty( $session['sha256'] ) ) {
            wp_send_json_error( array( 'message' => 'Large-video direct finalization is not complete.' ), 409 );
        }
        if ( ! is_file( $part ) || (int) filesize( $part ) !== (int) $session['bytes'] ) {
            wp_send_json_error( array( 'message' => 'Large-video staging file is incomplete.' ), 409 );
        }
        if ( absint( $session['user_id'] ?? 0 ) !== get_current_user_id() ) {
            wp_send_json_error( array( 'message' => 'Large-video session owner mismatch.' ), 403 );
        }

        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) wp_send_json_error( array( 'message' => $uploads['error'] ), 500 );

        $filename = wp_unique_filename( $uploads['path'], sanitize_file_name( $session['filename'] ) );
        $dest = trailingslashit( $uploads['path'] ) . $filename;
        $moved = @rename( $part, $dest );
        if ( ! $moved ) {
            $moved = @copy( $part, $dest );
            if ( $moved ) @unlink( $part );
        }
        if ( ! $moved || ! is_file( $dest ) ) {
            wp_send_json_error( array( 'message' => 'Could not move the completed large video into WordPress Media.' ), 500 );
        }

        $attachment_id = wp_insert_attachment( array(
            'post_title' => sanitize_text_field( pathinfo( $session['filename'], PATHINFO_FILENAME ) ),
            'post_status' => 'inherit',
            'post_mime_type' => sanitize_mime_type( $session['mime_type'] ),
            'guid' => trailingslashit( $uploads['url'] ) . rawurlencode( $filename ),
        ), $dest, 0, true );

        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $dest );
            wp_send_json_error( array( 'message' => $attachment_id->get_error_message() ), 500 );
        }

        $meta = array(
            'filesize' => (int) $session['bytes'],
            'file' => _wp_relative_upload_path( $dest ),
        );
        if ( ! empty( $session['width'] ) ) $meta['width'] = absint( $session['width'] );
        if ( ! empty( $session['height'] ) ) $meta['height'] = absint( $session['height'] );
        if ( ! empty( $session['duration_ms'] ) ) {
            $seconds = max( 0, (int) round( absint( $session['duration_ms'] ) / 1000 ) );
            $meta['length'] = round( absint( $session['duration_ms'] ) / 1000, 3 );
            $meta['length_formatted'] = sprintf( '%02d:%02d', floor( $seconds / 60 ), $seconds % 60 );
        }
        wp_update_attachment_metadata( $attachment_id, $meta );
        update_post_meta( $attachment_id, '_jkssb_sha256', sanitize_text_field( $session['sha256'] ) );
        update_post_meta( $attachment_id, '_jkssb_local_fallback', '1' );
        update_post_meta( $attachment_id, '_jkssb_storage_source', 'wordpress_media' );
        update_post_meta( $attachment_id, '_jkssb_original_filename', sanitize_file_name( $session['filename'] ) );
        update_post_meta( $attachment_id, '_jksh_large_video_transport', self::VERSION );

        @unlink( $session_file );

        wp_send_json_success( array(
            'ok' => true,
            'attachment_id' => (int) $attachment_id,
            'sha256' => sanitize_text_field( $session['sha256'] ),
            'url' => wp_get_attachment_url( $attachment_id ),
            'storage' => 'wordpress_media',
            'wordpress_media_bytes' => true,
            'direct_transport' => true,
            'transport_version' => self::VERSION,
        ) );
    }

    public static function redirect_old_portal() : void {
        if ( is_admin() || wp_doing_ajax() || ! is_singular( 'page' ) ) return;
        global $post;
        if ( ! $post instanceof WP_Post || ! has_shortcode( (string) $post->post_content, 'jk_social_upload' ) ) return;
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) return;
        wp_safe_redirect( admin_url( 'admin.php?page=jksh-social-upload&view=upload' ), 302 );
        exit;
    }

    public static function inject_fetch_router() : void {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) return;
        if ( is_admin() && 'jksh-social-upload' !== sanitize_key( $_GET['page'] ?? '' ) ) return;
        ?>
        <script id="jksh-large-video-transport-v100">
        (() => {
          if (window.__jkshLargeVideoTransportV100) return;
          window.__jkshLargeVideoTransportV100 = true;
          const nativeFetch = window.fetch.bind(window);
          const directUrl = <?php echo wp_json_encode( self::direct_url() ); ?>;
          const prefix = <?php echo wp_json_encode( self::PREFIX ); ?>;

          window.fetch = async function(input, init) {
            try {
              const body = init && init.body;
              if (body instanceof FormData) {
                const action = String(body.get('action') || '');
                const uploadId = String(body.get('upload_id') || '');
                if (uploadId.startsWith(prefix) && action === 'jkssb_ui_chunk') {
                  return nativeFetch(directUrl, {method:'POST', body, credentials:'same-origin', cache:'no-store'});
                }
                if (uploadId.startsWith(prefix) && action === 'jkssb_ui_finalize') {
                  const directBody = new FormData();
                  directBody.append('mode','finalize');
                  directBody.append('upload_id', uploadId);
                  const dr = await nativeFetch(directUrl, {method:'POST', body:directBody, credentials:'same-origin', cache:'no-store'});
                  let dj;
                  try { dj = await dr.clone().json(); } catch(e) {
                    return new Response(JSON.stringify({success:false,data:{message:'Large-video direct finalizer returned an unreadable response.'}}),{status:502,headers:{'Content-Type':'application/json'}});
                  }
                  if (!dj || !dj.success) return dr;
                  body.set('action','jksh_large_video_register');
                  return nativeFetch(input, Object.assign({}, init, {body}));
                }
              }
            } catch(e) {}
            return nativeFetch(input, init);
          };
        })();
        </script>
        <?php
    }

    public static function register_routes() : void {
        register_rest_route( 'jksh-large-video/v1', '/status', array(
            'methods' => 'GET',
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            'callback' => function() {
                return rest_ensure_response( array(
                    'ok' => true,
                    'version' => self::VERSION,
                    'threshold_bytes' => self::THRESHOLD,
                    'safe_chunk_bytes' => self::safe_chunk_bytes(),
                    'direct_url' => self::direct_url(),
                    'secret_ready' => (bool) self::secret(),
                    'staging_writable' => self::ensure_staging(),
                    'bridge_modified' => false,
                    'heavy_hash_runs_without_wordpress' => true,
                ) );
            },
        ) );
    }
}

register_activation_hook( __FILE__, array( 'JKSH_Large_Video_Transport_V100', 'activate' ) );
JKSH_Large_Video_Transport_V100::init();
