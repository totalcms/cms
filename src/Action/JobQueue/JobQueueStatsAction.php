<?php

declare(strict_types=1);

namespace TotalCMS\Action\JobQueue;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\JobQueue\Service\JobManager;
use TotalCMS\Renderer\JsonRenderer;

readonly class JobQueueStatsAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private JobManager $manager,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$stats = $this->manager->queueStats();

		// Include debug info when ?debug=1 is passed
		$query = $request->getQueryParams();
		if (isset($query['debug']) && $query['debug'] === '1') {
			$stats['_debug']    = $this->manager->getDatabaseInfo();
			$stats['_rawCount'] = $this->manager->getRawJobCount();
		}

		return $this->renderer->json($response, $stats);
	}
}
