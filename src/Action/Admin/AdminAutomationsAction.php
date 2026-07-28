<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Automation\Service\AutomationRunReader;
use TotalCMS\Domain\Automation\Service\AutomationStateStore;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Extension\Service\DangerousCodeScanner;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Renderer\TwigRenderer;

/**
 * Admin section for automations — list, code editor, and run history, covering
 * both file-based automations and the read-only ones contributed by extensions.
 * Mirrors AdminMailerAction (thin controller; the Twig template + form builder
 * do the work). Editor extras: a DangerousCodeScanner advisory on the handler, the
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
		private ExtensionManager $extensions,
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
			// Extension-contributed automations run through the same runner and
			// already write run history, but nothing listed them — so a scheduled
			// extension job could fail, hit the auto-disable threshold and stop,
			// with no row in the admin to show it or re-enable it.
			'extensionAutomations' => $this->extensionAutomations(),
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

	/**
	 * Extension automations grouped by owning extension, with their failure count
	 * and whether the `automations` capability currently permits them.
	 *
	 * Read-only by nature rather than by policy: the handler is a PHP closure
	 * inside the extension, so there is no source to edit and nothing to persist.
	 * Disabling stays in the extension's own settings, where the capability
	 * toggle already lives.
	 *
	 * @return array<string,list<array<string,mixed>>>
	 */
	private function extensionAutomations(): array
	{
		$grouped = [];
		foreach ($this->extensions->listAutomationsForAdmin() as $row) {
			$grouped[$row['extension']][] = [
				'id'        => $row['key'],
				'name'      => $row['label'],
				'triggers'  => $row['triggers'],
				'permitted' => $row['permitted'],
				'failures'  => $this->state->failures($row['key']),
			];
		}

		return $grouped;
	}
}
