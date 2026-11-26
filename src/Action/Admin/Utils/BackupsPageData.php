<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin\Utils;

use Odan\Session\PhpSession;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Admin\BackupRestoreAction;
use TotalCMS\Domain\Backup\Service\BackupAccess;
use TotalCMS\Domain\Backup\Service\FileBackupStore;
use TotalCMS\Domain\Backup\Service\ObjectHistory;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Support\Config;

/**
 * The Backups utility. One page, three views, chosen by the query string:
 *
 * - nothing: pick a collection (and an object id).
 * - `collection`: the objects of it that were deleted and still have snapshots.
 * - `collection` + `id`: that object's history. `snapshot` opens one entry to
 *   show what changed; `restore` (with `property` for one field) shows what a
 *   restore would do, with the button that does it.
 *
 * Everything is a plain GET page: an entry opens by a link, and only the
 * restore itself is a POST ({@see BackupRestoreAction}).
 */
final readonly class BackupsPageData implements UtilsPageData
{
	public const PAGE_SIZE = 50;

	/** Where the restore action leaves its result for the next page view. */
	public const FLASH = 'backups';

	public function __construct(
		private ObjectHistory $history,
		private FileBackupStore $files,
		private BackupAccess $access,
		private CollectionLister $collections,
		private Config $config,
		private PhpSession $session,
	) {
	}

	public function build(ServerRequestInterface $request, string $page, string $action): array
	{
		$query      = $request->getQueryParams();
		$collection = $this->text($query['collection'] ?? '');
		$id         = $this->text($query['id'] ?? '');

		$data = [
			'view'        => 'pick',
			'collection'  => $collection,
			'id'          => $id,
			'collections' => $this->reachable(),
			'keeping'     => $this->keeping(),
			'notice'      => $this->notice(),
		];

		if ($collection === '') {
			return ['backups' => $data];
		}
		if (!isset($data['collections'][$collection])) {
			// Unknown, or one the actor's groups do not reach: the same answer.
			return ['backups' => ['view' => 'denied'] + $data];
		}

		return ['backups' => ($id === '' ? $this->deleted($collection, (int)($query['page'] ?? 1)) : $this->object($collection, $id, $query)) + $data];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function deleted(string $collection, int $page): array
	{
		if (!$this->access->deleted($collection)) {
			return ['view' => 'owned'];
		}

		$all   = $this->history->deleted($collection);
		$pages = max(1, (int)ceil(count($all) / self::PAGE_SIZE));
		$page  = min(max(1, $page), $pages);
		$rows  = [];

		foreach (array_slice($all, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE) as $row) {
			$kept   = $this->files->versions($collection, $row['id']);
			$rows[] = $row + $this->history->describe($collection, $row['id'], $row['file'], $row['at']) + [
				'files'      => count($kept),
				'size'       => $this->bytes(array_sum(array_column($kept, 'size'))),
				'canRestore' => $this->access->restore($collection, $row['id'], false),
			];
		}

		return ['view' => 'collection', 'deleted' => $rows, 'total' => count($all), 'page' => $page, 'pages' => $pages];
	}

	/**
	 * @param array<string,mixed> $query
	 *
	 * @return array<string,mixed>
	 */
	private function object(string $collection, string $id, array $query): array
	{
		if (!$this->access->object($collection, $id)) {
			return ['view' => 'denied'];
		}

		$live    = $this->history->isLive($collection, $id);
		$entries = $this->history->entries($collection, $id);
		$known   = array_column($entries, null, 'file');
		$open    = $this->text($query['snapshot'] ?? '');
		$restore = $this->text($query['restore'] ?? '');
		$field   = $this->text($query['property'] ?? '');
		$can     = $this->access->restore($collection, $id, $live);

		$data = [
			'view'       => 'object',
			'live'       => $live,
			'entries'    => $entries,
			'files'      => array_map(fn (array $file): array => ['size' => $this->bytes($file['size'])] + $file, $this->files->versions($collection, $id)),
			'canRestore' => $can,
			'open'       => null,
			'restoring'  => null,
		];

		if (isset($known[$open])) {
			$data['open'] = $known[$open] + ['changes' => $this->history->changes($collection, $id, $open)];
		}
		if ($can && isset($known[$restore])) {
			// One field of a deleted object cannot be restored: it comes back whole.
			$field   = $live ? $field : '';
			$preview = $this->history->preview($collection, $id, $restore, $field === '' ? null : $field);
			if ($preview !== null) {
				$data['restoring'] = $known[$restore] + $preview + ['property' => $field];
			}
		}

		return $data;
	}

	/**
	 * The collections the actor's groups let them update, by id.
	 *
	 * @return array<string,string> id => name
	 */
	private function reachable(): array
	{
		$reachable = [];
		foreach ($this->collections->listAllCollections() as $collection) {
			if ($this->access->reaches($collection->id)) {
				$reachable[$collection->id] = $collection->name !== '' ? $collection->name : $collection->id;
			}
		}
		asort($reachable, SORT_NATURAL | SORT_FLAG_CASE);

		return $reachable;
	}

	/**
	 * What Settings → Backups asks to be kept, so the page can say why a file
	 * is not here.
	 *
	 * @return array{enabled:bool, images:int, files:int, trash:bool}
	 */
	private function keeping(): array
	{
		$settings = $this->config->backups;

		return [
			'enabled' => (bool)($settings['enable'] ?? true),
			'images'  => max(0, (int)($settings['keepImages'] ?? 0)),
			'files'   => max(0, (int)($settings['keepFiles'] ?? 0)),
			'trash'   => (bool)($settings['trash'] ?? false),
		];
	}

	/**
	 * The result the restore action left, shown once.
	 *
	 * @return array<string,mixed>|null
	 */
	private function notice(): ?array
	{
		$flash = $this->session->getFlash();
		if (!$flash->has(self::FLASH)) {
			return null;
		}

		$notice = json_decode($flash->get(self::FLASH)[0] ?? '', true);

		return is_array($notice) ? $notice : null;
	}

	private function bytes(int $bytes): string
	{
		return match (true) {
			$bytes >= 1048576 => sprintf('%.1f MB', $bytes / 1048576),
			$bytes >= 1024    => sprintf('%.1f KB', $bytes / 1024),
			default           => "{$bytes} B",
		};
	}

	private function text(mixed $value): string
	{
		return is_string($value) ? trim($value) : '';
	}
}
