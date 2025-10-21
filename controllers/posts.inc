<?php

function get($params) {
	$_REQUEST['page'] = 'posts';
	render_html('posts', ['id' => $params['@id'], 'title' => 'Lorem ipsum']);
}

/**
@usage : access /posts/json from browser
*/
function json($params) {
	$res = db_find('posts', $params);
	if($params["id"] && !empty($res))
		render_json($res[0]);
	else
		render_json($res);
}