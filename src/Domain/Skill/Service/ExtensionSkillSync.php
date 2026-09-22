<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Skill\Service;

use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\Extension\Service\ExtensionDiscovery;

/**
 * Keeps `.claude/skills/` in step with the enabled extensions that ship an
 * agent skill.
 *
 * An extension may carry a `skill/` directory next to its manifest — a
 * `SKILL.md` plus optional references, the layout the core skill uses. While
 * the extension is enabled, that directory is installed to
 * `.claude/skills/{vendor}-{name}/` by the same {@see SkillInstaller} that
 * installs the core skill: same copy, same zip-layout path rewrite, same
 * fingerprint stamp, so `skill:install --check` reports a stale extension
 * skill exactly as it reports a stale core one, and the Composer plugin's
 * post-update `skill:install` refreshes both. Claude Code picks skills by
 * their description line, so each extension's is a folder of its own and
 * the core skill's fingerprint is never disturbed.
 *
 * A separate folder per extension is also what makes removal safe. The
 * stamp names the owning extension; a sync sweeps every stamped folder whose
 * extension is no longer enabled — disabled, removed, or gone from disk —
 * and never touches a folder without that stamp, which is the operator's
 * own skill or the core one.
 *
 * A skill is instructions to the agent, so shipping one is a prompt-injection
 * surface. The gates are the extension system's own: only an enabled
 * extension is installed, and enabling goes through the pre-enable review,
 * which shows the skill text next to the source-code findings.
 */
final readonly class ExtensionSkillSync
{
	/** The directory inside an extension that holds its skill. */
	public const SKILL_DIR = 'skill';

	public function __construct(
		private ExtensionDiscovery $discovery,
		private ExtensionStateRepository $states,
		private SkillInstaller $installer,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The skill folder name for an extension id: `acme/thing` → `acme-thing`.
	 */
	public static function folderName(string $extensionId): string
	{
		return str_replace('/', '-', $extensionId);
	}

	/**
	 * The extension's skill source directory, or null when it ships none.
	 */
	public function sourceFor(string $extensionId): ?string
	{
		$extPath = $this->discovery->getExtensionPath($extensionId);
		if ($extPath === null) {
			return null;
		}

		$source = $extPath . '/' . self::SKILL_DIR;

		return is_file($source . '/SKILL.md') ? $source : null;
	}

	/**
	 * Install every enabled extension's skill and remove every stamped folder
	 * whose extension is no longer enabled.
	 *
	 * @param string $skillsRoot      The project's `.claude/skills` directory
	 * @param bool   $composerInstall False rewrites Composer paths for the zip layout
	 *
	 * @return array{installed: list<array{id: string, target: string, hash: string, copied: int, failed: list<string>}>, removed: list<array{id: string, target: string}>, skipped: list<array{id: string, target: string, reason: string}>}
	 */
	public function sync(string $skillsRoot, bool $composerInstall): array
	{
		$skillsRoot = rtrim($skillsRoot, '/');
		$result     = ['installed' => [], 'removed' => [], 'skipped' => []];

		$wanted = $this->enabledWithSkill();

		if ($wanted !== [] && !is_dir($skillsRoot) && !@mkdir($skillsRoot, 0755, true) && !is_dir($skillsRoot)) {
			$this->logger->warning("Cannot create {$skillsRoot}; extension skills not installed.");

			return $result;
		}

		foreach ($wanted as $id => $source) {
			$target = $skillsRoot . '/' . self::folderName($id);

			// A folder we did not write — the operator's own skill of the same
			// name — is not ours to overwrite.
			if (is_dir($target) && $this->ownerOf($target) !== $id) {
				$this->logger->warning("Skill folder {$target} exists and was not installed for '{$id}'; left untouched.");
				$result['skipped'][] = ['id' => $id, 'target' => $target, 'reason' => 'exists'];

				continue;
			}

			$install = $this->installer->install($source, $target, true, $composerInstall, ['extension' => $id]);
			if ($install['failed'] !== []) {
				$this->logger->warning("Skill for '{$id}': " . count($install['failed']) . ' file(s) failed to copy into ' . $target);
			}

			$result['installed'][] = [
				'id'     => $id,
				'target' => $target,
				'hash'   => $install['hash'],
				'copied' => count($install['copied']),
				'failed' => $install['failed'],
			];
		}

		foreach ($this->stampedFolders($skillsRoot) as $target => $owner) {
			if (isset($wanted[$owner]) && $target === $skillsRoot . '/' . self::folderName($owner)) {
				continue;
			}

			$this->removeDirectory($target);
			$result['removed'][] = ['id' => $owner, 'target' => $target];
		}

		return $result;
	}

	/**
	 * Freshness of every enabled extension's installed skill, for `--check`.
	 *
	 * @return list<array{id: string, target: string, installed: bool, current: bool, hash: string, changed: list<string>, added: list<string>, removed: list<string>}>
	 */
	public function check(string $skillsRoot): array
	{
		$skillsRoot = rtrim($skillsRoot, '/');
		$checks     = [];

		foreach ($this->enabledWithSkill() as $id => $source) {
			$target = $skillsRoot . '/' . self::folderName($id);
			$check  = $this->installer->check($source, $target);

			$checks[] = [
				'id'        => $id,
				'target'    => $target,
				'installed' => $check['installed'] && $this->ownerOf($target) === $id,
				'current'   => $check['current'] && $this->ownerOf($target) === $id,
				'hash'      => $check['hash'],
				'changed'   => $check['changed'],
				'added'     => $check['added'],
				'removed'   => $check['removed'],
			];
		}

		return $checks;
	}

	/**
	 * @return array<string,string> extension id => skill source directory
	 */
	private function enabledWithSkill(): array
	{
		$wanted = [];

		foreach ($this->discovery->discover() as $id => $manifest) {
			if (!$this->states->isEnabled($id, $manifest)) {
				continue;
			}

			$source = $this->sourceFor($id);
			if ($source !== null) {
				$wanted[$id] = $source;
			}
		}

		return $wanted;
	}

	/**
	 * Every skill folder this sync wrote, by its stamp.
	 *
	 * @return array<string,string> folder path => owning extension id
	 */
	private function stampedFolders(string $skillsRoot): array
	{
		if (!is_dir($skillsRoot)) {
			return [];
		}

		$folders = [];
		foreach (scandir($skillsRoot) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			$path  = $skillsRoot . '/' . $entry;
			$owner = is_dir($path) ? $this->ownerOf($path) : null;
			if ($owner !== null) {
				$folders[$path] = $owner;
			}
		}

		return $folders;
	}

	/**
	 * The extension id stamped into a skill folder's sidecar manifest, or
	 * null when the folder is not one this sync wrote.
	 */
	private function ownerOf(string $target): ?string
	{
		$manifest = $target . '/' . SkillInstaller::MANIFEST;
		if (!is_file($manifest)) {
			return null;
		}

		$decoded = json_decode((string)@file_get_contents($manifest), true);
		$owner   = is_array($decoded) ? ($decoded['extension'] ?? null) : null;

		return is_string($owner) && $owner !== '' ? $owner : null;
	}

	private function removeDirectory(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}
		foreach (scandir($dir) ?: [] as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$path = $dir . '/' . $item;
			is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
		}
		@rmdir($dir);
	}
}
