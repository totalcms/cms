<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use TotalCMS\Action\Admin\Utils\UtilsPageDataResolver;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\PathResolver;

/**
 * Renders admin/utils.twig for every utility page. Page-specific data comes
 * from the UtilsPageData builder registered for the page (see the
 * UtilsPageDataResolver factory in config/container.php); pages with no
 * builder — logs, cache manager, server checker and the other HTMX-driven
 * utilities — render with the default payload only.
 */
readonly class AdminUtilsAction
{
	/**
	 * Every builder-owned template variable, defaulted so the template sees
	 * the same keys whichever page renders. Builders return only the keys
	 * they own; the merge below layers them over these.
	 */
	private const DEFAULT_PAGE_DATA = [
		'totalcms1DetectionData' => null,
		'apiKeys'                => null,
		'accessGroupsData'       => null,
		'oauthClients'           => null,
		'oauthClientsForm'       => null,
		'oauthGrants'            => null,
		'lintResults'            => null,
		'rssAnalysis'            => null,
		'rssError'               => null,
		'rssCollections'         => null,
		'updateInfo'             => null,
		'retainedBackup'         => null,
		'syncData'               => null,
		'jumpstartData'          => null,
		'visualizerData'         => null,
		'objectVisualizerData'   => null,
		'permissionMatrixData'   => null,
	];

	public function __construct(
		private TwigRenderer $twigRenderer,
		private EditionFeatureService $editionFeatures,
		private UtilsPageDataResolver $pageData,
	) {
	}

	/** @param array<string,string> $args The routing arguments */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		// Handle specific routes by setting expected page based on route name
		$routeName = RouteContext::fromRequest($request)->getRoute()?->getName() ?? '';

		if ($routeName === 'admin-utils-access-groups') {
			$args['page'] = 'access-groups';
		} elseif ($routeName === 'admin-utils-api-keys') {
			$args['page'] = 'api-keys';
		}
		$page = $args['page'] ?? 'index';

		$query     = $request->getQueryParams();
		$rawAction = $args['action'] ?? $query['action'] ?? '';
		$action    = is_string($rawAction) ? $rawAction : '';

		// Check edition for import pages (RSS, WordPress)
		if (in_array($page, ['import-rss', 'import-wordpress'], true) && !$this->editionFeatures->can(EditionFeature::RSS_IMPORT)) {
			$feature         = EditionFeature::RSS_IMPORT;
			$requiredEdition = $feature->requiredEdition();

			return $this->twigRenderer->template($response, 'access-denied.twig', [
				'message'  => sprintf(
					'The "%s" feature requires the %s edition or higher.',
					$feature->label(),
					ucfirst($requiredEdition->value)
				),
				'details'  => null,
				'referrer' => $request->getHeaderLine('Referer') ?: null,
			]);
		}

		$builder  = $this->pageData->for($page);
		$pageData = $builder !== null ? $builder->build($request, $page, $action) : [];

		/** @var array<string,mixed> $pageData */
		return $this->twigRenderer->template($response, 'admin/utils.twig', array_merge(
			self::DEFAULT_PAGE_DATA,
			$pageData,
			[
				'page'   => $page,
				'action' => $action,
				'url'    => [
					'path'   => $request->getUri()->getPath(),
					'query'  => $request->getUri()->getQuery(),
					'params' => $args,
					'page'   => 'utils',
				],
				'composerInstall' => PathResolver::isComposerInstall(),
				'postData'        => $request->getMethod() === 'POST' ? (array)$request->getParsedBody() : [],
			],
		));
	}
}
