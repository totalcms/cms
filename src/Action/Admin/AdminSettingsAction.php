<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

/**
 * Action for displaying settings management interface.
 */
readonly class AdminSettingsAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
		private Config $config,
	) {
	}

	/**
	 * @param array<string,string> $args The routing arguments
	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		// Get section from URL, default to general
		$section = $args['section'] ?? 'general';

		$context = [
			'url' => [
				'path'    => $request->getUri()->getPath(),
				'query'   => $request->getUri()->getQuery(),
				'params'  => $args,
				'page'    => 'settings',
				'section' => $section,
			],
			'currentSection' => $section,
		];

		// General section: detect APP_ENV override so the template can inform
		// the operator when the Site Environment select is controlled externally.
		if ($section === 'general') {
			$appEnv                  = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV');
			$appEnvLocked            = is_string($appEnv) && $appEnv !== '';
			$context['appEnvLocked'] = $appEnvLocked;
			$context['appEnvValue']  = $appEnvLocked ? (string)$appEnv : '';
		}

		// OAuth section: surface the configured key paths and whether the
		// files actually exist. Paths live in PHP config (config/defaults.php +
		// tcms.php overrides), not in the JSON settings form, so the operator
		// has no way to see them otherwise — and a fresh install with no keys
		// looks identical in the UI to one that's correctly set up.
		if ($section === 'oauth') {
			$signingKeyPath          = (string)($this->config->oauth['signingKeyPath'] ?? '');
			$publicKeyPath           = (string)($this->config->oauth['publicKeyPath'] ?? '');
			$context['oauthKeys']    = [
				'signingKeyPath' => $signingKeyPath,
				'publicKeyPath'  => $publicKeyPath,
				'signingExists'  => $signingKeyPath !== '' && is_file($signingKeyPath),
				'publicExists'   => $publicKeyPath !== '' && is_file($publicKeyPath),
			];
		}

		// MCP section: surface the resolved connection details. The endpoint is
		// base-path aware (Config::mcpEndpoint) so subpath / Stacks installs show
		// the correct `<domain><base>/mcp` rather than the domain-root URL
		// operators tend to assume — the exact thing they get wrong by hand.
		if ($section === 'mcp') {
			$context['mcpConnection'] = [
				'enabled'      => (bool)($this->config->mcp['enabled'] ?? true),
				'publicAccess' => (bool)($this->config->mcp['publicAccess'] ?? false),
				'endpoint'     => $this->config->mcpEndpoint(),
				'discovery'    => rtrim($this->config->url, '/') . $this->config->api . '/.well-known/mcp.json',
				'apiKeyHeader' => 'X-API-Key',
			];
		}

		return $this->twigRenderer->template($response, 'admin/settings.twig', $context);
	}
}
