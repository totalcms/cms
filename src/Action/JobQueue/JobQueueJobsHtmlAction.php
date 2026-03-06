<?php

namespace TotalCMS\Action\JobQueue;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Twig\Adapter\AdminTwigAdapter;
use TotalCMS\Renderer\RawRenderer;

readonly class JobQueueJobsHtmlAction
{
	public function __construct(
		private RawRenderer $renderer,
		private AdminTwigAdapter $adminAdapter,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$html = $this->adminAdapter->jobQueuePendingInfo()
			. $this->adminAdapter->jobQueueFailedInfo();

		return $this->renderer->render($response, $html);
	}
}
