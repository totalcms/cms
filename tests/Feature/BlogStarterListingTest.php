<?php

declare(strict_types=1);

use TotalCMS\Domain\Twig\Extension\TotalCMSTwigFilters;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;

/*
 * The blog starter's listing pages, rendered against posts the index holds
 * out of date order. They once passed {sort, limit} to
 * cms.collection.objects(), which takes no options: PHP dropped the
 * argument, so the home page showed every post in index order.
 */

function renderBlogStarterPage(string $page): string
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

				/** @return list<array<string,string>> */
				public function objects(string $collection): array
				{
					return $this->posts;
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

	preg_match_all('/\[(day-\d)\]/', $twig->render('page', ['cms' => $cms]), $ids);

	return implode(',', $ids[1]);
}

test('the blog starter home page shows the five newest posts, newest first', function (): void {
	expect(renderBlogStarterPage('index.twig'))->toBe('day-7,day-6,day-5,day-4,day-3');
});

test('the blog starter blog page lists every post, newest first', function (): void {
	expect(renderBlogStarterPage('blog/index.twig'))->toBe('day-7,day-6,day-5,day-4,day-3,day-2,day-1');
});
