<?php

function get($params) {
    $_REQUEST['page'] = 'analytics';
    render_html('analytics', ['user' => $params['user'] ?: 'Guest']);
}
