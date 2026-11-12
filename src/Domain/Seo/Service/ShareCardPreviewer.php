<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Seo\Service;

use TotalCMS\Domain\Builder\Data\PageData;
use TotalCMS\Domain\Builder\Data\RouteMatch;
use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Builder\Service\PageRouter;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Seo\Data\RenderedShareHead;
use TotalCMS\Domain\Seo\Data\SeoFields;
use TotalCMS\Domain\Seo\Data\ShareCard;
use TotalCMS\Domain\Seo\Data\ShareCardSection;
use TotalCMS\Domain\Twig\Service\TwigEngine;

/**
 * The Share Cards utility: every share card on a Site Builder site, a section
 * at a time and a chunk at a time, so a large site never runs one long request.
 *
 * Sections are the Site Builder pages (static routes only: a dynamic route
 * cannot be listed, and a redirect has no card), then each collection the
 * Site Builder serves: a pretty URL and a `pages/{collection}.twig`
 * template. A collection with a URL but no template, such as one a Stacks
 * site serves, is named as skipped. Drafts are listed: before launch they
 * are what is about to go live.
 */
readonly class ShareCardPreviewer
{
	public const CHUNK = 25;

	public function __construct(
		private BuilderConfigService $builderConfig,
		private IndexReader $indexReader,
		private CollectionLister $collectionLister,
		private PageRouter $router,
		private ObjectUrlBuilder $urlBuilder,
		private TwigEngine $twig,
		private ShareCardRenderer $renderer,
		private SeoSettingsLoader $seoSettings,
	) {
	}

	public function hasBuilder(): bool
	{
		return $this->builderConfig->pagesCollectionExists();
	}

	/** @return array{sections:list<ShareCardSection>,skipped:list<string>} */
	public function sections(): array
	{
		if (!$this->hasBuilder()) {
			return ['sections' => [], 'skipped' => []];
		}

		$pagesId  = $this->builderConfig->getPagesCollectionId();
		$sections = [new ShareCardSection($pagesId, 'Pages', ShareCardSection::PAGES, count($this->pages()), 'collections/seo-site/seo-site')];
		$skipped  = [];

		foreach ($this->servedCollections() as $collection) {
			if (!$this->twig->templateExists('pages/' . $collection->id . '.twig')) {
				$skipped[] = $collection->id;
				continue;
			}

			$sections[] = new ShareCardSection($collection->id, $collection->name, ShareCardSection::COLLECTION, count($this->objectPaths($collection)), 'collections/' . rawurlencode($collection->id) . '/edit');
		}

		return ['sections' => $sections, 'skipped' => $skipped];
	}

	/**
	 * One chunk of a section's cards, and the offset of the next chunk.
	 *
	 * @throws \DomainException For a section that is not a page list or a served collection
	 *
	 * @return array{cards:list<ShareCard>,next:?int}
	 */
	public function chunk(string $section, int $offset, int $limit = self::CHUNK): array
	{
		$offset = max(0, $offset);
		$cards  = [];

		if ($this->hasBuilder() && $section === $this->builderConfig->getPagesCollectionId()) {
			$pages = $this->pages();
			foreach (array_slice($pages, $offset, $limit) as $page) {
				$cards[] = $this->card($this->router->matchForPage($page), $page->route, '', 'builder/page/' . rawurlencode($page->id));
			}

			return ['cards' => $cards, 'next' => $offset + $limit < count($pages) ? $offset + $limit : null];
		}

		$collection = $this->servedCollection($section);
		$paths      = $this->objectPaths($collection);
		foreach (array_slice($paths, $offset, $limit, true) as $id => $path) {
			$match = $this->router->matchForObject($collection, (string)$id);
			if ($match instanceof RouteMatch) {
				$cards[] = $this->card($match, $path, $collection->id, 'collections/' . rawurlencode($collection->id) . '/' . rawurlencode((string)$id));
			}
		}

		return ['cards' => $cards, 'next' => $offset + $limit < count($paths) ? $offset + $limit : null];
	}

	private function card(RouteMatch $match, string $path, string $collection, string $editUrl): ShareCard
	{
		$head     = $this->renderer->render($match, $path, $collection);
		$settings = $this->seoSettings->load();
		$record   = $match->pageData;

		return new ShareCard(
			path: $path,
			shareUrl: $settings->absolute($path),
			imageSrc: $this->local($head, $settings->baseUrl),
			domain: $this->host($settings->baseUrl),
			editUrl: $editUrl,
			draft: (bool)($record['draft'] ?? false),
			noindex: SeoFields::fromArray(is_array($record['seo'] ?? null) ? $record['seo'] : [])->noindex,
			head: $head,
		);
	}

	/**
	 * The share image to show. One on the site's own address is loaded from
	 * this install (its path and query), since before launch that address may
	 * not serve it yet; one on any other host (a CDN, a bucket) is left alone.
	 */
	private function local(RenderedShareHead $head, string $baseUrl): string
	{
		$host = strtolower((string)parse_url($head->ogImage, PHP_URL_HOST));
		if ($head->ogImage === '' || ($host !== '' && $host !== $this->host($baseUrl))) {
			return $head->ogImage;
		}

		$path  = (string)parse_url($head->ogImage, PHP_URL_PATH);
		$query = (string)parse_url($head->ogImage, PHP_URL_QUERY);

		return $path . ($query !== '' ? '?' . $query : '');
	}

	private function host(string $url): string
	{
		return strtolower((string)parse_url($url, PHP_URL_HOST));
	}

	/**
	 * The Site Builder pages with a card: a template, a static route, and no redirect.
	 *
	 * @return list<PageData>
	 */
	private function pages(): array
	{
		$pages = [];
		foreach ($this->indexReader->fetchIndex($this->builderConfig->getPagesCollectionId())->objects as $entry) {
			$page = new PageData($entry);
			if ($page->template === '' || $page->route === '' || str_contains($page->route, '{') || ($page->status >= 300 && $page->status < 400)) {
				continue;
			}
			$pages[] = $page;
		}

		usort($pages, static fn (PageData $a, PageData $b): int => strcmp($a->route, $b->route));

		return $pages;
	}

	/**
	 * Collections the router serves: a URL with pretty URLs on, sorted by name.
	 *
	 * @return list<CollectionData>
	 */
	private function servedCollections(): array
	{
		$pagesId     = $this->builderConfig->getPagesCollectionId();
		$collections = array_values(array_filter(
			$this->collectionLister->listAllCollections(),
			static fn (CollectionData $c): bool => $c->id !== $pagesId && $c->url !== '' && $c->prettyUrl,
		));

		usort($collections, static fn (CollectionData $a, CollectionData $b): int => strnatcasecmp($a->name, $b->name));

		return $collections;
	}

	private function servedCollection(string $id): CollectionData
	{
		foreach ($this->servedCollections() as $collection) {
			if ($collection->id === $id && $this->twig->templateExists('pages/' . $collection->id . '.twig')) {
				return $collection;
			}
		}

		throw new \DomainException('Not a Share Cards section: ' . $id);
	}

	/**
	 * Each object's path, keyed by id, in index order; drafts included.
	 *
	 * @return array<string,string>
	 */
	private function objectPaths(CollectionData $collection): array
	{
		$paths = [];
		foreach ($this->indexReader->fetchIndex($collection->id)->objects as $object) {
			$url = $this->urlBuilder->buildUrl($collection, $object);
			if ($url === '' || $this->urlBuilder->hasEmptySegments($url)) {
				continue;
			}
			$paths[(string)($object['id'] ?? '')] = str_starts_with($url, 'http') ? (string)parse_url($url, PHP_URL_PATH) : '/' . ltrim($url, '/');
		}

		unset($paths['']);

		return $paths;
	}
}
