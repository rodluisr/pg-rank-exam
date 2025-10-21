<?php

function get($q) {
	elog("GET /api/products", $q);

	// ----- single by id -----
	if (!empty($q['id'])) {
		$product = db_find1st('luis_products', [
			'where'  => ['id' => (int)$q['id']],
			'fields' => ['id', 'name', 'description', 'price', 'stock_qty', 'status', 'image_url', 'category_id', 'upd_t']
		]);
		render_json(['result' => $product ?: []]);
		return;
	}

	// ----- list with optional category + search + pagination -----
	$category = $q['category'] ?? null;                 // slug
	$term     = trim((string)($q['q'] ?? ''));          // search term
	$limit    = min(100, max(1, (int)($q['limit'] ?? 10)));
	$page     = max(1, (int)($q['page'] ?? 1));
	$offset   = ($page - 1) * $limit;

	$whereParts = ["status = ?"];
	$params     = [1];

	// category filter (slug -> id)
	if (!empty($category)) {
		$cat = db_query("SELECT id FROM luis_categories WHERE slug = ? LIMIT 1", [strtolower($category)]);
		if (empty($cat)) {
			render_json(['result' => []]);
			return;
		}
		$whereParts[] = "category_id = ?";
		$params[]     = (int)$cat[0]['id'];
	}

	// search by name
	if ($term !== '') {
		$whereParts[] = "name LIKE ?";
		$params[]     = "%{$term}%";
		// $whereParts[] = "name LIKE CONCAT('%', ?, '%')"; $params[] = $term;
	}

	$whereSql = implode(' AND ', $whereParts);

	$sql = "SELECT id,name,description,price,stock_qty,status,image_url,category_id,upd_t
        FROM luis_products
        WHERE $whereSql
        ORDER BY upd_t DESC, id DESC
        LIMIT $limit OFFSET $offset
    ";

	$rows = db_query($sql, $params);
	render_json(['result' => $rows]);
}

function post($q) {
	if (empty($q)) {
        $raw = file_get_contents('php://input');
        $j = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($j)) {
            $q = $j;
        }
    }

    list($name, $description, $price, $stock_qty) = check_params($q, 'name,description,price,stock_qty');

    $name        = trim((string)$name);
    $description = trim((string)$description);
    $price       = (float)$price;
    $stock_qty   = (int)$stock_qty;
    $categoryStr = trim((string)($q['category'] ?? ''));   // slug or name
    $thumbnail   = trim((string)($q['thumbnail'] ?? ''));

    if ($name === '' || $description === '' || $price <= 0 || $stock_qty < 0 || $categoryStr === '') {
        render_json(['error' => 'Invalid input'], 400);
        return;
    }

    // Resolve category_id by slug OR lowercase(name)
    $slug = strtolower(preg_replace('/[^a-z0-9\-]+/i','-',preg_replace('/\s+/', '-', $categoryStr)));
    $cat = db_query("SELECT id FROM luis_categories WHERE slug=? OR LOWER(name)=? LIMIT 1", [$slug, strtolower($categoryStr)]);
    if (empty($cat)) {
        render_json(['error' => 'Unknown category'], 400);
        return;
    }
    $category_id = (int)$cat[0]['id'];

    $now = time();
    $product_id = db_save('luis_products', [
        'name'        => $name,
        'description' => $description,
        'price'       => $price,
        'stock_qty'   => $stock_qty,
        'category_id' => $category_id,
        'status'      => 1,
        'image_url'   => $thumbnail,   // URL already from /api/uploads
        'data'        => null,
        'ins_t'       => $now,
        'upd_t'       => $now,
    ], /*returnId*/ true);

    if (!$product_id) {
        render_json(['error' => 'Failed to create product'], 500);
        return;
    }

    render_json(['result' => [
        'id'        => (int)$product_id,
        'image_url' => $thumbnail,
        'category_id' => $category_id,
    ]]);
}