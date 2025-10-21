<?php
class Utils {
    const TOKEN_CHARS       = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
    const TOKEN_CHARS_FIRST = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";

    static function isAnybotUrl($str,$sub_prefix="") {
        $str = trim($str);
        $prefixes = [
            "/", Conf::$server_host, 
            Conf::$webhook_host, 
            Conf::$webhook_hostv2
        ];
        foreach ($prefixes as $prefix) {
            if(!empty($sub_prefix)){
                if(!str_ends($prefix,"/")&&!str_starts($sub_prefix,"/")) {
                    $prefix.="/";
                }
                $prefix = $prefix.$sub_prefix;
            }
            if(str_starts($str, $prefix)) return true;
        }
        return false;
    }
    static function isAnybotAPI($str) {
        return Utils::isAnybotUrl($str, "api/");
    }

    static function mode_str() {
        $mode = Conf::$mode;
        $vert = Conf::$vert;
        $env  = Conf::$env;
        $mode_str = "mode=$mode vert=$vert env=$env";
        return $mode_str;
    }
    
    static function callJSON($url, $method, $data = [], $header = [], $options = []){
        $header=$header?: [];   //array
        $options=$options?: []; // hash
        $method = strtoupper($method);
        if(in_array($method, ['POST', 'PUT', 'DELETE', 'UPLOAD', 'PATCH'])){
            $header[] = 'Content-type: application/json; charset=utf-8';
            $options  = [
                //CURLOPT_RETURNTRANSFER => true,
                CURLOPT_BINARYTRANSFER => true,
                CURLOPT_POSTFIELDS=>is_string($data)?$data: json_encode($data)
            ] + $options;
            return Utils::call($url, $method, '', $header,$options);
        }else{
            return Utils::call($url, 'GET', '', $header, $options);
        }
    }

    static function call($url, $method, $data = [], $header = [], $options = []) {
        $method = strtoupper($method);
        $defaults = $method == 'POST' || $method == 'PUT' ||$method == 'PATCH' ? [
            CURLOPT_URL            => $url,
            CURLOPT_HEADER         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_VERBOSE		=> true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_POSTFIELDS     => is_string($data)?$data:http_build_query($data)
        ]:[
            CURLOPT_URL            => $url. (strpos($url, '?') === FALSE ? '?' : ''). http_build_query($data),
            CURLOPT_HEADER         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_VERBOSE		=> true,
            CURLOPT_SSL_VERIFYPEER => false
        ];
        // method=delete
		if('DELETE'==$method) $defaults[CURLOPT_CUSTOMREQUEST] = "DELETE";
        if('POST'==$method) $defaults[CURLOPT_POST] = true;
        if('PUT'==$method) $defaults[CURLOPT_CUSTOMREQUEST] = "PUT";
        if('PATCH'==$method) $defaults[CURLOPT_CUSTOMREQUEST] = "PATCH";
		
        if (!empty($header)){
            $defaults[CURLOPT_HTTPHEADER] = $header;
        }
        $ch = curl_init($url);
        // elog($options + $defaults,"opts");
        curl_setopt_array($ch, $options + $defaults);
        if( ! $result = curl_exec($ch)){
            trigger_error(curl_error($ch));
        }

        $info = curl_getinfo($ch);
        curl_close($ch);
        $r = Utils::parse_call_response($result, $info);

        // log
        $log_d = "$method $url";
        if(false) {
            $log_d = ['url'=>"$method $url", 'data'=>$data ];
        }
        if($options['CURLOPT_VERBOSE']==true) {
            elog($log_d, 'Utils::call');
        }
        return $r;
    }

    private static function parse_call_response($result, $info=[]) {
        $res = ['body'=>$result];
        /* $info
                "url"
                "content_type"
                "http_code"
                "header_size"
                "request_size"
                "filetime"
                "ssl_verify_result"
                "redirect_count"
                "total_time"
                "namelookup_time"
                "connect_time"
                "pretransfer_time"
                "size_upload"
                "size_download"
                "speed_download"
                "speed_upload"
                "download_content_length"
                "upload_content_length"
                "starttransfer_time"
                "redirect_time"
        */
        $hsize = $info['header_size']>=0?$info['header_size']: $_REQUEST['__CURL_RESPONSE_HEADER_SIZE'];
        if($hsize>0) {
            $header_str     = substr($result, 0, $hsize);
            $header_str     = str_replace(["\n","\r\n"],"\n",$header_str);
            $body   = substr($result, $hsize);
            // parse json string
            $json_body = json_decode($body,true);                
            if($json_body) $body = $json_body;

            // parse header
            $headers = [];
            $hd  = explode("\n", $header_str);
            // status code
            $status      = $hd[0];
            $status_code = intval(explode(" ", $status)[1]);
            array_shift($hd);
            // headers
            $headers['status']      = $status;
            $headers['status_code'] = $status_code;
            foreach($hd as $d){
                $ss=explode(":",$d); $k = trim($ss[0]); $v = trim($ss[1]);
                $headers[$k] = $v;
            }
            $res = ['status'=>$status, 'status_code'=>$status_code, 'body'=>$body, 'headers'=> $headers, 'info'=> $info ];
        }
        return $res;
    }

    static function db_filter_fields($table, &$data=[],$exclude_fields=[]){
        list($table,$sname) = explode('@',$table);
        if(empty($sname)) $sname = $table;
        $s = db_schema($sname)['schema'];
        $s_map = [];
        foreach($s as $k=>$v) {
            $k = strtolower($k);
            $s_map[$k] = 1;
        }

        $ks = array_keys($data);
        foreach($ks as $k) {
            $k = strtolower($k);
            if(in_array($k, $exclude_fields)) {
                unset($data[$k]);    
            } else if(!$s_map[$k]) {
                unset($data[$k]);
            }
        }
        return $data;
    }
    static function db_fields($table, $exclude_fields=[]){
        list($table,$schemaname) = explode('@',$table);
        if(empty($schemaname)) $schemaname = $table;
        $schema = db_schema($schemaname)['schema'];
        $cols=[];$schema_cols =array_keys($schema);
        foreach($schema_cols as $c){
            if(!in_array($c, $exclude_fields)){
                $cols[]=$c;
            }
        }
        return $cols;
    }

    static function db_in_fields($field, $table_or_fields, $exclude_fields=[]) {
        if(is_string($table_or_fields)) {
            $table = $table_or_fields;
            $fields = self::db_fields($table, $exclude_fields);
        } else {
            $fields = $table_or_fields;
        }
        
        $fields = array_map(function($c){ return strtolower($c); }, $fields);
        $field  = strtolower($field);
        return in_array($field, $fields);
    }

    static function db_orderby_formart($order, $default_order="") {
        $order  = trim($order);
        $order  = preg_replace('/\\s+/usm', ' ', $order);
        if(empty($order)) return $default_order;

        $rs = [];
        $vs = explode(",", $order);
        foreach($vs as $v) {
            $v=trim($v);
            $vv = explode(" ", $v);
            $field = $vv[0];
            $sort  = $vv[1]?:"";
            $sort = in_array(strtolower($sort), ['asc', 'desc'])?$sort: "asc";
            $rs[] ="$field $sort";
        }
        
        if(empty($rs)) return $default_order;
        $res = implode(", ", $rs);
        return $res;
    }

    /**
     * Order byデータを安全に生成する
     *
     * @param [type] $table
     * @param [type] $order
     * @param array $exclude_fields
     * @return void
     */
    static function db_orderby($table_or_scheme, $order, $default_order="", $exclude_fields=[]) {
        $fields = self::db_fields($table_or_scheme, $exclude_fields);
        $order  = trim($order);
        $order  = preg_replace('/\\s+/usm', ' ', $order);
        if(empty($order)) return $default_order;

        $rs = [];
        $vs = explode(",", $order);
        foreach($vs as $v) {
            $v=trim($v);
            $vv = explode(" ", $v);
            $field = $vv[0];
            $sort  = $vv[1]?:"";
            if(!self::db_in_fields($field, $fields)) {
                continue;
            }
            $sort = in_array(strtolower($sort), ['asc', 'desc'])?$sort: "asc";
            $rs[] ="$field $sort";
        }
        
        if(empty($rs)) return $default_order;
        $res = implode(", ", $rs);
        return $res;
    }

    static function db_limit($limit, $default="") {
        $default = intval($default)?:20;
        if(isset($limit)){
            list($start,$pagesize) = explode(",", $limit);
    
            $start = intval($start);
            $pagesize = intval($pagesize);
    
            if(!$pagesize){
                $pagesize = $start;
                $start = 0;
            }
            $limit = "$start,$pagesize";
        } else {
            $limit = "0,$default";
        }
        return $limit;
    }

    static function build_db_fields($table, $exclude_fields, $prefix='') {
        $cols = self::db_fields($table, $exclude_fields);
        return $prefix."`".join("`,$prefix`", $cols)."`";
    }

    static function bot_signature_verify($bid,$sig_header,$payload){
        $secret_key = SysVars::getValue($bid, 'bot_api_secret_key');
        return self::signature_verify($sig_header,$payload,$secret_key);
    }

    static function signature_verify($sig_header,$payload,$secret_key){
        if(!$secret_key)return false;
        $sig = [];
        $pairs = explode(',',$sig_header);
        foreach ($pairs as $pair) {
            list($k,$v) = explode('=',$pair);
            $sig[$k] = $v;
        }

        if(!$sig['v1'] || !$sig['t']){
            return false;
        }

        if(time() - $sig['t'] > 300){ // 5mins
            return false;
        }

        $signed_payload = $sig['t'].'.'.$payload;

        $hmac = hash_hmac("sha256",$signed_payload,$secret_key);

        if($hmac == $sig['v1']){
            return true;
        }

        return false;
    }

    public static function imap_path($bot, $mailbox=NULL) {
        $ssl = $bot["imap_secure"] ? "/ssl" : "";
        return "{{$bot["imap_server"]}:{$bot["imap_port"]}/imap{$ssl}/novalidate-cert}" . $mailbox;
    }

    public static function encrypt($data, $key) {
        $key = md5($key);
        $x   = 0;
        $len = strlen($data);
        $l   = strlen($key);
        for ($i = 0; $i < $len; $i++) {
            if ($x == $l) 
                $x = 0;
            $char .= $key{$x};
            $x++;
        }

        for ($i = 0; $i < $len; $i++) {
            $str .= chr(ord($data{$i}) + (ord($char{$i})) % 256);
        }

        return base64_encode($str);
    }

    public static function decrypt($data, $key) {
        $key  = md5($key);
        $x    = 0;
        $data = base64_decode($data);
        $len  = strlen($data);
        $l    = strlen($key);
        for ($i = 0; $i < $len; $i++) {
            if ($x == $l)
                $x = 0;
            $char .= substr($key, $x, 1);
            $x++;
        }

        for ($i = 0; $i < $len; $i++) {
            if (ord(substr($data, $i, 1)) < ord(substr($char, $i, 1)))
                $str .= chr((ord(substr($data, $i, 1)) + 256) - ord(substr($char, $i, 1)));
            else
                $str .= chr(ord(substr($data, $i, 1)) - ord(substr($char, $i, 1)));
        }
        
        return $str;
    }

    function setTokenIfNull($tbl, $id, $field='token', $len=24) {
        if(empty($tbl)||!$id) return;

        $token=null; $max_time=100;
        for($i=0;$i<$max_time;$i++){//check duplicate value
            $token = keygen($len,self::TOKEN_CHARS);
            $first = keygen(1, self::TOKEN_CHARS_FIRST);
            if(is_number($token[0])) $token[0] = $first;

            $res = db_query("UPDATE `$tbl` SET `$field`=? WHERE id=? AND (`$field` is NULL OR `$field`='')", [$token, $id]);
            if(empty($res)) continue;
            break;
        }
        $last_token = db_query_column("SELECT `$field` FROM `$tbl` WHERE id=?",[$id])[0];
        return $last_token;
    }

    public static function md5_json($d) {
        if($d==null) return $d;
        $d = self::fix_md5_d($d);
        $d = is_string($d)?$d: json_encode($d);
        $md5 = md5($d);
        return $md5;
    }

    public static function fix_md5_d($d) {
        if(is_hash($d)) {
            foreach($d as $k=>&$v) {
                $v = self::fix_md5_d($v);
            }
        } else if(is_array($d)) {
            foreach($d as &$v) {
                $v = self::fix_md5_d($v);
            }
        } else {
            $d = "".$d;
        }
        return $d;
    }

    public static function encode_hex($k,$salt=false) {
        $cs = 'abcdefghijklmnopqrstuvwxyz0123456789';
        if($salt){
            $k=base64_encode($salt.$k);
        }
        $k  = keygen(3,$cs).bin2hex("$k").keygen(1,$cs);
        return $k;
    }
    public static function decode_hex($k,$salt=false) {
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

    /**
     * get archieved table name with surfix by rule 
     * if table not exists, create it automatically
     * @param [type] $bid
     * @param [type] $schema
     * @param [type] $rule : 
     *  "id" : $bid
     *  'unique' : 1 online
     *  100 : ciel(bid/100)
     * @return table name
     */
    public static function getTable($bid, $schema, $rule='id'){
        $surf = preg_match('/^\d+$/',"$rule")?ceil(intval($bid)/$rule):$bid;
        $tbl = $rule=="unique"?$schema: $schema."_".$surf;
        return cache_get($tbl, function($k) use ($schema, $tbl){
            $all = Utils::getAllTable(Conf::$db_name, $schema);
            if(!in_array($tbl, $all))
                db_migrate($schema,$tbl);
            return $tbl;
        });
    }

    public static function getAllTable($db, $tbl_preffix, $match_type='schema') {
        $tbl_preffix = preg_replace('/\\\\*_/uism', '\\\\_', $tbl_preffix); // "_" => "\_"
        $tables= db_query_column("SELECT `table_name`FROM information_schema.`tables` WHERE table_schema = '$db' AND `table_name` LIKE '$tbl_preffix%'");
        
        if($match_type=='schema') {
            // filter
            $preffix = preg_replace('/\\\\/uism', '', $tbl_preffix); // "\" => ""
            $tables = array_values(array_filter($tables, function($tbl) use ($preffix){
                if($preffix==$tbl) return true;
                $suffix = str_replace($preffix,'', $tbl);
                $suffix = preg_replace('/^_/uism', '', $suffix);
                $is_num = is_number($suffix);
                return $is_num;
            }));
        } else if($match_type=='all') {
            // nothing
        }
        return $tables;
    }


    /**
     * group by column from table with (q)uery object
     * @example $d = Utils::groupby("stores", ["bid"=>1573, 'pref'=>'北海道'], 'subcategory');
     */
    public static function groupby($tbl, $q, $col){
        if(empty($tbl)||empty($col)) return [];
        $schema = self::db_fields($tbl);
        if(!in_array($col, $schema)) return [];
        $q['fields']=$col;
        $q["$col@ne"]=null;
        list($colStr,$optStr,$qrdatas) = db_make_query($tbl, $q);
        $tbl = explode("@",$tbl)[0];
        $sql = "SELECT `$col` from `$tbl` $optStr group by `$col`";
        return db_query_column($sql, $qrdatas);
    }

    static function uuid_v4() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        // 32 bits for "time_low"
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        // 16 bits for "time_mid"
        mt_rand(0, 0xffff),
        // 16 bits for "time_hi_and_version",
        // four most significant bits holds version number 4
        mt_rand(0, 0x0fff) | 0x4000,
        // 16 bits, 8 bits for "clk_seq_hi_res",
        // 8 bits for "clk_seq_low",
        // two most significant bits holds zero and one for variant DCE1.1
        mt_rand(0, 0x3fff) | 0x8000,
        // 48 bits for "node"
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
      );
    }

    static function unset_null_hash(&$data, $maxlevel=1000){
        if($maxlevel<=0)return;
        if(is_array($data)){
            $maxlevel--;
            foreach ($data as $key => &$value) {
                if(is_hash($data)&&$value===null){
                    unset($data[$key]);
                }else if(is_array($value)){
                    self::unset_null_hash($value, $maxlevel);
                }
            }
        }
        
    }

    static function sys_info($keys) {
        $keys = !empty($keys)&&is_string($keys)?explode(",",$keys):$keys;
        // // redis
        $reids_pass    = Conf::$redis_pass;
        // $redis_schemas = [];
        // if(property_exists('Conf','redis_schemas')) $redis_schemas = array_merge([], Conf::$redis_schemas);
        // foreach($redis_schemas as $k=>&$v) {
        //     $v['pass'] = substr($v['pass'],0,2)."*******";
        //     $v = json_encode($v,JSON_UNESCAPED_UNICODE);
        // }
        $reids_pass = substr($v['pass'],0,2)."*******";

        // $reids_on_key = Conf::$reids_on_key;
        // $env_redis_on   = getenv($reids_on_key,true);
        
        $kafka = Conf::$kafka;
        $kafka['groups'] = json_encode($kafka['groups'],JSON_UNESCAPED_UNICODE);
        
        $info = [
            'mode'=>Conf::$mode, 'vert'=>Conf::$vert, 'env'=>Conf::$env,'patch_from'=>Conf::$patch_from,
            'host'=>[
                'server_host'=>Conf::$server_host,
                'webhook_host'=>Conf::$webhook_host,
                'node_server'=>Conf::$node_server,
                'auth_host'=>Conf::$auth_host,
                'pixel_server'=>Conf::$pixel_server,
                'takemepay_host'=>Conf::$takemepay_host,
                'sms_provider'=>Conf::$sms_provider,
                'sms_server_host'=>Conf::$sms_server_host,
            ],

            'mq_host'=>Conf::$mq_host, 'mq_hostname'=>Conf::$mq_hostname,

            // redis
            // 'redis_on'=>Conf::$redis_on, 
            'redis_servers'=>Conf::$redis_servers,
            'redis_sentinel'=>Conf::$redis_sentinel, 'sentinel_port'=>Conf::$sentinel_port, 'reids_pass'=>$reids_pass,
            // 'redis_schemas'=> $redis_schemas,
            'cache_hosts(memcache)'=>Conf::$cache_hosts,
            'cache_v2'=>Conf::$cache_v2,
            // kafka
            'kafka'=>$kafka,
            'kafka_consume_host'=>Conf::$kafka_consume_host,
            'kafka_produce_host'=>Conf::$kafka_produce_host,
            'DAC'=>[
                'old api' => ['do_msg_url'=>Conf::$do_msg_url],
                'new api'=> [ 'do2_stg_host'=>Conf::$do2_stg_host,'do2_host'=>Conf::$do2_host],
            ],

            'mail_from'=>Conf::$mail_from,
            'log_file'=>Conf::$log_file,
            'aws_s3_bucket'=>Conf::$aws_s3_bucket,
        ];
        if(!empty($keys)) {
            $res = [];
            foreach($keys as $k) {
                $res[$k] = $info[$k];
            }
            return $res;
        }
        
        return $info;
    }

    static function tilde2Half($str) {
        $tilde_cn = "～"; $tilde_ja = "〜"; 
        $tilde_top="‾"; // 店铺导入时会把 "~" 转成 "‾"
        $str=str_replace([$tilde_cn, $tilde_ja,$tilde_top], "~",$str);
        return $str;
    }

    static function mysql_escape_mimic($inp) { 
        if(is_array($inp)) 
            return array_map(__METHOD__, $inp); 
    
        if(!empty($inp) && is_string($inp)) { 
            return str_replace(array('\\', "\0", "\n", "\r", "'", '"', "\x1a"), array('\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'), $inp); 
        } 
    
        return $inp; 
    } 

    static function makeUploadFileName($apiver,$bid, $uid, $schema, $file, $imgData, $f_suffix="",$opts=[]) {            
        $suffix = $file['suffix'];
        $suffix = "${suffix}${$f_suffix}";
        $suffix = !empty($suffix)?"_$suffix":'';
        $out_ext = $file['ext']?:"jpg";

        $apiver=$apiver?:Bot::getApiVer($bid);
        if($apiver>=2) {
            $md5_v = md5($imgData);
            $fs_m = [
                "file"=>"$bid/file/f_${md5_v}${suffix}.${out_ext}", // defalt file
                "tmp"=>"$bid/tmp/tmp_${md5_v}${suffix}.${out_ext}", // temp file
                "user"=>"$bid/u/u_${uid}_${md5_v}${suffix}.${out_ext}",    // user's file
                "app"=>"$bid/u/view_u_${uid}_${md5_v}${suffix}.${out_ext}",// user's miniapp file
            ];
        } else {
            $fs_m = [
                "file"=>"file/f_${bid}_${md5_v}${suffix}.${out_ext}", // defalt file
                "tmp"=>"tmp/tmp_${bid}_${md5_v}${suffix}.${out_ext}", // temp file
                "user"=>"u/u_${bid}_${uid}_${md5_v}${suffix}.${out_ext}",    // user's file
                "app"=>"view_u_${bid}_${uid}_${md5_v}${suffix}.${out_ext}", // user's miniapp file
            ];
        }

        $fname = $fs_m[$schema];
        return $fname;
    }

	// Image compression 
	static function imgCompress($source, $destination, $quality) {

		$info = getimagesize($source);
	
		if ($info['mime'] == 'image/jpeg') 
			$image = imagecreatefromjpeg($source);
	
		elseif ($info['mime'] == 'image/gif') 
			$image = imagecreatefromgif($source);
	
		elseif ($info['mime'] == 'image/png') 
			$image = imagecreatefrompng($source);
	
		imagejpeg($image, $destination, $quality);
	
		return $destination;
	}
}