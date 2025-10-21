<?php
require_once __DIR__ . '/../liber.php';
elog("=== Patch Start ===");

// -----------------------------------------------------------------------------
// 1. Fetch data from API (all pages)
// -----------------------------------------------------------------------------
$categories_url = "https://dummyjson.com/products/categories";
$products_url   = "https://dummyjson.com/products";

// ---- fetch categories ----
$categories = call($categories_url, "GET");
if (is_string($categories)) {
	$decoded = json_decode($categories, true);
	if (json_last_error() === JSON_ERROR_NONE) $categories = $decoded;
	else {
		elog("Failed to decode categories JSON: " . json_last_error_msg());
		$categories = [];
	}
}
if (!is_array($categories)) {
	elog("Failed to fetch categories.");
	exit;
}

// ---- fetch ALL products ----
function fetch_all_products($baseUrl, $pageLimit = 100)
{
	$all = [];
	$skip = 0;
	$total = null;

	do {
		$url = $baseUrl . '?limit=' . (int)$pageLimit . '&skip=' . (int)$skip;
		elog("Fetching products page: limit=$pageLimit skip=$skip");
		$chunk = call($url, "GET");

		if (is_string($chunk)) {
			$tmp = json_decode($chunk, true);
			$chunk = (json_last_error() === JSON_ERROR_NONE) ? $tmp : [];
		}

		$products = $chunk['products'] ?? [];
		$total = $total ?? ($chunk['total'] ?? null);

		if (!$products) break;

		foreach ($products as $p) $all[] = $p;

		$skip += $pageLimit;

		// defensive break
		if ($total && count($all) >= $total) break;
		if ($skip > 10000) break; // safety net
	} while (true);

	return $all;
}

$products = fetch_all_products($products_url, 100);
elog("Total products fetched: " . count($products));
if (empty($products)) {
	elog("No products fetched.");
	exit;
}

// -----------------------------------------------------------------------------
// 2. Sync categories
// -----------------------------------------------------------------------------
elog("Syncing categories...");

function slugify($s)
{
	$s = strtolower(trim($s));
	$s = preg_replace('/\s+/', '-', $s);
	$s = preg_replace('/[^a-z0-9\-]+/', '-', $s);
	$s = preg_replace('/-+/', '-', $s);
	return trim($s, '-');
}

// load current slug→id map
$cats = [];
$slugToId = [];
$existing = db_query("SELECT id, slug FROM luis_categories", []);
foreach ($existing as $r) {
	if (!empty($r['slug'])) $slugToId[strtolower($r['slug'])] = (int)$r['id'];
}

foreach ($categories as $cat) {
	$name = is_array($cat) && isset($cat['name']) ? trim($cat['name']) : trim((string)$cat);
	if ($name === '') continue;
	$slug = is_array($cat) && isset($cat['slug']) ? slugify($cat['slug']) : slugify($name);

	$data = [
		'slug'   => $slug,
		'name'   => $name,
		'status' => 1,
		'upd_t'  => time(),
	];
	$cats[] = $data;

	if (isset($slugToId[$slug])) {
		$data['id'] = $slugToId[$slug];
		db_save('luis_categories', $data);
		elog("↻ Updated category: $name ($slug)");
	} else {
		$data['ins_t'] = time();
		$newId = db_save('luis_categories', $data, true);
		if (!$newId) {
			$found = db_query("SELECT id FROM luis_categories WHERE slug=? LIMIT 1", [$slug]);
			$newId = (int)($found[0]['id'] ?? 0);
		}
		$slugToId[$slug] = $newId;
		elog("➕ Inserted category: $name ($slug) id=$newId");
	}
}
elog("Categories synced\n");

// -----------------------------------------------------------------------------
// 3. Sync products
// -----------------------------------------------------------------------------
elog("Syncing products...");

foreach ($products as $p) {
	$catSlug = isset($p['category']) ? slugify($p['category']) : null;
	elog($catSlug, 'catSlug');
	$category_id = null;

	// get from cats[]; where slug matches
	foreach ($cats as $cat) {
		if ($catSlug && isset($cat['slug']) && $cat['slug'] === $catSlug) {
			// get id
			$found = db_query("SELECT id FROM luis_categories WHERE slug=? LIMIT 1", [$catSlug]);
			$category_id = (int)($found[0]['id'] ?? 0);
			break;
		}
	}
	
	// $category_id = ($catSlug && isset($cats['slug'])) ? $cats['slug'] : null;

	// ---- S3 upload ----
	$image_url = '';
	if (!empty($p['thumbnail'])) {
		$remote = $p['thumbnail'];
		$tmp = tempnam(sys_get_temp_dir(), 'img_');
		$data = @file_get_contents($remote);
		if ($data !== false) file_put_contents($tmp, $data);

		$ext = pathinfo(parse_url($remote, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
		$s3Key = "luis_products/{$p['id']}." . strtolower($ext);

		$uploadedUrl = is_file($tmp)
			? Amazon::s3upload($tmp, $s3Key, [], Conf::$aws_s3_bucket, 'public-read')
			: false;

		@unlink($tmp);
		$image_url = $uploadedUrl ?: $remote;
	}

	// ---- save product ----
	$row = [
		'id'           => (int)$p['id'],
		'category_id'  => $category_id,
		'name'         => $p['title'] ?? '',
		'description'  => $p['description'] ?? '',
		'price'        => $p['price'] ?? 0,
		'stock_qty'    => $p['stock'] ?? 0,
		'status'       => 1,
		'image_url'    => $image_url,
		'data'         => json_encode($p, JSON_UNESCAPED_UNICODE),
		'upd_t'        => time(),
	];

	db_save('luis_products', $row);
	elog("Product synced: {$p['title']} (cat={$catSlug}, cat_id=" . ($category_id ?? 'NULL') . ")");
}

elog("Products synced successfully.");

// -----------------------------------------------------------------------------
// 4. Done
// -----------------------------------------------------------------------------
elog("=== Sync Complete ===");
