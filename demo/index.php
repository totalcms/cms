<?php
require_once __DIR__ . '/../dist/autoload.php';
$totalcms = new TotalCMS\TotalCMS();
?>

<!DOCTYPE html>
<html lang="en">
	<head>
		<meta charset="utf-8">
		<meta http-equiv="X-UA-Compatible" content="IE=edge">
		<title>{{ cms.text('demoheader') }} - Demo Site</title>
		<meta name="description" content="A comprehensive demonstration of Total CMS 3 features and capabilities">
		<meta name="viewport" content="width=device-width, initial-scale=1">

		<link rel="stylesheet" href="demo.css" />

		<!-- Total CMS Content -->
		<link rel="stylesheet" href="{{ cms.api }}/assets/icons.css?v={{ cms.version }}"/>
		<link rel="stylesheet" href="{{ cms.api }}/assets/content.css?v={{ cms.version }}"/>
		<link rel="stylesheet" href="{{ cms.api }}/assets/cms-grid.css?v={{ cms.version }}"/>
		<link rel="stylesheet" href="{{ cms.api }}/assets/gallery.css?v={{ cms.version }}"/>
		<link rel="stylesheet" href="{{ cms.api }}/assets/pagination.css?v={{ cms.version }}"/>
		<link rel="preload" as="script" href="{{ cms.api }}/assets/content.js?v={{ cms.version }}" />
		<link rel="preload" as="script" href="{{ cms.api }}/assets/gallery.js?v={{ cms.version }}" />

		<!-- Load is using Total CMS Forms/Admin -->
		<link rel="stylesheet" href="{{ cms.api }}/assets/admin.css?v={{ cms.version }}"/>
		<link rel="preload" as="script" href="{{ cms.api }}/assets/admin.js?v={{ cms.version }}" />
	</head>
	<body>

		<!-- Header -->
		<header class="site-header">
			<div class="container">
				<h1>{{ cms.text('demoheader') }}</h1>
				<p>A comprehensive demonstration of Total CMS 3 features and capabilities</p>
			</div>
		</header>

		<div class="container">

			<!-- Hero Section -->
			<section class="hero">
				<h2>Welcome to {{ cms.text('demoname') }}</h2>
				<p>Explore the power and flexibility of Total CMS 3 with this interactive demo.</p>
				<div class="hero-image">
					{{ cms.image('demoimage', {w: 1200, h: 400, fit: 'crop'}) }}
				</div>
			</section>

			<!-- Featured Blog Posts -->
			<section class="section">
				<div class="section-header">
					<h2>Featured Posts</h2>
					<p>Check out our latest featured articles and updates</p>
				</div>

				<div class="featured-posts">
					{% set featuredPosts = cms.objects('blog', {featured: true}) | slice(0, 3) %}
					{% for post in featuredPosts %}
					<article class="post-card">
						<div class="post-card-image">
							{% if post.image %}
								{{ cms.image(post.id, {w: 400, h: 200, fit: 'crop'}, {collection: 'blog'}) }}
							{% endif %}
						</div>
						<div class="post-card-content">
							<h3 class="post-card-title">{{ post.title }}</h3>
							<div class="post-card-meta">
								<span>By {{ post.author }}</span>
								<span>{{ post.date | date('M j, Y') }}</span>
							</div>
							<div class="post-card-excerpt">
								{{ post.summary | striptags | truncate(150) }}
							</div>
							{% if post.tags | length > 0 %}
								<span class="badge">{{ post.tags[0] }}</span>
							{% endif %}
						</div>
					</article>
					{% endfor %}
				</div>
			</section>

			<!-- Products -->
			<section class="section">
				<div class="section-header">
					<h2>Our Products</h2>
					<p>Browse our collection of amazing products</p>
				</div>

				<div class="products-grid">
					{% set products = cms.objects('products') | slice(0, 6) %}
					{% for product in products %}
					<div class="product-card">
						<div class="product-image">
							{% if product.image %}
								{{ cms.image(product.id, {w: 300, h: 200, fit: 'crop'}, {collection: 'products'}) }}
							{% endif %}
						</div>
						<div class="product-info">
							<h3 class="product-name">{{ product.name | title }}</h3>
							<div class="product-price">${{ product.price | number_format(2) }}</div>
							{% if product.tags | length > 0 %}
							<div class="product-tags">
								{% for tag in product.tags | slice(0, 3) %}
									<span class="product-tag">{{ tag }}</span>
								{% endfor %}
							</div>
							{% endif %}
						</div>
					</div>
					{% endfor %}
				</div>
			</section>

			<!-- Gallery -->
			<section class="section">
				<div class="section-header">
					<h2>Photo Gallery</h2>
					<p>Beautiful images from our collection</p>
				</div>

				<div class="gallery-wrapper">
					{{ cms.gallery('demogallery', {
						columns: 3,
						gap: 1.5,
						lightbox: true,
						thumbnailWidth: 400,
						thumbnailHeight: 300,
						thumbnailFit: 'crop'
					}) }}
				</div>
			</section>

			<!-- Blog List with CMS Grid -->
			<section class="section">
				<div class="section-header">
					<h2>All Blog Posts</h2>
					<p>Explore all our articles</p>
				</div>

				<div class="blog-list">
					{% cmsgrid cms.objects('blog') | slice(0, 5) from 'blog' with 'list' %}
						<div class="cms-image">
							{{ cms.image(object.id, {w: 400, h: 400, fit: 'crop'}, {collection: 'blog'}) }}
						</div>
						<div class="cms-content">
							<h3 class="blog-item-title">{{ object.title }}</h3>
							<div class="blog-item-meta">
								<strong>{{ object.author }}</strong> • {{ cms.grid.date(object.date) }}
								{% if object.categories | length > 0 %}
									• Categories: {{ object.categories | join(', ') }}
								{% endif %}
							</div>
							<div class="blog-item-summary">
								{{ object.summary | striptags | truncate(200) }}
							</div>
						</div>
					{% endcmsgrid %}
				</div>
			</section>

			<!-- Field Types Demo -->
			<section class="section">
				<div class="section-header">
					<h2>CMS Field Types</h2>
					<p>Demonstration of various field types available in Total CMS</p>
				</div>

				<div class="fields-demo">
					<div class="field-item">
						<span class="field-label">Text Field</span>
						<div class="field-value">{{ cms.text('demoname') }}</div>
					</div>

					<div class="field-item">
						<span class="field-label">Email Field</span>
						<div class="field-value">{{ cms.email('demoemail') }}</div>
					</div>

					<div class="field-item">
						<span class="field-label">URL Field</span>
						<div class="field-value">
							<a href="{{ cms.url('demourl') }}" target="_blank">{{ cms.url('demourl') }}</a>
						</div>
					</div>

					<div class="field-item">
						<span class="field-label">Number Field</span>
						<div class="field-value">${{ cms.number('demoprice') }}</div>
					</div>

					<div class="field-item">
						<span class="field-label">Date Field</span>
						<div class="field-value">{{ cms.date('demodate') | date('F j, Y') }}</div>
					</div>

					<div class="field-item">
						<span class="field-label">Color Field</span>
						<div class="field-value">
							<span class="color-swatch" style="background-color: {{ cms.color('democolor') | color }}"></span>
							<strong>OKLCH</strong>: {{ cms.color('democolor') | oklch }}
							<strong>RGB</strong>: {{ cms.color('democolor') | rgb }}
							<strong>HSL</strong>: {{ cms.color('democolor') | hsl }}
							<strong>HEX</strong>: {{ cms.color('democolor') | hex }}
						</div>
					</div>

					<div class="field-item">
						<span class="field-label">Toggle Field</span>
						<div class="field-value">
							{% if cms.toggle('demotoggle') %}
								✅ Enabled
							{% else %}
								❌ Disabled
							{% endif %}
						</div>
					</div>

					<div class="field-item">
						<span class="field-label">Styled Text</span>
						<div class="field-value">
							{{ cms.styledtext('demostyledtext') }}
						</div>
					</div>
				</div>
			</section>

			<!-- Code Snippets from Playground -->
			<section class="section">
				<div class="section-header">
					<h2>Code Snippets</h2>
					<p>Reusable Twig snippets from the playground collection</p>
				</div>

				<div class="fields-demo">
					{% set snippets = cms.objects('playground', {category: 'JumpStart Snippets'}) %}
					{% for snippet in snippets | slice(0, 4) %}
					<div class="field-item">
						<span class="field-label">{{ snippet.name }}</span>
						<div class="field-value">
							<pre style="background: #f7fafc; padding: 1rem; border-radius: 8px; overflow-x: auto;"><code>{{ snippet.snippet | escape }}</code></pre>
						</div>
					</div>
					{% endfor %}
				</div>
			</section>

		</div>

		<!-- Footer -->
		<footer class="site-footer">
			<div class="container">
				<p>&copy; 2025 {{ cms.text('demoname') }} • Powered by Total CMS 3</p>
				<p style="margin-top: 0.5rem; font-size: 0.875rem;">
					Built with Total CMS {{ cms.version }} •
					<a href="{{ cms.api }}/admin" style="color: white; text-decoration: underline;">Admin Panel</a>
				</p>
			</div>
		</footer>

		<!-- Total CMS Scripts -->
		<script type="module" src="{{ cms.api }}/assets/content.js?v={{ cms.version }}"></script>
		<script type="module" src="{{ cms.api }}/assets/gallery.js?v={{ cms.version }}"></script>
		<script type="module" src="{{ cms.api }}/assets/admin.js?v={{ cms.version }}"></script>
	</body>
</html>

<?php echo $totalcms->processBufferMacros(); ?>