<?php

function get($params) {
	$_REQUEST['page'] = 'top';
	render_html('top', [
	 	'page_script' => '<script src="/js/top.js" defer></script>'
	]);
}
