<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\WebMcp;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Support\Config;

/**
 * GET /api/ext/totalcms/webmcp/tools.json — what the frontend script
 * registers as read tools, plus the origin-trial token.
 *
 * The tools call the collections API with the browser's own session, so
 * the list is what that session can read: a visitor gets the listed
 * collections that allow public `read`, and a signed-in operator — on any
 * page, dashboard or public — gets every listed collection, uncached.
 * Labels and descriptions are the collection's own, which the operator
 * wrote — never object content.
 */
final readonly class ToolManifestAction
{
	/** @param \Closure(): bool $operatorSignedIn */
	public function __construct(
		private ExtensionSettingsManager $settings,
		private CollectionFetcher $collections,
		private Config $config,
		private \Closure $operatorSignedIn,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
	{
		$id      = WebMcpFormBuilder::EXTENSION_ID;
		$admin   = ($this->operatorSignedIn)();
		$enabled = $this->settings->getSetting($id, 'readTools', true) === true;
		$listed  = $this->settings->getSetting($id, 'readCollections', []);
		$max     = (int)$this->settings->getSetting($id, 'maxResults', 10);

		$tools = [];
		if ($enabled && is_array($listed)) {
			foreach ($listed as $collectionId) {
				if (!is_string($collectionId)) {
					continue;
				}
				$collection = $this->collections->fetchCollection($collectionId);
				if (!$collection instanceof CollectionData) {
					continue;
				}
				if (!$admin && !in_array('read', array_map(strtolower(...), $collection->publicOperations), true)) {
					continue;
				}
				$record  = $collection->toArray();
				$tools[] = [
					'collection'  => $collection->id,
					'label'       => (string)($record['labelPlural'] ?? $collection->name),
					'description' => WebMcpAttributes::description((string)($record['description'] ?? '')),
				];
			}
		}

		$json = json_encode([
			'originTrialToken' => trim((string)$this->settings->getSetting($id, 'originTrialToken', '')),
			'maxResults'       => max(1, min(50, $max)),
			'api'              => $this->config->api . '/api',
			'tools'            => $tools,
		], JSON_UNESCAPED_SLASHES);

		$response->getBody()->write((string)$json);

		// Vary on the cookie so a visitor's cached list is never reused once
		// they sign in, and the operator's list is never cached at all.
		return $response
			->withHeader('Content-Type', 'application/json; charset=utf-8')
			->withHeader('Vary', 'Cookie')
			->withHeader('Cache-Control', $admin ? 'private, no-store' : 'public, max-age=300');
	}
}
