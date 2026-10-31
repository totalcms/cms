<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\Domain\Security\Data\FindingStatus;
use TotalCMS\Domain\Security\Data\SecurityContext;
use TotalCMS\Domain\Security\Data\Severity;
use TotalCMS\Domain\Security\Service\SecurityAuditor;
use TotalCMS\Domain\Security\Service\SecurityReportStore;
use TotalCMS\Infrastructure\Diagnostics\ServerChecker;
use TotalCMS\Support\Version;

/**
 * The admin's Server Checker from the command line: integrity, license,
 * server info, directory permissions, required and optional software.
 *
 * Exits non-zero when the install cannot run — the bundle is corrupted, a
 * directory is not writable, or a required extension is missing — so a
 * deploy script can gate on it.
 */
class CheckCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('check')
			->setDescription('Check the server: integrity, license, permissions, PHP extensions')
			->addOption('config', null, InputOption::VALUE_NONE, 'Also show the merged configuration (secrets redacted)')
			->addOption('no-security', null, InputOption::VALUE_NONE, 'Skip the security checks');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$checker = $this->totalcms->container()->get(ServerChecker::class);

		$integrity   = $checker->bundleCheck();
		$license     = $checker->licenseInfo();
		$permissions = $checker->checkPermissions();
		$required    = $checker->checkRequiredSoftware();

		// serverInfo() folds the license rows in for the admin page; here
		// they get their own section
		$server = array_diff_key($checker->serverInfo(), $license);

		$ok = $integrity
			&& !in_array(false, $permissions, true)
			&& !in_array(false, $required, true);

		$data = [
			'ok'          => $ok,
			'version'     => Version::number(),
			'build'       => Version::build(),
			'php'         => ['version' => PHP_VERSION, 'sapi' => PHP_SAPI],
			'integrity'   => $integrity,
			'license'     => $license,
			'server'      => $server,
			'permissions' => $permissions,
			'required'    => $required,
			'optional'    => $checker->getOptionalSoftwareDetails(),
		];

		if (!(bool)$input->getOption('no-security')) {
			$context = SecurityContext::fromCli($this->totalcms->config);
			$report  = $this->totalcms->container()->get(SecurityAuditor::class)->audit($context);
			$this->totalcms->container()->get(SecurityReportStore::class)->save($report);

			$data['security'] = [
				'url'      => $context->baseUrl !== '' ? $context->baseUrl : null,
				'checked'  => count($report->findings),
				'findings' => $report->toArray()['findings'],
			];
			$data['ok'] = $ok = $ok && $report->criticalCount() === 0;
		}

		if ((bool)$input->getOption('config')) {
			$data['config'] = $checker->getConfig();
		}

		$this->outputData($input, $output, $data);

		return $ok ? Command::SUCCESS : Command::FAILURE;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	protected function renderHuman(InputInterface $input, OutputInterface $output, array $data): void
	{
		$output->writeln('');
		$output->writeln("<info>Total CMS {$data['version']}</info> (build: {$data['build']})");
		$output->writeln(sprintf(
			'<comment>Checked with the command line PHP %s (%s). The web server runs its own PHP, so its</comment>',
			$data['php']['version'],
			$data['php']['sapi'],
		));
		$output->writeln('<comment>extensions, limits and cache state can differ: see Admin → Utilities → Server Checker.</comment>');
		if (isset($data['security'])) {
			$output->writeln('<comment>Checks that request the site use the address it last recorded: ' . OutputFormatter::escape((string)($data['security']['url'] ?? 'unknown, visit the admin once or set domain')) . '.</comment>');
		}

		$this->section($output, 'Integrity');
		$output->writeln($data['integrity']
			? '  <info>✓</info> Bundle files match the shipped manifest'
			: '  <error>✗</error> The installation has been corrupted: files differ from the shipped manifest');

		$this->section($output, 'License');
		$this->keyValues($output, (array)$data['license']);

		$this->section($output, 'Server');
		$this->keyValues($output, (array)$data['server']);

		$this->section($output, 'Permissions');
		foreach ((array)$data['permissions'] as $dir => $writable) {
			$output->writeln($writable
				? "  <info>✓</info> {$dir}"
				: "  <error>✗</error> {$dir}  <comment>not writable</comment>");
		}

		$this->section($output, 'Required software');
		foreach ((array)$data['required'] as $name => $present) {
			$output->writeln($present
				? "  <info>✓</info> {$name}"
				: "  <error>✗</error> {$name}  <comment>missing</comment>");
		}

		$this->section($output, 'Optional software');
		foreach ((array)$data['optional'] as $details) {
			$output->writeln($details['available']
				? "  <info>✓</info> {$details['name']}"
				: "  <comment>–</comment> {$details['name']}  <comment>{$details['recommendation']}</comment>");
		}

		if (isset($data['security'])) {
			$this->section($output, 'Security');
			foreach ((array)$data['security']['findings'] as $finding) {
				$this->renderFinding($output, (array)$finding);
			}
		}

		if (isset($data['config'])) {
			$this->section($output, 'Configuration');
			$output->writeln((string)json_encode($data['config'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		}

		$output->writeln('');
		$output->writeln($data['ok']
			? '<info>All checks passed.</info>'
			: '<error>Some checks failed.</error>');
		$output->writeln('');
	}

	/**
	 * @param array<string,mixed> $finding
	 */
	private function renderFinding(OutputInterface $output, array $finding): void
	{
		$ignored = !empty($finding['ignored']) ? ' (ignored)' : '';
		$title   = OutputFormatter::escape((string)$finding['title']);

		if ($finding['status'] === FindingStatus::Ok->value) {
			$output->writeln("  <info>✓</info> {$title}{$ignored}");

			return;
		}

		if ($finding['status'] === FindingStatus::Skipped->value) {
			$output->writeln("  <comment>?</comment> {$title}{$ignored}  <comment>" . OutputFormatter::escape((string)$finding['detail']) . "</comment>");

			return;
		}

		$output->writeln(match ($finding['severity']) {
			Severity::Critical->value => "  <error>✗</error> {$title}{$ignored}",
			Severity::Warning->value  => "  <comment>!</comment> {$title}{$ignored}",
			default                   => "  <comment>–</comment> {$title}{$ignored}",
		});

		if ((string)($finding['detail'] ?? '') !== '') {
			$output->writeln($this->indented((string)$finding['detail']));
		}
		if ((string)($finding['fix'] ?? '') !== '') {
			$output->writeln($this->indented((string)$finding['fix'], 'comment'));
		}
	}

	/** Escape, then indent every line (blank lines stay blank), optionally inside a style tag per line. */
	private function indented(string $text, string $style = ''): string
	{
		$lines = array_map(static function (string $line) use ($style): string {
			if (trim($line) === '') {
				return '';
			}
			$line = OutputFormatter::escape($line);

			return '    ' . ($style !== '' ? "<{$style}>{$line}</{$style}>" : $line);
		}, preg_split('/\R/', $text) ?: []);

		return implode("\n", $lines);
	}

	private function section(OutputInterface $output, string $title): void
	{
		$output->writeln('');
		$output->writeln("<info>{$title}</info>");
	}

	/**
	 * @param array<string,mixed> $rows
	 */
	private function keyValues(OutputInterface $output, array $rows): void
	{
		$width = max([0, ...array_map(strlen(...), array_keys($rows))]);
		foreach ($rows as $key => $value) {
			$value = is_scalar($value) ? (string)$value : (string)json_encode($value);
			$output->writeln(sprintf('  %-' . $width . 's  %s', $key, $value));
		}
	}
}
