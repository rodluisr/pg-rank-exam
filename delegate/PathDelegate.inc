<?php

class PathDelegate{
	
	/**
	 * Delegate method to rewrite URI, 
	 * 
	 * @param $uri: the original relative path
	 * @return : a new path you want to redirect. false or empty leads to a 400 error
	 * @example:
	 * 	//if you want to rewrite all '/' requests to '/myapp/'
	 * 	return $uri=="/"|| $uri=="" ? "/myapp/":$uri;
	 *
	 * @example:
	 * 	if($uri=="/admin/")
	 *	 	return "/admin/top/get";
	 * 
	 */
	static function rewriteURI($uri){
        if(!Consts::$session_enable) {
            head('Cache-Control','no-cache,must-revalidate');   
            head('Pragma','no-cache');   
            head('Expires','0');
        } 
		return $uri;	
	}
	
}