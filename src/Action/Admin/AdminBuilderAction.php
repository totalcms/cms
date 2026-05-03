<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Builder\Service\BuilderAssetScanner;
use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Builder\Service\BuilderInstaller;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Template\Service\TemplateFetcher;
use TotalCMS\Renderer\TwigRenderer;
use TotalCMS\Support\Config;

/**
 * GET /admin/builder[/{section}[/{path:.*}]] — render the Site Builder
 * admin page (sidebar + editor pane).
 */
readonly class AdminBuilderAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
		private BuilderConfigService $builderConfig,
		private BuilderInstaller $builderInstaller,
		private TemplateFetcher $templateFetcher,
		private IndexReader $indexReader,
		private BuilderAssetScanner $assetScanner,
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

		// First-visit setup
		$this->builderInstaller->migrateFromTemplatesDir();
		$this->builderInstaller->ensureDefaultLayout();
		$this->builderInstaller->ensurePagesCollection();

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
				'assets'          => $this->assetScanner->scan($assetsPath),
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
}
