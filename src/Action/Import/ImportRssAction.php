<?php

declare(strict_types=1);

namespace TotalCMS\Action\Import;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Import\RssImporter;
use TotalCMS\Renderer\JsonRenderer;

/**
 * Import an RSS/Atom feed by URL. The analyze variant parses the same
 * request ({@see ImportRssAnalyzeAction}), so the URL check cannot drift
 * between them again.
 */
readonly class ImportRssAction extends ImportEndpoint
{
	public function __construct(protected RssImporter $importer, JsonRenderer $renderer)
	{
		parent::__construct($renderer);
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$params = (array)$request->getParsedBody();
		$url    = isset($params['url']) ? trim((string)$params['url']) : '';

		if ($url === '') {
			return $this->reject($response, 'Missing required field: url');
		}

		if (filter_var($url, FILTER_VALIDATE_URL) === false) {
			return $this->reject($response, 'Invalid URL provided');
		}

		return $this->run($response, $url, $params);
	}

	/** @param array<string,mixed> $params */
	protected function run(ResponseInterface $response, string $url, array $params): ResponseInterface
	{
		$collection = isset($params['collection']) ? trim((string)$params['collection']) : '';
		if ($collection === '') {
			return $this->reject($response, 'Missing required field: collection');
		}

		$options = [];
		if (isset($params['draft'])) {
			$options['draft'] = filter_var($params['draft'], FILTER_VALIDATE_BOOLEAN);
		}
		if (isset($params['fieldMap']) && is_array($params['fieldMap'])) {
			$options['fieldMap'] = $params['fieldMap'];
		}

		return $this->attempt($response, 'Import failed', fn (): array => $this->imported(
			$this->importer->import($url, $collection, $options),
			'Successfully queued %d items for import from RSS feed.',
		));
	}
}
