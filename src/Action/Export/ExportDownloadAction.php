<?php

namespace TotalCMS\Action\Export;

use Nyholm\Psr7\Stream;
use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Object\Service\ObjectExporter;

/**
 * A collection export as a file download: the include/exclude query (with
 * the legacy `filter` alias), the "N objects skipped" flash, and the
 * attachment headers. Subclasses pick the exporter call and the encoding.
 */
abstract readonly class ExportDownloadAction
{
	public function __construct(
		protected ObjectExporter $objectExporter,
		protected SessionInterface $session,
	) {
	}

	/** Short format name for the filename extension (lowercased) and the flash message. */
	abstract protected function format(): string;

	abstract protected function contentType(): string;

	/**
	 * @param array<string,string> $params
	 *
	 * @return array{data: array<mixed>, errors: array<string>}
	 */
	abstract protected function export(string $collection, array $params, bool $filtered): array;

	/**
	 * @param array<mixed> $objects
	 *
	 * @return string|null null when the objects could not be encoded
	 */
	abstract protected function encode(array $objects): ?string;

	/** @param array<string,string> $args The arguments  */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$collection = $args['collection'];
		$params     = $request->getQueryParams();

		// Backwards compatibility: remap 'filter' to 'include'
		if (isset($params['filter']) && !isset($params['include'])) {
			$params['include'] = $params['filter'];
			unset($params['filter']);
		}

		$filtered = isset($params['include']) || isset($params['exclude']);
		$result   = $this->export($collection, $params, $filtered);

		if (count($result['errors']) > 0) {
			$this->session->getFlash()->add('warning', sprintf(
				'%d object(s) were skipped during %s export due to data mismatches. Check the logs for more information.',
				count($result['errors']),
				$this->format(),
			));
		}

		$response = $response->withHeader('Content-Type', $this->contentType())
			->withHeader('Content-Disposition', sprintf('attachment; filename="collection-%s.%s"', $collection, strtolower($this->format())));

		$body = $this->encode($result['data']);
		if ($body === null) {
			$response = $response->withStatus(500);
			$response->getBody()->write('Failed to encode ' . $this->format());

			return $response;
		}

		return $response->withBody(Stream::create($body));
	}
}
