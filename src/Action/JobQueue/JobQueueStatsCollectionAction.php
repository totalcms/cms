<?php

namespace TotalCMS\Action\JobQueue;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\JobQueue\Service\JobManager;
use TotalCMS\Renderer\JsonRenderer;

final readonly class JobQueueStatsCollectionAction
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
		$stats = $this->manager->queueStatsForCollection($args['collection']);

		return $this->renderer->json($response, $stats);
	}
}
