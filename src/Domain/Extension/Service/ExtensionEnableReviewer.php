<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Extension\Service;

use TotalCMS\Domain\Extension\Data\ExtensionManifest;
use TotalCMS\Domain\Skill\Service\ExtensionSkillSync;

/**
 * What the operator sees before consenting to enable an extension: the
 * capabilities it registers, the risky ones called out in plain language,
 * the source-scan findings, the author's review note, and the agent skill
 * it would install.
 */
final class ExtensionEnableReviewer
{
	/**
	 * Capabilities that expose public surface or reach sensitive data, each
	 * with the FYI shown on the review screen. Order here is display order.
	 * `container` is deliberately absent: it is always-on infrastructure and
	 * extensions can only register their own services — core ids are
	 * strict-denied at apply time — so there is nothing risky to disclose.
	 *
	 * @var array<string,string>
	 */
	public const RISKY_CAPABILITIES = [
		'routes:public' => 'Exposes public, unauthenticated endpoints.',
		'events:listen' => 'Can observe all content changes.',
		'automations'   => 'Runs server-side code automatically on a schedule or content events.',
		'mcp:tools'     => 'Registers actions AI agents can call (reachable externally if MCP public access is enabled).',
		'mcp:resources' => 'Exposes data that AI agents can fetch.',
	];

	/**
	 * @param array<string,bool> $capabilities What the extension registers, as detected
	 *
	 * @return array{capabilities: array<string,bool>, findings: list<mixed>, reviewNote: string, risky: array<string,string>, hasFlags: bool, skill: array{target: string, contents: string, files: list<string>}|null}
	 */
	public function review(string $extensionId, ExtensionManifest $manifest, array $capabilities, ?string $extPath): array
	{
		// Bundled extensions are exempt from the source scan — they version
		// with core and ship reviewed in the package (same rationale as the
		// update re-consent gate). Capability FYIs still show; only the
		// pattern findings are skipped.
		$findings = ($extPath !== null && !$manifest->bundled)
			? (new DangerousCodeScanner())->scan($extPath)
			: [];

		$risky = [];
		foreach (self::RISKY_CAPABILITIES as $cap => $label) {
			if (($capabilities[$cap] ?? false) === true) {
				$risky[$cap] = $label;
			}
		}

		return [
			'capabilities' => $capabilities,
			'findings'     => $findings,
			'reviewNote'   => $manifest->reviewNote,
			'risky'        => $risky,
			'hasFlags'     => $risky !== [] || $findings !== [],
			'skill'        => $this->skillReview($extensionId, $extPath),
		];
	}

	/**
	 * The agent skill an extension ships. A skill is instructions to the
	 * agent, installed into the project's `.claude/skills/` while the
	 * extension is enabled — so the operator reads it before consenting, the
	 * way they read the source findings. SKILL.md is listed first: it is the
	 * file the agent loads.
	 *
	 * @return array{target: string, contents: string, files: list<string>}|null
	 */
	private function skillReview(string $extensionId, ?string $extPath): ?array
	{
		$source = $extPath !== null ? $extPath . '/' . ExtensionSkillSync::SKILL_DIR : null;
		if ($source === null || !is_file($source . '/SKILL.md')) {
			return null;
		}

		$files    = [];
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
		foreach ($iterator as $item) {
			if ($item instanceof \SplFileInfo && $item->isFile()) {
				$files[] = str_replace(DIRECTORY_SEPARATOR, '/', ltrim(substr($item->getPathname(), strlen($source)), DIRECTORY_SEPARATOR));
			}
		}
		sort($files);
		$files = array_values(array_unique(array_merge(['SKILL.md'], $files)));

		return [
			'target'   => '.claude/skills/' . ExtensionSkillSync::folderName($extensionId) . '/',
			'contents' => (string)@file_get_contents($source . '/SKILL.md'),
			'files'    => $files,
		];
	}
}
