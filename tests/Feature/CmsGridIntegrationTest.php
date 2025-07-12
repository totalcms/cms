<?php

use TotalCMS\Domain\Twig\Extension\CmsGridTokenParser;
use TotalCMS\Domain\Twig\Extension\TotalCMSTwigFilters;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

beforeEach(function () {
	$this->sampleObjects = [
		[
			'id'      => '1',
			'title'   => 'First Blog Post',
			'summary' => 'This is a summary of the first blog post with some content.',
			'date'    => '2024-06-15',
			'tags'    => ['PHP', 'Twig'],
			'image'   => ['src' => 'image1.jpg', 'alt' => 'First image'],
		],
		[
			'id'      => '2',
			'title'   => 'Second Blog Post',
			'summary' => 'This is a summary of the second blog post.',
			'date'    => '2024-06-16',
			'tags'    => ['CMS', 'Web'],
			'image'   => ['src' => 'image2.jpg', 'alt' => 'Second image'],
		],
	];
});

test('cmsgrid renders basic grid without errors', function () {
	$template = '{% cmsgrid objects %}
		<h3>{{ object.title }}</h3>
		<p>{{ object.summary }}</p>
	{% endcmsgrid %}';

	$loader = new ArrayLoader(['test' => $template]);
	$twig   = new Environment($loader);
	$twig->addTokenParser(new CmsGridTokenParser());

	$result = $twig->render('test', ['objects' => $this->sampleObjects]);

	expect($result)->toContain('First Blog Post');
	expect($result)->toContain('Second Blog Post');
	expect($result)->toContain('cms-grid');
	expect($result)->toContain('cms-grid-item');
});

test('cmsgrid renders with collection context', function () {
	$template = '{% cmsgrid objects from "blog" %}
		<h3>{{ object.title }}</h3>
		<p>Collection: {{ collection }}</p>
	{% endcmsgrid %}';

	$loader = new ArrayLoader(['test' => $template]);
	$twig   = new Environment($loader);
	$twig->addTokenParser(new CmsGridTokenParser());

	$result = $twig->render('test', ['objects' => $this->sampleObjects]);

	expect($result)->toContain('Collection: blog');
	expect($result)->toContain('First Blog Post');
});

test('cmsgrid renders with CSS classes', function () {
	$template = '{% cmsgrid objects with "blog compact" %}
		<h3>{{ object.title }}</h3>
	{% endcmsgrid %}';

	$loader = new ArrayLoader(['test' => $template]);
	$twig   = new Environment($loader);
	$twig->addTokenParser(new CmsGridTokenParser());

	$result = $twig->render('test', ['objects' => $this->sampleObjects]);

	expect($result)->toContain('cms-grid blog compact');
});

test('cmsgrid renders with custom item tag', function () {
	$template = '{% cmsgrid objects as "article" %}
		<h3>{{ object.title }}</h3>
	{% endcmsgrid %}';

	$loader = new ArrayLoader(['test' => $template]);
	$twig   = new Environment($loader);
	$twig->addTokenParser(new CmsGridTokenParser());

	$result = $twig->render('test', ['objects' => $this->sampleObjects]);

	expect($result)->toContain('<article class="cms-grid-item">');
	expect($result)->toContain('</article>');
});

test('cmsgrid renders with full syntax', function () {
	$template = '{% cmsgrid objects from "products" with "grid wide" as "section" %}
		<h4>{{ object.title }}</h4>
		<p>From: {{ collection }}</p>
	{% endcmsgrid %}';

	$loader = new ArrayLoader(['test' => $template]);
	$twig   = new Environment($loader);
	$twig->addTokenParser(new CmsGridTokenParser());

	$result = $twig->render('test', ['objects' => $this->sampleObjects]);

	expect($result)->toContain('cms-grid grid wide');
	expect($result)->toContain('<section class="cms-grid-item">');
	expect($result)->toContain('From: products');
});

test('cmsgrid works with grid helper methods via mock', function () {
	$template = '{% cmsgrid objects from "blog" %}
		<h3>{{ object.title }}</h3>
		<div class="meta">{{ object.date }}</div>
	{% endcmsgrid %}';

	$loader = new ArrayLoader(['test' => $template]);
	$twig   = new Environment($loader);
	$twig->addTokenParser(new CmsGridTokenParser());

	$result = $twig->render('test', ['objects' => $this->sampleObjects]);

	expect($result)->toContain('2024-06-15');
	expect($result)->toContain('2024-06-16');
});

test('cmsgrid handles empty objects array', function () {
	$template = '{% cmsgrid objects %}
		<h3>{{ object.title }}</h3>
	{% endcmsgrid %}';

	$loader = new ArrayLoader(['test' => $template]);
	$twig   = new Environment($loader);
	$twig->addTokenParser(new CmsGridTokenParser());

	$result = $twig->render('test', ['objects' => []]);

	// Should not render anything for empty array
	expect(trim($result))->toBe('');
});

test('cmsgrid handles null objects', function () {
	$template = '{% cmsgrid objects %}
		<h3>{{ object.title }}</h3>
	{% endcmsgrid %}';

	$loader = new ArrayLoader(['test' => $template]);
	$twig   = new Environment($loader);
	$twig->addTokenParser(new CmsGridTokenParser());

	$result = $twig->render('test', ['objects' => null]);

	// Should not render anything for null
	expect(trim($result))->toBe('');
});

test('cmsgrid renders complex templates', function () {
	$template = '{% cmsgrid objects from "blog" with "blog list" %}
		{% if object.image %}
			<div class="image">Image: {{ object.image.src }}</div>
		{% endif %}
		<h3>{{ object.title }}</h3>
		<p>{{ object.summary }}</p>
		{% if object.tags %}
			<div class="tags">
				{% for tag in object.tags %}
					<span>{{ tag }}</span>{% if not loop.last %}, {% endif %}
				{% endfor %}
			</div>
		{% endif %}
		<small>Collection: {{ collection }}</small>
	{% endcmsgrid %}';

	$loader = new ArrayLoader(['test' => $template]);
	$twig   = new Environment($loader);
	$twig->addTokenParser(new CmsGridTokenParser());

	$result = $twig->render('test', ['objects' => $this->sampleObjects]);

	expect($result)->toContain('First Blog Post');
	expect($result)->toContain('Image: image1.jpg');
	expect($result)->toContain('<span>PHP</span>');
	expect($result)->toContain('<span>Twig</span>');
	expect($result)->toContain('<span>CMS</span>');
	expect($result)->toContain('<span>Web</span>');
	expect($result)->toContain('Collection: blog');
});

test('price filter integration works', function () {
	$products = [
		['name' => 'Product 1', 'price' => 19.99],
		['name' => 'Product 2', 'price' => 29.99],
	];

	$template = '{% cmsgrid products %}
		<h4>{{ object.name }}</h4>
		<span class="price">{{ object.price|price }}</span>
	{% endcmsgrid %}';

	$loader = new ArrayLoader(['test' => $template]);
	$twig   = new Environment($loader);
	$twig->addTokenParser(new CmsGridTokenParser());

	// Add the price filter
	$twig->addFilter(new Twig\TwigFilter('price', [TotalCMSTwigFilters::class, 'price']));

	$result = $twig->render('test', ['products' => $products]);

	expect($result)->toContain('$19.99');
	expect($result)->toContain('$29.99');
	expect($result)->toContain('Product 1');
	expect($result)->toContain('Product 2');
});
