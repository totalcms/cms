<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Renderer\TwigRenderer;

/**
 * Pre-enable review: shows what an extension registers + high-risk source
 * patterns, so the operator gives informed consent before enabling.
 */
readonly class AdminExtensionReviewAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
		private ExtensionManager $manager,
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

		return $this->twigRenderer->template($response, 'admin/extension-review.twig', [
			'url' => [
				'path'   => $request->getUri()->getPath(),
				'query'  => $request->getUri()->getQuery(),
				'params' => $args,
				'page'   => 'extensions',
			],
			'extensionId' => $extensionId,
			'reviewNote'  => $review['reviewNote'],
			'risky'       => $review['risky'],
			'findings'    => $review['findings'],
		]);
	}
}
