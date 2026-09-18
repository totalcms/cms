<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\WebMcp;

use Psr\Container\ContainerInterface;
use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Support\Config;
use Twig\Markup;
use Twig\TwigFunction;

require_once __DIR__ . '/WebMcpAttributes.php';
require_once __DIR__ . '/WebMcpFormBuilder.php';
require_once __DIR__ . '/ToolManifestAction.php';

/**
 * WebMCP — agent-callable forms and read tools, as an extension.
 *
 * Everything WebMCP-specific lives here; core gained only generic seams
 * (extra attributes on a form and its controls, save() returning its
 * promise). The declarative API is explainer-only and Chrome-only today, so
 * the extension is experimental and off by default; spec churn is a version
 * bump of this extension, not of the CMS. See docs/extensions/webmcp.
 */
class Extension implements ExtensionInterface
{
	public function register(ExtensionContext $context): void
	{
		$context->addContainerDefinition(
			WebMcpFormBuilder::class,
			static fn (ContainerInterface $container): WebMcpFormBuilder => new WebMcpFormBuilder(
				$container->get(TotalFormFactory::class),
				$container->get(SchemaFetcher::class),
				$container->get(CollectionFetcher::class),
				$container->get(ExtensionSettingsManager::class),
			),
		);

		$context->addTwigFunction(new TwigFunction(
			'webmcp_form',
			static fn (string $collection, array $options = []): Markup => new Markup(
				$context->get(WebMcpFormBuilder::class)->build($collection, $options),
				'UTF-8',
			),
			['is_safe' => ['html']],
		));

		// The browser half: origin-trial meta, the respondWith bridge for
		// annotated forms, and the read tools. Body position, module.
		$context->addFrontendAsset('js', 'webmcp.js');

		// Dashboard read tools are opt-in: an agent in the operator's own
		// browser reads with the operator's session. Admin forms are never
		// annotated, and the toggle is read here, when the extension registers.
		if ($context->setting('adminTools', false) === true) {
			$context->addAdminAsset('js', 'webmcp.js');
		}

		$config   = $context->get(Config::class);
		$access   = $context->get(AccessManager::class);
		$manifest = new ToolManifestAction(
			$context->get(ExtensionSettingsManager::class),
			$context->get(CollectionFetcher::class),
			$config,
			static fn (): bool => $access->userLoggedIn((string)($config->auth['collection'] ?? '')),
		);
		$context->addPublicRoutes(static function ($routes) use ($manifest): void {
			$routes->get('/tools.json', $manifest);
		});
	}

	public function boot(ExtensionContext $context): void
	{
	}
}
