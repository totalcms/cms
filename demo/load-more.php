<?php
require_once __DIR__ . '/../dist/autoload.php';
$totalcms = new TotalCMS\TotalCMS();
?>

<!DOCTYPE html>
<html lang="en">
	<head>
		<meta charset="utf-8">
		<meta http-equiv="X-UA-Compatible" content="IE=edge">
		<title>Load More Demo - Total CMS 3</title>
		<meta name="description" content="Demonstrating the HTMX-powered load more feature in Total CMS 3">
		<meta name="viewport" content="width=device-width, initial-scale=1">

		<link rel="stylesheet" href="demo.css" />
		<link rel="stylesheet" href="load-more.css" />

		<!-- Total CMS Content Assets -->
		<link rel="stylesheet" href="{{ cms.api }}/assets/icons.css?v={{ cms.version }}"/>
		<link rel="stylesheet" href="{{ cms.api }}/assets/content.css?v={{ cms.version }}"/>

		<!-- HTMX (required for load-more) -->
		<script src="{{ cms.api }}/assets/htmx.min.js"></script>
	</head>
	<body>

		<!-- Header -->
		<header class="site-header">
			<div class="container">
				<h1>Load More Demo</h1>
				<p>HTMX-powered progressive content loading with Total CMS 3</p>
			</div>
		</header>

		<div class="container">

			<!-- Example 1: Infinite Scroll (revealed trigger) -->
			<section class="section">
				<div class="section-header">
					<h2>Infinite Scroll</h2>
					<p>Posts load automatically as you scroll down. Uses <code>trigger: 'revealed'</code> — the default behavior.</p>
				</div>

				<div class="load-more-grid" id="infinite-scroll">
					{% set posts = cms.objects('blog') | slice(0, 3) %}
					{% for post in posts %}
						{% include 'demo/blog-card.html' with {object: post} %}
					{% endfor %}

					{{ cms.render.loadMore('blog', {
						template: 'demo/blog-card',
						limit: 3,
						sort: 'date:desc',
						trigger: 'revealed'
					}) }}
				</div>
			</section>

			<!-- Example 2: Click to Load (button trigger) -->
			<section class="section">
				<div class="section-header">
					<h2>Click to Load</h2>
					<p>Users click a button to load the next batch. Uses <code>trigger: 'click'</code> with a custom label.</p>
				</div>

				<div class="load-more-list" id="click-to-load">
					{% set posts = cms.objects('blog') | slice(0, 3) %}
					{% for post in posts %}
						{% include 'demo/blog-row.html' with {object: post} %}
					{% endfor %}

					{{ cms.render.loadMore('blog', {
						template: 'demo/blog-row',
						limit: 3,
						sort: 'date:desc',
						trigger: 'click',
						label: 'Show More Posts'
					}) }}
				</div>
			</section>

			<!-- How It Works -->
			<section class="section">
				<div class="section-header">
					<h2>How It Works</h2>
					<p>The load-more system uses HTMX to progressively load content without full page reloads.</p>
				</div>

				<div class="how-it-works">
					<div class="step">
						<div class="step-number">1</div>
						<div class="step-content">
							<h3>Server renders page 1</h3>
							<p>Your Twig template renders the initial batch of items using <code>cms.objects()</code> with a slice.</p>
							<pre><code>&#123;% set posts = cms.objects('blog') | slice(0, 3) %&#125;
&#123;% for post in posts %&#125;
    &#123;% include 'demo/blog-card.html' with &#123;object: post&#125; %&#125;
&#123;% endfor %&#125;</code></pre>
						</div>
					</div>

					<div class="step">
						<div class="step-number">2</div>
						<div class="step-content">
							<h3>Output the HTMX trigger</h3>
							<p><code>cms.render.loadMore()</code> outputs an HTMX element that fetches page 2 when triggered.</p>
							<pre><code>&#123;&#123; cms.render.loadMore('blog', &#123;
    template: 'demo/blog-card',
    limit: 3,
    sort: 'date:desc',
    trigger: 'revealed'
&#125;) &#125;&#125;</code></pre>
						</div>
					</div>

					<div class="step">
						<div class="step-number">3</div>
						<div class="step-content">
							<h3>HTMX chains the rest</h3>
							<p>Each response includes rendered items plus the next trigger element. The chain continues until all items are loaded.</p>
						</div>
					</div>

					<div class="options-table">
						<h3>Available Options</h3>
						<table>
							<thead>
								<tr>
									<th>Option</th>
									<th>Default</th>
									<th>Description</th>
								</tr>
							</thead>
							<tbody>
								<tr><td><code>template</code></td><td><em>required</em></td><td>Twig template path for each item</td></tr>
								<tr><td><code>limit</code></td><td>20</td><td>Items per page (max 100)</td></tr>
								<tr><td><code>sort</code></td><td>—</td><td>Sort rule, e.g. <code>date:desc</code></td></tr>
								<tr><td><code>include</code></td><td>—</td><td>Include filter, e.g. <code>featured:true</code></td></tr>
								<tr><td><code>exclude</code></td><td>—</td><td>Exclude filter, e.g. <code>draft:true</code></td></tr>
								<tr><td><code>search</code></td><td>—</td><td>Full-text search query</td></tr>
								<tr><td><code>trigger</code></td><td>revealed</td><td><code>revealed</code> (scroll) or <code>click</code> (button)</td></tr>
								<tr><td><code>label</code></td><td>Load More</td><td>Button label for click triggers</td></tr>
								<tr><td><code>class</code></td><td>—</td><td>Extra CSS classes on the trigger element</td></tr>
							</tbody>
						</table>
					</div>
				</div>
			</section>

		</div>

		<!-- Footer -->
		<footer class="site-footer">
			<div class="container">
				<p>&copy; 2025 Total CMS 3 • Load More Demo</p>
				<p style="margin-top: 0.5rem; font-size: 0.875rem;">
					Built with Total CMS {{ cms.version }} + HTMX •
					<a href="index.php" style="color: white; text-decoration: underline;">Main Demo</a> •
					<a href="{{ cms.api }}/admin" style="color: white; text-decoration: underline;">Admin Panel</a>
				</p>
			</div>
		</footer>

		<!-- Total CMS Content Scripts -->
		<script type="module" src="{{ cms.api }}/assets/content.js?v={{ cms.version }}"></script>
	</body>
</html>

<?php echo $totalcms->processBufferMacros(); ?>
