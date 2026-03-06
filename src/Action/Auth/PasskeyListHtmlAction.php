<?php

declare(strict_types=1);

namespace TotalCMS\Action\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Auth\Service\AccessManager;
use TotalCMS\Domain\Auth\Service\PasskeyService;
use TotalCMS\Domain\Rendering\Utilities\HTMLUtils;
use TotalCMS\Renderer\RawRenderer;
use TotalCMS\Support\Config;

readonly class PasskeyListHtmlAction
{
	public function __construct(
		private PasskeyService $passkeyService,
		private AccessManager $accessManager,
		private RawRenderer $renderer,
		private Config $config,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(
		ServerRequestInterface $request,
		ResponseInterface $response,
		array $args,
	): ResponseInterface {
		$user       = $this->accessManager->userData();
		$userId     = (string)($user['id'] ?? '');
		$collection = (string)($user['collection'] ?? '');

		$passkeys = $this->passkeyService->listPasskeys($userId, $collection);

		return $this->renderer->render($response, $this->renderTable($passkeys));
	}

	/** @param array<int,array<string,mixed>> $passkeys */
	private function renderTable(array $passkeys): string
	{
		if ($passkeys === []) {
			return HTMLUtils::element('p', 'No passkeys registered yet.', ['class' => 'passkeys-empty']);
		}

		$rows = '';
		foreach ($passkeys as $pk) {
			$name      = htmlspecialchars((string)($pk['name'] ?? 'Unnamed'));
			$created   = $this->formatDate((string)($pk['createdAt'] ?? ''));
			$lastUsed  = $this->formatDate((string)($pk['lastUsed'] ?? ''));
			$credId    = htmlspecialchars((string)($pk['credentialId'] ?? ''));

			$deleteBtn = HTMLUtils::button('Delete', [
				'type'       => 'button',
				'class'      => 'cms-button cms-button-small cms-button-danger passkey-delete',
				'hx-delete'  => $this->config->api . '/passkeys/' . urlencode($credId),
				'hx-confirm' => 'Delete this passkey? You will no longer be able to sign in with it.',
				'hx-target'  => '#passkeys-list',
				'hx-swap'    => 'innerHTML',
			]);

			$cols = HTMLUtils::element('td', $name)
				. HTMLUtils::element('td', $created)
				. HTMLUtils::element('td', $lastUsed)
				. HTMLUtils::element('td', $deleteBtn);

			$rows .= HTMLUtils::element('tr', $cols);
		}

		$headerRow = HTMLUtils::element('tr',
			HTMLUtils::element('th', 'Name')
			. HTMLUtils::element('th', 'Created')
			. HTMLUtils::element('th', 'Last Used')
			. HTMLUtils::element('th', '')
		);
		$thead = HTMLUtils::element('thead', $headerRow);
		$tbody = HTMLUtils::element('tbody', $rows);

		return HTMLUtils::element('table', $thead . $tbody, ['class' => 'passkeys-table']);
	}

	private function formatDate(string $date): string
	{
		if ($date === '') {
			return '';
		}

		$timestamp = strtotime($date);
		if ($timestamp === false) {
			return '';
		}

		return date('M j, Y', $timestamp);
	}
}
