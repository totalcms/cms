<?php

declare(strict_types=1);

namespace TotalCMS\Bundled\S3Uploads\Upload;

use TotalCMS\Bundled\S3Uploads\Access\S3Access;
use TotalCMS\Bundled\S3Uploads\S3\S3Client;
use TotalCMS\Bundled\S3Uploads\S3\S3Exception;
use TotalCMS\Bundled\S3Uploads\S3\S3Settings;
use TotalCMS\Bundled\S3Uploads\Storage\KeyBuilder;
use TotalCMS\Bundled\S3Uploads\Storage\Pointer;
use TotalCMS\Bundled\S3Uploads\Storage\S3Fields;
use TotalCMS\Domain\Auth\Exception\AccessDeniedException;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Object\Service\ObjectPatcher;
use TotalCMS\Domain\Security\SignedToken;
use TotalCMS\Domain\Storage\FileLock;

/**
 * The two server steps of an upload. The file itself goes from the browser
 * straight to the bucket in between.
 *
 * presign(): check the request, pick the key, and hand back a link that can
 * PUT exactly that key with the announced type and size, plus a signed token
 * describing the upload.
 *
 * commit(): take the token back, ask the bucket what actually arrived, and
 * write the pointer to the object. The browser never names a key: it reaches
 * commit() only inside the token, which is bound to the user who asked.
 */
final readonly class UploadService
{
	public const TOKEN_PURPOSE = 's3-upload';

	/** Seconds the browser has to start the PUT. */
	private const PUT_EXPIRES = 900;

	/**
	 * Seconds between presign and commit. The whole upload has to fit inside
	 * it, and 5 GB on a slow line takes hours, so it is a day. The token is
	 * bound to one user and one key, so a long life gives nothing away.
	 */
	private const TOKEN_EXPIRES = 86400;

	private const LOCK_DIR = '.system/extension-data/totalcms/s3-uploads/locks/';

	/** Longest duration accepted from the browser: a day. */
	private const MAX_DURATION = 86400;

	/**
	 * @param \Closure(): bool $licensed whether this site may upload
	 * @param \Closure(): int  $now      Unix time
	 */
	public function __construct(
		private S3Settings $settings,
		private S3Client $client,
		private KeyBuilder $keys,
		private S3Fields $fields,
		private \Closure $licensed,
		private SignedToken $tokens,
		private ObjectFetcher $objects,
		private ObjectPatcher $patcher,
		private S3Access $access,
		private FileLock $lock,
		private \Closure $now,
	) {
	}

	/**
	 * @param int $size bytes, as the browser reports them
	 *
	 * @return array{url:string, method:string, headers:array<string,string>, key:string, token:string}
	 *
	 * @throws UploadError when the upload is refused
	 * @throws S3Exception
	 */
	public function presign(string $collection, string $id, string $property, string $filename, int $size, string $mime): array
	{
		if (!($this->licensed)()) {
			throw new UploadError('s3.unlicensed', 403, 'S3 Uploads needs Total CMS Pro.');
		}
		if (!$this->settings->isConfigured()) {
			throw new UploadError('s3.unconfigured', 503, 'S3 Uploads is not set up yet. A super admin can add the bucket in Extensions → S3 Uploads.');
		}

		$field = $this->fields->property($collection, $property);
		if ($field === null) {
			throw new UploadError('s3.bad_property', 400, "{$property} is not an S3 file field.");
		}
		if (!$this->fields->storesJson($collection, $property)) {
			// Refused here, before the file travels: the save at commit would drop the pointer.
			throw new UploadError('s3.bad_type', 400, "{$property} cannot hold an S3 file yet: its Type of Data in the schema is not json. Set it to json, save the schema, and try again.");
		}
		if (!$this->objects->existsObject($collection, $id)) {
			throw new UploadError('s3.no_object', 404, 'Save the object before uploading.');
		}

		// The save at commit enforces both again; checking here keeps a user
		// who would be refused then from filling the bucket first.
		if (!$this->access->can($collection, 'update') || !$this->access->owns($collection, $id)) {
			throw $this->forbidden($collection);
		}

		$name  = Pointer::cleanName($filename);
		$mime  = trim($mime) === '' ? 'application/octet-stream' : strtolower(trim($mime));
		$count = $field['field'] === S3Fields::DEPOT ? count($this->currentPointers($collection, $id, $property, $field['field'])) : 0;
		$this->enforceRules($field, $name, $size, $mime, $count);

		try {
			$key = $this->keys->build($collection, $id, $property, $name, $this->folder($field));
		} catch (\InvalidArgumentException) {
			throw new UploadError('s3.no_object', 404, 'Save the object before uploading.');
		}

		$token = $this->tokens->sign(self::TOKEN_PURPOSE, [
			'c' => $collection,
			'i' => $id,
			'p' => $property,
			'k' => $key,
			's' => $size,
			'm' => $mime,
			'n' => $name,
			'u' => $this->access->userId(),
		], ($this->now)() + self::TOKEN_EXPIRES);

		return [
			'url'     => $this->client->presignPut($key, $mime, $size, self::PUT_EXPIRES),
			'method'  => 'PUT',
			'headers' => ['Content-Type' => $mime],
			'key'     => $key,
			'token'   => $token,
		];
	}

	/**
	 * @param float|null $duration seconds, as the browser measured them; kept for audio and video only
	 *
	 * @return array{value:mixed, item:array<string,mixed>} the property's new stored value, and the pointer just added
	 *
	 * @throws UploadError when the upload is refused
	 * @throws S3Exception
	 */
	public function commit(string $token, ?float $duration): array
	{
		$upload = $this->tokens->verify(self::TOKEN_PURPOSE, $token);
		$userId = $this->access->userId();
		if ($upload === null || !$this->access->identified() || ($upload['u'] ?? null) !== $userId || !is_string($upload['k'] ?? null)) {
			throw new UploadError('s3.token', 400, 'This upload expired. Try again.');
		}

		$collection = (string)($upload['c'] ?? '');
		$id         = (string)($upload['i'] ?? '');
		$property   = (string)($upload['p'] ?? '');
		$key        = $upload['k'];
		$name       = (string)($upload['n'] ?? '');

		$field = $this->fields->property($collection, $property);

		// The same token again (a double submit, a proxy retry): the file is
		// already on the object, so answer as the first time and touch nothing.
		$already = $field === null ? null : $this->stored($collection, $id, $property, $field['field'], $key);
		if ($already !== null) {
			return $already;
		}

		$found = $this->client->headObject($key);
		if ($found === null) {
			throw new UploadError('s3.missing_upload', 409, 'The file did not reach the bucket. If the browser console shows a CORS error, add the CORS rules from the S3 Uploads docs to the bucket.');
		}

		$mime = strtolower(trim($found['mime']));
		if ($found['size'] !== ($upload['s'] ?? null) || $mime !== ($upload['m'] ?? null)) {
			$this->discard($key);

			throw new UploadError('s3.mismatch', 422, 'The uploaded file does not match what was announced, so it was removed. Try again.');
		}
		if ($field === null) {
			$this->discard($key);

			throw new UploadError('s3.bad_property', 400, "{$property} is not an S3 file field.");
		}

		$pointer = new Pointer(
			id: (string)KeyBuilder::idFromKey($key),
			key: $key,
			name: $name,
			size: $found['size'],
			mime: $mime,
			etag: $found['etag'],
			duration: $this->duration($duration, $mime),
			uploadedAt: gmdate('c', ($this->now)()),
			uploadedBy: $userId,
		);

		try {
			// One commit at a time per object: two files finishing together on a
			// depot would otherwise each append to the list the other had read.
			return $this->lock->withLock(
				self::LOCK_DIR . KeyBuilder::segment($collection) . '--' . KeyBuilder::segment($id),
				fn (): array => $this->save($collection, $id, $property, $field, $pointer),
			);
		} catch (UploadError $e) {
			$this->discard($key);

			throw $e;
		} catch (AccessDeniedException) {
			$this->discard($key);

			throw $this->forbidden($collection);
		} catch (\Throwable $e) {
			// Validation of another field, a full disk: whatever stopped the
			// save, the file has no pointer and must not stay in the bucket.
			$this->discard($key);

			throw new UploadError('s3.save_failed', 422, 'The file uploaded, but the object could not be saved, so the upload was removed. ' . $e->getMessage());
		}
	}

	/**
	 * Write the pointer to the object. Runs under the object's lock.
	 *
	 * @param array{field:string, settings:array<string,mixed>} $field
	 *
	 * @return array{value:mixed, item:array<string,mixed>}
	 *
	 * @throws UploadError when the object is gone, the rules refuse the file, or the save does not keep the pointer
	 */
	private function save(string $collection, string $id, string $property, array $field, Pointer $pointer): array
	{
		try {
			$current = $this->currentPointers($collection, $id, $property, $field['field']);
		} catch (\UnexpectedValueException) {
			throw new UploadError('s3.no_object', 404, 'Save the object before uploading.');
		}

		// Another request committed this token while this one waited for the lock.
		$already = $this->describe($current, $field['field'], $pointer->key);
		if ($already !== null) {
			return $already;
		}

		$depot = $field['field'] === S3Fields::DEPOT;
		$this->enforceRules($field, $pointer->name, $pointer->size, $pointer->mime, $depot ? count($current) : 0);

		$item  = $pointer->toArray();
		$value = $depot ? [...array_map(fn (Pointer $p): array => $p->toArray(), $current), $item] : $item;
		$saved = $this->patcher->patchObject($collection, $id, [$property => $value])->toArray();

		// The save can keep the old value of a field this user may not change.
		// Then nothing points at the file, whatever the form would show.
		if ($this->describe(S3Fields::pointers($saved[$property] ?? null, $field['field']), $field['field'], $pointer->key) === null) {
			throw $this->forbidden($collection);
		}

		return ['value' => $value, 'item' => $item];
	}

	/**
	 * What commit() answers when the object already holds this key; null when it does not.
	 *
	 * @return array{value:mixed, item:array<string,mixed>}|null
	 */
	private function stored(string $collection, string $id, string $property, string $field, string $key): ?array
	{
		try {
			return $this->describe($this->currentPointers($collection, $id, $property, $field), $field, $key);
		} catch (\UnexpectedValueException) {
			return null;
		}
	}

	/**
	 * @param list<Pointer> $pointers the property's current pointers
	 *
	 * @return array{value:mixed, item:array<string,mixed>}|null
	 */
	private function describe(array $pointers, string $field, string $key): ?array
	{
		foreach ($pointers as $pointer) {
			if ($pointer->key === $key) {
				$item = $pointer->toArray();

				return [
					'value' => $field === S3Fields::DEPOT ? array_map(fn (Pointer $p): array => $p->toArray(), $pointers) : $item,
					'item'  => $item,
				];
			}
		}

		return null;
	}

	private function forbidden(string $collection): UploadError
	{
		return new UploadError('s3.forbidden', 403, "You do not have permission to upload files to {$collection}.");
	}

	/**
	 * @param array{field:string, settings:array<string,mixed>} $field
	 *
	 * @throws UploadError
	 */
	private function enforceRules(array $field, string $name, int $size, string $mime, int $existingCount): void
	{
		$rules   = is_array($field['settings']['rules'] ?? null) ? $field['settings']['rules'] : [];
		$refusal = UploadRules::check($rules, $name, $size, $mime, $existingCount, $field['field']);
		if ($refusal !== null) {
			throw new UploadError($refusal['code'], 422, $refusal['message']);
		}
	}

	/**
	 * The property's valid pointers as they are on disk right now.
	 *
	 * @return list<Pointer>
	 *
	 * @throws \UnexpectedValueException when the object is gone
	 */
	private function currentPointers(string $collection, string $id, string $property, string $field): array
	{
		$stored = $this->objects->fetchObjectFromDisk($collection, $id)->toArray();

		return S3Fields::pointers($stored[$property] ?? null, $field);
	}

	/** @param array{field:string, settings:array<string,mixed>} $field */
	private function folder(array $field): ?string
	{
		$folder = $field['settings']['folder'] ?? null;

		return is_string($folder) && trim($folder) !== '' ? $folder : null;
	}

	private function duration(?float $duration, string $mime): ?float
	{
		$isMedia = str_starts_with($mime, 'audio/') || str_starts_with($mime, 'video/');

		return $isMedia && $duration !== null && $duration > 0 && $duration < self::MAX_DURATION ? $duration : null;
	}

	/** Remove a key that will never get a pointer. Best effort: prune catches what this misses. */
	private function discard(string $key): void
	{
		try {
			$this->client->deleteObject($key);
		} catch (S3Exception) {
			// The refusal the caller is about to report matters more.
		}
	}
}
