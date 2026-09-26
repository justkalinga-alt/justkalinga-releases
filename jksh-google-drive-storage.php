<?php
/**
 * Plugin Name: JKSH Google Drive Storage
 * Description: Routes JK Social Upload videos over 50 MB directly from the browser to Google Drive. WordPress stores metadata only.
 * Version: 1.0.1
 * Author: JustKalinga
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class JKSH_Drive_Storage_V101 {
    const VERSION='1.0.1', OPTION='jksh_drive_storage_v101', YT_OPTION='jksh_youtube_config';
    const THRESHOLD=52428800, MAX_BYTES=524288000, PREFIX='jkdrv1.';
    const SCOPE='https://www.googleapis.com/auth/drive.file';
    const AUTH='https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN='https://oauth2.googleapis.com/token';
    const FILES='https://www.googleapis.com/drive/v3/files';
    const UPLOAD='https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable';
    private static $i;
    static function instance(){ return self::$i ?: self::$i=new self(); }
    private function __construct(){
        add_action('admin_menu',[$this,'menu'],30);
        add_action('admin_enqueue_scripts',[$this,'assets']);
        add_action('admin_post_jksh_drive_oauth_start',[$this,'oauth_start']);
        add_action('admin_post_jksh_drive_oauth_callback',[$this,'oauth_callback']);
        add_action('admin_post_jksh_drive_disconnect',[$this,'disconnect']);
        add_action('admin_post_jksh_drive_test',[$this,'test']);
        add_action('wp_ajax_jkssb_ui_begin',[$this,'begin'],1);
        add_action('wp_ajax_jksh_drive_register',[$this,'register'],1);
        add_action('rest_api_init',[$this,'routes']);
    }
    private function cfg(){ $x=get_option(self::OPTION,[]); return is_array($x)?$x:[]; }
    private function save($x){ update_option(self::OPTION,$x,false); }
    private function crypto(){ return class_exists('JKSH\\Security\\Crypto') ? new \JKSH\Security\Crypto() : null; }
    private function client(){
        $x=get_option(self::YT_OPTION,[]); $x=is_array($x)?$x:[]; $c=$this->crypto(); $secret='';
        if($c&&!empty($x['client_secret_encrypted'])) try{$secret=(string)$c->decrypt((string)$x['client_secret_encrypted']);}catch(Throwable $e){}
        return ['id'=>sanitize_text_field((string)($x['client_id']??'')),'secret'=>$secret];
    }
    private function redirect_uri(){ return admin_url('admin-post.php?action=jksh_drive_oauth_callback'); }
    private function page($a=[]){ return add_query_arg($a,admin_url('admin.php?page=jksh-drive-storage')); }
    private function bounce($type,$msg){ wp_safe_redirect($this->page(['jksh_drive_'.$type=>rawurlencode((string)$msg)])); exit; }
    private function guard($a){ if(!current_user_can('manage_options'))wp_die('Forbidden',403); check_admin_referer($a); }
    private function token_call($body){
        $r=wp_remote_post(self::TOKEN,['timeout'=>30,'redirection'=>0,'headers'=>['Content-Type'=>'application/x-www-form-urlencoded'],'body'=>$body]);
        if(is_wp_error($r))return $r; $d=json_decode((string)wp_remote_retrieve_body($r),true); $d=is_array($d)?$d:[]; $code=(int)wp_remote_retrieve_response_code($r);
        if($code<200||$code>=300||empty($d['access_token']))return new WP_Error('drive_token',sanitize_text_field((string)($d['error_description']??$d['error']??'Google token exchange failed.'))); return $d;
    }
    private function store_tokens($d,$keep=''){
        $c=$this->crypto(); if(!$c)return new WP_Error('drive_crypto','JK Social credential crypto is unavailable.'); $x=$this->cfg();
        try{$x['access']=$c->encrypt((string)$d['access_token']); $r=(string)($d['refresh_token']??$keep); if($r)$x['refresh']=$c->encrypt($r);}catch(Throwable $e){return new WP_Error('drive_encrypt','Could not securely store Drive tokens.');}
        $x['expires']=gmdate('Y-m-d H:i:s',time()+max(300,absint($d['expires_in']??3600))); $x['connected']=true; $x['scope']=self::SCOPE; $this->save($x); return true;
    }
    private function dec($k){ $x=$this->cfg(); $c=$this->crypto(); if(!$c||empty($x[$k]))return ''; try{return (string)$c->decrypt((string)$x[$k]);}catch(Throwable $e){return '';} }
    private function access(){
        $x=$this->cfg(); if(empty($x['connected']))return new WP_Error('drive_off','Google Drive storage is not connected.'); $a=$this->dec('access'); $exp=!empty($x['expires'])?strtotime($x['expires'].' UTC'):0; if($a&&$exp>time()+300)return $a;
        $refresh=$this->dec('refresh'); if(!$refresh)return new WP_Error('drive_refresh','Drive refresh token missing. Reconnect Drive.'); $cl=$this->client(); if(!$cl['id']||!$cl['secret'])return new WP_Error('drive_client','Existing Google OAuth client is unavailable.');
        $d=$this->token_call(['client_id'=>$cl['id'],'client_secret'=>$cl['secret'],'grant_type'=>'refresh_token','refresh_token'=>$refresh]); if(is_wp_error($d))return $d; $s=$this->store_tokens($d,$refresh); if(is_wp_error($s))return $s; return (string)$d['access_token'];
    }
    private function api($method,$url,$token,$body=null,$headers=[]){
        $args=['method'=>$method,'timeout'=>45,'redirection'=>0,'headers'=>array_merge(['Authorization'=>'Bearer '.$token,'Accept'=>'application/json'],$headers)]; if(null!==$body)$args['body']=$body; $r=wp_remote_request($url,$args); if(is_wp_error($r))return $r;
        $d=json_decode((string)wp_remote_retrieve_body($r),true); $d=is_array($d)?$d:[]; $code=(int)wp_remote_retrieve_response_code($r); if($code<200||$code>=300)return new WP_Error('drive_api',sanitize_text_field((string)($d['error']['message']??'Google Drive API request failed.'))); return ['r'=>$r,'d'=>$d];
    }
    private function folder($token,$name,$parent=''){
        $m=['name'=>$name,'mimeType'=>'application/vnd.google-apps.folder']; if($parent)$m['parents']=[$parent]; $r=$this->api('POST',self::FILES.'?fields=id,name,parents',$token,wp_json_encode($m),['Content-Type'=>'application/json; charset=UTF-8']); if(is_wp_error($r))return $r; return sanitize_text_field((string)($r['d']['id']??''));
    }
    private function ensure_folders($token){
        $x=$this->cfg(); $root=sanitize_text_field((string)($x['root']??'')); $large=sanitize_text_field((string)($x['large']??''));
        if(!$root){$root=$this->folder($token,'JK Social Hub Media Vault');if(is_wp_error($root)||!$root)return is_wp_error($root)?$root:new WP_Error('drive_folder','Could not create Drive vault.');}
        if(!$large){$large=$this->folder($token,'Large Media',$root);if(is_wp_error($large)||!$large)return is_wp_error($large)?$large:new WP_Error('drive_folder','Could not create Large Media folder.');}
        $x['root']=$root;$x['large']=$large;$this->save($x);return ['root'=>$root,'large'=>$large];
    }
    function menu(){ add_submenu_page('jksh-social-upload','Google Drive Storage','Drive Storage','manage_options','jksh-drive-storage',[$this,'page_html']); }
    function page_html(){
        if(!current_user_can('manage_options'))return; $x=$this->cfg(); $cl=$this->client(); $ok=!empty($x['connected']); $s=isset($_GET['jksh_drive_success'])?rawurldecode(sanitize_text_field(wp_unslash($_GET['jksh_drive_success']))):''; $e=isset($_GET['jksh_drive_error'])?rawurldecode(sanitize_text_field(wp_unslash($_GET['jksh_drive_error']))):'';
        echo '<div class="wrap"><h1>Google Drive Storage</h1>'; if($s)echo '<div class="notice notice-success"><p>'.esc_html($s).'</p></div>';if($e)echo '<div class="notice notice-error"><p>'.esc_html($e).'</p></div>';
        echo '<div class="card" style="max-width:850px"><h2>JK Social large-media vault</h2><p><strong>Routing:</strong> ≤50 MB → Supabase | &gt;50 MB → Google Drive | WordPress Media bytes → disabled.</p><p><strong>Google client:</strong> '.($cl['id']?'Ready, reusing YouTube OAuth client':'Missing').'</p><p><strong>Drive:</strong> '.($ok?'<span style="color:#08783d;font-weight:700">Connected</span>':'<span style="color:#b32d2e;font-weight:700">Not connected</span>').'</p><p><strong>Scope:</strong> <code>'.esc_html(self::SCOPE).'</code></p><p><strong>Redirect:</strong> <code>'.esc_html($this->redirect_uri()).'</code></p>';
        if(!empty($x['large']))echo '<p><strong>Large Media folder:</strong> <code>'.esc_html($x['large']).'</code></p>';
        $connect=wp_nonce_url(admin_url('admin-post.php?action=jksh_drive_oauth_start'),'jksh_drive_oauth_start'); echo '<p><a class="button button-primary button-hero" href="'.esc_url($connect).'">'.($ok?'Reconnect Drive':'Connect Google Drive').'</a>';
        if($ok){echo ' <a class="button" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=jksh_drive_test'),'jksh_drive_test')).'">Test</a> <a class="button button-link-delete" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=jksh_drive_disconnect'),'jksh_drive_disconnect')).'">Disconnect</a>';}
        echo '</p><p>Drive OAuth is separate from YouTube OAuth and requests <strong>Drive only</strong>, avoiding the invalid mixed-scope request.</p></div></div>';
    }
    function oauth_start(){
        $this->guard('jksh_drive_oauth_start'); $cl=$this->client(); if(!$cl['id']||!$cl['secret'])$this->bounce('error','Existing Google OAuth client is unavailable.'); $state=wp_generate_password(48,false,false); set_transient('jksh_drive_state_'.hash('sha256',$state),['u'=>get_current_user_id(),'h'=>wp_hash($state)],10*MINUTE_IN_SECONDS);
        $url=add_query_arg(['client_id'=>$cl['id'],'redirect_uri'=>$this->redirect_uri(),'response_type'=>'code','scope'=>self::SCOPE,'access_type'=>'offline','prompt'=>'consent','include_granted_scopes'=>'false','state'=>$state],self::AUTH); wp_redirect($url,302,'JK Social Drive'); exit;
    }
    function oauth_callback(){
        if(!current_user_can('manage_options'))wp_die('Forbidden',403); if(!empty($_GET['error']))$this->bounce('error',sanitize_text_field(wp_unslash($_GET['error']))); $state=sanitize_text_field(wp_unslash($_GET['state']??'')); $code=sanitize_text_field(wp_unslash($_GET['code']??'')); $k='jksh_drive_state_'.hash('sha256',$state);$st=get_transient($k);delete_transient($k);
        if(!$state||!$code||!is_array($st)||absint($st['u']??0)!==get_current_user_id()||!hash_equals((string)($st['h']??''),wp_hash($state)))$this->bounce('error','Drive OAuth state validation failed.'); $cl=$this->client(); $d=$this->token_call(['client_id'=>$cl['id'],'client_secret'=>$cl['secret'],'code'=>$code,'grant_type'=>'authorization_code','redirect_uri'=>$this->redirect_uri()]); if(is_wp_error($d))$this->bounce('error',$d->get_error_message()); $s=$this->store_tokens($d); if(is_wp_error($s))$this->bounce('error',$s->get_error_message()); $f=$this->ensure_folders((string)$d['access_token']); if(is_wp_error($f))$this->bounce('error',$f->get_error_message()); $this->bounce('success','Google Drive large-media storage connected.');
    }
    function disconnect(){ $this->guard('jksh_drive_disconnect');$x=$this->cfg();unset($x['access'],$x['refresh'],$x['expires']);$x['connected']=false;$this->save($x);$this->bounce('success','Drive disconnected locally.'); }
    function test(){ $this->guard('jksh_drive_test');$t=$this->access();if(is_wp_error($t))$this->bounce('error',$t->get_error_message());$x=$this->cfg();$id=sanitize_text_field((string)($x['large']??''));$r=$this->api('GET',self::FILES.'/'.rawurlencode($id).'?fields=id,name,mimeType',$t);if(is_wp_error($r))$this->bounce('error',$r->get_error_message());$this->bounce('success','Drive connection verified.'); }
    private function ui(){ if(!current_user_can('manage_options'))wp_send_json_error(['message'=>'Forbidden'],403);check_ajax_referer('jkssb_social_upload','nonce'); }
    function begin(){
        $bytes=absint($_POST['bytes']??0); if($bytes<=self::THRESHOLD)return; $this->ui(); $name=sanitize_file_name(wp_unslash($_POST['filename']??''));$mime=sanitize_mime_type(wp_unslash($_POST['mime_type']??'')); if(!$name||0!==strpos($mime,'video/')||$bytes>self::MAX_BYTES)wp_send_json_error(['message'=>'Drive routing currently supports video files over 50 MB and up to 500 MB.'],422);
        $t=$this->access();if(is_wp_error($t))wp_send_json_error(['message'=>'Google Drive is not connected. Open Social Upload → Drive Storage and connect Drive.'],409);$x=$this->cfg();$folder=sanitize_text_field((string)($x['large']??''));if(!$folder){$f=$this->ensure_folders($t);if(is_wp_error($f))wp_send_json_error(['message'=>$f->get_error_message()],500);$folder=$f['large'];}
        $fields='id,name,mimeType,size,parents,md5Checksum,webViewLink,createdTime';$url=self::UPLOAD.'&fields='.rawurlencode($fields);$meta=['name'=>$name,'mimeType'=>$mime,'parents'=>[$folder],'appProperties'=>['jksh'=>'1','storage_provider'=>'google_drive']];
        $r=wp_remote_post($url,['timeout'=>30,'redirection'=>0,'headers'=>['Authorization'=>'Bearer '.$t,'Content-Type'=>'application/json; charset=UTF-8','X-Upload-Content-Type'=>$mime,'X-Upload-Content-Length'=>(string)$bytes],'body'=>wp_json_encode($meta)]);if(is_wp_error($r))wp_send_json_error(['message'=>$r->get_error_message()],502);$session=(string)wp_remote_retrieve_header($r,'location');$code=(int)wp_remote_retrieve_response_code($r);if($code<200||$code>=300||!$session)wp_send_json_error(['message'=>'Could not start Google Drive resumable upload.'],502);
        $id=self::PREFIX.wp_generate_uuid4();set_transient('jksh_drive_upload_'.hash('sha256',$id),['u'=>get_current_user_id(),'name'=>$name,'mime'=>$mime,'bytes'=>$bytes,'w'=>absint($_POST['width']??0),'h'=>absint($_POST['height']??0),'dur'=>absint($_POST['duration_ms']??0),'folder'=>$folder],2*HOUR_IN_SECONDS);wp_send_json_success(['upload_id'=>$id,'storage_target'=>'google_drive','max_chunk_bytes'=>8388608,'drive_resumable_url'=>esc_url_raw($session),'provider'=>'google_drive']);
    }
    function register(){
        $this->ui();$id=sanitize_text_field(wp_unslash($_POST['upload_id']??''));if(0!==strpos($id,self::PREFIX))wp_send_json_error(['message'=>'Invalid Drive upload session.'],400);$k='jksh_drive_upload_'.hash('sha256',$id);$s=get_transient($k);if(!is_array($s)||absint($s['u']??0)!==get_current_user_id())wp_send_json_error(['message'=>'Drive upload session expired.'],403);$fid=sanitize_text_field(wp_unslash($_POST['drive_file_id']??''));if(!$fid)wp_send_json_error(['message'=>'Drive file ID missing.'],422);$t=$this->access();if(is_wp_error($t))wp_send_json_error(['message'=>$t->get_error_message()],401);
        $fields='id,name,mimeType,size,parents,md5Checksum,webViewLink,createdTime';$r=$this->api('GET',self::FILES.'/'.rawurlencode($fid).'?fields='.rawurlencode($fields),$t);if(is_wp_error($r))wp_send_json_error(['message'=>$r->get_error_message()],502);$f=$r['d'];if(!in_array((string)$s['folder'],array_map('strval',(array)($f['parents']??[])),true)||(int)($f['size']??0)!==(int)$s['bytes'])wp_send_json_error(['message'=>'Drive upload verification failed.'],409);
        $guid=!empty($f['webViewLink'])?esc_url_raw($f['webViewLink']):'https://drive.google.com/file/d/'.rawurlencode($fid).'/view';$aid=wp_insert_attachment(['post_title'=>sanitize_text_field(pathinfo($s['name'],PATHINFO_FILENAME)),'post_status'=>'inherit','post_mime_type'=>sanitize_mime_type($s['mime']),'guid'=>$guid],'',0,true);if(is_wp_error($aid))wp_send_json_error(['message'=>$aid->get_error_message()],500);$m=['filesize'=>(int)$s['bytes'],'width'=>absint($s['w']??0),'height'=>absint($s['h']??0)];if(!empty($s['dur'])){$sec=(float)$s['dur']/1000;$m['length']=$sec;$m['length_formatted']=sprintf('%02d:%02d',floor($sec/60),round($sec)%60);}wp_update_attachment_metadata($aid,$m);
        foreach(['_jksh_storage_provider'=>'google_drive','_jksh_drive_file_id'=>$fid,'_jksh_drive_folder_id'=>$s['folder'],'_jksh_drive_web_view_link'=>$guid,'_jksh_drive_md5'=>sanitize_text_field((string)($f['md5Checksum']??'')),'_jksh_remote_bytes'=>(int)$s['bytes'],'_jksh_remote_filename'=>$s['name'],'_jksh_remote_mime'=>$s['mime']] as $mk=>$mv)update_post_meta($aid,$mk,$mv);delete_transient($k);wp_send_json_success(['attachment_id'=>(int)$aid,'external_attachment_id'=>(int)$aid,'storage'=>'google_drive','provider'=>'google_drive','drive_file_id'=>$fid,'drive'=>['id'=>$fid,'name'=>sanitize_text_field((string)($f['name']??$s['name'])),'size'=>(int)($f['size']??$s['bytes']),'mime_type'=>sanitize_mime_type((string)($f['mimeType']??$s['mime'])),'web_view_link'=>$guid]]);
    }
    function assets(){
        $page=sanitize_key(wp_unslash($_GET['page']??''));if('jksh-social-upload'!==$page)return;wp_enqueue_script('jksh-drive-router-v101',plugin_dir_url(__FILE__).'router.js',[],self::VERSION,true);wp_localize_script('jksh-drive-router-v101','JKSHDriveStorage',['prefix'=>self::PREFIX,'ajax'=>admin_url('admin-ajax.php')]);
    }
    function routes(){ register_rest_route('jksh-drive-storage/v1','/status',['methods'=>'GET','permission_callback'=>function(){return current_user_can('manage_options');},'callback'=>function(){$x=$this->cfg();$cl=$this->client();return rest_ensure_response(['ok'=>true,'version'=>self::VERSION,'connected'=>!empty($x['connected']),'google_client_ready'=>(bool)($cl['id']&&$cl['secret']),'scope'=>self::SCOPE,'redirect_uri'=>$this->redirect_uri(),'threshold_bytes'=>self::THRESHOLD,'large_folder_id'=>sanitize_text_field((string)($x['large']??'')),'routing'=>['small'=>'supabase','large'=>'google_drive','wordpress_media_bytes'=>false]]);}]); }
}
JKSH_Drive_Storage_V101::instance();