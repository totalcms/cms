<?php

namespace TotalCMS\Domain\Twig\Adapter;

use Psr\Log\LoggerInterface;
use TotalCMS\Action\XmlRpc\XmlRpcDiscoveryAction;
use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\License\Service\LicenseStatus;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Domain\Twig\Data\FrontendAsset;
use TotalCMS\Domain\Twig\Extension\TotalCMSTwigFilters;
use TotalCMS\Domain\Twig\Service\AssetRenderer;
use TotalCMS\Factory\LogChannel;
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
	public string $siteName;
	public string $adminTitle;
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
		public FeedTwigAdapter $feed,
		public LocaleTwigAdapter $locale,
		public UtilsTwigAdapter $utils,
		public SeoTwigAdapter $seo,
	) {
		$this->logger     = $this->loggerFactory->channelLogger(LogChannel::Twig);
		$this->env        = $this->config->env;
		$this->base       = $this->config->api;
		$this->api        = $this->base . '/api';
		$this->clearcache = $this->api . '/emergency/cache/clear';
		$this->dashboard  = $this->base . '/admin';
		$this->domain     = $this->config->domain;
		$this->siteName   = $this->config->displayName();
		$this->adminTitle = $this->config->adminTitle();
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
			'canAccessSettings'            => ['auth', 'canAccessSettings'],
			'canAccessJumpStart'           => ['auth', 'canAccessJumpStart'],
			'canAccessJobQueue'            => ['auth', 'canAccessJobQueue'],
			'canAccessProjectSetup'        => ['auth', 'canAccessProjectSetup'],
			'canAccessDataViews'           => ['auth', 'canAccessDataViews'],
			'canAccessBuilder'             => ['auth', 'canAccessBuilder'],
			'canAccessExtension'           => ['auth', 'canAccessExtension'],
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
			'processAutomationsCommand'  => ['admin', 'processAutomationsCommand'],
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
			name: $asset->name,
		);
	}

	/**
	 * The frontend assets a page gets: every registered record minus the core
	 * features left out for this render. The site-wide `frontendAssets.except`
	 * setting is the default and a call's `except` adds to it, so a Stacks
	 * page that carries no config file can decide for itself. Names match
	 * core assets only: extension assets carry no name and always render.
	 *
	 * @param array<string,mixed> $options
	 *
	 * @return list<FrontendAsset>
	 */
	private function frontendAssets(array $options): array
	{
		$names = static fn (mixed $list): array => is_array($list) ? array_values(array_filter($list, 'is_string')) : [];

		$except = array_merge($names($this->config->frontendAssets['except'] ?? []), $names($options['except'] ?? []));

		if ($except === []) {
			return $this->frontendAssetsList;
		}

		return array_values(array_filter(
			$this->frontendAssetsList,
			static fn (FrontendAsset $asset): bool => $asset->name === '' || !in_array($asset->name, $except, true),
		));
	}

	/**
	 * Render frontend asset tags for the document head.
	 *
	 * Usage in Twig: {{ cms.assetsHead() }}
	 *
	 * Options: `except` — core feature names to leave out on this page, on
	 * top of the site's `frontendAssets.except` setting. Pass the same option
	 * to assetsBody() — a feature can be a stylesheet here and a script there.
	 *
	 * @param array<string,mixed> $options
	 */
	public function assetsHead(array $options = []): string
	{
		return AssetRenderer::head($this->frontendAssets($options)) . $this->xmlrpcDiscoveryTag();
	}

	/**
	 * Emit the WordPress-standard `<link rel="EditURI">` RSD discovery tag when
	 * the XML-RPC endpoint is enabled, so writing clients (MarsEdit, etc.) given
	 * only the site's home page URL can find the endpoint — including on
	 * subfolder installs where guessing `{home}/xmlrpc.php` fails.
	 *
	 * The href is built from `$this->base` (== `$config->api`), the same source
	 * {@see XmlRpcDiscoveryAction} uses to construct the
	 * endpoint it actually serves, so the two can never disagree.
	 *
	 * `assetsHead()` runs on every customer front-end page, so this is
	 * deliberately conservative: any missing/malformed config, or an adapter
	 * that skipped its constructor, yields an empty string rather than a
	 * fatal error or notice.
	 */
	private function xmlrpcDiscoveryTag(): string
	{
		try {
			if (($this->config->xmlrpc['enable'] ?? false) !== true) {
				return '';
			}

			$href = rtrim($this->base, '/') . '/xmlrpc.php?rsd';

			return HTMLUtils::inlineElement('link', [
				'rel'   => 'EditURI',
				'type'  => 'application/rsd+xml',
				'title' => 'RSD',
				'href'  => $href,
			]) . "\n";
		} catch (\Throwable) {
			return '';
		}
	}

	/**
	 * Render frontend asset tags for the document body.
	 *
	 * Usage in Twig: {{ cms.assetsBody() }}
	 *
	 * Takes the same `except` option as assetsHead(); pass both calls the
	 * same list so a feature's stylesheet and script stay together.
	 *
	 * @param array<string,mixed> $options
	 */
	public function assetsBody(array $options = []): string
	{
		return AssetRenderer::body($this->frontendAssets($options));
	}

	/**
	 * Render admin asset tags for the document head.
	 *
	 * Usage in Twig: {{ cms.adminAssetsHead() }}
	 */
	public function adminAssetsHead(): string
	{
		return AssetRenderer::head($this->adminAssetsList) . $this->adminAccentStyle();
	}

	/**
	 * The `<style>` that applies the dashboard accent setting to
	 * `--totalform-accent`, the variable the admin stylesheets read. It follows
	 * the stylesheets so the `:root` override wins the cascade, and it is part
	 * of the head helper so a customer admin page picks up the configured
	 * accent as the dashboard does. Nothing is emitted when no accent is set.
	 *
	 * Public because admin-layout.twig (login, setup, OAuth consent) writes its
	 * own asset tags and asks for just this rule: {{ cms.adminAccentStyle() }}
	 */
	public function adminAccentStyle(): string
	{
		$accent = $this->config('dashboard', 'accent');
		if (!is_string($accent) || $accent === '') {
			return '';
		}

		$oklch = TotalCMSTwigFilters::oklch(TotalCMSTwigFilters::hexToColor($accent), 100, false);

		return '<style>:root{--totalform-accent:' . $oklch . ";}</style>\n";
	}

	/**
	 * Render admin asset tags for the document body.
	 *
	 * Usage in Twig: {{ cms.adminAssetsBody() }}
	 *
	 * The admin scripts read two globals — the JS translation catalog and the
	 * dashboard settings the browser needs — so those are emitted here, ahead
	 * of the script tags, rather than left for each template to remember. A
	 * customer admin page that calls this helper gets working translations and
	 * the configured confirm countdown, not the English keys and the
	 * hard-coded fallback.
	 */
	public function adminAssetsBody(): string
	{
		return $this->adminGlobalsScript() . AssetRenderer::body($this->adminAssetsList);
	}

	/**
	 * The inline `<script>` that defines `window.TCMS_TRANSLATIONS` and
	 * `window.TCMS_CONFIG` for the admin bundle. Admin-only: `assetsBody()`
	 * never emits it, because the catalog is per-user and the config is the
	 * dashboard's. Encoded with the HEX flags so a `</script>` inside a
	 * translated string cannot end the element early.
	 */
	private function adminGlobalsScript(): string
	{
		$flags = JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

		$translations = json_encode($this->locale->jsTranslations(), $flags);
		$config       = json_encode(['confirmCountdown' => $this->config('dashboard', 'confirmCountdown')], $flags);

		return '<script>window.TCMS_TRANSLATIONS = ' . $translations . ';window.TCMS_CONFIG = ' . $config . ";</script>\n";
	}
}
