<?php
class RenderDelegate{
	
	/**
	 * get html title value inside <title> tag
	 * 
	 * @param $req: REQ obj
	 * @param $data: data assigned to Render with function assign($k,$v);
 	 * @return : string <TITLE>  
	 * 
	 * @example:
	 * 	//to access controller/action name
	 * 	$controller =$req->getController();
	 * 	$action =$req->getAction();
	 * 	
	 * 	//to access HTTP request params
	 * 	$params = $req->params; //be careful, don't change the value
	 *  
	 *  //to access assigned data
	 *  $data["mykey"];
	 * 
	 */
	static function getTitle($req,$data){
		return APP_NAME;
	}
	
	/**
	 * get html description value inside <meta name="description" content=""> tag
	 *
	 * @param $req: REQ obj
	 * @param $data: data assigned to Render with function assign($k,$v);
	 * @return : string 
	 *
	 */
	static function getDescription($req,$data){
		return "";
	}
	
	/**
	 * get html keywords value inside <meta name="keywords" content=""> tag
	 *
	 * @param $req: REQ obj
	 * @param $data: data assigned to Render with function assign($k,$v);
	 * @return : string splited with ","
	 *
	 */
	static function getKeywords($req,$data){
		return "";
	}
	
	/**
	 * custom tag defination example
	 * remove this if not in use
	 * 
	 * @param $v_str: given that there is a custom tag names 'box', {box 1px solid black} $v_str will be : "1px solid black"
	 * @param $data:data assigned to Render with function assign($k,$v)
	 * @return: string that you want to print to screen.
	 *  
	 */
	static function tag_box($v_str,$data){
		$border = !empty($v_str)? "border:$v_str":"";
		return '<div style="width:100px;height:100px;'.$border.'"></div>';
	}
	
	
}