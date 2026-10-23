<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Interfaces\RouteParserInterface;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Renderer\TwigRenderer;

/**
 * Pre-enable review: shows what an extension registers + high-risk source
 * patterns, so the operator gives informed consent before enabling.
 *
 * With nothing to review (no flags, no developer note, no agent skill) the
 * page would be a heading and two buttons, so it redirects to the
 * Extensions page instead — highlighting the extension when the request
 * came from a fresh install (`?installed=1`).
 */
readonly class AdminExtensionReviewAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
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
		$review      = $this->manager->getEnableReview($extensionId);

		if (!$review['needsReview']) {
			$installed = ($request->getQueryParams()['installed'] ?? '') === '1';
			$target    = $installed
				? $this->routeParser->urlFor('admin-extensions', [], ['installed' => $extensionId])
				: $this->routeParser->urlFor('admin-extensions');

			return $response
				->withHeader('Location', $target)
				->withStatus(302);
		}

		$manifest = $this->manager->getManifest($extensionId);

		return $this->twigRenderer->template($response, 'admin/extension-review.twig', [
			'url' => [
				'path'   => $request->getUri()->getPath(),
				'query'  => $request->getUri()->getQuery(),
				'params' => $args,
				'page'   => 'extensions',
			],
			'extensionId' => $extensionId,
			'name'        => $manifest->name ?? '',
			'version'     => $manifest->version ?? '',
			'reviewNote'  => $review['reviewNote'],
			'risky'       => $review['risky'],
			'findings'    => $review['findings'],
			'skill'       => $review['skill'],
		]);
	}
}
