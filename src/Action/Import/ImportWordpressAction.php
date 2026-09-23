<?php

declare(strict_types=1);

namespace TotalCMS\Action\Import;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use TotalCMS\Domain\Import\WordpressImporter;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Import a WordPress WXR export uploaded as `wordpress`. The analyze variant
 * parses the same upload ({@see ImportWordpressAnalyzeAction}).
 */
readonly class ImportWordpressAction extends ImportEndpoint
{
	public function __construct(protected WordpressImporter $importer, JsonRenderer $renderer)
	{
		parent::__construct($renderer);
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		/** @var UploadedFileInterface[] $files */
		$files = $request->getUploadedFiles();

		if (!isset($files['wordpress']) || $files['wordpress']->getError() !== UPLOAD_ERR_OK) {
			return $this->reject($response, 'Missing or invalid file upload. Use field name "wordpress".');
		}

		$xml = (string)$files['wordpress']->getStream();
		if (trim($xml) === '') {
			return $this->reject($response, 'Uploaded file is empty.');
		}

		return $this->run($request, $response, $xml);
	}

	protected function run(ServerRequestInterface $request, ResponseInterface $response, string $xml): ResponseInterface
	{
		$params     = (array)$request->getParsedBody();
		$collection = isset($params['collection']) ? trim((string)$params['collection']) : '';
		if ($collection === '') {
			return $this->reject($response, 'Missing required field: collection');
		}

		$options = [];
		if (isset($params['draft'])) {
			$options['draft'] = filter_var($params['draft'], FILTER_VALIDATE_BOOLEAN);
		}

		return $this->attempt($response, 'Import failed', fn (): array => $this->imported(
			$this->importer->import($xml, $collection, $options),
			'Successfully queued %d posts for import from WordPress export.',
		));
	}
}
