<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\RouteParserInterface;
use TotalCMS\Domain\Extension\Service\ExtensionManager;

/**
 * Enable or disable an extension, then redirect back to the extensions page.
 */
readonly class ExtensionToggleAction
{
	public function __construct(
		private ExtensionManager $manager,
		private RouteParserInterface $routeParser,
	) {
	}

	/**
	 * @param array<string,string> $args
	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$extensionId = $args['extension'] ?? '';
		$action      = $args['action'] ?? '';

		if ($action === 'enable' && $extensionId !== '') {
			$body      = $request->getParsedBody();
			$confirmed = is_array($body) && (($body['confirmed'] ?? '') === '1');

			// A genuinely disabled extension with review flags gets one stop at the
			// review page first. Quarantined extensions are enabled=true (isEnabled
			// is true), so re-enabling them skips this and clears quarantine directly.
			if (!$confirmed && !$this->manager->isEnabled($extensionId) && $this->manager->getEnableReview($extensionId)['hasFlags']) {
				$reviewUrl = $this->routeParser->urlFor('admin-extension-review', ['extension' => $extensionId]);

				return $response
					->withHeader('Location', $reviewUrl)
					->withStatus(302);
			}

			try {
				$this->manager->enable($extensionId);
			} catch (\RuntimeException) {
				// Extension is incompatible. The listing already surfaces the reason
				// to the user; the redirect below will return them to that view.
			}
		} elseif ($action === 'disable' && $extensionId !== '') {
			$this->manager->disable($extensionId);
		}

		$redirectUrl = $this->routeParser->urlFor('admin-extensions');

		return $response
			->withHeader('Location', $redirectUrl)
			->withStatus(302);
	}
}
