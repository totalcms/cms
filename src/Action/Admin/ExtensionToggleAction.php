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
