<?php

namespace TotalCMS\Action\Export;

use Nyholm\Psr7\Stream;
use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Object\Service\ObjectExporter;

readonly class ExportJsonAction
{
	public function __construct(
		private ObjectExporter $objectExporter,
		private SessionInterface $session,
	) {
	}

	/** @param array<string,string> $args The arguments	 */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$collection = $args['collection'];
		$result     = $this->objectExporter->exportAllObjectsForJson($collection);
		$objects    = $result['data'];
		$errors     = $result['errors'];

		// If there were errors, set a flash message for the user
		if (count($errors) > 0) {
			$flash   = $this->session->getFlash();
			$message = sprintf(
				'%d object(s) were skipped during JSON export due to data mismatches. Check the logs for more information.',
				count($errors)
			);
			$flash->add('warning', $message);
		}

		$response = $response->withHeader('Content-Type', 'application/json')
			->withHeader('Content-Disposition', sprintf('attachment; filename="collection-%s.json"', $collection));

		$jsonData = json_encode($objects);

		if ($jsonData === false) {
			$response = $response->withStatus(500);
			$response->getBody()->write('Failed to encode JSON');

			return $response;
		}

		return $response->withBody(Stream::create($jsonData));
	}
}
