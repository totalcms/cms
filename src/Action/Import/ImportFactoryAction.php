<?php

namespace TotalCMS\Action\Import;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Factory\Service\FactoryImporter;
use TotalCMS\Domain\JobQueue\Service\JobQueuer;
use TotalCMS\Renderer\JsonRenderer;

readonly class ImportFactoryAction
{
	/**
	 * The constructor.
	 *
	 * @param JsonRenderer $renderer The renderer
	 * @param FactoryImporter $importer Factory import service
	 * @param JobQueuer $jobQueuer Job queue service
	 */
	public function __construct(
		private JsonRenderer $renderer,
		private FactoryImporter $importer,
		private JobQueuer $jobQueuer,
	) {
	}

	/**
	 * Action.
	 *
	 * @param ServerRequestInterface $request The request
	 * @param ResponseInterface $response The response
	 * @param array<string,string> $args
	 *
	 * @return ResponseInterface The response
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$collection = $args['collection'];
		$params     = $request->getQueryParams();
		$rules      = json_decode($request->getBody(), true);

		// using fqty so that it's not a common name that could be used by the user
		$quantity = intval($params['fqty'] ?? $rules['fqty'] ?? 1);
		$useQueue = boolval($params['queue'] ?? $rules['queue'] ?? false);

		if (isset($rules['fqty'])) {
			unset($rules['fqty']);
		}
		if (isset($rules['queue'])) {
			unset($rules['queue']);
		}
		if (isset($rules['csrf_token'])) {
			unset($rules['csrf_token']);
		}

		// Use job queue for large quantities or when explicitly requested
		if ($useQueue || $quantity > 50) {
			$jobId = $this->jobQueuer->queueFactory($collection, $quantity, $rules);

			return $this->renderer->json($response, [
				'job_queued' => true,
				'job_id'     => $jobId,
				'quantity'   => $quantity,
				'message'    => "Queued factory job to generate {$quantity} objects",
			]);
		}

		// Direct import for small quantities
		$importCount = $this->importer->import($collection, $quantity, $rules);

		return $this->renderer->json($response, ['import_count' => $importCount]);
	}
}
