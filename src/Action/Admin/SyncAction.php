<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Settings\Services\SettingsFetcher;
use TotalCMS\Domain\Sync\Service\SyncService;
use TotalCMS\Renderer\JsonRenderer;

readonly class SyncAction
{
	public function __construct(
		private JsonRenderer $renderer,
		private SettingsFetcher $settingsFetcher,
		private SyncService $syncService,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$action = $args['action'] ?? '';

		// Validate sync is configured
		$syncSettings = $this->settingsFetcher->loadSection('sync');
		$url          = trim((string) ($syncSettings['url'] ?? ''));
		$key          = trim((string) ($syncSettings['key'] ?? ''));

		if ($url === '' || $key === '') {
			return $this->renderer->json($response, [
				'success' => false,
				'error'   => 'Sync not configured. Set the production URL and API key in Settings > Sync.',
			])->withStatus(400);
		}

		$post      = (array) $request->getParsedBody();
		$schemas   = $this->parseList($post['schemas'] ?? []);
		$templates = $this->parseList($post['templates'] ?? []);

		try {
			$result = match ($action) {
				'push' => $this->syncService->push($url, $key, $schemas, $templates),
				'pull' => $this->syncService->pull($url, $key, $schemas, $templates),
				default => throw new \InvalidArgumentException("Unknown sync action: {$action}"),
			};
		} catch (\InvalidArgumentException $e) {
			return $this->renderer->json($response, [
				'success' => false,
				'error'   => $e->getMessage(),
			])->withStatus(400);
		} catch (\RuntimeException $e) {
			return $this->renderer->json($response, [
				'success' => false,
				'error'   => $e->getMessage(),
			])->withStatus(502);
		}

		return $this->renderer->json($response, $result);
	}

	/**
	 * @return list<string>|null
	 */
	private function parseList(mixed $value): ?array
	{
		if (is_array($value) && $value !== []) {
			return array_values(array_filter(array_map('strval', $value)));
		}

		return null;
	}
}
