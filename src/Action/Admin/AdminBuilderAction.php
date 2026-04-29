<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Template\Service\TemplateFetcher;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Renderer\RawRenderer;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

readonly class AdminBuilderAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
		private RawRenderer $rawRenderer,
		private TwigEngine $twigEngine,
		private BuilderConfigService $builderConfig,
		private TemplateFetcher $templateFetcher,
		private IndexReader $indexReader,
		private Config $config,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$section = $args['section'] ?? '';
		$path    = $args['path'] ?? '';
		$file    = ($section !== '' && $path !== '') ? $section . '/' . $path : '';

		if ($request->getMethod() === 'POST') {
			return match ($section) {
				'preview' => $this->handlePreview($request, $response),
				default   => $response->withStatus(404),
			};
		}

		// First-visit setup
		$this->builderConfig->migrateFromTemplatesDir();
		$this->builderConfig->ensureDefaultLayout();
		$this->builderConfig->ensurePagesCollection();

		$pagesCollectionId = $this->builderConfig->getPagesCollectionId();

		$assetsPath = (string)($this->config->builder['assetsPath'] ?? 'assets');
		if ($assetsPath === '') {
			$assetsPath = 'assets';
		}

		$templateData = [
			'url' => [
				'path'    => $request->getUri()->getPath(),
				'query'   => $request->getUri()->getQuery(),
				'params'  => $args,
				'page'    => 'builder',
				'section' => $section,
				'file'    => $file,
			],
			'builder' => [
				'docroot'         => $this->builderConfig->getDocroot(),
				'pagesCollection' => $pagesCollectionId,
				'pages'           => $this->loadPages($pagesCollectionId),
				'assetsPath'      => $assetsPath,
				'assets'          => $this->scanAssets($assetsPath),
			],
		];

		// Load template content for editor
		if ($section === 'page') {
			// Page section — handled by page templates
		} elseif ($file !== '' && $section !== 'new') {
			try {
				$template                 = $this->templateFetcher->fetchTemplate($path, $section);
				$templateData['template'] = [
					'id'       => $template->id,
					'contents' => $template->contents,
					'path'     => $file,
				];
			} catch (\DomainException) {
				// Template not found — editor will show error
			}
		}

		return $this->twigRenderer->template($response, 'admin/builder.twig', $templateData);
	}

	/** @return array<array<string,mixed>> */
	private function loadPages(string $collectionId): array
	{
		try {
			$index = $this->indexReader->fetchIndex($collectionId);

			return $index->objects->sortBy('sort')->values()->toArray();
		} catch (\Exception) {
			return [];
		}
	}

	/**
	 * Scan the assets directory and return files grouped by type.
	 *
	 * @return array{css: list<string>, js: list<string>, fonts: list<string>, images: list<string>, other: list<string>, hasManifest: bool}
	 */
	private function scanAssets(string $assetsPath): array
	{
		$result = ['css' => [], 'js' => [], 'fonts' => [], 'images' => [], 'other' => [], 'hasManifest' => false];
		$dir    = $this->config->docroot . '/' . trim($assetsPath, '/');

		if (!is_dir($dir)) {
			return $result;
		}

		$result['hasManifest'] = file_exists($dir . '/manifest.json');

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
		);

		foreach ($iterator as $file) {
			if (!$file instanceof \SplFileInfo || !$file->isFile()) {
				continue;
			}

			$ext      = strtolower($file->getExtension());
			$relative = str_replace($dir . '/', '', $file->getPathname());

			// Skip manifest.json and hidden files
			if ($relative === 'manifest.json' || str_starts_with(basename($relative), '.')) {
				continue;
			}

			match (true) {
				in_array($ext, ['css', 'scss', 'less'], true)                           => $result['css'][]    = $relative,
				in_array($ext, ['js', 'mjs', 'ts'], true)                               => $result['js'][]     = $relative,
				in_array($ext, ['woff', 'woff2', 'ttf', 'otf', 'eot'], true)            => $result['fonts'][]  = $relative,
				in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'ico', 'avif'], true) => $result['images'][] = $relative,
				default                                                                  => $result['other'][]  = $relative,
			};
		}

		sort($result['css']);
		sort($result['js']);
		sort($result['fonts']);
		sort($result['images']);
		sort($result['other']);

		return $result;
	}

	private function handlePreview(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$post     = (array)$request->getParsedBody();
		$template = (string)($post['template'] ?? '');
		$render   = '';

		if ($template !== '') {
			try {
				$render = $this->twigEngine->renderString($template);
			} catch (\Throwable $e) {
				$render = '<div class="cms-twig-error"><strong>Error:</strong> <pre>'
					. htmlspecialchars($e->getMessage())
					. '</pre></div>';
			}
		}

		$response = $response->withHeader('Content-Type', 'text/html');

		return $this->rawRenderer->render($response, $render);
	}
}
