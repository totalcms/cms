<?php

namespace TotalCMS\Action\JobQueue;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Admin\JobQueueStats;
use TotalCMS\Domain\JobQueue\Service\JobManager;
use TotalCMS\Renderer\RawRenderer;
use TotalCMS\Support\Config;

readonly class JobQueueStatsHtmlAction
{
	public function __construct(
		private RawRenderer $renderer,
		private JobManager $manager,
		private Config $config,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$collection = $args['collection'] ?? '';

		$stats = new JobQueueStats(
			api: $this->config->api . '/api',
			jobManager: $this->manager,
			collection: $collection,
		);

		return $this->renderer->render($response, $stats->allStatsTables());
	}
}
