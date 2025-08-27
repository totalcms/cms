<?php

namespace TotalCMS\Action\JobQueue;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\JobQueue\Service\JobManager;
use TotalCMS\Renderer\JsonRenderer;

readonly class JobQueueClearAction
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
		$cleared = $this->manager->clearQueue();

		if ($cleared === false) {
			return $response->withStatus(500);
		}

		return $this->renderer->json($response, ['cleared' => $cleared]);
	}
}
