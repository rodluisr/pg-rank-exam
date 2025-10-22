<!DOCTYPE html>
<html>

<head>
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<title>Products - PG Rank Exam (Luis)</title>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="description" content="">
	<meta name="color-scheme" content="light dark">
	<link rel="stylesheet" type="text/css" href="/css/style.css">
	<script src="/js/main.js"></script>
</head>

<body class="masonry-active">
</body>

</html>
<div class="analytics-page section mt-6 container">
	<div class="top row mb-4" style="align-items:center; gap:12px;">
		<a href="/" class="button ghost" aria-label="View Analytics">←</a>
		<h1 class="m-0">Analytics</h1>
	</div>
	<p class="muted">Daily Active Users (unique IPs per minute)</p>
	
	<div class="card p-6">
	  <form id="anaForm" class="row gx-4 wrap" style="align-items:center; gap:12px;">
		<label class="label" for="anaDate">Date</label>
		<input id="anaDate" type="date" class="input" style="max-width:200px">
		<button id="anaReload" type="button" class="button ghost">Reload</button>
	  </form>
	
	  <div class="mt-4" style="width:100%; overflow:auto;">
		<canvas id="anaCanvas" width="1200" height="300" aria-label="DAU chart"></canvas>
	  </div>
	  <p id="anaEmpty" class="muted" hidden>No data for this date.</p>
	</div>
	
</div>
<script src="/js/analytics.js"></script>
