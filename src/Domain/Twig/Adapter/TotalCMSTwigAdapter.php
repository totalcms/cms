<?php

namespace TotalCMS\Domain\Twig\Adapter;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\License\Service\LicenseStatus;
use TotalCMS\Domain\Twig\Data\FrontendAsset;
use TotalCMS\Domain\Twig\Service\AssetRenderer;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;
use TotalCMS\Support\VersionData;

/**
 * Twig Adapter with Total CMS.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
 * @SuppressWarnings("PHPMD.ExcessivePublicCount")
 */
class TotalCMSTwigAdapter
{
	private readonly LoggerInterface $logger;

	public string $env;
	public string $base;
	public string $api;
	public string $dashboard;
	public string $login;

	public string $domain;
	public string $clearcache;
	public VersionData $version;
	public string $currentUrl;

	/** @var list<FrontendAsset> */
	private array $frontendAssetsList = [];

	/** @var list<FrontendAsset> */
	private array $adminAssetsList = [];

	/** @SuppressWarnings("PHPMD.Superglobals") */
	public function __construct(
		private readonly Config $config,
		public TotalFormFactory $form,
		public LicenseStatus $license,
		public EditionTwigAdapter $edition,
		private readonly LoggerFactory $loggerFactory,
		public RenderTwigAdapter $render,
		public ViewTwigAdapter $view,
		public SchemaTwigAdapter $schema,
		public AuthTwigAdapter $auth,
		public DataTwigAdapter $data,
		public MediaTwigAdapter $media,
		public CollectionTwigAdapter $collection,
		public AdminTwigAdapter $admin,
		public BuilderTwigAdapter $builder,
		public LocaleTwigAdapter $locale,
		public UtilsTwigAdapter $utils,
	) {
		$this->logger     = $this->loggerFactory->addFileHandler('twig.log')->createLogger('twig');
		$this->env        = $this->config->env;
		$this->base       = $this->config->api;
		$this->api        = $this->base . '/api';
		$this->clearcache = $this->api . '/emergency/cache/clear';
		$this->dashboard  = $this->base . '/admin';
		$this->domain     = $this->config->domain;
		$this->currentUrl = $_SERVER['REQUEST_URI'] ?? '';
		$this->version    = new VersionData();
	}

	public function config(string $key, ?string $setting = null): mixed
	{
		if (!property_exists($this->config, $key)) {
			return '';
		}

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
	 * Backwards compatibility for methods moved to sub-adapters.
	 *
	 * Methods with property name collisions (data, collection, schema, view)
	 * are NOT supported via __call since they now exist as sub-adapter properties.
	 *
	 * @param array<mixed> $arguments
	 *
	 * @phpstan-ignore method.notFound
	 */
	public function __call(string $name, array $arguments): mixed
	{
		/** @var array<string,array{string,string}> */
		static $legacyMap = [
			// DataTwigAdapter (cms.data.*)
			'text'       => ['data', 'text'],
			'code'       => ['data', 'code'],
			'styledtext' => ['data', 'styledtext'],
			'toggle'     => ['data', 'toggle'],
			'date'       => ['data', 'date'],
			'color'      => ['data', 'color'],
			'colour'     => ['data', 'colour'],
			'svg'        => ['data', 'svg'],
			'email'      => ['data', 'email'],
			'url'        => ['data', 'url'],
			'number'     => ['data', 'number'],

			// MediaTwigAdapter (cms.media.*)
			'imagePath'        => ['media', 'imagePath'],
			'galleryPath'      => ['media', 'galleryPath'],
			'galleryImageData' => ['media', 'galleryImageData'],
			'depot'            => ['media', 'depot'],
			'download'         => ['media', 'download'],
			'depotDownload'    => ['media', 'depotDownload'],
			'stream'           => ['media', 'stream'],
			'depotStream'      => ['media', 'depotStream'],

			// RenderTwigAdapter (cms.render.*)
			'image'            => ['render', 'image'],
			'gallery'          => ['render', 'gallery'],
			'galleryLauncher'  => ['render', 'galleryLauncher'],
			'galleryImage'     => ['render', 'galleryImage'],
			'galleryCaption'   => ['render', 'galleryCaption'],
			'galleryAlt'       => ['render', 'galleryAlt'],
			'alt'              => ['render', 'alt'],
			'paginationSimple' => ['render', 'paginationSimple'],
			'paginationFull'   => ['render', 'paginationFull'],
			'depotBrowser'     => ['render', 'depotBrowser'],
			'cloneDialog'      => ['render', 'cloneDialog'],

			// CollectionTwigAdapter (cms.collection.*)
			'collections'                 => ['collection', 'list'],
			'collectionsByCategory'       => ['collection', 'byCategory'],
			'objectCount'                 => ['collection', 'objectCount'],
			'objects'                     => ['collection', 'objects'],
			'object'                      => ['collection', 'object'],
			'objectUrl'                   => ['collection', 'objectUrl'],
			'search'                      => ['collection', 'search'],
			'property'                    => ['collection', 'property'],
			'redirectIfNotFound'          => ['collection', 'redirectIfNotFound'],
			'hasTemplateUrl'              => ['collection', 'hasTemplateUrl'],
			'redirectToCanonicalUrl'      => ['collection', 'redirectToCanonicalUrl'],
			'canonicalObjectUrl'          => ['collection', 'canonicalObjectUrl'],
			'validateUrlTemplateFields'   => ['collection', 'validateUrlTemplateFields'],
			'getUrlTemplateFields'        => ['collection', 'urlTemplateFields'],
			'objectUrlHasEmptySegments'   => ['collection', 'objectUrlHasEmptySegments'],
			'prettyUrl'                   => ['collection', 'prettyUrl'],

			// SchemaTwigAdapter (cms.schema.*)
			'schemas'                   => ['schema', 'list'],
			'reservedSchemas'           => ['schema', 'reserved'],
			'customSchemas'             => ['schema', 'custom'],
			'schemasByCategory'         => ['schema', 'byCategory'],
			'schemaForCollection'       => ['schema', 'forCollection'],
			'getInheritedProperties'    => ['schema', 'inheritedProperties'],
			'isDeckCompatible'          => ['schema', 'isDeckCompatible'],
			'getDeckIncompatibleTypes'  => ['schema', 'deckIncompatibleTypes'],

			// AuthTwigAdapter (cms.auth.*)
			'logout'                       => ['auth', 'logout'],
			'login'                        => ['auth', 'login'],
			'userData'                     => ['auth', 'userData'],
			'userLoggedIn'                 => ['auth', 'userLoggedIn'],
			'userHasAccess'                => ['auth', 'userHasAccess'],
			'sessionData'                  => ['auth', 'sessionData'],
			'verifyFilePassword'           => ['auth', 'verifyFilePassword'],
			'isAdmin'                      => ['auth', 'isAdmin'],
			'canAccessCollection'          => ['auth', 'canAccessCollection'],
			'canAccessCollectionSettings'  => ['auth', 'canAccessCollectionSettings'],
			'canAccessSchemas'             => ['auth', 'canAccessSchemas'],
			'canAccessTemplates'           => ['auth', 'canAccessTemplates'],
			'canAccessSettings'            => ['auth', 'canAccessSettings'],
			'canAccessJumpStart'           => ['auth', 'canAccessJumpStart'],
			'canAccessJobQueue'            => ['auth', 'canAccessJobQueue'],
			'canAccessProjectSetup'        => ['auth', 'canAccessProjectSetup'],
			'canAccessDataViews'           => ['auth', 'canAccessDataViews'],
			'canAccessImport'              => ['auth', 'canAccessImport'],
			'canDeleteObjects'             => ['auth', 'canDeleteObjects'],
			'canEditObjects'               => ['auth', 'canEditObjects'],
			'getAccessibleCollections'     => ['auth', 'accessibleCollections'],

			// AdminTwigAdapter (cms.admin.*)
			'dashboardStats'             => ['admin', 'dashboardStats'],
			'dashboardRecentCollections' => ['admin', 'dashboardRecentCollections'],
			'dashboardEmptyCollections'  => ['admin', 'dashboardEmptyCollections'],
			'dashboardSystemStatus'      => ['admin', 'dashboardSystemStatus'],
			'dashboardRecentObjects'     => ['admin', 'dashboardRecentObjects'],
			'processJobQueueCommand'     => ['admin', 'processJobQueueCommand'],
			'jobQueuePendingInfo'        => ['admin', 'jobQueuePendingInfo'],
			'jobQueueFailedInfo'         => ['admin', 'jobQueueFailedInfo'],
			'getDevModeStatus'           => ['admin', 'devModeStatus'],
			'isDevModeActive'            => ['admin', 'isDevModeActive'],
			'templatesByFolder'          => ['admin', 'templatesByFolder'],
			'getInaccessibleCollections' => ['admin', 'inaccessibleCollections'],
			'getInaccessibleSchemas'     => ['admin', 'inaccessibleSchemas'],
			'apacheRule'                 => ['admin', 'apacheRule'],
			'nginxRule'                  => ['admin', 'nginxRule'],

			// LocaleTwigAdapter (cms.locale.*)
			'languages' => ['locale', 'languages'],
			'setLocale' => ['locale', 'set'],
			'getLocale' => ['locale', 'get'],

			// ViewTwigAdapter (cms.view.*)
			'dataviews' => ['view', 'list'],
		];

		if (isset($legacyMap[$name])) {
			[$adapter, $method] = $legacyMap[$name];
			$this->logger->warning("Deprecated: cms.{$name}() is deprecated. Use cms.{$adapter}.{$method}() instead.");

			return $this->$adapter->$method(...$arguments);
		}

		// Backwards compatibility: cms.data() → cms.data.raw()
		// Twig resolves __call before property access when arguments are present.
		if ($name === 'data') {
			$this->logger->warning('Deprecated: cms.data() is deprecated. Use cms.data.raw() instead.');

			return $this->data->raw(...$arguments);
		}

		throw new \BadMethodCallException("Method '{$name}' does not exist on TotalCMSTwigAdapter.");
	}

	/**
	 * Append frontend asset records (called by ExtensionManager and
	 * CoreFrontendAssetRegistrar during boot). Incoming URLs are relative
	 * (e.g. `/assets/foo.js`); we prepend the API base here so AssetRenderer
	 * can emit them verbatim.
	 *
	 * @param list<FrontendAsset> $assets
	 */
	public function addFrontendAssets(array $assets): void
	{
		foreach ($assets as $asset) {
			$this->frontendAssetsList[] = $this->withApiBase($asset);
		}
	}

	/**
	 * Append admin asset records (called by ExtensionManager during boot).
	 *
	 * @param list<FrontendAsset> $assets
	 */
	public function addAdminAssets(array $assets): void
	{
		foreach ($assets as $asset) {
			$this->adminAssetsList[] = $this->withApiBase($asset);
		}
	}

	/**
	 * Return a copy of the asset with its URL rewritten to be absolute
	 * against the current API base.
	 */
	private function withApiBase(FrontendAsset $asset): FrontendAsset
	{
		return new FrontendAsset(
			type: $asset->type,
			url: $this->api . $asset->url,
			position: $asset->position,
			module: $asset->module,
			preload: $asset->preload,
		);
	}

	/**
	 * Render frontend asset tags for the document head.
	 *
	 * Usage in Twig: {{ cms.assetsHead() }}
	 */
	public function assetsHead(): string
	{
		return AssetRenderer::head($this->frontendAssetsList);
	}

	/**
	 * Render frontend asset tags for the document body.
	 *
	 * Usage in Twig: {{ cms.assetsBody() }}
	 */
	public function assetsBody(): string
	{
		return AssetRenderer::body($this->frontendAssetsList);
	}

	/**
	 * Render admin asset tags for the document head.
	 *
	 * Usage in Twig: {{ cms.adminAssetsHead() }}
	 */
	public function adminAssetsHead(): string
	{
		return AssetRenderer::head($this->adminAssetsList);
	}

	/**
	 * Render admin asset tags for the document body.
	 *
	 * Usage in Twig: {{ cms.adminAssetsBody() }}
	 */
	public function adminAssetsBody(): string
	{
		return AssetRenderer::body($this->adminAssetsList);
	}
}
