<?php
/**
 *	@file: liber.php
 *	@author: Soyoes 2014/01/28
 *****************************************************************************/
require 'conf/conf.inc';
const __SLASH__ = DIRECTORY_SEPARATOR;
define('APP_DIR', dirname(__FILE__));
define('IMAGE_DIR', APP_DIR . __SLASH__ . "webroot" . __SLASH__ . "images" . __SLASH__);
define('APP_NAME', end(explode("/", APP_DIR)));

$mdirs = glob(APP_DIR.__SLASH__.'modules'."/*", GLOB_ONLYDIR);
set_include_path(
get_include_path(). PATH_SEPARATOR
.implode(PATH_SEPARATOR, $mdirs). PATH_SEPARATOR
. APP_DIR.__SLASH__.'delegate'.PATH_SEPARATOR
. APP_DIR.__SLASH__.'modules'.__SLASH__ 
);
class Consts extends Conf{
	static $db_regexp_op = ['mysql'=>'REGEXP','postgres'=>'~'];
	static $db_query_filters;
	static $arr_query_filters;
	static $query_filter_names = [
		'eq' 	=> '=',
		'ne' 	=> '!',
		'lt' 	=> '<',
		'gt'	=> '>',
		'le' 	=> '<=',
		'ge'	=> '>=',
		'in'	=> '[]',
		'nin' 	=> '![]',
		'bt' 	=> '()',
		'nb' 	=> '!()',
		'l' 	=> '?',
		'nl' 	=> '!?',
		'm' 	=> '~',
		'nm' 	=> '!~',
		'mi' 	=> '~~',
		'nmi' 	=> '!~~'
	];
	static $error_codes = [
		'200'=>'OK',
		'201'=>'Created',
		'202'=>'Accepted',
		'204'=>'No Content',
		'301'=>'Moved Permanently',
		'302'=>'Found',
		'400'=>'Bad Request',
		'401'=>'Unauthorized',
		'403'=>'Forbidden',
		'404'=>'Not Found',
		'413'=>'Request Entity Too Large',
		'414'=>'Request-URI Too Large',
		'415'=>'Unsupported Media Type',
		'419'=>'Authentication Timeout',
		'500'=>'Internal Server Error',
		'501'=>'Not Implemented'];
	static function init(){
		self::$db_engine = strtolower(self::$db_engine);
		if(empty(self::$default_action)) self::$default_action = strtolower($_SERVER['REQUEST_METHOD']);
	}
}

if (!function_exists('getallheaders')) {
	function getallheaders() {
		$headers = [];
		foreach ($_SERVER as $name => $value) {
			if (substr($name, 0, 5) == 'HTTP_') {
				$headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
			}
		}
		return $headers;
	}
}

Consts::init();
function assign($key, $value){
	$render = REQ::getInstance()->getRender();
	$render->assign($key, $value);
}
function render_layout($file){
	$req = REQ::getInstance();
	$req->setRenderLayout($file);
}
function render($arg1=false, $arg2=false){
	switch(REQ::getInstance()->getFormat()){
		case 'json':return render_json($arg1);
		case 'text':return render_text($arg1);
		default:return render_html($arg1,$arg2);			
	}
}
function render_html($templateName=null, $datas=array()){
	$req = REQ::getInstance();
	list($render,$render_layout) = [$req->getRender(), $req->getRenderLayout()];	
	$appName = str_has($req->getURI(),'/'.APP_NAME.'/')?'/'.APP_NAME:'';
	$render->assign('TITLE',APP_NAME);
	if(!headers_sent()){
		header('Content-type: text/html; charset=UTF-8');
	}
	if($templateName&&empty($datas)&&is_array($templateName)){
		$datas = $templateName;
		$templateName=null;
	}
	if (!$templateName)
		$templateName = $req->getController().'_'.$req->getAction().'.html';
	if(!str_ends($templateName, '.html'))
		$templateName .= '.html';
	$render->render($templateName,$datas,$render_layout);
	$req->setResponseBody('true');
	if(isset($_REQUEST['after_wrapper']))
		after_wrapper($req->getParams());
}
function render_json($data){
	if($_REQUEST['__elog']){
		error_log("-----^^^^^^^^^^^^^");
		$logs = cache_get('elog_'.$_REQUEST['__elog'], false , false);
		$time = explode('_',$_REQUEST['__elog']);
		$data = [
			'__elog' => $logs?:[],
			'data' => $data,
			'time' => $time[1]
		];		
		cache_del('elog_'.$_REQUEST['__elog'],false);
	}
	$body = json_encode($data);
	if(!empty($data) && empty($body))
		$body = json_encode(utf8ize($data));
	header('Content-type: application/json; charset=UTF-8');
	REQ::getInstance()->setResponseBody($body);
	REQ::write($body,'json');
}
function render_text($text){
	header('Content-type: text/plain; charset=UTF-8');
	REQ::getInstance()->setResponseBody($text);
	REQ::write($text,'text');
}
function render_js($js){
	header('Content-type: application/javascript; charset=UTF-8');
	REQ::getInstance()->setResponseBody($js);
	REQ::write($js,'text');
}
function render_default_template(){
	$path = APP_DIR.__SLASH__.'views'.__SLASH__.REQ::getTemplateType();
	$req = REQ::getInstance();
	$ns = $req->getNamespace();
	if($ns!=''){
		$path .= '/'.str_replace('.','/',$ns);
	}
	$template_file = $req->getController().'_'.$req->getAction().'.html';
	if(file_exists($path.'/'.$template_file)){		render_html($template_file);
	}else{
				error(404,'html','action does not exist.');
	}
	REQ::quit();
}
function keygen($len,$chars=false){
	if(!isset($len))$len=16;
	if(!$chars) $chars = 'abcdefghijklmnopqrstuvwxyz0123456789_.;,-$%()!@';
	$key='';$clen=strlen($chars);
	for($i=0;$i<$len;$i++){
		$key.=$chars[rand(0,$clen-1)];
	}
	return $key;
}
function rand1($arr){	if(empty($arr))return null;
	return count($arr)>1?$arr[rand(0,count($arr)-1)]:$arr[0];
}
function utf8ize($d,$depth = 512) {
	function _utf8ize($d,$depth, $curDepth=0){
		if($curDepth>=$depth) return null;
		$curDepth++;
		if (is_array($d)){
			foreach ($d as $k => $v)
				$d[$k] = _utf8ize($v,$depth,$curDepth);
			return $d;
		}else if (is_string ($d))
			// return utf8_encode($d);
			return mb_convert_encoding($d, 'UTF-8', 'UTF-8');
	}
	return _utf8ize($d,$depth);
}
function error($code, $contentType='', $reason=''){
	if(empty($reason)&&!empty($contentType)&&!in_array($contentType, ['html','json','text'])){
		$reason=$contentType;$contentType='json';
	}
	// if(Conf::$mode=='Developing') elog($code.":".$reason, "ERROR");
	$msg = Consts::$error_codes[''.$code];
	header('HTTP/1.1 '.$code.' '.$msg, FALSE);
	$req = REQ::getInstance();
	$src = REQ::load_resources();
	$type = REQ::getClientType();
	$hasHtml = in_array($type.'/error_'.$code, $src['views'])||in_array($type.'\/error_'.$code, $src['views']);

	if(isset($contentType)&&!in_array($contentType, ['html','json','text'])){
		if(empty($reason)) {
			$reason=$contentType;
			$contentType=null;
		}
	}	
	if(empty($contentType)) 
		$contentType = is_ajax_request()?'json':'html';
	switch($contentType){
		case 'json':
			header('Content-type: application/json; charset=utf-8');
			if($_REQUEST['__elog']){
				elog(['code'=>$code, 'msg'=>$msg, 'reason'=>$reason], "ERROR");
				render_json([]);
			}else{
				echo '{"error":"'."$code $msg. $reason".'"}';
			}
			break;
		case "html":
			header('Content-type: text/html; charset=utf-8');
			if($hasHtml)
				render_html("error_$code.html",['code'=>$code,'msg'=>$msg,'reason'=>$reason]);
			else
				echo "<HTML><HEAD><link rel='stylesheet' href='/css/error.css' type='text/css'/><script href='/js/error.js'></script></HEAD><BODY><section><h1>$code ERROR</h1><p>$msg</p></section></BODY></HTML>";
			break;
		default:			header('Content-type: text/plain; charset=utf-8');
			echo "$code ERROR: $msg. $reason";
			break;
	}
	REQ::quit();
}
function is_https() {
    if(!empty($_SERVER['HTTP_X_FORWARDED_PROTO']))
        return strtoupper($_SERVER['HTTP_X_FORWARDED_PROTO']) == "HTTPS";
    else
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;
}
function redirect($url,$clientRedirect=false,$async_func=false) {
	$appName = str_has($_SERVER['REQUEST_URI'],APP_NAME.'/')?
	APP_NAME.'/':'';
	$redirectUrl = str_starts($url, 'http:') || str_starts($url, 'https:') ?
	   $url : (is_https() ? 'https' : 'http') . '://'.$_SERVER['HTTP_HOST'].'/'.$appName . $url;
		if(!$clientRedirect){
		header('HTTP/1.1 301 Moved Permanently');
		header('Location: '.$redirectUrl);
	}else{
		header('Content-type: text/html; charset=utf-8');
		echo '<script type="text/javascript">window.location="'.$redirectUrl.'";</script>';
	}

	if($async_func) {
		$args = array_slice(func_get_args(), 3);
		async($msg,function() use($msg,$async_func,$args){
			try{
				call_user_func_array ($async_func, $args);				
			}catch(Exception $e){
				error_log("exec exception:\n".$e->getTraceAsString());
				print $e->getMessage();
			}
			REQ::quit();
		});
	} else {
		REQ::quit();
	}
}
function call($url, $method, $data = [], $header = [], $options = []) {
	elog($url, 'CALL');
	$method = strtoupper($method);
	$postJSON = $method=="POSTJSON" ;
	if($postJSON) $method = 'POST';
    $defaults = $method == 'POST' || $method == 'PUT' ? [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_HEADER         => false,
        CURLOPT_RETURNTRANSFER => true,
		CURLOPT_VERBOSE		=> true,
		CURLOPT_SSL_VERIFYPEER => false,
		CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_POSTFIELDS     => is_string($data)?$data:http_build_query($data)
    ]:[
        CURLOPT_URL            => $url. (strpos($url, '?') === FALSE ? '?' : ''). http_build_query($data),
        CURLOPT_HEADER         => false,
        CURLOPT_RETURNTRANSFER => true,
		CURLOPT_VERBOSE		=> true,
		CURLOPT_SSL_VERIFYPEER => false,
		CURLOPT_SSL_VERIFYHOST => false
	];

	if($postJSON){
		$defaults[CURLOPT_POSTFIELDS] = is_array($data) ? json_encode($data) : $data;
		$defaults[CURLOPT_FOLLOWLOCATION] = 1;
		$header[]='Content-Type: application/json';
	}
	
	if (!empty($header)){
        $defaults[CURLOPT_HTTPHEADER] = $header;
    }
	$ch = curl_init($url);
	// elog($options + $defaults,"opts");
	curl_setopt_array($ch, $options + $defaults);
	
    if( ! $result = curl_exec($ch)){
        trigger_error(curl_error($ch));
	}
	if($result&&$options[CURLOPT_HEADER]==true) {
		$h_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$_REQUEST['__CURL_RESPONSE_HEADER_SIZE'] = $h_size;
	}
    curl_close($ch);
    return $result;
}
/**
 * @param $msg : obj/arr > json else:text
 * @param function $func: function to execute
 */
function async($msg,$func){
	ob_end_clean();
	header('Connection: close');
	ignore_user_abort(true); 	ob_start();
	if(is_array($msg)) {
		header('Content-type: application/json; charset=UTF-8');
		echo json_encode($msg);
	}else
		echo $msg;
	$size = ob_get_length();
	header("Content-Length: $size");
	ob_end_flush(); 
	flush();
	$args = array_slice(func_get_args(), 2);
	call_user_func_array ($func, $args);
}
function async_render_after($func) {
	header('Connection: close');
	ignore_user_abort(true);
	$size = ob_get_length();
	header("Content-Length: $size");
	ob_end_flush(); 
	flush();
	
	async("", $func);
}
function user_lang(){
	return isset($_REQUEST['@lang'])?$_REQUEST['@lang']:
					(!empty($_SESSION['lang']) ? $_SESSION['lang']:
						(isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])?
							substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2):Consts::$lang));
}
function T($key, $lang=false){
	$filename = null;
	if(!$lang){
       $lang = user_lang();
    }
	$text_func = function($fn){
		$file = join(__SLASH__,[APP_DIR,'conf','text.csv']);
		if (file_exists($file)){
			$lines = preg_split('/[\r\n]+/',file_get_contents($file));
			$idx = 0;
			$res = [];$langs = [];
			if (($handle = fopen($file, 'r')) !== FALSE) {
				$max_len = 0; 				$delimiter = ',';
				try {
					while (($cols = fgetcsv($handle, $max_len, $delimiter)) !== FALSE) {
						if($idx++==0){
							if($cols[0]!='id'){
								error_log('Language File Error: the first column of text.csv must have a name of "id" ');
								return [];
							}
							$langs = $cols;
							array_shift($langs);
							continue;
						}else{
							$c = 1;
							$id = $cols[0];
							$res[$id] = [];
							foreach ($langs as $l) 
								$res[$id][$l] = $cols[$c]?$cols[$c++]:"";
						}
					}
				} catch(Exception $e) {
					error_log('Language File Error: '.$e->getMessage());
				}
			}
			fclose($handle);
			return $res;
		}return [];
	};
	$texts = Consts::$mode=='Developing'?$text_func('__TEXTS__'):cache_get('__TEXTS__', $text_func);
	$lang = isset($texts[$key][$lang]) ? $lang : (isset($texts[$key][Consts::$lang]) ? Consts::$lang : false);

	if($lang){
		$text = $texts[$key][$lang];
		if(str_has($text,'%')){
			$args = array_slice(func_get_args(), 1);
			$enc = mb_detect_encoding($text);
			return $lang=='en'? vsprintf($text, $args) : 
				mb_convert_encoding(vsprintf(mb_convert_encoding($text,'UTF-8',$enc), $args),$enc,'UTF-8');
		}else
			return $text;
	}else{
		error_log("__ERR_WORD_NOT_EXISTS_($key,$lang)__, please check your /conf/text.csv");
		return "??";
		//return null;
	}
}

/**
 * this method can only detect any.js
 */
function is_ajax_request(){
	return $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
}

function parse_uri($uri, $method, &$params=[], $ua=""){
	$host =$_SERVER['SERVER_NAME'];
	if(empty($uri)) $uri = $_SERVER['REQUEST_URI'];
	if(empty($method)) $method = $_SERVER['REQUEST_METHOD'];
	if(empty($ua)) $ua = $_SERVER['HTTP_USER_AGENT'];
		if(!empty(Consts::$path_prefix)) 
		$uri =  preg_replace('/'.preg_quote(Consts::$path_prefix, '/').'/', '', $uri, 1);
		$uri = PathDelegate::rewriteURI($uri);
	if(!empty(Consts::$plugins)){
		foreach(Consts::$plugins as $n=>$pt){
			if(preg_match($pt, $_SERVER['HTTP_HOST'])){
				$_REQUEST['__SERVICE']=$n;
				break;
			}
		}
	}
	$uri = htmlEntities($uri, ENT_QUOTES|ENT_HTML401);
	$uri = preg_replace(['/\sHTTP.*/','/(\/)+/','/\/$/','/^[a-zA-Z0-9]/'], ['','/','',"/$1"], $uri);
	$parts = parse_url('http://'.$host.$uri);
	$uri = $parts['path'];
	$fmts = ['json','bson','text','html','csv'];
	if(isset($parts['query']))
		parse_str(str_replace(['&amp;','&quot;'], ['&','"'], $parts['query']),$params);
		if(($host=='localhost'||$host=="127.0.0.1") && (str_has($uri,'liber.php')||str_has($uri,'index.php')) && isset($params['__URL__']) ){
		$uri = $params['__URL__'];
		unset($params['__URL__']);
	}
	list($uri, $ext) = explode('.', $uri);
	$specifiedFmt = in_array($ext,$fmts);
	if($ext==1||$ext==""||$specifiedFmt){
		preg_match_all('/\/(?P<digit>\d+)(\/|$)/', $uri, $matches);
		if(!empty($matches['digit'])){
			// $params['@id'] = intval($matches['digit'][0]);
			$params['@id'] = $matches['digit'][0];
			$uri = str_replace('/'.$params['@id'],'',$uri);
			// $uri = preg_replace('/\/\d+\/*/', '/', $uri);
		}
		$rest = parse_rest_uri($uri, $method, $params);
		return ['uri'=>$uri, 'method'=>$method, 'params'=>$params, 'format'=>($specifiedFmt)?$format:false] + $rest;
	}else{		
		$uri = '/webroot'.$uri.'.'.$ext;
		return ['uri'=>$uri, 'method'=>$method, 'params'=>$params, 'static'=>true];
	}
}
function parse_rest_uri($uri, $method, &$params){
	$uri = preg_replace('/(^\/)|(\/$)/','',$uri);
	$uparts = explode('/',$uri);
	$uparts =ds_remove($uparts, '');
	$method = strtolower($method);
	if ($method == 'put' || $method == 'delete') {
        parse_str(file_get_contents('php://input'), $input);
        $params = array_merge($params, $input);
	}
	$target =($method=='post'||$method=='put')?$_POST: $_GET;
	foreach($target as $k=>$v)
		$params[$k] = $v;
	unset($params['__URL__']);
	$fmts = ['json','bson','text','html','csv'];
	$res = [];
	foreach($params as $k=>$v){
		if($k=='@format' && in_array($v, $fmts)) 
			$res['format'] = $v;
		if(preg_match('/^[\{\[].*[\}\]]$/',$v))			$params[$k] = $v;
		else
			$params[$k] = htmlEntities($v); 	}
	unset($params['@format']);
	if(isset($params['@test_mode'])) $_REQUEST['@test_mode']=1;
 	unset($params['@test_mode']);
	$resources = REQ::load_resources();
	list($namespace, $controller, $action) =
		['',Consts::$default_controller,Consts::$default_action];
	$len = count($uparts);
	if(empty($uparts)){$uparts=[$controller,$action];}
	if(count($uparts)==1)$uparts[]=$action;
	$servicePrefix = $_REQUEST['__SERVICE']?"_".$_REQUEST['__SERVICE']."/":'';

	$suri = "${servicePrefix}$uri";
	if(!empty($servicePrefix)) {
		array_unshift($uparts, "_".$_REQUEST['__SERVICE']);
		$len ++;
	}
	
	if($uri==''){
		$res['uri'] = $controller;
		// elog("ns1=$namespace, ctrl=$controller, act=$action");
	}else if(in_array("$uri",$resources['namespaces'])
		|| in_array("$suri",$resources['namespaces'])){//default controller
		$namespace = $uri;
		// elog("ns2=$namespace, ctrl=$controller, act=$action");
	}else if(in_array("$uri",$resources['controllers'])
		|| in_array("$suri",$resources['controllers'])){//controller exist with default action
		if(!empty($servicePrefix)) {
			array_shift($uparts); $len--;
		}
		$namespace = join('/',array_slice($uparts, 0 , $len-1));
		$controller = $uparts[$len-1];
        $action = $method;
		// elog("ns3=$namespace, ctrl=$controller, act=$action");
	}else if(in_array(join('/',array_slice($uparts, 0 , $len-1)),$resources['controllers'])){			$namespace = join('/',array_slice($uparts, 0 , $len-2));
		$controller = $uparts[$len-2];
		$action = $uparts[$len-1];
		// elog("ns4=$namespace, ctrl=$controller, act=$action");
	}else if(!empty($servicePrefix) && in_array(join('/',array_slice($uparts, 1 , $len-2)),$resources['controllers'])){			
		$namespace = join('/',array_slice($uparts, 1 , $len-3));
		$controller = $uparts[$len-2];
		$action = $uparts[$len-1];
		// elog("ns5=$namespace, ctrl=$controller, act=$action");
	}else{		
		if(str_starts($uri,'@')){
			$uri = substr($uparts[0],1);
			if(in_array($uri,$resources['schemas'])){
				$controller = '@REST';
				$res['schema_name'] = $uri;
				$schemaDef =db_schema($uri);
				$res['schema_def'] = $schemaDef;
				$action = $method;
			}
		}else if(in_array(REQ::getClientType().'/'.join('_',$uparts), $resources['views'])){			$res['static'] = join('_',$uparts).'.html';
		}else error(404,'html',$uri);
	}
	if(in_array($action,['get','post','put','delete']) && $method!=$action)
		error(405,false,'Method not allowed');
	$res['namespace'] = $namespace;
	$res['controller'] = $controller;
	$res['action'] = $action;
	$res['params'] = $params;
	return $res;
}
function parse_user_agent($ua=""){
	if(empty($ua)) $ua = $_SERVER['HTTP_USER_AGENT'];
		$type = 'pc';
	if(preg_match('/(curl|wget|ApacheBench)\//i',$ua))
		$type = 'cmd';
	else if(preg_match('/(iPhone|iPod|(Android.*Mobile)|BlackBerry|IEMobile)/i',$ua))
		$type = 'sm';
	else if(preg_match('/(iPad|MSIE.*Touch|Android)/',$ua))
		$type = 'pad';
		if(preg_match('/Googlebot|bingbot|msnbot|Yahoo|Y\!J|Yeti|Baiduspider|BaiduMobaider|ichiro|hotpage\.fr|Feedfetcher|ia_archiver|Tumblr|Jeeves\/Teoma|BlogCrawler/i',$ua))
		$bot = 'bot';
	else if(preg_match('/Googlebot-Image|msnbot-media/i',$ua))
		$bot = 'ibot';
	else 
		$bot = false;
	return ['type'=>$type,'bot'=>$bot];
}
function check_params($p, $keys, $code=400, $msg='Parameter error'){
	$res = [];
	if(!empty($keys)){
		if(is_string($keys))$keys=explode(',',$keys);
		foreach($keys as $k){
			if(!isset($p[$k])){
				if($_REQUEST['__elog'])
					elog("ERROR: '$k' is required", "check_params()");
				error($code,false,$msg);
			}
			$res[]=$p[$k];
		}
	}
	return $res;
}
class REQ {
	private static $resources = null;
	private static $instances = [];
	private static $db = null;
	private static $token = null;
	private static $client_type = 'pc';
	private static $template_type = 'pc';
	private static $client_bot = false;
	private static $test_mode = false;
	private $data = [];
	private $dispatched = false;
	private $interrupted = false;
	private $redirecting = null;
	private $is_thread = false;
	private $render = null;
	private $render_path = null;
	private $render_layout = '_layout.html';
	var $params = [];
	private $response_body = null;
	private function __construct(){}
	static function getDB(){return self::$db;}
	static function setDB($dbh){if(isset($dbh) && $dbh instanceof PDO)self::$db=$dbh;}
	static function getTemplateType(){return self::$template_type;}
	static function getClientType(){return self::$client_type;}
	static function getInstance($idx=0){$idx= $idx<0?count(self::$instances)+$idx:$idx;return self::$instances[$idx];}
	static function isTestMode(){return self::$test_mode;}
	static function stackSize(){return count(self::$instances);}
	static function dispatch($uri=false, $method=false, $params=[], $ua=''){
		if(strtoupper($_SERVER['REQUEST_METHOD'])=='OPTIONS' && strlen(Consts::$cross_domain_methods)>0){
			header('HTTP/1.1 200 OK');
			header('Content-type: application/json');
			header('Access-Control-Allow-Methods: '.preg_replace('/\s*,\s*/', ', ', Consts::$cross_domain_methods));
			exit;
		}
        header('Access-Control-Allow-Origin: *');
		$req = new REQ();
		self::$instances[]=$req; 		$ua = parse_user_agent($ua);
		self::$client_type = $ua['type'];
		self::$client_bot = $ua['bot'];
		$req->data = parse_uri($uri, $method, $params, $ua);
		$req->params = $req->data['params'];
		if(Consts::$session_enable && !isset($_SESSION)){
			Session::start();
			$_SESSION['lang'] = user_lang();
		}
		if(count(self::$instances)>1){
			$req->is_thread = true;
		}
		$hds = getallheaders();
		if($hds['Elog']||$hds['ELOG']){
			$_REQUEST['__elog']=keygen(32,'abcdefghijklmnopqrstuvwxyz0123456789').'_'.time();	
			elog($req->params, "params");
		}

		if(!empty($req->data['static']))
			return render_html($req->data['static']);
		return $req->process();
	}
	static function load_resources(){
		if(self::$resources)
			return self::$resources;
		self::$resources = cache_get('APP_RESOURCES', function($key){
			$ver = exec("cd ".APP_DIR."/; git log -1 | head -n 1 | awk '{print \$2}' ");
			if(empty($ver)){
				$lastTS = exec("cd ".APP_DIR."/;ls -lt | head -n 2 | tail -n 1 | awk '{print \$6,\$7,\$8}' ");
				$ver = strtotime($lastTS);
			}
			$ctrldir = APP_DIR.__SLASH__."controllers";
			exec("find $ctrldir",$res);
			$namespaces = []; $controllers = [];
			foreach($res as $f){
				$namespaces []= strtolower(preg_replace(["/^".str_replace("/","\/",APP_DIR.__SLASH__."controllers")."/",'/\/(.*)\.inc$/',"/^\//"],["","",""], $f));
				if(str_ends($f,".inc")){
					$ctl = strtolower(preg_replace(["/^".str_replace("/","\/",APP_DIR.__SLASH__."controllers")."/",'/\.inc$/',"/^\//"],["","",""], $f));
					$controllers[]= $ctl;
				}
			}
			$schemadir = APP_DIR.__SLASH__."conf".__SLASH__."schemas";
			exec("find $schemadir",$res2);
			$schemas = array_unique(array_map(function($e) use($schemadir){
				return strtolower(preg_replace(["/^".str_replace("/","\/",APP_DIR.__SLASH__."conf".__SLASH__."schemas")."/",'/\.ini$/',"/^\//"],["","",""], $e));
			},$res2));
			$vdir = APP_DIR.__SLASH__."views";
			exec("find -L $vdir",$res3);
			$views = array_unique(array_map(function($e){
				return strtolower(preg_replace(["/^".str_replace("/","\/",APP_DIR.__SLASH__."views".__SLASH__)."/",'/\.html$/',"/^\//"],["","",""], $e));
			},$res3));
			$view_types = glob($vdir.__SLASH__."*",GLOB_ONLYDIR);
			return [
				'version'		=> $ver,
				'namespaces' 	=> ds_remove(array_unique($namespaces), ''),
				'controllers' 	=> ds_remove(array_unique($controllers), ''),
				'schemas'		=> ds_remove($schemas, ''),
				'views'			=> ds_remove($views, ['','pc','sm','bot','ibot','pad','mail']),
				'view_types'	=> array_map(function($e){
					return end(preg_split('/[\/]/',$e));
				},$view_types)
			];
		},false);
		return self::$resources;
	}
	static function quit(){
		$last = array_pop(self::$instances);
		if($last)
			$last->interrupted = true;
		if(empty(self::$instances)){
			self::$db = null;
			exit;
		}
	}
	static function write($text, $format){
		if((self::$test_mode||$_REQUEST['@test_mode']) && $format=='json'){
			Tests::writeJSON($text);
		}else
			echo $text;
	}
	function getRender($path=null){
		if(!isset($this->render)){
			$src = self::load_resources();
			$vtypes = $src['view_types'];
			self::$template_type = in_array(self::$client_type,$vtypes)? self::$client_type:'pc';
			$data = $this->data;
			if ($path==null){
				$path = APP_DIR.__SLASH__.'views'.__SLASH__.self::$template_type;
				$path = $_REQUEST['__SERVICE']? $path.'/_'.$_REQUEST['__SERVICE']:$path;
				$path = $data['namespace']==''? $path:$path.'/'.$data['namespace'];
			}
			$render = Render::factory($path);
			$render->assign('CLIENT_TYPE',self::$template_type);
			$render->assign('controller',$data['controller']);
			$render->assign('action',$data['action']);
			$render->assign('APP_VER',Conf::$mode=="Developing"?time():$src['version']);
			$this->render_path = $path;
			$this->render = $render;
		}
		return $this->render;
	}
	function getRenderPath(){return $this->render_path;}
	function setRenderPath($path){if(isset($path) && is_string($path))$this->render_path=$path;}
	function getRenderLayout(){return $this->render_layout;}
	function setRenderLayout($path){if(isset($path) && is_string($path))$this->render_layout=$path;}
	function getNamespace(){return $this->data['namespace'];}
	function getController(){return $this->data['controller'];}
	function getAction(){return $this->data['action'];}
	function getFormat(){return $this->data['format'];}
	function getURI(){return $this->data['uri'];}
	function getMethod(){return $this->data['method'];}
	function getData($key){return empty($this->data)?null:$this->data[$key];}
	function setResponseBody($body){if(isset($body) && is_string($body))$this->response_body=$body;}
	public function process(){		
		if($this->dispatched===true)return;
        if (!preg_match('/product/i',Consts::$mode)) {
            error_log('URI: ' . $this->getURI());
            error_log('METHOD: ' . $_SERVER['REQUEST_METHOD']);
            error_log('PARAMETERS: ' . json_encode($this->params));
			error_log("HTTP_REFERER: ".$_SERVER["HTTP_REFERER"]);
        }
		// if(isset($_SESSION['member_id']))
		// 	elog($_SESSION['member_id'].":".$_SERVER['REQUEST_URI'],"MID");//to verify session
        try{			
			$data = $this->data;
			$filterNames = [];$filterCls = [];
			foreach (Consts::$filters as $fn => $pt) {
				if($pt=='*' || preg_match($pt, substr($this->data['uri'],1)))
					$filterNames[]=$fn;
			}
			$size = count($filterNames);
			for ($token=$size*(-1); $token<=$size; $token++){
				if(true===$this->interrupted)
					break;
				if ($token == 0){					
					$per = permission($_REQUEST['__SERVICE']);
					if($per != 200){				
						if($per == 401)  return error(401, false,'Permission ERROR : Sorry, You are not permited to do that.');
						if($per == 403)  return error(403, false,'Permission ERROR : Sorry, You are not permited to do that.');
					}
					if(!empty($data['schema_name']))
						$this->process_rest();
					else
						$this->process_normal($_REQUEST['__SERVICE']);
				}else if($size>0){					
					$nextIdx = $token < 0 ? $size + $token : $size - $token ;
					$filterName = $filterNames[$nextIdx];
					if(!empty($filterName)){
						$existsFilter = array_key_exists($filterName, $filterCls);
						$filter = $existsFilter? $filterCls[$filterName]: Filter::factory($filterName);
						if(!$existsFilter){
							$filterCls[$filterName] = $filter;
						}
						($token<0) ? $filter->before($this->params, $authRequired) : $filter->after($this->params, $authRequired);
					}
				}
			}
			if(isset($this->redirecting)){
				redirect($this->redirecting);
			}
			if(!isset($this->response_body)){
				render_html();
			}
		}catch(Exception $e){
			error_log("exec exception:\n".$e->getTraceAsString());
			print $e->getMessage();
		}
		REQ::quit();
	}
	private function process_normal($plugin=false){
		try {
			//FIXME add namespace | customize 1st level path
			$ctrldir = APP_DIR.__SLASH__.'controllers'.__SLASH__.($plugin?"_$plugin".__SLASH__:"");
			$data = $this->data;
			$controller_dir = !empty($data['namespace']) ? $ctrldir.$data['namespace'].'/':$ctrldir;
			$file_path = $controller_dir.$data['controller'].'.inc';
			// echo $file_path;exit;
			if(file_exists($file_path)){
				require_once $file_path;
				//process
				$action = $data['action'];
				$exec = function($action){
					//FIXME : exclude_wrappers not work
					$has_wrapper =  !isset($exclude_wrappers) || !in_array($action, $exclude_wrappers);
					if (function_exists('before_wrapper') && $has_wrapper)
						before_wrapper($this->params);
					$action($this->params);
					if (function_exists('after_wrapper')  && $has_wrapper){
						if($data['format']!='html'){//use only on json or text, html should use in smarty.
							after_wrapper($this->params);
						}else{
							$_REQUEST['after_wrapper'] = true;
						}
					}
				};
				if(function_exists($action)){//normal request
					$exec($action);
				}else if(Consts::$mode=='Developing' && str_starts($action,'test_') && function_exists(str_replace('test_','',$action))){//unit test
					self::$test_mode = true;
					return Tests::run($data['controller'],str_replace('test_','',$action));
				}else if(function_exists('__magic')){
					$this->params['@path']=$action;
					$exec('__magic');
				}else{//no action
					return render_default_template();
				}
			}else if($plugin){
				return $this->process_normal();
			}
			
		} catch(Exception $e) {
			echo $e->getMessage();
			if($plugin)
				return $this->process_normal();
			throw new Exception($controllerName.',Controller not found');
		}
	}
	private function process_rest(){
		$schema = $this->data['schema_name'];
		$schemaDef =$this->data['schema_def'];
		$method = strtolower($_SERVER['REQUEST_METHOD']);
		$pk = $schemaDef['general']['pk'];
		$params = $this->params;
		if(isset($params[$pk]) && !isset($params['@id']))
			$params['@id'] = $params[$pk];
		$delegate_name = $schema.'_'.$this->data['action'];  
		if(!method_exists('RestfulDelegate', $delegate_name)){
			switch(strtolower($_SERVER['REQUEST_METHOD'])){
				case 'get'	:return $this->rest_get($schema,$params);
				case 'post'	:return $this->rest_post($schema,$params);
				case 'put'	:return $this->rest_put($schema,$params);
				case 'delete':return $this->rest_delete($schema,$params);
				default : return error(401,false,'RESTful ERROR : Sorry, You are not permited to do that.');
			}
		}else{
			$re = call_user_func(['RestfulDelegate', $delegate_name]);
			if(!$re) error(401, false,'RESTful ERROR : Sorry, You are not permited to do that.');
		}
	}
	private function rest_get($schema, $params){
		$res = (isset($params['@id']))?
			db_find1st($schema, $params):
			db_find($schema, $params);
		render_json($res);
	}
	private function rest_post($schema, $params){
		if(isset($params['@id'])){
			error(400,false,'RESTful ERROR : Sorry, You can\'t use RESTful POST with @id, try PUT for update or using normal controllers');
		}else{
			return render_json(db_save($schema, $params, true));
		}
	}
	private function rest_put($schema, $params){
		if(isset($params['@id'])){
			return render_json(db_save($schema, $params));
		}else{
			error(400,false,'RESTful ERROR : You must specify a @id to use RESTful PUT');
		}
	}
	private function rest_delete($schema, $params){
		if(isset($params['@id'])){
			return render_json(db_delete($schema, $params));
		}else{
			error(400,false,'RESTful ERROR : You must specify a @id to use RESTful DELETE');
		}
	}
}
function permission($plugin=false){
	$req = REQ::getInstance();
	$uri = $req->getURI();
	$schemaDef = $req->getData('schema_def');
	$group = AuthDelegate::group();//str or array of 0,1
	$permission = '';
	if(!empty($schemaDef)){//RESTFUL
		$restful =  strtolower($schemaDef['general']['restful']?$schemaDef['general']['restful']:'');
		//not permit restful on this schema
		if(!empty($restful) && $restful!='all' && !str_has($restful, $method)){ return false; }
		$permission = isset($schemaDef['general']['permission'])?$schemaDef['general']['permission']:'';
	}else{
		$ctl = $req->getController();
		$ns  = $req->getNamespace();
		$tree = cache_get('APP_PERMISSION_'.$ns.'_'.$ctl, function($key) use($plugin){
			$req = REQ::getInstance();
			$ns = $req->getNamespace();
			$ctrldir = APP_DIR.__SLASH__.'controllers'.__SLASH__.($plugin?"_$plugin/":'');
			$controller_dir = !empty($ns) ? $ctrldir.$ns.'/':$ctrldir;
			$fp = $controller_dir.str_replace('APP_PERMISSION_'.$ns.'_','',$key).'.inc';
			if(!file_exists($fp) && $plugin){
				$fp = str_replace("/_$plugin/",'/',$fp);
			}
			$tree = fs_src_tree($fp);
			//$permission = ['@file' => $tree['annotations']['permission']];
			$permission = [];
			foreach ($tree['functions'] as $fn => $ftr){
				$permission[$fn] = $ftr['annotations']['permission'];
			}
			return $permission; 
		},false);

		$act = $req->getAction();

		//$permission = isset($tree[$act])?$tree[$act]:$tree['@file'];
		$permission = isset($tree[$act])?$tree[$act]:'F';			
	}

	$bits = isset($permission)&&isset($permission[$group])?$permission[$group]:($group==0?'8':'F');//bits is hex string (len=1)
	// elog("GR=$group, bits=$bits");			
	if($bits=='0') return $group==0? 401 : 403;

	$bits = str_pad(base_convert($bits, 16, 2),4,'0',STR_PAD_LEFT);
	$bitIdx = array_search(strtolower($req->getMethod()), ['get','post','put','delete']);
	if($bits[$bitIdx]!='1') return $group==0? 401 : 403;
	return 200;
}

function str_has($haystack, $needle){
	if(!is_string($haystack)||!is_string($needle))return false;
	return strpos($haystack, $needle) !== false;
}
function str_starts($haystack,$needle,$case=true) {
	if($case){return (strcmp(substr($haystack, 0, strlen($needle)),$needle)===0);}
	return (strcasecmp(substr($haystack, 0, strlen($needle)),$needle)===0);
}
function str_ends($haystack,$needle,$case=true) {
	if($case){return (strcmp(substr($haystack, strlen($haystack) - strlen($needle)),$needle)===0);}
	return (strcasecmp(substr($haystack, strlen($haystack) - strlen($needle)),$needle)===0);
}
function str_encode_hex($k,$salt=false) {
	$cs = 'abcdefghijklmnopqrstuvwxyz0123456789';
	if($salt){
		$k=base64_encode($salt.$k);
	}
	$k  = keygen(3,$cs).bin2hex("$k").keygen(1,$cs);
	return $k;
}
function str_decode_hex($k,$salt=false) {
	//$d=hex2str(substr($k,3,-1));
	$d=hex2bin(substr($k,3,-1));
	if($salt){
		$d=base64_decode($d);
		if(str_starts($d,$salt)){
			$d=substr_replace($d, '', 0, strlen($salt));
		}
	}
	return $d;
}
function check_decode_params($q, $keys, $k, $salt='anybot'){
	if(empty($q) || empty($keys) || empty($k)) return false;
	$keys = is_string($keys)?explode(",", $keys): $keys;
	$str=str_decode_hex($k,$salt);
	if(empty($str)) return false;
	
	$ds = json_decode($str,true);
	if(!$ds) {// not json
		$ds = [];
		parse_str($str,$ds);
	}
	foreach ($keys as $k) {
		$k = trim($k);
		$v = $q[$k];
		$dv = $ds[$k];
		if($v!=$dv) return false;
	}
	return true;
}
function hex2str($hex){
	$string='';
	for ($i=0; $i < strlen($hex)-1; $i+=2){
		$string .= chr(hexdec($hex[$i].$hex[$i+1]));
	}
	return $string;
}
function str2hex($string){
	$hex='';
	for ($i=0; $i < strlen($string); $i++){
		$hex .= dechex(ord($string[$i]));
	}
	return $hex;
}
function str2half($s){
		return mb_convert_kana($s, "as", 'UTF-8');
}
function wstr2num($s){
	$v = 0;$n = 0;
	$nmap = ['一'=>1,'壱'=>1,'壹'=>1,'弌'=>2,'二'=>2,'弐'=>2,'貳'=>2,'貮'=>2,'贰'=>2,'三'=>3,'参'=>3,'參'=>3,'弎'=>3,'叁'=>3,'叄'=>3,'四'=>4,'肆'=>4,'五'=>5,'伍'=>5,'六'=>6,'陸'=>6,'陸'=>6,'七'=>7,'漆'=>7,'柒'=>7,'八'=>8,'捌'=>8,'九'=>9,'玖'=>9];
	$bmap = ['十'=>10,'拾'=>10,'廿'=>20,'卄'=>20,'卅'=>30,'丗'=>30,'卌'=>40,'百'=>100,'陌'=>100,'千'=>1000,'阡'=>1000,'仟'=>1000];
	$b4map = ['万'=>10000,'萬'=>10000,'億'=>100000000,'兆'=>1000000000000];
	$s = str2half($s);
	$ns = "";
	$sl = mb_strlen($s);
	for($x=0;$x<$sl;$x++){
		$c = mb_substr($s, $x, 1, 'UTF-8');
		if(preg_match('/[0-9]/',$c)){
			$ns.=$c;
			$n = intval($ns);
		}else if(isset($nmap[$c])){
			$n=$nmap[$c];
		}else if(isset($bmap[$c])){
			$v+=$n*$bmap[$c];
			$n=0;
			$ns="";
		}else if(isset($b4map[$c])){
			if($n>0)$v+=$n;
			$v*=$b4map[$c];
			$n=0;
			$ns="";
		}
	}
	if($n>0)$v+=$n;
	return $v;
}
function is_email($email){
	return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
}
function is_ip($str){
	return false !== filter_var( $email, FILTER_VALIDATE_IP);
}
function is_url($str){
	return false !== filter_var( $str, FILTER_VALIDATE_URL );
}
function is_hash($arr){
	return !empty($arr) && is_array($arr) && array_keys($arr) !== range(0, count($arr) - 1);
}
function is_json($str){
	return is_object(json_decode($str));
}
function is_kanji($s){
	return preg_match('/^\p{Han}+$/u',$s);
}
function is_katakana($s){
	$r=preg_match('/^\p{Katakana}+$/u',$s);
	if($r) return $r;
	return preg_match('/^\p{Katakana}+$/u',trim(str_replace(' ','', $s)));
}
function is_hirakana($s){
	return preg_match('/^\p{Hiragana}+$/u',$s);
}
function is_japanese_name($s){
	return preg_match('/^[\p{Hiragana}\p{Katakana}\p{Han}]+$/u',preg_replace('/[\s　]/u','',$s));
}
function is_number($s){
	return preg_match('/^[\d\.]+$/',$s);
}
function is_phone_jp($s){
	return preg_match('/^\d{2,4}[\-ー−]*\d{3,4}[\-ー−]*\d{3,4}$/',$s);
}
function is_zipcode_jp($s){
	return preg_match('/^\d{3}[\-ー−]*\d{4}$/',$s);
}
function is_len($s,$min,$max=false){
	$min = intval($min);
	$max = $max===false?false:intval($max);
	$l = strlen($s);
 	return ($max===false) ?$l>=$min : $l>=$min && $l<=$max;
}
function is_ymdhi($s){
	return preg_match('/[12]\d{3}[\-ー年\.−]*\d{1,2}[\-ー月\.−]*\d{1,2}\s+(午前|午後|am|pm)*\d{1,2}[時:]\d{1,2}/u',$s);
}
function is_ymd($s){
	return preg_match('/[12]\d{3}[\-ー年\.−]*\d{1,2}[\-ー月\.−]*\d{1,2}/u',$s);
}
function is_ym($s){
	return preg_match('/[12]\d{3}[\-ー年\.−]*\d{1,2}/u',$s);
}
function is_hi($s){
	return preg_match('/^(午前|午後|am|pm)*\d{1,2}[時:]\d{1,2}/u',$s);
}
function is_name($s){
	//TODO: temp fixed
	return strlen($s)>0;
	//return preg_match('/^([\\\\u00c0-\\\\u01ffa-zA-Z\'\\-ァ-ヺー・ぁ-ん一-龯\\\\u4e00-\\\\u9a05])+([\\s　]*[\\\\u00c0-\\\\u01ffa-zA-Z\'-ァ-ヺー・ぁ-ん一-龯\\\\u4e00-\\\\u9a05]+)/ui',$s);
}

/** 
 * cartesian product 
 * @param $input : 
 pattern A = [
 	arm => [A,B,C],
    gender => [Female,Male],
    color => [white,black,red]
 ],
 - OR -
 pattern B = [
	arm => [A=>0,B=>300,C=>500],
    gender => [Female=>0,Male=>0],
    color => [white,black,red]
 ]
 * @return cartesian product 
 * 	pattern A [[arm=>A,gender=>Female,color=>white],...]
 * 	pattern B [[arm=>A,gender=>Female,color=>white,__SUM__:800],...]
 * 	
 */
function cartesian($input){
    $result = [[]];
    foreach ($input as $key => $values) {
        $append = [];
		$isAssoc = is_hash($values);
        foreach ($values as $k=>$value) {
            foreach ($result as $data) {
                $d = $data + [$key => $isAssoc ? $k:$value];
				if($isAssoc) $d['__SUM__'] += $value;
				$append[] = $d;
            }
        }
        $result = $append;
    }
    return $result;
}

//get yearweek 201920 (20th week of 2019) => [timestampStart,timestampEnd];
function yearweek_date($year,$week){
	$t1 = strtotime(date( "Y-m-d 00:00:00", strtotime($year."W".$week."1"))); // First day of week
	$t2 = strtotime(date( "Y-m-d 23:59:59", strtotime($year."W".$week."7"))); // Last day of week
	return [$t1,$t2];
}

//timestamp => 201905 (5th week of 2019)
function date_yearweek($time){
	return date('oW', is_number($time)?$time:strtotime($time));
}

function hash_incr($data, $key, $amount){
	$v = hash_set($data,$key,true,0);
	$v += $amount;
	return hash_set($data, $key, $v);
}
function hash_set(&$data, $keyPath, $val){
	$paths = explode('.', $keyPath);
	$o = &$data;
	$current_path = '';
	$path_size = count($paths);
	$key = $paths[0];
	$org = isset($data[$key])? $data[$key]: null;
	for ($i=0; $i<$path_size; $i++){
		$path = $paths[$i];
		if (is_string($o) && (str_starts($o, '{') || str_starts($o, '[')))
			$o = json_decode($o,true);
		if ($i == $path_size-1){
			$o[$path] = $val;
		}else{
			if (!isset($o[$path]))
				$o[$path] = [];
			$o = &$o[$path];
		}
	}
	return ['key'=>$key, 'val'=>$data[$key], 'org'=>$org];
}
function hash_get(&$data, $keyPath, $autoCreate=true, $defaultValue=null){
	if (empty($data)) {
		if($autoCreate){
			hase_set($data, $keyPath, $defaultValue);
		}else
			return $defaultValue;
	}
	$paths = explode('.', $keyPath);
	$o = $data;
	$current_path = '';
	while (count($paths)>1){
		$path = array_shift($paths);
		if (is_string($o) && (str_starts($o, '{') || str_starts($o, '[')))
			$o = json_decode($o,true);
		if (!isset($o[$path])){
			return $defaultValue;
		}
		$o = $o[$path];
	}
	if (is_string($o) && (str_starts($o, '{') || str_starts($o, '[')))
		$o = json_decode($o,true);
	$key = array_pop($paths);
	if(!isset($o[$key]))
		return $defaultValue;
	return $o[$key];
}
function hash_trim(&$e, $chars, $recursive){
	if(!is_hash($e)) return $e;
	$ks = array_keys($e);
	foreach ($ks as $k){
		$v = $e[$k];
		if($v===null || $v==='null' || in_array($v,$chars))
			unset($e[$k]);	
		if($recursive){
			if(is_string($v) && preg_match('/^\s*[\[\{]/',$v))
				$v = json_decode($v,true) ?: $v;
			if(is_array($v)){
				$e[$k] = is_hash($v) ? hash_trim($v,$chars,$recursive) : ( ds_trim($v,$chars,$recursive) ?: $v );
			}
		}
	}
	return $e;
}
function arr2hash($arr, $keyName, $valueName=null, $prefix=null){
	$hash = [];
	foreach ($arr as $e){
		$hash[($prefix?$prefix:'').$e[$keyName]] = $valueName==null ? $e : $e[$valueName];
	}
	return $hash;
}
function ds_remove(&$arr, $conditions, $firstOnly=FALSE){
	if(!isset($conditions)||(is_array($conditions)&&count($conditions)==0))
		return $arr;
	$res = array();
	$found = false;
	foreach ($arr as $el){
		$match = TRUE;
		if($firstOnly && $found){
			$match = FALSE;
		}else{
			if(is_hash($conditions)){
				foreach ($conditions as $k=>$v){
					if (!isset($el[$k]) || $el[$k]!=$v){
						$match = FALSE;
						break;
					}
				}
			}else if(is_array($conditions)){
				$match = in_array($el, $conditions);
			}else if(is_callable($conditions)){
				$match = $conditions($el);
			}else{
				$match = ($el===$conditions);
			}
		}
		if (!$match){
			$res[]=$el;
			$found = true;
		}
	}
	$arr = $res;
	return $res;
}
function ds_find($arr, $opts,$firstOnly=false){
	if(empty(Consts::$arr_query_filters))
		Consts::$arr_query_filters = [
		'=' 	=> function($o,$k,$v){return $o[$k]===$v;},
		'!' 	=> function($o,$k,$v){return $o[$k]!==$v;},
		'<' 	=> function($o,$k,$v){return $o[$k]<$v;},
		'>' 	=> function($o,$k,$v){return $o[$k]>$v;},
		'<=' 	=> function($o,$k,$v){return $o[$k]<=$v;},
		'>=' 	=> function($o,$k,$v){return $o[$k]>=$v;},
		'[]' 	=> function($o,$k,$v){return is_array($v)&&in_array($o[$k],$v);},
		'![]' 	=> function($o,$k,$v){return is_array($v)?!in_array($o[$k],$v):true;},
		'()' 	=> function($o,$k,$v){return is_array($v) && count($v)==2 && $o[$k]>=min($v[0],$v[1]) && $o[$k]<=max($v[0],$v[1]);},
		'!()' 	=> function($o,$k,$v){return !is_array($v) || count($v)<2 || $o[$k]<min($v[0],$v[1]) || $o[$k]>max($v[0],$v[1]);},
		'?'  	=> function($o,$k,$v){return !empty($o[$k]) && !empty($v) && str_has($o[$k], $v); },
		'!?'  	=> function($o,$k,$v){return empty($o[$k]) || !empty($v) || !str_has($o[$k], $v); },
		'~' 	=> function($o,$k,$v){return !empty($o[$k]) && !empty($v) && preg_match('/'.$v.'/', $o[$k]);},
		'!~'	=> function($o,$k,$v){return empty($o[$k]) || !empty($v) || !preg_match('/'.$v.'/', $o[$k]);},
		'~~' 	=> function($o,$k,$v){return !empty($o[$k]) && !empty($v) && preg_match('/'.$v.'/i', $o[$k]);},
		'!~~'	=> function($o,$k,$v){return empty($o[$k]) || !empty($v) || !preg_match('/'.$v.'/i', $o[$k]);},
	];
	if(empty($opts))return false; 
	$res = [];
	foreach ($arr as $a){
		$match = true;
		foreach ($opts as $k=>$v){
			$cmd = strstr($k, '@');
			$cmd = !$cmd ? "=":substr($k, $cmd);
			$func = Consts::$arr_query_filters[$cmd];
			if ($func && !$func($a,$k,$v)){
				$match = false;break;
			}
		}
		if($match){
			if($firstOnly) return $a;
			$res[] = $a;
		}
	}
	return $res;
}
function ds_sort($arr, $sortKey=null, $sortOrder=1, $comparator=null){
	if(isset($sortKey)){
		if($comparator==null){
			$cfmt = '$av=$a["%s"];if(!isset($av))$av=0;$bv=$b["%s"];if(!isset($bv))$bv=0;if($av==$bv){return 0;} return is_string($av)?strcmp($av,$bv)*%d:($av>$bv)?-1*%d:1*%d;';
			$code = sprintf($cfmt, $sortKey, $sortKey, $sortOrder, $sortOrder,$sortOrder);
			$cmp = create_function("$a, $b", $code);
			usort($arr, $cmp);
		}else
			usort($arr, $comparator);
		return $arr;
	}else{
		asort($arr);
		return $arr;
	}
}
function ds_trim(&$arr,$chars=[],$recursive=false){
	if (!is_array($arr)) return $arr;
	$iso = is_hash($arr);
	if($iso) hash_trim($arr, $chars, $recursive);
	else
		foreach ($arr as &$e) {
			hash_trim($e, $chars, $recursive);
		}
	return $arr;
}

function ms(){
    list($usec, $sec) = explode(' ', microtime());
    return ((int)((float)$usec*1000) + (int)$sec*1000);
}
function fs_put_ini($file, array $options){
	$tmp = '';
	foreach($options as $section => $values){
		$tmp .= "[$section]\n";
		foreach($values as $key => $val){
			if(is_array($val)){
				foreach($val as $k =>$v)
					$tmp .= "{$key}[$k] = \'$v\'\n";
			}else
				$tmp .= "$key = \'$val\'\n";
		}
		$tmp .= '\n';
	}
	file_put_contents($file, $tmp);
	unset($tmp);
}
function fs_archived_path ($id, $tokenLength=1000){
	$arch =  (int)$id % (int)$tokenLength;
	return "$arch/$id";
}
function fs_mkdir($out){
	$folder = (str_has($out,'.'))? preg_replace('/[^\/]*\.[^\/]*$/','',$out):$out;
	if(!file_exists($folder))
		mkdir($folder, 0775, TRUE);
}
function fs_xml2arr($xmlString){
	return json_decode(json_encode((array)simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NOCDATA)), TRUE);
}
function fs_annotations($comm){
	$comm = explode("\n",preg_replace(['/\/\*+\s*/m','/\s*\*+\/\s*/m'],'',$comm));
	$anno = [];
	$rows = count($comm);
	$tag = null; $value=[]; $attr= null;
	for($i=0;$i<=$rows;$i++){
		$cm = trim(preg_replace('/^[\s\*]*/','',$i<$rows?$comm[$i]:''));
		preg_match_all('/^@(?P<tag>[a-zA-Z]+)\s*(?P<attr>[^:^=]*)\s*[:=]*\s*(?P<value>.*)/i',$cm,$matches);
				if(!empty($matches['tag']) || $i==$rows){
			if(empty($tag))$tag = 'desc';
			if(empty($anno[$tag]))
				$anno[$tag] = [];
			$anno[$tag] []= ['value'=>join("\n", $value),'attr'=>$attr];
			$tag = null; $value=[]; $attr = null;
		}
				if(!empty($matches['tag'])){
			$tag = trim(strtolower($matches['tag'][0]));
			$value []= preg_replace('/^[:\s]*/','',trim($matches['value'][0]));
			$attr = preg_replace('/^[:\s]*/','',$matches['attr'][0]);
		}else if(!empty($cm)){
			$value []= $cm;
		}
	}
	foreach ($anno as $key=>$vs){
		if(count($vs)==1) $anno[$key] = $vs[0]['value'];
	}
	return $anno;
}
function fs_src_tree($phpfile){
	$src = file_get_contents($phpfile);
	require_once $phpfile;
		preg_match_all('/<\?php\s*\/\*+\s*(?P<comment>.*?)\*\/\s*/sm', $src, $fdef);
	$comment = $fdef['comment'][0];
		preg_match_all('/^(abstract)*\s*(class|trait)\s+(?P<cls>[\w\d]+)\s*/mi', $src, $ma);
	$classes = [];
	if(!empty($ma['cls'])){
		foreach ($ma['cls'] as $cls){
			$classes[$cls] =[];
			$cr = new ReflectionClass($cls);
			$classes[$cls]['name'] = $cls;
						$parent = $cr->getParentClass();
			if($parent) $classes[$cls]['parent']=$parent->getName();
						$classes[$cls]['interfaces']=$cr->getInterfaceNames();
						$classes[$cls]['abstract']=$cr->isAbstract();
						$classes[$cls]['trait']=$cr->isTrait();
						$comm = $cr->getDocComment();
			if($comm==$comment) $comment='';
			$classes[$cls]['annotations']=fs_annotations($comm);
						$methods = $cr->getMethods();
			foreach ($methods as $mr){
				$args = array_map(function($e){return $e->name;}, $mr->getParameters());
				$anno = fs_annotations($mr->getDocComment());
				$classes[$cls]['methods'][$mr->getName()] = [
				'name'	=> $mr->getName(),
				'classname'=>$cls,
				'annotations'=>$anno, 'params'=>$args,
				'abstract' => $mr->isAbstract(),
				'constructor' => $mr->isConstructor(),
				'destructor' => $mr->isDestructor(),
				'final' => $mr->isFinal(),
				'visibility' => $mr->isPrivate()?'private':($mr->isProtected()?'protected':'public'),
				'static' => $mr->isStatic()
				];
			}
						$props = $cr->getProperties();
			foreach ($props as $pr){
				$classes[$cls]['properties'][$pr->getName()] = [
				'visibility' => $pr->isPrivate()?'private':($pr->isProtected()?'protected':'public'),
				'static' => $pr->isStatic()
				];
			}
		}
	}
		preg_match_all('/^function\s+(?P<func>[\w\d_]+)\s*\(/mi', $src, $ma);
	$funcs = [];
	if(!empty($ma['func'])){
		foreach ($ma['func'] as $fn){
			$ref = new ReflectionFunction($fn);
			$args = array_map(function($e){return $e->name;}, $ref->getParameters());
			$comm = $ref->getDocComment();
			if($comm==$comment) $comment='';
			$anno = fs_annotations($comm);
			$funcs[$fn] = ['annotations'=>$anno, 'params'=>$args, 'name'=>$fn];
		}
	}
	return ['annotations' => empty($comment)?[]:fs_annotations($comment),'functions' => $funcs,'classes' => $classes];
}
function elog($o, $label=''){
	$trace=debug_backtrace();
	$m = strlen($trace[1]['class'])? $trace[1]['class']."::":"";
	$m .= $trace[1]['function'];
	$ws = is_array($o)?"\n":(strlen($o)>=10?"\n":"");
	if($_REQUEST['__elog']){
		$logs = cache_get('elog_'.$_REQUEST['__elog'],false,false)?:[];
		if(is_string($logs)) $logs = json_decode($logs,true);
		$log = [
			'location'=>$m.' #'.$trace[0]['line'],
			'msg'=>$o
		];
		if(!empty($label)) $log['label']=$label;
		$logs[] = $log;
		cache_set('elog_'.$_REQUEST['__elog'], json_encode($logs), 60, false);//60 second?
	}
	$s = $m." #".$trace[0]['line']." $label=$ws".(is_array($o)?json_encode($o,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE):$o)."\n";	
	if(property_exists('Conf','log_file') && Conf::$log_file)
		file_put_contents(Conf::$log_file,date('[m/d H:i:s]').':'.$s,FILE_APPEND);
	else
		error_log($s);
}

function clog( $data ){
	echo json_encode( $data );
}

function comp($a,$b,$cmp='eq',$k=false){
	$v1 = is_hash($a)&&$k?$a[$k]:$a;
	$v2 = is_hash($b)&&$k?$b[$k]:$b;
	switch($cmp){
		case 'eq':return $v1==$v2;
		case 'ne':return $v1!=$v2;
		case 'le':return $v1<=$v2;
		case 'lt':return $v1<$v2;
		case 'ge':return $v1>=$v2;
		case 'gt':return $v1>$v2;
	}
}
function arr_filter($arr, $v, $k=false, $comp='eq', &$i=false){
	$res = [];$x=0;
	if($arr){
		foreach ($arr as $e) {
			if(($k===false && comp($e,$v,$comp))||($k && comp($e[$k],$v,$comp))){
				$res[]=$e;
				if($i===false)$i=$x;
			}
			$x++;
		}
	}
	return $res;
}
function arr_intersect($a1,$a2){
	$r = [];
	foreach ($a1 as $e) {
		if(in_array($e, $a2))$r[]=$e;
	}
	return $r;
}
function arr_diff($a,$base){
	$r = [];
	foreach ($a as $e) {
		if(!in_array($e, $base))$r[]=$e;
	}
	return $r;
}

class Session {
	static function start(){
		ini_set('session.gc_maxlifetime', Conf::$session_lifetime);
		session_set_cookie_params(Conf::$session_lifetime);

		session_start();
		$ua = $_SERVER['HTTP_USER_AGENT'];
		$time = time();
		$sid  = session_id();
		if(!isset($_COOKIE['sid'])){
			if (Conf::$mode=='Developing') {
				setcookie('sid', md5($_SERVER['REMOTE_ADDR'].'|'.$ua."|".$sid), $time+86400*30, '/');
			} else {
				// name,value,expires,path,domain,secure,httponly
				setcookie('sid', md5($_SERVER['REMOTE_ADDR'].'|'.$ua."|".$sid), $time+86400*30, '/',"",TRUE,FALSE);
			}
			$_SESSION['IP'] = $_SERVER['REMOTE_ADDR'];
			$_SESSION['UA'] = $ua;
			$_SESSION['ISSUED_HOST'] = $_SERVER['SERVER_NAME'];
			$_SESSION['ISSUED_AT'] = $time;
			$_SESSION['CSRF_NOUNCE'] = md5(uniqid(rand(), TRUE));			setcookie('sidsecr', sha1($_SESSION['CSRF_NOUNCE']), $time+86400*30, '/');
		}else{
			if($_COOKIE['sid']!=md5($_SESSION['IP'].'|'.$_SESSION['UA']."|".$sid)
				|| $time-(isset($_SESSION['ISSUED_AT'])?$_SESSION['ISSUED_AT']:0)>=Consts::$session_lifetime){
								self::clear();
				return self::start();
			}else if($_COOKIE['sidsecr']!=sha1($_SESSION['CSRF_NOUNCE'])){
				self::clear();
				return error(400); 			}
		}
	}
	static function regenerate() {
		session_regenerate_id();
		$sid = session_id(); //// PHPSESSID | anysess
		setcookie('sid', md5($_SERVER['REMOTE_ADDR'].'|'.$ua."|".$sid), $time+86400*30, '/');
		if(!$_SESSION['CSRF_NOUNCE'])
			$_SESSION['CSRF_NOUNCE'] = md5(uniqid(rand(), TRUE));
		setcookie('sidsecr', sha1($_SESSION['CSRF_NOUNCE']), $time+86400*30, '/');
	}
	static function clear(){
		$sname = session_name(); // PHPSESSID | anysess
		setcookie($sname, '', 1 ,"/");
		setcookie('sid', '', 1, "/");
		setcookie('sidsecr', '', 1, "/");
		unset($_COOKIE[$sname]);
		unset($_COOKIE['sid']);
		unset($_COOKIE['sidsecr']);
		session_unset();
		session_destroy();
	} 
}
function cache_get($key, $nullHandler=null,$sync=true){
	$k = APP_NAME."::".$key;
	$res = function_exists('apcu_fetch') ? apcu_fetch($k):apc_fetch($k);
	if((!$res || !isset($res)) && $sync){
		$res = mc_get($key);
	}
	if(!$res && is_callable($nullHandler)){
		$res = $nullHandler($key);
		if(isset($res)&&$res!=false){
			cache_set($key, $res, 3600, $sync);
		} 
	}
	return $res;	
}
function cache_set($key, $value, $time=3600, $sync=true){
	$k = APP_NAME."::".$key;
	$s = function_exists('apcu_store') ?apcu_store($k, $value, $time):apc_store($k, $value, $time);
	return ($time && $sync)?mc_set($key,$value,$time):$s;
}
function cache_del($key,$sync=true){
	$k = APP_NAME."::".$key;
	$r = function_exists('apcu_delete') ? apcu_delete($k):apc_delete($k);
	return ($sync)?mc_del($key):$r;
}
function cache2_del($apiver,$schema,$key,$opts=[]){
	if($apiver>=2&&property_exists('Conf','cache_v2')) {
		try {
			$type  = Conf::$cache_v2['type'];
			$hosts = Conf::$cache_v2[$type][$schema]?:(Conf::$cache_v2[$type]['default']?:false);
			if(empty($hosts)) {//无效设定，继续使用旧版本的
				return mc_del($key);
			}else if($type=='memcached') {
				return mc_del($key, $hosts);
			}else if($type=='redis') {
				return redis_del($key, $hosts);
			}
		} catch (Exception $e) {
			$error = [ 'code'=>$e->getCode(), 'msg'=> $e->getMessage(), 'trace'=>$e->getTraceAsString() ];
			elog("Error: ".json_encode($error,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
			return false;
		}
	}
	return mc_del($key);
}
function cache_inc($key,$amount=1,$sync=false){
	$k = APP_NAME."::".$key;
	$res = function_exists('apcu_inc') ?apcu_inc($k, $amount):apc_inc($k, $amount);
	return ($sync)?mc_inc($key,$amount):$res;
}
function cache_dump(){
	return @apc_bin_dumpfile([],null, APP_DIR.__SLASH__."tmp".__SLASH__."apc.data");
}
function cache_load(){
	return @apc_bin_loadfile(APP_DIR.__SLASH__."tmp".__SLASH__."apc.data");
}

//clear apc cache for all ap servers
function cache_clear($k, $path, $all=false){
	$tar = $all ? Conf::$ap_servers : [$_SERVER['SERVER_NAME']];
	$rs = [];
    foreach($tar as $a){
		if ($_SERVER['SERVER_NAME']==$a){
			cache_del($k);
			$rs[]="CLEAR:local:$a";
		}else{
			call("http://$a/$path",'GET',['k'=>$k]);
			$rs[]="CLEAR:http://$a/$path";
		}
	}
	return $rs;
}

// clear ngx.shared from all lua servers
function cache_clear_v2($keys,$targets,$q=false) {
	$res = [];
	$q = $q?:[];
	$token   = property_exists('Conf','lua_token')?Conf::$lua_token:'';
	$keys    = is_array($keys)?implode(",", $keys): $keys;
	$targets = is_array($targets)?implode(",", $targets): $targets;
	$params  = array_merge($q, ['keys'=>$keys, 'targets'=>$targets, 'token'=>$token]);
	foreach (Conf::$apv2_servers as $srv) {
		//elog("=====cache_clear_v2 server=$srv");
		putenv("http_proxy="); putenv("https_proxy="); // for dev
		$r=call("http://$srv/tools/shm_clear",'GET',$params/*, [ 'Anybot-Token'=> $token]*/);
		$res[] = "$srv=".$r; // example: ["localhost:8000=DONE\n","localhost2:8000=DONE\n"]
	}
	return $res;
}
// clear ngx.shared.bots from all lua servers
function cache_clear_v2_bot($bid){
	return cache_clear_v2('','bot',['bid'=>$bid]);
}

function mc_conn($hosts=false,$opts=[]){
	$hosts = $hosts?:Consts::$cache_hosts;
	if(empty($hosts)){
		return false;
	}else{
		$conn = new Memcached(APP_NAME);
		$hosts=explode(",", $hosts);
		$ss = $conn->getServerList();
		if (empty ( $ss )) {
			$conn->setOption(Memcached::OPT_RECV_TIMEOUT, 1000);
			$conn->setOption(Memcached::OPT_SEND_TIMEOUT, 1000);
			$conn->setOption(Memcached::OPT_TCP_NODELAY, true);
			$conn->setOption(Memcached::OPT_SERVER_FAILURE_LIMIT, 50);
			$conn->setOption(Memcached::OPT_CONNECT_TIMEOUT, 500);
			$conn->setOption(Memcached::OPT_RETRY_TIMEOUT, 300);
			$conn->setOption(Memcached::OPT_DISTRIBUTION, Memcached::DISTRIBUTION_CONSISTENT);
			$conn->setOption(Memcached::OPT_REMOVE_FAILED_SERVERS, true);
			$conn->setOption(Memcached::OPT_LIBKETAMA_COMPATIBLE, true);
			if( property_exists('Conf','mc_compression')&&(Conf::$mc_compression===false||Conf::$mc_compression===0) ){
				$conn->setOption(Memcached::OPT_COMPRESSION, false); //データを圧縮しないで、nodeまたはluaとデータを共有することができます。
			}
			$hs = [];
			foreach ($hosts as $host){
				list($h,$p) = explode(":",trim($host));
				// $conn->addServer ($h, isset($p)&&$p!=""?(int)$p:11211, 1 );
				$hs[] = [$h, isset($p)&&$p!=""?(int)$p:11211, 1];
			}
			$conn->addServers($hs);
		}
		return $conn;
	}
}
function mc_get($key, $nullHandler=null, $hosts=false, $app_name=false){
	$conn = mc_conn($hosts);
	$k = ($app_name?:APP_NAME)."::".$key;
	if($conn){
		$v = $conn->get($k);
		if(!in_array($conn->getResultCode(),[Memcached::RES_SUCCESS,Memcached::RES_NOTFOUND])){
			elog("mc_get Key:$key Code: ".$conn->getResultCode()." Msg: ".$conn->getResultMessage(),'MEMCACHE RES');
		}
		if(!$v && isset($nullHandler)){
			$r = $nullHandler($key);
			if(isset($r)&&$r!=false){
				mc_set($key, $r, 3600, $hosts, $app_name);
			}
			return $r;
		}else if(is_string($v)){
			$j = json_decode($v,true);
			if($j)return $j;
		}
		return $v;
	}
	return false;
}
function mc_gets($keys){ // TODO: <-- Are we still using this? 
	$conn = mc_conn();
	$ks = array_map(function($e){return APP_NAME."::".$k;}, $keys);
	if($conn)
		return $conn->getMulti($ks);
	return false;
}
function mc_set($key, $value, $time=3600, $hosts=false, $app_name=false){
	$k = ($app_name?:APP_NAME)."::".$key;
	$conn = mc_conn($hosts);
	$res = ($conn)? $conn->set($k,is_array($value)?json_encode($value):$value,$time):false;
	if(!$res){
		elog("mc_set Key:$key Code: ".$conn->getResultCode()." Msg: ".$conn->getResultMessage(),'MEMCACHE RES');
	}
	return $res;

}
function mc_sets($datas,$time=3600){
	$ds = [];
	foreach($datas as $k=>$v){
		$ds[APP_NAME."::".$k] = $v;		
	}
	$conn = mc_conn();
	return ($conn)? $conn->setMulti($ds,$time):false;
}
function mc_del($key,$hosts=false,$app_name=false){
	$k = ($app_name?:APP_NAME)."::".$key;
	$conn = mc_conn($hosts);
	return ($conn)? $conn->delete($k,$time):false;
}
function mc_inc($key,$amount=1){
	$k = APP_NAME."::".$key;
	$conn = mc_conn();
	return ($conn)? $conn->increment($k,$amount):false;
}
function redis_conn_byhosts($hosts=false){
	$redis_pass = $hosts[0]['pass']?:'';
	if(count($svs)>1){
		$svs = array_map(function($h){return is_string($h)?$h: "$h[ip]:$h[port]"; },$hosts);
		$cluster = new RedisCluster(NULL,$svs, 2, 1.5, true, $redis_pass);
		$cluster->setOption(Redis::OPT_PREFIX, APP_NAME.'::');
		return $cluster;
	}else{
		$redis = new Redis();
		if(is_string($hosts[0])) {
			$ss = explode(":",$hosts[0]);
			list($host,$port) = $ss;
		} else {
			$host = $hosts[0]['ip'];
			$port = $hosts[0]['port'];
		}
		$redis->connect($host, $port?:6379);
		if($redis_pass)
			$redis->auth($redis_pass);
		$redis->setOption(Redis::OPT_PREFIX, APP_NAME.'::');
		return $redis;
	}
}
function redis_conn($hosts=false){
	if(!empty($hosts)){
		return redis_conn_byhosts($hosts);
	}
	
	$redis_sentinel = Conf::$redis_sentinel;
	$sentinel_port = empty(Conf::$sentinel_port)?'26379':Conf::$sentinel_port;
	$servers = [];

	if (!empty($redis_sentinel)) {
		// get redis master servers from redis sentinel (php5.4 only, will change to RedisSentinel at update to php7)
		$redis = new Redis();
		$redis->connect($redis_sentinel, $sentinel_port);
		$masters = $redis->rawCommand('SENTINEL', 'masters');
		$masters = parse_array($masters);

		// php 7 & phpredis5.2.2+
		if (substr(PHP_VERSION, 0, 1) >= 7) {
			$sentinel = new RedisSentinel($redis_sentinel, $sentinel_port);
			$masters = $sentinel->masters();
		}
			
		foreach ($masters as $master) {
			// get masters setting with master*
			if (preg_match('/^master/',$master['name']) ) {
				$server = $master['ip'];
				$port = $master['port'];
				$servers[] = $server . ':'. $port;
				// array_push($servers,$server . ':'. $port);
			}
		}
		$svs = $servers;
	} else {
		// if no redissentinel, use conf.inc setting (not recommend)
		$svs = Conf::$redis_servers;
	}
	if(empty($svs))return false;
	if(count($svs)>1){
		$cluster = new RedisCluster(NULL,$svs, 2, 1.5, true, Conf::$redis_pass);
		// $cluster = new RedisCluster(NULL, $svs);
		// if(Conf::$redis_pass)
		// 	$cluster->auth(Conf::$redis_pass);
		$cluster->setOption(Redis::OPT_PREFIX, APP_NAME.'::');
		return $cluster;
	}else{
		$redis = new Redis();
		$redis->connect($svs[0]);
		if(Conf::$redis_pass)
			$redis->auth(Conf::$redis_pass);	
		$redis->setOption(Redis::OPT_PREFIX, APP_NAME.'::');
		return $redis;
	}
}
// set SENTINEL master result to hash (php 5.4 only, will delete at update to php7)
function parse_array(array $data)
{
    $result = array();
    $count = count($data);
    for ($i = 0; $i < $count;) {
        $record = $data[$i];
        if (is_array($record)) {
            $result[] = parse_array($record);
            $i++;
        } else {
            $result[$record] = $data[$i + 1];
            $i += 2;
        }
    }
    return $result;
}

function redis_get($key, $nullHandler=null, $hosts=false){
	$conn = redis_conn($hosts);
	$k = $key;//APP_NAME."::".$key;
	if($conn){
		$v = $conn->get($k);
		if(!$v && isset($nullHandler)){
			$r = $nullHandler($key);
			if(isset($r)&&$r!=false){
				redis_set($key, $r);
			}
			return $r;
		}else if(is_string($v)){
			$j = json_decode($v,true);
			if($j)return $j;
		}
		return $v;
	}
	return false;
}
function redis_set($key, $value, $time=3600, $hosts=false){
	$k = $key;//APP_NAME."::".$key;
	$conn = redis_conn($hosts);
	if(!$conn)return false;
	if($time>0)
		$conn->setEx($k,$time,is_array($value)?json_encode($value):$value);
	else
		$conn->set($k,is_array($value)?json_encode($value):$value);
}

function redis_del($key, $hosts=false){
	$k = $key;//APP_NAME."::".$key;
	$conn = redis_conn($hosts);
	return ($conn)? $conn->del($k):false;
}

function db_conn($opts=null, $pdoOpts=null){
	$db = REQ::getDB();
	if(!isset($db)){
		$db = pdo_conn($opts,$pdoOpts);
		REQ::setDB($db);
	}
	return $db;
}
function pdo_conn($opts=null, $pdoOpts=null){
	$opts = $opts ? $opts: [
		'engine'=>Consts::$db_engine,
		'host'	=>Consts::$db_host,
		'port'	=>Consts::$db_port,
		'db'	=>Consts::$db_name,
		'user'	=>Consts::$db_user,
		'pass'	=>Consts::$db_pass,
	];
	if (ini_get('mysqlnd_ms.enable')) {
		$conn_str = $opts['engine'].':host='.$opts['host'].';dbname='.$opts['db'].';charset=utf8mb4';
	} else {
		$conn_str = $opts['engine'].':host='.$opts['host'].';port='.$opts['port'].';dbname='.$opts['db'].';charset=utf8mb4';
	}
	$pdoOpts = $pdoOpts ? $pdoOpts :[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,PDO::ATTR_PERSISTENT => false];
	return new PDO($conn_str,$opts['user'],$opts['pass'],$pdoOpts);
}
function fdb_conn($pdoOpts){
	$opts = [
		'engine'=>'mysql',
		'host'	=>property_exists('Conf','framework_db_host')?Conf::$framework_db_host:Conf::$db_host,
		'port'	=>property_exists('Conf','framework_db_port')?Conf::$framework_db_port:3306,
		'db'	=>property_exists('Conf','framework_db_name')?Conf::$framework_db_name:Conf::$db_name,
		'user'	=>property_exists('Conf','framework_db_user')?Conf::$framework_db_user:Conf::$db_user,
		'pass'	=>property_exists('Conf','framework_db_pass')?Conf::$framework_db_pass:Conf::$db_pass,
	];
	return pdo_conn($opts);
}
function pdo_tables($pdo,$db){
	$q = $pdo->prepare("SHOW tables FROM `$db`");
    $q->execute();
    return $q->fetchAll(PDO::FETCH_COLUMN);
}
function pdo_desc($pdo,$table){
	$q = $pdo->prepare("SHOW COLUMNS FROM `$table`");
    $q->execute();
    return $q->fetchAll();
}
function pdo_query($pdo, $sql, $datas=[], $pdoOpt=null) {
	unset($_REQUEST['__db_affected_rows'],$_REQUEST['__db_error']);
	if(!$pdo || empty($sql))return false;
	if(Conf::$mode=='Developing' || $_REQUEST['__elog']){
		elog(['template'=>$sql,'data'=>$datas], "SQL");
	}
	if($pdoOpt==null)$pdoOpt=PDO::FETCH_ASSOC;
	$isQeury = str_starts(strtolower(trim($sql)), 'select');
	$statement = $pdo->prepare($sql);
	$r = $statement->execute ($datas);
	if ($r == FALSE) {
		error_log("DB ERR:".json_encode($datas));	
		if($_REQUEST['__elog']) elog($datas,"SQL-ERR");	
		return false;
	} else {
		//if($_REQUEST['__need_db_query_rows']) {
			$_REQUEST['__db_affected_rows'] = $statement->rowCount();
		//}
	}
	return $isQeury? $statement->fetchAll($pdoOpt):true;
}
function pdo_count($pdo, $sql, $datas=[], $col=0){
	if(!$pdo || empty($sql))return false;
//elog($sql,"pdo_count");	
	$statement = $pdo->prepare($sql);
	if ($statement->execute ($datas) == FALSE) {
		return false;
	}
	$res =  $statement->fetchColumn();
	return intval($res);
}
function pdo_import($pdo, $table, $datas, $regName='regAt', $updName='updAt'){
	if(!isset($pdo) ||!isset($table) || count($datas)==0)
		return false;
	list($table,$schemaname) = explode('@',$table);
	if(empty($schemaname)) $schemaname = $table;
	$schema = db_schema($schemaname)['schema'];
	$cols = [];
	foreach ($datas as $d){
		$cols = array_unique(array_merge($cols,array_keys($d)));
	}
	$cls = $cols;$cols=[];$schema_cols =array_keys($schema);
	foreach($cls as $c){
		if(in_array($c, $schema_cols)){
			$cols[]=$c;
		}
	}
	$hasRegStamp = !empty($regName) && array_key_exists($regName,$schema);
	if($hasRegStamp && !in_array($regName, $cols)) $cols[] = $regName;
	$hasTimestamp = !empty($updName) && array_key_exists($updName,$schema);
	if($hasTimestamp && !in_array($updName, $cols)) $cols[] = $updName;
	$sql = 'INSERT IGNORE INTO '.$table.' (`'.join('`,`', $cols).'`) VALUES ';
	$time = time();
	foreach ($datas as $d){
		if($hasRegStamp && empty($d[$regName])){$d[$regName]=$time;}
		if($hasTimestamp && empty($d[$updName])){$d[$updName]=$time;}
		$vals = [];
		foreach ($cols as $c){
			$v = array_key_exists($c, $d) ? $d[$c] : null;
			$vals[]=db_v($v, $schema[$c]);
		}
		$sql.=' ('.join(',', $vals).'), ';
	}
	$sql = substr($sql, 0, strlen($sql)-2);
	$pdo->setAttribute(PDO::ATTR_TIMEOUT, 1000);
	try{
		pdo_query($pdo, $sql);
		$pdo->setAttribute(PDO::ATTR_TIMEOUT, 10);
	}catch(Exception $e){
		$pdo->setAttribute(PDO::ATTR_TIMEOUT, 10);
		$file = '/tmp/'.$table.'_imp.sql';
		file_put_contents($file,$sql);
		$data = [
			'reason' => $file,
			'trace' => $e->getTraceAsString(),
			'code' => $e->getCode(),
			'msg' => $e->getMessage(),
		];
		exception_save($data);
		return false;
	}
}
function pdo_save($pdo, $table, $data, $returnId=false, $schema_def=false){
	if(isset($_REQUEST['__db_error'])) unset($_REQUEST['__db_error']);
	if(!isset($pdo) || !isset($table) || !is_hash($data) || empty($data))return false;
	$regName = Consts::$schema_reg;
	$updName = Consts::$schema_upd;
	list($table,$schemaname) = explode('@',$table);
	if(!$schema_def){
		if(empty($schemaname)) $schemaname = $table;
		$schema_def = db_schema($schemaname);
	}else if(is_string($schema_def))
		$schema_def=json_decode($schema_def,true);
	$schema = $schema_def['schema'];
	$pk = $schema_def['general']['pk'];
	$pks=[];$qo=null;$isUpdate=false;
	if(Conf::$mode=='Developing'){
			}	
	if(isset($data[$pk])&&$data[$pk]=='')
		unset($data[$pk]);
	if (preg_match('/[|+,]/',$pk)){
		$pks = preg_split('/[|+,]/', $pk);
		$qo = [];
		foreach ($pks as $p){
			if(empty($data[$p])){
				$qo=[];break;
			}else 
				$qo[$p] = $data[$p];
		}
		if(!empty($qo)){
			try{
				$ext = pdo_find($pdo, $table.'@'.$schemaname, $qo, false, false, $schema_def);
			}catch(Exception $e){
				elog($e->getMessage().'\n');
			}
			$isUpdate = !empty($ext);
		}
	} else{
		$id = isset($data[$pk]) ? $data[$pk] : null;
		$isUpdate = isset($id) && pdo_exists($pdo, $table.'@'.$schemaname, $id, $pk);
	}
	$sql = '';
	if(array_key_exists($updName,$schema) && !isset($data[$updName])){
		$data[$updName] = time();
	}
	$qdatas = [];
	if ($isUpdate){
		if($id)cache_del($table.'_'.$id);
		foreach ($data as $col => $val){
			if(str_ends($col,'+')) {
				$opr = substr($col,-1);
				$col = substr($col,0,-1); 
			}
			if($col==$pk || in_array($col, $pks) || !isset($schema[$col]))continue;
			if(!empty($colStmt))$colStmt .= ',';
			$colStmt .= $opr? '`'.$col.'`=`'.$col.'` + :'.$col.' ' : '`'.$col.'`=:'.$col.' ';
			$qdatas[$col]= is_array($val)?json_encode($val):$val;		}
		if(empty($pks)){
			$sql = 'UPDATE `'.$table.'` SET '.$colStmt.' WHERE `'.$pk.'`='.db_v($id).';';
		}else{
			$table = $table.'@'.$schemaname;
            list($colStr,$optStr,$qrdatas) = db_make_query($table, $qo);
			foreach ($qrdatas as $qk=>$qv){
				$qdatas[$qk]= $qv;
			}
			$sql ='UPDATE `'.$table.'` SET '.$colStmt.' '.$optStr; 
		}
	}else{
		if(array_key_exists($regName,$schema) && !isset($data[$regName]))
			$data[$regName] = time();
		foreach ($data as $col => $val){
			if(str_ends($col,'+')) {
				$opr = substr($col,-1);
				$col = substr($col,0,-1);
			}
			if(!isset($schema[$col]))continue;
			if(!empty($colStmt))$colStmt .= ',';
			if(!empty($valStmt))$valStmt .= ',';
			$colStmt .= '`'.$col.'`';
			$valStmt .= $opr? '`'.$col.'` + :'.$col.' ' : ':'.$col;
			$qdatas[$col] = is_array($val)?json_encode($val):$val ;		}
		$sql = 'INSERT '.$ignore.' INTO `'.$table.'` ('.$colStmt.') VALUES('.$valStmt.')';
	}
	try {
						if($returnId==true && !$isUpdate) {
			if(!$pdo->inTransaction()) {
				$res = pdo_trans($pdo,[$sql, 'SELECT LAST_INSERT_ID() as \'last_id\''],[$qdatas]);
				$data['id'] = $res[0]['last_id'];
			}else{
				pdo_query($pdo, $sql,$qdatas);
				$res = pdo_query($pdo, 'SELECT LAST_INSERT_ID() as \'last_id\'', []);
				$data['id'] = $res[0]['last_id'];
			}
		}else{
			pdo_query($pdo, $sql,$qdatas);
		}
		return $data;
	} catch (Exception $e) {
		error_log('ERROR '.$e->getMessage());
		error_log($sql);
		$data = [
			'reason' => $sql,
			'trace' => $e->getTraceAsString(),
			'code' => $e->getCode(),
			'msg' => $e->getMessage(),
		];
		$_REQUEST['__db_error'] = $data;
		exception_save($data);

		return false;
	}
}
function pdo_find($pdo, $table, $opts=[], $withCount=false, $pdoOpt=null, $schema_def=false){
	if(!$pdo || !$table)return false;
	list($colStr, $optStr,$datas,$conns) = db_make_query($table, $opts,[],false,$schema_def);
	$sql = 'SELECT '.$colStr.' FROM '.$table.$optStr;
	$res = pdo_query($pdo, $sql, $datas, $pdoOpt);

	if(!empty($conns) && !empty($res)){
		$ds = [];
		$extras = [];
		foreach ($conns as $conn => $def) {
			$col = $def['column'];
			if(!isset($ds[$col]))
				$ds[$col] = array_map(function($e) use($col){return $e[$col];}, $res);
			$condition = empty($def['query'])?[]:$def['query'];
			$condition['fields'] = $def['fields'];
			$tc = $def['target_column'];
			if(count($ds[$col])>1){
				$ds[$col] = array_filter($ds[$col], function($e){
					return $e && $e!='';
				});
				if(!empty($ds[$col]))$ds[$col]=array_unique($ds[$col]);
				if(!empty($ds[$col]))
					$condition[$tc.'@in']=join(',',$ds[$col]);
			}else
				$condition[$tc]=$ds[$col][0];
			$re = pdo_find($pdo,$def['table'],$condition, false, $pdoOpt, $schema_def);
			$extras[$conn]=[];
			foreach ($re as $r) {
				$k = $r[$tc];
				if(!isset($extras[$conn][$k])) 
					$extras[$conn][$k]=[];	
				$extras[$conn][$k][] = $r;
			}
					}
		foreach ($res as &$r) {
			foreach ($conns as $conn => $def) {
				$tc = $def['target_column'];
				$r[$conn] = $extras[$conn][''.$r[$def['column']]];
				if($def['fields']!='*' && !in_array($tc, $def['fields']))
					unset($r[$conn][$tc]);
			}
		}
	}
	if($withCount){
				$sql = 'SELECT count(*) FROM '.$table.preg_replace(['/ORDER\s+BY.*/i','/LIMIT\s.*/i'], '',$optStr);
		$cnt = pdo_count($pdo,$sql, $datas, $opts['useCache']);
		$key_cnt = property_exists('Consts', 'schema_total')? Consts::$schema_total:'count';
		$key_res = property_exists('Consts', 'schema_result')? Consts::$schema_result:'result';
		return [$key_cnt=>$cnt,$key_res=>$res];
	}else{
		return $res;
	}
}
function pdo_exists($pdo, $table, $id, $pk=false){
	if(!isset($pdo) ||!isset($table) || !isset($id))
		return false;
	list($table,$schemaname) = explode('@',$table);
	if(empty($schemaname)) $schemaname = $table;
	$pk = $pk ?: db_schema($schemaname)['general']['pk'];
	$entity =pdo_count($pdo, "select count(*) from $table where `$pk`=:$pk",[$pk=>$id]);
	return $entity>0;
}
function pdo_trans($pdo,$querys,$datas,$pdoOpt=null){
	if(isset($_REQUEST['__db_error'])) unset($_REQUEST['__db_error']);
	if(!isset($pdo)||!isset($querys))
		return false;
	if($pdoOpt==null)$pdoOpt=PDO::FETCH_ASSOC;
	$mod = $pdo->getAttribute(PDO::ATTR_ERRMODE);
	$pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, 0 );
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$cnt = 0;
	$res = true;
	try{
		$pdo->beginTransaction();
		if(is_callable($querys)){
			$cnt = $querys($pdo);
		}else if(is_array($querys)){
			$i=0;
			foreach($querys as $q){
				$i++;
				if($q==='@rollback'){
					$pdo->rollBack();$cnt--;
				}else{
					$statement = $pdo->prepare($q);
					if(!$statement){
						error_log("PDO TRANS Failed : ".$pdo->errorInfo());
						continue;
					}
					$data = isset($datas[$i-1])?$datas[$i-1]:[];
					if ($statement->execute($data) == false) {
						error_log("PDO TRANS Failed : ".$pdo->errorInfo());
						continue;
					}
					if(str_starts(strtolower($q), 'select')){
						$res = $statement->fetchAll($pdoOpt);					
					}
					$cnt++;
				}
			}
		}
		if($cnt>0)
			$pdo->commit();
		else
			$pdo->rollBack();
	}catch(Exception $e){
		error_log('DB Transaction ERR:'.$e->getMessage());
		$data = [
			'trace' => $e->getTraceAsString(),
			'code' => $e->getCode(),
			'msg' => $e->getMessage(),
		];
		$_REQUEST['__db_error'] = $data;
		exception_save($data);

		$pdo->rollBack();
        $res = false;
	}
	$pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, 1);
	$pdo->setAttribute(PDO::ATTR_ERRMODE, $mod);
	return $res;
}
function db_query($sql, $datas=[], $useCache = false, $pdoOpt=null) {
	$db = false ;
	try {
		$db = db_conn();
		$res = pdo_query($db, $sql, $datas, $pdoOpt);
		$db = null;
		return $res;
	} catch ( PDOException $e ) {
		error_log ('DB ERR :'. $e->getMessage() );
		error_log ('DB ERR SQL:'. $sql );
		$data = [
			'reason' => $sql,
			'trace' => $e->getTraceAsString(),
			'code' => $e->getCode(),
			'msg' => $e->getMessage(),
		];
		exception_save($data);

		$db = null;
		return null;
	}
}
function db_desc($table, $fullInfo=false){
	$db = db_conn();
	$cs = pdo_desc($db,$table);
	$db = null;
	return $fullInfo? $cs : array_map(function($e){return $e['Field'];},$cs);
}
function db_query_column($sql, $datas=[]){
	return db_query($sql,$datas,false,PDO::FETCH_COLUMN);
}
function db_count($sql=null, $datas=[], $useCache=false){
	$db = null;
	try {
		if($useCache){
			$value = cache_get($sql);
			if(isset($value) && $value!=false)
				return $value;
		}
		$db = db_conn();
		$res = pdo_count($db, $sql, $datas);
		if($useCache && $res){
			cache_set($sql, $res);
		}
		$db=null;
		return $res;
	} catch (PDOException $e) {
		elog($e,$sql);
		$data = [
			'reason' => $sql,
			'trace' => $e->getTraceAsString(),
			'code' => $e->getCode(),
			'msg' => $e->getMessage(),
		];
		exception_save($data);

		$db=null;
		return 0;
	}
}
function db_find($table, $opts=[], $withCount=false, $pdoOpt=null, $schema_def=false){
	$db = db_conn();
	$rs = pdo_find($db, $table, $opts, $withCount, $pdoOpt, $schema_def);
	$db = null;
	return $rs;
}
function db_find1st($table, $opts=[], $pdoOpt=null,$schema_def=false){
	$opts['limit']=1;
	$res = db_find($table,$opts,false,$pdoOpt,$schema_def);
	return isset($res)&&$res!=false ? $res[0]:false;
}
function db_import($table, $datas){
	$db = db_conn();
	$rs = pdo_import($db, $table, $datas, Consts::$schema_reg, Consts::$schema_upd);
	$db = null;
	return $rs;
}
function db_fields($schema, $prefix="", $others=[]) {
	$prefix = $prefix?:"";
	$fields = [];
	if($schema) {
		$tbl_schema = db_schema($schema)['schema']?:[];
		foreach($tbl_schema as $k=>$v) {
			$field = !empty($prefix)?"${prefix}.${k}": $k;
			$fields[] = $field;
		}
	}
	foreach($others as $field) { $fields[] = $field; }
	return $fields;
}

function db_orderby($fields, $order, $default_order="") {
	$order  = trim($order);
	$order  = preg_replace('/\\s+/usm', ' ', $order);
	if(empty($fields) || empty($order)) return $default_order;

	if(is_hash($fields)) {
		$fields_m = $fields;
	} else {
		$fields_m = [];
		array_map(function($f) use(&$fields_m){ $fields_m[$f] = 1; }, $fields);
	}

	$rs = [];
	$vs = explode(",", $order);
	foreach($vs as $v) {
		$v=trim($v);
		$vv = explode(" ", $v);
		$field = $vv[0];
		$sort  = $vv[1]?:"";
		if(!array_key_exists($field, $fields_m)){
			continue;
		}
		$sort = in_array(strtolower($sort), ['asc', 'desc'])?$sort: "asc";
		$rs[] ="$field $sort";
	}
	
	if(empty($rs)) return $default_order;
	$res = implode(", ", $rs);
	return $res;
}
function db_make_query(&$table, $opts=[], $omit=[], $colPrefix=false, $schemaDef=false){
	db_init_filters();
	if(!isset($table))return false;
		list($table,$schemaname) = explode('@',$table);
	if(empty($schemaname)) $schemaname = $table;
	$colStr = '*'; 	if(!empty($schemaDef)){
		$schemaDef=is_string($schemaDef)?json_decode($schemaDef,true):$schemaDef;
	}else{
		$schemaDef = db_schema($schemaname);
	}
	$pk = $schemaDef['general']['pk'];
	$schema = $schemaDef['schema'];
	$connect = $schemaDef['connect'];
	$connNames = !empty($connect) ?array_keys($connect):[];
	if($colPrefix)$colPrefix.=".";
	$data = [];
	$conns = [];
		if(is_hash($opts) && !empty($opts['fields']) && 
		(preg_match('/[\{\}\.]+/',$opts['fields'])) || (!empty($connNames)&&preg_match('/\b('.join('|',$connNames).')\b/', $opts['fields'])) ){ 				preg_match_all('/\b(?P<tbl>[\w\d_]+)\{(?P<cols>[^\}]+)\}/', $opts['fields'], $ma);
		if(!empty($ma['tbl'])){
			$i=0;
			foreach ($ma['tbl'] as $tbl) {
				if(!isset($connect[$tbl]))continue;				if(!isset($conns[$tbl])) $conns[$tbl] = ['fields'=>[$connect[$tbl]['target_column']]]+$connect[$tbl];
				$conns[$tbl]['fields'] = array_merge($conns[$tbl]['fields'],explode(',',$ma['cols'][$i++])) ;
			}
			$opts['fields'] = preg_replace(['/\b(?P<tbl>[\w\d_]+)\{(?P<cols>[^\}]+)\}/','/^,/','/,$/'], '', $opts['fields']);
		}
				$cols =  explode(',',$opts['fields']);
		$ncols = [];
		foreach ($cols as $f) {
			$f = trim($f);
			if(in_array($f, $connNames)){
				$conns[$f] = ['fields'=>'*']+$connect[$f];
			}else if(str_has($f, '.')){
				list($tbl, $col) = explode('.', $f);
				if(in_array($tbl, $connNames)){
					if(!isset($conns[$tbl])) $conns[$tbl] = ['fields'=>[$connect[$tbl]['target_column']]]+$connect[$tbl];
					$conns[$tbl]['fields'][] = $col;	
				}
			}else{
				if($f=='*' || array_key_exists($f, $schema))
					$ncols[]=$f;
			}
		}
		$connFields = array_keys($conns);
		foreach ($connFields as $cf) {
			if($opts['fields']!='*' && !preg_match('/\b'.$connect[$cf]['target_column'].'\b/i',$opts['fields'])){
				$ncols []= $connect[$cf]['column'];
			}	
		}
		$colStr = '`'.join('`,`',$ncols).'`';
	}else{
		if(!empty($opts['fields']) && $opts['fields']!='*'){
			$colStr = is_string($opts['fields'])? explode(',',preg_replace('/[`\s]/','',$opts['fields'])):$opts['fields'];
			$colStr = array_filter($colStr, function($e) use($schema, $omit){return array_key_exists($e, $schema) && !in_array($e,$omit);});
			$colStr = $colPrefix? $colPrefix.'`'.join('`,'.$colPrefix.'`', $colStr).'`':'`'.join('`,`', $colStr).'`';
		}else if($colStr=='*' && !empty($schemaDef['general']['fields'])){
			$colStr = $colPrefix? $colPrefix.'`'.str_replace(',', '`,'.$colPrefix.'`', $schemaDef['general']['fields']).'`':'`'.str_replace(',', '`,`', $schemaDef['general']['fields']).'`';
		}
	}
	if(is_hash($opts)){
		$optStr = [];
				if(array_key_exists('@id', $opts) && array_key_exists('id', $opts)) {
			unset($opts['@id']);unset($opts['limit']);
		}
		foreach ($opts as $k => $v){
			preg_match_all('/^(?<tbl>[\w\d_]+)\./i',$k,$ma);
			if(!empty($ma['tbl'])){				$tbl = $ma['tbl'][0];
				$col = substr($k, strlen($tbl)+1);
				if(empty($conns[$tbl])) continue;
				if(!isset($conns[$tbl]['query']))
					$conns[$tbl]['query'] = [];
				$conns[$tbl]['query'][$col] = $v;
			}else{
				if($k=='@id')$k=$pk;
				list($k,$cmd) = explode('@',$k);
				$keys = array_filter(preg_split('/\|/',$k), function($k) use($schema, $omit){
					return array_key_exists($k, $schema) && !in_array($k,$omit);
				});
				if(!empty($keys)){
					$cmd = !isset($cmd)||$cmd=='' ? '=':$cmd;
					$cmd = strpbrk($cmd, 'begilmnqt') !==false? Consts::$query_filter_names[$cmd]:$cmd;
					$func = Consts::$db_query_filters[$cmd];
					$vStr = $func(join('|', $keys), $v, $data);
					if($vStr) $optStr []= $vStr;
				}	
			}
		}
		$optStr =  empty($optStr) ? '': ' WHERE '.join(' AND ', $optStr);
		if(!in_array('order',$omit) && !empty($opts['order']))
			$optStr .= ' ORDER BY '.db_orderby($schema,$opts['order'],$pk);
			// $optStr .= ' ORDER BY '.$opts['order'];
		if(!in_array('limit',$omit) && !empty($opts['limit']))
			$optStr .= ' LIMIT '.Utils::db_limit($opts['limit']);
			// $optStr .= ' LIMIT '.$opts['limit'];
	}else {
		$optStr = !empty($opts)? ' WHERE '. $opts : '';
	}
	return [$colStr,$optStr,$data,$conns];
}
function db_exists($table, $id){
	$db = db_conn();
	$rs = pdo_exists($db, $table, $id);
	$db = null;
	return $rs;
}
function db_delete($table, $opts){
	if(empty($opts))return false;
	list($cs,$optStr,$data) = db_make_query($table, $opts,['order','limit','fields']);
	$sql = 'DELETE FROM '.$table.' '.$optStr;
	return db_query($sql,$data);
}
function db_update($table, $data, $opts=[]){
	$tableschema = $table;
	if(!isset($table) || empty($data) || !is_hash($data))
		return false;
	$vStrs = [];
	list($table,$schemaname) = explode('@',$table);
	if(empty($schemaname)) $schemaname = $table;
	$schema = db_schema($schemaname)['schema'];
	foreach($data as $k=>$v){
		$vStrs[]='`'.$k.'`='.db_v($v, $schema[$k]);
	} 
	$vStrs = join(',',$vStrs);
	list($cs,$optStr,$data) = db_make_query($tableschema, $opts,['order','limit','fields']);
	$sql = 'UPDATE '.$table.' SET '.$vStrs.' '.$optStr;
	return db_query($sql,$data);
}
function db_migrate($schemaName, $tableName=null){
	$pdo = db_conn();
	pdo_migrate($pdo,Consts::$db_name,$schemaName,$tableName);
	$pdo = null;
}
function pdo_migrate($pdo,$dbn,$schemaName, $tableName=null){
	$isCLI = (php_sapi_name() === 'cli');
	if(empty($tableName))$tableName=$schemaName;
	$schema_def = db_schema($schemaName);
	$dbms = $schema_def['general']['dbms'];
	if(isset($dbms)&&$dbms!="mysql"){
		// TODO others dbms
		echo "Ignore $dbms table: ".$tableName."\n";
		return;
	}
	$sql = "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$dbn' AND table_name='$tableName'";
	$res = pdo_count($pdo,$sql,[], false);
	$exists = $res>0;
	if ($res<=0 ){//schema doesn't exist
		$schema = $schema_def['schema'];
		$pk = $schema_def['general']['pk'];

		//db engine
		$engine = $schema_def['general']['engine'];
		if(empty($engine)) $engine='InnoDB';

		$colStmt = '';
		foreach ($schema as $col => $type){
			$colStmt .= '`'.$col.'` '.$type.', ';
		}
		$incStmt = '';
		$auto_increment = $schema_def['general']['auto_increment'];
		if($auto_increment) $incStmt .= 'auto_increment='.$auto_increment;

		$sql = '';
		if (str_has($pk, '|')||str_has($pk, '+')||str_has($pk, ',')){
			$parts = preg_split('/[\|\+,]/', $pk);
			$pkName = join('_',$parts);
			$keys = '`'.join('`,`',$parts).'`';
			$sql = "CREATE TABLE `$dbn`.`$tableName` ( $colStmt CONSTRAINT $pkName PRIMARY KEY ($keys)) ENGINE=$engine $incStmt DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;";
		}else{
			$sql = "CREATE TABLE `$dbn`.`$tableName` ( $colStmt PRIMARY KEY (`$pk`)) ENGINE=$engine $incStmt DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;";
		}
		$res = pdo_query($pdo,$sql);
		
		//index
		$index = $schema_def['index'];
		if(!empty($index)){
			foreach ($index as $cols => $desc){
				$name = 'idx_'.preg_replace('/[\+\.\|]/', '_',$cols);
				$cols = preg_replace('/[\+\.\|]/', '`,`',$cols);
				$desc = $desc=='unique'?'UNIQUE ':' ';
				$indextype = ($engine == 'MEMORY' && strtolower($desc)=='hash') ? ' USING HASH' : ' ';
				$sql = "CREATE ${desc}INDEX $name ON `$tableName` (`$cols`) $indextype";
				if($isCLI) echo $sql.'\n';
				pdo_query($pdo,$sql);
			}
		}
		
	}
	if($isCLI && !$exists)
		echo 'Created '.$tableName."\n";
	else return true;
}
function db_save($table, $data, $returnId=false, $schema_def=false){
	$db = db_conn();
	$rs = pdo_save($db, $table, $data, $returnId,$schema_def);
	$db = null;
	return $rs;
}

function exception_save($data){
	return;
	try{
		$opts = [
			'engine'=>'mysql',
			'host'	=>Consts::$db_host,
			'port'	=>Consts::$db_port,
			'db'	=>Consts::$extdb_name,
			'user'	=>Consts::$db_user,
			'pass'	=>Consts::$db_pass,
		];
		if (ini_get('mysqlnd_ms.enable')) {
			$conn_str = $opts['engine'].':host='.$opts['host'].';dbname='.$opts['db'].';charset=utf8mb4';
		} else {
			$conn_str = $opts['engine'].':host='.$opts['host'].';port='.$opts['port'].';dbname='.$opts['db'].';charset=utf8mb4';
		}
		$pdoOpts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,PDO::ATTR_PERSISTENT => false];
		$dbh =  new PDO($conn_str,$opts['user'],$opts['pass'],$pdoOpts);

		$table = 'exception_logs';
		$schema_def = db_schema($table);
		$schema = $schema_def['schema'];

		if ($dbh == null){
			elog(Consts::$extdb_name ."接続に失敗しました。");
			return;
		}
	
		// set 0 when bid is not set
		$data["bid"] = empty($data['bid'])?0:$data['bid'];
		// set hostname
		$data["host"] = gethostname();

		// set system time to ins_t
		$regName = Consts::$schema_reg;
		$data[$regName] = time();

		foreach ($data as $col => $val){
			if(str_ends($col,'+')) {
				$opr = substr($col,-1);
				$col = substr($col,0,-1);
			}
			if(!isset($schema[$col]))continue;
			if(!empty($colStmt))$colStmt .= ',';
			if(!empty($valStmt))$valStmt .= ',';
			$colStmt .= '`'.$col.'`';
			$valStmt .= $opr? '`'.$col.'` + :'.$col.' ' : ':'.$col;
			$qdatas[$col] = is_array($val)?json_encode($val):$val ;		
		}
		$sql = 'INSERT '.' INTO `'.$table.'` ('.$colStmt.') VALUES('.$valStmt.')';
		$stmt = $dbh->prepare($sql);
		if ($stmt->execute ($qdatas) == FALSE) {
			elog('exception_logs save error, because '.json_encode($qdatas));
			return ;
		}
	} catch (PDOException $e){
		elog('exception_logs save error, because '.$e->getMessage());
	}
	$dbh = null;
	return;
}

function db_schema($schemaName=null){
	$schemas = cache_get('DB_SCHEMAS', function($key){
		$ss = ['project'=>load_schemas($key)];
		if(!empty(Conf::$framework)){
			$ss['framework'] = load_schemas($key,Conf::$framework.'/conf/schemas');
		}
		return $ss;
	}, false);
	// elog($schemas['project'][$schemaName],"SCHE");
	return isset($schemaName)? ($schemas['project'][$schemaName]?:$schemas['framework'][$schemaName]):$schemas;
}

function load_schemas($key, $dir=false){ 
	$dir = $dir ?: APP_DIR.__SLASH__.'conf'.__SLASH__.'schemas';
	$files = glob($dir."/*.ini");
	$schemas = [];
	$conns = [];
	foreach ($files as $f) {
		$n = str_replace([$dir.'/','.ini'], '', $f);
		$s = parse_ini_file($f, true);
		if(!empty($s['connect'])){
			$conns = [];
			foreach ($s['connect'] as $ck => $cv) {
				preg_match_all('/(?P<col>[\w\d_]+)\s*=\s*(?P<tbl>[\w\d_]+)\.(?P<tarCol>[\w\d_]+)/', $cv, $mc);
				if(!empty($mc['col'])&&!empty($mc['tbl'])&&!empty($mc['tarCol'])){
					$conns[$ck] = [
						'column' 		=> $mc['col'][0],
						'table' 		=> $mc['tbl'][0],
						'target_column' => $mc['tarCol'][0],
					];
				}else{
					throw "DB ERR: wrong format in $f.ini [connect], should be [MAPPING_NAME = 'COLUMN_NAME = TABLE_NAME.COLUMN_NAME']";
				}
			}
			$s['connect'] = $conns;
		}
		$schemas[$n] = $s;
	}
	return $schemas;
}
function db_trans($querys,$datas=[]){
	$db = db_conn();
	$rs = pdo_trans($db, $querys,$datas);
	$db = null;
	return $rs;
}
function db_escape($v){
    return str_replace(["\\",  "\x00", "\n",  "\r",  "'",  '"', "\x1a"], ["\\\\","\\0","\\n", "\\r", "\'", '\"', "\\Z"], $v);
}

function s3_get($fn){
	$r = cache_get('S3:'.$fn, function($k) use ($fn){
		//$url = "https://anyhook-dev.s3.amazonaws.com/apps/1_315f6a615f4a50.json";
		$url = 'https://'.Conf::$aws_s3_bucket.'.s3.amazonaws.com/'.$fn;
		$r = file_get_contents($url);
		return $r ?: false;
	}, false);
	return $r;
}
function s3_clear_cache($fn){
	cache_del('S3:'.$fn);
}
function s3_url_prefix($bucket) {
	if(!Conf::$s3_cdn_enabled) {
		return "https://" . $bucket . ".s3.amazonaws.com";
	}

	$bucket = Conf::$s3_cdn_enabled&&Conf::$s3_cdn_bm[$bucket]?Conf::$s3_cdn_bm[$bucket]: $bucket;
	if(Conf::$s3_cdn_enabled&&!empty(Conf::$s3_cdn_urls[$bucket])) {
		return Conf::$s3_cdn_urls[$bucket];
	} else {
		return "https://" . $bucket . ".s3.amazonaws.com";
	}
}
function is_s3_cdn_url($bucket, $url) {
	if(!Conf::$s3_cdn_enabled || empty($url)) return false;

	$bucket = Conf::$s3_cdn_enabled&&Conf::$s3_cdn_bm[$bucket]?Conf::$s3_cdn_bm[$bucket]: $bucket;
	$cdn_url = Conf::$s3_cdn_urls[$bucket];
	return !empty($cdn_url)?str_starts($url, $cdn_url): false;
}
function s3_url_to_cdn($bucket, $url) {
	if(!Conf::$s3_cdn_enabled || empty($url)) return $url;

	$bucket = Conf::$s3_cdn_enabled&&Conf::$s3_cdn_bm[$bucket]?Conf::$s3_cdn_bm[$bucket]: $bucket;
	if(Conf::$s3_cdn_enabled&&!empty(Conf::$s3_cdn_urls[$bucket]) && str_starts($url, "https://$bucket.s3.amazonaws.com")) {
		return Conf::$s3_cdn_urls[$bucket] . preg_replace("/https:\/\/.*\.amazonaws\.com/ui", '', $url);
	}
	return $url;
}

function bson_enc($arr){
	$str = json_encode($arr);
	$str = str_replace('\\', '', $str);
	return str2hex($str);
}
function bson_dec($bson){
	if(isset($bson)){
		$json = hex2str($bson);
		return json_decode($json,true);
	}
	return false;
}
function db_v($v, $typeDef='', $bsonText=false){
	$tp = explode(" ",$typedef)[0];
	if(!isset($v))
		return 'NULL';
	if(is_bool($v))
		return $v ? 1 : 0;
	if (is_array($v)){
		return $bsonText&&(isset($tp)&&preg_match('/text/i', $tp))? '\''.bson_enc($v).'\''
				: '\''.db_escape(json_encode($v)).'\'';
	}
	if(is_string($v)){
		if(preg_match('/bigint/i', $tp) && str_has($v, '-'))
			return strtotime($v);
		if(preg_match('/(int|byte)/i', $tp))
			return intval($v);
		return "'".db_escape($v)."'";
	} 
	return $v;
}
function db_make_filters($k,$k_operator, $v, $v_operator, &$o, $func_make) {
	$keys = is_array($k)? $k : preg_split('/\|/',$k);
	$values = is_array($v)? $v : preg_split('/\|/',$v);
	$conditions =[]; $idx=0;
	foreach($keys as $_k) {
		$sub_cond = [];
		foreach($values as $_v) {
			$sql = $func_make($_k, $_v, $o, $idx);
			if($sql) $sub_cond[] = $sql;
			$idx++;
		}
		if(!empty($sub_cond))
			$conditions[] = count($values)>1 ? '('. join(' '.$v_operator.' ', $sub_cond) .')' : join(' '.$v_operator.' ', $sub_cond);
	}
	if(count($conditions) <=0 ) return false;
	return count($conditions)>1 ? '('. join(' '.$k_operator.' ', $conditions) .')' : join(' '.$k_operator.' ', $conditions);	
}
function db_init_filters(){
	if(empty(Consts::$db_query_filters))
		Consts::$db_query_filters = [
		'=' 	=> function($k,$v,&$o){
			return db_make_filters($k, 'or', $v, 'or', $o, function($k,$v, &$o, $idx){
				if($v==='NULL') return '`'.$k.'` IS NULL'; else { $_k = $k.'_'.$idx; $o[$_k]=$v;return '`'.$k.'`=:'.$_k; }
			});
		},
		'!' 	=> function($k,$v,&$o){
			return db_make_filters($k, 'and', $v, 'and', $o, function($k,$v, &$o, $idx){
				if($v==='NULL') return '`'.$k.'` IS NOT NULL'; else { $_k = $k.'_'.$idx;$o[$_k]=$v;return '`'.$k.'`!=:'.$_k.''; }
			});
		},
		'<' 	=> function($k,$v,&$o){
			return db_make_filters($k, 'and', $v, 'and', $o, function($k,$v, &$o, $idx){
				$_k = $k.'_'.$idx; $o[$_k]=$v;return '`'.$k.'`<:'.$_k.'';
			});
		},
		'>' 	=> function($k,$v,&$o){
			return db_make_filters($k, 'and', $v, 'and', $o, function($k,$v, &$o, $idx){
				$_k = $k.'_'.$idx; $o[$_k]=$v;return '`'.$k.'`>:'.$_k.'';
			});
		},
		'<=' 	=> function($k,$v,&$o){
			return db_make_filters($k, 'and', $v, 'and', $o, function($k,$v, &$o, $idx){
				$_k = $k.'_'.$idx; $o[$_k]=$v;return '`'.$k.'`<=:'.$_k.'';
			});
		},
		'>=' 	=> function($k,$v,&$o){
			return db_make_filters($k, 'and', $v, 'and', $o, function($k,$v, &$o, $idx){
				$_k = $k.'_'.$idx; $o[$_k]=$v;return '`'.$k.'`>=:'.$_k.'';
			});
		},
		'[]' 	=> function($k,$v,&$o){
			return db_make_filters($k, 'and', $v, 'and', $o, function($k,$v, &$o, $idx){
				if(is_string($v))$v=explode(',',$v);if(count($v)==0)return false;$vs=array_map(function($e){return db_v($e);},$v);return '`'.$k.'` IN ('.join(',',$vs).')';
			});
		},
		'![]' 	=> function($k,$v,&$o){
			return db_make_filters($k, 'and', $v, 'and', $o, function($k,$v, &$o, $idx){
				if(is_string($v))$v=explode(',',$v);if(count($v)==0)return false; $vs=array_map(function($e){return db_v($e);},$v);return '`'.$k.'` NOT IN ('.join(',',$vs).')';
			});
		},
		'()' 	=> function($k,$v,&$o){
			return db_make_filters($k, 'and', $v, 'and', $o, function($k,$v, &$o, $idx){
				if(is_string($v))$v=explode(',',$v);if(count($v)!=2)return false; return '(`'.$k."` BETWEEN '".min($v[0],$v[1])."' AND '".max($v[0],$v[1])."')";
			});
		},
		'!()' 	=> function($k,$v,&$o){
			return db_make_filters($k, 'and', $v, 'and', $o, function($k,$v, &$o, $idx){
				if(is_string($v))$v=explode(',',$v);if(count($v)!=2)return false; return '(`'.$k."` NOT BETWEEN '".min($v[0],$v[1])."' AND '".max($v[0],$v[1])."')";
			});
		},
		'?'  	=> function($k,$v,&$o){
			// bugfixed: 「'」=>「\'」
			// if(preg_match_all('/\'/uim', $v,$m1) && !preg_match_all('/\\\\\'/uim', $v,$m2)) $v= preg_replace('/\'/uim', '\\\'',$v);
			return db_make_filters($k, 'or', $v, 'or', $o, function($k,$v, &$o, $idx){
				// if(!str_has($v,'%'))$v='%'.$v.'%';return '`'.$k.'` LIKE \''.preg_replace('/[\+\s]+/','%',$v).'\'';
				$_k = '__l_'.$k.'_'.$idx;if(!str_has($v,'%'))$v='%'.$v.'%';$o[$_k]=$v;return '`'.$k.'` LIKE :'.$_k;
			});
		},
		'!?'  	=> function($k,$v,&$o){
			// bugfixed: 「'」=>「\'」
			// if(preg_match_all('/\'/uim', $v,$m1) && !preg_match_all('/\\\\\'/uim', $v,$m2)) $v= preg_replace('/\'/uim', '\\\'',$v);
			return db_make_filters($k, 'and', $v, 'and', $o, function($k,$v, &$o, $idx){
				// if(!str_has($v,'%'))$v='%'.$v.'%';return '`'.$k.'` NOT LIKE \''.preg_replace('/[\+\s]+/','%',$v).'\'';
				$_k = '__nl_'.$k.'_'.$idx;if(!str_has($v,'%'))$v='%'.$v.'%';$o[$_k]=$v;return '`'.$k.'` NOT LIKE :'.$_k;
			});
		},
		'~' 	=> function($k,$v,&$o){
			return db_make_filters($k, 'or', $v, 'or', $o, function($k,$v, &$o, $idx){
				$op = Consts::$db_regexp_op[Consts::$db_engine];if(!isset($op))return false;return '`'.$k.'` '.$op.' \''.db_escape(preg_replace('/^\/|\/$/','',$v)).'\'';
			});
		},
		'!~'	=> function($k,$v,&$o){
			return db_make_filters($k, 'or', $v, 'or', $o, function($k,$v, &$o, $idx){
				$op = Consts::$db_regexp_op[Consts::$db_engine];if(!isset($op))return false;return '`'.$k.'` NOT '.$op.' \''.db_escape(preg_replace('/^\/|\/$/','',$v)).'\'';
			});
		},
		'~~'	=> function($k,$v,&$o){
			return db_make_filters($k, 'or', $v, 'or', $o, function($k,$v, &$o, $idx){
				$op = Consts::$db_regexp_op[Consts::$db_engine];if(!isset($op))return false;return 'LOWER(`'.$k.'`) '.$op.' \''.db_escape(preg_replace('/^\/|\/$/','',$v)).'\'';
			});
		},
		'!~~'	=> function($k,$v,&$o){
			return db_make_filters($k, 'or', $v, 'or', $o, function($k,$v, &$o, $idx){
				$op = Consts::$db_regexp_op[Consts::$db_engine];if(!isset($op))return false;return 'LOWER(`'.$k.'`) NOT '.$op.' \''.db_escape(preg_replace('/^\/|\/$/','',$v)).'\'';
			});
		},
			];
}
class Render {
	
	private static $var_prefix = 'LBR_';
	private static $output_path;
	private static $ext = '.html';
	private $layout = '_layout';
	private $data = [];
	private $contents = [];
	private $path;
	private function __construct(){}
	static function factory($path){
		self::$output_path = APP_DIR.__SLASH__.'tmp'.__SLASH__;
		$render = new Render();
		$render->path = $path.__SLASH__;
		return $render;
	}
	function assign($key, $value){
		$this->data[$key] = $value;	
	}
	static function solvePluginPath($fn){
		$fk = preg_replace('/\.html$/','',str_replace(APP_DIR."/views/",'',$fn));
		if(!empty($_REQUEST['__SERVICE'])){
			return str_replace('/_'.$_REQUEST['__SERVICE'].'/','/',$fn);
		}
		return $fn;
	}

	function render($file,$data=[],$layout=null,$renderOnly=false){
		$req = REQ::getInstance();
		$template = isset($layout)? $layout : $this->layout;
		$ns = $req?$req->getNamespace():"";
		$ns = empty($ns)?"":$ns."-";
		$plugin = $_REQUEST['__SERVICE']?:false;
		$key = 'template-'. REQ::getTemplateType()."-".($plugin?$plugin."-":"").$ns.$template;	
		if(!empty($plugin)){
			$key = preg_replace('/(_'."$plugin-".')/', "", $key);
		}	
		
// echo $key;exit;
		$wrapper_code = cache_get($key, function($f) use($plugin, $template){
			$req = REQ::getInstance();
			$ns = $req?$req->getNamespace():"";
			$path = $this->path;

			if(str_starts($template,"/")){
				$path = str_replace("/".$ns."/","",$path);
			}
			if(!empty($plugin))
				$path = preg_replace('/(_'."$plugin".'\/)+/', "_$plugin/", $path);

			$ns = empty($ns)?"":$ns."-";
			$key_prefix = 'template-'.REQ::getTemplateType()."-$plugin-".$ns;
			$fn = $path.str_replace($key_prefix,'',$template);
			
			if(!str_ends($fn,'.html')) $fn.='.html';	
// echo $fn;exit;
			// $fn = self::solvePluginPath($fn);
			return $fn? file_get_contents($fn) : "";
		},false);
		if(!empty($data))
		foreach ($data as $k=>$v)
			$this->data[$k] = $v;
		$this->data['__render'] = $this;
		$req = REQ::getInstance();
		if($req){
			if(!$this->data['__controller']) $this->data['__controller'] = $req->getController();
			if(!$this->data['__namespace']) $this->data['__namespace'] = $req->getNamespace();
			if(!$this->data['__action']) $this->data['__action'] = $req->getAction();
			if(!$this->data['__params']) $this->data['__params'] = $req->params;
		}
		$_REQUEST[self::$var_prefix."TMP_DATA"] = $this->data;
		extract($this->data, EXTR_PREFIX_ALL, self::$var_prefix);
		// echo $wrapper_code;exit;
		$r = $this->render_file($file,$wrapper_code);
		$output = null;
		if($r){
			if($renderOnly){
				ob_start(); 
				include($r);
				$output = ob_get_contents();
				ob_end_clean();
			}else{
				include($r);
			}
		}
		unset($this->data);
		unset($data);
		if($output)
			return $output;
	}
	function render_file($file,$template_code){
		$prefix  = 'template-' . REQ::getTemplateType() . '-';
		$plugin = $_REQUEST['__SERVICE'];
		if(!empty($plugin)) $prefix = $prefix.$plugin."-";
		if(!empty($this->data['__namespace'])){
			$prefix .= $this->data['__namespace']."-";
			$prefix = str_replace("/","-",$prefix);
		}
		$path = $this->path;
		$path = preg_replace('/(_'."$plugin".'\/)+/', "_$plugin/", $path);
		if(str_starts($file,"/")){
			$req = REQ::getInstance();
			$ns = $req?$req->getNamespace():"";
			$path = str_replace("/".$ns."/","",$path);
		}
		$filepath = $path.$file;
		if(!file_exists($filepath) && !empty($plugin)) 
			$filepath = str_replace('_'.$plugin.'/','',$filepath);	
		$outpath = self::$output_path. $prefix .str_replace(self::$ext,'.php',str_replace('/','--',$file));
		// echo $outpath;exit;
		if(!file_exists($outpath)
						||Consts::$mode=="Developing"){
			$code = $this->compile($filepath,$template_code);
			if(isset($code) && $code!=""){
				file_put_contents($outpath,$code);
				unset($code);
			}
		}
		return $outpath;
	}
	function compile($file,$wrapper){
		list($before, $after) = $wrapper?explode('__CONTENTS__', $wrapper):["",""];
		$src = $before.file_get_contents($file).$after;
		$rows = preg_split('/(\{[^\{^\}]*\})/', $src, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY ) ;
		$phpcode = '';
		$indent = 0;
		$ignore = false;
				$delegate_methods = get_class_methods('RenderDelegate');
		$custom_tags = [];
		foreach ($delegate_methods as $m) 
			if(str_starts($m,'tag_'))
				$custom_tags []= preg_replace('/^tag_/','',$m);
				$tags_regexp = (!empty($custom_tags)) ?
			'(%|=|if|elseif|else|break|ignore|for|var|include|'.join('|',$custom_tags).')':
			'(%|=|if|elseif|else|break|ignore|for|var|include)';
		while($code = array_shift($rows)){
			$matched = false;
			preg_match_all('/\{(?P<close>\/*)(?P<tag>'.$tags_regexp.'{0,1})\s*(?P<val>.*)\}/', $code, $matches);
			if(empty($matches[0])){
				$phpcode .= $code;
			}else{
				list($close, $tag, $val) =  [$matches['close'][0]=="/"?true:false, $matches['tag'][0], trim($matches['val'][0])];
				if($tag=='' || $tag=='=')$tag='echo';
				if($tag=='%')$tag='text';
				$val = $tag=="text"?$val: preg_replace('/\.([a-zA-Z0-9_]+)/', "['$1']",$val);
				if(!preg_match('/\$(_GET|_POST|_REQUEST|_SESSION)/', $val))
					$val = preg_replace('/\$/','$'.self::$var_prefix."_",$val);
				if($close){
					if($tag=='if'||$tag=='for')$indent --;
					if($tag=='ignore'){
						$ignore = false;
					}else{
						$phpcode .= '<?php } ?>';
					}
				}else if($ignore){
					$phpcode .= $code;
				}else if(!empty($custom_tags)&&in_array($tag, $custom_tags)){
										$phpcode .= "<?php echo RenderDelegate::tag_{$tag}(".(empty($val)?'""':'"'.$val.'"').", \$_REQUEST['".self::$var_prefix."TMP_DATA']); ?>";
				}else{
					switch($tag){
						case 'for':
							$parts = preg_split('/\s*,\s*/',$val,-1,PREG_SPLIT_NO_EMPTY );
							$len = count($parts);
							$indent ++;
							switch($len){
								case 1:$phpcode .= '<?php foreach('.$parts[0]." as $".self::$var_prefix."_key=>$".self::$var_prefix."_value) { ?>";break;
								case 2:$phpcode .= '<?php foreach('.$parts[0]." as $".self::$var_prefix."_key=>".$parts[1].") { ?>";break;
								default :
									if((preg_match('/^\d+$/', $parts[1])) || (preg_match('/^\$/', $parts[1])) && (preg_match('/^\d+$/', $parts[2]))|| (preg_match('/^\$/', $parts[2]))){
										$phpcode .= '<?php for($'.$parts[0].'='.$parts[1].';$'.$parts[0].'<'.$parts[2].';$'.$parts[0].'++) { ?>';
									}else
										$phpcode .= '<?php foreach('.$parts[0].' as '.$parts[1].'=>'.$parts[2].') { ?>';break;
							}
							break;
						case 'if':
							$indent ++;
							$phpcode .= '<?php if('.$val.'){ ?>';break;
						case 'elseif':
							$phpcode .= '<?php }else if('.$val.'){ ?>';break;
						case 'else':
							$phpcode .= '<?php }else{ ?>';break;
						case 'break':
							$phpcode .= '<?php break; ?>';break;
						case 'echo':
							$phpcode .= '<?= '.$val.' ?>';break;
						case 'text':
							$vstr = preg_split('/,+/', trim($val));
							if(count($vstr)>1){
								$vstr = array_map(function($e){return trim($e);}, $vstr);
								$phpcode .= '<?= T("'.join('","',$vstr).'"); ?>';break;
							}else
								$phpcode .= '<?= T("'.$val.'"); ?>';break;
						case 'var':
							$phpcode .= '<?php '.$val.'; ?>';break;
						case 'include':
							$phpcode .= '<?php $__render->include_template("'.preg_replace('/\'"/',"",$val).'"); ?>';break;
						case 'ignore':
							$ignore = true;
							break;
						default:
							break;
					}				}			}
		}
		return $phpcode;
	}
	function include_template($f){
		$r = $this->render_file($f.'.html');
		$output = '';
		if($r) {
			ob_start(); include($r);
			$output = ob_get_contents();
			ob_end_clean();
		};
		echo $output;
		flush();
	}
	static function paginate($page,$total,$opts=['perPage'=>20]){
		$pp = ($opts['perPage']>0)? $opts['perPage']: 20;		$pi = $opts['items']?$opts['items']:9;		$ptotal = ceil($total/$pp);
		$size 	= min($ptotal, max(7,$pi));
		$pages 	= [$page];
		if($ptotal>$size){
			$seg = $size%2==0?$size+1:$size;
			for ($i=1;count($pages)<$seg;$i++){
				if($page-$i>=1) array_unshift($pages,$page-$i);
				if($page+$i<=$ptotal)$pages[]=$page+$i;
			}
			if(end($pages)<=$ptotal-1)
				$pages=array_merge(array_slice($pages,0,count($pages)-2),[0,$ptotal]);
			if($pages[0]>=2)
				$pages=array_merge([1,0],array_slice($pages,2,count($pages)));
		}else{
			$pages = [];
			for($i=1;$i<=$ptotal;$i++)
				if(!in_array($i,$pages))
					$pages[]=$i;
		}
		return ['pages'=>$pages,'cursor'=>array_search($page,$pages)];
	}
}

abstract class Filter{
	private function __construct(){}
	abstract public function before(&$params, $authRequired);
	abstract public function after($params, $authRequired);
	public static function factory($type){
		if (include APP_DIR.__SLASH__.'filters'.__SLASH__. $type. '.inc') {
            $classname = strtoupper($type[0]).substr($type,1).'Filter';
            return new $classname;
        } else {
            throw new Exception('filter not found');
        }
	}
}

function ql_query($opt,$data=[]){
	$f = !empty($opt['f'])?ql_parse_f($opt['f']):'';
	$s = !empty($opt['s'])?ql_parse_s($opt['s']):'*';
	$o = !empty($opt['o'])?ql_parse_o($opt['o']):'';
	$g = !empty($opt['g'])?ql_parse_g($opt['g']):'';
	$w = $opt['w'];
	$l = !empty($opt['l'])?$opt['l']:'';
	if(empty($f))return false;
	if(empty($w) && !empty($data)){
		$nd = [];
		$w = ql_parse_w(ql_build_w($od,$nd));
		$data = $nd;
	}else{
		$w = ql_parse_w(ql_filter(preg_replace(['/#.*/','/[\t\s]+/'],'',$w),$data));
			}
	$sql = "SELECT $s FROM $f ".
		(empty($w)?'':"WHERE $w ").
		(empty($g)?'':"GROUP BY $g ").
		(empty($o)?'':"ORDER BY $o ").
		(empty($l)?'':"LIMIT $l");
	try{
		$res = db_query($sql, $data);
		return $res;
	}catch(Exception $e){
		return [
			'error' => $e->getMessage(),
			'sql' => $sql
		];
	}
}
function ql_filter($w, &$data){
	preg_match_all('/:(?<v>[a-z0-9_]+)/i',$w,$mvs);
	$allvs = $mvs['v'];
	$ks = array_map(function($e){return explode('@', $e)[0];},array_keys($data));
		preg_match_all('/(?<pre>[&|]*)[a-z0-9_\.]+[=<>!]+:(?<v>[a-z]+[a-z0-9_]+)(?<sur>[&|]*)/i', $w, $m);
	if(!empty($m['v'])){
		$i=0;
		foreach ($m['v'] as $k) {
			$pre=preg_match('/[&|]/',$m['pre'][$i])?'\\'.$m['pre'][$i]:'';
			$sur=empty($pre)&&preg_match('/[&|]/',$m['sur'][$i])?'\\'.$m['sur'][$i]:'';
			if(!in_array($k, $ks))
				$w = preg_replace('/'.$pre.'[a-z0-9_.]+[=<>!]+:'.$k.$sur.'/i', '', $w);
			$i++;
		}
	}
		preg_match_all('/(?<pre>[&|]*)(?<name>[a-z0-9_\.]+)(?<n>!*)[\(\[]:(?<a>[a-z0-9_]+)[\]\)](?<sur>[&|]*)/i', $w, $m);
	if(!empty($m['a'])){
		$i = 0;
		foreach ($m['a'] as $a) {
												if(!empty($data[$a])){
								$vs = explode(',',$data[$a]);
				$j = 0;
				$rep = [];				foreach ($vs as $v){
					$data[$a.'_'.$j]=$v;
					$rep[]=':'.$a.'_'.$j;
					$allvs[]=$a.'_'.$j++;
				}
				$rep = join(',',$rep);
								unset($data[$a]);
				$ks = array_map(function($e){return explode('@', $e)[0];},array_keys($data));
				unset($allvs[array_search($a, $allvs)]);
								$w = preg_replace('/('.$m['name'][$i].$m['n'][$i].')([\(\[]):(?<a>[a-z0-9_]+)([\]\)])/i','$1$2'.$rep.'$4',$w);
			}else{				$pre=preg_match('/[&|]/',$m['pre'][$i])?'\\'.$m['pre'][$i]:'';				$sur=empty($pre)&&preg_match('/[&|]/',$m['sur'][$i])?'\\'.$m['sur'][$i]:'';				$w = preg_replace('/'.$pre.'[a-z0-9_\.]+!*[\(\[]'.$a.'[\]\)]'.$sur.'/i', '', $w);
			}
			$i++;
		}
	}
		preg_match_all('/(?<pre>[&|]*)[a-z0-9_\.]+!*[\(\[](?<v>[:a-z0-9_\.,]+)[\]\)](?<sur>[&|]*)/i', $w, $m);
	if(!empty($m['v'])){
		$i=0;
		foreach ($m['v'] as $v) {
			$pre=preg_match('/[&|]/',$m['pre'][$i])?'\\'.$m['pre'][$i]:'';			$sur=empty($pre)&&preg_match('/[&|]/',$m['sur'][$i])?'\\'.$m['sur'][$i]:'';			$vs = array_filter(explode(',', $v),function($e){return $e[0]==':';});
			if(empty($vs))continue;
			$vs = array_map(function($e){return substr($e, 1);}, $vs);
			if(count(array_intersect($vs, $ks)) != count($vs))				$w = preg_replace('/'.$pre.'[a-z0-9_\.]+!*[\(\[]'.$v.'[\]\)]'.$sur.'/i', '', $w);
			$i++;
		}
	}
		preg_match_all('/(?<pre>[&|]*)[a-z0-9_\.]+!*\/:(?<v>[^\/]+)\/(?<sur>[&|]*)/i', $w, $m);
	if(!empty($m['v'])){
		$i=0;
		foreach ($m['v'] as $k) {
			$pre=preg_match('/[&|]/',$m['pre'][$i])?'\\'.$m['pre'][$i]:'';
			$sur=empty($pre)&&preg_match('/[&|]/',$m['sur'][$i])?'\\'.$m['sur'][$i]:'';
			if(!in_array($k, $ks))
				$w = preg_replace('/'.$pre.'[a-z0-9_\.]+!*\/:'.$k.'\/'.$sur.'/i', '', $w);
			$i++;
		}
	}
		preg_match_all('/(?<pre>[&|]*)[a-z0-9_\.]+!*\{:(?<v>[^\}]+)\}(?<sur>[&|]*)/i', $w, $m);
	if(!empty($m['v'])){
		$i=0;
		foreach ($m['v'] as $k) {
			$pre=preg_match('/[&|]/',$m['pre'][$i])?'\\'.$m['pre'][$i]:'';
			$sur=empty($pre)&&preg_match('/[&|]/',$m['sur'][$i])?'\\'.$m['sur'][$i]:'';
			if(!in_array($k, $ks))
				$w = preg_replace('/'.$pre.'[a-z0-9_\.]+!*\{:'.$k.'\}'.$sur.'/i', '', $w);
			$i++;
		}
	}
		$w = preg_replace(['/\([&|]/','/[&|]\)/','/^[&|]/','/[&|]$/'], ['(',')','',''], $w);
	preg_match_all('/(?<pre>[&|]*)(?<bra>\(\))(?<sur>[&|]*)/i', $w, $m);
	if(!empty($m['bra'])){
		$i=0;
		foreach ($m['bra'] as $k) {
			$pre=preg_match('/[&|]/',$m['pre'][$i])?'\\'.$m['pre'][$i]:'';
			$sur=empty($pre)&&preg_match('/[&|]/',$m['sur'][$i])?'\\'.$m['sur'][$i]:'';
			$w = preg_replace('/'.$pre.'\(\)'.$sur.'/i', '', $w);
			$i++;
		}
	}
		$aks = array_filter($ks, function($k) use($allvs,&$data){if(in_array($k, $allvs))return true;else unset($data[$k]);return false;});
			return $w;
}
function ql_build_w($o, &$data){
	$qns = [
		'eq' 	=> '=$',
		'ne' 	=> '!=$',
		'lt' 	=> '<$',
		'gt'	=> '>$',
		'le' 	=> '<=$',
		'ge'	=> '>=$',
		'in'	=> '[$]',
		'nin' 	=> '![$]',
		'bt' 	=> '($)',
		'nb' 	=> '!($)',
		'l' 	=> '/$/',
		'nl' 	=> '!/$/',
		'm' 	=> '{$}',
		'nm' 	=> '!{$}',
	];
	$ws = [];
	if(is_hash($o)){
		foreach ($o as $k=>$v){
			list($c, $r)=explode('@', $k);
			$cn = str_replace('.', '_', $c);
			$i=0;
			while(isset($data[$cn])) 				$cn.="_".(++$i);
			$data[$cn] = $v;
			$ws []= !empty($r)? $c.str_replace('$', ":$cn", $qns[$r]):"$c=:$cn";
		}
		return implode('&', $ws);
	}else if(is_array($o)){
		foreach ($o as $e){
			$w=ql_build_w($e,$data);
			if(!empty($w))
				$ws []= '('.$w.')';
		}
		return implode('|', $ws);
	}
	return '';
}
function ql_parse_w($q){
	$sql = preg_replace([
			'/&&/','/\|\|/',			'/&/','/\|/',			'/\!=null/', 			'/=null/', 			'/([\da-z_\.]+)(\!*)\((:[^,]+),(:[^\)]+)\)/i', 			'/([\da-z_\.]+)(\!*)\(([^,]+),([^\)]+)\)/i', 			'/([\da-z_\.]+)(\!*)\(:([0-9a-z_]+)\)/i', 			'/(\!*)\[([^\]]+)\]/', 			'/(\!*)\/(:[^\/\^\$]+)\//', 			'/(\!*)\/([^\/]+)\//', 			'/%\^/', 			'/\$%/', 			'/(\!*)\{(:[^\}\^\$]+)\}/', 			'/(\!*)\{([^\}]+)\}/', 			'/\!(BETWEEN|IN|LIKE)\s/', 			'/(?<=^|[=<>\s\(])([a-z0-9_]+)\.([a-z0-9_]+)(?=[=<>\s\)]*|$)/i',			'/@binAND\s(:[a-zA-Z0-9\._]+)/','/@binOR\s(:[a-zA-Z0-9\._]+)/',
			],[
			' @binAND ',' @binOR ',			' AND ',' OR ',			' IS NOT NULL ', 			' IS NULL ', 			' ($1 $2BETWEEN $3 AND $4) ', 			' ($1 $2BETWEEN \'$3\' AND \'$4\') ', 			' ($1 $2BETWEEN :$3) ', 			' $1IN ($2) ', 			' $1LIKE $2 ', 			' $1LIKE \'%$2%\' ', 			'', 							'', 							'$1 REGEXP $2',			'$1 REGEXP \'$2\'',			'NOT $1 ',			'$1.`$2`',			'& b\'$1\'','| b\'$1\'',
			],$q);
		$sql = preg_replace_callback('/(?<k>\b[a-z0-9_`]+)(?<o>\s*[><=]\s*)(?<v>[^\s]+)(?=\s|$)/',function($m){
						if(preg_match('/^[\d\.]+$/',$m['v']) || str_starts($m['v'],':') || preg_match('/^[a-z\d`_]+\.[a-z\d`_]+$/i', $m['v'])){
			return $m['k'].$m['o'].$m['v']." ";
		}else {
			return $m['k'].$m['o']."'".$m['v']."' ";
		}
	}, $sql);
		$sql = preg_replace_callback('/(?<=IN\s)\((?<v>[^:][^\)]+)/',function($m){
        return "('".implode("','", explode(',', $m['v']))."'";
    },$sql);
	return $sql;
}
function ql_parse_f($t){
		$t = preg_replace_callback('/(?<=\{)([^\}]+)(?=\})/', function($m){
		return ql_parse_w($m[0]);
	}, $t);
	$sql = preg_replace([
		'/{/', 		'/([a-z_]+[\w\d_]*)\[([a-z_]+[\w\d_]*)\]/', 				'/>\(/',		'/<\(/',		'/\^\(/',		'/[\)\}]/'		],[
		' ON ',
		'`$1` $2',		' LEFT JOIN ',		' RIGHT JOIN ',		' INNER JOIN ',		''
		], $t);
	return $sql;
}
function ql_parse_s($t){
	$sql = preg_replace([
		'/([\.\(])([a-z_]+[\w\d_]*)\b([^\.])/i',
		'/\[([\w\d_]+)\]/i', 		],[
		'$1`$2`$3',
		' \'$1\'',
		], $t);
	return $sql;
}
function ql_parse_o($t){
	$sql = preg_replace([
		'/\!/'
		],[
		' DESC',
		], $t);
	return $sql;
}
function ql_parse_g($t){
	$sql = preg_replace([
				'/([a-z\d_]+)(?!\.)/i',
		],[
				'`$1`',
		], $t);
	return $sql;
}

spl_autoload_register(function($class){
	if(!include $class.'.inc')
		include $class.'.php';
});
try{
	if(php_sapi_name() == 'cli' || PHP_SAPI == 'cli'){
		$cli_args = array_slice($argv, 1);
		$cli_cmd = array_shift($cli_args);
		$cli_cmd="cli_".$cli_cmd;
		if(function_exists($cli_cmd)){$cli_cmd();}
	}else{
		REQ::dispatch();
	}
}catch(Exception $e){
	error_log($e->getMessage());
	exit;
}
function cli_script(){
	global $cli_args;
	$f = array_shift($cli_args);
	if(!empty($f)){
		$pwd=dirname(__FILE__);
		$f = $pwd."/scripts/$f.php";
		include $f;
	}
	exit;
}
function cli_migrate(){
	try{
		$pwd=dirname(__FILE__);
		$schemas = glob($pwd."/conf/schemas/*.ini") ;
		foreach ($schemas as $file){
			echo $file."\n";
			$parts = explode("/",$file);
			$file = $parts[count($parts)-1];
			$parts = explode(".", $file);
			$schema = $parts[0];
			echo $schema."\n";
			db_migrate($schema);
		}
		echo "DONE\n";
	}catch(Exception $e){
		echo "FAILED\n";
	}
	exit;
}

?>
