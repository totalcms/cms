<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command\Skill;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Command\BaseCommand;
use TotalCMS\Domain\Skill\Service\ExtensionSkillSync;
use TotalCMS\Domain\Skill\Service\SkillInstaller;
use TotalCMS\Support\PathResolver;

/**
 * Installs or refreshes the bundled agent skill into the project's
 * `.claude/skills/totalcms/` directory.
 *
 * Source of truth ships in the package at `resources/skill/`; this copies it into
 * the project root so Claude Code (and other agents reading the files directly)
 * pick it up. Run automatically by the project skeleton's Composer hooks, and
 * available manually as `tcms skill:install`.
 *
 * The shipped source describes the Composer layout. On a zip install the copy is
 * rewritten as it lands (`vendor/bin/tcms` becomes `php resources/bin/tcms`,
 * `vendor/totalcms/cms/` drops away) so the skill's paths match the site.
 */
class SkillInstallCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('skill:install')
			->setDescription('Install or update the Total CMS agent skill into .claude/skills/totalcms')
			->addOption(
				'check',
				null,
				InputOption::VALUE_NONE,
				'Report whether the installed skill matches the shipped source (exit 1 when stale)',
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$source = PathResolver::packageRoot() . '/resources/skill';
		$target = PathResolver::projectRoot() . '/.claude/skills/totalcms';

		if ((bool)$input->getOption('check')) {
			return $this->runCheck($input, $output, $source, $target);
		}

		$result = (new SkillInstaller())->install($source, $target, true, PathResolver::isComposerInstall());

		// Enabled extensions that ship a skill land beside the core one, in
		// their own folders; disabled or removed ones are swept.
		$result['extensions'] = $this->sync()->sync(dirname($target), PathResolver::isComposerInstall());

		return $this->outputData($input, $output, $result);
	}

	private function sync(): ExtensionSkillSync
	{
		return $this->totalcms->container()->get(ExtensionSkillSync::class);
	}

	/**
	 * Compare the installed copy against the shipped source without writing anything.
	 */
	private function runCheck(InputInterface $input, OutputInterface $output, string $source, string $target): int
	{
		$check               = (new SkillInstaller())->check($source, $target);
		$extensions          = $this->sync()->check(dirname($target));
		$check['extensions'] = $extensions;
		$staleExtensions     = array_values(array_filter($extensions, static fn (array $e): bool => !$e['current']));

		if ($this->isJson($input)) {
			$output->writeln((string)json_encode($check, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

			return $check['current'] && $staleExtensions === [] ? Command::SUCCESS : Command::FAILURE;
		}

		$output->writeln('');

		// Extension skills first, one line each, whatever the core skill's state.
		foreach ($extensions as $ext) {
			$output->writeln($ext['current']
				? sprintf('<info>%s skill is current</info> (hash %s)', $ext['id'], $this->shortHash($ext['hash']))
				: sprintf('<comment>%s skill is %s</comment>', $ext['id'], $ext['installed'] ? 'stale' : 'not installed'));
		}

		if ($check['current']) {
			$output->writeln(sprintf(
				'<info>Agent skill is current</info> (hash %s, installed for %s)',
				$this->shortHash($check['hash']),
				$check['installedFor'] ?? 'an unknown version',
			));
			$output->writeln('');

			if ($staleExtensions !== []) {
				$output->writeln(sprintf('  Run `%s skill:install` and start a new agent session so the fresh copy loads.', $this->cli()));
				$output->writeln('');

				return Command::FAILURE;
			}

			return Command::SUCCESS;
		}

		if (!$check['installed']) {
			$output->writeln('<comment>Agent skill is not installed.</comment>');
		} else {
			$differences = array_merge($check['changed'], $check['added'], $check['removed']);
			$output->writeln(sprintf(
				'<comment>Agent skill is stale</comment> — %d file(s) differ: %s',
				count($differences),
				$differences === [] ? 'content hash changed' : implode(', ', $differences),
			));
		}

		$output->writeln(sprintf('  Run `%s skill:install` and start a new agent session so the fresh copy loads.', $this->cli()));
		$output->writeln('');

		return Command::FAILURE;
	}

	private function cli(): string
	{
		return PathResolver::isComposerInstall() ? 'vendor/bin/tcms' : 'php resources/bin/tcms';
	}

	private function shortHash(string $hash): string
	{
		return $hash === '' ? 'unknown' : substr($hash, 0, 8) . '…';
	}

	/**
	 * @param array{installed: bool, source: string, target: string, copied: list<string>, failed: list<string>, hash: string, extensions?: array{installed: list<array{id: string, target: string, hash: string, copied: int, failed: list<string>}>, removed: list<array{id: string, target: string}>, skipped: list<array{id: string, target: string, reason: string}>}} $data
	 */
	protected function renderHuman(InputInterface $input, OutputInterface $output, array $data): void
	{
		$output->writeln('');

		if (!$data['installed']) {
			$output->writeln('<comment>Skill source not found at ' . $data['source'] . ' — nothing to install.</comment>');
			$output->writeln('');

			return;
		}

		$output->writeln(sprintf('<info>Agent skill installed to %s</info>', $data['target']));
		$failed = count($data['failed']);
		$output->writeln(sprintf(
			'  %d file(s) written%s',
			count($data['copied']),
			$failed > 0 ? sprintf(', <error>%d failed</error>', $failed) : '',
		));
		$output->writeln(PathResolver::isComposerInstall()
			? '  Paths written for the Composer layout (CLI: vendor/bin/tcms)'
			: '  Paths rewritten for the zip layout (CLI: php resources/bin/tcms)');
		$output->writeln(sprintf('  Content hash %s — check freshness with `%s skill:install --check`', $this->shortHash($data['hash']), $this->cli()));

		$extensions = $data['extensions'] ?? ['installed' => [], 'removed' => [], 'skipped' => []];
		foreach ($extensions['installed'] as $ext) {
			$failed = count($ext['failed']);
			$output->writeln(sprintf(
				'<info>%s skill installed to %s</info> (%d file(s)%s)',
				$ext['id'],
				$ext['target'],
				$ext['copied'],
				$failed > 0 ? sprintf(', <error>%d failed</error>', $failed) : '',
			));
		}
		foreach ($extensions['removed'] as $ext) {
			$output->writeln(sprintf('<comment>%s skill removed from %s</comment> (extension no longer enabled)', $ext['id'], $ext['target']));
		}
		foreach ($extensions['skipped'] as $ext) {
			$output->writeln(sprintf('<comment>%s skill skipped:</comment> %s exists and was not installed by Total CMS', $ext['id'], $ext['target']));
		}
		$output->writeln('');
	}
}
