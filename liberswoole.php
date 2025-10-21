<?php

const __SLASH__ = DIRECTORY_SEPARATOR;
define('APP_DIR', dirname(__FILE__));
define('CONF_DIR', APP_DIR . __SLASH__ . 'conf' . __SLASH__);
define('IMAGE_DIR', APP_DIR . __SLASH__ . 'webroot' . __SLASH__ . 'images' . __SLASH__);
define('VIEW_DIR', APP_DIR . __SLASH__ . 'views' . __SLASH__);
define('TEMPLATE_DIR', APP_DIR . __SLASH__ . 'tmp' . __SLASH__);
define('CONTROLLER_DIR', APP_DIR . __SLASH__ . 'controllers' . __SLASH__);
define('MODULE_DIR', APP_DIR . __SLASH__ . 'modules' . __SLASH__);
define('LIB_DIR', APP_DIR . __SLASH__ . 'lib' . __SLASH__);
define('CERT_DIR', APP_DIR . __SLASH__ . 'certs' . __SLASH__);
define('DELEGATE_DIR', APP_DIR . __SLASH__ . 'delegates' . __SLASH__);
define('FILTER_DIR', APP_DIR . __SLASH__ . 'filters' . __SLASH__);
define('APP_NAME', end(explode('/', APP_DIR)));

require APP_DIR . '/vendor/autoload.php';
$mod = glob(MODULE_DIR . '/*.inc'); // modify swoole defined classes
foreach ($mod as $m)
    require $m;
$fix = glob(LIB_DIR . '/swoole/*.php'); // modify swoole defined classes
foreach ($fix as $f)
    require $f;

use OpenSwoole\Http\Server;
use OpenSwoole\Coroutine;
use OpenSwoole\Core\Coroutine\Client\PDOClientFactory;
use OpenSwoole\Core\Coroutine\Client\PDOConfig;
use OpenSwoole\Core\Coroutine\Client\RedisClientFactory;
use OpenSwoole\Core\Coroutine\Client\RedisClusterClientFactory;
use OpenSwoole\Core\Coroutine\Client\RedisConfig;
use OpenSwoole\Core\Coroutine\Client\RedisClusterConfig;
use OpenSwoole\Core\Coroutine\Pool\ClientPool;


$server = new Server('0.0.0.0', 8888,  OpenSwoole\Server::POOL_MODE);
$server->set([
    'backlog' => 128,
    'upload_tmp_dir' => '/tmp/',
    'http_parse_post' => true,
    'http_parse_cookie' => true,
    'http_parse_files' => true,
    'http_compression' => true,
    'http_compression_level' => 5,
    'enable_static_handler' => true,
    'document_root' => APP_DIR . '/webroot',
    'enable_static_handler' => true,
    'http_autoindex' => true,
    'static_handler_locations' => ['/js', '/css', '/images', 'fonts'],
]);


$mdirs = glob(APP_DIR . __SLASH__ . 'modules' . '/*', GLOB_ONLYDIR);
set_include_path(
    get_include_path() . PATH_SEPARATOR
        . implode(PATH_SEPARATOR, $mdirs) . PATH_SEPARATOR
        . DELEGATE_DIR . PATH_SEPARATOR
        . MODULE_DIR
);

require CONF_DIR . 'conf.inc';
class Consts extends Conf
{
    static $db_regexp_op = ['mysql' => 'REGEXP', 'postgres' => '~'];
    static $db_query_filters;
    static $arr_query_filters;
    static $query_filter_names = [
        'eq'     => '=',
        'ne'     => '!',
        'lt'     => '<',
        'gt'    => '>',
        'le'     => '<=',
        'ge'    => '>=',
        'in'    => '[]',
        'nin'     => '![]',
        'bt'     => '()',
        'nb'     => '!()',
        'l'     => '?',
        'nl'     => '!?',
        'm'     => '~',
        'nm'     => '!~',
        'mi'     => '~~',
        'nmi'     => '!~~'
    ];
    static $error_codes = [
        '200' => 'OK',
        '201' => 'Created',
        '202' => 'Accepted',
        '204' => 'No Content',
        '301' => 'Moved Permanently',
        '302' => 'Found',
        '400' => 'Bad Request',
        '401' => 'Unauthorized',
        '403' => 'Forbidden',
        '404' => 'Not Found',
        '413' => 'Request Entity Too Large',
        '414' => 'Request-URI Too Large',
        '415' => 'Unsupported Media Type',
        '419' => 'Authentication Timeout',
        '500' => 'Internal Server Error',
        '501' => 'Not Implemented'
    ];
}


abstract class Filter
{
    private function __construct()
    {
    }
    //CHANGE: 2406, to support traditional style
    // abstract public static function before(&$params, $req);
    // abstract public static function after(&$params, $req);
    abstract public function before(&$params, $req);
    abstract public function after(&$params, $req);
    public static function factory($type)
    {
        $classname = strtoupper($type[0]) . substr($type, 1) . 'Filter';
        if (class_exists($classname) || include_once FILTER_DIR . $type . '.inc') {
            return new $classname;
        } else {
            throw new Exception('filter not found');
        }
    }
}

abstract class Controller
{
    //CHANGES: 2406 - abstractなので、newできない
    // private function __construct()
    // {
    // }
    public static function factory($path)
    {
        $classname = preg_replace('/ /', '', ucwords(preg_replace('/[\/_-]/', ' ', $path))) . 'Controller';
        //CHANGES: 2406, to support traditional liber.php controllers
        // include_once CONTROLLER_DIR . $path . '.inc';
        include_once 'phpmod:/'.CONTROLLER_DIR . $path . '.inc'; //CHANGES: to rewrite the func names, to avoid duplicated err.
        preg_replace('/^_/','',str_replace('.inc','',str_replace('/','_',str_replace(CONTROLLER_DIR, '', $path))));
        if (!class_exists($classname, false)) $classname = "POController";
        return new $classname;
    }
}

//CHANGES: 2406
//PO: Procedure Oriented, to support traditional liber.php controllers
class POController extends Controller{
    public function __call($name, $arguments) {
        if (function_exists($name)) {
            return call_user_func_array($name, array_slice($arguments,1));
        }
        throw new Exception("Procedure Oriented function: {$name}() does not exist");
    }
}


class Session
{
    private $prefix = 'session:';
    private $ip;
    private $user_agent;
    private $response;
    private $sid;
    private $data;
    public static function factory($req)
    {
        $session = new Session();
        $header = $req->getHeader();
        $server = $req->getServer();
        $session->response = $req->getResponse();
        $session->ip = $server['remote_addr'];
        $session->user_agent = $header['user-agent'];
        $request = $req->getRequest();
        if ($request->cookie['sid'])
            $session->sid = $request->cookie['sid'];
        return $session;
    }
    private static function calc_sid($data)
    {
        $sid = md5($data['IP'] . '|' . $data['UA'] . "|" . $data['CSRF_NOUNCE']);
        return $sid;
    }
    private function generate()
    {
        $time = time();
        $key = md5(uniqid(rand(), true));
        $this->data = [
            'IP' => $this->ip,
            'UA' => $this->user_agent,
            'ISSUED_AT' => $time,
            'CSRF_NOUNCE' => $key
        ];
        $sid = $this->calc_sid($this->data);
        $this->sid = $sid;
        $exp = 86400 * 30;
        $this->set_cache($exp);
        $this->response->cookie('sid', $sid, $time + $exp, '/', false, false);
    }
    public function start()
    {
        $this->get_cache();
        if (empty($this->data))
            $this->generate();
    }
    public function regenerate()
    {
        $this->clear();
        $this->generate();
    }
    public function clear()
    {
        $this->del_cache();
        unset($this->sid);
        $this->response->cookie('sid', '', 1, '/');
    }
    public function get()
    {
        if (!$this->data)
            $this->get_cache();
        return $this->data;
    }
    public function set($data = false)
    {
        if ($data)
            $this->data = $data;
        $this->set_cache();
    }
    public function get_val($key)
    {
        if (empty($key)) return;//CHANGES
        if (!$this->data)
            $this->get_cache();
        return $this->data[$key];
    }
    public function set_val($key, $value, $immidiate = false)
    {
        if (empty($key)) return;//CHANGES
        if (!$this->data)
            $this->get_cache();
        $this->data[$key] = $value;
        if ($immidiate)
            $this->set_cache();
    }
    public function del_val($key, $immidiate = false)//CHANGES
    {
        if (empty($key)) return;
        if (!$this->data)
            $this->get_cache();
        unset($this->data[$key]);
        if ($immidiate)
            $this->set_cache();
    }
    public function flush(){ //save to redis
        $this-> set_cache(); 
    }
    private function get_cache()
    {
        if (!$this->sid) return null;
        $data = redis_get($this->prefix . $this->sid);
        if ($this->calc_sid($data) == $this->sid)
            $this->data = $data;
    }
    private function set_cache($exp = false)
    {
        $exp = $exp ?: ($this->data['ISSUED_AT'] + 86400 * 30 - time());
        redis_set($this->prefix . $this->sid, $this->data, $exp);
    }
    private function del_cache()
    {
        redis_del($this->prefix . $this->sid);
    }
}

class Renderer
{
    private $prefix = 'render:';
    private $var_prefix = 'LBR_';
    private $output_path;
    private $ext = '.html';
    private $layout = '_layout';
    private $data = [];
    private $contents = [];
    private $path;
    private $request;
    private $wrapper_code;
    private function __construct()
    {
    }
    public static function factory($req, $path = null)
    {
        $renderer = new Renderer();
        $renderer->request = $req;

        if ($path == null)
            $path = VIEW_DIR . $req->getClientType();
        $renderer->output_path = TEMPLATE_DIR;
        $renderer->path = $path . __SLASH__;
        return $renderer;
    }

    public function setRenderLayout($path)
    {
        $this->layout = $path;
    }

    public function assign($key, $value)
    {
        $this->data[$key] = $value;
    }

    private function render_cleanup()
    {
        unset($this->data);
        unset($this->wrapper_code);
    }

    public function render($file, $data = [])
    {
        $key = 'template-' . $this->request->getClientType() . "-" . preg_replace('/\//', '-', $this->layout);
        $path = $this->path;
        $fn = $path . $this->layout;
        if (!str_ends($fn, '.html')) $fn .= '.html';
        $this->wrapper_code = $fn && file_exists($fn) ? redis_get($this->prefix . $key, function () use ($fn) {
            return file_get_contents($fn);
        }) : "";
        if (!empty($data))
            foreach ($data as $k => $v)
                $this->data[$k] = $v;
        $this->data['__render'] = $this;
        if (!$this->data['__controller']) $this->data['__controller'] = $this->request->getController();
        if (!$this->data['__action']) $this->data['__action'] = $this->request->getAction();
        if (!$this->data['__params']) $this->data['__params'] = $this->request->getParams();
        $r = $this->render_file($file);
        $output = null;
        if ($r) {
            ob_start();
            extract($this->data, EXTR_PREFIX_ALL, $this->var_prefix);
            include($r);
            $output = ob_get_contents();
            ob_end_clean();
        }
        $this->render_cleanup();
        if ($output) return $output;
    }

    private function render_file($file)
    {
        $prefix  = 'template-' . $this->request->getClientType() . '-';
        $path = $this->path;
        $file = preg_replace('/^\//','',$file);
        $filepath = $path . $file;
        $outpath = $this->output_path . $prefix . str_replace($this->ext, '.php', str_replace('/', '--', $file));
        if (!file_exists($outpath) || Consts::$mode == "Developing") {
            $code = $this->compile($filepath);
            if (isset($code) && $code != "") {
                file_put_contents($outpath, $code);
                unset($code);
            }
        }
        return $outpath;
    }

    private function compile($file)
    {
        list($before, $after) = $this->wrapper_code ? explode('__CONTENTS__', $this->wrapper_code) : ["", ""];
        $src = $before . file_get_contents($file) . $after;
        $rows = preg_split('/(\{[^\{^\}]*\})/', $src, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $phpcode = '';
        $indent = 0;
        $ignore = false;
        $custom_tags = [];

        $tags_regexp = (!empty($custom_tags)) ?
            '(%|=|if|elseif|else|break|ignore|for|var|include|' . join('|', $custom_tags) . ')' :
            '(%|=|if|elseif|else|break|ignore|for|var|include)';
        while ($code = array_shift($rows)) {
            $matched = false;
            preg_match_all('/\{(?P<close>\/*)(?P<tag>' . $tags_regexp . '{0,1})\s*(?P<val>.*)\}/', $code, $matches);
            if (empty($matches[0])) {
                $phpcode .= $code;
            } else {
                list($close, $tag, $val) =  [$matches['close'][0] == "/" ? true : false, $matches['tag'][0], trim($matches['val'][0])];
                if ($tag == '' || $tag == '=') $tag = 'echo';
                if ($tag == '%') $tag = 'text';
                $val = $tag == "text" ? $val : preg_replace('/\.([a-zA-Z0-9_]+)/', "['$1']", $val);
                if (!preg_match('/\$(_GET|_POST|_REQUEST|_SESSION)/', $val))
                    $val = preg_replace('/\$/', '$' . $this->var_prefix . "_", $val);
                if ($close) {
                    if ($tag == 'if' || $tag == 'for') $indent--;
                    if ($tag == 'ignore') {
                        $ignore = false;
                    } else {
                        $phpcode .= '<?php } ?>';
                    }
                } else if ($ignore) {
                    $phpcode .= $code;
                } else {
                    switch ($tag) {
                        case 'for':
                            $parts = preg_split('/\s*,\s*/', $val, -1, PREG_SPLIT_NO_EMPTY);
                            $len = count($parts);
                            $indent++;
                            switch ($len) {
                                case 1:
                                    $phpcode .= '<?php foreach(' . $parts[0] . " as $" . $this->var_prefix . "_key=>$" . $this->var_prefix . "_value) { ?>";
                                    break;
                                case 2:
                                    $phpcode .= '<?php foreach(' . $parts[0] . " as $" . $this->var_prefix . "_key=>" . $parts[1] . ") { ?>";
                                    break;
                                default:
                                    if ((preg_match('/^\d+$/', $parts[1])) || (preg_match('/^\$/', $parts[1])) && (preg_match('/^\d+$/', $parts[2])) || (preg_match('/^\$/', $parts[2]))) {
                                        $phpcode .= '<?php for($' . $parts[0] . '=' . $parts[1] . ';$' . $parts[0] . '<' . $parts[2] . ';$' . $parts[0] . '++) { ?>';
                                    } else
                                        $phpcode .= '<?php foreach(' . $parts[0] . ' as ' . $parts[1] . '=>' . $parts[2] . ') { ?>';
                                    break;
                            }
                            break;
                        case 'if':
                            $indent++;
                            $phpcode .= '<?php if(' . $val . '){ ?>';
                            break;
                        case 'elseif':
                            $phpcode .= '<?php }else if(' . $val . '){ ?>';
                            break;
                        case 'else':
                            $phpcode .= '<?php }else{ ?>';
                            break;
                        case 'break':
                            $phpcode .= '<?php break; ?>';
                            break;
                        case 'echo':
                            $phpcode .= '<?= ' . $val . ' ?>';
                            break;
                        case 'text':
                            $vstr = preg_split('/,+/', trim($val));
                            if (count($vstr) > 1) {
                                $vstr = array_map(function ($e) {
                                    return trim($e);
                                }, $vstr);
                                $phpcode .= '<?= $this->request->T("' . join('","', $vstr) . '"); ?>';
                                break;
                            } else
                                $phpcode .= '<?= $this->request->T("' . $val . '"); ?>';
                            break;
                        case 'var':
                            $phpcode .= '<?php ' . $val . '; ?>';
                            break;
                        case 'ignore':
                            $ignore = true;
                            break;
                        default:
                            break;
                    }
                }
            }
        }
        return $phpcode;
    }
}

class REQ
{
    private $server;
    private $request;
    private $response;
    private $header;

    private $params = [];
    private $data = [];//CHANGES: to replace $_REQUEST[k]=v
    private $uri; 
    private $url;
    private $meta = [];//CHANGES: response headers, cos there is no header_remove() in swoole
    private $sse = false;//CHANGES: to support SSE(Server-side-events)

    private $user_agent;

    private $controller;
    private $method;
    private $action;

    private $client_type;
    private $client_bot;

    private $renderer;
    private $session;

    public function getHeader()
    {
        return $this->header;
    }
    public function getServer()
    {
        return $this->server;
    }
    public function getRequest()
    {
        return $this->request;
    }
    public function getResponse()
    {
        return $this->response;
    }
    public function getSession()
    {
        return $this->session;
    }
    public function getRenderer()
    {
        return $this->renderer;
    }
    public function getUri()//equivalent of $_SERVER['REQUEST_URI'];
    {
        return $this->uri;
    }
    public function getHost()//CHANGES: equivalent of $_SERVER['HTTP_HOST']
    {
        return $this->header['host'];
    }
    public function isHTTPS(){//CHANGES: equivalent of $_SERVER['HTTPS']
        $https = $this->header['x-forwarded-proto'] ?? '';
        return strtolower($https) == 'https';
    }
    public function isSSE($v=null){//CHANGES: to check/switch sse
        if($v!==null) $this->sse = $v;
        return $this->sse;
    }
    public function getParams()
    {
        return $this->params;
    }
    public function getData()
    {
        return $this->data;
    }
    public function getController()
    {
        return $this->controller;
    }
    public function getMethod()//equivalent of $_SERVER['REQUEST_METHOD'];
    {
        return $this->method;
    }
    public function getAction()
    {
        return $this->action;
    }
    public function getClientType()
    {
        return $this->client_type;
    }
    //CHANGES: equivalent to header(), if $v==false, it means remove. or its set.
    public function setMeta($k,$v,$immidiate=false){
        if(empty($k)) return;
        if($v===false) unset($this->meta[$k]);
        else $this->meta[$k]=$v;
    }
    //CHANGES: affect changes of response-headers and flush session
    public function flush(){
        foreach($this->meta as $k=>$v)
            $this->response->header($k, $v);
        if($this->session)
            $this->session->flush();
    }

    public function start($request, $response)
    {
        $this->header = $request->header;
        $this->server = $request->server;
        $this->request = $request;
        $this->response = $response;
        $this->method = strtolower($this->server['request_method']);
        if (property_exists($this->request, $this->method)) 
            $this->params = $this->request->{$this->method};
        else 
            parse_str($this->server['query_string']||'', $this->params);
        $this->params = $this->params ?: [];

        $this->uri = $this->server['request_uri'];
        $this->url = $this->server['request_uri'] . (!empty($this->server['query_string']) ? "?$this->server[query_string]" : '');

        $ua = $this->parse_user_agent();
        $this->client_type = $ua['type'];
        $this->client_bot = $ua['bot'];

        error_log('URI: ' . $this->uri);
        error_log('METHOD: ' . $this->server['request_method']);
        error_log('PARAMETERS: ' . json_encode($this->params));
        error_log('HTTP_REFERER: ' . $this->server['remote_addr']);

        $this->renderer = Renderer::factory($this);
        if(Conf::$session_enable){//CHANGES: 2406, to save performance for non-session projects
            $this->session = Session::factory($this);
            $this->session->start();
        }
        
        $this->process();
    }

    private function permission($ctrl, $act, $fn)
    {
        include_once DELEGATE_DIR . 'AuthDelegate.inc';
        //CHANGES: 2406, to support projects without delegates folder
        $group = class_exists("AuthDelegate") ? AuthDelegate::group() : 0;
        $permission = '';

        $tree = fs_src_tree($fn, false);
        $permissions = [];
        foreach ($tree['classes'][get_class($ctrl)]['methods'] as $fn => $ftr)
            $permissions[$fn] = $ftr['annotations']['permission'];

        $permission = isset($permissions[$act]) ? $permissions[$act] : 'F';
        $bits = isset($permission) && isset($permission[$group]) ? $permission[$group] : ($group == 0 ? '8' : 'F');

        if ($bits == '0') return $group == 0 ? 401 : 403;

        $bits = str_pad(base_convert($bits, 16, 2), 4, '0', STR_PAD_LEFT);
        $bitIdx = array_search($this->method, ['get', 'post', 'put', 'delete']);
        if ($bits[$bitIdx] != '1') return $group == 0 ? 401 : 403;
        return 200;
    }

    private function process()
    {
        $uri = preg_replace('/\/$/', '', preg_replace('/^\//', '', $this->uri));

        if (is_file(CONTROLLER_DIR . implode(__SLASH__, explode('/', $uri)) . '.inc')) {
            $cn = implode(__SLASH__, explode('/', $uri));
            $act = $this->method;
        } elseif (is_file(CONTROLLER_DIR . implode(__SLASH__, array_slice(explode('/', $uri), 0, -1)) . '.inc')) {
            $cn = implode(__SLASH__, array_slice(explode('/', $uri), 0, -1));
            $ps = explode('/', $uri);
            $act = array_pop($ps);
        } elseif (is_dir(CONTROLLER_DIR . implode(__SLASH__, explode('/', $uri)))) {
            $cn = implode(__SLASH__, explode('/', $uri)) . '/' . Conf::$default_controller;
            $act = $this->method;
        } elseif (is_dir(CONTROLLER_DIR . implode(__SLASH__, array_slice(explode('/', $uri), 0, -1)))) {
            $cn = implode(__SLASH__, array_slice(explode('/', $uri), 0, -1)) . '/' . Conf::$default_controller;
            $ps = explode('/', $uri);
            $act = array_pop($ps);
        } else {
            return $this->error(404);
        }

        $fn = preg_replace('/\/+/', "/", CONTROLLER_DIR . $cn . '.inc');
        $ctrl = Controller::factory($cn);

        //CHANGES:2406 to avoid duplicated func name problem
        if(property_exists('Conf', 'legacy'))
            $act = str_replace('/','_', preg_replace('/^\//', '',$cn))."_{$act}";
        $act = method_exists($ctrl, $act) && is_callable([$ctrl, $act]) ? $act : 
            //CHANGES:2406, magicファンクションの公式名称は__callです。
            //(method_exists($ctrl, '__magic') && is_callable([$ctrl, '__magic']) ? '__magic' : false)
            (method_exists($ctrl, '__call') && is_callable([$ctrl, '__call']) ? $act:false);//'__call' : false);
        if (!$act) return $this->error(404);
        $this->controller = $cn;
        $this->action = $act;

        $before = [];
        $after = [];

        foreach (Consts::$filters as $flt => $pt) {
            if ($pt == '*' || preg_match($pt, $this->uri)) {
                $flt = Filter::factory($flt);
                if (method_exists($flt, 'before') && is_callable([$flt, 'before'])) $before[] = $flt;
                if (method_exists($flt, 'after') && is_callable([$flt, 'after'])) array_unshift($after,$flt);//CHANGES:2406 afters are reversed
            }
        }

        $prev = $this->permission($ctrl, $act, $fn);
        if ($prev != 200)
            return $this->error($prev);
        foreach ($before as $bf)
            if ($bf->before($this->params, $this) === false)
                return $this->error(401);
        $ctrl->$act($act, $this->params);
        foreach ($after as $af)
            $af->after($this->params, $this);
    }

    public function is_https() {
        return !empty($this->header['x-forwarded-proto']) && strtoupper($this->header['x-forwarded-proto']) == "HTTPS";
    }    
    public function redirect($url) 
    {        
        if (strpos($url, '/') === 0) 
            $url = Conf::$server_host . $url;
        if (!str_starts_with($url, 'http:') || !str_starts_with($url, 'https:'))
            $url = ($this->is_https() ? 'https' : 'http') . '://' . $url;
        
        $this->response->redirect($url);
    }

    public function render_json($data)
    {
        $this->response->header('Content-type', 'application/json; charset=UTF-8');
        $this->response->write(json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    public function render_layout($file)
    {
        $this->renderer->setRenderLayout($file);
    }

    public function render_html($templateName = null, $datas = array())
    {
        $this->response->header('Content-type', 'text/html; charset=UTF-8');
        if (!$templateName)
            $templateName = property_exists('Conf', 'legacy')? $this->action . '.html' :  $this->controller . '_' . $this->action . '.html';

        if (!str_ends($templateName, '.html'))
            $templateName .= '.html';

        $this->renderer->assign('TITLE', APP_NAME);
        $output = $this->renderer->render($templateName, $datas);
        $this->response->write($output);
    }

    public function error($code, $contentType = '', $reason = '', $error_message = false)
    {
        if (!in_array($contentType, ['html', 'json', 'text']))
            $contentType = 'json';

        $msg = $error_message ?: Consts::$error_codes['' . $code];
        $this->response->status($code, $reason);

        switch ($contentType) {
            case 'json':
                $this->response->header('Content-type', 'application/json; charset=utf-8');
                $this->render_json(['error' => "$code $msg. $reason"]);
                break;
            case "html":
                $type = $this->client_type;
                $files = scandir(VIEW_DIR . __SLASH__ . $type);
                $hasHtml = in_array("error_$code.html", $files);
                $this->response->header('Content-type', 'text/html; charset=utf-8');
                if ($hasHtml)
                    $this->render_html("error_$code.html", ['code' => $code, 'msg' => $msg, 'reason' => $reason]);
                else
                    $this->response->write("<HTML><HEAD><link rel='stylesheet' href='/css/error.css' type='text/css'/><script href='/js/error.js'></script></HEAD><BODY><section><h1>$code ERROR</h1><p>$msg</p></section></BODY></HTML>");
                break;
            default:
                $this->response->header('Content-type', 'text/plain; charset=utf-8');
                $this->response->write("$code ERROR: $msg. $reason");
                break;
        }
    }

    private function parse_user_agent()
    {
        $ua = $this->request->header['user-agent'];

        $type = 'pc';
        if (preg_match('/(curl|wget|ApacheBench)\//i', $ua))
            $type = 'cmd';
        else if (preg_match('/(iPhone|iPod|(Android.*Mobile)|BlackBerry|IEMobile)/i', $ua))
            $type = 'sm';
        else if (preg_match('/(iPad|MSIE.*Touch|Android)/', $ua))
            $type = 'pad';

        if (preg_match('/Googlebot|bingbot|msnbot|Yahoo|Y\!J|Yeti|Baiduspider|BaiduMobaider|ichiro|hotpage\.fr|Feedfetcher|ia_archiver|Tumblr|Jeeves\/Teoma|BlogCrawler/i', $ua))
            $bot = 'bot';
        else if (preg_match('/Googlebot-Image|msnbot-media/i', $ua))
            $bot = 'ibot';
        else
            $bot = false;

        return ['type' => $type, 'bot' => $bot];
    }

    function user_lang()
    {
        return isset($this->params['@lang']) ? $this->params['@lang'] : (!empty($this->session->get_val('lang')) ? $this->session->get_val('lang') : (isset($this->header['accept-language']) ?
                    substr($this->header['accept-language'], 0, 2) : Consts::$lang));
    }
    public function T($key, $lang = false)
    {
        if (!$lang) {
            $lang = $this->user_lang();
        }
        $text_func = function ($fn) {
            $file = CONF_DIR . 'text.csv';
            if (file_exists($file)) {
                $lines = preg_split('/[\r\n]+/', file_get_contents($file));
                $idx = 0;
                $res = [];
                $langs = [];
                if (($handle = fopen($file, 'r')) !== FALSE) {
                    $max_len = 0;
                    $delimiter = ',';
                    try {
                        while (($cols = fgetcsv($handle, $max_len, $delimiter)) !== FALSE) {
                            if ($idx++ == 0) {
                                if ($cols[0] != 'id') {
                                    error_log('Language File Error: the first column of text.csv must have a name of "id" ');
                                    return [];
                                }
                                $langs = $cols;
                                array_shift($langs);
                                continue;
                            } else {
                                $c = 1;
                                $id = $cols[0];
                                $res[$id] = [];
                                foreach ($langs as $l)
                                    $res[$id][$l] = $cols[$c] ? $cols[$c++] : "";
                            }
                        }
                    } catch (Exception $e) {
                        error_log('Language File Error: ' . $e->getMessage());
                    }
                }
                fclose($handle);
                return $res;
            }
            return [];
        };
        $texts = Consts::$mode == 'Developing' ? $text_func('__TEXTS__') : redis_get('__TEXTS__', $text_func, false);
        $lang = isset($texts[$key][$lang]) ? $lang : (isset($texts[$key][Consts::$lang]) ? Consts::$lang : false);

        if ($lang) {
            $text = $texts[$key][$lang];
            if (str_has($text, '%')) {
                $args = array_slice(func_get_args(), 1);
                $enc = mb_detect_encoding($text);
                return $lang == 'en' ? vsprintf($text, $args) :
                    mb_convert_encoding(vsprintf(mb_convert_encoding($text, 'UTF-8', $enc), $args), $enc, 'UTF-8');
            } else
                return $text;
        } else {
            error_log("__ERR_WORD_NOT_EXISTS_($key,$lang)__, please check your /conf/text.csv");
            return "??";
            //return null;
        }
    }

    function check_params($p, $keys, $code=400, $msg='Parameter error')
    {
        $res = [];
        if(!empty($keys)){
            if(is_string($keys))$keys=explode(',',$keys);
            foreach($keys as $k){
                $k = trim($k);
                if(!isset($p[$k])){
                    $this->error($code,false,$msg);
                }
                $res[]=$p[$k];
            }
        }
        return $res;
    }
}

function keygen($len, $chars = false)
{
    if (!isset($len)) $len = 16;
    if (!$chars) $chars = 'abcdefghijklmnopqrstuvwxyz0123456789_.;,-$%()!@';
    $key = '';
    $clen = strlen($chars);
    for ($i = 0; $i < $len; $i++) {
        $key .= $chars[rand(0, $clen - 1)];
    }
    return $key;
}
function str_has($haystack, $needle)
{
    if (!is_string($haystack) || !is_string($needle)) return false;
    return strpos($haystack, $needle) !== false;
}
function str_starts($haystack, $needle){//CHANGES:2406, wrapper of old style
    return str_starts_with($haystack, $needle);
}
function str_ends($haystack, $needle, $case = true)
{
    if ($case) {
        return (strcmp(substr($haystack, strlen($haystack) - strlen($needle)), $needle) === 0);
    }
    return (strcasecmp(substr($haystack, strlen($haystack) - strlen($needle)), $needle) === 0);
}
function str_encode_hex($k, $salt = false)
{
    $cs = 'abcdefghijklmnopqrstuvwxyz0123456789';
    if ($salt) {
        $k = base64_encode($salt . $k);
    }
    $k  = keygen(3, $cs) . bin2hex("$k") . keygen(1, $cs);
    return $k;
}
function str_decode_hex($k, $salt = false)
{
    //$d=hex2str(substr($k,3,-1));
    $d = hex2bin(substr($k, 3, -1));
    if ($salt) {
        $d = base64_decode($d);
        if (str_starts_with($d, $salt)) {
            $d = substr_replace($d, '', 0, strlen($salt));
        }
    }
    return $d;
}
function str_fix_newlines($str, $new_v = "\n")
{
    if (empty($str) || !is_string($str)) return $str;
    $v = str_replace(array("\r\n", "\r", "\n"), $new_v, $str);
    return $v;
}
function check_decode_params($q, $keys, $k, $salt = 'anybot')
{
    if (empty($q) || empty($keys) || empty($k)) return false;
    $keys = is_string($keys) ? explode(",", $keys) : $keys;
    $str = str_decode_hex($k, $salt);
    if (empty($str)) return false;

    $ds = json_decode($str, true);
    if (!$ds) { // not json
        $ds = [];
        parse_str($str, $ds);
    }
    foreach ($keys as $k) {
        $k = trim($k);
        $v = $q[$k];
        $dv = $ds[$k];
        if ($v != $dv) return false;
    }
    return true;
}
function hex2str($hex)
{
    $string = '';
    for ($i = 0; $i < strlen($hex) - 1; $i += 2) {
        $string .= chr(hexdec($hex[$i] . $hex[$i + 1]));
    }
    return $string;
}
function str2hex($string)
{
    $hex = '';
    for ($i = 0; $i < strlen($string); $i++) {
        $hex .= dechex(ord($string[$i]));
    }
    return $hex;
}
function str2half($s)
{
    return mb_convert_kana($s, "as", 'UTF-8');
}
function wstr2num($s)
{
    $v = 0;
    $n = 0;
    $nmap = ['一' => 1, '壱' => 1, '壹' => 1, '弌' => 2, '二' => 2, '弐' => 2, '貳' => 2, '貮' => 2, '贰' => 2, '三' => 3, '参' => 3, '參' => 3, '弎' => 3, '叁' => 3, '叄' => 3, '四' => 4, '肆' => 4, '五' => 5, '伍' => 5, '六' => 6, '陸' => 6, '陸' => 6, '七' => 7, '漆' => 7, '柒' => 7, '八' => 8, '捌' => 8, '九' => 9, '玖' => 9];
    $bmap = ['十' => 10, '拾' => 10, '廿' => 20, '卄' => 20, '卅' => 30, '丗' => 30, '卌' => 40, '百' => 100, '陌' => 100, '千' => 1000, '阡' => 1000, '仟' => 1000];
    $b4map = ['万' => 10000, '萬' => 10000, '億' => 100000000, '兆' => 1000000000000];
    $s = str2half($s);
    $ns = "";
    $sl = mb_strlen($s);
    for ($x = 0; $x < $sl; $x++) {
        $c = mb_substr($s, $x, 1, 'UTF-8');
        if (preg_match('/[0-9]/', $c)) {
            $ns .= $c;
            $n = intval($ns);
        } else if (isset($nmap[$c])) {
            $n = $nmap[$c];
        } else if (isset($bmap[$c])) {
            $v += $n * $bmap[$c];
            $n = 0;
            $ns = "";
        } else if (isset($b4map[$c])) {
            if ($n > 0) $v += $n;
            $v *= $b4map[$c];
            $n = 0;
            $ns = "";
        }
    }
    if ($n > 0) $v += $n;
    return $v;
}
function is_email($str)
{
    return false !== filter_var($str, FILTER_VALIDATE_EMAIL);
}
function is_ip($str)
{
    return false !== filter_var($str, FILTER_VALIDATE_IP);
}
function is_url($str)
{
    return false !== filter_var($str, FILTER_VALIDATE_URL);
}
function is_hash($arr)
{
    return !empty($arr) && is_array($arr) && array_keys($arr) !== range(0, count($arr) - 1);
}
function is_json($str)
{
    return is_object(json_decode($str));
}
function is_kanji($s)
{
    return preg_match('/^\p{Han}+$/u', $s);
}
function is_katakana($s)
{
    $r = preg_match('/^\p{Katakana}+$/u', $s);
    if ($r) return $r;
    return preg_match('/^\p{Katakana}+$/u', trim(str_replace(' ', '', $s)));
}
function is_hirakana($s)
{
    return preg_match('/^\p{Hiragana}+$/u', $s);
}
function is_japanese_name($s)
{
    return preg_match('/^[\p{Hiragana}\p{Katakana}\p{Han}]+$/u', preg_replace('/[\s　]/u', '', $s));
}
function is_number($s)
{
    return is_string($s) && preg_match('/^[\d\.]+$/', $s);
}
function is_phone_jp($s)
{
    return preg_match('/^\d{2,4}[\-ー−]*\d{3,4}[\-ー−]*\d{3,4}$/', $s);
}
function is_zipcode_jp($s)
{
    return preg_match('/^\d{3}[\-ー−]*\d{4}$/', $s);
}
function is_len($s, $min, $max = false)
{
    $min = intval($min);
    $max = $max === false ? false : intval($max);
    $l = strlen($s);
    return ($max === false) ? $l >= $min : $l >= $min && $l <= $max;
}
function is_ymdhi($s)
{
    return preg_match('/[12]\d{3}[\-ー年\.−]*\d{1,2}[\-ー月\.−]*\d{1,2}\s+(午前|午後|am|pm)*\d{1,2}[時:]\d{1,2}/u', $s);
}
function is_ymd($s)
{
    return preg_match('/[12]\d{3}[\-ー年\.−]*\d{1,2}[\-ー月\.−]*\d{1,2}/u', $s);
}
function is_ym($s)
{
    return preg_match('/[12]\d{3}[\-ー年\.−]*\d{1,2}/u', $s);
}
function is_hi($s)
{
    return preg_match('/^(午前|午後|am|pm)*\d{1,2}[時:]\d{1,2}/u', $s);
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
function cartesian($input)
{
    $result = [[]];
    foreach ($input as $key => $values) {
        $append = [];
        $isAssoc = is_hash($values);
        foreach ($values as $k => $value) {
            foreach ($result as $data) {
                $d = $data + [$key => $isAssoc ? $k : $value];
                if ($isAssoc) $d['__SUM__'] += $value;
                $append[] = $d;
            }
        }
        $result = $append;
    }
    return $result;
}
//get yearweek 201920 (20th week of 2019) => [timestampStart,timestampEnd];
function yearweek_date($year, $week)
{
    $t1 = strtotime(date("Y-m-d 00:00:00", strtotime($year . "W" . $week . "1"))); // First day of week
    $t2 = strtotime(date("Y-m-d 23:59:59", strtotime($year . "W" . $week . "7"))); // Last day of week
    return [$t1, $t2];
}
//timestamp => 201905 (5th week of 2019)
function date_yearweek($time)
{
    return date('oW', is_number($time) ? $time : strtotime($time));
}
function hash_incr($data, $key, $amount)
{
    $v = hash_set($data, $key, true, 0);
    $v += $amount;
    return hash_set($data, $key, $v);
}
function hash_set(&$data, $keyPath, $val)
{
    $paths = explode('.', $keyPath);
    $o = &$data;
    $current_path = '';
    $path_size = count($paths);
    $key = $paths[0];
    $org = isset($data[$key]) ? $data[$key] : null;
    for ($i = 0; $i < $path_size; $i++) {
        $path = $paths[$i];
        if (is_string($o) && (str_starts_with($o, '{') || str_starts_with($o, '[')))
            $o = json_decode($o, true);
        if ($i == $path_size - 1) {
            $o[$path] = $val;
        } else {
            if (!isset($o[$path]))
                $o[$path] = [];
            $o = &$o[$path];
        }
    }
    return ['key' => $key, 'val' => $data[$key], 'org' => $org];
}
function hash_get(&$data, $keyPath, $autoCreate = true, $defaultValue = null)
{
    if (empty($data)) {
        if ($autoCreate) {
            hash_set($data, $keyPath, $defaultValue);
        } else
            return $defaultValue;
    }
    $paths = explode('.', $keyPath);
    $o = $data;
    $current_path = '';
    while (count($paths) > 1) {
        $path = array_shift($paths);
        if (is_string($o) && (str_starts_with($o, '{') || str_starts_with($o, '[')))
            $o = json_decode($o, true);
        if (!isset($o[$path])) {
            return $defaultValue;
        }
        $o = $o[$path];
    }
    if (is_string($o) && (str_starts_with($o, '{') || str_starts_with($o, '[')))
        $o = json_decode($o, true);
    $key = array_pop($paths);
    if (!isset($o[$key]))
        return $defaultValue;
    return $o[$key];
}
function hash_trim(&$e, $chars, $recursive)
{
    if (!is_hash($e)) return $e;
    $ks = array_keys($e);
    foreach ($ks as $k) {
        $v = $e[$k];
        if ($v === null || $v === 'null' || in_array($v, $chars))
            unset($e[$k]);
        if ($recursive) {
            if (is_string($v) && preg_match('/^\s*[\[\{]/', $v))
                $v = json_decode($v, true) ?: $v;
            if (is_array($v)) {
                $e[$k] = is_hash($v) ? hash_trim($v, $chars, $recursive) : (ds_trim($v, $chars, $recursive) ?: $v);
            }
        }
    }
    return $e;
}
function arr2hash($arr, $keyName, $valueName = null, $prefix = null)
{
    $hash = [];
    foreach ($arr as $e) {
        $hash[($prefix ? $prefix : '') . $e[$keyName]] = $valueName === null ? $e : $e[$valueName];
    }
    return $hash;
}
function ds_remove(&$arr, $conditions, $firstOnly = FALSE)
{
    if (!isset($conditions) || (is_array($conditions) && count($conditions) == 0))
        return $arr;
    $res = array();
    $found = false;
    foreach ($arr as $el) {
        $match = TRUE;
        if ($firstOnly && $found) {
            $match = FALSE;
        } else {
            if (is_hash($conditions)) {
                foreach ($conditions as $k => $v) {
                    if (!isset($el[$k]) || $el[$k] != $v) {
                        $match = FALSE;
                        break;
                    }
                }
            } else if (is_array($conditions)) {
                $match = in_array($el, $conditions);
            } else if (is_callable($conditions)) {
                $match = $conditions($el);
            } else {
                $match = ($el === $conditions);
            }
        }
        if (!$match) {
            $res[] = $el;
            $found = true;
        }
    }
    $arr = $res;
    return $res;
}
function ds_find($arr, $opts, $firstOnly = false)
{
    if (empty(Consts::$arr_query_filters))
        Consts::$arr_query_filters = [
            '='     => function ($o, $k, $v) {
                return $o[$k] === $v;
            },
            '!'     => function ($o, $k, $v) {
                return $o[$k] !== $v;
            },
            '<'     => function ($o, $k, $v) {
                return $o[$k] < $v;
            },
            '>'     => function ($o, $k, $v) {
                return $o[$k] > $v;
            },
            '<='     => function ($o, $k, $v) {
                return $o[$k] <= $v;
            },
            '>='     => function ($o, $k, $v) {
                return $o[$k] >= $v;
            },
            '[]'     => function ($o, $k, $v) {
                return is_array($v) && in_array($o[$k], $v);
            },
            '![]'     => function ($o, $k, $v) {
                return is_array($v) ? !in_array($o[$k], $v) : true;
            },
            '()'     => function ($o, $k, $v) {
                return is_array($v) && count($v) == 2 && $o[$k] >= min($v[0], $v[1]) && $o[$k] <= max($v[0], $v[1]);
            },
            '!()'     => function ($o, $k, $v) {
                return !is_array($v) || count($v) < 2 || $o[$k] < min($v[0], $v[1]) || $o[$k] > max($v[0], $v[1]);
            },
            '?'      => function ($o, $k, $v) {
                return !empty($o[$k]) && !empty($v) && str_has($o[$k], $v);
            },
            '!?'      => function ($o, $k, $v) {
                return empty($o[$k]) || !empty($v) || !str_has($o[$k], $v);
            },
            '~'     => function ($o, $k, $v) {
                return !empty($o[$k]) && !empty($v) && preg_match('/' . $v . '/', $o[$k]);
            },
            '!~'    => function ($o, $k, $v) {
                return empty($o[$k]) || !empty($v) || !preg_match('/' . $v . '/', $o[$k]);
            },
            '~~'     => function ($o, $k, $v) {
                return !empty($o[$k]) && !empty($v) && preg_match('/' . $v . '/i', $o[$k]);
            },
            '!~~'    => function ($o, $k, $v) {
                return empty($o[$k]) || !empty($v) || !preg_match('/' . $v . '/i', $o[$k]);
            },
        ];
    if (empty($opts)) return false;
    $res = [];
    foreach ($arr as $a) {
        $match = true;
        foreach ($opts as $k => $v) {
            $cmd = strstr($k, '@');
            $cmd = !$cmd ? "=" : substr($k, $cmd);
            $func = Consts::$arr_query_filters[$cmd];
            if ($func && !$func($a, $k, $v)) {
                $match = false;
                break;
            }
        }
        if ($match) {
            if ($firstOnly) return $a;
            $res[] = $a;
        }
    }
    return $res;
}
function ds_sort($arr, $sortKey = null, $sortOrder = 1, $comparator = null)
{
    if (isset($sortKey)) {
        if ($comparator == null) {
            $cfmt = '';
            $cmp = function ($a, $b) use ($sortKey, $sortOrder) {
                $av = $a[$sortKey];
                if (!isset($av)) $av = 0;
                $bv = $b[$sortKey];
                if (!isset($bv)) $bv = 0;
                if ($av == $bv) {
                    return 0;
                }

                return is_string($av) ? strcmp($av, $bv) * $sortOrder : (($av > $bv) ? -1 * $sortOrder : 1 * $sortOrder);
            };
            usort($arr, $cmp);
        } else
            usort($arr, $comparator);
        return $arr;
    } else {
        asort($arr);
        return $arr;
    }
}
function ds_trim(&$arr, $chars = [], $recursive = false)
{
    if (!is_array($arr)) return $arr;
    $iso = is_hash($arr);
    if ($iso) hash_trim($arr, $chars, $recursive);
    else
        foreach ($arr as &$e) {
            hash_trim($e, $chars, $recursive);
        }
    return $arr;
}
function ms()
{
    list($usec, $sec) = explode(' ', microtime());
    return ((int)((float)$usec * 1000) + (int)$sec * 1000);
}
function fs_put_ini($file, array $options)
{
    $tmp = '';
    foreach ($options as $section => $values) {
        $tmp .= "[$section]\n";
        foreach ($values as $key => $val) {
            if (is_array($val)) {
                foreach ($val as $k => $v)
                    $tmp .= "{$key}[$k] = \'$v\'\n";
            } else
                $tmp .= "$key = \'$val\'\n";
        }
        $tmp .= '\n';
    }
    file_put_contents($file, $tmp);
    unset($tmp);
}
function fs_archived_path($id, $tokenLength = 1000)
{
    $arch =  (int)$id % (int)$tokenLength;
    return "$arch/$id";
}
function fs_mkdir($out)
{
    $folder = (str_has($out, '.')) ? preg_replace('/[^\/]*\.[^\/]*$/', '', $out) : $out;
    if (!file_exists($folder))
        mkdir($folder, 0775, TRUE);
}
function fs_xml2arr($xmlString)
{
    return json_decode(json_encode((array)simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NOCDATA)), TRUE);
}
function fs_annotations($comm)
{
    $comm = explode("\n", preg_replace(['/\/\*+\s*/m', '/\s*\*+\/\s*/m'], '', $comm));
    $anno = [];
    $rows = count($comm);
    $tag = null;
    $value = [];
    $attr = null;
    for ($i = 0; $i <= $rows; $i++) {
        $cm = trim(preg_replace('/^[\s\*]*/', '', $i < $rows ? $comm[$i] : ''));
        preg_match_all('/^@(?P<tag>[a-zA-Z]+)\s*(?P<attr>[^:^=]*)\s*[:=]*\s*(?P<value>.*)/i', $cm, $matches);
        if (!empty($matches['tag']) || $i == $rows) {
            if (empty($tag)) $tag = 'desc';
            if (empty($anno[$tag]))
                $anno[$tag] = [];
            $anno[$tag][] = ['value' => join("\n", $value), 'attr' => $attr];
            $tag = null;
            $value = [];
            $attr = null;
        }
        if (!empty($matches['tag'])) {
            $tag = trim(strtolower($matches['tag'][0]));
            $value[] = preg_replace('/^[:\s]*/', '', trim($matches['value'][0]));
            $attr = preg_replace('/^[:\s]*/', '', $matches['attr'][0]);
        } else if (!empty($cm)) {
            $value[] = $cm;
        }
    }
    foreach ($anno as $key => $vs) {
        if (count($vs) == 1) $anno[$key] = $vs[0]['value'];
    }
    return $anno;
}
function fs_src_tree($phpfile, $reload=true)
{
    if(property_exists('Consts', 'legacy'))
        $phpfile = "phpmod:/".$phpfile;
    if($$reload)    
        require_once $phpfile;
    $src = file_get_contents($phpfile);
    preg_match_all('/<\?php\s*\/\*+\s*(?P<comment>.*?)\*\/\s*/sm', $src, $fdef);
    $comment = $fdef['comment'][0];
    preg_match_all('/^(abstract)*\s*(class|trait)\s+(?P<cls>[\w\d]+)\s*/mi', $src, $ma);
    $classes = [];
    if (!empty($ma['cls'])) {
        foreach ($ma['cls'] as $cls) {
            $classes[$cls] = [];
            $cr = new ReflectionClass($cls);
            $classes[$cls]['name'] = $cls;
            $parent = $cr->getParentClass();
            if ($parent) $classes[$cls]['parent'] = $parent->getName();
            $classes[$cls]['interfaces'] = $cr->getInterfaceNames();
            $classes[$cls]['abstract'] = $cr->isAbstract();
            $classes[$cls]['trait'] = $cr->isTrait();
            $comm = $cr->getDocComment();
            if ($comm == $comment) $comment = '';
            $classes[$cls]['annotations'] = fs_annotations($comm);
            $methods = $cr->getMethods();
            foreach ($methods as $mr) {
                $args = array_map(function ($e) {
                    return $e->name;
                }, $mr->getParameters());
                $anno = fs_annotations($mr->getDocComment());
                $classes[$cls]['methods'][$mr->getName()] = [
                    'name'    => $mr->getName(),
                    'classname' => $cls,
                    'annotations' => $anno, 'params' => $args,
                    'abstract' => $mr->isAbstract(),
                    'constructor' => $mr->isConstructor(),
                    'destructor' => $mr->isDestructor(),
                    'final' => $mr->isFinal(),
                    'visibility' => $mr->isPrivate() ? 'private' : ($mr->isProtected() ? 'protected' : 'public'),
                    'static' => $mr->isStatic()
                ];
            }
            $props = $cr->getProperties();
            foreach ($props as $pr) {
                $classes[$cls]['properties'][$pr->getName()] = [
                    'visibility' => $pr->isPrivate() ? 'private' : ($pr->isProtected() ? 'protected' : 'public'),
                    'static' => $pr->isStatic()
                ];
            }
        }
    }
    preg_match_all('/^function\s+(?P<func>[\w\d_]+)\s*\(/mi', $src, $ma);
    $funcs = [];
    if (!empty($ma['func'])) {
        foreach ($ma['func'] as $fn) {
            $ref = new ReflectionFunction($fn);
            $args = array_map(function ($e) {
                return $e->name;
            }, $ref->getParameters());
            $comm = $ref->getDocComment();
            if ($comm == $comment) $comment = '';
            $anno = fs_annotations($comm);
            $funcs[$fn] = ['annotations' => $anno, 'params' => $args, 'name' => $fn];
        }
    }
    return ['annotations' => empty($comment) ? [] : fs_annotations($comment), 'functions' => $funcs, 'classes' => $classes];
}
function elog($o, $label = '')
{
    $trace = debug_backtrace();
    // $m = strlen($trace[1]['class']) ? $trace[1]['class'] . "::" : "";
    // $m .= $trace[1]['function'];
    $m = is_array($trace[1]['class'])?$trace[1]['class']:[$trace[1]['class']];
    $m[] = $trace[1]['function'];
    $m = implode('::', $m);
    $ws = is_array($o) ? "\n" : (is_string($o)&&strlen($o) >= 10 ? "\n" : "");
    $ostr = is_object($o) && !is_array($o) ? "{CLASS}" : (is_array($o) ? json_encode($o, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $o);
    $s=$m . " #" . $trace[0]['line'] . " $label=$ws".  $ostr . "\n";
    if (property_exists('Conf', 'log_file') && Conf::$log_file)
        file_put_contents(Conf::$log_file, date('[m/d H:i:s]') . ':' . $s, FILE_APPEND);
    else
        error_log($s);

}
function comp($a, $b, $cmp = 'eq', $k = false)
{
    $v1 = is_hash($a) && $k ? $a[$k] : $a;
    $v2 = is_hash($b) && $k ? $b[$k] : $b;
    switch ($cmp) {
        case 'eq':
            return $v1 == $v2;
        case 'ne':
            return $v1 != $v2;
        case 'le':
            return $v1 <= $v2;
        case 'lt':
            return $v1 < $v2;
        case 'ge':
            return $v1 >= $v2;
        case 'gt':
            return $v1 > $v2;
    }
}
function arr_filter($arr, $v, $k = false, $comp = 'eq', &$i = false)
{
    $res = [];
    $x = 0;
    if ($arr) {
        foreach ($arr as $e) {
            if (($k === false && comp($e, $v, $comp)) || ($k && comp($e[$k], $v, $comp))) {
                $res[] = $e;
                if ($i === false) $i = $x;
            }
            $x++;
        }
    }
    return $res;
}
function arr_intersect($a1, $a2)
{
    $r = [];
    foreach ($a1 as $e) {
        if (in_array($e, $a2)) $r[] = $e;
    }
    return $r;
}
function arr_diff($a, $base)
{
    $r = [];
    foreach ($a as $e) {
        if (!in_array($e, $base)) $r[] = $e;
    }
    return $r;
}

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
function redis_sentinel_masters()
{
    $redis_sentinel = Consts::$redis_sentinel;
    $sentinel_port = Consts::$sentinel_port ?: '26379';

    $redis = new Redis();
    $redis->connect($redis_sentinel, $sentinel_port);
    $masters = $redis->rawCommand('SENTINEL', 'masters');
    $masters = parse_array($masters);

    $sentinel = new RedisSentinel($redis_sentinel, $sentinel_port);
    $masters = $sentinel->masters();

    $servers = [];
    foreach ($masters as $master) {
        if (preg_match('/^master/', $master['name'])) {
            $server = $master['ip'];
            $port = $master['port'];
            $servers[] = $server . ':' . $port;
        }
    }
    return $servers;
}
function redis_config($host, $auth = null, $pref = APP_NAME)
{
    list($host, $port) = explode(':', $host);
    $port = $port ?: Consts::$redis_port;
    $config = (new RedisConfig())
        ->withHost($host)
        ->withPort($port)
        ->withAuth($auth);
        // ->withOptions([Redis::OPT_PREFIX => $pref . ':']);
    return $config;
}
function redis_cluster_config($hosts, $auth = null, $pref = APP_NAME)
{
    $hosts = array_map(function ($h) {
        list($host, $port) = explode(':', $h);
        $port = $port ?: Consts::$redis_port;
        return "$host:$port";
    }, $hosts);
    $config = (new RedisClusterConfig())
        ->withHosts($hosts)
        ->withAuth($auth)
        ->withTimeout(2)
        ->withReadTimeout(1.5)
        ->withOptions([Redis::OPT_PREFIX => $pref . ':']);
    return $config;
}
function redis_pool($hosts = null, $pass = false, $pref = APP_NAME, $size = 8)
{
    if (!$hosts && Consts::$redis_sentinel)
        $hosts = redis_sentinel_masters();
    if (($hosts && count($hosts) > 1) || (!$hosts && count(Consts::$redis_servers) > 1)) {
        $config = redis_cluster_config(($hosts ?: Consts::$redis_servers), $pass ?: Consts::$redis_pass, $pref);
        $pool = new ClientPool(RedisClusterClientFactory::class, $config, $size);
    } elseif ($hosts || (!$hosts && Consts::$redis_servers)) {
        $config = redis_config(($hosts ?: Consts::$redis_servers)[0], $pass ?: Consts::$redis_pass, $pref);
        $pool = new ClientPool(RedisClientFactory::class, $config, $size);
    }
    $pool->fill();
    return $pool;
}
function rdis_conn($hosts = null, $pass = false, $pref = APP_NAME)
{
    $config = redis_config(($hosts ?: Consts::$redis_servers)[0], $pass ?: Consts::$redis_pass, $pref);
    return RedisClientFactory::make($config);
}
function redis_conn($hosts = null, $pass = false, $pref = APP_NAME)
{
    return $hosts == null && $pass == null && $pref == APP_NAME ? Pool::getRedisClient() : rdis_conn($hosts, $pass, $pref);
}
function redis_close($client, $hosts = null, $pass = false, $pref = APP_NAME)
{
    return $hosts == null && $pass == null && $pref == APP_NAME ? Pool::putRedisClient($client) : $client->close();
}
function redis_get($key, $nullHandler = null, $hosts = null)
{
    $conn = redis_conn($hosts);
    $k = $key;
    if ($conn) {
        $v = $conn->get($k);
        if (!$v && isset($nullHandler)) {
            $r = $nullHandler($key);
            if (isset($r) && $r != false)
                redis_set($key, $r);
        } else if (is_string($v) && preg_match('/^\s*[\[\{]/', $v)) {
            $r = json_decode($v, true);
        } else {
            $r = $v;
        }
    }
    redis_close($conn, $hosts);
    return $r;
}
function redis_set($key, $value, $time = 3600, $hosts = null)
{
    $k = $key;
    $conn = redis_conn($hosts);
    if (!$conn) return false;
    if ($time > 0)
        $conn->setEx($k, $time, is_array($value) ? json_encode($value) : $value);
    else
        $conn->set($k, is_array($value) ? json_encode($value) : $value);
    redis_close($conn, $hosts);
}
function redis_del($key, $hosts = null)
{
    $k = $key;
    $conn = redis_conn($hosts);
    $r = ($conn) ? $conn->del($k) : false;
    redis_close($conn, $hosts);
    return $r;
}
function redis_unlink($key_or_keys, $hosts = null)
{
    if (empty($key_or_keys)) return;
    $conn = redis_conn($hosts);
    if (method_exists($conn, 'unlink')) {
        $r = ($conn) ? $conn->unlink($key_or_keys) : false;;
    } else {
        $r = redis_del($key_or_keys, $hosts);
    }
    redis_close($conn, $hosts);
    return $r;
}

class Pool
{
    private static $db;
    private static $redis;

    public static function getDBClient($timeout = 1.5)
    {
        return Pool::$db->get($timeout);
    }
    public static function putDBClient($client)
    {
        return Pool::$db->put($client);
    }
    public static function getRedisClient($timeout = 2.5)
    {
        return Pool::$redis->get($timeout);
    }
    public static function putRedisClient($client)
    {
        return Pool::$redis->put($client);
    }
    public static function init()
    {
        if (Pool::$db) return;
        Pool::$db = pdo_pool();
        Pool::$redis = redis_pool();
    }
    public static function cleanup()
    {
        if (Pool::$db)
            Pool::$db->close();
        if (Pool::$redis)
            Pool::$redis->close();
    }
}
function db_escape($v)
{
    return str_replace(["\\",  "\x00", "\n",  "\r",  "'",  '"', "\x1a"], ["\\\\", "\\0", "\\n", "\\r", "\'", '\"', "\\Z"], $v);
}
function db_v($v, $typeDef = '', $bsonText = false)
{
    $tp = explode(" ", $typeDef)[0];
    if (!isset($v))
        return 'NULL';
    if (is_bool($v))
        return $v ? 1 : 0;
    if (is_array($v)) {
        return $bsonText && (isset($tp) && preg_match('/text/i', $tp)) ? '\'' . bson_enc($v) . '\''
            : '\'' . db_escape(json_encode($v)) . '\'';
    }
    if (is_string($v)) {
        if (preg_match('/bigint/i', $tp) && str_has($v, '-'))
            return strtotime($v);
        if (preg_match('/(int|byte)/i', $tp))
            return intval($v);
        return "'" . db_escape($v) . "'";
    }
    return $v;
}
function db_make_filters($k, $k_operator, $v, $v_operator, &$o, $func_make)
{
    $keys = is_array($k) ? $k : preg_split('/\|/', $k);
    $values = is_array($v) ? $v : preg_split('/\|/', $v);
    $conditions = [];
    $idx = 0;
    foreach ($keys as $_k) {
        $sub_cond = [];
        foreach ($values as $_v) {
            $sql = $func_make($_k, $_v, $o, $idx);
            if ($sql) $sub_cond[] = $sql;
            $idx++;
        }
        if (!empty($sub_cond))
            $conditions[] = count($values) > 1 ? '(' . join(' ' . $v_operator . ' ', $sub_cond) . ')' : join(' ' . $v_operator . ' ', $sub_cond);
    }
    if (count($conditions) <= 0) return false;
    return count($conditions) > 1 ? '(' . join(' ' . $k_operator . ' ', $conditions) . ')' : join(' ' . $k_operator . ' ', $conditions);
}
function db_init_filters()
{
    if (empty(Consts::$db_query_filters))
        Consts::$db_query_filters = [
            '='     => function ($k, $v, &$o) {
                return db_make_filters($k, 'or', $v, 'or', $o, function ($k, $v, &$o, $idx) {
                    if ($v === 'NULL') return '`' . $k . '` IS NULL';
                    else {
                        $_k = $k . '_' . $idx;
                        $o[$_k] = $v;
                        return '`' . $k . '`=:' . $_k;
                    }
                });
            },
            '!'     => function ($k, $v, &$o) {
                return db_make_filters($k, 'and', $v, 'and', $o, function ($k, $v, &$o, $idx) {
                    if ($v === 'NULL') return '`' . $k . '` IS NOT NULL';
                    else {
                        $_k = $k . '_' . $idx;
                        $o[$_k] = $v;
                        return '`' . $k . '`!=:' . $_k . '';
                    }
                });
            },
            '<'     => function ($k, $v, &$o) {
                return db_make_filters($k, 'and', $v, 'and', $o, function ($k, $v, &$o, $idx) {
                    $_k = $k . '_' . $idx;
                    $o[$_k] = $v;
                    return '`' . $k . '`<:' . $_k . '';
                });
            },
            '>'     => function ($k, $v, &$o) {
                return db_make_filters($k, 'and', $v, 'and', $o, function ($k, $v, &$o, $idx) {
                    $_k = $k . '_' . $idx;
                    $o[$_k] = $v;
                    return '`' . $k . '`>:' . $_k . '';
                });
            },
            '<='     => function ($k, $v, &$o) {
                return db_make_filters($k, 'and', $v, 'and', $o, function ($k, $v, &$o, $idx) {
                    $_k = $k . '_' . $idx;
                    $o[$_k] = $v;
                    return '`' . $k . '`<=:' . $_k . '';
                });
            },
            '>='     => function ($k, $v, &$o) {
                return db_make_filters($k, 'and', $v, 'and', $o, function ($k, $v, &$o, $idx) {
                    $_k = $k . '_' . $idx;
                    $o[$_k] = $v;
                    return '`' . $k . '`>=:' . $_k . '';
                });
            },
            '[]'     => function ($k, $v, &$o) {
                return db_make_filters($k, 'and', $v, 'and', $o, function ($k, $v, &$o, $idx) {
                    if (is_string($v)) $v = explode(',', $v);
                    if (count($v) == 0) return false;
                    $vs = array_map(function ($e) {
                        return db_v($e);
                    }, $v);
                    return '`' . $k . '` IN (' . join(',', $vs) . ')';
                });
            },
            '![]'     => function ($k, $v, &$o) {
                return db_make_filters($k, 'and', $v, 'and', $o, function ($k, $v, &$o, $idx) {
                    if (is_string($v)) $v = explode(',', $v);
                    if (count($v) == 0) return false;
                    $vs = array_map(function ($e) {
                        return db_v($e);
                    }, $v);
                    return '`' . $k . '` NOT IN (' . join(',', $vs) . ')';
                });
            },
            '()'     => function ($k, $v, &$o) {
                return db_make_filters($k, 'and', $v, 'and', $o, function ($k, $v, &$o, $idx) {
                    if (is_string($v)) $v = explode(',', $v);
                    if (count($v) != 2) return false;
                    return '(`' . $k . "` BETWEEN '" . min($v[0], $v[1]) . "' AND '" . max($v[0], $v[1]) . "')";
                });
            },
            '!()'     => function ($k, $v, &$o) {
                return db_make_filters($k, 'and', $v, 'and', $o, function ($k, $v, &$o, $idx) {
                    if (is_string($v)) $v = explode(',', $v);
                    if (count($v) != 2) return false;
                    return '(`' . $k . "` NOT BETWEEN '" . min($v[0], $v[1]) . "' AND '" . max($v[0], $v[1]) . "')";
                });
            },
            '?'      => function ($k, $v, &$o) {
                // bugfixed: 「'」=>「\'」
                // if(preg_match_all('/\'/uim', $v,$m1) && !preg_match_all('/\\\\\'/uim', $v,$m2)) $v= preg_replace('/\'/uim', '\\\'',$v);
                return db_make_filters($k, 'or', $v, 'or', $o, function ($k, $v, &$o, $idx) {
                    // if(!str_has($v,'%'))$v='%'.$v.'%';return '`'.$k.'` LIKE \''.preg_replace('/[\+\s]+/','%',$v).'\'';
                    $_k = '__l_' . $k . '_' . $idx;
                    if (!str_has($v, '%')) $v = '%' . $v . '%';
                    $o[$_k] = $v;
                    return '`' . $k . '` LIKE :' . $_k;
                });
            },
            '!?'      => function ($k, $v, &$o) {
                // bugfixed: 「'」=>「\'」
                // if(preg_match_all('/\'/uim', $v,$m1) && !preg_match_all('/\\\\\'/uim', $v,$m2)) $v= preg_replace('/\'/uim', '\\\'',$v);
                return db_make_filters($k, 'and', $v, 'and', $o, function ($k, $v, &$o, $idx) {
                    // if(!str_has($v,'%'))$v='%'.$v.'%';return '`'.$k.'` NOT LIKE \''.preg_replace('/[\+\s]+/','%',$v).'\'';
                    $_k = '__nl_' . $k . '_' . $idx;
                    if (!str_has($v, '%')) $v = '%' . $v . '%';
                    $o[$_k] = $v;
                    return '`' . $k . '` NOT LIKE :' . $_k;
                });
            },
            '~'     => function ($k, $v, &$o) {
                return db_make_filters($k, 'or', $v, 'or', $o, function ($k, $v, &$o, $idx) {
                    $op = Consts::$db_regexp_op[Consts::$db_engine];
                    if (!isset($op)) return false;
                    return '`' . $k . '` ' . $op . ' \'' . db_escape(preg_replace('/^\/|\/$/', '', $v)) . '\'';
                });
            },
            '!~'    => function ($k, $v, &$o) {
                return db_make_filters($k, 'or', $v, 'or', $o, function ($k, $v, &$o, $idx) {
                    $op = Consts::$db_regexp_op[Consts::$db_engine];
                    if (!isset($op)) return false;
                    return '`' . $k . '` NOT ' . $op . ' \'' . db_escape(preg_replace('/^\/|\/$/', '', $v)) . '\'';
                });
            },
            '~~'    => function ($k, $v, &$o) {
                return db_make_filters($k, 'or', $v, 'or', $o, function ($k, $v, &$o, $idx) {
                    $op = Consts::$db_regexp_op[Consts::$db_engine];
                    if (!isset($op)) return false;
                    return 'LOWER(`' . $k . '`) ' . $op . ' \'' . db_escape(preg_replace('/^\/|\/$/', '', $v)) . '\'';
                });
            },
            '!~~'    => function ($k, $v, &$o) {
                return db_make_filters($k, 'or', $v, 'or', $o, function ($k, $v, &$o, $idx) {
                    $op = Consts::$db_regexp_op[Consts::$db_engine];
                    if (!isset($op)) return false;
                    return 'LOWER(`' . $k . '`) NOT ' . $op . ' \'' . db_escape(preg_replace('/^\/|\/$/', '', $v)) . '\'';
                });
            },
            '{}'     => function ($k, $v, &$o) {
                return db_make_filters($k, 'or', $v, 'or', $o, function ($k, $v, &$o, $idx) {
                    if (is_string($v) || is_number(($v))) {
                        $v = strval($v);
                        $_k = $k . '_' . $idx;
                        $o[$_k] = $v;
                        return 'JSON_CONTAINS(`' . $k . '`,:' . $_k . ')';
                    }
                });
            },
            '!{}'     => function ($k, $v, &$o) {
                return db_make_filters($k, 'or', $v, 'or', $o, function ($k, $v, &$o, $idx) {
                    if (is_string($v) || is_number(($v))) {
                        $v = strval($v);
                        $_k = $k . '_' . $idx;
                        $o[$_k] = $v;
                        return 'NOT JSON_CONTAINS(`' . $k . '`,:' . $_k . ')';
                    }
                });
            },
        ];
}
function db_limit($limit, $default = "")
{
    $default = intval($default) ?: 20;
    if (isset($limit)) {
        list($start, $pagesize) = explode(",", $limit);

        $start = intval($start);
        $pagesize = intval($pagesize);

        if (!$pagesize) {
            $pagesize = $start;
            $start = 0;
        }
        $limit = "$start,$pagesize";
    } else {
        $limit = "0,$default";
    }
    return $limit;
}
function db_make_query(&$table, $opts = [], $omit = [], $colPrefix = false, $schemaDef = false)
{
    db_init_filters();
    if (!isset($table)) return false;
    list($table, $schemaname) = explode('@', $table);
    if (empty($schemaname)) $schemaname = $table;
    $colStr = '*';
    if (!empty($schemaDef)) {
        $schemaDef = is_string($schemaDef) ? json_decode($schemaDef, true) : $schemaDef;
    } else {
        $schemaDef = db_schema($schemaname);
    }
    $pk = $schemaDef['general']['pk'];
    $schema = $schemaDef['schema'];
    $connect = $schemaDef['connect'];
    $connNames = !empty($connect) ? array_keys($connect) : [];
    if ($colPrefix) $colPrefix .= ".";
    $data = [];
    $conns = [];
    if (
        is_hash($opts) && !empty($opts['fields']) &&
        (preg_match('/[\{\}\.]+/', $opts['fields'])) || (!empty($connNames) && preg_match('/\b(' . join('|', $connNames) . ')\b/', $opts['fields']))
    ) {
        preg_match_all('/\b(?P<tbl>[\w\d_]+)\{(?P<cols>[^\}]+)\}/', $opts['fields'], $ma);
        if (!empty($ma['tbl'])) {
            $i = 0;
            foreach ($ma['tbl'] as $tbl) {
                if (!isset($connect[$tbl])) continue;
                if (!isset($conns[$tbl])) $conns[$tbl] = ['fields' => [$connect[$tbl]['target_column']]] + $connect[$tbl];
                $conns[$tbl]['fields'] = array_merge($conns[$tbl]['fields'], explode(',', $ma['cols'][$i++]));
            }
            $opts['fields'] = preg_replace(['/\b(?P<tbl>[\w\d_]+)\{(?P<cols>[^\}]+)\}/', '/^,/', '/,$/'], '', $opts['fields']);
        }
        $cols =  explode(',', $opts['fields']);
        $ncols = [];
        foreach ($cols as $f) {
            $f = trim($f);
            if (in_array($f, $connNames)) {
                $conns[$f] = ['fields' => '*'] + $connect[$f];
            } else if (str_has($f, '.')) {
                list($tbl, $col) = explode('.', $f);
                if (in_array($tbl, $connNames)) {
                    if (!isset($conns[$tbl])) $conns[$tbl] = ['fields' => [$connect[$tbl]['target_column']]] + $connect[$tbl];
                    $conns[$tbl]['fields'][] = $col;
                }
            } else {
                if ($f == '*' || array_key_exists($f, $schema))
                    $ncols[] = $f;
            }
        }
        $connFields = array_keys($conns);
        foreach ($connFields as $cf) {
            if ($opts['fields'] != '*' && !preg_match('/\b' . $connect[$cf]['target_column'] . '\b/i', $opts['fields'])) {
                $ncols[] = $connect[$cf]['column'];
            }
        }
        $colStr = '`' . join('`,`', $ncols) . '`';
    } else {
        if (!empty($opts['fields']) && $opts['fields'] != '*') {
            $colStr = is_string($opts['fields']) ? explode(',', preg_replace('/[`\s]/', '', $opts['fields'])) : $opts['fields'];
            $colStr = array_filter($colStr, function ($e) use ($schema, $omit) {
                return array_key_exists($e, $schema) && !in_array($e, $omit);
            });
            $colStr = $colPrefix ? $colPrefix . '`' . join('`,' . $colPrefix . '`', $colStr) . '`' : '`' . join('`,`', $colStr) . '`';
        } else if ($colStr == '*' && !empty($schemaDef['general']['fields'])) {
            $colStr = $colPrefix ? $colPrefix . '`' . str_replace(',', '`,' . $colPrefix . '`', $schemaDef['general']['fields']) . '`' : '`' . str_replace(',', '`,`', $schemaDef['general']['fields']) . '`';
        }
    }
    if (is_hash($opts)) {
        $optStr = [];
        if (array_key_exists('@id', $opts) && array_key_exists('id', $opts)) {
            unset($opts['@id']);
            unset($opts['limit']);
        }
        foreach ($opts as $k => $v) {
            preg_match_all('/^(?<tbl>[\w\d_]+)\./i', $k, $ma);
            if (!empty($ma['tbl'])) {
                $tbl = $ma['tbl'][0];
                $col = substr($k, strlen($tbl) + 1);
                if (empty($conns[$tbl])) continue;
                if (!isset($conns[$tbl]['query']))
                    $conns[$tbl]['query'] = [];
                $conns[$tbl]['query'][$col] = $v;
            } else {
                if ($k == '@id') $k = $pk;
                list($k, $cmd) = explode('@', $k);
                $keys = array_filter(preg_split('/\|/', $k), function ($k) use ($schema, $omit) {
                    return array_key_exists($k, $schema) && !in_array($k, $omit);
                });
                if (!empty($keys)) {
                    $cmd = !isset($cmd) || $cmd == '' ? '=' : $cmd;
                    $cmd = strpbrk($cmd, 'begilmnqt') !== false ? Consts::$query_filter_names[$cmd] : $cmd;
                    $func = Consts::$db_query_filters[$cmd];
                    $vStr = $func(join('|', $keys), $v, $data);
                    if ($vStr) $optStr[] = $vStr;
                }
            }
        }
        $optStr =  empty($optStr) ? '' : ' WHERE ' . join(' AND ', $optStr);
        if (!in_array('order', $omit) && !empty($opts['order']))
            $optStr .= ' ORDER BY ' . db_orderby($schema, $opts['order'], $pk);
        // $optStr .= ' ORDER BY '.$opts['order'];
        if (!in_array('limit', $omit) && !empty($opts['limit']))
            $optStr .= ' LIMIT ' . db_limit($opts['limit']);
        // $optStr .= ' LIMIT '.$opts['limit'];
    } else {
        $optStr = !empty($opts) ? ' WHERE ' . $opts : '';
    }
    return [$colStr, $optStr, $data, $conns];
}
function load_schemas($dir = false)
{
    $dir = $dir ?: CONF_DIR . 'schemas';
    $files = glob($dir . "/*.ini");
    $schemas = [];
    $conns = [];
    foreach ($files as $f) {
        $n = str_replace([$dir . '/', '.ini'], '', $f);
        $s = parse_ini_file($f, true);
        if (!empty($s['connect'])) {
            $conns = [];
            foreach ($s['connect'] as $ck => $cv) {
                preg_match_all('/(?P<col>[\w\d_]+)\s*=\s*(?P<tbl>[\w\d_]+)\.(?P<tarCol>[\w\d_]+)/', $cv, $mc);
                if (!empty($mc['col']) && !empty($mc['tbl']) && !empty($mc['tarCol'])) {
                    $conns[$ck] = [
                        'column'         => $mc['col'][0],
                        'table'         => $mc['tbl'][0],
                        'target_column' => $mc['tarCol'][0],
                    ];
                } else {
                    throw "DB ERR: wrong format in $f.ini [connect], should be [MAPPING_NAME = 'COLUMN_NAME = TABLE_NAME.COLUMN_NAME']";
                }
            }
            $s['connect'] = $conns;
        }
        $schemas[$n] = $s;
        redis_set("DB_SCHEMAS:$n", $s);
    }
    return $schemas;
}
function db_schema($schemaName)
{
    $schema = redis_get("DB_SCHEMAS:$schemaName", function () use ($schemaName) {
        $schemas = load_schemas();
        return $schemas[$schemaName];
    });
    return $schema;
}
function db_fields($schema, $prefix = '', $others = [])
{
    $prefix = $prefix ?: '';
    $fields = [];
    if ($schema) {
        $tbl_schema = db_schema($schema)['schema'] ?: [];
        foreach ($tbl_schema as $k => $v) {
            $field = !empty($prefix) ? $prefix . '.' . $k : $k;
            $fields[] = $field;
        }
    }
    foreach ($others as $field) {
        $fields[] = $field;
    }
    return $fields;
}
function db_orderby($fields, $order, $default_order = "")
{
    $order  = trim($order);
    $order  = preg_replace('/\\s+/usm', ' ', $order);
    if (empty($fields) || empty($order)) return $default_order;

    if (is_hash($fields)) {
        $fields_m = $fields;
    } else {
        $fields_m = [];
        array_map(function ($f) use (&$fields_m) {
            $fields_m[$f] = 1;
        }, $fields);
    }

    $rs = [];
    $vs = explode(",", $order);
    foreach ($vs as $v) {
        $v = trim($v);
        $vv = explode(" ", $v);
        $field = $vv[0];
        $sort  = $vv[1] ?: "";
        if (!array_key_exists($field, $fields_m)) {
            continue;
        }
        $sort = in_array(strtolower($sort), ['asc', 'desc']) ? $sort : "asc";
        $rs[] = "$field $sort";
    }

    if (empty($rs)) return $default_order;
    $res = implode(", ", $rs);
    return $res;
}
function pdo_config($opts = null, $pdoOpts = null)
{
    $opts = is_array($opts) ? $opts : [
        'engine' => Consts::$db_engine,
        'host' => Consts::$db_host,
        'port' => Consts::$db_port,
        'name' => Consts::$db_name,
        'user' => Consts::$db_user,
        'pass' => Consts::$db_pass,
        'charset' => 'utf8mb4'
    ];
    $pdoOpts = is_array($pdoOpts) ? $pdoOpts : [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_PERSISTENT => false];
    return (new PDOConfig())
        ->withDriver($opts['engine'])
        ->withHost($opts['host'])
        ->withPort($opts['port'])
        ->withDbname($opts['name'])
        ->withUsername($opts['user'])
        ->withPassword($opts['pass'])
        ->withCharset($opts['charset'])
        ->withOptions($pdoOpts);
}
function pdo_conn($opts = null, $pdoOpts = null)
{
    $config = pdo_config($opts, $pdoOpts);
    return PDOClientFactory::make($config);
}
function pdo_pool($opts = null, $pdoOpts = null, $size = 30)
{
    $config = pdo_config($opts, $pdoOpts);
    $size = intval($size);
    $pool = new ClientPool(PDOClientFactory::class, $config, $size, true);
    $pool->fill();
    return $pool;
}
function db_conn($opts = null, $pdoOpts = null)
{
    return $opts == null && $pdoOpts == null ? Pool::getDBClient() : pdo_conn($opts, $pdoOpts);
}
function db_close($client, $opts = null, $pdoOpts = null)
{
    return $opts == null && $pdoOpts == null ? Pool::putDBClient($client) : $client->close();
}
function pdo_query_column($pdo, $sql, $datas = [])
{
    return pdo_query($pdo, $sql, $datas, PDO::FETCH_COLUMN);
}
function db_query_column($sql, $datas = [])
{
    return db_query($sql, $datas, false, PDO::FETCH_COLUMN);
}
function pdo_query($pdo, $sql, $datas = [], $pdoOpt = null)
{
    if (!$pdo || empty($sql)) return false;
    if (Conf::$mode == 'Developing' || Conf::$mode == 'dev')
        elog(['template' => $sql, 'data' => $datas], "SQL");
    if ($pdoOpt == null) $pdoOpt = PDO::FETCH_ASSOC;
    $isQuery = str_starts_with(strtolower(trim($sql)), 'select');
    $statement = $pdo->prepare($sql);
    $r = $statement->execute($datas);
    if ($r == FALSE)
        return false;
    return $r == FALSE ? false : ($isQuery ? $statement->fetchAll($pdoOpt) : true);
}
function db_query($sql, $datas = [], $pdoOpt = null)
{
    $db = false;
    try {
        $db = db_conn();
        $res = pdo_query($db, $sql, $datas, $pdoOpt);
        db_close($db);
        return $res;
    } catch (PDOException $e) {
        error_log('DB ERR :' . $e->getMessage());
        error_log('DB ERR SQL:' . $sql);
        if ($db) db_close($db);
        return null;
    }
}
function pdo_count($pdo, $sql, $datas = [], $col = 0)
{
    if (!$pdo || empty($sql)) return false;
    $statement = $pdo->prepare($sql);
    if ($statement->execute($datas) == FALSE) {
        return false;
    }
    $res =  $statement->fetchColumn();
    return intval($res);
}
function db_count($sql = null, $datas = [])
{
    $db = null;
    try {
        $db = db_conn();
        $res = pdo_count($db, $sql, $datas);
        db_close($db);
        return $res;
    } catch (PDOException $e) {
        error_log('DB ERR :' . $e->getMessage());
        error_log('DB ERR SQL:' . $sql);
        if ($db) db_close($db);
        return 0;
    }
}
function pdo_import($pdo, $table, $datas, $opts = [])
{
    if (!isset($pdo) || !isset($table) || count($datas) == 0) return false;
    $regName = Consts::$schema_reg;
    $updName = Consts::$schema_upd;
    $ptt = $opts['partition'] ? " PARTITION ($opts[partition]) " : '';
    list($table, $schemaname) = explode('@', $table);
    if (empty($schemaname)) $schemaname = $table;
    $schema = db_schema($schemaname)['schema'];
    $cols = [];
    foreach ($datas as $d) {
        $cols = array_unique(array_merge($cols, array_keys($d)));
    }
    $cls = $cols;
    $cols = [];
    $schema_cols = array_keys($schema);
    foreach ($cls as $c) {
        if (in_array($c, $schema_cols)) {
            $cols[] = $c;
        }
    }
    $hasRegStamp = !empty($regName) && array_key_exists($regName, $schema);
    if ($hasRegStamp && !in_array($regName, $cols)) $cols[] = $regName;
    $hasTimestamp = !empty($updName) && array_key_exists($updName, $schema);
    if ($hasTimestamp && !in_array($updName, $cols)) $cols[] = $updName;
    $sql = 'INSERT IGNORE INTO ' . $table . $ptt . ' (`' . join('`,`', $cols) . '`) VALUES ';
    $time = time();
    foreach ($datas as $d) {
        if ($hasRegStamp && empty($d[$regName])) {
            $d[$regName] = $time;
        }
        if ($hasTimestamp && empty($d[$updName])) {
            $d[$updName] = $time;
        }
        $vals = [];
        foreach ($cols as $c) {
            $v = array_key_exists($c, $d) ? $d[$c] : null;
            $vals[] = db_v($v, $schema[$c]);
        }
        $sql .= ' (' . join(',', $vals) . '), ';
    }
    $sql = substr($sql, 0, strlen($sql) - 2);
    $pdo->setAttribute(PDO::ATTR_TIMEOUT, 1000);
    try {
        pdo_query($pdo, $sql);
        $pdo->setAttribute(PDO::ATTR_TIMEOUT, 10);
    } catch (Exception $e) {
        $pdo->setAttribute(PDO::ATTR_TIMEOUT, 10);
        $file = '/tmp/' . $table . '_imp.sql';
        file_put_contents($file, $sql);
        return false;
    }
}
function db_import($table, $datas)
{
    $db = db_conn();
    $rs = pdo_import($db, $table, $datas, Consts::$schema_reg, Consts::$schema_upd);
    db_close($db);
    return $rs;
}
function pdo_save($pdo, $table, $data, $returnId = false, $schema_def = false)
{
    if (!isset($pdo) || !isset($table) || !is_hash($data) || empty($data)) return false;
    $regName = Consts::$schema_reg;
    $updName = Consts::$schema_upd;
    $ptt = $data['partition'] ? " PARTITION ($data[partition]) " : '';
    list($table, $schemaname) = explode('@', $table);
    if (!$schema_def) {
        if (empty($schemaname)) $schemaname = $table;
        $schema_def = db_schema($schemaname);
    } else if (is_string($schema_def))
        $schema_def = json_decode($schema_def, true);
    $schema = $schema_def['schema'];
    $pk = $schema_def['general']['pk'];
    $pks = [];
    $qo = null;
    $isUpdate = false;
    if (Conf::$mode == 'Developing') {
    }
    if (isset($data[$pk]) && $data[$pk] == '')
        unset($data[$pk]);
    if (preg_match('/[|+,]/', $pk)) {
        $pks = preg_split('/[|+,]/', $pk);
        $qo = [];
        foreach ($pks as $p) {
            if (empty($data[$p])) {
                $qo = [];
                break;
            } else
                $qo[$p] = $data[$p];
        }
        if (!empty($qo)) {
            try {
                $ext = pdo_find($pdo, $table . '@' . $schemaname, $qo, false, false, $schema_def);
            } catch (Exception $e) {
                elog($e->getMessage() . '\n');
            }
            $isUpdate = !empty($ext);
        }
    } else {
        $id = isset($data[$pk]) ? $data[$pk] : null;
        $isUpdate = isset($id) && pdo_exists($pdo, $table . '@' . $schemaname, $id, $pk);
    }
    $sql = '';
    if (array_key_exists($updName, $schema) && !isset($data[$updName])) {
        $data[$updName] = time();
    }
    $qdatas = [];
    if ($isUpdate) {
        foreach ($data as $col => $val) {
            if (str_ends($col, '+')) {
                $opr = substr($col, -1);
                $col = substr($col, 0, -1);
            }
            if ($col == $pk || in_array($col, $pks) || !isset($schema[$col])) continue;
            if (!empty($colStmt)) $colStmt .= ',';
            $colStmt .= $opr ? '`' . $col . '`=`' . $col . '` + :' . $col . ' ' : '`' . $col . '`=:' . $col . ' ';
            $qdatas[$col] = is_array($val) ? json_encode($val) : $val;
        }
        if (empty($pks)) {
            $sql = 'UPDATE `' . $table . '`' . $ptt . ' SET ' . $colStmt . ' WHERE `' . $pk . '`=' . db_v($id) . ';';
        } else {
            $table = $table . '@' . $schemaname;
            list($colStr, $optStr, $qrdatas) = db_make_query($table, $qo);
            foreach ($qrdatas as $qk => $qv) {
                $qdatas[$qk] = $qv;
            }
            $sql = 'UPDATE `' . $table . '`' . $ptt . ' SET ' . $colStmt . ' ' . $optStr;
        }
    } else {
        if (array_key_exists($regName, $schema) && !isset($data[$regName]))
            $data[$regName] = time();
        foreach ($data as $col => $val) {
            if (str_ends($col, '+')) {
                $opr = substr($col, -1);
                $col = substr($col, 0, -1);
            }
            if (!isset($schema[$col])) continue;
            if (!empty($colStmt)) $colStmt .= ',';
            if (!empty($valStmt)) $valStmt .= ',';
            $colStmt .= '`' . $col . '`';
            $valStmt .= $opr ? '`' . $col . '` + :' . $col . ' ' : ':' . $col;
            $qdatas[$col] = is_array($val) ? json_encode($val) : $val;
        }
        $sql = 'INSERT IGNORE' . ' INTO `' . $table . '`' . $ptt . ' (' . $colStmt . ') VALUES(' . $valStmt . ')';
    }
    try {
        if ($returnId == true && !$isUpdate) {
            if (!$pdo->inTransaction()) {
                $res = pdo_trans($pdo, [$sql, 'SELECT LAST_INSERT_ID() as \'last_id\''], [$qdatas]);
                $data['id'] = $res[0]['last_id'];
            } else {
                pdo_query($pdo, $sql, $qdatas);
                $res = pdo_query($pdo, 'SELECT LAST_INSERT_ID() as \'last_id\'', []);
                $data['id'] = $res[0]['last_id'];
            }
        } else {
            pdo_query($pdo, $sql, $qdatas);
        }
        return $data;
    } catch (Exception $e) {
        error_log('ERROR ' . $e->getMessage());
        error_log($sql);

        return false;
    }
}
function db_save($table, $data, $returnId = false, $schema_def = false)
{
    $db = db_conn();
    $rs = pdo_save($db, $table, $data, $returnId, $schema_def);
    db_close($db);
    return $rs;
}
function pdo_find($pdo, $table, $opts = [], $withCount = false, $pdoOpt = null, $schema_def = false)
{
    if (!$pdo || !$table) return false;
    $ptt = $opts['partition'] ? " PARTITION ($opts[partition]) " : '';
    list($colStr, $optStr, $datas, $conns) = db_make_query($table, $opts, [], false, $schema_def);
    $sql = 'SELECT ' . $colStr . ' FROM ' . $table . $ptt . $optStr;
    $res = pdo_query($pdo, $sql, $datas, $pdoOpt);

    if (!empty($conns) && !empty($res)) {
        $ds = [];
        $extras = [];
        foreach ($conns as $conn => $def) {
            $col = $def['column'];
            if (!isset($ds[$col]))
                $ds[$col] = array_map(function ($e) use ($col) {
                    return $e[$col];
                }, $res);
            $condition = empty($def['query']) ? [] : $def['query'];
            $condition['fields'] = $def['fields'];
            $tc = $def['target_column'];
            if (count($ds[$col]) > 1) {
                $ds[$col] = array_filter($ds[$col], function ($e) {
                    return $e && $e != '';
                });
                if (!empty($ds[$col])) $ds[$col] = array_unique($ds[$col]);
                if (!empty($ds[$col]))
                    $condition[$tc . '@in'] = join(',', $ds[$col]);
            } else
                $condition[$tc] = $ds[$col][0];
            $re = pdo_find($pdo, $def['table'], $condition, false, $pdoOpt, $schema_def);
            $extras[$conn] = [];
            foreach ($re as $r) {
                $k = $r[$tc];
                if (!isset($extras[$conn][$k]))
                    $extras[$conn][$k] = [];
                $extras[$conn][$k][] = $r;
            }
        }
        foreach ($res as &$r) {
            foreach ($conns as $conn => $def) {
                $tc = $def['target_column'];
                $r[$conn] = $extras[$conn]['' . $r[$def['column']]];
                if ($def['fields'] != '*' && !in_array($tc, $def['fields']))
                    unset($r[$conn][$tc]);
            }
        }
    }
    if ($withCount) {
        $sql = 'SELECT count(*) FROM ' . $table . preg_replace(['/ORDER\s+BY.*/i', '/LIMIT\s.*/i'], '', $optStr);
        $cnt = pdo_count($pdo, $sql, $datas);
        $key_cnt = property_exists('Consts', 'schema_total') ? Consts::$schema_total : 'count';
        $key_res = property_exists('Consts', 'schema_result') ? Consts::$schema_result : 'result';
        return [$key_cnt => $cnt, $key_res => $res];
    } else {
        return $res;
    }
}
function db_find($table, $opts = [], $withCount = false, $pdoOpt = null, $schema_def = false)
{
    $db = db_conn();
    $rs = pdo_find($db, $table, $opts, $withCount, $pdoOpt, $schema_def);
    db_close($db);
    return $rs;
}
function db_find1st($table, $opts = [], $pdoOpt = null, $schema_def = false)
{
    $opts['limit'] = 1;
    $res = db_find($table, $opts, false, $pdoOpt, $schema_def);
    return isset($res) && $res != false ? $res[0] : false;
}
function pdo_exists($pdo, $table, $id, $pk = false)
{
    if (!isset($pdo) || !isset($table) || !isset($id))
        return false;
    list($table, $schemaname) = explode('@', $table);
    if (empty($schemaname)) $schemaname = $table;
    $pk = $pk ?: db_schema($schemaname)['general']['pk'];
    $entity = pdo_count($pdo, "select count(*) from $table where `$pk`=:$pk", [$pk => $id]);
    return $entity > 0;
}
function db_exists($table, $id)
{
    $db = db_conn();
    $rs = pdo_exists($db, $table, $id);
    db_close($db);
    return $rs;
}
function pdo_trans($pdo, $querys, $datas, $pdoOpt = null)
{
    if (isset($_REQUEST['__db_error'])) unset($_REQUEST['__db_error']);
    if (!isset($pdo) || !isset($querys))
        return false;
    if ($pdoOpt == null) $pdoOpt = PDO::FETCH_ASSOC;
    $mod = $pdo->getAttribute(PDO::ATTR_ERRMODE);
    $pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, 0);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $cnt = 0;
    $res = true;
    try {
        $pdo->beginTransaction();
        if (is_callable($querys)) {
            $cnt = $querys($pdo);
        } else if (is_array($querys)) {
            $i = 0;
            foreach ($querys as $q) {
                $i++;
                if ($q === '@rollback') {
                    $pdo->rollBack();
                    $cnt--;
                } else {
                    $statement = $pdo->prepare($q);
                    if (!$statement) {
                        error_log("PDO TRANS Failed : " . $pdo->errorInfo());
                        continue;
                    }
                    $data = isset($datas[$i - 1]) ? $datas[$i - 1] : [];
                    if ($statement->execute($data) == false) {
                        error_log("PDO TRANS Failed : " . $pdo->errorInfo());
                        continue;
                    }
                    if (str_starts_with(strtolower($q), 'select')) {
                        $res = $statement->fetchAll($pdoOpt);
                    }
                    $cnt++;
                }
            }
        }
        if ($cnt > 0)
            $pdo->commit();
        else
            $pdo->rollBack();
    } catch (Exception $e) {
        error_log('DB Transaction ERR:' . $e->getMessage());
        $data = [
            'trace' => $e->getTraceAsString(),
            'code' => $e->getCode(),
            'msg' => $e->getMessage(),
        ];
        $_REQUEST['__db_error'] = $data;

        $pdo->rollBack();
        $res = false;
    }
    $pdo->setAttribute(PDO::ATTR_AUTOCOMMIT, 1);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, $mod);
    return $res;
}
function db_trans($querys, $datas = [])
{
    $db = db_conn();
    $rs = pdo_trans($db, $querys, $datas);
    db_close($db);
    return $rs;
}
function db_delete($table, $opts)
{
    if (empty($opts)) return false;
    list($cs, $optStr, $data) = db_make_query($table, $opts, ['order', 'limit', 'fields']);
    $sql = 'DELETE FROM ' . $table . ' ' . $optStr;
    return db_query($sql, $data);
}
function db_update($table, $data, $opts = [])
{
    $tableschema = $table;
    if (!isset($table) || empty($data) || !is_hash($data))
        return false;
    $vStrs = [];
    list($table, $schemaname) = explode('@', $table);
    if (empty($schemaname)) $schemaname = $table;
    $schema = db_schema($schemaname)['schema'];
    foreach ($data as $k => $v) {
        $vStrs[] = '`' . $k . '`=' . db_v($v, $schema[$k]);
    }
    $vStrs = join(',', $vStrs);
    list($cs, $optStr, $data) = db_make_query($tableschema, $opts, ['order', 'limit', 'fields']);
    $sql = 'UPDATE ' . $table . ' SET ' . $vStrs . ' ' . $optStr;
    return db_query($sql, $data);
}

function pdo_migrate($pdo, $dbn, $schemaName, $tableName = null)
{
    $isCLI = (php_sapi_name() === 'cli');
    if (empty($tableName)) $tableName = $schemaName;
    $schema_def = db_schema($schemaName);
    $dbms = $schema_def['general']['dbms'];
    if (isset($dbms) && $dbms != "mysql") {
        // TODO others dbms
        echo "Ignore $dbms table: " . $tableName . "\n";
        return;
    }
    $sql = "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$dbn' AND table_name='$tableName'";
    $res = pdo_count($pdo, $sql, [], false);
    $exists = $res > 0;
    if ($res <= 0) { //schema doesn't exist
        $schema = $schema_def['schema'];
        $pk = $schema_def['general']['pk'];

        //db engine
        $engine = $schema_def['general']['engine'];
        if (empty($engine)) $engine = 'InnoDB';

        $colStmt = '';
        foreach ($schema as $col => $type) {
            $colStmt .= '`' . $col . '` ' . $type . ', ';
        }
        $incStmt = '';
        $auto_increment = $schema_def['general']['auto_increment'];
        if ($auto_increment) $incStmt .= 'auto_increment=' . $auto_increment;

        $ptt = $schema_def['partition'];
        $partition = '';
        if (!empty($ptt) && $ptt['type']) {
            $ptp = strtoupper($ptt['type']);
            $pcmds = ['LIST' => 'IN', 'RANGE' => 'LESS THAN'];
            $cmd = $pcmds[$ptp];
            unset($ptt['type']);
            $ppks = [];
            $psql = [];
            foreach ($ptt as $pn => $ps) {
                list($ppk, $pvs) = explode('=', $ps);
                if (!in_array($ppk, $ppks)) $ppks[] = $ppk;
                $psql[] = "PARTITION $pn VALUES $cmd ($pvs)";
            }
            $partition = "PARTITION BY $ptp (`" . join('`,`', $ppks) . "`) (" . implode(',', $psql) . ")";
        }

        $sql = '';
        if (str_has($pk, '|') || str_has($pk, '+') || str_has($pk, ',')) {
            $parts = preg_split('/[\|\+,]/', $pk);
            $pkName = join('_', $parts);
            $keys = '`' . join('`,`', $parts) . '`';
            $sql = "CREATE TABLE `$dbn`.`$tableName` ( $colStmt CONSTRAINT $pkName PRIMARY KEY ($keys)) ENGINE=$engine $incStmt DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci $partition;";
        } else {
            $sql = "CREATE TABLE `$dbn`.`$tableName` ( $colStmt PRIMARY KEY (`$pk`)) ENGINE=$engine $incStmt DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci $partition;";
        }
        $res = pdo_query($pdo, $sql);

        //index
        $index = $schema_def['index'];
        if (!empty($index)) {
            foreach ($index as $cols => $desc) {
                $name = 'idx_' . preg_replace('/[\+\.\|]/', '_', $cols);
                $cols = preg_replace('/[\+\.\|]/', '`,`', $cols);
                $desc = $desc == 'unique' ? 'UNIQUE ' : ' ';
                $indextype = ($engine == 'MEMORY' && strtolower($desc) == 'hash') ? ' USING HASH' : ' ';
                $sql = "CREATE $desc" . "INDEX $name ON `$tableName` (`$cols`) $indextype";
                if ($isCLI) echo $sql . '\n';
                pdo_query($pdo, $sql);
            }
        }
    }
    if ($isCLI && !$exists)
        echo 'Created ' . $tableName . "\n";
    else return true;
}
function db_migrate($schemaName, $tableName = null)
{
    $db = db_conn();
    pdo_migrate($db, Consts::$db_name, $schemaName, $tableName);
    db_close($db);
}


function s3_get($fn)
{
    $r = redis_get('S3:' . $fn, function ($k) use ($fn) {
        $url = 'https://' . Conf::$aws_s3_bucket . '.s3.amazonaws.com/' . $fn;
        $r = file_get_contents($url);
        return $r ?: false;
    }, false);
    return $r;
}
function s3_clear_cache($fn)
{
    redis_del('S3:' . $fn);
}

function bson_enc($arr)
{
    $str = json_encode($arr);
    $str = str_replace('\\', '', $str);
    return str2hex($str);
}
function bson_dec($bson)
{
    if (isset($bson)) {
        $json = hex2str($bson);
        return json_decode($json, true);
    }
    return false;
}

function call($url, $method, $data = [], $header = [], $options = [])
{
    elog($url, 'CALL');
    $method = strtoupper($method);
    $postJSON = $method == "POSTJSON";
    if ($postJSON) $method = 'POST';
    $defaults = $method == 'POST' || $method == 'PUT' ? [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_HEADER         => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_VERBOSE        => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_POSTFIELDS     => is_string($data) ? $data : http_build_query($data)
    ] : [
        CURLOPT_URL            => $url . (strpos($url, '?') === FALSE ? '?' : '') . http_build_query($data),
        CURLOPT_HEADER         => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_VERBOSE        => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ];

    if ($postJSON) {
        $defaults[CURLOPT_POSTFIELDS] = is_array($data) ? json_encode($data) : $data;
        $defaults[CURLOPT_FOLLOWLOCATION] = 1;
        $header[] = 'Content-Type: application/json';
    }

    if (!empty($header)) {
        $defaults[CURLOPT_HTTPHEADER] = $header;
    }

    if ($options[CURLOPT_CONNECTTIMEOUT] > 0) {
        // nothibng
    } else if ($_REQUEST['__CURLOPT_CONNECTTIMEOUT'] > 0) {
        $defaults[CURLOPT_CONNECTTIMEOUT] = intval($_REQUEST['__CURLOPT_CONNECTTIMEOUT']);
    } else if (property_exists('Conf', 'curlopt_connecttimeout') && Conf::$curlopt_connecttimeout) {
        $defaults[CURLOPT_CONNECTTIMEOUT] = Conf::$curlopt_connecttimeout;
    } else {
        $defaults[CURLOPT_CONNECTTIMEOUT] = 3;
    }
    unset($_REQUEST['__CURLOPT_CONNECTTIMEOUT']);
    if (!empty($_REQUEST['__CURL_HTTP_CODE'])) {
        unset($_REQUEST['__CURL_HTTP_CODE']);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, $options + $defaults);

    unset($_REQUEST['__CURL_ERROR']);
    if (!$result = curl_exec($ch)) {
        $err = ['code' => curl_errno($ch), 'msg' => curl_error($ch), 'from' => 'curl'];
        $_REQUEST['__CURL_ERROR'] = $err;
        elog("call_error code=$err[code] msg=$err[msg] url=$url");

        trigger_error(curl_error($ch));
    }
    if ($result && $options[CURLOPT_HEADER] == true) {
        $h_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $_REQUEST['__CURL_RESPONSE_HEADER_SIZE'] = $h_size;
    }
    $_REQUEST['__CURL_HTTP_CODE'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $result;
}


/**------------- PHP8 Fatal error handler  --------------**/

function ignore_errors($errno, $errstr, $errfile, $errline) {
    switch ($errno) {
        case E_ERROR:
        case E_USER_ERROR:
            error_log("[PHP Error:$errno] $errstr - $errfile:$errline\n");
            // exit(1);
            break;
        default:
            break;
    }
    return true;/* Don't execute PHP internal error handler */
}
set_error_handler("ignore_errors");



//CHANGES: 2406
/**------------- FOR LEGACY liber.php START -------------**/
class FnRewriteStream {
    private $pos = 0;
    private $data;

    public function stream_open($path, $mode, $options, &$opened_path) {
        $path = str_replace("phpmod:/", "", $path);
        $this->data = file_get_contents($path);
        $fn = preg_replace('/^_/','',str_replace('.inc','',str_replace('/','_',str_replace(CONTROLLER_DIR, '', $path))));
        $this->data = preg_replace_callback('/function\s+(\w+)\s*\(/', function($matches) use ($fn) {
            if (!empty($matches[1]))
                return "function {$fn}_". $matches[1] . '(';
            return $matches[0];
        }, $this->data);
        return true;
    }

    public function stream_read($count) {
        $ret = substr($this->data, $this->pos, $count);
        $this->pos += strlen($ret);
        return $ret;
    }

    public function stream_eof() {
        return $this->pos >= strlen($this->data);
    }

    public function stream_stat() {
        return [];
    }
}
if(property_exists('Consts', 'legacy'))
    stream_wrapper_register("phpmod", "FnRewriteStream");

/**
 * 1. get request instance: $k=null/$v=null; $r = req();
 * 2. get request value: $k=string; $v = req($key);
 * 3. set request key->value: $k=string/$v=mixed; req($k,$v);
 * 3. delete request key: $k=string/$v=false; req($k,false);
 */
function req($k=null, $v=null){
    $ctx = Coroutine::getContext();
    $r = $ctx['req'];
    if(!$k) return $r;
    $d = $r->getData();
    if($v===null){//GET
        return $d[$k];
    }else if($v===false){//DEL
        unset($d[$k]);
    }else //SET
        $d[$k]=$v;
}

/**
 * 1. get session data: $k=null; $data=session();
 * 2. get session value: $k=string; $v = session($key);
 * 3. set session key->value: $k=string/$v=mixed; session($k,$v);
 * 4. delete session key: $k=string/$v=false; session($k,false);
 * 5. set session data/clear: $k=Hash; session($data); to clear: session([]); / session([],null,true)
 */
function session($k=null, $v=null, $immidiate=false){
    $req = req();
    $s = $req->getSession();
    if(!$k) return $s->get();
    if($v===null){
        if(is_string($k)) return $s->get_val($k);//GET
        if(is_hash($k)) return $s->set($k);//SET the whole data/clear
        return null;
    }else if($v===false){//DEL
        $s->del_val($k,$immidiate);
    }else //SET
        $s->set_val($k,$v,$immidiate);
}
/**
 * set response header, equivalent to header()
 * 1. set: $k=str/$v=value; head($k,$v);
 * 2. delete: $k=string; $v = session($key);
 */
function head($k, $v, $immidiate=false){
    if(empty($k))return;
    $req = req();
    $req->setMeta($k,$v);
    if($v!==false && $immidiate){//SET, but not DELETE (DEL can not be affected)
        $resp = $req->getResponse();
        $resp->header($k, $v);
    }
}

function assign($key, $value){
    $req = req();
    if($req) $req->getRenderer()->assign($key, $value);
}

function render_layout($file){
    $req = req();
    if($req) $req->render_layout($file);
}

function render_html($templateName=null, $datas=array()){
    $req = req();
    if($req) $req->render_html($templateName, $datas);
}

function render_json($data){
    $req = req();
    if($req) $req->render_json($data);
}

function render_text($text){
    $req = req();
    if($req) $req->render_text($text);
}

function render_js($js){
    $req = req();
    if($req) $req->render_js($js);
}

function check_params($p, $keys, $code=400, $msg='Parameter error'){
    $req = req();
    if($req) $req->check_params($p, $keys, $code, $msg);
}

function error($code, $contentType = '', $reason = '', $error_message = false){
    $req = req();
    if($req) $req->error($code, $contentType, $reason, $error_message);
}

function T($key, $lang = false){
    $req = req();
    if($req) $req->T($key, $lang);
}

function sse_start(){
	head('Content-Type','text/event-stream',true);
    head('Cache-Control','no-cache',true);
    $req = req();
    $req->isSSE(true);
}

function sse_flush($data){
	if(!is_sse()) return;
	if(is_string($data)||is_number($data)) 
		$data=['msg'=>$data];
    $req = req();
    $req->getResponse()->write("data: " . json_encode($data) . "\n\n");
}

function sse_end($exit=true){
	if(!is_sse()) return;
	sse_flush(['SSESTAT' => "END"]);
    $req = req();
	if($exit) $req->error(204);//prevent reconnect from client side
}

function is_sse(){
    $req = req();
    return $req->isSSE();
}

/**------------- FOR LEGACY liber.php END -------------**/

$server->on('request', function ($request, $response) {
    if (strtoupper($request->server['request_method']) == 'OPTIONS' && strlen(Consts::$cross_domain_methods) > 0) {
        $response->status(200);
        $response->header('Content-type', 'application/json');
        $response->header('Access-Control-Allow-Origin: *');
        $response->header('Access-Control-Allow-Methods: ' . preg_replace('/\s*,\s*/', ', ', Consts::$cross_domain_methods));
        exit;
    }
    
    Pool::init();
    
    //CHANGES: 2406, to use render_json() anywhere
    $ctx = Coroutine::getContext();
    $req = $ctx['req'] = new REQ();
    
    try {
        $req->start($request, $response);
        $req->flush();//set response headers/sessions->redis
    } catch (Exception $th) {
        error_log('Internal Server Error : '.$th->getMessage());
        $req->error(500);
    }
    $response->end();
});

$server->on('shutdown', function ($server) {
    $conn = rdis_conn();
    $conn->flushAll();
    Pool::cleanup();
});

$server->start();
