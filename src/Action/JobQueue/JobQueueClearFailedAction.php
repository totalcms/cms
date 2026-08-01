<?php

declare(strict_types=1);

namespace TotalCMS\Action\JobQueue;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\JobQueue\Service\JobManager;
use TotalCMS\Renderer\JsonRenderer;

readonly class JobQueueClearFailedAction
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
		// Unlike clearQueue(), a zero here is a normal result rather than a
		// failure signal — there was simply nothing to clear — so the count is
		// returned as-is instead of being mapped to a 500.
		$cleared = $this->manager->clearFailedJobs();

		return $this->renderer->json($response, ['cleared' => $cleared]);
	}
}
