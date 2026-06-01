<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Automation\Service\AutomationRunReader;
use TotalCMS\Domain\Automation\Service\AutomationStateStore;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Extension\Service\DangerousCodeScanner;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Renderer\TwigRenderer;

/**
 * Admin section for automations — list, code editor, and run history. Mirrors
 * AdminMailerAction (thin controller; the Twig template + form builder do the
 * work). Editor extras: a DangerousCodeScanner advisory on the handler, the
 * per-run history (via AutomationRunReader), and the consecutive-failure count
 * that drives the auto-disabled banner.
 */
readonly class AdminAutomationsAction
{
	public function __construct(
		private TwigRenderer $twigRenderer,
		private CollectionFetcher $collectionFetcher,
		private ObjectFetcher $objectFetcher,
		private DangerousCodeScanner $scanner,
		private AutomationStateStore $state,
		private AutomationRunReader $runReader,
	) {
	}

	/** @param array<string,string> $args The routing arguments */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
	{
		$this->collectionFetcher->fetchOrCreateReserved('automations');

		$id           = $args['id'] ?? '';
		$templateData = [
			'url' => [
				'path'       => $request->getUri()->getPath(),
				'query'      => $request->getUri()->getQuery(),
				'page'       => 'automations',
				'id'         => $id,
				'collection' => 'automations',
			],
			// The sidebar renders in every mode, so the newest-run-per-automation
			// status badges are always needed.
			'lastRuns' => $this->runReader->latestPerAutomation(),
		];

		$reserved = ['', 'new', '-export', '-import'];

		if (!in_array($id, $reserved, true)) {
			// Editor mode: surface the handler advisory, run history, and the
			// failure/auto-disable state for the banner.
			try {
				$data          = $this->objectFetcher->fetchObject('automations', $id)->toArray();
				$handlerSource = is_string($data['handler'] ?? null) ? $data['handler'] : '';

				$templateData['findings'] = $this->scanner->scanCode($handlerSource);
				$templateData['runs']     = $this->runReader->history($id);
				$templateData['failures'] = $this->state->failures($id);
				$templateData['disabled'] = !filter_var($data['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
			} catch (\Throwable) {
				// Unknown id — fall through and let the template show the list.
			}
		} elseif ($request->getMethod() === 'POST' && $id === 'new') {
			$postData = (array)$request->getParsedBody();
			if (isset($postData['duplicate']) && is_string($postData['duplicate'])) {
				$templateData['duplicateData'] = $this->objectFetcher
					->fetchObject('automations', $postData['duplicate'])
					->toArray();
			}
		}

		return $this->twigRenderer->template($response, 'admin/automations.twig', $templateData);
	}
}
