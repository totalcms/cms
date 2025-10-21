<?php

namespace TotalCMS\Domain\Twig\Adapter;

use Odan\Session\PhpSession;
use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Auth\Service\FileAccessManager;
use TotalCMS\Domain\Cache\CacheReporter;
use TotalCMS\Domain\Cache\Service\DevModeManager;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Collection\Utilities\PaginationGenerator;
use TotalCMS\Domain\ImageWorks\Service\GlideFactory;
use TotalCMS\Domain\ImageWorks\Service\ImageCacheService;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Index\Service\IndexSearcher;
use TotalCMS\Domain\JobQueue\Repository\JobRepository;
use TotalCMS\Domain\JobQueue\Service\JobManager;
use TotalCMS\Domain\License\Service\LicenseStatus;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\DeckCompatibilityChecker;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Security\Encryption\Cipher;
use TotalCMS\Domain\Template\Repository\TemplateRepository;
use TotalCMS\Domain\Twig\Service\GridRenderer;
use TotalCMS\Infrastructure\Diagnostics\LogAnalyzer;
use TotalCMS\Infrastructure\Diagnostics\ServerChecker;
use TotalCMS\Support\Config;

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
	public string $env;
	public string $api;
	public string $dashboard;
	public string $login;
	public string $logout;
	public string $domain;
	public string $clearcache;

	public function __construct(
		private readonly Config $config,
		private readonly IndexReader $indexReader,
		private readonly IndexSearcher $indexSearcher,
		private readonly ObjectFetcher $objectFetcher,
		private readonly CollectionLister $collectionLister,
		private readonly CollectionFetcher $collectionFetcher,
		private readonly SchemaLister $schemaLister,
		private readonly SchemaFetcher $schemaFetcher,
		private readonly DeckCompatibilityChecker $deckCompatibilityChecker,
		private readonly TemplateRepository $templateRepository,
		public TotalFormFactory $form,
		public ServerChecker $checker,
		public CacheReporter $cacheReporter,
		public LogAnalyzer $logger,
		private readonly PhpSession $session,
		private readonly AccessManager $accessManager,
		private readonly FileAccessManager $fileAccessManager,
		private readonly AccessControlService $accessControl,
		public ImageCacheService $imageCacheService,
		public GridRenderer $grid,
		private readonly DevModeManager $devModeManager,
		public LicenseStatus $license,
	) {
		$this->env        = $this->config->env;
		$this->api        = $this->config->api;
		$this->clearcache = $this->api . '/emergency/cache/clear';
		$this->dashboard  = $this->api . '/admin';
		$this->logout     = $this->api . '/logout';
		$this->domain     = $this->getDomainName();
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
		$jobManager = new JobManager(
			new JobRepository()
		);

		$pendingJobs = $jobManager->getPendingJobs();

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
		$jobManager = new JobManager(
			new JobRepository()
		);

		$failedJobs = $jobManager->getFailedJobs();

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
	 * @param array<mixed> $object
	 */
	public function redirectIfNotFound(array $object = []): void
	{
		if ($object === []) {
			$notfound = $this->config->notfound;
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

	public function config(string $key, ?string $setting): mixed
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

	// Get all schemas
	/** @return array<array<string,mixed>> */
	public function schemas(): array
	{
		$schemas = $this->schemaLister->listAllSchemas();

		return array_map(fn (SchemaData $schema): array => $schema->toArray(), $schemas);
	}

	// Get all reserved schemas
	/** @return array<array<string,mixed>> */
	public function reservedSchemas(): array
	{
		$schemas = $this->schemaLister->listReservedSchemas();

		return array_map(fn (SchemaData $schema): array => $schema->toArray(), $schemas);
	}

	// Get all custom schemas
	/** @return array<array<string,mixed>> */
	public function customSchemas(): array
	{
		$schemas = $this->schemaLister->listCustomSchemas();

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

	// Get all collections
	/** @return array<object> */
	public function collections(): array
	{
		return $this->collectionLister->listAllCollections();
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

	// Get collection meta data
	/** @return array<string,mixed> */
	public function collection(string $collection): array
	{
		$collection = $this->collectionFetcher->fetchCollection($collection);

		if (!$collection instanceof CollectionData) {
			return [];
		}

		return $collection->toArray();
	}

	public function objectUrl(string $collection, string $id): string
	{
		$collection = $this->collectionFetcher->fetchCollection($collection);
		if (!$collection instanceof CollectionData) {
			return '';
		}

		return CollectionData::objectUrl($collection, $id);
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
		} catch (\Exception) {
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
		} catch (\Exception) {
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
		} catch (\Exception) {
			return [];
		}

		return $object->toArray();
	}

	/** @param array<string,string> $options */
	public function download(string $id, array $options = []): string
	{
		$collection = $options['collection'] ?? 'file';
		$property   = $options['property'] ?? 'file';
		$password   = $options['pwd'] ?? '';

		$url = "{$this->api}/download/{$collection}/{$id}/{$property}";

		// Auto-encrypt password if provided and not already encrypted
		if (!empty($password) && !$this->isEncryptedPassword($password)) {
			$password = Cipher::encrypt($password);
		}

		if (!empty($password)) {
			$url .= '?pwd=' . urlencode($password);
		}

		return $url;
	}

	/**
	 * @param array<string,string> $fileOptions
	 * @param array<string,mixed> $options
	 */
	public function depotDownload(string $id, string $name, array $fileOptions = [], array $options = []): string
	{
		$collection = $options['collection'] ?? 'depot';
		$property   = $options['property'] ?? 'depot';
		$path       = $fileOptions['path'] ?? '';
		$password   = $fileOptions['pwd'] ?? '';

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
			'path' => trim($path, '/'),
			'pwd'  => $password,
		]));

		if ($query !== '') {
			$url .= "?$query";
		}

		return $url;
	}

	/** @param array<string,string> $options */
	public function stream(string $id, array $options = []): string
	{
		$collection = $options['collection'] ?? 'file';
		$property   = $options['property'] ?? 'file';
		$password   = $options['pwd'] ?? '';

		$url = "{$this->api}/stream/{$collection}/{$id}/{$property}";

		// Auto-encrypt password if provided and not already encrypted
		if (!empty($password) && !$this->isEncryptedPassword($password)) {
			$password = Cipher::encrypt($password);
		}

		if (!empty($password)) {
			$url .= '?pwd=' . urlencode($password);
		}

		return $url;
	}

	/**
	 * @param array<string,string> $fileOptions
	 * @param array<string,mixed> $options
	 */
	public function depotStream(string $id, string $name, array $fileOptions = [], array $options = []): string
	{
		$collection = $options['collection'] ?? 'depot';
		$property   = $options['property'] ?? 'depot';
		$path       = $fileOptions['path'] ?? '';
		$password   = $fileOptions['pwd'] ?? '';

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
			'path' => trim($path, '/'),
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
	 * @param array<string,string|int> $imageworks
	 * @param array<string,mixed> $options
	 */
	public function image(?string $id, array $imageworks = [], array $options = []): string
	{
		$options = array_merge([
			'collection' => 'image',
			'property'   => 'image',
			'loading'    => 'lazy',
		], $options);

		if ($id === null || $id === '') {
			return '';
		}

		$imagePath = $this->imagePath($id, $imageworks, $options);
		if ($imagePath === '') {
			return '';
		}

		$image = $this->data($options['collection'], $id, $options['property']);

		$html = HTMLUtils::inlineElement('img', [
			'src'           => $imagePath,
			'alt'           => $image['alt'],
			'loading'       => $options['loading'],
			'draggable'     => 'false',
			'oncontextmenu' => 'return false;',
		]);

		if (!empty($image['link'])) {
			$html = HTMLUtils::element('a', $html, ['href' => $image['link']]);
		}

		return $html;
	}

	/**
	 * Create image HTML from provided image data (without fetching from CMS).
	 *
	 * @param array<string,mixed> $imageData Image data array
	 * @param string $id of the object with the image
	 * @param array<string,string|int> $imageworks Imageworks processing options
	 * @param array<string,mixed> $options Additional options
	 *
	 * @return string Image HTML
	 */
	public function imageFromData(array $imageData, string $id, array $imageworks = [], array $options = []): string
	{
		if ($imageData === []) {
			return '';
		}

		$options = array_merge([
			'collection' => 'image',
			'property'   => 'image',
			'loading'    => 'lazy',
		], $options);

		if ($id === '') {
			return '';
		}

		// Build path using buildImageworksAPI if we have enough data
		$imagePath = self::buildImageworksAPI($this->api, $id, $imageData, $imageworks, $options);
		if ($imagePath === '') {
			return '';
		}

		// Get alt text from various possible keys
		$alt = $imageData['alt'] ?? $imageData['exif']['title'] ?? $imageData['exif']['description'] ?? $id;

		$html = HTMLUtils::inlineElement('img', [
			'src'           => $imagePath,
			'alt'           => $alt,
			'loading'       => $options['loading'],
			'draggable'     => 'false',
			'oncontextmenu' => 'return false;',
		]);

		// Add link wrapper if present
		$link = $imageData['link'] ?? '';
		if (!empty($link)) {
			$html = HTMLUtils::element('a', $html, ['href' => $link]);
		}

		return $html;
	}

	// Get the image path for an image property
	/**
	 * @param array<string,mixed> $options
	 * @param array<string,string|int> $imageworks
	 */
	public function imagePath(?string $id, array $imageworks = [], array $options = []): string
	{
		$options = array_merge([
			'collection' => 'image',
			'property'   => 'image',
		], $options);

		if ($id === null || $id === '') {
			return '';
		}

		$collection = $options['collection'];
		$property   = $options['property'];

		$image = $this->data($collection, $id, $property);
		if (!is_array($image) || !array_key_exists('size', $image) || $image['size'] === 0) {
			return '';
		}

		return self::buildImageworksAPI($this->api, $id, $image, $imageworks, $options);
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

		if ($image === [] || !array_key_exists('name', $image)) {
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
	 * @param array<string,string|int> $thumbSettings
	 * @param array<string,string|int> $fullSettings
	 * @param array<string,mixed> $options
	 */
	public function gallery(string $id, array $thumbSettings = [], array $fullSettings = [], array $options = []): string
	{
		$options = array_merge([
			'collection' => 'gallery',
			'property'   => 'gallery',
		], $options);

		if ($thumbSettings === []) {
			$thumbSettings = ['w' => 300, 'h' => 200];
		}

		$gallery = '';

		$images = $this->data($options['collection'], $id, $options['property']);

		// Check if captions should be shown
		$showCaptions = isset($options['captions']) && $options['captions'];

		foreach ($images as $image) {
			$img = HTMLUtils::inlineElement('img', [
				'src'           => $this->galleryPath($id, $image['name'], $thumbSettings, $options),
				'alt'           => $image['alt'],
				'loading'       => 'lazy',
				'draggable'     => 'false',
				'oncontextmenu' => 'return false;',
			]);
			$link = HTMLUtils::element('a', $img, [
				'href' => $this->galleryPath($id, $image['name'], $fullSettings, $options),
			]);

			// Always wrap in figure for semantic HTML5
			$figureContent = $link;
			if ($showCaptions && !empty($image['alt'])) {
				$caption = HTMLUtils::element('figcaption', htmlspecialchars((string)$image['alt']), ['class' => 'cms-gallery-caption']);
				$figureContent .= $caption;
			}

			$figure = HTMLUtils::element('figure', $figureContent, [
				'class'        => 'cms-gallery-item',
				'data-src'     => $this->galleryPath($id, $image['name'], $fullSettings, $options),
				'data-lg-size' => "{$image['width']}-{$image['height']}",
			]);
			$gallery .= $figure;
		}

		// Don't add these to the gallery settings
		unset($options['collection']);
		unset($options['property']);
		unset($options['captions']); // Remove captions option from JS settings

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

		return HTMLUtils::element('div', $gallery, $attributes);
	}

	/**
	 * @param array<string,mixed> $options
	 * @param array<string,string|int> $imageworks
	 */
	public function galleryImage(?string $id, ?string $name, array $imageworks = [], array $options = []): string
	{
		$options = array_merge([
			'collection' => 'gallery',
			'property'   => 'gallery',
		], $options);

		if ($id === null || $id === '' || $id === '0' || ($name === null || $name === '' || $name === '0')) {
			return '';
		}

		$imagePath = $this->galleryPath($id, $name, $imageworks, $options);
		if ($imagePath === '') {
			return '';
		}

		$image = $this->galleryImageData($id, $name, $options);
		$alt   = $image['alt'] ?? $this->galleryAlt($id, $name, $options);
		$link  = $image['link'] ?? '';

		$html = HTMLUtils::inlineElement('img', [
			'src'           => $imagePath,
			'alt'           => $alt,
			'draggable'     => 'false',
			'oncontextmenu' => 'return false;',
		]);

		if (!empty($link)) {
			$html = HTMLUtils::element('a', $html, ['href' => $link]);
		}

		return $html;
	}

	// get an image object from inside a gallery by it's name
	/**
	 * @param array<string,mixed> $options
	 *
	 * @return array<string,mixed>
	 */
	public function galleryImageData(string $id, string $name, array $options = []): ?array
	{
		$options = array_merge([
			'collection' => 'gallery',
			'property'   => 'gallery',
		], $options);

		$gallery = $this->data($options['collection'], $id, $options['property']);
		if (!is_array($gallery)) {
			return null;
		}

		$image = array_filter($gallery, fn (array $image): bool => pathinfo((string)$image['name'])['filename'] === $name);

		foreach ($gallery as $image) {
			if ($image['name'] === $name) {
				return $image;
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $options
	 * @param array<string,string|int> $imageworks
	 */
	public function galleryPath(?string $id, ?string $name, array $imageworks = [], array $options = []): string
	{
		$options = array_merge([
			'collection' => 'gallery',
			'property'   => 'gallery',
		], $options);

		if ($id === null || $id === '' || $name === null || $name === '') {
			return '';
		}

		$image = $this->galleryImageData($id, $name, $options) ?? [];

		return self::buildImageworksGalleryAPI($this->api, $id, $name, $image, $imageworks, $options);
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

	// Get an alt tag for an image
	/** @param array<string,string> $options */
	public function alt(string $id, array $options = []): string
	{
		$options = array_merge([
			'collection' => 'image',
			'property'   => 'image',
		], $options);

		$image = $this->data($options['collection'], $id, $options['property']);

		if (!is_array($image) || !array_key_exists('alt', $image)) {
			return '';
		}

		return $image['alt'];
	}

	// Get an alt tag for a gallery image
	/** @param array<string,string> $options */
	public function galleryAlt(string $id, string $name, array $options = []): string
	{
		$options = array_merge([
			'collection' => 'gallery',
			'property'   => 'gallery',
		], $options);

		$image = $this->galleryImageData($id, $name, $options);

		if (!is_array($image) || !array_key_exists('alt', $image)) {
			return '';
		}

		return $image['alt'];
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
		} catch (\Exception) {
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
		} catch (\Exception) {
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
		$templates = $this->templateRepository->listCustomTemplates(null, true);

		$folders = [];

		foreach ($templates as $path) {
			// Parse path to get folder and template name
			[$folder, $templateId] = TemplateRepository::parsePath($path);

			// Determine group name
			$groupName = 'Templates';
			if ($folder !== null) {
				// Convert folder path to group name (e.g., "pages/blog" -> "Pages / Blog")
				$parts     = explode('/', str_replace('-', ' ', $folder));
				$groupName = implode(' / ', array_map('ucwords', $parts));
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
	 * Check if current user can perform an action on a collection.
	 */
	public function canAccessCollection(string $collection, string $method = 'GET'): bool
	{
		$userData = $this->accessManager->userData();
		if ($userData === [] || !isset($userData['id'])) {
			return false;
		}

		return $this->accessControl->canAccessCollection($userData['id'], $collection, $method);
	}

	/**
	 * Get collections the current user can access with a given method.
	 *
	 * @param string $method HTTP method (GET, POST, PUT, DELETE)
	 *
	 * @return array<string> Collection IDs user can access
	 */
	public function getAccessibleCollections(string $method = 'GET'): array
	{
		$allCollections = $this->collectionLister->listAllCollections();
		$accessible     = [];

		foreach ($allCollections as $collection) {
			if ($this->canAccessCollection($collection->id, $method)) {
				$accessible[] = $collection->id;
			}
		}

		return $accessible;
	}

	/**
	 * Check if current user can perform an action on a schema.
	 */
	public function canAccessSchema(string $schema, string $method = 'GET'): bool
	{
		$userData = $this->accessManager->userData();
		if ($userData === [] || !isset($userData['id'])) {
			return false;
		}

		return $this->accessControl->canAccessSchema($userData['id'], $schema, $method);
	}

	/**
	 * Check if user is in admin group (bypasses all access controls).
	 */
	public function isAdmin(): bool
	{
		$userData = $this->accessManager->userData();
		if ($userData === [] || !isset($userData['id'])) {
			return false;
		}

		return $this->accessControl->isAdmin($userData['id']);
	}
}
