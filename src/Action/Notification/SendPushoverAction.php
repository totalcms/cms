<?php

declare(strict_types=1);

namespace TotalCMS\Action\Notification;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Notification\Service\PushoverService;
use TotalCMS\Renderer\JsonRenderer;

/**
 * SendPushoverAction handles push notification sending via Pushover.
 */
readonly class SendPushoverAction
{
	public function __construct(
		private PushoverService $pushoverService,
		private JsonRenderer $renderer,
		private AccessManager $accessManager,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$data = (array)$request->getParsedBody();

		// Validate required fields
		if (!isset($data['message']) || $data['message'] === '') {
			return $this->renderer->json($response->withStatus(400), [
				'success' => false,
				'message' => 'message is required',
			]);
		}

		$title     = (string)($data['title'] ?? '');
		$message   = (string)$data['message'];
		$priority  = (int)($data['priority'] ?? 0);
		$sound     = (string)($data['sound'] ?? '');
		$link      = (string)($data['link'] ?? '');
		$linkTitle = (string)($data['linkTitle'] ?? '');
		$formData  = $data['data'] ?? [];
		$image     = $data['image'] ?? [];

		if ($formData === '') {
			$formData = [];
		} elseif (is_string($formData)) {
			$formData = json_decode($formData, true);
		}

		if (!is_array($formData)) {
			return $this->renderer->json($response->withStatus(400), [
				'success' => false,
				'message' => 'data must be an array',
			]);
		}

		if (!is_array($image)) {
			$image = [];
		}

		$userData = $this->accessManager->userData();

		$result = $this->pushoverService->send(
			message   : $message,
			data      : $formData,
			user      : $userData,
			priority  : $priority,
			title     : $title,
			sound     : $sound,
			link      : $link,
			linkTitle : $linkTitle,
			image     : $image,
		);

		if (!$result['success']) {
			$response = $response->withStatus(500);
		}

		return $this->renderer->json($response, $result);
	}
}
