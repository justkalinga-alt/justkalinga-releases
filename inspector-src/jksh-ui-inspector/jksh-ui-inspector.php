<?php
/**
 * Plugin Name: JKSH UI Inspector
 * Description: Temporary read-only inspector for JK Social Hub UI maintenance.
 * Version: 0.1.0
 * Author: JustKalinga
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class JKSH_UI_Inspector {
    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
    }
    public static function perm() { return current_user_can( 'manage_options' ); }
    public static function routes() {
        register_rest_route( 'jksh-ui-inspector/v1', '/render-library', array(
            'methods' => 'GET', 'callback' => array( __CLASS__, 'render_library' ), 'permission_callback' => array( __CLASS__, 'perm' )
        ) );
        register_rest_route( 'jksh-ui-inspector/v1', '/search-plugin', array(
            'methods' => 'GET', 'callback' => array( __CLASS__, 'search_plugin' ), 'permission_callback' => array( __CLASS__, 'perm' ),
            'args' => array( 'plugin' => array( 'required' => true ), 'q' => array( 'required' => true ) )
        ) );
    }
    public static function render_library( WP_REST_Request $req ) {
        if ( ! shortcode_exists( 'jk_social_upload' ) ) return new WP_Error( 'missing_shortcode', 'jk_social_upload shortcode not registered.' );
        $old_view = isset( $_GET['view'] ) ? $_GET['view'] : null;
        $_GET['view'] = 'library';
        ob_start();
        echo do_shortcode( '[jk_social_upload]' );
        $html = ob_get_clean();
        if ( null === $old_view ) unset( $_GET['view'] ); else $_GET['view'] = $old_view;
        global $wp_styles, $wp_scripts;
        return array(
            'ok' => true,
            'html' => $html,
            'styles_queue' => ( $wp_styles && isset( $wp_styles->queue ) ) ? array_values( $wp_styles->queue ) : array(),
            'scripts_queue' => ( $wp_scripts && isset( $wp_scripts->queue ) ) ? array_values( $wp_scripts->queue ) : array(),
        );
    }
    private static function plugin_dir_from_key( $key ) {
        $map = array(
            'hub' => WP_PLUGIN_DIR . '/jk-social-hub',
            'bridge' => WP_PLUGIN_DIR . '/jk-social-supabase-bridge',
        );
        return isset( $map[$key] ) ? $map[$key] : '';
    }
    public static function search_plugin( WP_REST_Request $req ) {
        $plugin = sanitize_key( $req->get_param( 'plugin' ) );
        $q = (string) $req->get_param( 'q' );
        $dir = self::plugin_dir_from_key( $plugin );
        if ( ! $dir || ! is_dir( $dir ) ) return new WP_Error( 'bad_plugin', 'Plugin directory not found.' );
        $hits = array();
        $rii = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
        foreach ( $rii as $file ) {
            if ( ! $file->isFile() ) continue;
            $ext = strtolower( pathinfo( $file->getFilename(), PATHINFO_EXTENSION ) );
            if ( ! in_array( $ext, array( 'php','js','css' ), true ) ) continue;
            $path = $file->getPathname();
            $rel = ltrim( str_replace( $dir, '', $path ), '/\\' );
            $lines = @file( $path );
            if ( ! is_array( $lines ) ) continue;
            foreach ( $lines as $i => $line ) {
                if ( false === stripos( $line, $q ) ) continue;
                $ctx = array();
                $a = max( 0, $i - 4 ); $b = min( count($lines)-1, $i + 5 );
                for ( $j=$a; $j<=$b; $j++ ) $ctx[] = array( 'line'=>$j+1, 'text'=>rtrim($lines[$j], "\r\n") );
                $hits[] = array( 'file'=>$rel, 'line'=>$i+1, 'context'=>$ctx );
                if ( count( $hits ) >= 30 ) break 2;
            }
        }
        return array( 'ok'=>true, 'plugin'=>$plugin, 'query'=>$q, 'hits'=>$hits );
    }
}
JKSH_UI_Inspector::init();
