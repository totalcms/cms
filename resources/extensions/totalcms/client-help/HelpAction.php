<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\ClientHelp;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Auth\Service\AccessControlService;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

/**
 * The help home (`/home`) and articles (`/post/{id}`). Everything is read
 * server-side, so a client needs no access to the collection behind them.
 */
readonly class HelpAction
{
	private const EXTENSION = 'totalcms/client-help';

	public function __construct(
		private HelpLibrary $library,
		private TwigRenderer $twigRenderer,
		private TwigEngine $twig,
		private ExtensionSettingsManager $settings,
		private AccessManager $accessManager,
		private AccessControlService $accessControl,
		private Config $config,
		private LoggerInterface $logger,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		// The rail marks an extension's item by the URL's ending, so articles
		// report the home path to keep Help marked while one is open.
		$path = (string)preg_replace('~/post/[^/]*$~', '/home', $request->getUri()->getPath());
		$url  = ['path' => $path, 'page' => 'client-help'];

		$article = null;
		if (isset($args['id'])) {
			$article = $this->library->article($args['id']);
			if ($article === null) {
				return $this->twigRenderer->template($response->withStatus(404), 'admin/404.twig', [
					'url' => ['path' => $url['path'], 'page' => '404'],
				]);
			}
		}

		$settings = $this->settings->getSettings(self::EXTENSION);
		$isAdmin  = $this->isAdmin();

		return $this->twigRenderer->template($response, '@totalcms-client-help/client-help.twig', [
			'url'        => $url,
			'groups'     => $this->library->groups(),
			'label'      => $this->label($settings),
			'isAdmin'    => $isAdmin,
			'article'    => $article,
			'featured'   => $article === null ? $this->library->featured() : [],
			'postCount'  => $article === null ? count($this->library->published()) : 0,
			'note'       => $article === null ? $this->note((string)($settings['note'] ?? ''), $isAdmin) : ['html' => '', 'error' => ''],
			'customCss'  => (string)($settings['customCss'] ?? ''),
			'customHtml' => (string)($settings['customHtml'] ?? ''),
		]);
	}

	/** @param array<string,mixed> $settings */
	private function label(array $settings): string
	{
		return Extension::navItem((string)($settings['navLabel'] ?? ''))->label;
	}

	/**
	 * The note is Twig, so it can fail at parse or run time. The page stays up
	 * either way, and only admins, who can fix it, see why.
	 *
	 * @return array{html: string, error: string}
	 */
	private function note(string $source, bool $isAdmin): array
	{
		if (trim($source) === '') {
			return ['html' => '', 'error' => ''];
		}

		try {
			return ['html' => $this->twig->renderString($source, []), 'error' => ''];
		} catch (\Throwable $e) {
			$this->logger->error('Client Help note failed to render: ' . $e->getMessage());

			return ['html' => '', 'error' => $isAdmin ? $e->getMessage() : ''];
		}
	}

	private function isAdmin(): bool
	{
		if (!$this->config->authEnabled()) {
			return true;
		}

		$user = $this->accessManager->userData();

		return isset($user['id']) && $this->accessControl->isAdmin((string)$user['id'], (string)($user['collection'] ?? ''));
	}
}
