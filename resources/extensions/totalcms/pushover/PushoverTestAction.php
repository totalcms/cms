<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\Pushover;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Renderer\TwigRenderer;

readonly class PushoverTestAction
{
	public function __construct(
		private PushoverService $pushoverService,
		private TwigRenderer $twigRenderer,
	) {
	}

	/**
	 * @param array<string,string> $args
	 */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
	{
		$data = [
			'url' => [
				'path'   => $request->getUri()->getPath(),
				'query'  => $request->getUri()->getQuery(),
				'params' => $args,
				'page'   => 'extensions',
			],
		];

		if ($request->getMethod() === 'POST') {
			$body    = (array)$request->getParsedBody();
			$message = trim((string)($body['message'] ?? ''));

			if ($message === '') {
				$message = 'This is a test notification from Total CMS.';
			}

			$result = $this->pushoverService->send(
				message: $message,
				title: 'Total CMS Test',
			);

			$data['testResult'] = [
				'success' => $result->success,
				'message' => $result->success
					? 'Test notification sent successfully. Check your Pushover app.'
					: 'Failed to send test notification: ' . $result->message,
			];
		}

		return $this->twigRenderer->template($response, 'admin/pushover-test.twig', $data);
	}
}
