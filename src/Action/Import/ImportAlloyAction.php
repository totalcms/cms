<?php

declare(strict_types=1);

namespace TotalCMS\Action\Import;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Import\AlloyImporter;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Import from an Alloy blog. The analyze variant parses the same request
 * ({@see ImportAlloyAnalyzeAction}), so a validation rule lives once.
 */
readonly class ImportAlloyAction extends ImportEndpoint
{
	private const FOLDERS = ['blog', 'image_uploads', 'embeds', 'droplets'];

	public function __construct(protected AlloyImporter $importer, JsonRenderer $renderer)
	{
		parent::__construct($renderer);
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$params = (array)$request->getParsedBody();
		foreach (self::FOLDERS as $field) {
			if (empty($params[$field])) {
				return $this->reject($response, sprintf('Missing required field: %s', $field));
			}
		}

		return $this->run($response, [
			'blog'          => (string)$params['blog'],
			'image_uploads' => (string)$params['image_uploads'],
			'embeds'        => (string)$params['embeds'],
			'droplets'      => (string)$params['droplets'],
		]);
	}

	/** @param array{blog: string, image_uploads: string, embeds: string, droplets: string} $folders */
	protected function run(ResponseInterface $response, array $folders): ResponseInterface
	{
		return $this->attempt($response, 'Import failed', fn (): array => $this->imported(
			$this->importer->import($folders),
			'Successfully queued %d items for import from Alloy.',
		));
	}
}
