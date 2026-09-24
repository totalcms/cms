<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\WebMcp;

use Psr\Container\ContainerInterface;
use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Extension\ExtensionContext;
use TotalCMS\Domain\Extension\ExtensionInterface;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use Twig\Markup;
use Twig\TwigFunction;

require_once __DIR__ . '/WebMcpAttributes.php';
require_once __DIR__ . '/WebMcpFormBuilder.php';

/**
 * WebMCP — agent-callable forms, and the MCP server's read tools registered
 * in the visitor's browser.
 *
 * The read tools are not this extension's: the script is a stateless MCP
 * client of /mcp, calling as the signed-in user, so what an agent may read
 * follows each collection's MCP Access, the field exposure flags, mcp.enabled,
 * mcp.publicAccess and the Standard edition or above — one place to configure AI exposure.
 * Core recognizes a same-origin session on /mcp and makes it read-only.
 *
 * Experimental and off by default; spec churn is a version bump here, never
 * a CMS release. See docs/extensions/webmcp.
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

		$forms = $context->setting('declarativeForms', true) === true;
		$tools = $context->setting('readTools', true) === true;
		$admin = $context->setting('adminTools', false) === true;
		$token = trim((string)$context->setting('originTrialToken', ''));

		// bridge.js answers agent submits on annotated forms; webmcp.js imports
		// it and adds the read tools. Both off: nothing on the page at all.
		if ($forms || $tools) {
			$context->addFrontendAsset('js', $tools ? 'webmcp.js' : 'bridge.js');
			if ($token !== '') {
				// Server-rendered so Chrome sees the token at parse time.
				$context->addFrontendMeta(['http-equiv' => 'origin-trial', 'content' => $token]);
			}
		}

		// Dashboard read tools are opt-in: an agent in the operator's own
		// browser reads with the operator's session, read-only. Admin forms
		// are never annotated.
		if ($admin) {
			$context->addAdminAsset('js', 'webmcp.js');
			if ($token !== '') {
				$context->addAdminMeta(['http-equiv' => 'origin-trial', 'content' => $token]);
			}
		}
	}

	public function boot(ExtensionContext $context): void
	{
	}
}
