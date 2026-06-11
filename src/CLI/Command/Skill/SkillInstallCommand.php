<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command\Skill;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Command\BaseCommand;
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
 */
class SkillInstallCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('skill:install')
			->setDescription('Install or update the Total CMS agent skill into .claude/skills/totalcms');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$source = PathResolver::packageRoot() . '/resources/skill';
		$target = PathResolver::projectRoot() . '/.claude/skills/totalcms';

		$result = (new SkillInstaller())->install($source, $target, true);

		return $this->outputData($input, $output, $result);
	}

	/**
	 * @param array{installed: bool, source: string, target: string, copied: list<string>, failed: list<string>} $data
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
		$output->writeln('');
	}
}
