<?php
// GET /api/categories
// Optional params:
//   - id=123         → single by id
//   - slug=beauty    → single by slug
//   - status=1|0     → filter (default 1)
//   - page, limit    → pagination for list (optional)

function get($q) {
    elog("GET /api/categories", $q);

    if (!empty($q['id'])) {
        $row = db_find1st('luis_categories', [
            'where'  => ['id' => (int)$q['id']],
            'fields' => ['id','slug','name','status','sort','upd_t']
        ]);
        render_json(['result' => $row ?: []]);
        return;
    }

    if (!empty($q['slug'])) {
        $row = db_find1st('luis_categories', [
            'where'  => ['slug' => strtolower($q['slug'])],
            'fields' => ['id','slug','name','status','sort','upd_t']
        ]);
        render_json(['result' => $row ?: []]);
        return;
    }

    // list
    $status = isset($q['status']) ? (int)$q['status'] : 1; // default active only
    $limit  = max(1, min(500, (int)($q['limit'] ?? 100)));
    $page   = max(1, (int)($q['page'] ?? 1));
    $offset = ($page - 1) * $limit;

    $rows = db_find('luis_categories', [
        'where'  => ['status' => $status],
        'fields' => ['id','slug','name','status','sort','upd_t'],
        'order'  => 'sort ASC, name ASC',
        'limit'  => $limit,
        'offset' => $offset,
    ]);

    elog("categories list count=" . count($rows));
    render_json(['result' => $rows]);
}