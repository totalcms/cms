<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Adapter;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionEditionService;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Index\Service\IndexSearcher;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

/**
 * Twig sub-adapter for collection and object access.
 *
 * Accessed in Twig as `cms.collection.*`.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
 */
readonly class CollectionTwigAdapter
{
	private LoggerInterface $logger;

	public function __construct(
		private Config $config,
		private CollectionLister $collectionLister,
		private CollectionFetcher $collectionFetcher,
		private CollectionEditionService $collectionEditionService,
		private IndexReader $indexReader,
		private IndexSearcher $indexSearcher,
		private ObjectFetcher $objectFetcher,
		private ObjectUrlBuilder $objectUrlBuilder,
		LoggerFactory $loggerFactory,
	) {
		$this->logger = $loggerFactory->addFileHandler('twig.log')->createLogger('twig');
	}

	/**
	 * Get all accessible collections (filtered by edition).
	 *
	 * @return array<CollectionData>
	 */
	public function list(): array
	{
		$collections = $this->collectionLister->listAllCollections();

		return array_filter(
			$collections,
			fn (CollectionData $c): bool => $this->collectionEditionService->isSchemaAccessible($c->schema)
		);
	}

	/**
	 * Get the number of objects in a collection.
	 * Uses cached collection metadata for fast access.
	 */
	public function objectCount(string $collection): int
	{
		$collectionData = $this->collectionFetcher->fetchCollection($collection);

		if (!$collectionData instanceof CollectionData) {
			return 0;
		}

		return $collectionData->totalObjects;
	}

	/** @return array<string,list<object>> */
	public function byCategory(): array
	{
		$collections = $this->list();
		$categories  = [];
		foreach ($collections as $collection) {
			$category = empty($collection->category) ? 'Collections' : trim(strval($collection->category));
			if (!array_key_exists($category, $categories)) {
				$categories[$category] = [];
			}
			$categories[$category][] = $collection;
		}
		// Sort the categories by key and move the Collections category to the bottom
		uksort($categories, function ($a, $b): int {
			if ($a === 'Collections') {
				return 1;
			}
			if ($b === 'Collections') {
				return -1;
			}

			return strcmp($a, $b);
		});

		return $categories;
	}

	/**
	 * Get a single collection by ID.
	 * Returns empty array if collection doesn't exist or is inaccessible due to edition.
	 *
	 * @return array<string,mixed>
	 */
	public function get(string $collectionId): array
	{
		// Check edition accessibility first
		if (!$this->collectionEditionService->isAccessible($collectionId)) {
			return [];
		}

		$collection = $this->collectionFetcher->fetchCollection($collectionId);

		if (!$collection instanceof CollectionData) {
			return [];
		}

		return $collection->toArray();
	}

	/**
	 * Get URL for an object. Supports templated URLs when full object is provided.
	 *
	 * @param string $collectionId Collection ID
	 * @param string|array<string,mixed> $idOrObject Object ID string or full object array
	 */
	public function objectUrl(string $collectionId, string|array $idOrObject): string
	{
		$collectionData = $this->collectionFetcher->fetchCollection($collectionId);
		if (!$collectionData instanceof CollectionData) {
			return '';
		}

		// If full object array provided, use ObjectUrlBuilder directly
		if (is_array($idOrObject)) {
			return $this->objectUrlBuilder->buildUrl($collectionData, $idOrObject);
		}

		// If template URL but only ID provided, we need the full object
		if ($this->objectUrlBuilder->isTemplateUrl($collectionData->url)) {
			try {
				$object = $this->objectFetcher->fetchObject($collectionId, $idOrObject);

				return $this->objectUrlBuilder->buildUrl($collectionData, $object->toArray());
			} catch (\Exception $e) {
				$this->logger->warning("Could not fetch object '{$idOrObject}' for template URL in '{$collectionId}'", ['error' => $e->getMessage()]);
			}
		}

		// Legacy behavior for non-template URLs or fallback
		return CollectionData::objectUrl($collectionData, $idOrObject);
	}

	/**
	 * @param string|array<string> $propertyPriorities
	 *
	 * @return array<array<string,mixed>>
	 */
	public function search(string $collection, string $query, string|array $propertyPriorities): array
	{
		try {
			$results = $this->indexSearcher->search($collection, $query, $propertyPriorities);
		} catch (\Exception $e) {
			$this->logger->warning("Search failed for collection '{$collection}' with query '{$query}'", ['error' => $e->getMessage()]);

			return [];
		}

		if ($results->isEmpty()) {
			return [];
		}

		return $results->toArray();
	}

	// Get all objects from a collection
	/** @return array<array<string,mixed>> */
	public function objects(string $collection): array
	{
		// if there is an exception, return an empty array
		try {
			$index = $this->indexReader->fetchIndex($collection);
		} catch (\Exception $e) {
			$this->logger->warning("Failed to fetch collection '{$collection}'", ['error' => $e->getMessage()]);

			return [];
		}

		return $index->objects->toArray();
	}

	// Get a list of all values from a property in a collection
	/** @return array<mixed> */
	public function property(string $collection, string $property): array
	{
		$collection = $this->indexReader->fetchIndex($collection);

		return $collection->objects->pluck($property)->flatten()->unique()->toArray();
	}

	// Get an object from a collection
	/** @return array<string,mixed> */
	public function object(string $collection, string $id): array
	{
		// if there is an exception, return an empty array for the template
		try {
			$object = $this->objectFetcher->fetchObject($collection, $id);
		} catch (\Exception $e) {
			$this->logger->warning("Object '{$id}' not found in collection '{$collection}'", ['error' => $e->getMessage()]);

			return [];
		}

		return $object->toArray();
	}

	/**
	 * @SuppressWarnings("PHPMD.ExitExpression")
	 *
	 * @param array<mixed>|string|null $object
	 */
	public function redirectIfNotFound(array|string|null $object = [], string $redirectUrl = ''): void
	{
		$redirectUrl = trim($redirectUrl);
		if (in_array($object, [[], null, ''], true)) {
			$notfound = $redirectUrl !== '' ? $redirectUrl : $this->config->notfound;
			if ($notfound !== '') {
				http_response_code(404);
				header('Location: ' . $notfound);
				exit;
			}
		}
	}

	// -------------------------
	// URL Template Methods
	// -------------------------

	/**
	 * Check if a collection uses a templated URL.
	 */
	public function hasTemplateUrl(string $collectionId): bool
	{
		$collection = $this->collectionFetcher->fetchCollection($collectionId);
		if (!$collection instanceof CollectionData) {
			return false;
		}

		return $this->objectUrlBuilder->isTemplateUrl($collection->url);
	}

	/**
	 * Validate URL template fields against schema index and required fields.
	 * Returns array with 'notIndexed', 'notRequired', and 'prettyUrlDisabled' fields.
	 *
	 * @return array{notIndexed: array<string>, notRequired: array<string>, prettyUrlDisabled: bool}
	 */
	public function validateUrlTemplateFields(string $collectionId): array
	{
		$empty = ['notIndexed' => [], 'notRequired' => [], 'prettyUrlDisabled' => false];

		$collection = $this->collectionFetcher->fetchCollection($collectionId);
		if (!$collection instanceof CollectionData) {
			return $empty;
		}

		if (!$this->objectUrlBuilder->isTemplateUrl($collection->url)) {
			return $empty;
		}

		$result                      = $this->objectUrlBuilder->validateTemplateFields($collection->url, $collection->schema);
		$result['prettyUrlDisabled'] = !$collection->prettyUrl;

		return $result;
	}

	/**
	 * Check if an object's URL has empty segments (missing template data).
	 *
	 * @param string|array<string,mixed> $idOrObject Object ID string or full object array
	 */
	public function objectUrlHasEmptySegments(string $collectionId, string|array $idOrObject): bool
	{
		$url = $this->objectUrl($collectionId, $idOrObject);

		return $url !== '' && $this->objectUrlBuilder->hasEmptySegments($url);
	}

	/**
	 * Get the fields used in a collection's URL template.
	 *
	 * @return array<string>
	 */
	public function urlTemplateFields(string $collectionId): array
	{
		$collection = $this->collectionFetcher->fetchCollection($collectionId);
		if (!$collection instanceof CollectionData) {
			return [];
		}

		if (!$this->objectUrlBuilder->isTemplateUrl($collection->url)) {
			return [];
		}

		return $this->objectUrlBuilder->extractTemplateFields($collection->url);
	}

	/**
	 * Redirect to the canonical object URL if current path doesn't match.
	 * Returns empty string if no redirect needed, otherwise returns redirect HTML.
	 * Query parameters from the current URL are preserved on the redirect.
	 *
	 * @param string $collectionId Collection ID
	 * @param string|array<string,mixed> $idOrObject Object ID or full object data
	 * @param string $method Redirect method: 'header' (HTTP redirect), 'meta' (meta refresh), 'js' (JavaScript), or 'both' (meta+js)
	 * @param int $httpStatus HTTP status code (301 permanent, 302 temporary) - used with 'header' method
	 *
	 * @return string HTML for redirect, or empty string if no redirect needed (header method exits immediately)
	 *
	 * @SuppressWarnings("PHPMD.Superglobals")
	 * @SuppressWarnings("PHPMD.ExitExpression")
	 */
	public function redirectToCanonicalUrl(
		string $collectionId,
		string|array $idOrObject,
		string $method = 'header',
		int $httpStatus = 301,
	): string {
		// Only redirect for pretty URLs - query string URLs don't need canonical redirects
		$collection = $this->collectionFetcher->fetchCollection($collectionId);
		if (!$collection instanceof CollectionData || !$collection->prettyUrl) {
			return '';
		}

		$canonicalUrl = $this->objectUrl($collectionId, $idOrObject);
		if ($canonicalUrl === '') {
			return ''; // No URL configured, no redirect needed
		}

		// Compare paths (ignore query strings like UTM params)
		$requestUri  = $_SERVER['REQUEST_URI'] ?? '';
		$currentPath = strtok($requestUri, '?') ?: '';

		$canonicalNormalized = rtrim(strtolower($canonicalUrl), '/');
		$currentNormalized   = rtrim(strtolower($currentPath), '/');

		if ($canonicalNormalized === $currentNormalized) {
			return ''; // Already on canonical URL, no redirect needed
		}

		// Preserve query parameters from the current request (except 'id' which is now in the URL path)
		$queryString = parse_url((string)$requestUri, PHP_URL_QUERY);
		if (!in_array($queryString, [null, false, ''], true)) {
			parse_str($queryString, $params);
			unset($params['id']);
			if ($params !== []) {
				$canonicalUrl .= '?' . http_build_query($params);
			}
		}

		// HTTP header redirect (best for SEO, must be called before any output)
		if ($method === 'header') {
			if (!headers_sent()) {
				http_response_code($httpStatus);
				header('Location: ' . $canonicalUrl);
				exit;
			}
			// Fall back to meta refresh if headers already sent
			$method = 'meta';
		}

		$html = '';

		if ($method === 'meta' || $method === 'both') {
			$html .= sprintf(
				'<meta http-equiv="refresh" content="0;url=%s">',
				htmlspecialchars($canonicalUrl, ENT_QUOTES)
			);
		}

		if ($method === 'js' || $method === 'both') {
			$html .= sprintf(
				'<script>window.location.replace(%s);</script>',
				json_encode($canonicalUrl)
			);
		}

		// Add canonical link tag for SEO
		$html .= sprintf(
			'<link rel="canonical" href="%s">',
			htmlspecialchars($canonicalUrl, ENT_QUOTES)
		);

		return $html;
	}

	/**
	 * Get the canonical (absolute) URL for an object.
	 * Useful for generating <link rel="canonical"> tags, redirects, and SEO.
	 * Always returns an absolute URL with scheme and domain.
	 *
	 * @param string $collectionId Collection ID
	 * @param string|array<string,mixed> $idOrObject Object ID or full object data
	 *
	 * @return string The absolute canonical URL
	 *
	 * @SuppressWarnings("PHPMD.Superglobals")
	 */
	public function canonicalObjectUrl(string $collectionId, string|array $idOrObject): string
	{
		$url = $this->objectUrl($collectionId, $idOrObject);

		if ($url === '') {
			return $url;
		}

		// Make URL absolute
		if (!str_starts_with($url, 'http')) {
			$scheme = $_SERVER['REQUEST_SCHEME'] ?? 'https';
			$host   = $this->config->domain;
			$url    = $scheme . '://' . $host . $url;
		}

		return $url;
	}

	/**
	 * Format a URL as a pretty URL (strip .php, ensure trailing slash).
	 *
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 * @SuppressWarnings("PHPMD.Superglobals")
	 */
	public function prettyUrl(string $path, bool $addDomain = false): string
	{
		$domain = $this->config->domain;
		$home   = 'https://' . $domain;
		$url    = $addDomain ? $home . $path : $path;

		// just in case someone puts in the full url and not just the path
		if (str_starts_with($path, $home)) {
			$url = $path;
		}
		if (str_ends_with($path, 'php')) {
			$url = dirname($url);
		}
		if (!str_ends_with($url, '/')) {
			$url .= '/';
		}

		return $url;
	}
}
