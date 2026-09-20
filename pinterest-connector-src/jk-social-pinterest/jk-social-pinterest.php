<?php
/**
 * Plugin Name: JK Social Pinterest Connector
 * Description: Pinterest OAuth, board discovery, token refresh and native Pin publishing for JK Social Hub.
 * Version: 0.1.5
 * Author: JustKalinga
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class JKSH_Pinterest_Connector {
    const VERSION = '0.1.5';
    const PLATFORM = 'pinterest';
    const OPTION = 'jksh_pinterest_connector';
    const API = 'https://api.pinterest.com/v5';
    const SANDBOX_API = 'https://api-sandbox.pinterest.com/v5';
    const OAUTH = 'https://www.pinterest.com/oauth/';
    const ACTION = 'jksh_process_job';
    private static $instance = null;

    public static function instance() { if ( null === self::$instance ) self::$instance = new self(); return self::$instance; }
    private function __construct() {
        add_action( 'admin_post_jksh_pinterest_save_settings', [ $this, 'save_settings' ] );
        add_action( 'admin_post_jksh_pinterest_oauth_start', [ $this, 'oauth_start' ] );
        add_action( 'admin_post_jksh_pinterest_oauth_callback', [ $this, 'oauth_callback' ] ); // legacy fallback
        add_action( 'admin_post_jksh_pinterest_test_account', [ $this, 'test_account' ] );
        add_action( 'admin_post_jksh_pinterest_disconnect_account', [ $this, 'disconnect_account' ] );
        add_action( 'admin_post_jksh_pinterest_save_sandbox', [ $this, 'save_sandbox_settings' ] );
        add_action( 'admin_post_jksh_pinterest_disconnect_sandbox', [ $this, 'disconnect_sandbox' ] );
        add_action( 'admin_footer', [ $this, 'admin_footer' ], 1130 );
        add_action( 'rest_api_init', [ $this, 'rest_routes' ] );
        add_action( self::ACTION, [ $this, 'process_job' ], 5, 1 );
    }

    private function deps_ready() { return class_exists('JKSH\\Repositories\\AccountRepository') && class_exists('JKSH\\Security\\Crypto'); }
    private function accounts() { return new \JKSH\Repositories\AccountRepository(); }
    private function crypto() { return new \JKSH\Security\Crypto(); }
    private function settings_service() { return new \JKSH\Services\Settings(); }
    public function redirect_uri() { return rest_url( 'jksh/v1/pinterest/oauth/callback' ); }
    public function scopes() { return [ 'boards:read', 'boards:write', 'pins:read', 'pins:write', 'user_accounts:read' ]; }

    private function sandbox_config() {
        $raw = get_option( self::OPTION, [] );
        $raw = is_array( $raw ) ? $raw : [];
        $token = '';
        if ( ! empty( $raw['sandbox_token_encrypted'] ) && $this->deps_ready() ) {
            try { $token = (string) $this->crypto()->decrypt( (string) $raw['sandbox_token_encrypted'] ); } catch ( Throwable $e ) { $token = ''; }
        }
        return [
            'token' => $token,
            'enabled' => ! empty( $raw['sandbox_proof_enabled'] ),
            'verified' => ! empty( $raw['sandbox_verified'] ),
            'username' => sanitize_text_field( (string) ( $raw['sandbox_username'] ?? '' ) ),
            'board_id' => sanitize_text_field( (string) ( $raw['sandbox_board_id'] ?? '' ) ),
            'board_name' => sanitize_text_field( (string) ( $raw['sandbox_board_name'] ?? '' ) ),
            'boards' => is_array( $raw['sandbox_boards'] ?? null ) ? $raw['sandbox_boards'] : [],
            'token_expires_at' => sanitize_text_field( (string) ( $raw['sandbox_token_expires_at'] ?? '' ) ),
            'verified_at_utc' => sanitize_text_field( (string) ( $raw['sandbox_verified_at_utc'] ?? '' ) ),
        ];
    }

    private function save_sandbox_config( array $in ) {
        $raw = get_option( self::OPTION, [] );
        $raw = is_array( $raw ) ? $raw : [];
        if ( ! empty( $in['clear'] ) ) {
            foreach ( [ 'sandbox_token_encrypted','sandbox_proof_enabled','sandbox_verified','sandbox_username','sandbox_board_id','sandbox_board_name','sandbox_boards','sandbox_token_expires_at','sandbox_verified_at_utc' ] as $key ) unset( $raw[ $key ] );
            update_option( self::OPTION, $raw, false );
            return;
        }
        if ( array_key_exists( 'token', $in ) && '' !== trim( (string) $in['token'] ) ) {
            $raw['sandbox_token_encrypted'] = $this->crypto()->encrypt( trim( (string) $in['token'] ) );
        }
        if ( array_key_exists( 'enabled', $in ) ) $raw['sandbox_proof_enabled'] = ! empty( $in['enabled'] );
        if ( array_key_exists( 'verified', $in ) ) $raw['sandbox_verified'] = ! empty( $in['verified'] );
        if ( array_key_exists( 'username', $in ) ) $raw['sandbox_username'] = sanitize_text_field( (string) $in['username'] );
        if ( array_key_exists( 'board_id', $in ) ) $raw['sandbox_board_id'] = sanitize_text_field( (string) $in['board_id'] );
        if ( array_key_exists( 'board_name', $in ) ) $raw['sandbox_board_name'] = sanitize_text_field( (string) $in['board_name'] );
        if ( array_key_exists( 'boards', $in ) ) $raw['sandbox_boards'] = is_array( $in['boards'] ) ? $in['boards'] : [];
        if ( array_key_exists( 'token_expires_at', $in ) ) $raw['sandbox_token_expires_at'] = sanitize_text_field( (string) $in['token_expires_at'] );
        if ( array_key_exists( 'verified_at_utc', $in ) ) $raw['sandbox_verified_at_utc'] = sanitize_text_field( (string) $in['verified_at_utc'] );
        update_option( self::OPTION, $raw, false );
    }

    private function sandbox_api( $method, $path, $token, $body = null ) {
        $args = [
            'method' => strtoupper( (string) $method ),
            'timeout' => 45,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
            ],
        ];
        if ( null !== $body ) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode( $body );
        }
        return $this->response( wp_remote_request( self::SANDBOX_API . $path, $args ) );
    }

    private function sandbox_discover_boards( $token ) {
        $out = []; $bookmark = ''; $guard = 0;
        do {
            $path = '/boards?page_size=100' . ( $bookmark ? '&bookmark=' . rawurlencode( $bookmark ) : '' );
            $r = $this->sandbox_api( 'GET', $path, $token );
            if ( ! $r['ok'] ) return [ 'ok' => false, 'items' => [], 'message' => $r['message'] ];
            foreach ( (array) ( $r['data']['items'] ?? [] ) as $b ) {
                if ( ! empty( $b['id'] ) ) {
                    $out[] = [
                        'id' => sanitize_text_field( (string) $b['id'] ),
                        'name' => sanitize_text_field( (string) ( $b['name'] ?? 'Board' ) ),
                        'privacy' => sanitize_key( (string) ( $b['privacy'] ?? '' ) ),
                    ];
                }
            }
            $bookmark = sanitize_text_field( (string) ( $r['data']['bookmark'] ?? '' ) );
            $guard++;
        } while ( $bookmark && $guard < 10 );
        return [ 'ok' => true, 'items' => $out, 'message' => '' ];
    }

    private function sandbox_readiness() {
        $s = $this->sandbox_config();
        $ready = ! empty( $s['token'] ) && ! empty( $s['verified'] ) && ! empty( $s['username'] ) && ! empty( $s['board_id'] );
        return [
            'configured' => ! empty( $s['token'] ),
            'enabled' => ! empty( $s['enabled'] ),
            'verified' => ! empty( $s['verified'] ),
            'ready' => $ready,
            'username' => $s['username'],
            'board_id' => $s['board_id'],
            'board_name' => $s['board_name'],
            'boards' => $s['boards'],
            'token_expires_at' => $s['token_expires_at'],
            'verified_at_utc' => $s['verified_at_utc'],
            'image_pins_supported' => true,
            'video_pins_supported' => false,
            'environment' => 'sandbox',
        ];
    }

    private function sandbox_mode_enabled() {
        $s = $this->sandbox_readiness();
        return ! empty( $s['enabled'] ) && ! empty( $s['ready'] );
    }

    public function save_sandbox_settings() {
        $this->guard( 'jksh_pinterest_save_sandbox' );
        $current = $this->sandbox_config();
        $token = trim( (string) wp_unslash( $_POST['sandbox_token'] ?? '' ) );
        if ( '' === $token ) $token = (string) $current['token'];
        if ( '' === $token ) $this->redirect( 'error', 'Generate a Pinterest Sandbox token first, then paste it here.' );

        $profile = $this->sandbox_api( 'GET', '/user_account', $token );
        if ( ! $profile['ok'] ) $this->redirect( 'error', 'Pinterest Sandbox token verification failed: ' . $profile['message'] );
        $username = sanitize_text_field( (string) ( $profile['data']['username'] ?? '' ) );
        if ( '' === $username ) $this->redirect( 'error', 'Pinterest Sandbox did not return a profile username.' );

        $boards = $this->sandbox_discover_boards( $token );
        if ( ! $boards['ok'] ) $this->redirect( 'error', 'Pinterest Sandbox board discovery failed: ' . $boards['message'] );

        $items = $boards['items'];
        $selected = sanitize_text_field( (string) wp_unslash( $_POST['sandbox_board_id'] ?? '' ) );
        $board = null;
        foreach ( $items as $item ) {
            if ( $selected && $selected === (string) $item['id'] ) { $board = $item; break; }
            if ( ! $board && 'JustKalinga Sandbox Proof' === (string) $item['name'] ) $board = $item;
        }

        if ( ! $board ) {
            $created = $this->sandbox_api( 'POST', '/boards', $token, [
                'name' => 'JustKalinga Sandbox Proof',
                'description' => 'Pinterest API Sandbox proof board for JustKalinga Standard Access review.',
            ] );
            if ( $created['ok'] && ! empty( $created['data']['id'] ) ) {
                $board = [
                    'id' => sanitize_text_field( (string) $created['data']['id'] ),
                    'name' => sanitize_text_field( (string) ( $created['data']['name'] ?? 'JustKalinga Sandbox Proof' ) ),
                    'privacy' => sanitize_key( (string) ( $created['data']['privacy'] ?? '' ) ),
                ];
                $items[] = $board;
            } elseif ( $items ) {
                $board = $items[0];
            }
        }

        if ( ! $board || empty( $board['id'] ) ) $this->redirect( 'error', 'Pinterest Sandbox connected, but no usable proof board could be found or created.' );

        $this->save_sandbox_config( [
            'token' => $token,
            'enabled' => ! empty( $_POST['sandbox_proof_enabled'] ),
            'verified' => true,
            'username' => $username,
            'board_id' => (string) $board['id'],
            'board_name' => (string) $board['name'],
            'boards' => $items,
            'token_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ),
            'verified_at_utc' => current_time( 'mysql', true ),
        ] );
        $this->redirect( 'success', 'Pinterest Sandbox verified securely. Image proof Pins can now publish as Sandbox Published.' );
    }

    public function disconnect_sandbox() {
        $this->guard( 'jksh_pinterest_disconnect_sandbox' );
        $this->save_sandbox_config( [ 'clear' => true ] );
        $this->redirect( 'success', 'Pinterest Sandbox disconnected. Production Pinterest was not changed.' );
    }

    private function config() {
        $raw = get_option( self::OPTION, [] ); $raw = is_array($raw) ? $raw : [];
        $secret = '';
        if ( ! empty($raw['app_secret_encrypted']) && $this->deps_ready() ) {
            try { $secret = (string) $this->crypto()->decrypt( (string) $raw['app_secret_encrypted'] ); } catch (Throwable $e) { $secret=''; }
        }
        return [
            'app_id' => sanitize_text_field((string)($raw['app_id']??'')),
            'app_secret' => $secret,
            'api_access_confirmed' => !empty($raw['api_access_confirmed']),
            'default_board_id' => sanitize_text_field((string)($raw['default_board_id']??'')),
            'live_publish_enabled' => !empty($raw['live_publish_enabled']),
            'standard_access_confirmed' => !empty($raw['standard_access_confirmed']),
        ];
    }

    private function save_config( array $in ) {
        $old = get_option(self::OPTION,[]); $old=is_array($old)?$old:[];
        $out=$old;
        if (array_key_exists('app_id',$in)) $out['app_id']=sanitize_text_field((string)$in['app_id']);
        if (array_key_exists('app_secret',$in) && ''!==trim((string)$in['app_secret'])) $out['app_secret_encrypted']=$this->crypto()->encrypt(trim((string)$in['app_secret']));
        if (array_key_exists('api_access_confirmed',$in)) $out['api_access_confirmed']=!empty($in['api_access_confirmed']);
        if (array_key_exists('default_board_id',$in)) $out['default_board_id']=sanitize_text_field((string)$in['default_board_id']);
        if (array_key_exists('live_publish_enabled',$in)) $out['live_publish_enabled']=!empty($in['live_publish_enabled']);
        if (array_key_exists('standard_access_confirmed',$in)) $out['standard_access_confirmed']=!empty($in['standard_access_confirmed']);
        update_option(self::OPTION,$out,false);
    }

    public function readiness() {
        $c = $this->config();
        $rows = $this->deps_ready() ? $this->accounts()->connected( self::PLATFORM ) : [];
        $verified = 0; $boards = [];
        foreach ( $rows as $row ) {
            $set = $this->accounts()->settings( $row );
            if ( ! empty( $set['profile_verified_by_api'] ) ) $verified++;
            if ( empty( $boards ) && ! empty( $set['boards'] ) && is_array( $set['boards'] ) ) $boards = $set['boards'];
        }
        $dry = $this->deps_ready() ? (bool) $this->settings_service()->get( 'dry_run', 1 ) : true;
        $configured = '' !== $c['app_id'] && '' !== $c['app_secret'];
        $sandbox = $this->sandbox_readiness();
        $production_possible = $configured && $c['api_access_confirmed'] && $c['standard_access_confirmed'] && $verified > 0 && count( $boards ) > 0 && '' !== $c['default_board_id'] && ! $dry && $c['live_publish_enabled'];
        $sandbox_possible = ! $dry && ! empty( $sandbox['enabled'] ) && ! empty( $sandbox['ready'] );
        return [
            'ok' => true,
            'version' => self::VERSION,
            'platform' => self::PLATFORM,
            'api_version' => 'v5',
            'configured' => $configured,
            'api_access_confirmed' => $c['api_access_confirmed'],
            'connected_accounts' => count( $rows ),
            'verified_accounts' => $verified,
            'boards_count' => count( $boards ),
            'default_board_id' => $c['default_board_id'],
            'dry_run' => $dry,
            'pinterest_live_publish_enabled' => $c['live_publish_enabled'],
            'standard_access_confirmed' => $c['standard_access_confirmed'],
            'live_publish_possible' => $production_possible || $sandbox_possible,
            'publisher_gate' => $sandbox_possible ? 'sandbox_ready' : ( ( $configured && $c['api_access_confirmed'] && $c['standard_access_confirmed'] && $verified > 0 && count( $boards ) > 0 && '' !== $c['default_board_id'] ) ? 'ready_to_arm' : 'locked' ),
            'active_environment' => $sandbox_possible ? 'sandbox' : ( $production_possible ? 'production' : 'none' ),
            'sandbox' => $sandbox,
            'redirect_uri' => $this->redirect_uri(),
            'scope' => $this->scopes(),
            'supported' => $sandbox_possible ? [ 'image_pin' ] : [ 'image_pin', 'video_pin' ],
            'note' => $sandbox_possible
                ? 'Pinterest Sandbox proof mode is active. Image Pins publish to the official Sandbox; video Pins remain disabled until Standard access is granted.'
                : 'Pinterest public production writes require Standard access, an API-verified account, a selected board, Dry Run OFF and the dedicated Pinterest live switch.',
        ];
    }

    public function save_settings(){ $this->guard('jksh_pinterest_save_settings'); $this->save_config([
        'app_id'=>wp_unslash($_POST['app_id']??''),'app_secret'=>wp_unslash($_POST['app_secret']??''),
        'api_access_confirmed'=>!empty($_POST['api_access_confirmed']),'standard_access_confirmed'=>!empty($_POST['standard_access_confirmed']),'default_board_id'=>wp_unslash($_POST['default_board_id']??''),
    ]); $this->redirect('success','Pinterest settings saved.'); }

    public function oauth_start(){ $this->guard('jksh_pinterest_oauth_start'); $c=$this->config();
        if(''===$c['app_id']||''===$c['app_secret']) $this->redirect('error','Save the Pinterest App ID and App Secret first.');
        if(!$c['api_access_confirmed']) $this->redirect('error','Confirm Pinterest API access before connecting.');
        $state=wp_generate_password(48,false,false);
        set_transient('jksh_pinterest_state_'.hash('sha256',$state),['hash'=>wp_hash($state),'user_id'=>get_current_user_id()],10*MINUTE_IN_SECONDS);
        $url=add_query_arg(['client_id'=>$c['app_id'],'redirect_uri'=>$this->redirect_uri(),'response_type'=>'code','scope'=>implode(',', $this->scopes()),'state'=>$state],self::OAUTH);
        $parsed=wp_parse_url($url);
        if(!is_array($parsed)||'https'!==($parsed['scheme']??'')||'www.pinterest.com'!==strtolower((string)($parsed['host']??''))) $this->redirect('error','Pinterest OAuth URL validation failed.');
        wp_redirect($url,302,'JK Social Pinterest'); exit;
    }

    public function oauth_callback(){
        $state=sanitize_text_field((string)($_GET['state']??''));
        $key='jksh_pinterest_state_'.hash('sha256',$state);
        $expected=get_transient($key);
        delete_transient($key);
        $hash=is_array($expected)?(string)($expected['hash']??''):(string)$expected;
        if(!$state||!$hash||!hash_equals($hash,wp_hash($state))) $this->redirect('error','Pinterest OAuth state validation failed.');
        if(!empty($_GET['error'])) $this->redirect('error','Pinterest authorization was not completed.');
        $code=sanitize_text_field((string)($_GET['code']??'')); if(!$code)$this->redirect('error','Pinterest did not return an authorization code.');
        $c=$this->config(); $token=$this->token_request(['grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>$this->redirect_uri()],$c);
        if(!$token['ok']||empty($token['data']['access_token'])) $this->redirect('error','Pinterest token exchange failed: '.$token['message']);
        $done=$this->complete_oauth($token['data']);
        if(is_wp_error($done)) $this->redirect('error',$done->get_error_message());
        $this->redirect('success','Pinterest connected, profile verified and boards discovered.');
    }

    public function oauth_rest_callback( $request ) {
        $_GET['state'] = sanitize_text_field( (string) $request->get_param('state') );
        $_GET['code']  = sanitize_text_field( (string) $request->get_param('code') );
        if ( $request->get_param('error') ) $_GET['error'] = sanitize_text_field( (string) $request->get_param('error') );
        $this->oauth_callback();
        return rest_ensure_response(['ok'=>false]);
    }

    private function token_request($body,$c){
        $r=wp_remote_post(self::API.'/oauth/token',['timeout'=>30,'headers'=>['Authorization'=>'Basic '.base64_encode($c['app_id'].':'.$c['app_secret']),'Content-Type'=>'application/x-www-form-urlencoded'],'body'=>$body]);
        return $this->response($r);
    }

    private function response($r){ if(is_wp_error($r)) return ['ok'=>false,'code'=>0,'data'=>[],'message'=>$r->get_error_message()]; $code=(int)wp_remote_retrieve_response_code($r); $raw=wp_remote_retrieve_body($r); $data=json_decode($raw,true); $data=is_array($data)?$data:[]; $msg=(string)($data['message']??$data['error_description']??$data['error']??('HTTP '.$code)); return ['ok'=>$code>=200&&$code<300,'code'=>$code,'data'=>$data,'message'=>sanitize_text_field($msg)]; }
    private function api($method,$path,$token,$body=null){ $args=['method'=>$method,'timeout'=>45,'headers'=>['Authorization'=>'Bearer '.$token,'Accept'=>'application/json']]; if(null!==$body){$args['headers']['Content-Type']='application/json';$args['body']=wp_json_encode($body);} return $this->response(wp_remote_request(self::API.$path,$args)); }

    private function discover_boards($token){ $out=[];$bookmark='';$guard=0; do{ $path='/boards?page_size=100'.($bookmark?'&bookmark='.rawurlencode($bookmark):''); $r=$this->api('GET',$path,$token); if(!$r['ok']) return ['ok'=>false,'items'=>[],'message'=>$r['message']]; foreach((array)($r['data']['items']??[]) as $b){ if(!empty($b['id']))$out[]=['id'=>sanitize_text_field((string)$b['id']),'name'=>sanitize_text_field((string)($b['name']??'Board')),'privacy'=>sanitize_key((string)($b['privacy']??''))]; } $bookmark=sanitize_text_field((string)($r['data']['bookmark']??'')); $guard++; }while($bookmark&&$guard<10); return ['ok'=>true,'items'=>$out,'message'=>'']; }

    private function complete_oauth($token_data){
        $access=sanitize_text_field((string)$token_data['access_token']); $profile=$this->api('GET','/user_account',$access); if(!$profile['ok']) return new WP_Error('jksh_pinterest_profile',$profile['message']);
        $username=sanitize_text_field((string)($profile['data']['username']??'')); if(!$username) return new WP_Error('jksh_pinterest_username','Pinterest did not return a username.');
        $boards=$this->discover_boards($access); if(!$boards['ok']) return new WP_Error('jksh_pinterest_boards',$boards['message']);
        $expires=absint($token_data['expires_in']??2592000); $expiry=gmdate('Y-m-d H:i:s',time()+$expires);
        $credentials=['access_token'=>$access,'refresh_token'=>sanitize_text_field((string)($token_data['refresh_token']??'')),'token_type'=>sanitize_key((string)($token_data['token_type']??'bearer')),'scope'=>sanitize_text_field((string)($token_data['scope']??implode(' ',$this->scopes())))];
        $settings=['username'=>$username,'account_type'=>sanitize_key((string)($profile['data']['account_type']??'')),'business_name'=>sanitize_text_field((string)($profile['data']['business_name']??'')),'website_url'=>esc_url_raw((string)($profile['data']['website_url']??'')),'profile_image'=>esc_url_raw((string)($profile['data']['profile_image']??'')),'profile_verified_by_api'=>true,'boards'=>$boards['items'],'required_scopes'=>$this->scopes(),'connector_phase'=>'pinterest-v0.1.1'];
        $id=$this->accounts()->upsert(['platform'=>self::PLATFORM,'account_name'=>'@'.$username,'external_account_id'=>$username,'status'=>'connected','credentials'=>$credentials,'settings'=>$settings,'token_expires_at'=>$expiry,'last_checked_at'=>current_time('mysql',true),'last_error'=>'']);
        $c=$this->config(); if(!$c['default_board_id']&&!empty($boards['items'][0]['id']))$this->save_config(['default_board_id'=>$boards['items'][0]['id']]);
        return $id;
    }

    public function test_account(){ $this->guard('jksh_pinterest_test_account'); $id=absint($_POST['account_id']??0); $a=$this->accounts()->get($id); if(!$a||self::PLATFORM!==(string)$a->platform)$this->redirect('error','Pinterest account not found.'); $token=$this->token_for($a); if(is_wp_error($token)){$this->accounts()->update_status($id,'attention',$token->get_error_message());$this->redirect('error',$token->get_error_message());} $p=$this->api('GET','/user_account',$token); if(!$p['ok']){$this->accounts()->update_status($id,'attention',$p['message']);$this->redirect('error','Pinterest connection test failed: '.$p['message']);} $this->accounts()->update_status($id,'connected',''); $this->redirect('success','Pinterest connection verified.'); }
    public function disconnect_account(){ $this->guard('jksh_pinterest_disconnect_account'); $id=absint($_POST['account_id']??0); $a=$this->accounts()->get($id); if($a&&self::PLATFORM===(string)$a->platform)$this->accounts()->disconnect($id); $this->redirect('success','Pinterest account disconnected locally.'); }

    private function token_for($account){ $creds=$this->accounts()->credentials($account); $access=(string)($creds['access_token']??''); if(!$access)return new WP_Error('jksh_pinterest_token','Pinterest access token is missing.'); $exp=!empty($account->token_expires_at)?strtotime((string)$account->token_expires_at.' UTC'):0; if(!$exp||$exp>time()+300)return $access; $refresh=(string)($creds['refresh_token']??''); if(!$refresh)return new WP_Error('jksh_pinterest_refresh','Pinterest refresh token is missing. Reconnect the account.'); $c=$this->config(); $r=$this->token_request(['grant_type'=>'refresh_token','refresh_token'=>$refresh],$c); if(!$r['ok']||empty($r['data']['access_token']))return new WP_Error('jksh_pinterest_refresh_failed','Pinterest token refresh failed: '.$r['message']); $new=$r['data']; if(empty($new['refresh_token']))$new['refresh_token']=$refresh; $settings=$this->accounts()->settings($account); $this->accounts()->upsert(['platform'=>self::PLATFORM,'account_name'=>(string)$account->account_name,'external_account_id'=>(string)$account->external_account_id,'status'=>'connected','credentials'=>['access_token'=>(string)$new['access_token'],'refresh_token'=>(string)$new['refresh_token'],'token_type'=>(string)($new['token_type']??'bearer'),'scope'=>(string)($new['scope']??($creds['scope']??''))],'settings'=>$settings,'token_expires_at'=>gmdate('Y-m-d H:i:s',time()+absint($new['expires_in']??2592000)),'last_checked_at'=>current_time('mysql',true),'last_error'=>'']); return (string)$new['access_token']; }

    private function primary_account(){ foreach($this->accounts()->connected(self::PLATFORM) as $a){$s=$this->accounts()->settings($a);if(!empty($s['profile_verified_by_api']))return $a;} return null; }
    private function fields($v){$f=json_decode((string)($v->fields_json??'{}'),true);return is_array($f)?$f:[];}
    private function media_ids($v){$m=json_decode((string)($v->media_json??'[]'),true);return is_array($m)?array_values(array_filter(array_map('absint',$m))):[];}
    private function text_limit($s,$n){$s=sanitize_textarea_field((string)$s); return function_exists('mb_substr')?mb_substr($s,0,$n):substr($s,0,$n);}

    private function preflight($v){
        $ids = $this->media_ids($v);
        if ( 1 !== count($ids) ) return ['ok'=>false,'message'=>'Pinterest publishing requires exactly one image or video attachment.'];
        $mime = (string) get_post_mime_type($ids[0]);

        if ( $this->sandbox_mode_enabled() ) {
            $sb = $this->sandbox_readiness();
            if ( empty($sb['board_id']) ) return ['ok'=>false,'message'=>'Pinterest Sandbox proof board is not configured.'];
            if ( ! str_starts_with($mime,'image/') ) return ['ok'=>false,'message'=>'Pinterest Sandbox supports image Pins only. Video Pins require Standard API access.'];
            return ['ok'=>true,'message'=>'Pinterest Sandbox image preflight passed.','environment'=>'sandbox'];
        }

        $a = $this->primary_account();
        if ( ! $a ) return ['ok'=>false,'message'=>'No API-verified Pinterest account is connected.'];
        $c = $this->config();
        if ( ! $c['default_board_id'] ) return ['ok'=>false,'message'=>'Select a default Pinterest board.'];
        if ( ! str_starts_with($mime,'image/') && ! str_starts_with($mime,'video/') ) return ['ok'=>false,'message'=>'Pinterest supports image or video Pins only.'];
        if ( str_starts_with($mime,'video/') ) {
            $f = $this->fields($v);
            $cover = absint($f['cover_attachment_id']??0);
            if ( ! $cover ) return ['ok'=>false,'message'=>'Pinterest video Pins require a cover image.'];
        }
        return ['ok'=>true,'message'=>'Pinterest production preflight passed.','environment'=>'production'];
    }

    private function publish($v,$payload){
        if ( $this->sandbox_mode_enabled() ) return $this->publish_sandbox($v,$payload); $pf=$this->preflight($v); if(!$pf['ok'])return ['ok'=>false,'retryable'=>false,'message'=>$pf['message']]; $a=$this->primary_account(); $token=$this->token_for($a); if(is_wp_error($token))return ['ok'=>false,'retryable'=>false,'message'=>$token->get_error_message()]; $c=$this->config(); $f=$this->fields($v); $ids=$this->media_ids($v); $id=$ids[0]; $mime=(string)get_post_mime_type($id); $title=$this->text_limit($f['title']??$v->title??'',100); $desc=$this->text_limit($f['description']??$v->description??$v->caption??'',800); $link=esc_url_raw((string)($f['link']??$f['product_url']??'')); $alt=$this->text_limit($f['alt_text']??'',500); $board=sanitize_text_field((string)($f['board_id']??$c['default_board_id'])); $body=['board_id'=>$board,'title'=>$title,'description'=>$desc]; if($link)$body['link']=$link; if($alt)$body['alt_text']=$alt;
        if(str_starts_with($mime,'image/')){ $url=wp_get_attachment_url($id); if(!$url)return ['ok'=>false,'retryable'=>false,'message'=>'Pinterest image URL could not be resolved.']; $body['media_source']=['source_type'=>'image_url','url'=>esc_url_raw($url),'is_standard'=>true]; }
        else { $media=$this->register_video($token,$id); if(is_wp_error($media))return ['ok'=>false,'retryable'=>true,'message'=>$media->get_error_message()]; $cover_id=absint($f['cover_attachment_id']??0); $cover=wp_get_attachment_url($cover_id); if(!$cover)return ['ok'=>false,'retryable'=>false,'message'=>'Pinterest video cover URL could not be resolved.']; $body['media_source']=['source_type'=>'video_id','media_id'=>$media,'cover_image_url'=>esc_url_raw($cover)]; }
        $r=$this->api('POST','/pins',$token,$body); if(!$r['ok']||empty($r['data']['id']))return ['ok'=>false,'retryable'=>$r['code']>=500||429===$r['code'],'message'=>'Pinterest Pin creation failed: '.$r['message'],'response'=>$r['data']]; $pin=sanitize_text_field((string)$r['data']['id']); $verify=$this->api('GET','/pins/'.rawurlencode($pin),$token); if(!$verify['ok'])return ['ok'=>false,'retryable'=>true,'message'=>'Pinterest returned a Pin ID but read-back verification failed: '.$verify['message'],'response'=>$r['data']]; return ['ok'=>true,'pending'=>false,'external_id'=>$pin,'external_url'=>'https://www.pinterest.com/pin/'.rawurlencode($pin).'/','message'=>'Pinterest Pin published and verified.','response'=>$verify['data'],'payload'=>$payload]; }


    private function publish_sandbox($v,$payload){
        $pf = $this->preflight($v);
        if ( ! $pf['ok'] ) return ['ok'=>false,'retryable'=>false,'message'=>$pf['message']];

        $sc = $this->sandbox_config();
        $f = $this->fields($v);
        $ids = $this->media_ids($v);
        $id = $ids[0];
        $url = wp_get_attachment_url($id);
        if ( ! $url ) return ['ok'=>false,'retryable'=>false,'message'=>'Pinterest Sandbox image URL could not be resolved.'];

        $title = $this->text_limit($f['title']??$v->title??'',100);
        $desc = $this->text_limit($f['description']??$v->description??$v->caption??'',800);
        $link = esc_url_raw((string)($f['link']??$f['product_url']??''));
        $alt = $this->text_limit($f['alt_text']??'',500);
        $body = [
            'board_id' => sanitize_text_field((string)$sc['board_id']),
            'title' => $title,
            'description' => $desc,
            'media_source' => [
                'source_type' => 'image_url',
                'url' => esc_url_raw($url),
                'is_standard' => true,
            ],
        ];
        if ( $link ) $body['link'] = $link;
        if ( $alt ) $body['alt_text'] = $alt;

        $r = $this->sandbox_api('POST','/pins',(string)$sc['token'],$body);
        if ( ! $r['ok'] || empty($r['data']['id']) ) {
            return ['ok'=>false,'retryable'=>$r['code']>=500||429===$r['code'],'message'=>'Pinterest Sandbox Pin creation failed: '.$r['message'],'response'=>$r['data'],'environment'=>'sandbox'];
        }

        $pin = sanitize_text_field((string)$r['data']['id']);
        $verify = $this->sandbox_api('GET','/pins/'.rawurlencode($pin),(string)$sc['token']);
        if ( ! $verify['ok'] ) {
            return ['ok'=>false,'retryable'=>true,'message'=>'Pinterest Sandbox returned a Pin ID but read-back verification failed: '.$verify['message'],'response'=>$r['data'],'environment'=>'sandbox'];
        }

        return [
            'ok'=>true,
            'pending'=>false,
            'environment'=>'sandbox',
            'external_id'=>$pin,
            'external_url'=>'https://www.pinterest.com/pin/'.rawurlencode($pin).'/',
            'message'=>'Pinterest Sandbox Pin published and verified.',
            'response'=>$verify['data'],
            'payload'=>$payload,
            'sandbox_board_id'=>(string)$sc['board_id'],
            'sandbox_board_name'=>(string)$sc['board_name'],
        ];
    }

    private function register_video($token,$attachment_id){ if(!function_exists('curl_init'))return new WP_Error('jksh_pinterest_curl','PHP cURL is required for Pinterest video upload.'); $reg=$this->api('POST','/media',$token,['media_type'=>'video']); if(!$reg['ok']||empty($reg['data']['media_id'])||empty($reg['data']['upload_url']))return new WP_Error('jksh_pinterest_media_register','Pinterest video registration failed: '.$reg['message']); $media=sanitize_text_field((string)$reg['data']['media_id']); $file=get_attached_file($attachment_id); if(!$file||!is_readable($file))return new WP_Error('jksh_pinterest_video_file','Pinterest video source file is not readable.'); $fields=(array)($reg['data']['upload_parameters']??[]); $post=[]; foreach($fields as $k=>$v)$post[(string)$k]=(string)$v; $post['file']=new CURLFile($file,(string)get_post_mime_type($attachment_id),wp_basename($file)); $ch=curl_init((string)$reg['data']['upload_url']); curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$post,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>180,CURLOPT_HEADER=>false]); curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch); if(!in_array($code,[200,201,204],true))return new WP_Error('jksh_pinterest_video_upload','Pinterest video upload failed'.($err?': '.$err:'').' (HTTP '.$code.').'); for($i=0;$i<20;$i++){sleep(3);$check=$this->api('GET','/media/'.rawurlencode($media),$token);if($check['ok']){$status=strtolower((string)($check['data']['status']??''));if(in_array($status,['succeeded','success'],true))return $media;if(in_array($status,['failed','error'],true))return new WP_Error('jksh_pinterest_media_failed','Pinterest video processing failed.');}} return new WP_Error('jksh_pinterest_media_timeout','Pinterest video processing did not finish in time.'); }

    public function process_job($job_id){ if(!$this->deps_ready()||!class_exists('JKSH\\Repositories\\JobRepository'))return; $jobs=new \JKSH\Repositories\JobRepository(); $job=$jobs->get(absint($job_id)); if(!$job||self::PLATFORM!==sanitize_key((string)$job->platform)||in_array((string)$job->status,['published','dry_run_complete','cancelled','failed','blocked'],true))return; $variants=new \JKSH\Repositories\VariantRepository(); $v=$variants->get((int)$job->variant_id); if(!$v)return; $payload=json_decode((string)($job->payload_json??'{}'),true);$payload=is_array($payload)?$payload:[];$mode=sanitize_key((string)($job->publish_mode??'dry_run')); $now=current_time('mysql',true); $attempts=(int)$job->attempts+1; $jobs->update((int)$job->id,['status'=>'processing','attempts'=>$attempts,'started_at'=>$now]); $pf=$this->preflight($v);
        if('dry_run'===$mode){ if(!$pf['ok']){$jobs->update((int)$job->id,['status'=>'failed','last_error'=>$pf['message'],'completed_at'=>$now]);$variants->update_status((int)$v->id,'failed');return;} $jobs->update((int)$job->id,['status'=>'dry_run_complete','completed_at'=>$now,'last_error'=>'']);$variants->update_status((int)$v->id,'dry_run_complete');return; }
        $c=$this->config(); if(!$this->sandbox_mode_enabled()&&!$c['live_publish_enabled']){$jobs->update((int)$job->id,['status'=>'blocked','last_error'=>'Pinterest live publishing master switch is OFF.','completed_at'=>$now]);$variants->update_status((int)$v->id,'blocked');return;}
        $res=$this->publish($v,$payload);
        if(!empty($res['ok'])){
            $eid=sanitize_text_field((string)($res['external_id']??''));
            $eurl=esc_url_raw((string)($res['external_url']??''));
            $is_sandbox='sandbox'===sanitize_key((string)($res['environment']??'production'));
            $job_response=is_array($res['response']??null)?$res['response']:[];
            if($is_sandbox){
                $job_response['environment']='sandbox';
                $job_response['sandbox_published']=true;
                $job_response['sandbox_pin_id']=$eid;
                $job_response['sandbox_pin_url']=$eurl;
            }
            $jobs->update((int)$job->id,[
                'status'=>'published',
                'payload_json'=>wp_json_encode($res['payload']??$payload),
                'last_response_json'=>wp_json_encode($job_response),
                'last_error'=>'',
                'completed_at'=>current_time('mysql',true)
            ]);
            $variants->update_status((int)$v->id,'published',['external_id'=>$eid,'external_url'=>$eurl]);

            if($is_sandbox){
                $vf=$this->fields($v);
                $vf['jksh_environment']='sandbox';
                $vf['sandbox_published']=true;
                $vf['sandbox_pin_id']=$eid;
                $vf['sandbox_pin_url']=$eurl;
                $vf['sandbox_board_id']=sanitize_text_field((string)($res['sandbox_board_id']??''));
                $vf['sandbox_board_name']=sanitize_text_field((string)($res['sandbox_board_name']??''));
                $vf['sandbox_published_at_utc']=current_time('mysql',true);
                $variants->update_fields((int)$v->id,['fields_json'=>wp_json_encode($vf)]);
            }

            (new \JKSH\Repositories\LogRepository())->add([
                'job_id'=>(int)$job->id,
                'content_id'=>(int)$job->content_id,
                'variant_id'=>(int)$job->variant_id,
                'action'=>$is_sandbox?'pinterest_sandbox_published':'publish',
                'result'=>'success',
                'external_id'=>$eid,
                'external_url'=>$eurl,
                'message'=>(string)$res['message'],
                'context'=>['platform'=>self::PLATFORM,'environment'=>$is_sandbox?'sandbox':'production']
            ]);
            global $wpdb;
            $t=$wpdb->prefix.'jksh_jobs';
            $remaining=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE content_id=%d AND id<>%d AND status NOT IN ('published','published_manual','cancelled')",(int)$job->content_id,(int)$job->id));
            if(0===$remaining)(new \JKSH\Repositories\ContentRepository())->update_status((int)$job->content_id,'published');
            return;
        }
        $retry=!empty($res['retryable'])&&$attempts<(int)$job->max_attempts; if($retry){$delay=[1=>60,2=>180,3=>600][$attempts]??600;$jobs->update((int)$job->id,['status'=>'queued','last_error'=>(string)$res['message'],'last_response_json'=>wp_json_encode($res['response']??[])]);wp_schedule_single_event(time()+$delay,self::ACTION,[(int)$job->id]);}else{$jobs->update((int)$job->id,['status'=>'failed','last_error'=>(string)$res['message'],'last_response_json'=>wp_json_encode($res['response']??[]),'completed_at'=>current_time('mysql',true)]);$variants->update_status((int)$v->id,'failed');}
    }

    public function rest_routes(){ register_rest_route('jksh/v1','/pinterest/oauth/callback',['methods'=>'GET','permission_callback'=>'__return_true','callback'=>[$this,'oauth_rest_callback']]); register_rest_route('jksh/v1','/pinterest/readiness',['methods'=>'GET','permission_callback'=>fn()=>current_user_can('manage_options'),'callback'=>fn()=>rest_ensure_response($this->readiness())]); register_rest_route('jksh/v1','/pinterest/boards',['methods'=>'GET','permission_callback'=>fn()=>current_user_can('manage_options'),'callback'=>function(){ $a=$this->primary_account();if(!$a)return new WP_Error('jksh_pinterest_account','Pinterest is not connected.',['status'=>409]);$t=$this->token_for($a);if(is_wp_error($t))return $t;return rest_ensure_response($this->discover_boards($t)); }]); register_rest_route('jksh/v1','/pinterest/publisher-switch',['methods'=>'POST','permission_callback'=>fn()=>current_user_can('manage_options'),'callback'=>function($req){$enabled=(bool)$req->get_param('enabled');if(!$req->get_param('confirm'))return new WP_Error('jksh_pinterest_confirm','Confirmation is required.',['status'=>400]);$this->save_config(['live_publish_enabled'=>$enabled]);return rest_ensure_response($this->readiness());}]); }

    public function admin_footer(){ if(!is_admin()||'jksh-accounts'!==sanitize_key((string)($_GET['page']??''))||!$this->deps_ready())return; $c=$this->config();$r=$this->readiness();$sb=$this->sandbox_readiness();$sc=$this->sandbox_config();$accounts=$this->accounts()->connected(self::PLATFORM);$boards=[];if($accounts){$s=$this->accounts()->settings($accounts[0]);$boards=is_array($s['boards']??null)?$s['boards']:[];} ?>
      <section id="jksh-pinterest-foundation-v010" class="jksh-card" style="display:none">
        <h2>Pinterest <span class="jksh-badge status-<?php echo esc_attr($r['verified_accounts']?'connected':($r['configured']?'attention':'not_connected')); ?>"><?php echo esc_html($r['verified_accounts']?'Connected':'Foundation'); ?></span></h2>
        <p><strong>Pinterest v0.1.5:</strong> one unified Pinterest control panel for Production + Sandbox proof publishing, secure credentials, board discovery, image/video production Pins, image-only Sandbox Pins and external read-back verification.</p>
        <form class="jksh-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <input type="hidden" name="action" value="jksh_pinterest_save_settings"><?php wp_nonce_field('jksh_pinterest_save_settings'); ?>
          <p><label><strong>Pinterest App ID</strong><br><input type="text" name="app_id" value="<?php echo esc_attr($c['app_id']); ?>" style="width:100%"></label></p>
          <p><label><strong>Pinterest App Secret</strong><br><input type="password" name="app_secret" placeholder="<?php echo $c['app_secret']?'Saved securely - leave blank to keep it':'Enter App Secret'; ?>" style="width:100%"></label></p>
          <p><label><input type="checkbox" name="api_access_confirmed" value="1" <?php checked($c['api_access_confirmed']); ?>> I confirm this Pinterest app has approved API access.</label></p>
          <p><label><input type="checkbox" name="standard_access_confirmed" value="1" <?php checked($c['standard_access_confirmed']); ?>> I confirm this app has Pinterest <strong>Standard access</strong> for public production Pins.</label></p>
          <?php if($boards): ?><p><label><strong>Default publishing board</strong><br><select name="default_board_id" style="width:100%"><option value="">Select board</option><?php foreach($boards as $b): ?><option value="<?php echo esc_attr($b['id']); ?>" <?php selected($c['default_board_id'],$b['id']); ?>><?php echo esc_html($b['name'].' · '.$b['id']); ?></option><?php endforeach; ?></select></label></p><?php endif; ?>
          <p><button class="button button-primary">Save Pinterest settings</button></p>
        </form>
        <hr><p><strong>OAuth redirect URI</strong><br><code><?php echo esc_html($this->redirect_uri()); ?></code></p><p><strong>Required scopes</strong><br><code><?php echo esc_html(implode(', ',$this->scopes())); ?></code></p>
        <p><strong>Configured:</strong> <?php echo $r['configured']?'Yes':'No'; ?> &nbsp; <strong>Verified account:</strong> <?php echo (int)$r['verified_accounts']; ?> &nbsp; <strong>Boards:</strong> <?php echo (int)$r['boards_count']; ?> &nbsp; <strong>Standard access:</strong> <?php echo !empty($r['standard_access_confirmed'])?'Confirmed':'Not confirmed'; ?></p>
        <?php if($r['configured']&&$r['api_access_confirmed']): ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="jksh_pinterest_oauth_start"><?php wp_nonce_field('jksh_pinterest_oauth_start'); ?><button class="button button-hero">Connect / Reconnect Pinterest</button></form><?php endif; ?>
        <?php if($accounts): ?><h3>Connected Pinterest account</h3><table class="widefat striped"><thead><tr><th>Profile</th><th>Verified</th><th>Boards</th><th>Token expiry</th><th>Actions</th></tr></thead><tbody><?php foreach($accounts as $a):$s=$this->accounts()->settings($a);?><tr><td><strong><?php echo esc_html($a->account_name); ?></strong></td><td><?php echo !empty($s['profile_verified_by_api'])?'Yes':'No'; ?></td><td><?php echo count((array)($s['boards']??[])); ?></td><td><?php echo esc_html((string)$a->token_expires_at); ?></td><td><form style="display:inline" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="jksh_pinterest_test_account"><input type="hidden" name="account_id" value="<?php echo (int)$a->id; ?>"><?php wp_nonce_field('jksh_pinterest_test_account'); ?><button class="button">Test</button></form> <form style="display:inline" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="jksh_pinterest_disconnect_account"><input type="hidden" name="account_id" value="<?php echo (int)$a->id; ?>"><?php wp_nonce_field('jksh_pinterest_disconnect_account'); ?><button class="button button-link-delete">Disconnect</button></form></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
        <hr style="margin:24px 0">
        <div id="jksh-pinterest-sandbox-inline-v015">
          <h3 style="display:flex;align-items:center;gap:8px;">Pinterest Sandbox <span class="jksh-badge status-<?php echo !empty($sb['ready'])?'connected':'not_connected'; ?>"><?php echo !empty($sb['ready'])?'Connected':'Not connected'; ?></span></h3>
          <p><strong>Standard Access proof mode.</strong> This lives inside the real Pinterest connector. Production OAuth above is untouched. Sandbox supports <strong>image Pins only</strong>; video Pins stay blocked until Pinterest grants Standard API access.</p>
          <form class="jksh-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="jksh_pinterest_save_sandbox"><?php wp_nonce_field('jksh_pinterest_save_sandbox'); ?>
            <p><label><strong>Sandbox access token</strong><br><input type="password" name="sandbox_token" value="" autocomplete="new-password" placeholder="<?php echo !empty($sb['configured'])?'Saved securely - leave blank to keep it':'Paste the token generated in Pinterest Sandbox'; ?>" style="width:100%"></label></p>
            <p><label><input type="checkbox" name="sandbox_proof_enabled" value="1" <?php checked(!empty($sb['enabled'])); ?>> Use Pinterest Sandbox for proof image Pins until Standard API access is granted.</label></p>
            <?php if(!empty($sb['boards'])): ?><p><label><strong>Sandbox proof board</strong><br><select name="sandbox_board_id" style="width:100%"><?php foreach($sb['boards'] as $b): ?><option value="<?php echo esc_attr($b['id']); ?>" <?php selected($sb['board_id'],$b['id']); ?>><?php echo esc_html($b['name'].' · '.$b['id']); ?></option><?php endforeach; ?></select></label></p><?php endif; ?>
            <p><button class="button button-primary"><?php echo !empty($sb['ready'])?'Save / Re-verify Sandbox':'Save & verify Sandbox token'; ?></button></p>
          </form>
          <?php if(!empty($sb['ready'])): ?>
            <table class="widefat striped" style="margin-top:12px"><tbody>
              <tr><th>Sandbox profile</th><td><strong>@<?php echo esc_html($sb['username']); ?></strong></td></tr>
              <tr><th>Proof board</th><td><?php echo esc_html($sb['board_name']); ?><br><code><?php echo esc_html($sb['board_id']); ?></code></td></tr>
              <tr><th>Environment</th><td><strong>Sandbox</strong> · image Pins only</td></tr>
              <tr><th>Token expiry</th><td><?php echo esc_html($sb['token_expires_at']?:'Approximately 30 days from generation'); ?> UTC</td></tr>
              <tr><th>Content Library receipt</th><td><strong>Sandbox Published</strong> + Pin ID + Open link after verified success</td></tr>
            </tbody></table>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:12px" onsubmit="return confirm('Disconnect only the Pinterest Sandbox token? Production Pinterest will remain connected.');">
              <input type="hidden" name="action" value="jksh_pinterest_disconnect_sandbox"><?php wp_nonce_field('jksh_pinterest_disconnect_sandbox'); ?>
              <button class="button button-link-delete">Disconnect Sandbox</button>
            </form>
          <?php endif; ?>
        </div>
      </section>
      <script>
      (()=>{
        const mountPinterest = () => {
          const card=document.getElementById('jksh-pinterest-foundation-v010');
          const panelGrid=document.querySelector('[data-account-panel="pinterest"] .jksh-account-panel-grid');
          if(!card||!panelGrid) return false;
          panelGrid.querySelectorAll('.jksh-card').forEach(existing=>{ if(existing!==card) existing.remove(); });
          card.style.display='block';
          card.classList.add('jksh-account-card-wide');
          if(card.parentElement!==panelGrid) panelGrid.appendChild(card);

          const grid=document.querySelector('.jksh-account-health-grid');
          if(grid&&!grid.querySelector('[data-open-account-tab="pinterest"]')){
            const b=document.createElement('button');
            b.type='button'; b.className='jksh-health-card'; b.dataset.openAccountTab='pinterest';
            b.innerHTML='<span class="jksh-health-icon">P</span><span><small>Pinterest</small><strong><?php echo (int)$r['verified_accounts']; ?> verified</strong><em><?php echo esc_js($r['verified_accounts'] ? (!empty($r['standard_access_confirmed']) ? ($r['pinterest_live_publish_enabled'] ? 'Native publisher armed' : 'Publisher ready · switch off') : 'Standard access required') : 'Connection required'); ?></em></span>';
            grid.appendChild(b);
            b.addEventListener('click',()=>{ const tab=document.querySelector('[data-account-tab="pinterest"]'); if(tab) tab.click(); });
          }
          return true;
        };
        if(!mountPinterest()){
          let tries=0;
          const timer=setInterval(()=>{ tries++; if(mountPinterest()||tries>20) clearInterval(timer); },100);
        }
      })();
      </script>
    <?php }

    private function guard($action){ if(!current_user_can('manage_options'))wp_die('Not allowed.'); check_admin_referer($action); }
    private function redirect($type,$msg){ wp_safe_redirect(add_query_arg(['page'=>'jksh-accounts','jksh_notice'=>$type,'jksh_msg'=>$msg],admin_url('admin.php')).'#account-pinterest'); exit; }
}

add_action('plugins_loaded',function(){ if(class_exists('JKSH\\Plugin')) JKSH_Pinterest_Connector::instance(); },30);
