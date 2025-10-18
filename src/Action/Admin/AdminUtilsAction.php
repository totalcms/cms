<?php

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\ApiKey\Service\ApiKeyFetcher;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Renderer\TwigRenderer;

readonly class AdminUtilsAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
		private TwigEngine $twigEngine,
		private ApiKeyFetcher $apiKeyFetcher,
	) {
	}

	/** @param array<string,string> $args The routing arguments */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$page    = $args['page'] ?? 'index';
		$results = '';

		// Check for sub-actions in URL path (e.g., /admin/utils/api-keys/new)
		$path      = $request->getUri()->getPath();
		$subAction = null;
		if (str_ends_with($path, '/new')) {
			$subAction = 'new';
		}

		if ($request->getMethod() === 'POST') {
			$post = (array)$request->getParsedBody();

			if ($page === 'twig-playground' && isset($post['twig'])) {
				try {
					$results = $this->twigEngine->renderString((string)$post['twig']);
				} catch (\Throwable $e) {
					$results = sprintf('<div class="error"><pre><code>%s</code></pre></div>', htmlspecialchars($e->getMessage()));
				}
			}
		}

		// Detect Total CMS 1 data for project-setup page
		$totalcms1DetectionData = null;
		if ($page === 'project-setup') {
			$totalcms1DetectionData = $this->detectTotalCms1Data();
		}

		// Fetch API keys for api-keys page
		$apiKeys = null;
		if ($page === 'api-keys' && $subAction !== 'new') {
			$apiKeys = $this->apiKeyFetcher->getAllKeys();
		}

		return $this->twigRenderer->template($response, 'admin/utils.twig', [
			'page'      => $page,
			'subAction' => $subAction,
			'url'       => [
				'path'   => $request->getUri()->getPath(),
				'query'  => $request->getUri()->getQuery(),
				'params' => $args,
				'page'   => 'utils',
			],
			'results'                => $results,
			'totalcms1DetectionData' => $totalcms1DetectionData,
			'apiKeys'                => $apiKeys,
			'postData'               => $request->getMethod() === 'POST' ? (array)$request->getParsedBody() : [],
		]);
	}

	/**
	 * @SuppressWarnings("PHPMD.Superglobals")
	 *
	 * @return array<string,string>|null
	 */
	private function detectTotalCms1Data(): ?array
	{
		// Check production location first
		$documentRoot   = $_SERVER['DOCUMENT_ROOT'] ?? '';
		$productionPath = $documentRoot . '/cms-data';

		if (is_dir($productionPath)) {
			return [
				'path'   => $productionPath,
				'source' => 'production',
			];
		}

		// Check test data location
		$testPath = __DIR__ . '/../../../tests/test-data/cms-data';
		$testPath = realpath($testPath);

		if ($testPath && is_dir($testPath)) {
			return [
				'path'   => $testPath,
				'source' => 'test',
			];
		}

		return null;
	}
}
