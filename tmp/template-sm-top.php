<!DOCTYPE html>
<html>

<head>
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<title>PG Rank Exam</title>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	<meta name="description" content="">
	<meta name="color-scheme" content="light dark">
	<link rel="stylesheet" type="text/css" href="/css/style.css">
	<script src="/js/main.js"></script>
</head>

<body>
	<div class="row">
		<button class="button" data-action="toggleTheme">Toggle theme</button>
		<button class="button outline" data-action="setLight">Light</button>
		<button class="button outline" data-action="setDark">Dark</button>
	</div>
</body>

</html><?= $LBR__page_script ?>

<div class="container section grid cols-12 gap-6 md-cols-12">
	<!-- Mobile hamburger -->
	<button class="hamburger show-sm" data-toggle="sidebar" aria-label="Open categories" aria-expanded="false"
		aria-controls="side-cats">
		<span></span><span></span><span></span>
		<!-- <span class="hamburger-label">Categories</span> -->
	</button>
	
	<!-- Categories sidebar -->
	<aside class="card p-6" style="grid-column: span 12; max-width:260px" aria-label="Categories">
		<h2 class="mb-4">Categories</h2>
		<ul id="catList" class="list list--divided" role="menu" aria-orientation="vertical">
			<!-- Default “All” -->
			<li>
				<a href="#" class="cat-link active" data-cat="all" role="menuitem" aria-current="true">All</a>
			</li>
			<!-- ... -->
		</ul>
	</aside>

	<!-- Products -->
	<main id="products" class="grid gap-6" style="grid-column: span 12;">
		<div id="productsLoading" class="muted" aria-live="polite" hidden>Loading…</div>
		<div id="productsEmpty" class="muted" hidden>No products found.</div>
		<div id="productsList" class="grid auto gap-5" aria-live="polite"></div>
	</main>
</div>

<!-- Templates -->
<template id="tpl-product-card">
	<div class="card p-6">
		<div class="row wrap gx-4">
			<img class="p-img" alt="" style="max-width:140px;border-radius:10px;border:1px solid var(--border)">
			<div class="p-body" style="min-width:240px">
				<h2 class="p-name mb-4" style="margin-top:0"></h2>
				<p class="p-desc muted"></p>
				<p class="p-meta mt-4"><strong class="p-price"></strong><span class="p-stock"></span></p>
			</div>
		</div>
	</div>
</template>

<template id="tpl-cat-item">
	<li>
		<a href="#" class="cat-link" role="menuitem"></a>
	</li>
</template>