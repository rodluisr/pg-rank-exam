<?php

class OAuthDelegate{
	
	/**
	 * For server-side oauth callback only 
	 * Login check delegate method with request parameters from opensocial platforms.
	 * SQL/Cmd Injection is checked via Core::dispatch
	 *
	 * @param $schema: database schema name to handle this type of request. @see Consts::$auth_patterns
	 * @param $remoteDomain: remote opensocial platform domain.
	 * @param $params: other http request parameters & datas
	 *
	 * @return : [
	 * 	error => 0=success|1=wrong pass|2=not exists,
	 * 	user => user data from db
	 * ]
	 * */
	static public function login($schema, $params){
		
	}
	
	static function logout(){
	
	}
	
	
}