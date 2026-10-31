<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Service;

use Twig\Cache\FilesystemCache;

/**
 * Twig's filesystem cache, with string templates kept apart and pruned.
 *
 * A Stacks page is rendered from its whole output buffer as a string template
 * ({@see TwigEngine::renderString()}), and Twig compiles each distinct string
 * to its own file. A page holding a per-request value — a form's hidden date
 * or IP field, a token — is a new string on every visit, so the cache grew by
 * one compiled page per request, never deleted (18 GB on one site).
 *
 * String templates (and `template_from_string()`) now go in their own folder.
 * A copy in use has its timestamp refreshed at most every REFRESH seconds, and
 * copies unused for KEEP seconds are deleted, at most once per PRUNE_EVERY.
 * A page that does not change keeps its copy; one-off copies go a day later.
 * File templates are cached as before.
 */
final class TwigTemplateCache extends FilesystemCache
{
	public const STRING_DIR = 'twig-strings';

	/** Delete string templates not used for this long. */
	public const KEEP = 86400;

	/** Refresh a used copy's timestamp when it is older than this. */
	public const REFRESH = 43200;

	/** Prune at most this often. */
	public const PRUNE_EVERY = 3600;

	private readonly string $stringDir;

	public function __construct(string $directory, int $options = 0)
	{
		parent::__construct($directory, $options);
		$this->stringDir = rtrim($directory, '\/') . '/' . self::STRING_DIR;
	}

	public function generateKey(string $name, string $className): string
	{
		if (!self::isStringTemplate($name)) {
			return parent::generateKey($name, $className);
		}

		$hash = hash('xxh128', $className); // as Twig's own key, on PHP 8.1+

		return "{$this->stringDir}/{$hash[0]}{$hash[1]}/{$hash}.php";
	}

	public function load(string $key): void
	{
		parent::load($key);

		if (!$this->isStringKey($key)) {
			return;
		}
		$modified = @filemtime($key);
		if ($modified !== false && $modified < time() - self::REFRESH) {
			@touch($key);
		}
	}

	public function write(string $key, string $content): void
	{
		parent::write($key, $content);

		if ($this->isStringKey($key)) {
			$this->pruneIfDue();
		}
	}

	/**
	 * Delete string templates not used for KEEP seconds.
	 *
	 * @return int the number of files deleted
	 */
	public function prune(): int
	{
		$cutoff  = time() - self::KEEP;
		$deleted = 0;
		foreach (glob($this->stringDir . '/*/*.php') ?: [] as $file) {
			$modified = @filemtime($file);
			if ($modified !== false && $modified < $cutoff && @unlink($file)) {
				$deleted++;
			}
		}

		return $deleted;
	}

	/** Twig names a string template `__string_template__{hash}`, or `{name} (string template {hash})`. */
	public static function isStringTemplate(string $name): bool
	{
		return str_starts_with($name, '__string_template__') || str_contains($name, ' (string template ');
	}

	private function isStringKey(string $key): bool
	{
		return str_starts_with($key, $this->stringDir . '/');
	}

	/** A marker file's timestamp records the last prune, so it runs at most once per PRUNE_EVERY. */
	private function pruneIfDue(): void
	{
		$marker = $this->stringDir . '/.pruned';
		$last   = @filemtime($marker);
		if ($last !== false && $last > time() - self::PRUNE_EVERY) {
			return;
		}

		@touch($marker);
		$this->prune();
	}
}
