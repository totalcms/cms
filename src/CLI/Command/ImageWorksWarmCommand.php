<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\Domain\ImageWorks\Data\WarmReport;
use TotalCMS\Domain\ImageWorks\Data\WarmVariants;
use TotalCMS\Domain\ImageWorks\Service\ImageWorksWarmer;
use TotalCMS\Domain\License\Service\EditionFeatureService;

/**
 * Pre-build the ImageWorks cache for a collection so the first visitor to a
 * page does not pay for resizing and encoding. The command only parses
 * options and prints; ImageWorksWarmer does the work.
 */
class ImageWorksWarmCommand extends BaseCommand
{
	private const MAX_FAILURES_SHOWN = 5;

	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('imageworks:warm')
			->setDescription('Pre-build the ImageWorks cache for a collection')
			->addArgument('collection', InputArgument::REQUIRED, 'Collection ID')
			->addArgument('property', InputArgument::OPTIONAL, 'Image or gallery property (default: all of them)')
			->addOption('widths', null, InputOption::VALUE_REQUIRED, 'Comma list of widths, e.g. 480,768,1024')
			->addOption('formats', null, InputOption::VALUE_REQUIRED, 'Comma list of formats for the widths grid: avif, webp, native, ...')
			->addOption('picture', null, InputOption::VALUE_NONE, 'Use the widths and formats cms.render.picture() uses by default, plus native')
			->addOption('params', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'ImageWorks params as a query string, e.g. "w=800&h=600&fit=crop" (repeatable)')
			->addOption('preset', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Preset name (repeatable)')
			->addOption('url', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Copy a variant from a real ImageWorks URL (repeatable)')
			->addOption('id', null, InputOption::VALUE_REQUIRED, 'Comma list of object IDs (default: the whole collection)')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count what would be made, make nothing')
			->addOption('memory', 'm', InputOption::VALUE_REQUIRED, 'Memory limit', '512M');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$memory = $input->getOption('memory');
		if (is_string($memory)) {
			ini_set('memory_limit', $memory);
		}

		$collection = (string)$input->getArgument('collection');
		$argument   = $input->getArgument('property');
		$property   = is_string($argument) && $argument !== '' ? $argument : null;
		$dryRun     = (bool)$input->getOption('dry-run');

		$warmer   = $this->totalcms->container()->get(ImageWorksWarmer::class);
		$ids      = $this->commaList($input->getOption('id'));
		$variants = $this->preflight($input, $output, $warmer, $collection, $property, $ids);
		if (is_int($variants)) {
			return $variants;
		}

		$bar = null;
		if (!$this->isJson($input) && !$dryRun) {
			$bar = new ProgressBar($output);
			$bar->setFormat(' %current%/%max% images [%bar%] %percent:3s%% %elapsed:6s%');
		}
		$onImage = function (int $done, int $total) use (&$bar): void {
			if ($bar === null) {
				return;
			}
			if ($bar->getMaxSteps() !== $total) {
				$bar->start($total);
			}
			$bar->setProgress($done);
		};

		try {
			$report = $warmer->warm($collection, $property, $variants, $ids, $dryRun, $onImage);
		} catch (\Throwable $e) {
			$bar?->clear();

			return $this->outputError($input, $output, 'Could not warm collection "' . $collection . '": ' . $e->getMessage());
		}

		if ($bar !== null) {
			$bar->finish();
			$output->writeln('');
		}

		$this->outputData($input, $output, $report->toArray() + ['dryRun' => $dryRun]);

		return $report->failed > 0 ? Command::FAILURE : Command::SUCCESS;
	}

	/**
	 * @param array<string,mixed>|list<mixed> $data
	 */
	protected function renderHuman(InputInterface $input, OutputInterface $output, array $data): void
	{
		$dryRun = (bool)($data['dryRun'] ?? false);
		$rows   = [
			'Objects'        => $data['objects'] ?? 0,
			'Images'         => $data['images'] ?? 0,
			$dryRun ? 'Would make' : 'Made' => $dryRun ? ($data['wouldMake'] ?? 0) : ($data['made'] ?? 0),
			'Already cached' => $data['cached'] ?? 0,
			'Original'       => $data['original'] ?? 0,
			'Uncacheable'    => $data['uncacheable'] ?? 0,
			'Failed'         => $data['failed'] ?? 0,
		];

		$output->writeln('');
		foreach ($rows as $label => $value) {
			$output->writeln(sprintf('  %-15s %8s', $label . ':', is_scalar($value) ? (string)$value : ''));
		}

		/** @var list<array<string,string>> $failures */
		$failures = is_array($data['failures'] ?? null) ? $data['failures'] : [];
		if ($failures !== []) {
			$output->writeln('');
			$output->writeln('Failures:');
			foreach (array_slice($failures, 0, self::MAX_FAILURES_SHOWN) as $row) {
				$output->writeln('  ' . OutputFormatter::escape(sprintf(
					'%s/%s %s %s [%s]: %s',
					$row['collection'],
					$row['id'],
					$row['property'],
					$row['image'],
					$row['params'],
					$row['error']
				)));
			}
			if (count($failures) > self::MAX_FAILURES_SHOWN) {
				$output->writeln(sprintf('  ... and %d more (use --json for the full list)', count($failures) - self::MAX_FAILURES_SHOWN));
			}
		}

		$uncacheable = $data['uncacheable'] ?? 0;
		if (is_int($uncacheable) && $uncacheable > 0) {
			$output->writeln('');
			$output->writeln(sprintf(
				'%d variants use both an image and a text watermark; ImageWorks re-makes those on every request, so they can\'t be warmed.',
				$uncacheable
			));
		}
	}

	/**
	 * Everything checked before anything is made: the property, the variant
	 * options and the watermark edition. Returns the variants, or the exit
	 * code of the error it printed. Warns when the run would make cache
	 * folders the web server can't use.
	 *
	 * @param list<string> $ids
	 */
	private function preflight(InputInterface $input, OutputInterface $output, ImageWorksWarmer $warmer, string $collection, ?string $property, array $ids): WarmVariants|int
	{
		try {
			$properties = $warmer->properties($collection, $property);
			$variants   = WarmVariants::fromOptions(
				$this->widths($input->getOption('widths')),
				$this->commaList($input->getOption('formats')),
				(bool)$input->getOption('picture'),
				$this->stringList($input->getOption('params')),
				$this->stringList($input->getOption('preset')),
				$this->stringList($input->getOption('url')),
				$this->totalcms->config,
			);
			$blocked = $warmer->blockedWatermark($collection, $properties, $ids);
		} catch (InvalidArgumentException $e) {
			return $this->outputError($input, $output, $e->getMessage());
		} catch (\Throwable $e) {
			return $this->outputError($input, $output, 'Could not read collection "' . $collection . '": ' . $e->getMessage());
		}

		if ($blocked !== null) {
			$edition = ucfirst($this->totalcms->container()->get(EditionFeatureService::class)->getEdition()->value);

			return $this->outputError($input, $output, "{$blocked} has watermarks, but this run resolved the {$edition} edition, which doesn't include them. Nothing was warmed. Check the license from this machine with `tcms check`, or warm a property without watermarks.");
		}

		$warning = $this->ownerWarning($this->totalcms->config->datadir);
		if ($warning !== null) {
			$stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
			$stderr->writeln('<comment>' . OutputFormatter::escape($warning) . '</comment>');
		}

		return $variants;
	}

	/**
	 * The user this process runs as, or null when PHP has no posix extension.
	 * A seam for tests.
	 */
	protected function effectiveUid(): ?int
	{
		return function_exists('posix_geteuid') ? posix_geteuid() : null;
	}

	/**
	 * Glide makes a folder per image under `.cache/`, and the data filesystem
	 * creates folders as 0700. A run as another user (root from cron, say)
	 * leaves folders the web server can't read or write into.
	 */
	private function ownerWarning(string $datadir): ?string
	{
		$uid   = $this->effectiveUid();
		$owner = is_dir($datadir) ? fileowner($datadir) : false;
		if ($uid === null || $owner === false || $uid === $owner) {
			return null;
		}

		$user      = $this->userName($uid);
		$ownerName = $this->userName($owner);

		return "You are running as {$user}, but the data folder belongs to {$ownerName}. Cache folders made by this run may be unreadable by the web server — run it as {$ownerName}, for example `sudo -u {$ownerName} …`";
	}

	private function userName(int $uid): string
	{
		$info = function_exists('posix_getpwuid') ? posix_getpwuid($uid) : false;

		return is_array($info) ? $info['name'] : "uid {$uid}";
	}

	/**
	 * @return list<string> trimmed, non-empty items of a comma list
	 */
	private function commaList(mixed $value): array
	{
		if (!is_string($value)) {
			return [];
		}

		return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== ''));
	}

	/**
	 * @return list<int>
	 *
	 * @throws InvalidArgumentException when an item is not a positive whole number
	 */
	private function widths(mixed $value): array
	{
		$widths = [];
		foreach ($this->commaList($value) as $item) {
			if (!ctype_digit($item) || (int)$item < 1) {
				throw new InvalidArgumentException("--widths must be positive whole numbers, got \"{$item}\".");
			}
			$widths[] = (int)$item;
		}

		return $widths;
	}

	/**
	 * @return list<string>
	 */
	private function stringList(mixed $value): array
	{
		return is_array($value) ? array_values(array_map(strval(...), $value)) : [];
	}
}
