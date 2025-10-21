<?php

class AuthDelegate{
	/**
	* @return int: 0~N, which means current user's group ID
	*/
	static function group(){
		if (session_status() == PHP_SESSION_ACTIVE){//using session
			if(!empty($_SESSION['userId']))
				return 1;
			if(!empty($_SESSION['adminId']))
				return 2;
		}else{//request level auth
			/*
			 add your Request level auth process here
			 */
		}
		return 0;
	}
	
}
