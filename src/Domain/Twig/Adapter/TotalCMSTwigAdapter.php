<?php

namespace TotalCMS\Domain\Twig\Adapter;

use Odan\Session\PhpSession;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Admin\CollectionTable;
use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Auth\Service\FileAccessManager;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Cache\CacheReporter;
use TotalCMS\Domain\Cache\Service\DevModeManager;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionEditionService;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Collection\Utilities\CollectionSorter;
use TotalCMS\Domain\Collection\Utilities\PaginationGenerator;
use TotalCMS\Domain\ImageWorks\Service\GlideFactory;
use TotalCMS\Domain\ImageWorks\Service\ImageCacheService;
use TotalCMS\Domain\ImageWorks\Service\ImageDimensionCalculator;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Index\Service\IndexSearcher;
use TotalCMS\Domain\JobQueue\Service\JobManager;
use TotalCMS\Domain\License\Service\LicenseStatus;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\DeckCompatibilityChecker;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Schema\Service\SchemaSaver;
use TotalCMS\Domain\Security\Encryption\Cipher;
use TotalCMS\Domain\Template\Repository\TemplateRepository;
use TotalCMS\Domain\Template\Service\TemplateLister;
use TotalCMS\Domain\Twig\Service\DepotBrowserRenderer;
use TotalCMS\Domain\Twig\Service\GridRenderer;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Infrastructure\Diagnostics\LogAnalyzer;
use TotalCMS\Infrastructure\Diagnostics\ServerChecker;
use TotalCMS\Support\Config;
use TotalCMS\Support\VersionData;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\ArrayLoader;

/**
 * Twig Adapter with Total CMS.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PHPMD.TooManyMethods")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
 * @SuppressWarnings("PHPMD.ExcessivePublicCount")
 * @SuppressWarnings("PHPMD.ExcessiveClassLength")
 */
class TotalCMSTwigAdapter
{
	private ?TwigEnvironment $captionTwig = null;
	private readonly LoggerInterface $logger;

	public string $env;
	public string $api;
	public string $dashboard;
	public string $login;
	public string $logout;
	public string $domain;
	public string $clearcache;
	public VersionData $version;
	public string $currentUrl;

	public function __construct(
		private readonly Config $config,
		private readonly IndexReader $indexReader,
		private readonly IndexSearcher $indexSearcher,
		private readonly ObjectFetcher $objectFetcher,
		private readonly CollectionLister $collectionLister,
		private readonly CollectionFetcher $collectionFetcher,
		private readonly CollectionEditionService $collectionEditionService,
		private readonly SchemaLister $schemaLister,
		private readonly SchemaFetcher $schemaFetcher,
		private readonly DeckCompatibilityChecker $deckCompatibilityChecker,
		private readonly TemplateLister $templateLister,
		private readonly ObjectUrlBuilder $objectUrlBuilder,
		public TotalFormFactory $form,
		public ServerChecker $checker,
		public CacheReporter $cacheReporter,
		public LogAnalyzer $logAnalyzer,
		private readonly PhpSession $session,
		private readonly AccessManager $accessManager,
		private readonly FileAccessManager $fileAccessManager,
		private readonly AccessControlService $accessControl,
		public ImageCacheService $imageCacheService,
		public GridRenderer $grid,
		private readonly DepotBrowserRenderer $depotBrowserRenderer,
		private readonly DevModeManager $devModeManager,
		public LicenseStatus $license,
		public EditionTwigAdapter $edition,
		private readonly JobManager $jobManager,
		private readonly CacheManager $cacheManager,
		private readonly LoggerFactory $loggerFactory,
	) {
		$this->logger     = $this->loggerFactory->addFileHandler('twig.log')->createLogger('twig');
		$this->env        = $this->config->env;
		$this->api        = $this->config->api;
		$this->clearcache = $this->api . '/emergency/cache/clear';
		$this->dashboard  = $this->api . '/admin';
		$this->logout     = $this->api . '/logout';
		$this->domain     = $this->getDomainName();
		$this->version    = $this->getVersion();
		$this->currentUrl = $_SERVER['REQUEST_URI'] ?? '';
	}

	/** @SuppressWarnings("PHPMD.Superglobals") */
	public function processJobQueueCommand(): string
	{
		// php <install_dir>/resources/bin/processJobs.php --docroot=/home/username/websites/example.com
		$phpPath    = defined(PHP_BINARY) ? PHP_BINARY : 'php';
		$installDir = realpath(__DIR__ . '/../../../..');
		$docroot    = rtrim((string)$_SERVER['DOCUMENT_ROOT'], DIRECTORY_SEPARATOR);
		$command    = $installDir . '/resources/bin/processJobs.php';

		// Quote paths that contain spaces
		$quotedCommand = str_contains($command, ' ') ? '"' . $command . '"' : $command;
		$quotedDocroot = str_contains($docroot, ' ') ? '"' . $docroot . '"' : $docroot;

		return sprintf(
			'%s %s --docroot=%s',
			$phpPath,
			$quotedCommand,
			$quotedDocroot,
		);
	}

	/**
	 * Get development mode status.
	 *
	 * @return array<string,mixed>
	 */
	public function getDevModeStatus(): array
	{
		return $this->devModeManager->getDevModeStatus();
	}

	/**
	 * Check if development mode is active.
	 */
	public function isDevModeActive(): bool
	{
		return $this->devModeManager->isDevModeActive();
	}

	/**
	 * Get pending jobs info for display.
	 */
	public function jobQueuePendingInfo(): string
	{
		$pendingJobs = $this->jobManager->getPendingJobs();

		if ($pendingJobs === []) {
			return '';
		}

		$rows = '';
		foreach ($pendingJobs as $job) {
			$payload  = json_decode($job->payload, true);
			$objectId = $payload['id'] ?? 'N/A';

			$rows .= sprintf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				htmlspecialchars($job->type),
				htmlspecialchars($job->collection),
				htmlspecialchars((string)$objectId),
				htmlspecialchars($job->createdAt)
			);
		}

		return sprintf(
			'<section class="jobqueue-preview-section">
				<h3>Pending Jobs</h3>
				<div class="jobqueue-table-wrapper">
					<table class="jobqueue-preview pending-jobs cms-colors">
						<thead>
							<tr>
								<th>Type</th>
								<th>Collection</th>
								<th>Object ID</th>
								<th>Created</th>
							</tr>
						</thead>
						<tbody>%s</tbody>
					</table>
				</div>
			</section>',
			$rows
		);
	}

	/**
	 * Get failed jobs info for display.
	 */
	public function jobQueueFailedInfo(): string
	{
		$failedJobs = $this->jobManager->getFailedJobs();

		if ($failedJobs === []) {
			return '';
		}

		$rows = '';
		foreach ($failedJobs as $job) {
			$payload  = json_decode($job->payload, true);
			$objectId = $payload['id'] ?? 'N/A';

			// Truncate error message for display
			$errorSnippet = $job->lastError;
			if (strlen($errorSnippet) > 100) {
				$errorSnippet = substr($errorSnippet, 0, 100) . '...';
			}

			$rows .= sprintf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td title="%s">%s</td></tr>',
				htmlspecialchars($job->type),
				htmlspecialchars($job->collection),
				htmlspecialchars((string)$objectId),
				htmlspecialchars(strval($job->attempts)),
				htmlspecialchars($job->lastError),
				htmlspecialchars($errorSnippet)
			);
		}

		return sprintf(
			'<section class="jobqueue-preview-section">
				<h3>Failed Jobs</h3>
				<div class="jobqueue-table-wrapper">
					<table class="jobqueue-preview failed-jobs cms-colors">
						<thead>
							<tr>
								<th>Type</th>
								<th>Collection</th>
								<th>Object ID</th>
								<th>Attempts</th>
								<th>Error</th>
							</tr>
						</thead>
						<tbody>%s</tbody>
					</table>
				</div>
			</section>',
			$rows
		);
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

	/** @SuppressWarnings("PHPMD.BooleanArgumentFlag") */
	public function prettyUrl(string $path, bool $addDomain = false): string
	{
		$home = 'https://' . $this->domain;
		$url  = $addDomain ? $home . $path : $path;

		// just incase someone puts in the full url and not just the path
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

	private function startPathForUrl(string $url): string
	{
		$path  = strval(parse_url($url, PHP_URL_PATH));
		$start = $path;

		if (str_ends_with($path, 'php')) {
			$start = dirname($path) . '/';
		}
		if (!str_ends_with($start, '/')) {
			$start .= '/';
		}

		return ltrim($start, '/');
	}

	public function apacheRule(string $url, string $collection = 'Collection'): string
	{
		$path  = strval(parse_url($url, PHP_URL_PATH));
		$start = $this->startPathForUrl($url);

		return <<<HTACCESS
# Total CMS Pretty URL Rewrites for $collection
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^$start([\w-]+)$ $path?id=$1 [L,QSA]
HTACCESS;
	}

	public function nginxRule(string $url, string $collection = 'Collection'): string
	{
		$path  = strval(parse_url($url, PHP_URL_PATH));
		$start = $this->startPathForUrl($url);

		return <<<NGINX
# Total CMS Pretty URL Rewrites for {$collection}
rewrite ^/{$start}([\w-]+)/?\$ /{$path}?id=\$1 last;
NGINX;
	}

	/** @SuppressWarnings("PHPMD.Superglobals") */
	private function getDomainName(): string
	{
		return $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
	}

	private function getVersion(): VersionData
	{
		return new VersionData();
	}

	/** @return array<string,string> */
	public function languages(): array
	{
		return [
			'Arabic'          => 'ar_SA',
			'Bengali'         => 'bn_BD',
			'Czech'           => 'cs_CZ',
			'Dutch'           => 'nl_NL',
			'English'         => 'en_US',
			'French'          => 'fr_FR',
			'German'          => 'de_DE',
			'Greek'           => 'el_GR',
			'Hebrew'          => 'he_IL',
			'Hindi'           => 'hi_IN',
			'Italian'         => 'it_IT',
			'Japanese'        => 'ja_JP',
			'Javanese'        => 'jv_ID',
			'Korean'          => 'ko_KR',
			'Malay'           => 'ms_MY',
			'Mandarin'        => 'zh_CN',
			'Persian (Farsi)' => 'fa_IR',
			'Polish'          => 'pl_PL',
			'Portuguese'      => 'pt_BR',
			'Punjabi'         => 'pa_IN',
			'Romanian'        => 'ro_RO',
			'Russian'         => 'ru_RU',
			'Spanish'         => 'es_ES',
			'Swahili'         => 'sw_KE',
			'Tamil'           => 'ta_IN',
			'Tagalog'         => 'tl_PH',
			'Thai'            => 'th_TH',
			'Turkish'         => 'tr_TR',
			'Ukrainian'       => 'uk_UA',
			'Urdu'            => 'ur_PK',
			'Vietnamese'      => 'vi_VN',
		];
	}

	/**
	 * Set the locale for internationalization (dates, numbers, relative time).
	 * Useful for multilingual sites to switch locale per page.
	 * Requires the PHP intl extension to be installed.
	 *
	 * Usage in Twig: {{ cms.setLocale('de_DE') }}
	 *
	 * @param string $locale The locale code (e.g., 'de_DE', 'fr_FR', 'ja_JP')
	 *
	 * @return string Empty string (no output in template)
	 */
	public function setLocale(string $locale): string
	{
		// Locale functions require the intl extension
		// I18n::setLocale() internally calls \Locale::setDefault()
		if (extension_loaded('intl')) {
			\Locale::setDefault($locale);
			\Cake\I18n\I18n::setLocale($locale);
		}

		return '';
	}

	/**
	 * Get the current locale.
	 * Requires the PHP intl extension to be installed.
	 *
	 * Usage in Twig: {{ cms.getLocale() }}
	 *
	 * @return string The current locale code (defaults to 'en_US' if intl not available)
	 */
	public function getLocale(): string
	{
		// I18n::getLocale() internally calls \Locale::getDefault()
		if (!extension_loaded('intl')) {
			return 'en_US';
		}

		return \Cake\I18n\I18n::getLocale();
	}

	/**
	 * @SuppressWarnings("PHPMD.Superglobals")
	 */
	public function login(string $collection = '', ?string $redirect = null): string
	{
		$loginUrl = $collection === ''
			? sprintf('%s/%s', $this->api, 'login')
			: sprintf('%s/%s/%s', $this->api, 'login', $collection);

		// If redirect is null, default to current page
		// If redirect is empty string, no redirect parameter
		// If redirect has value, use that value
		if ($redirect === null) {
			$redirect = $_SERVER['REQUEST_URI'] ?? '';
		}

		if ($redirect !== '') {
			$loginUrl .= '?' . http_build_query(['redirect' => $redirect]);
		}

		return $loginUrl;
	}

	/** @return array<string,mixed> */
	public function userData(): array
	{
		return $this->accessManager->userData();
	}

	public function userLoggedIn(string $collection = ''): bool
	{
		return $this->accessManager->userLoggedIn($collection);
	}

	/** @param string|array<string> $groups */
	public function userHasAccess(array|string $groups, string $collection = ''): bool
	{
		return $this->accessManager->userHasAccess($groups, $collection);
	}

	public function sessionData(string $key): ?string
	{
		if ($this->session->has($key)) {
			return $this->session->get($key);
		}

		return null;
	}

	/** @SuppressWarnings("PHPMD.ElseExpression") */
	public function verifyFilePassword(string $password, string $collection, string $id, string $property, ?string $name = null): bool
	{
		if ($name !== null) {
			$this->fileAccessManager->loadDepotFile($collection, $id, $property);
		} else {
			$this->fileAccessManager->loadFile($collection, $id, $property);
		}

		return $this->fileAccessManager->verfiyPasswordOnly($password);
	}

	public function config(string $key, ?string $setting = null): mixed
	{
		if ($setting === null) {
			return $this->config->$key;
		}

		$config = $this->config->$key;
		if (is_array($config) && array_key_exists($setting, $config)) {
			return $config[$setting];
		}

		return '';
	}

	/**
	 * Log a message from a Twig template to the twig.log file.
	 *
	 * @param array<string,mixed> $context
	 */
	public function log(string $message, string $level = 'warning', array $context = []): void
	{
		$this->logger->log($level, $message, $context);
	}

	/**
	 * Get all accessible schemas (filtered by edition).
	 *
	 * @return array<array<string,mixed>>
	 */
	public function schemas(): array
	{
		$schemas = $this->schemaLister->listAllSchemas();

		// Filter by edition accessibility
		$schemas = array_filter(
			$schemas,
			fn (SchemaData $schema): bool => $this->collectionEditionService->isSchemaAccessible($schema->id)
		);

		return array_map(fn (SchemaData $schema): array => $schema->toArray(), $schemas);
	}

	/**
	 * Get all accessible reserved schemas (filtered by edition).
	 * Blog, blog-legacy, depot require Standard+.
	 *
	 * @return array<array<string,mixed>>
	 */
	public function reservedSchemas(): array
	{
		$schemas = $this->schemaLister->listReservedSchemas();

		// Filter by edition accessibility
		$schemas = array_filter(
			$schemas,
			fn (SchemaData $schema): bool => $this->collectionEditionService->isSchemaAccessible($schema->id)
		);

		return array_map(fn (SchemaData $schema): array => $schema->toArray(), $schemas);
	}

	/**
	 * Get all accessible custom schemas (Pro edition only).
	 *
	 * @return array<array<string,mixed>>
	 */
	public function customSchemas(): array
	{
		$schemas = $this->schemaLister->listCustomSchemas();

		// Filter by edition accessibility (custom schemas require Pro)
		$schemas = array_filter(
			$schemas,
			fn (SchemaData $schema): bool => $this->collectionEditionService->isSchemaAccessible($schema->id)
		);

		return array_map(fn (SchemaData $schema): array => $schema->toArray(), $schemas);
	}

	/** @return array<string,array<array<string,mixed>>> */
	public function schemasByCategory(): array
	{
		$customSchemas   = $this->customSchemas();
		$reservedSchemas = $this->reservedSchemas();

		$categories = [];

		// Process custom schemas by category
		foreach ($customSchemas as $schema) {
			$category = empty($schema['category']) ? 'Custom Schemas' : trim(strval($schema['category']));
			if (!array_key_exists($category, $categories)) {
				$categories[$category] = [];
			}
			$categories[$category][] = $schema;
		}

		// Always add Built-in Schemas category for reserved schemas
		$categories['Built-in Schemas'] = $reservedSchemas;

		// Sort the categories by key, but keep Built-in Schemas at the bottom
		uksort($categories, function ($a, $b): int {
			if ($a === 'Built-in Schemas') {
				return 1;
			}
			if ($b === 'Built-in Schemas') {
				return -1;
			}
			if ($a === 'Custom Schemas') {
				return 1;
			}
			if ($b === 'Custom Schemas') {
				return -1;
			}

			return strcmp($a, $b);
		});

		return $categories;
	}

	// Get schema definition
	/** @return array<string,mixed> */
	public function schema(string $schema): array
	{
		$schema = $this->schemaFetcher->fetchSchema($schema);

		return $schema->toArray();
	}

	/** @return array<string,mixed> */
	public function schemaForCollection(string $collection): array
	{
		$schema = $this->schemaFetcher->fetchSchemaForCollection($collection);

		return $schema->toArray();
	}

	/**
	 * Get inherited properties for a schema.
	 * Returns array with property details including source schema, field type, and property type.
	 * Only shows properties that are PURELY inherited (not also defined in the schema itself).
	 *
	 * @return array<string,array{source:string,field:string,type:string,definition:array<string,mixed>}>
	 */
	public function getInheritedProperties(string $schemaId): array
	{
		try {
			$schema = $this->schemaFetcher->fetchRawSchema($schemaId);

			if ($schema->inheritFrom === []) {
				return [];
			}

			$inheritedProperties = [];
			$ownPropertyNames    = array_keys($schema->properties);

			// Process each parent schema in order
			foreach ($schema->inheritFrom as $parentId) {
				try {
					$parentSchema = $this->schemaFetcher->fetchRawSchema($parentId);

					foreach ($parentSchema->properties as $propName => $propDef) {
						// Only add if not already in own properties and not already inherited (first wins)
						if (!in_array($propName, $ownPropertyNames, true) && !isset($inheritedProperties[$propName])) {
							$inheritedProperties[$propName] = [
								'source'     => $parentId,
								'field'      => $propDef['field'] ?? 'text',
								'type'       => SchemaSaver::extractPropertyType($propDef),
								'definition' => $propDef,
							];
						}
					}
				} catch (\Exception $e) {
					$this->logger->warning("Parent schema '{$parentId}' not found during inheritance resolution for '{$schemaId}'", ['error' => $e->getMessage()]);
					continue;
				}
			}

			return $inheritedProperties;
		} catch (\Exception) {
			return [];
		}
	}

	/**
	 * Get all accessible collections (filtered by edition).
	 *
	 * @return array<CollectionData>
	 */
	public function collections(): array
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

	/**
	 * Get collections that are inaccessible due to edition restrictions.
	 * Used for dashboard alerts.
	 *
	 * @return array<CollectionData>
	 */
	public function getInaccessibleCollections(): array
	{
		return $this->collectionEditionService->getInaccessibleCollections();
	}

	/**
	 * Get schemas that are inaccessible due to edition restrictions.
	 * Used for admin alerts on schema listing page.
	 *
	 * @return array<string>
	 */
	public function getInaccessibleSchemas(): array
	{
		return $this->collectionEditionService->getInaccessibleSchemas();
	}

	/** @return array<string,list<object>> */
	public function collectionsByCategory(): array
	{
		$collections = $this->collections();
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
	public function collection(string $collectionId): array
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
	 * Render a collection table for the admin interface.
	 */
	public function collectionTable(string $collection): string
	{
		// Try to get cached table HTML for large collections
		$collectionData = $this->collectionFetcher->fetchCollection($collection);
		$cacheKey       = null;

		if ($collectionData instanceof CollectionData && $collectionData->totalObjects > 1000) {
			// Use lastUpdated as cache buster - changes whenever objects are modified
			$lastUpdated = $collectionData->lastUpdated ?? '';
			$cacheKey    = "table:{$collection}:" . md5($lastUpdated);

			$cached = $this->cacheManager->getComputedData($cacheKey);
			if ($cached !== null && is_string($cached)) {
				return $cached;
			}
		}

		$options = [
			'config'            => $this->config,
			'collectionFetcher' => $this->collectionFetcher,
			'collectionLister'  => $this->collectionLister,
			'schemaFetcher'     => $this->schemaFetcher,
			'collectionReader'  => $this->indexReader,
			'objectUrlBuilder'  => $this->objectUrlBuilder,
			'api'               => $this->api,
			'collection'        => $collection,
		];

		$table  = new CollectionTable(...$options);
		$result = $table->build();

		// Cache the rendered HTML for large collections (1 hour TTL)
		if ($cacheKey !== null) {
			$this->cacheManager->storeComputedData($cacheKey, $result, CacheManager::TTL_INDEX_DATA);
		}

		return $result;
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
			$collection = $this->indexReader->fetchIndex($collection);
		} catch (\Exception $e) {
			$this->logger->warning("Failed to fetch collection '{$collection}'", ['error' => $e->getMessage()]);

			return [];
		}

		return $collection->objects->toArray();
	}

	// Get a list of all values from a property in a collection
	/** @return array<mixed> */
	public function property(string $collection, string $property): array
	{
		$collection = $this->indexReader->fetchIndex($collection);

		return $collection->objects->pluck($property)->flatten()->unique()->toArray();
	}

	// Get an objects from a collection
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
	 * @param string|array<string,mixed> $idOrObject
	 * @param array<string,mixed> $options
	 */
	public function download(string|array $idOrObject, array $options = []): string
	{
		$collection = $options['collection'] ?? 'file';
		$property   = $options['property'] ?? 'file';
		$password   = $options['pwd'] ?? '';

		// Performance optimization: Accept full object to avoid re-fetching
		$id = is_array($idOrObject) ? ($idOrObject['id'] ?? '') : $idOrObject;
		if ($id === '') {
			return '';
		}

		$url = "{$this->api}/download/{$collection}/{$id}/{$property}";

		// Auto-encrypt password if provided and not already encrypted
		if (!empty($password) && !$this->isEncryptedPassword($password)) {
			$password = Cipher::encrypt($password);
		}

		if (!empty($password)) {
			$url .= '?pwd=' . urlencode((string)$password);
		}

		return $url;
	}

	/**
	 * @param string|array<string,mixed> $idOrObject
	 * @param array<string,mixed> $options
	 */
	public function depotDownload(string|array $idOrObject, string $name, array $options = []): string
	{
		$collection = $options['collection'] ?? 'depot';
		$property   = $options['property'] ?? 'depot';
		$path       = $options['path'] ?? '';
		$password   = $options['pwd'] ?? '';

		// Performance optimization: Accept full object to avoid re-fetching
		$id = is_array($idOrObject) ? ($idOrObject['id'] ?? '') : $idOrObject;
		if ($id === '') {
			return '';
		}

		// Add support for supplying the path via the name
		if (str_contains($name, '/')) {
			$pathinfo = pathinfo($name);
			$path     = $pathinfo['dirname'];
			$name     = $pathinfo['basename'];
		}

		$url = "{$this->api}/download/{$collection}/{$id}/{$property}/" . urlencode($name);

		// Auto-encrypt password if provided and not already encrypted
		if (!empty($password) && !$this->isEncryptedPassword($password)) {
			$password = Cipher::encrypt($password);
		}

		$query = http_build_query(array_filter([
			'path' => trim((string)$path, '/'),
			'pwd'  => $password,
		]));

		if ($query !== '') {
			$url .= "?$query";
		}

		return $url;
	}

	/**
	 * @param string|array<string,mixed> $idOrObject
	 * @param array<string,mixed> $options
	 */
	public function stream(string|array $idOrObject, array $options = []): string
	{
		$collection = $options['collection'] ?? 'file';
		$property   = $options['property'] ?? 'file';
		$password   = $options['pwd'] ?? '';

		// Performance optimization: Accept full object to avoid re-fetching
		$id = is_array($idOrObject) ? ($idOrObject['id'] ?? '') : $idOrObject;
		if ($id === '') {
			return '';
		}

		$url = "{$this->api}/stream/{$collection}/{$id}/{$property}";

		// Auto-encrypt password if provided and not already encrypted
		if (!empty($password) && !$this->isEncryptedPassword($password)) {
			$password = Cipher::encrypt($password);
		}

		if (!empty($password)) {
			$url .= '?pwd=' . urlencode((string)$password);
		}

		return $url;
	}

	/**
	 * @param string|array<string,mixed> $idOrObject
	 * @param array<string,mixed> $options
	 */
	public function depotStream(string|array $idOrObject, string $name, array $options = []): string
	{
		$collection = $options['collection'] ?? 'depot';
		$property   = $options['property'] ?? 'depot';
		$path       = $options['path'] ?? '';
		$password   = $options['pwd'] ?? '';

		// Performance optimization: Accept full object to avoid re-fetching
		$id = is_array($idOrObject) ? ($idOrObject['id'] ?? '') : $idOrObject;
		if ($id === '') {
			return '';
		}

		// Add support for supplying the path via the name
		if (str_contains($name, '/')) {
			$pathinfo = pathinfo($name);
			$path     = $pathinfo['dirname'];
			$name     = $pathinfo['basename'];
		}

		$url = "{$this->api}/stream/{$collection}/{$id}/{$property}/" . urlencode($name);

		// Auto-encrypt password if provided and not already encrypted
		if (!empty($password) && !$this->isEncryptedPassword($password)) {
			$password = Cipher::encrypt($password);
		}

		$query = http_build_query(array_filter([
			'path' => trim((string)$path, '/'),
			'pwd'  => $password,
		]));

		if ($query !== '') {
			$url .= "?$query";
		}

		return $url;
	}

	// Get an data property from an object
	public function data(string $collection, string $id, string $property): mixed
	{
		$object = $this->object($collection, $id);

		if (array_key_exists($property, $object)) {
			return $object[$property];
		}

		$this->logger->debug("Property '{$property}' not found on object '{$id}' in collection '{$collection}'");

		return '';
	}

	/** @param array<string,string> $options */
	public function toggle(string $id, array $options = []): bool
	{
		$options = array_merge([
			'collection' => 'toggle',
			'property'   => 'status',
		], $options);

		return boolval($this->data($options['collection'], $id, $options['property']));
	}

	/** @param array<string,string> $options */
	public function date(string $id, array $options = []): string
	{
		$options = array_merge([
			'collection' => 'date',
			'property'   => 'date',
		], $options);

		return strval($this->data($options['collection'], $id, $options['property']));
	}

	/**
	 * @param array<string,mixed> $options
	 *
	 * @return array<string,mixed>
	 */
	public function color(string $id, array $options = []): array
	{
		$options = array_merge([
			'collection' => 'color',
			'property'   => 'color',
		], $options);

		$color = $this->data($options['collection'], $id, $options['property']);

		if (!is_array($color)) {
			return [];
		}

		return $color;
	}

	/**
	 * @param array<string,mixed> $options
	 *
	 * @return array<string,mixed>
	 */
	public function colour(string $id, array $options = []): array
	{
		return $this->color($id, $options);
	}

	/** @param array<string,string> $options */
	public function svg(string $id, array $options = []): string
	{
		$options = array_merge([
			'collection' => 'svg',
			'property'   => 'svg',
		], $options);

		return strval($this->data($options['collection'], $id, $options['property']));
	}

	/**
	 * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
	 *
	 * @param array<string,string> $options
	 */
	public function email(string $id, array $options = [], bool $obfuscate = false): string
	{
		$options = array_merge([
			'collection' => 'email',
			'property'   => 'email',
		], $options);

		$email = strval($this->data($options['collection'], $id, $options['property']));

		return $obfuscate ? HTMLUtils::htmlencode($email) : $email;
	}

	/** @param array<string,string> $options */
	public function url(string $id, array $options = []): string
	{
		$options = array_merge([
			'collection' => 'url',
			'property'   => 'url',
		], $options);

		return strval($this->data($options['collection'], $id, $options['property']));
	}

	/** @param array<string,string> $options */
	public function number(string $id, array $options = []): string
	{
		$options = array_merge([
			'collection' => 'number',
			'property'   => 'number',
		], $options);

		return strval($this->data($options['collection'], $id, $options['property']));
	}

	/** @param array<string,string> $options */
	public function text(string $id, array $options = []): string
	{
		$options = array_merge([
			'collection' => 'text',
			'property'   => 'text',
		], $options);

		return strval($this->data($options['collection'], $id, $options['property']));
	}

	/** @param array<string,string> $options */
	public function code(string $id, array $options = []): string
	{
		$options = array_merge([
			'collection' => 'code',
			'property'   => 'code',
		], $options);

		return strval($this->data($options['collection'], $id, $options['property']));
	}

	/** @param array<string,string> $options */
	public function styledtext(string $id, array $options = []): string
	{
		$options = array_merge([
			'collection' => 'styledtext',
			'property'   => 'styledtext',
		], $options);

		return strval($this->data($options['collection'], $id, $options['property']));
	}

	/**
	 * @param array<string,mixed> $options
	 *
	 * @return array<array<string,string|int>>
	 */
	public function depot(string $id, array $options = []): array
	{
		$options = array_merge([
			'collection' => 'depot',
			'property'   => 'depot',
		], $options);

		$files = $this->data($options['collection'], $id, $options['property']);

		return is_array($files) ? $files : [];
	}

	/**
	 * Render a public depot file browser component.
	 *
	 * @param array<string,mixed> $options
	 */
	public function depotBrowser(string $id, array $options = []): string
	{
		$options = array_merge([
			'collection'  => 'depot',
			'property'    => 'depot',
			'filter'      => false,
			'preview'     => false,
			'comments'    => false,
			'download'    => true,
			'tags'        => false,
			'folders'     => true,
			'humanize'    => true,
			'class'       => '',
			'reverseSort' => false,
			'filterTags'  => [],
		], $options);

		$collection = $options['collection'];
		$property   = $options['property'];

		$depot = $this->data($collection, $id, $property);
		if (!is_array($depot)) {
			return '';
		}

		$downloadUrl = fn (string $objId, string $name, array $opts): string => $this->depotDownload(
			$objId,
			$name,
			array_merge(['collection' => $collection, 'property' => $property], $opts),
		);

		$streamUrl = fn (string $objId, string $name, array $opts): string => $this->depotStream(
			$objId,
			$name,
			array_merge(['collection' => $collection, 'property' => $property], $opts),
		);

		return $this->depotBrowserRenderer->render($id, $depot, $options, $downloadUrl, $streamUrl);
	}

	/** @param array<string,string> $getData */
	public function paginationSimple(
		int $totalObjects,
		int $currentPage,
		int $pageLimit,
		string $pageKey     = 'p',
		string $prevContent = 'Previous',
		string $nextContent = 'Next',
		array $getData     = [],
	): string {
		return PaginationGenerator::simplePagination(...func_get_args());
	}

	/** @param array<string,string> $getData */
	public function paginationFull(
		int $totalObjects,
		int $currentPage,
		int $pageLimit,
		string $pageKey     = 'p',
		string $prevContent = 'Previous',
		string $nextContent = 'Next',
		array $getData     = [],
	): string {
		return PaginationGenerator::fullPagination(...func_get_args());
	}

	/**
	 * @SuppressWarnings("PHPMD.ElseExpression")
	 *
	 * @param string|array<string,mixed>|null $idOrObject Object array or object ID string
	 * @param array<string,string|int> $imageworks
	 * @param array<string,mixed> $options
	 */
	public function image(string|array|null $idOrObject, array $imageworks = [], array $options = []): string
	{
		$options = array_merge([
			'collection' => 'image',
			'property'   => 'image',
			'loading'    => 'lazy',
		], $options);

		if (in_array($idOrObject, [null, '', []], true)) {
			return '';
		}

		$imagePath = $this->imagePath($idOrObject, $imageworks, $options);
		if ($imagePath === '') {
			return '';
		}

		// Performance optimization: Extract image data from object if passed
		if (is_array($idOrObject)) {
			$image = $idOrObject[$options['property']] ?? [];
		} else {
			$image = $this->data($options['collection'], $idOrObject, $options['property']);
		}

		// Calculate dimensions for layout stability (prevents CLS)
		$dimensions = ImageDimensionCalculator::calculateFromImageData($image, $imageworks);

		$html = HTMLUtils::inlineElement('img', [
			'src'           => $imagePath,
			'alt'           => $this->alt($idOrObject, $options),
			'width'         => $dimensions['width'],
			'height'        => $dimensions['height'],
			'class'         => $options['class'] ?? null,
			'loading'       => $options['loading'] ?? null,
			'draggable'     => 'false',
			'oncontextmenu' => 'return false;',
		]);

		if (!empty($image['link'])) {
			$html = HTMLUtils::element('a', $html, ['href' => $image['link']]);
		}

		return $html;
	}

	// Get the image path for an image property
	/**
	 * @param string|array<string,mixed>|null $idOrObject Object array or object ID string
	 * @param array<string,mixed> $options
	 * @param array<string,string|int> $imageworks
	 */
	public function imagePath(string|array|null $idOrObject, array $imageworks = [], array $options = []): string
	{
		$options = array_merge([
			'collection' => 'image',
			'property'   => 'image',
		], $options);

		if (in_array($idOrObject, [null, '', []], true)) {
			return '';
		}

		$collection = $options['collection'];
		$property   = $options['property'];

		// Resolve preset format for URL extension
		$imageworks = $this->resolvePresetFormat($imageworks);

		// Performance optimization: Accept full object to avoid re-fetching
		if (is_array($idOrObject)) {
			// Object passed directly - extract ID and image data
			$id = $idOrObject['id'] ?? '';
			if ($id === '') {
				return '';
			}

			// Try to get image data from the object
			$image = $idOrObject[$property] ?? null;
			if (!is_array($image) || !array_key_exists('size', $image) || $image['size'] === 0) {
				return '';
			}

			return self::buildImageworksAPI($this->api, $id, $image, $imageworks, $options);
		}

		// Original behavior: ID string passed, fetch object data
		$image = $this->data($collection, $idOrObject, $property);
		if (!is_array($image) || !array_key_exists('size', $image) || $image['size'] === 0) {
			return '';
		}

		return self::buildImageworksAPI($this->api, $idOrObject, $image, $imageworks, $options);
	}

	/**
	 * Resolve preset format for URL extension.
	 * If a preset is specified and has an 'fm' value, add it to imageworks so the URL uses the correct extension.
	 *
	 * @param array<string,mixed> $imageworks
	 *
	 * @return array<string,mixed>
	 */
	private function resolvePresetFormat(array $imageworks): array
	{
		// If fm is already explicitly set, use it
		if (isset($imageworks['fm'])) {
			return $imageworks;
		}

		// If no preset, nothing to resolve
		if (!isset($imageworks['p'])) {
			return $imageworks;
		}

		$presetName = (string)$imageworks['p'];
		$presets    = $this->config->imageworks['presets'] ?? [];

		if (!isset($presets[$presetName])) {
			return $imageworks;
		}

		$preset = $presets[$presetName];

		// If preset has fm, add it to imageworks for URL building
		if (isset($preset['fm'])) {
			$imageworks['fm'] = $preset['fm'];
		}

		return $imageworks;
	}

	/**
	 * @param array<string,mixed> $image
	 * @param array<string,mixed> $options
	 * @param array<string,string|int> $imageworks
	 */
	public static function buildImageworksAPI(string $api, string $id, array $image, array $imageworks = [], array $options = []): string
	{
		$options = array_merge([
			'collection' => 'image',
			'property'   => 'image',
		], $options);

		$collection = $options['collection'];
		$property   = $options['property'];

		if ($image === [] || !array_key_exists('name', $image) || $image['name'] === '') {
			return '';
		}

		// Default to original image type
		$type = strtolower(pathinfo((string)$image['name'], PATHINFO_EXTENSION));
		// If type is set in imageworks options, use that
		if (array_key_exists('fm', $imageworks)) {
			$type = $imageworks['fm'];
			unset($imageworks['fm']);
		}
		// If type is not in the list of allowed types, default to jpg
		$type = in_array($type, GlideFactory::IMG_TYPES) ? $type : 'jpg';

		$api .= "/imageworks/$collection/$id/$property.$type";

		// cache busting links
		$imageworks['cache'] = strrev((string)preg_replace('/\W+/', '', (string)$image['uploadDate']));

		// From Stacks Preview Server - Not used in Imageworks and breaks the image generation
		unset($imageworks['datadir']);
		unset($imageworks['route']);

		// Parse the existing URL and its query parameters
		$parsedUrl = parse_url($api);

		if (!isset($parsedUrl['path'])) {
			return '';
		}

		$existingParams = [];
		if (isset($parsedUrl['query'])) {
			parse_str($parsedUrl['query'], $existingParams);
		}

		// Merge the existing parameters with the new imageworks options
		$imageworks = array_merge($existingParams, $imageworks);

		// Reconstruct the URL without the original query string, and append the new query string
		$api = $parsedUrl['path'] . '?' . http_build_query($imageworks);

		return $api;
	}

	/**
	 * @SuppressWarnings("PHPMD.ElseExpression")
	 *
	 * @param string|array<string,mixed>|null $idOrObject Object array or object ID string
	 * @param array<string,string|int> $thumbSettings
	 * @param array<string,string|int> $fullSettings
	 * @param array<string,mixed> $options
	 */
	public function gallery(string|array|null $idOrObject, array $thumbSettings = [], array $fullSettings = [], array $options = []): string
	{
		$options = array_merge([
			'collection' => 'gallery',
			'property'   => 'gallery',
		], $options);

		if ($thumbSettings === []) {
			$thumbSettings = ['w' => 300, 'h' => 200];
		}

		$gallery = '';

		if (in_array($idOrObject, [null, '', []], true)) {
			return $gallery;
		}

		// Performance optimization: Accept full object to avoid re-fetching
		if (is_array($idOrObject)) {
			// Object passed directly - extract ID and gallery data
			$id     = $idOrObject['id'] ?? '';
			$images = $idOrObject[$options['property']] ?? [];
		} else {
			// Original behavior: ID string passed, fetch object data
			$id     = $idOrObject;
			$images = $this->data($options['collection'], $id, $options['property']);
		}

		if (in_array($images, [null, '', []], true)) {
			return $gallery;
		}

		// Sort images if sort option is provided
		if (isset($options['sort']) && $options['sort'] !== '') {
			$images = $this->sortGalleryImages($images, $options['sort']);
		}

		// Check if captions should be shown on grid thumbnails and in lightbox
		// When set to a string, it's used as a template (e.g., "{{alt}} | {{exif.camera}}")
		$showGridCaptions  = !empty($options['gridCaptions']);
		$gridCaptionTpl    = isset($options['gridCaptions']) && $options['gridCaptions'] !== true ? trim((string)$options['gridCaptions']) : '';
		$showCaptions      = !empty($options['captions']);
		$captionTpl        = isset($options['captions']) && $options['captions'] !== true ? trim((string)$options['captions']) : '';

		// Check if only featured images should be shown in grid (but all in lightbox)
		$featuredOnly = isset($options['featuredOnly']) && $options['featuredOnly'];
		$allImages    = $images; // Keep all images for lightbox
		if ($featuredOnly) {
			$images = array_filter($images, fn (array $img): bool => !empty($img['featured']));
			$images = array_values($images); // Re-index array
		}

		// Uses direct image data to avoid redundant galleryImageData() lookups per image
		foreach ($images as $image) {
			// Calculate dimensions for layout stability (prevents CLS)
			$thumbDimensions = ImageDimensionCalculator::calculateFromImageData($image, $thumbSettings);

			// Build full-size URL once and reuse for both href and data-src
			$fullUrl = $this->buildGalleryUrl($id, $image, $fullSettings, $options);

			$img = HTMLUtils::inlineElement('img', [
				'src'           => $this->buildGalleryUrl($id, $image, $thumbSettings, $options),
				'alt'           => $this->altFromImageData($image),
				'width'         => $thumbDimensions['width'],
				'height'        => $thumbDimensions['height'],
				'loading'       => 'lazy',
				'draggable'     => 'false',
				'oncontextmenu' => 'return false;',
			]);
			$link = HTMLUtils::element('a', $img, [
				'href' => $fullUrl,
			]);

			// Always wrap in figure for semantic HTML5
			$figureContent = $link;
			if ($showGridCaptions) {
				$captionText = $this->captionFromImageData($image, $gridCaptionTpl);
				if ($captionText !== '') {
					$captionHtml = $gridCaptionTpl !== '' ? $captionText : htmlspecialchars($captionText);
					$caption     = HTMLUtils::element('figcaption', $captionHtml, ['class' => 'cms-gallery-caption']);
					$figureContent .= $caption;
				}
			}

			// Calculate the actual dimensions after ImageWorks processing
			$processedDimensions = ImageDimensionCalculator::calculateFromImageData($image, $fullSettings);

			$figureAttrs = [
				'class'        => 'cms-gallery-item',
				'data-src'     => $fullUrl,
				'data-lg-size' => "{$processedDimensions['width']}-{$processedDimensions['height']}",
			];

			// Add lightbox caption via data-sub-html attribute
			if ($showCaptions) {
				$captionText = $this->captionFromImageData($image, $captionTpl);
				if ($captionText !== '') {
					$figureAttrs['data-sub-html'] = $captionTpl !== '' ? $captionText : htmlspecialchars($captionText);
				}
			}

			// Add image name for mapping when using featuredOnly mode
			if ($featuredOnly) {
				$figureAttrs['data-gallery-image'] = $image['name'];
			}

			$figure = HTMLUtils::element('figure', $figureContent, $figureAttrs);
			$gallery .= $figure;
		}

		// Build dynamic elements for all images when featuredOnly is enabled
		$dynamicTemplate = '';
		if ($featuredOnly && count($allImages) > count($images)) {
			$dynamicEl = [];
			foreach ($allImages as $img) {
				$item = [
					'src'    => $this->buildGalleryUrl($id, $img, $fullSettings, $options),
					'thumb'  => $this->buildGalleryUrl($id, $img, $thumbSettings, $options),
					'lgSize' => "{$img['width']}-{$img['height']}",
					'name'   => $img['name'],
				];
				if ($showCaptions) {
					$captionText = $this->captionFromImageData($img, $captionTpl);
					if ($captionText !== '') {
						$item['subHtml'] = $captionTpl !== '' ? $captionText : htmlspecialchars($captionText);
					}
				}
				$dynamicEl[] = $item;
			}
			$dynamicTemplate = sprintf(
				'<template class="cms-gallery-dynamic">%s</template>',
				(string)json_encode($dynamicEl)
			);
		}

		// Don't add these to the gallery settings
		unset($options['collection']);
		unset($options['property']);
		unset($options['captions']); // Remove captions option from JS settings
		unset($options['gridCaptions']); // Remove gridCaptions option from JS settings
		unset($options['featuredOnly']); // Remove featuredOnly from JS settings
		unset($options['sort']); // Remove sort option from JS settings

		// Prevent lightGallery from using alt/title as caption fallback
		$options['getCaptionFromTitleOrAlt'] = false;

		// Extract custom class before encoding settings
		$customClass = '';
		if (isset($options['class'])) {
			$customClass = $options['class'];
			unset($options['class']);
		}

		// Extract maxVisible and viewAllText before encoding settings
		$maxVisible = 0;
		if (isset($options['maxVisible']) && $options['maxVisible'] > 0) {
			$maxVisible = (int)$options['maxVisible'];
			unset($options['maxVisible']);
		}

		$viewAllText = null;
		if (isset($options['viewAllText'])) {
			$viewAllText = $options['viewAllText'];
			unset($options['viewAllText']);
		}

		// Build CSS classes - always include 'cms-gallery', add custom class if provided
		$cssClasses = 'cms-gallery';
		if (!empty($customClass)) {
			$cssClasses .= ' ' . $customClass;
		}

		$attributes = [
			'class'         => $cssClasses,
			'data-settings' => (string)json_encode($options),
		];

		// Add max-visible attribute if provided
		if ($maxVisible > 0) {
			$attributes['data-max-visible'] = (string)$maxVisible;
			if ($viewAllText !== null) {
				$attributes['data-view-all-text'] = htmlspecialchars($viewAllText);
			}
		}

		$output = HTMLUtils::element('div', $gallery, $attributes);

		// Append dynamic template if featuredOnly mode has additional images
		if ($dynamicTemplate !== '') {
			$output .= $dynamicTemplate;
		}

		return $output;
	}

	/**
	 * Generate a dynamic gallery that can be triggered programmatically.
	 * Returns a template tag with JSON data for JavaScript initialization.
	 *
	 * @SuppressWarnings("PHPMD.ElseExpression")
	 *
	 * @param string|array<string,mixed> $idOrObject Object array or object ID string
	 * @param array<string,string|int> $thumbSettings
	 * @param array<string,string|int> $fullSettings
	 * @param array<string,mixed> $options
	 */
	public function galleryLauncher(string|array $idOrObject, array $thumbSettings = [], array $fullSettings = [], array $options = []): string
	{
		$options = array_merge([
			'collection' => 'gallery',
			'property'   => 'gallery',
		], $options);

		if ($thumbSettings === []) {
			$thumbSettings = ['w' => 300, 'h' => 200];
		}

		// Performance optimization: Extract gallery data from object if passed
		if (is_array($idOrObject)) {
			$id     = $idOrObject['id'] ?? '';
			$images = $idOrObject[$options['property']] ?? [];
		} else {
			$id     = $idOrObject;
			$images = $this->data($options['collection'], $id, $options['property']);
		}

		// Sort images if sort option is provided
		if (isset($options['sort']) && $options['sort'] !== '') {
			$images = $this->sortGalleryImages($images, $options['sort']);
		}

		// Check if captions should be shown in subHtml
		$showCaptions = !empty($options['captions']);
		$captionTpl   = isset($options['captions']) && $options['captions'] !== true ? trim((string)$options['captions']) : '';

		// Build dynamicEl array for lightGallery
		// Uses direct image data to avoid redundant galleryImageData() lookups per image
		$dynamicEl = [];
		foreach ($images as $image) {
			$item = [
				'src'    => $this->buildGalleryUrl($id, $image, $fullSettings, $options),
				'thumb'  => $this->buildGalleryUrl($id, $image, $thumbSettings, $options),
				'lgSize' => "{$image['width']}-{$image['height']}",
				'name'   => $image['name'], // Include name for image-based index lookup
			];

			// Add subHtml if captions are enabled and meaningful alt text exists
			if ($showCaptions) {
				$captionText = $this->captionFromImageData($image, $captionTpl);
				if ($captionText !== '') {
					$item['subHtml'] = $captionTpl !== '' ? $captionText : htmlspecialchars($captionText);
				}
			}

			$dynamicEl[] = $item;
		}

		// Generate unique gallery ID (allow override via options)
		$galleryId = $options['galleryId'] ?? "{$options['collection']}-{$id}";

		// Remove options that shouldn't be in JS settings
		unset($options['collection']);
		unset($options['property']);
		unset($options['captions']);
		unset($options['gridCaptions']);
		unset($options['galleryId']);
		unset($options['sort']);

		// Prevent lightGallery from using alt/title as caption fallback
		$options['getCaptionFromTitleOrAlt'] = false;

		// Build template attributes
		$attributes = [
			'data-gallery-id' => $galleryId,
			'data-settings'   => (string)json_encode($options),
		];

		// Convert attributes to HTML string
		$attributesString = '';
		foreach ($attributes as $key => $value) {
			$attributesString .= sprintf(' %s="%s"', $key, htmlspecialchars((string)$value, ENT_QUOTES));
		}

		// Return template tag with JSON content
		return sprintf(
			'<template%s>%s</template>',
			$attributesString,
			htmlspecialchars((string)json_encode($dynamicEl), ENT_QUOTES)
		);
	}

	/**
	 * @param string|array<string,mixed>|null $idOrObject Object array or object ID string
	 * @param array<string,mixed> $options
	 * @param array<string,string|int> $imageworks
	 */
	public function galleryImage(string|array|null $idOrObject, string|int|null $name, array $imageworks = [], array $options = []): string
	{
		$options = array_merge([
			'collection' => 'gallery',
			'property'   => 'gallery',
		], $options);

		if (in_array($idOrObject, [null, '', []], true) || $name === null || $name === '') {
			return '';
		}

		$imagePath = $this->galleryPath($idOrObject, $name, $imageworks, $options);
		if ($imagePath === '') {
			return '';
		}

		$image = $this->galleryImageData($idOrObject, $name, $options);
		$link  = $image['link'] ?? '';

		// Calculate dimensions for layout stability (prevents CLS)
		$dimensions = ImageDimensionCalculator::calculateFromImageData($image ?? [], $imageworks);

		// Determine gallery ID and image name for launcher integration
		$id        = is_array($idOrObject) ? ($idOrObject['id'] ?? '') : $idOrObject;
		$imageName = $image['name'] ?? (is_string($name) ? $name : '');

		$imgAttrs = [
			'src'                => $imagePath,
			'alt'                => $this->galleryAlt($idOrObject, $name, $options),
			'width'              => $dimensions['width'],
			'height'             => $dimensions['height'],
			'draggable'          => 'false',
			'oncontextmenu'      => 'return false;',
			'data-gallery'       => "{$options['collection']}-{$id}",
			'data-gallery-image' => $imageName,
			'class'              => $options['class'] ?? null,
			'loading'            => $options['loading'] ?? null,
		];

		$html = HTMLUtils::inlineElement('img', $imgAttrs);

		if (!empty($link)) {
			$html = HTMLUtils::element('a', $html, ['href' => $link]);
		}

		return $html;
	}

	/**
	 * Get an image object from inside a gallery by it's name.
	 *
	 * @SuppressWarnings("PHPMD.ElseExpression")
	 *
	 * @param string|array<string,mixed> $idOrObject Object array or object ID string
	 * @param array<string,mixed> $options
	 *
	 * @return array<string,mixed>|null
	 */
	public function galleryImageData(string|array $idOrObject, string|int $name, array $options = []): ?array
	{
		$options = array_merge([
			'collection' => 'gallery',
			'property'   => 'gallery',
		], $options);

		// Performance optimization: Extract gallery data from object if passed
		if (is_array($idOrObject)) {
			$gallery = $idOrObject[$options['property']] ?? null;
		} else {
			$gallery = $this->data($options['collection'], $idOrObject, $options['property']);
		}

		if (!is_array($gallery) || $gallery === []) {
			$this->logger->debug("No gallery data found for property '{$options['property']}'", ['idOrObject' => is_string($idOrObject) ? $idOrObject : 'object']);

			return null;
		}

		// Check if name is a numeric index (1-based for user-friendliness)
		if (is_numeric($name)) {
			$index  = (int)$name - 1; // Convert to 0-based
			$values = array_values($gallery); // Re-index array

			return $values[$index] ?? null;
		}

		// Handle keyword names (first, last, random, featured)
		$values = array_values($gallery);
		if ($name === 'first') {
			return $values[0] ?? null;
		}
		if ($name === 'last') {
			return $values[count($values) - 1] ?? null;
		}
		if ($name === 'random') {
			return $values[array_rand($values)] ?? null;
		}
		if ($name === 'featured') {
			$featured = array_filter($values, fn (array $img): bool => !empty($img['featured']));
			if ($featured !== []) {
				return $featured[array_rand($featured)];
			}

			// Fall back to random if no featured images
			return $values[array_rand($values)] ?? null;
		}

		foreach ($gallery as $image) {
			if ($image['name'] === $name) {
				return $image;
			}
		}

		return null;
	}

	/**
	 * @SuppressWarnings("PHPMD.ElseExpression")
	 *
	 * @param string|array<string,mixed>|null $idOrObject Object array or object ID string
	 * @param array<string,mixed> $options
	 * @param array<string,string|int> $imageworks
	 */
	public function galleryPath(string|array|null $idOrObject, string|int|null $name, array $imageworks = [], array $options = []): string
	{
		$options = array_merge([
			'collection' => 'gallery',
			'property'   => 'gallery',
		], $options);

		if (in_array($idOrObject, [null, '', []], true) || $name === null || $name === '') {
			return '';
		}

		// Resolve preset format for URL extension
		$imageworks = $this->resolvePresetFormat($imageworks);

		// Extract ID for URL building
		if (is_array($idOrObject)) {
			$id = $idOrObject['id'] ?? '';
			if ($id === '') {
				return '';
			}
		} else {
			$id = $idOrObject;
		}

		$image = $this->galleryImageData($idOrObject, $name, $options) ?? [];

		// When $name is a numeric index (int or string), get the actual filename from the resolved image
		$imageName = is_numeric($name) ? (string)($image['name'] ?? '') : $name;

		return self::buildImageworksGalleryAPI($this->api, $id, $imageName, $image, $imageworks, $options);
	}

	/**
	 * @param array<string,mixed> $image
	 * @param array<string,mixed> $options
	 * @param array<string,string|int> $imageworks
	 */
	public static function buildImageworksGalleryAPI(string $baseapi, string $id, string $name, array $image, array $imageworks = [], array $options = []): string
	{
		$options = array_merge([
			'collection' => 'gallery',
			'property'   => 'gallery',
		], $options);

		$collection = $options['collection'];
		$property   = $options['property'];

		// Default to dynamic API routes
		$api                 = $baseapi . "/imageworks/$collection/$id/$property/$name";
		$imageworks['cache'] = uniqid();
		$dynamicRoutes       = ['first', 'last', 'random', 'featured'];

		// Process the image as regular filename
		if (!in_array($name, $dynamicRoutes)) {
			if (!array_key_exists('uploadDate', $image)) {
				return '';
			}

			// Default to original image type
			$type = strtolower(pathinfo((string)$image['name'], PATHINFO_EXTENSION));
			// If type is set in imageworks, use that
			if (array_key_exists('fm', $imageworks)) {
				$type = $imageworks['fm'];
				unset($imageworks['fm']);
			}
			// If type is not in the list of allowed types, default to jpg
			$type     = in_array($type, GlideFactory::IMG_TYPES) ? $type : 'jpg';
			$basename = pathinfo($name)['filename'];

			$api = $baseapi . "/imageworks/$collection/$id/$property/$basename.$type";

			// cache busting links
			$imageworks['cache'] = strrev((string)preg_replace('/\W+/', '', (string)$image['uploadDate']));
		}

		// From Stacks Preview Server - Not used in Imageworks and breaks the image generation
		unset($imageworks['datadir']);
		unset($imageworks['route']);

		// Parse the existing URL and its query parameters
		$parsedUrl = parse_url($api);

		if (!isset($parsedUrl['path'])) {
			return '';
		}

		$existingParams = [];
		if (isset($parsedUrl['query'])) {
			parse_str($parsedUrl['query'], $existingParams);
		}

		// Merge the existing parameters with the new imageworks
		$imageworks = array_merge($existingParams, $imageworks);

		// Reconstruct the URL without the original query string, and append the new query string
		$api = $parsedUrl['path'] . '?' . http_build_query($imageworks);

		return $api;
	}

	/**
	 * Get an alt tag for an image.
	 *
	 * @SuppressWarnings("PHPMD.ElseExpression")
	 *
	 * @param string|array<string,mixed> $idOrObject Object array or object ID string
	 * @param array<string,mixed> $options
	 */
	public function alt(string|array $idOrObject, array $options = []): string
	{
		$options = array_merge([
			'collection' => 'image',
			'property'   => 'image',
		], $options);

		// Performance optimization: Extract image data from object if passed
		if (is_array($idOrObject)) {
			$image = $idOrObject[$options['property']] ?? null;
		} else {
			$image = $this->data($options['collection'], $idOrObject, $options['property']);
		}

		if (!is_array($image)) {
			return '';
		}

		if (!empty($image['alt'])) {
			return $image['alt'];
		}
		if (!empty($image['exif']['title'])) {
			return $image['exif']['title'];
		}
		if (!empty($image['exif']['description'])) {
			return $image['exif']['description'];
		}

		return $image['name'] ?? '';
	}

	/**
	 * Get an alt tag for a gallery image.
	 *
	 * @param string|array<string,mixed> $idOrObject Object array or object ID string
	 * @param array<string,mixed> $options
	 */
	public function galleryAlt(string|array $idOrObject, string|int $name, array $options = []): string
	{
		$options = array_merge([
			'collection' => 'gallery',
			'property'   => 'gallery',
		], $options);

		$image = $this->galleryImageData($idOrObject, $name, $options);

		if (!is_array($image)) {
			return '';
		}

		if (!empty($image['alt'])) {
			return $image['alt'];
		}
		if (!empty($image['exif']['title'])) {
			return $image['exif']['title'];
		}
		if (!empty($image['exif']['description'])) {
			return $image['exif']['description'];
		}

		return $image['name'] ?? '';
	}

	/**
	 * Build an ImageWorks URL for a gallery image using pre-loaded image data.
	 * Avoids redundant galleryImageData() lookups when image data is already available.
	 *
	 * @param array<string,mixed> $image
	 * @param array<string,string|int> $imageworks
	 * @param array<string,mixed> $options
	 */
	private function buildGalleryUrl(string $id, array $image, array $imageworks, array $options): string
	{
		$imageworks = $this->resolvePresetFormat($imageworks);

		return self::buildImageworksGalleryAPI($this->api, $id, $image['name'] ?? '', $image, $imageworks, $options);
	}

	/**
	 * Get alt text for a gallery image using pre-loaded image data.
	 * Avoids redundant galleryImageData() lookups when image data is already available.
	 *
	 * @param array<string,mixed> $image
	 */
	private function altFromImageData(array $image): string
	{
		if (!empty($image['alt'])) {
			return $image['alt'];
		}
		if (!empty($image['exif']['title'])) {
			return $image['exif']['title'];
		}
		if (!empty($image['exif']['description'])) {
			return $image['exif']['description'];
		}

		return $image['name'] ?? '';
	}

	/**
	 * Get caption text for a gallery image using pre-loaded image data.
	 * Avoids redundant galleryImageData() lookups when image data is already available.
	 *
	 * @param array<string,mixed> $image
	 */
	private function captionFromImageData(array $image, string $template = ''): string
	{
		if ($template !== '') {
			return $this->renderCaptionTemplate($template, $image);
		}

		if (!empty($image['alt'])) {
			return $image['alt'];
		}
		if (!empty($image['exif']['title'])) {
			return $image['exif']['title'];
		}
		if (!empty($image['exif']['description'])) {
			return $image['exif']['description'];
		}

		return '';
	}

	/**
	 * Sort gallery images using CollectionSorter.
	 *
	 * @param array<array<string,mixed>> $images
	 * @param string|array<array<string,mixed>> $sort Sort option: string property name (prefix with '-' for reverse) or array of rule arrays
	 *
	 * @return array<array<string,mixed>>
	 */
	private function sortGalleryImages(array $images, string|array $sort): array
	{
		if (is_string($sort)) {
			$reverse  = str_starts_with($sort, '-');
			$property = $reverse ? substr($sort, 1) : $sort;
			$rules    = [['property' => $property, 'reverse' => $reverse]];
		} else {
			$rules = $sort;
		}

		$sorter = new CollectionSorter($images);

		return $sorter->sortByRules($rules);
	}

	/**
	 * Get caption text for a gallery image.
	 * Same fallback chain as galleryAlt() but WITHOUT the filename fallback,
	 * since filenames make poor visible captions.
	 *
	 * @param string|array<string,mixed> $idOrObject Object array or object ID string
	 * @param array<string,mixed> $options
	 */
	public function galleryCaption(string|array $idOrObject, string|int $name, array $options = [], string $template = ''): string
	{
		$options = array_merge([
			'collection' => 'gallery',
			'property'   => 'gallery',
		], $options);

		$image = $this->galleryImageData($idOrObject, $name, $options);

		if (!is_array($image)) {
			return '';
		}

		// Template mode: render using lightweight Twig engine
		if ($template !== '') {
			return $this->renderCaptionTemplate($template, $image);
		}

		// Default fallback chain (no filename)
		if (!empty($image['alt'])) {
			return $image['alt'];
		}
		if (!empty($image['exif']['title'])) {
			return $image['exif']['title'];
		}
		if (!empty($image['exif']['description'])) {
			return $image['exif']['description'];
		}

		return '';
	}

	/**
	 * Get a lightweight Twig environment for rendering caption templates.
	 * This is separate from the main TwigEngine to avoid circular dependencies.
	 */
	private function getCaptionTwig(): TwigEnvironment
	{
		if (!$this->captionTwig instanceof TwigEnvironment) {
			$this->captionTwig = new TwigEnvironment(new ArrayLoader(), [
				'autoescape'       => false,
				'strict_variables' => false,
			]);
		}

		return $this->captionTwig;
	}

	/**
	 * Render a caption template using a lightweight Twig environment.
	 * Image data fields are available directly (e.g., {{ alt }}, {{ exif.camera }}).
	 * Returns empty string if all output is whitespace/separators.
	 *
	 * @param array<string,mixed> $image
	 */
	private function renderCaptionTemplate(string $template, array $image): string
	{
		try {
			$template = str_replace(['{', '}'], ['{{', '}}'], $template);
			$twig     = $this->getCaptionTwig();
			$tmpl     = $twig->createTemplate($template);
			$result   = trim($tmpl->render($image));

			// If the result has no meaningful text content, treat as empty
			if (trim(strip_tags($result)) === '') {
				return '';
			}

			return $result;
		} catch (\Exception $e) {
			$this->logger->warning('Gallery caption template error: ' . $e->getMessage(), ['template' => $template]);

			return '';
		}
	}

	/**
	 * Check if a password is already encrypted (base64 encoded).
	 * Encrypted passwords from Cipher::encrypt() are base64 encoded strings.
	 */
	private function isEncryptedPassword(string $password): bool
	{
		// Check if string is valid base64 and has reasonable length for encrypted data
		if (base64_decode($password, true) === false) {
			return false;
		}

		// Encrypted passwords should be longer than typical plain passwords
		return strlen($password) > 20;
	}

	/**
	 * Check if a schema is compatible with deck usage.
	 */
	public function isDeckCompatible(string $schemaId): bool
	{
		try {
			$schema = $this->schemaFetcher->fetchSchema($schemaId);

			return $this->deckCompatibilityChecker->isCompatible($schema->toArray());
		} catch (\Exception $e) {
			$this->logger->warning("Schema '{$schemaId}' not found for deck compatibility check", ['error' => $e->getMessage()]);

			return false;
		}
	}

	/**
	 * Get incompatible property types for a schema when used with deck.
	 *
	 * @return array<string>
	 */
	public function getDeckIncompatibleTypes(string $schemaId): array
	{
		try {
			$schema = $this->schemaFetcher->fetchSchema($schemaId);

			return $this->deckCompatibilityChecker->getSchemaIncompatibleTypes($schema->toArray());
		} catch (\Exception $e) {
			$this->logger->warning("Schema '{$schemaId}' not found for deck incompatible types check", ['error' => $e->getMessage()]);

			return [];
		}
	}

	/**
	 * Group templates by folder for display in admin sidebar.
	 *
	 * @return array<string,array<array<string,string>>>
	 */
	public function templatesByFolder(): array
	{
		// Get all templates recursively
		$templates = $this->templateLister->listCustomTemplates(null, true);

		$folders = [];

		foreach ($templates as $path) {
			// Parse path to get folder and template name
			[$folder, $templateId] = TemplateRepository::parsePath($path);

			// Determine group name
			$groupName = 'Templates';
			if ($folder !== null) {
				// Convert folder path to group name (e.g., "pages/blog" -> "Pages / Blog")
				$parts     = explode('/', str_replace('-', ' ', $folder));
				$groupName = implode(' / ', array_map(ucwords(...), $parts));
			}

			// Create template entry
			if (!array_key_exists($groupName, $folders)) {
				$folders[$groupName] = [];
			}

			$folders[$groupName][] = [
				'id'     => $templateId,
				'folder' => $folder ?? '',
				'path'   => $path, // Full path for linking
			];
		}

		// Sort folders alphabetically, but keep "Templates" (root) at the bottom
		uksort($folders, function ($a, $b): int {
			if ($a === 'Templates') {
				return 1;
			}
			if ($b === 'Templates') {
				return -1;
			}

			return strcmp($a, $b);
		});

		return $folders;
	}

	/**
	 * Check if current user can perform a CRUD operation on a collection.
	 */
	public function canAccessCollection(string $collection, string $operation = 'read'): bool
	{
		// If auth is disabled globally, allow everything
		if ($this->config->auth['enable'] === false) {
			return true;
		}

		$userData = $this->accessManager->userData();
		if ($userData === [] || !isset($userData['id'])) {
			return false;
		}

		return $this->accessControl->canAccessCollection($userData['id'], $collection, $operation);
	}

	/**
	 * Get collections the current user can access with a given CRUD operation.
	 *
	 * @param string $operation CRUD operation (create, read, update, delete)
	 *
	 * @return array<string> Collection IDs user can access
	 */
	public function getAccessibleCollections(string $operation = 'read'): array
	{
		$allCollections = $this->collectionLister->listAllCollections();
		$accessible     = [];

		foreach ($allCollections as $collection) {
			if ($this->canAccessCollection($collection->id, $operation)) {
				$accessible[] = $collection->id;
			}
		}

		return $accessible;
	}

	/**
	 * Check if current user can perform a CRUD operation on collections in general.
	 * Use for actions like "New Collection" that don't target a specific collection.
	 */
	public function canAccessCollectionsOperation(string $operation = 'read'): bool
	{
		// If auth is disabled globally, allow everything
		if ($this->config->auth['enable'] === false) {
			return true;
		}

		$userData = $this->accessManager->userData();
		if ($userData === [] || !isset($userData['id'])) {
			return false;
		}

		return $this->accessControl->canAccessCollectionsOperation($userData['id'], $operation);
	}

	/**
	 * Check if current user can perform an action on a collection's metadata.
	 */
	public function canAccessCollectionMeta(string $collection, string $operation = 'read'): bool
	{
		// If auth is disabled globally, allow everything
		if ($this->config->auth['enable'] === false) {
			return true;
		}

		$userData = $this->accessManager->userData();
		if ($userData === [] || !isset($userData['id'])) {
			return false;
		}

		return $this->accessControl->canAccessCollectionMeta($userData['id'], $collection, $operation);
	}

	/**
	 * Check if current user can perform a CRUD operation on collection metadata in general.
	 * Use for actions like viewing the collections list or creating new collections.
	 */
	public function canAccessCollectionsMetaOperation(string $operation = 'read'): bool
	{
		// If auth is disabled globally, allow everything
		if ($this->config->auth['enable'] === false) {
			return true;
		}

		$userData = $this->accessManager->userData();
		if ($userData === [] || !isset($userData['id'])) {
			return false;
		}

		return $this->accessControl->canAccessCollectionsMetaOperation($userData['id'], $operation);
	}

	/**
	 * Check if current user can perform a CRUD operation on a schema.
	 */
	public function canAccessSchema(string $schema, string $operation = 'read'): bool
	{
		// If auth is disabled globally, allow everything
		if ($this->config->auth['enable'] === false) {
			return true;
		}

		$userData = $this->accessManager->userData();
		if ($userData === [] || !isset($userData['id'])) {
			return false;
		}

		return $this->accessControl->canAccessSchema($userData['id'], $schema, $operation);
	}

	/**
	 * Check if current user can perform a CRUD operation on schemas in general.
	 * Use for actions like "New Schema" that don't target a specific schema.
	 */
	public function canAccessSchemasOperation(string $operation = 'read'): bool
	{
		// If auth is disabled globally, allow everything
		if ($this->config->auth['enable'] === false) {
			return true;
		}

		$userData = $this->accessManager->userData();
		if ($userData === [] || !isset($userData['id'])) {
			return false;
		}

		return $this->accessControl->canAccessSchemasOperation($userData['id'], $operation);
	}

	/**
	 * Check if current user can access templates (boolean check).
	 */
	public function canAccessTemplates(): bool
	{
		// If auth is disabled globally, allow everything
		if ($this->config->auth['enable'] === false) {
			return true;
		}

		$userData = $this->accessManager->userData();
		if ($userData === [] || !isset($userData['id'])) {
			return false;
		}

		return $this->accessControl->canAccessTemplates($userData['id']);
	}

	/**
	 * Check if current user can access a specific utils page.
	 */
	public function canAccessUtil(string $page): bool
	{
		// If auth is disabled globally, allow everything
		if ($this->config->auth['enable'] === false) {
			return true;
		}

		$userData = $this->accessManager->userData();
		if ($userData === [] || !isset($userData['id'])) {
			return false;
		}

		return $this->accessControl->canAccessUtils($userData['id'], $page);
	}

	/**
	 * Check if current user has ANY access to utils (boolean check).
	 */
	public function canAccessUtils(): bool
	{
		// If auth is disabled globally, allow everything
		if ($this->config->auth['enable'] === false) {
			return true;
		}

		$userData = $this->accessManager->userData();
		if ($userData === [] || !isset($userData['id'])) {
			return false;
		}

		return $this->accessControl->canAccessAnyUtils($userData['id']);
	}

	/**
	 * Check if current user can access mailer.
	 */
	public function canAccessMailer(): bool
	{
		// If auth is disabled globally, allow everything
		if ($this->config->auth['enable'] === false) {
			return true;
		}

		$userData = $this->accessManager->userData();
		if ($userData === [] || !isset($userData['id'])) {
			return false;
		}

		return $this->accessControl->canAccessMailer($userData['id']);
	}

	/**
	 * Check if current user can access playground.
	 */
	public function canAccessPlayground(): bool
	{
		// If auth is disabled globally, allow everything
		if ($this->config->auth['enable'] === false) {
			return true;
		}

		$userData = $this->accessManager->userData();
		if ($userData === [] || !isset($userData['id'])) {
			return false;
		}

		return $this->accessControl->canAccessPlayground($userData['id']);
	}

	/**
	 * Check if current user can access docs.
	 */
	public function canAccessDocs(): bool
	{
		// If auth is disabled globally, allow everything
		if ($this->config->auth['enable'] === false) {
			return true;
		}

		$userData = $this->accessManager->userData();
		if ($userData === [] || !isset($userData['id'])) {
			return false;
		}

		return $this->accessControl->canAccessDocs($userData['id']);
	}

	/**
	 * Check if user is in admin group (bypasses all access controls).
	 */
	public function isAdmin(): bool
	{
		// If auth is disabled globally, allow everything
		if ($this->config->auth['enable'] === false) {
			return true;
		}

		$userData = $this->accessManager->userData();
		if ($userData === [] || !isset($userData['id'])) {
			return false;
		}

		return $this->accessControl->isAdmin($userData['id']);
	}

	// -------------------------
	// Dashboard Data Methods
	// -------------------------

	/**
	 * Get dashboard statistics.
	 *
	 * @return array<string,int>
	 */
	public function dashboardStats(): array
	{
		$collections = $this->collectionLister->listAllCollections();
		$schemas     = $this->schemaLister->listCustomSchemas();
		$templates   = $this->templateLister->listCustomTemplates();

		// Sum totalObjects from all collections (much faster than counting index objects)
		$totalObjects = 0;
		foreach ($collections as $collection) {
			$totalObjects += $collection->totalObjects;
		}

		// Get job queue stats
		$totalJobs = count($this->jobManager->getPendingJobs()) + count($this->jobManager->getFailedJobs());

		return [
			'collections'  => count($collections),
			'schemas'      => count($schemas),
			'templates'    => count($templates),
			'totalObjects' => $totalObjects,
			'totalJobs'    => $totalJobs,
		];
	}

	/**
	 * Get recent collections (top 10 by last updated).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function dashboardRecentCollections(): array
	{
		// Get all collections
		$collections = $this->collectionLister->listAllCollections();

		$result = [];

		foreach ($collections as $collection) {
			if (!$this->canAccessCollection($collection->id)) {
				continue;
			}
			// Skip auth collections (frequently updated on login, clutters recent list)
			if ($collection->schema === 'auth') {
				continue;
			}
			$result[] = [
				'id'           => $collection->id,
				'name'         => $collection->name,
				'schema'       => $collection->schema,
				'objectCount'  => $collection->totalObjects,
				'lastModified' => $collection->lastUpdated !== '' ? $collection->lastUpdated : null,
				'addUrl'       => "collections/{$collection->id}/add",
				'viewUrl'      => "collections/{$collection->id}",
			];
		}

		// Sort by lastUpdated (most recent first)
		usort($result, function (array $a, array $b): int {
			// Handle null lastModified values (put them at the end)
			if ($a['lastModified'] === null && $b['lastModified'] === null) {
				return 0;
			}
			if ($a['lastModified'] === null) {
				return 1;
			}
			if ($b['lastModified'] === null) {
				return -1;
			}

			// Sort by date descending (most recent first)
			return $b['lastModified'] <=> $a['lastModified'];
		});

		// Return top 10 most recently updated
		return array_slice($result, 0, 10);
	}

	/**
	 * Get collections that have no objects (might need attention).
	 * Always checks ALL collections, not just custom ones.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function dashboardEmptyCollections(): array
	{
		$collections = $this->collectionLister->listAllCollections();
		$result      = [];

		foreach ($collections as $collection) {
			// Only include empty collections using cached totalObjects field
			if ($collection->totalObjects === 0 && $this->canAccessCollection($collection->id)) {
				$result[] = [
					'id'      => $collection->id,
					'name'    => $collection->name,
					'schema'  => $collection->schema,
					'addUrl'  => "collections/{$collection->id}/add",
					'viewUrl' => "collections/{$collection->id}",
				];
			}
		}

		// Sort by name
		usort($result, fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

		return $result;
	}

	/**
	 * Get system status information.
	 *
	 * @return array<string,mixed>
	 */
	public function dashboardSystemStatus(): array
	{
		$cacheStats    = $this->cacheReporter->getCacheStats();
		$services      = $cacheStats['services'] ?? [];
		$enabledCaches = array_filter(
			$services,
			fn ($cache): bool => is_array($cache) && isset($cache['available']) && $cache['available'] === true
		);

		$licenseStatus = $this->license->getSidebarStatus();

		return [
			'phpVersion'       => PHP_VERSION,
			'totalcmsVersion'  => $this->config->version ?? '3.0',
			'cacheBackends'    => array_keys($enabledCaches),
			'memoryLimit'      => ini_get('memory_limit'),
			'maxExecutionTime' => ini_get('max_execution_time'),
			'environment'      => $this->env,
			'license'          => [
				'severity'      => $licenseStatus->severity,
				'message'       => $licenseStatus->tooltip,
				'daysRemaining' => $licenseStatus->daysRemaining,
			],
		];
	}

	/**
	 * Get recent objects across all collections (last 10).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function dashboardRecentObjects(): array
	{
		$collections   = $this->collectionLister->listCustomCollections();
		$recentObjects = [];

		foreach ($collections as $collection) {
			try {
				$index = $this->indexReader->fetchIndex($collection->id);

				foreach ($index->objects as $object) {
					if (!isset($object['onUpdate']) && !isset($object['onCreate'])) {
						continue;
					}

					$timestamp = $object['onUpdate'] ?? $object['onCreate'] ?? null;
					if ($timestamp === null) {
						continue;
					}

					$recentObjects[] = [
						'id'             => $object['id'] ?? '',
						'collection'     => $collection->id,
						'collectionName' => $collection->name,
						'schema'         => $collection->schema,
						'timestamp'      => $timestamp,
						'editUrl'        => "collections/{$collection->id}/{$object['id']}",
						// Try to get a display name from common fields
						'displayName' => $object['title'] ?? $object['name'] ?? $object['id'] ?? 'Untitled',
					];
				}
			} catch (\Exception) {
				// Skip if collection has no index
				continue;
			}
		}

		// Sort by timestamp descending
		usort($recentObjects, fn (array $a, array $b): int => $b['timestamp'] <=> $a['timestamp']);

		// Return only the 10 most recent
		return array_slice($recentObjects, 0, 10);
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
	public function getUrlTemplateFields(string $collectionId): array
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
			$host   = $_SERVER['HTTP_HOST'] ?? $this->config->domain;
			$url    = $scheme . '://' . $host . $url;
		}

		return $url;
	}
}
