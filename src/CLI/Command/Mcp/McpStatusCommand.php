<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command\Mcp;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Command\BaseCommand;
use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Support\Config;

/**
 * `tcms mcp:status` — surfaces the operator-facing health of the MCP server.
 *
 * Useful for diagnosing why an agent can't reach the endpoint:
 *   - `enabled` reflects `mcp.enabled` in settings.
 *   - `public_access` reflects `mcp.publicAccess` (the default-deny master switch).
 *   - `edition_ok` checks the Pro+ edition gate.
 *   - `tool_prefix` shows the optional `mcp.toolPrefix` that namespaces tool names.
 *   - `tools.admin` / `tools.public` show the tool surface each persona sees.
 *
 * Combine with `tcms mcp:test <tool>` for a quick dispatch sanity check.
 */
class McpStatusCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('mcp:status')
			->setDescription('Show MCP server status: enabled/edition/tool counts per persona');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$container = $this->totalcms->container();
		$config    = $container->get(Config::class);
		$editions  = $container->get(EditionFeatureService::class);
		$registry  = $container->get(ToolRegistry::class);

		$mcpConfig = (array)$config->mcp;

		// The live server registers schema-defined saved-query tools per
		// request (McpServerFactory::build()); without mirroring that here,
		// status under-reports the tool surface and an operator who just
		// saved a tool in a collection's MCP card sees it "missing". The
		// before/after diff identifies which names are schema-defined.
		$coreNames = array_map(static fn ($t): string => $t->name, $registry->all());
		$container->get(\TotalCMS\Domain\Mcp\Tool\Service\SchemaToolRegistrar::class)->register($registry);
		$schemaTools = array_values(array_diff(
			array_map(static fn ($t): string => $t->name, $registry->all()),
			$coreNames,
		));

		$admin  = array_map(static fn ($t): string => $t->name, $registry->forPersona(McpPersona::ADMIN));
		$public = array_map(static fn ($t): string => $t->name, $registry->forPersona(McpPersona::PUBLIC_));

		// The licensing domain is auto-detected from the request Host header, and
		// the CLI has neither that nor SERVER_NAME — so config/defaults.php falls
		// back to the literal 'unknown', the license API has no record for it, and
		// the edition reads as a trial on a properly licensed install.
		//
		// The gate itself is right and worth reporting: on Lite it is the reason
		// /mcp answers 403, which is the second thing an operator checks after
		// `enabled`. So report it, and say when the number underneath it came from
		// a domain we never actually resolved. Same root cause the Docker /
		// reverse-proxy warning in LicenseValidationMiddleware covers, reached a
		// different way.
		$domainResolved = $config->domain !== ''
			&& $config->domain !== 'unknown'
			&& !Config::isNonRoutableHost($config->domain);

		$data = [
			'enabled'         => (bool)($mcpConfig['enabled'] ?? true),
			'public_access'   => (bool)($mcpConfig['publicAccess'] ?? false),
			'edition_ok'      => $editions->can(EditionFeature::MCP_SERVER),
			'edition'         => $editions->getEdition()->value,
			'domain'          => $config->domain,
			'domain_resolved' => $domainResolved,
			'tool_prefix'     => (string)($mcpConfig['toolPrefix'] ?? ''),
			'tools'           => [
				'admin'  => $admin,
				'public' => $public,
			],
			'schema_tools'  => $schemaTools,
		];

		return $this->outputData($input, $output, $data);
	}

	/**
	 * @param array<string,mixed>|list<mixed> $data
	 */
	protected function renderHuman(InputInterface $input, OutputInterface $output, array $data): void
	{
		$ok = static fn (bool $b): string => $b ? '<info>yes</info>' : '<comment>no</comment>';

		$output->writeln('');
		$output->writeln('<info>MCP Server Status</info>');
		$output->writeln(sprintf('  enabled:       %s', $ok((bool)$data['enabled'])));
		$output->writeln(sprintf('  public access: %s', $ok((bool)$data['public_access'])));
		$output->writeln(sprintf('  edition gate:  %s  <comment>(edition=%s)</comment>', $ok((bool)$data['edition_ok']), $data['edition']));
		$output->writeln(sprintf('  tool prefix:   %s', $data['tool_prefix'] === '' ? '<comment>(none)</comment>' : (string)$data['tool_prefix']));

		if (!(bool)$data['domain_resolved']) {
			$output->writeln('');
			$output->writeln(sprintf(
				"  <comment>Note: the licensing domain resolved to '%s', so the edition above is</comment>",
				$data['domain'] === '' ? '(empty)' : (string)$data['domain'],
			));
			$output->writeln('  <comment>not this site\'s. It comes from the request Host header, which the CLI</comment>');
			$output->writeln('  <comment>does not have — set `domain` in config/tcms.php to read it correctly</comment>');
			$output->writeln('  <comment>here. Web requests are unaffected.</comment>');
		}

		$output->writeln('');

		$tools       = (array)$data['tools'];
		$admin       = is_array($tools['admin']) ? $tools['admin'] : [];
		$pub         = is_array($tools['public']) ? $tools['public'] : [];
		$schemaTools = is_array($data['schema_tools'] ?? null) ? $data['schema_tools'] : [];

		$annotate = static fn (string $name): string => in_array($name, $schemaTools, true)
			? $name . ' <comment>(saved query)</comment>'
			: $name;

		$output->writeln(sprintf('<info>Admin persona</info> (%d tools)', count($admin)));
		foreach ($admin as $name) {
			$output->writeln('  - ' . $annotate((string)$name));
		}
		$output->writeln('');

		$output->writeln(sprintf('<info>Public persona</info> (%d tools)', count($pub)));
		if ($pub === []) {
			$output->writeln('  <comment>(none — flip mcp.publicAccess on and mark collections mcp.access=public)</comment>');
		}
		foreach ($pub as $name) {
			$output->writeln('  - ' . $annotate((string)$name));
		}
		$output->writeln('');
	}
}
