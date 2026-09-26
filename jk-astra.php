<?php
/**
 * Plugin Name: JK Astra
 * Description: Secure control plane and queued device bridge for JustKalinga infrastructure.
 * Version: 0.1.0
 * Author: JustKalinga
 */

if (!defined('ABSPATH')) { exit; }

final class JK_Astra {
    const VERSION = '0.1.0';
    const NS = 'jk-astra/v1';

    public static function init() {
        register_activation_hook(__FILE__, [__CLASS__, 'activate']);
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    private static function devices_table() {
        global $wpdb;
        return $wpdb->prefix . 'jk_astra_devices';
    }

    private static function jobs_table() {
        global $wpdb;
        return $wpdb->prefix . 'jk_astra_jobs';
    }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $devices = self::devices_table();
        $jobs = self::jobs_table();

        dbDelta("CREATE TABLE $devices (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            device_uuid varchar(64) NOT NULL,
            name varchar(191) NOT NULL,
            kind varchar(64) NOT NULL DEFAULT 'generic',
            token_hash char(64) NOT NULL,
            capabilities longtext NULL,
            last_seen datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY device_uuid (device_uuid)
        ) $charset;");

        dbDelta("CREATE TABLE $jobs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_uuid varchar(64) NOT NULL,
            device_uuid varchar(64) NOT NULL,
            command varchar(96) NOT NULL,
            args longtext NULL,
            status varchar(24) NOT NULL DEFAULT 'queued',
            result longtext NULL,
            error_text longtext NULL,
            created_at datetime NOT NULL,
            claimed_at datetime NULL,
            completed_at datetime NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY job_uuid (job_uuid),
            KEY device_status (device_uuid,status),
            KEY created_at (created_at)
        ) $charset;");

        update_option('jk_astra_version', self::VERSION, false);
    }

    private static function admin_permission() {
        return current_user_can('manage_options');
    }

    public static function routes() {
        register_rest_route(self::NS, '/health', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'health'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NS, '/devices', [
            [
                'methods' => 'GET',
                'callback' => [__CLASS__, 'list_devices'],
                'permission_callback' => [__CLASS__, 'admin_permission'],
            ],
            [
                'methods' => 'POST',
                'callback' => [__CLASS__, 'create_device'],
                'permission_callback' => [__CLASS__, 'admin_permission'],
            ],
        ]);

        register_rest_route(self::NS, '/jobs', [
            [
                'methods' => 'POST',
                'callback' => [__CLASS__, 'create_job'],
                'permission_callback' => [__CLASS__, 'admin_permission'],
            ],
        ]);

        register_rest_route(self::NS, '/jobs/(?P<job_uuid>[A-Za-z0-9\-]+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_job'],
            'permission_callback' => [__CLASS__, 'admin_permission'],
        ]);

        register_rest_route(self::NS, '/worker/poll', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'worker_poll'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NS, '/worker/result', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'worker_result'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function health() {
        return rest_ensure_response([
            'ok' => true,
            'service' => 'jk-astra',
            'version' => self::VERSION,
            'time_utc' => gmdate('c'),
        ]);
    }

    private static function allowed_commands() {
        return [
            'health',
            'system.info',
            'system.load',
            'stream.status',
            'phone.adb_devices',
            'phone.device_info',
            'phone.launch_app',
            'phone.force_stop_app',
            'phone.screenshot',
            'phone.ui_dump',
            'phone.logcat',
            'phone.tap',
            'phone.swipe',
            'phone.keyevent',
            'phone.type_text',
            'phone.flashlight',
        ];
    }

    private static function clean_json($value) {
        return wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function create_device(WP_REST_Request $request) {
        global $wpdb;
        $name = sanitize_text_field((string) $request->get_param('name'));
        $kind = sanitize_key((string) ($request->get_param('kind') ?: 'generic'));
        if ($name === '') {
            return new WP_Error('jk_astra_name_required', 'Device name is required.', ['status' => 400]);
        }

        $device_uuid = wp_generate_uuid4();
        $token = bin2hex(random_bytes(32));
        $now = current_time('mysql', true);
        $caps = $request->get_param('capabilities');

        $ok = $wpdb->insert(self::devices_table(), [
            'device_uuid' => $device_uuid,
            'name' => $name,
            'kind' => $kind,
            'token_hash' => hash('sha256', $token),
            'capabilities' => $caps !== null ? self::clean_json($caps) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%s','%s','%s','%s','%s','%s','%s']);

        if (!$ok) {
            return new WP_Error('jk_astra_device_create_failed', 'Could not create device.', ['status' => 500]);
        }

        return rest_ensure_response([
            'ok' => true,
            'device_uuid' => $device_uuid,
            'name' => $name,
            'kind' => $kind,
            'token' => $token,
            'note' => 'Token is returned only at creation time. Store it securely on the worker.',
        ]);
    }

    public static function list_devices() {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT device_uuid,name,kind,capabilities,last_seen,created_at,updated_at FROM ' . self::devices_table() . ' ORDER BY id ASC', ARRAY_A);
        foreach ($rows as &$row) {
            $row['capabilities'] = $row['capabilities'] ? json_decode($row['capabilities'], true) : null;
        }
        return rest_ensure_response(['ok' => true, 'devices' => $rows]);
    }

    public static function create_job(WP_REST_Request $request) {
        global $wpdb;
        $device_uuid = sanitize_text_field((string) $request->get_param('device_uuid'));
        $command = sanitize_text_field((string) $request->get_param('command'));
        $args = $request->get_param('args');
        if (!in_array($command, self::allowed_commands(), true)) {
            return new WP_Error('jk_astra_command_not_allowed', 'Command is not allowlisted.', ['status' => 400]);
        }
        $exists = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::devices_table() . ' WHERE device_uuid=%s', $device_uuid));
        if (!$exists) {
            return new WP_Error('jk_astra_device_not_found', 'Unknown device.', ['status' => 404]);
        }
        if ($args === null) { $args = new stdClass(); }
        $job_uuid = wp_generate_uuid4();
        $now = current_time('mysql', true);
        $ok = $wpdb->insert(self::jobs_table(), [
            'job_uuid' => $job_uuid,
            'device_uuid' => $device_uuid,
            'command' => $command,
            'args' => self::clean_json($args),
            'status' => 'queued',
            'created_at' => $now,
            'updated_at' => $now,
        ], ['%s','%s','%s','%s','%s','%s','%s']);
        if (!$ok) {
            return new WP_Error('jk_astra_job_create_failed', 'Could not create job.', ['status' => 500]);
        }
        return rest_ensure_response(['ok' => true, 'job_uuid' => $job_uuid, 'status' => 'queued']);
    }

    public static function get_job(WP_REST_Request $request) {
        global $wpdb;
        $job_uuid = sanitize_text_field((string) $request['job_uuid']);
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::jobs_table() . ' WHERE job_uuid=%s', $job_uuid), ARRAY_A);
        if (!$row) {
            return new WP_Error('jk_astra_job_not_found', 'Unknown job.', ['status' => 404]);
        }
        $row['args'] = $row['args'] ? json_decode($row['args'], true) : null;
        $row['result'] = $row['result'] ? json_decode($row['result'], true) : null;
        return rest_ensure_response(['ok' => true, 'job' => $row]);
    }

    private static function authenticate_worker(WP_REST_Request $request) {
        global $wpdb;
        $device_uuid = sanitize_text_field((string) $request->get_param('device_uuid'));
        $auth = trim((string) $request->get_header('authorization'));
        if ($device_uuid === '' || stripos($auth, 'Bearer ') !== 0) {
            return null;
        }
        $token = trim(substr($auth, 7));
        if ($token === '') { return null; }
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::devices_table() . ' WHERE device_uuid=%s', $device_uuid), ARRAY_A);
        if (!$row) { return null; }
        $candidate = hash('sha256', $token);
        if (!hash_equals($row['token_hash'], $candidate)) { return null; }
        return $row;
    }

    public static function worker_poll(WP_REST_Request $request) {
        global $wpdb;
        $device = self::authenticate_worker($request);
        if (!$device) {
            return new WP_Error('jk_astra_unauthorized', 'Invalid worker credentials.', ['status' => 401]);
        }
        $now = current_time('mysql', true);
        $wpdb->update(self::devices_table(), ['last_seen' => $now, 'updated_at' => $now], ['id' => (int)$device['id']], ['%s','%s'], ['%d']);

        $job = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::jobs_table() . ' WHERE device_uuid=%s AND status=%s ORDER BY id ASC LIMIT 1',
            $device['device_uuid'], 'queued'
        ), ARRAY_A);

        if (!$job) {
            return rest_ensure_response(['ok' => true, 'job' => null]);
        }

        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::jobs_table() . ' SET status=%s, claimed_at=%s, updated_at=%s WHERE id=%d AND status=%s',
            'claimed', $now, $now, (int)$job['id'], 'queued'
        ));
        if (!$updated) {
            return rest_ensure_response(['ok' => true, 'job' => null]);
        }
        $job['status'] = 'claimed';
        $job['args'] = $job['args'] ? json_decode($job['args'], true) : [];
        unset($job['result'], $job['error_text']);
        return rest_ensure_response(['ok' => true, 'job' => $job]);
    }

    public static function worker_result(WP_REST_Request $request) {
        global $wpdb;
        $device = self::authenticate_worker($request);
        if (!$device) {
            return new WP_Error('jk_astra_unauthorized', 'Invalid worker credentials.', ['status' => 401]);
        }
        $job_uuid = sanitize_text_field((string) $request->get_param('job_uuid'));
        $status = sanitize_key((string) $request->get_param('status'));
        if (!in_array($status, ['succeeded','failed'], true)) {
            return new WP_Error('jk_astra_bad_status', 'Status must be succeeded or failed.', ['status' => 400]);
        }
        $job = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::jobs_table() . ' WHERE job_uuid=%s AND device_uuid=%s', $job_uuid, $device['device_uuid']), ARRAY_A);
        if (!$job) {
            return new WP_Error('jk_astra_job_not_found', 'Unknown job for this device.', ['status' => 404]);
        }
        $result = $request->get_param('result');
        $error = sanitize_textarea_field((string) $request->get_param('error'));
        $now = current_time('mysql', true);
        $wpdb->update(self::jobs_table(), [
            'status' => $status,
            'result' => $result !== null ? self::clean_json($result) : null,
            'error_text' => $error ?: null,
            'completed_at' => $now,
            'updated_at' => $now,
        ], ['id' => (int)$job['id']], ['%s','%s','%s','%s','%s'], ['%d']);
        $wpdb->update(self::devices_table(), ['last_seen' => $now, 'updated_at' => $now], ['id' => (int)$device['id']], ['%s','%s'], ['%d']);
        return rest_ensure_response(['ok' => true, 'job_uuid' => $job_uuid, 'status' => $status]);
    }
}

JK_Astra::init();
