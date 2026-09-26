<?php
/**
 * Plugin Name: JKSH Google Config Inspector
 * Description: Temporary read-only inspector returning Google/YouTube option names and structural keys only. Never returns stored values.
 * Version: 1.0.0
 * Author: JustKalinga
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
add_action( 'rest_api_init', function () {
    register_rest_route( 'jksh-google-config/v1', '/keys', array(
        'methods' => 'GET',
        'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        'callback' => function() {
            global $wpdb;
            $names = $wpdb->get_col(
                "SELECT option_name FROM {$wpdb->options}
                 WHERE option_name LIKE '%youtube%'
                    OR option_name LIKE '%google%'
                    OR option_name LIKE '%oauth%'
                    OR option_name LIKE '%jksh%'
                 ORDER BY option_name ASC"
            );
            $out = array();
            foreach ( array_unique( array_map( 'strval', (array) $names ) ) as $name ) {
                if ( false === stripos( $name, 'youtube' ) && false === stripos( $name, 'google' ) && false === stripos( $name, 'oauth' ) ) continue;
                $raw = get_option( $name, null );
                $keys = array();
                if ( is_array( $raw ) ) {
                    $keys = array_values( array_map( 'strval', array_keys( $raw ) ) );
                } elseif ( is_object( $raw ) ) {
                    $keys = array_values( array_map( 'strval', array_keys( get_object_vars( $raw ) ) ) );
                }
                $out[] = array(
                    'option_name' => $name,
                    'value_type' => gettype( $raw ),
                    'structural_keys' => $keys,
                );
            }
            return rest_ensure_response( array( 'ok' => true, 'items' => $out ) );
        },
    ) );
} );