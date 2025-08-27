<?php

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

		return $this->renderer->json($response, $stats);
	}
}
