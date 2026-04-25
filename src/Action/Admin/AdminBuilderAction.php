<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Builder\Service\BuilderConfigService;
use TotalCMS\Domain\Builder\Service\SiteGenerator;
use TotalCMS\Domain\Template\Service\TemplateFetcher;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Renderer\RawRenderer;
use TotalCMS\Renderer\TwigRenderer;

readonly class AdminBuilderAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
		private RawRenderer $rawRenderer,
		private TwigEngine $twigEngine,
		private BuilderConfigService $builderConfig,
		private SiteGenerator $stubGenerator,
		private TemplateFetcher $templateFetcher,
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
				'preview'  => $this->handlePreview($request, $response),
				'generate' => $this->handleGenerate($response),
				default    => $response->withStatus(404),
			};
		}

		// First-visit setup
		$this->builderConfig->migrateFromTemplatesDir();
		$this->builderConfig->ensureDefaultLayout();
		$this->builderConfig->ensurePagesCollection();

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
				'docroot' => $this->builderConfig->getDocroot(),
			],
		];

		// Load template content for editor
		if ($file !== '' && $section !== 'new') {
			try {
				$template = $this->templateFetcher->fetchTemplate($path, $section);
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

	private function handleGenerate(ResponseInterface $response): ResponseInterface
	{
		$result = $this->stubGenerator->generate();

		return $this->twigRenderer->template($response, 'admin/builder/generate-result.twig', [
			'result' => $result->toArray(),
		]);
	}
}
