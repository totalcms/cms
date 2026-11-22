<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin;

use Odan\Session\PhpSession;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Routing\RouteContext;
use TotalCMS\Action\Admin\Utils\BackupsPageData;
use TotalCMS\Domain\Auth\Exception\AccessDeniedException;
use TotalCMS\Domain\Backup\Service\BackupAccess;
use TotalCMS\Domain\Backup\Service\BackupStore;
use TotalCMS\Domain\Backup\Service\ObjectHistory;
use TotalCMS\Domain\Backup\Service\ObjectRestorer;

/**
 * POST /admin/utils/backups/restore
 *
 * Restores an object from one of its snapshots: the whole record, or one
 * top-level field (`property`). Then goes back to the object's history, which
 * shows what happened.
 *
 * The route is behind the `backups` utility grant and the admin's CSRF check.
 * Here the actor must be allowed to update the object (to create it, for a
 * deleted one), and the restore itself is an ordinary save, so the write gate
 * has the last word.
 *
 * It keeps the password the record has now. A snapshot of a user holds the old
 * hash, and putting it back would make an old password work again.
 */
final readonly class BackupRestoreAction
{
	public function __construct(
		private ObjectRestorer $restorer,
		private ObjectHistory $history,
		private BackupStore $store,
		private BackupAccess $access,
		private PhpSession $session,
	) {
	}

	public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
	{
		$body       = (array)$request->getParsedBody();
		$collection = $this->text($body['collection'] ?? '');
		$id         = $this->text($body['id'] ?? '');
		$snapshot   = $this->text($body['snapshot'] ?? '');
		$property   = $this->text($body['property'] ?? '');

		$live = $this->history->isLive($collection, $id);
		if (!$this->access->restore($collection, $id, $live)) {
			throw new HttpForbiddenException($request, 'You may not restore this object.');
		}

		$notice = ['ok' => false, 'collection' => $collection, 'id' => $id, 'property' => $property, 'files' => []];

		if (!in_array($snapshot, array_column($this->store->listObjectSnapshots($collection, $id), 'file'), true)) {
			$notice['error'] = 'That snapshot no longer exists. It may have been pruned.';
		} elseif ($property !== '' && !$live) {
			$notice['error'] = 'A deleted object can only be restored whole.';
		} else {
			try {
				$result = $this->restorer->restoreWithFiles($collection, $id, $snapshot, $property === '' ? null : $property, keepPasswords: true);

				$notice['ok']      = true;
				$notice['created'] = !$live;
				$notice['files']   = $result['files'];
			} catch (\Throwable $e) {
				// The write gate's refusal speaks for itself. Anything else is
				// usually a save the schema no longer accepts.
				$notice['error'] = $e instanceof AccessDeniedException ? $e->getMessage() : 'The restore was refused: ' . $e->getMessage();
			}
		}

		$this->session->getFlash()->set(BackupsPageData::FLASH, [(string)json_encode($notice)]);

		$back = RouteContext::fromRequest($request)->getRouteParser()->urlFor('admin-utils', ['page' => 'backups'], ['collection' => $collection, 'id' => $id]);

		return $response->withStatus(302)->withHeader('Location', $back);
	}

	private function text(mixed $value): string
	{
		return is_string($value) ? trim($value) : '';
	}
}
