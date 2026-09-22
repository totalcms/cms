<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Skill;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Extension\Data\ExtensionState;
use TotalCMS\Domain\Extension\Repository\ExtensionStateRepository;
use TotalCMS\Domain\Extension\Service\ExtensionDiscovery;
use TotalCMS\Domain\Extension\Service\ManifestValidator;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Skill\Service\ExtensionSkillSync;
use TotalCMS\Domain\Skill\Service\SkillInstaller;
use TotalCMS\Domain\Storage\StorageFilesystemAdapter;
use TotalCMS\Support\Config;

/**
 * An extension may ship a `skill/` directory next to its manifest. While the
 * extension is enabled its skill is installed into `.claude/skills/{vendor}-{name}/`
 * on the same rail as the core skill (same installer, same fingerprint stamp,
 * refreshed by the Composer plugin on every update); when it is disabled or
 * removed, the folder goes. Folders the sync did not write are never touched.
 */
final class ExtensionSkillSyncTest extends TestCase
{
	private string $tmp;
	private string $extensionsDir;
	private string $skillsRoot;
	private ExtensionStateRepository $states;
	private ExtensionSkillSync $sync;

	protected function setUp(): void
	{
		$this->tmp           = sys_get_temp_dir() . '/tcms-extskill-' . uniqid();
		$this->extensionsDir = $this->tmp . '/tcms-data/extensions';
		$this->skillsRoot    = $this->tmp . '/.claude/skills';
		mkdir($this->extensionsDir, 0755, true);
		mkdir($this->tmp . '/none', 0755, true);

		$config          = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
		$config->datadir = $this->tmp . '/tcms-data';

		$storage = $this->createMock(StorageFilesystemAdapter::class);
		$storage->method('fileExists')->willReturn(false);
		$this->states = new ExtensionStateRepository($storage);

		$discovery = new ExtensionDiscovery(
			$config,
			new ManifestValidator($this->createMock(EditionFeatureService::class)),
			new NullLogger(),
			$this->tmp . '/none/project',
			$this->tmp . '/none/bundled',
			fn (): array => [],
		);

		$this->sync = new ExtensionSkillSync($discovery, $this->states, new SkillInstaller(), new NullLogger());
	}

	protected function tearDown(): void
	{
		$this->rrmdir($this->tmp);
	}

	public function testInstallsAnEnabledExtensionsSkillUnderVendorDashName(): void
	{
		$this->extension('acme/thing', withSkill: true, enabled: true);

		$result = $this->sync->sync($this->skillsRoot, composerInstall: true);

		$target = $this->skillsRoot . '/acme-thing';
		$this->assertSame(['acme/thing'], array_column($result['installed'], 'id'));
		$this->assertFileExists($target . '/SKILL.md');
		$this->assertFileExists($target . '/references/notes.md');
		// Stamped like the core skill, plus the owning extension so a later
		// sync can tell this folder is ours to remove.
		$stamp = json_decode((string)file_get_contents($target . '/' . SkillInstaller::MANIFEST), true);
		$this->assertSame('acme/thing', $stamp['extension']);
		$this->assertNotSame('', $stamp['hash']);
	}

	public function testDisabledExtensionGetsNoSkillAndAStaleOneIsRemoved(): void
	{
		$this->extension('acme/thing', withSkill: true, enabled: true);
		$this->sync->sync($this->skillsRoot, composerInstall: true);
		$this->assertDirectoryExists($this->skillsRoot . '/acme-thing');

		$this->states->saveState('acme/thing', new ExtensionState(enabled: false));
		$result = $this->sync->sync($this->skillsRoot, composerInstall: true);

		$this->assertSame(['acme/thing'], array_column($result['removed'], 'id'));
		$this->assertDirectoryDoesNotExist($this->skillsRoot . '/acme-thing');
	}

	public function testRemovedExtensionsSkillIsSweptEvenWithNoManifestLeft(): void
	{
		$this->extension('acme/thing', withSkill: true, enabled: true);
		$this->sync->sync($this->skillsRoot, composerInstall: true);

		$this->rrmdir($this->extensionsDir . '/acme/thing');
		$this->states->removeState('acme/thing');
		$this->sync->sync($this->skillsRoot, composerInstall: true);

		$this->assertDirectoryDoesNotExist($this->skillsRoot . '/acme-thing');
	}

	public function testExtensionWithoutASkillDirectoryIsIgnored(): void
	{
		$this->extension('acme/plain', withSkill: false, enabled: true);

		$result = $this->sync->sync($this->skillsRoot, composerInstall: true);

		$this->assertSame([], $result['installed']);
		$this->assertDirectoryDoesNotExist($this->skillsRoot . '/acme-plain');
	}

	public function testNeverTouchesAFolderItDidNotWrite(): void
	{
		// The operator's own skill happens to share the name. No stamp naming
		// an extension means it is not ours: leave it, say so.
		mkdir($this->skillsRoot . '/acme-thing', 0755, true);
		file_put_contents($this->skillsRoot . '/acme-thing/SKILL.md', 'mine');
		$this->extension('acme/thing', withSkill: true, enabled: true);

		$result = $this->sync->sync($this->skillsRoot, composerInstall: true);

		$this->assertSame('mine', file_get_contents($this->skillsRoot . '/acme-thing/SKILL.md'));
		$this->assertSame(['acme/thing'], array_column($result['skipped'], 'id'));

		// And the core skill, which carries a stamp but no extension, is not swept.
		mkdir($this->skillsRoot . '/totalcms', 0755, true);
		file_put_contents($this->skillsRoot . '/totalcms/' . SkillInstaller::MANIFEST, '{"hash":"abc","files":{}}');
		$this->sync->sync($this->skillsRoot, composerInstall: true);
		$this->assertDirectoryExists($this->skillsRoot . '/totalcms');
	}

	public function testRewritesComposerPathsForAZipInstall(): void
	{
		$this->extension('acme/thing', withSkill: true, enabled: true);

		$this->sync->sync($this->skillsRoot, composerInstall: false);

		$this->assertStringContainsString('php resources/bin/tcms thing:sync', (string)file_get_contents($this->skillsRoot . '/acme-thing/SKILL.md'));
	}

	public function testCheckReportsEachEnabledSkillsFreshness(): void
	{
		$this->extension('acme/thing', withSkill: true, enabled: true);
		$this->assertSame([['id' => 'acme/thing', 'installed' => false, 'current' => false]], $this->summary($this->sync->check($this->skillsRoot)));

		$this->sync->sync($this->skillsRoot, composerInstall: true);
		$this->assertSame([['id' => 'acme/thing', 'installed' => true, 'current' => true]], $this->summary($this->sync->check($this->skillsRoot)));

		file_put_contents($this->extensionsDir . '/acme/thing/skill/SKILL.md', "---\nname: thing\ndescription: changed\n---\n");
		$this->assertSame([['id' => 'acme/thing', 'installed' => true, 'current' => false]], $this->summary($this->sync->check($this->skillsRoot)));
	}

	/**
	 * @param list<array<string,mixed>> $checks
	 *
	 * @return list<array{id:string,installed:bool,current:bool}>
	 */
	private function summary(array $checks): array
	{
		return array_map(static fn (array $c): array => ['id' => $c['id'], 'installed' => $c['installed'], 'current' => $c['current']], $checks);
	}

	private function extension(string $id, bool $withSkill, bool $enabled): void
	{
		[$vendor, $name] = explode('/', $id);
		$dir             = $this->extensionsDir . '/' . $vendor . '/' . $name;
		mkdir($dir, 0755, true);
		file_put_contents($dir . '/extension.json', (string)json_encode([
			'id'          => $id,
			'name'        => $name,
			'description' => 'fixture',
			'version'     => '1.0.0',
			'requires'    => ['totalcms' => '>=3.0.0', 'php' => '>=8.2'],
			'entrypoint'  => 'Extension.php',
			'license'     => 'MIT',
		]));
		if ($withSkill) {
			mkdir($dir . '/skill/references', 0755, true);
			file_put_contents($dir . '/skill/SKILL.md', "---\nname: {$name}\ndescription: fixture skill\n---\nRun `vendor/bin/tcms {$name}:sync`.\n");
			file_put_contents($dir . '/skill/references/notes.md', 'notes');
		}
		$this->states->saveState($id, new ExtensionState(enabled: $enabled));
	}

	private function rrmdir(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}
		foreach (scandir($dir) ?: [] as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$path = $dir . '/' . $item;
			is_dir($path) ? $this->rrmdir($path) : unlink($path);
		}
		rmdir($dir);
	}
}
