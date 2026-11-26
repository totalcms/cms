<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\S3Uploads\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpNotFoundException;
use TotalCMS\Bundled\S3Uploads\Access\S3Access;
use TotalCMS\Bundled\S3Uploads\Serving\DownloadCounter;
use TotalCMS\Bundled\S3Uploads\Serving\PointerLocator;
use TotalCMS\Bundled\S3Uploads\Serving\UrlResolver;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Event\Data\CoreEvent;
use TotalCMS\Domain\Event\Payload\FileDownloadedPayload;
use TotalCMS\Domain\Event\Service\EventDispatcher;

/**
 * GET|HEAD /api/ext/totalcms/s3-uploads/stream/{collection}/{id}/{property}[/{file}]
 * GET|HEAD /api/ext/totalcms/s3-uploads/download/{collection}/{id}/{property}[/{file}].
 *
 * The public links templates print, named and behaving as core's own
 * `/stream` and `/download` file routes do. Both count the hit and redirect to
 * the file; neither sends its bytes.
 *
 * - `/stream` shows the file: the bucket or CDN for a public bucket, a
 *   short-lived signed link for a private one. It counts and nothing more, so
 *   a feed or a player that uses it does not flood listeners.
 * - `/download` saves the file under its original name, always through a
 *   signed link (only a signed request can set the name). It also fires
 *   `file.downloaded`, and a protected one is written to the file-access log
 *   with the member who took it.
 *
 * The destination always comes from the stored pointer, never from the
 * request, so this cannot be used to redirect anywhere else. What is counted
 * is the redirect; the bucket serves the bytes, so whether the transfer
 * finished is not known here.
 */
final readonly class DownloadRedirectAction
{
	/**
	 * @param \Closure(string, string): string $loginUrl  the login page for a collection, returning to a URL afterwards
	 * @param LoggerInterface                  $accessLog the file-access log core writes protected downloads to
	 */
	public function __construct(
		private PointerLocator $locator,
		private UrlResolver $urls,
		private DownloadCounter $counter,
		private CollectionFetcher $collections,
		private S3Access $access,
		private \Closure $loginUrl,
		private EventDispatcher $events,
		private LoggerInterface $accessLog,
	) {
	}

	/** @param array<string,string> $args */
	public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
	{
		$collection = (string)($args['collection'] ?? '');
		$id         = (string)($args['id'] ?? '');
		$property   = (string)($args['property'] ?? '');
		$fileId     = (string)($args['file'] ?? '');
		$download   = ($args['mode'] ?? 'stream') === 'download';

		$found = $this->locator->find($collection, $id, $property, $fileId);
		if ($found === null) {
			throw new HttpNotFoundException($request, 'File not found');
		}
		$pointer = $found['pointer'];

		$groups = $this->protectingGroups($collection, $found['settings']);
		$denied = $this->gate($request, $response, $collection, $groups);
		if ($denied !== null) {
			return $denied;
		}

		if ($this->counts($request)) {
			$this->counter->increment($collection, $id, $fileId === '' ? $property : $property . '/' . $fileId);
			if ($download) {
				$this->report($collection, $id, $property, $pointer->name, $groups);
			}
		}

		$location = $download ? $this->urls->downloadUrl($pointer) : $this->urls->directUrl($pointer);

		return $response
			->withStatus(302)
			->withHeader('Location', $location)
			->withHeader('Cache-Control', 'no-store')
			->withHeader('Referrer-Policy', 'no-referrer');
	}

	/**
	 * The access groups that protect this file: the collection's, unless the
	 * property sets `protectedByCollection` to false. That is core's setting
	 * for its own file fields, with core's default: in a collection with
	 * groups a file is protected until the schema says otherwise. Empty when
	 * the file is open to anyone.
	 *
	 * @param array<string,mixed> $settings
	 *
	 * @return array<int|string,mixed>
	 */
	private function protectingGroups(string $collection, array $settings): array
	{
		// A hand-written schema may say "false" or 0. Only a clear no opens the
		// file; anything else, a typo included, leaves it protected.
		$protected = filter_var($settings['protectedByCollection'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;

		// A site with authentication switched off has no login to send anyone to.
		if (!$protected || $this->access->open()) {
			return [];
		}

		return $this->collections->fetchCollection($collection)->groups ?? [];
	}

	/**
	 * Enforce the groups. Unprotected files never touch the session.
	 *
	 * @param array<int|string,mixed> $groups
	 */
	private function gate(ServerRequestInterface $request, ResponseInterface $response, string $collection, array $groups): ?ResponseInterface
	{
		if ($groups === []) {
			return null;
		}

		if (!$this->access->signedIn()) {
			return $response->withStatus(302)->withHeader('Location', ($this->loginUrl)($collection, (string)$request->getUri()));
		}
		if (!$this->access->inGroups($groups)) {
			$response->getBody()->write('You do not have access to this file.');

			return $response->withStatus(403)->withHeader('Content-Type', 'text/plain; charset=utf-8');
		}

		return null;
	}

	/**
	 * Tell the rest of the site about a counted download, as core does for its
	 * own files. Bookkeeping only: it must never fail the download.
	 *
	 * @param array<int|string,mixed> $groups the groups protecting the file, empty when it is open
	 */
	private function report(string $collection, string $id, string $property, string $filename, array $groups): void
	{
		try {
			$user = $groups === [] ? null : $this->access->user();
			if ($user !== null) {
				// The same record, in the same log, as FileAccessManager::logDownload().
				$this->accessLog->info('Protected file downloaded', [
					'user_id'         => $user['id'],
					'user_collection' => $user['collection'],
					'collection'      => $collection,
					'object_id'       => $id,
					'property'        => $property,
					'filename'        => $filename,
					'subpath'         => null,
					'timestamp'       => date('Y-m-d H:i:s'),
					'is_super_admin'  => $user['superAdmin'],
					'user_groups'     => $groups,
				]);
			}

			$this->events->dispatch(CoreEvent::FILE_DOWNLOADED, new FileDownloadedPayload($collection, $id, $property, $filename, '', $groups !== []));
		} catch (\Throwable) {
			// The file is still served.
		}
	}

	/**
	 * A GET for the file from its first byte. HEAD, a seek into the file and the
	 * two-byte probe media players send first are not downloads.
	 */
	private function counts(ServerRequestInterface $request): bool
	{
		if ($request->getMethod() !== 'GET') {
			return false;
		}

		$range = strtolower(str_replace(' ', '', $request->getHeaderLine('Range')));

		return $range === '' || (str_starts_with($range, 'bytes=0-') && $range !== 'bytes=0-1');
	}
}
