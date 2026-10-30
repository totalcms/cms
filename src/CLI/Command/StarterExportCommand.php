<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use TotalCMS\Domain\Settings\Services\SettingsSchemaFetcher;
use TotalCMS\Domain\Starter\Data\StarterExportOptions;
use TotalCMS\Domain\Starter\Data\StarterExportResult;
use TotalCMS\Domain\Starter\Service\StarterExporter;
use TotalCMS\Domain\Starter\Service\StarterProjectWriter;
use TotalCMS\Domain\Starter\Service\StarterSecretScrubber;
use TotalCMS\Support\Config;
use TotalCMS\Support\PathResolver;
use TotalCMS\Support\Version;

class StarterExportCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('starter:export')
			->setDescription('Write a clean copy of this site as a Composer starter project')
			->addArgument('dir', InputArgument::REQUIRED, 'Folder to write the starter to (outside this project)')
			->addOption('name', null, InputOption::VALUE_REQUIRED, 'Composer package name, e.g. totalcms/starter-restaurant')
			->addOption('description', null, InputOption::VALUE_REQUIRED, 'Composer package description', '')
			->addOption('structure-only', null, InputOption::VALUE_NONE, 'Leave out every object except the Site Builder pages')
			->addOption('yes', 'y', InputOption::VALUE_NONE, 'Include the content without asking')
			->addOption('force', 'f', InputOption::VALUE_NONE, 'Write into a folder that is not empty');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$dir     = (string)$input->getArgument('dir');
		$name    = (string)($input->getOption('name') ?? '');
		$name    = $name !== '' ? $name : 'totalcms/starter-' . basename(rtrim($dir, '/'));
		$datadir = (string)realpath($this->totalcms->container()->get(Config::class)->datadir);

		$exporter = self::exporter();
		$options  = new StarterExportOptions(
			destination: $dir,
			name: $name,
			description: (string)$input->getOption('description'),
			includeContent: $this->includeContent($input, $output, $exporter, $datadir),
			force: (bool)$input->getOption('force'),
		);

		try {
			$result = $exporter->export($options, (string)realpath(PathResolver::projectRoot()), $datadir);
		} catch (\InvalidArgumentException|\RuntimeException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return Command::FAILURE;
		}

		if ($this->isJson($input)) {
			$output->writeln((string)json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		} else {
			$this->report($output, $result, $name);
		}

		return $result->passed() ? Command::SUCCESS : Command::FAILURE;
	}

	public static function exporter(): StarterExporter
	{
		$packageRoot = PathResolver::packageRoot();

		return new StarterExporter(
			new StarterSecretScrubber(new SettingsSchemaFetcher(), $packageRoot . '/resources/extensions'),
			new StarterProjectWriter($packageRoot . '/resources/starter', Version::number()),
		);
	}

	/**
	 * Content travels unless the author says otherwise. Asked rather than
	 * assumed, because a site built for one client is the usual source and
	 * its content is that client's.
	 */
	private function includeContent(InputInterface $input, OutputInterface $output, StarterExporter $exporter, string $datadir): bool
	{
		if ($input->getOption('structure-only')) {
			return false;
		}
		if ($input->getOption('yes') || $this->isJson($input) || !$this->canPrompt($input)) {
			return true;
		}

		$summary = $exporter->contentSummary($datadir);
		if ($summary === []) {
			return true;
		}

		$question = sprintf(
			'This site has %d objects across %d collections (%s). Include them in the starter? [Y/n] ',
			array_sum($summary),
			count($summary),
			implode(', ', array_keys($summary)),
		);

		/** @var QuestionHelper $helper */
		$helper = $this->getHelper('question');

		return (bool)$helper->ask($input, $output, new ConfirmationQuestion($question, true));
	}

	private function report(OutputInterface $output, StarterExportResult $result, string $name): void
	{
		$output->writeln(sprintf('<info>Exported %s</info> to %s (%d files, %s)', $name, $result->destination, $result->files, self::size($result->bytes)));

		$lists = [
			'Left out the objects of'                                   => $result->emptiedCollections,
			'Blanked settings'                                          => $result->scrubbedSettings,
			'Replaced config/tcms.php. Move what the starter needs from' => $result->configKeys,
		];
		foreach ($lists as $label => $items) {
			if ($items !== []) {
				$output->writeln('');
				$output->writeln("{$label}: <comment>" . implode(', ', $items) . '</comment>');
			}
		}

		$output->writeln('');
		$output->writeln(sprintf('CREDITS.md lists %d credited image(s).', $result->creditedImages));
		if ($result->uncreditedImages !== []) {
			$output->writeln('<comment>No credit on these images (add one in the image\'s Credit section, then export again):</comment>');
			foreach ($result->uncreditedImages as $image) {
				$output->writeln("  {$image}");
			}
		}

		$output->writeln('');
		$output->writeln('Largest files (resize images in the site before exporting):');
		foreach ($result->largestFiles as $file => $bytes) {
			$output->writeln(sprintf('  %8s  %s', self::size($bytes), $file));
		}

		if (!$result->passed()) {
			$output->writeln('');
			$output->writeln('<error>A secret from this site is in the output. Do not publish it.</error>');
			foreach ($result->secretHits as $file) {
				$output->writeln("  {$file}");
			}
		}
	}

	private static function size(int $bytes): string
	{
		return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, (int)round($bytes / 1024)) . ' KB';
	}
}
