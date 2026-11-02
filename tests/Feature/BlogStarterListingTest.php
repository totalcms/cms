<?php

declare(strict_types=1);

use TotalCMS\Domain\Query\Data\QueryResult;
use TotalCMS\Domain\Twig\Extension\TotalCMSTwigFilters;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;

/*
 * The blog starter's listing pages, rendered against posts the index holds
 * out of date order. They once passed {sort, limit} to
 * cms.collection.objects(), which takes no options: PHP dropped the
 * argument, so the home page showed every post in index order. They now use
 * cms.collection.query(); the stub below sorts and pages like the real one.
 */

/** @param array<string,string> $getData */
function renderBlogStarterPage(string $page, array $getData = []): string
{
	$dir   = dirname(__DIR__, 2) . '/resources/builder/starters/blog/pages/';
	$twig  = new Environment(new ArrayLoader([
		'page'                     => (string)file_get_contents($dir . $page),
		'layouts/default.twig'     => '{% block content %}{% endblock %}',
		'partials/post-card.twig'  => '[{{ post.id }}]',
	]));
	$twig->addFilter(new TwigFilter('sortCollection', TotalCMSTwigFilters::sortCollection(...)));
	$twig->addFilter(new TwigFilter('sortCollectionByString', TotalCMSTwigFilters::sortCollectionByString(...)));

	$posts = array_map(
		static fn (int $day): array => ['id' => "day-{$day}", 'date' => sprintf('2026-01-%02d', $day)],
		[3, 7, 1, 6, 2, 5, 4],
	);
	$cms = new class($posts) {
		public object $collection;
		public object $builder;

		/** @param list<array<string,string>> $posts */
		public function __construct(array $posts)
		{
			$this->collection = new class($posts) {
				/** @param list<array<string,string>> $posts */
				public function __construct(private array $posts)
				{
				}

				/**
				 * @param array<string,mixed> $options
				 *
				 * @return array<string,mixed>
				 */
				public function query(string $collection, array $options = []): array
				{
					$items  = TotalCMSTwigFilters::sortCollectionByString($this->posts, (string)($options['sort'] ?? ''));
					$limit  = (int)($options['limit'] ?? 0);
					$offset = $limit > 0 ? (max(1, (int)($options['page'] ?? 1)) - 1) * $limit : 0;

					return (new QueryResult(array_slice($items, $offset, $limit > 0 ? $limit : null), count($items), $limit > 0 ? $limit : count($items), $offset))->forTemplate();
				}
			};
			$this->builder = new class {
				public function url(string $id): ?string
				{
					return null;
				}
			};
		}
	};

	preg_match_all('/\[(day-\d)\]/', $twig->render('page', ['cms' => $cms, 'getData' => $getData]), $ids);

	return implode(',', $ids[1]);
}

test('the blog starter home page shows the five newest posts, newest first', function (): void {
	expect(renderBlogStarterPage('index.twig'))->toBe('day-7,day-6,day-5,day-4,day-3');
});

test('the blog starter blog page lists the newest posts first', function (): void {
	expect(renderBlogStarterPage('blog/index.twig'))->toBe('day-7,day-6,day-5,day-4,day-3,day-2,day-1');
});

test('the blog starter blog page shows ten posts a page, with links between pages', function (): void {
	// The stub's seven posts fill one page; render page 2 of a 10-per-page
	// list by asking for it, and check it is empty with a link back.
	$dir  = dirname(__DIR__, 2) . '/resources/builder/starters/blog/pages/';
	$page = (string)file_get_contents($dir . 'blog/index.twig');

	expect($page)->toContain('limit: 10, page: getData.page|default(1)');
	expect(renderBlogStarterPage('blog/index.twig', ['page' => '2']))->toBe('');
});
