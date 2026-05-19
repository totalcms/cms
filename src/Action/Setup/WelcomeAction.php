<?php

declare(strict_types=1);

namespace TotalCMS\Action\Setup;

use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Renderer\TwigRenderer;

/**
 * Setup wizard landing page: welcome screen with language selection.
 */
readonly class WelcomeAction
{
	private const AVAILABLE_LOCALES = [
		'en_US' => 'English',
		'en_GB' => 'English (UK)',
		'de_DE' => 'Deutsch',
		'es_ES' => 'Español',
		'it_IT' => 'Italiano',
		'nl_NL' => 'Nederlands',
	];

	public function __construct(
		private TwigRenderer $twigRenderer,
		private TranslationService $translationService,
		private SessionInterface $session,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		// Handle locale selection
		$query  = $request->getQueryParams();
		$locale = $query['lang'] ?? $this->session->get('setup_locale', 'en_US');

		if (is_string($locale) && isset(self::AVAILABLE_LOCALES[$locale])) {
			$this->session->set('setup_locale', $locale);
			$this->translationService->setLocale($locale);
		}

		return $this->twigRenderer->template($response, 'setup/welcome.twig', [
			'url' => [
				'path' => $request->getUri()->getPath(),
				'page' => 'setup',
			],
			'locales'       => self::AVAILABLE_LOCALES,
			'currentLocale' => $locale,
		]);
	}
}
