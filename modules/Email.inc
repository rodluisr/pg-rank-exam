<?php

require __DIR__.'/../lib/aws_3.phar';

const REGION = 'us-west-2';

use Aws\Ses\SesClient;

class Email{
    static function send($from,$to,$subject,$body,$replyTo=false,$cc=''){
        if(empty($from)||empty($to)||empty($subject)||empty($body))
            return false;
        $client = SesClient::factory([
            'version'=> 'latest',
            'region' => REGION,
            'credentials' => [
                'key' => Conf::$aws_key_mail,
                'secret'  => Conf::$aws_sec_mail
            ]
        ]);
        $q = [];
        $q['Source'] = $from;
        $q['Destination']['ToAddresses'] = is_string($to)?explode(',',$to):$to;
        if (!empty($cc)) {
            $q['Destination']['CcAddresses'] = is_string($cc)?explode(',',$cc):$cc;
        }
        $q['Message']['Subject']['Data'] = $subject;
        $q['Message']['Body']['Html']['Data'] = $body;
        if($replyTo){
            $q['ReplyToAddresses'] = is_string($replyTo)? explode(",",$replyTo):$replyTo;
        }
        try {
            $res = $client->sendEmail($q);
            $mid = $res->get('MessageId');
            elog("TO:OK:".date('md/H:i:s').':'.(is_string($to)?$to:join(',',$to)).(empty($cc)?"":" and CC:OK:".date('md/H:i:s').':'.(is_string($cc)?$cc:join(',',$cc))));
            return ['mid'=>$mid];
        } catch (Exception $e) {
            elog("TO:Failed:".date('md/H:i:s').':'.(is_string($to)?$to:join(',',$to)).(empty($cc)?"":" or CC:Failed:".date('md/H:i:s').':'.(is_string($cc)?$cc:join(',',$cc))));
            elog("Failed reason: ".$e->getMessage());
            return ['error'=>$e->getMessage()];
        }
    }

    static public function render($template, $data){
		$path = APP_DIR.__SLASH__."views".__SLASH__."mail";
        if(!str_ends($template,".html")) $template .= ".html";
        $_REQUEST['host']=(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
        $data['host']=$_REQUEST['host'];
        $render = Render::factory($path);
		return $render->render($template,$data,null,true);
	}

	static public function sendHTML($from,$to,$template,$data=[],$title=false){
		$to = Conf::$dummy_mail?:$to;
        if(!$title) $title = T("mail_$template");
		$html = self::render($template,$data);
		self::send("Anybot <$from>",$to,$title,$html);
    }
    
}

?>
