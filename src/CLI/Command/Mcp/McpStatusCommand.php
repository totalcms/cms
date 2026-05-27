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

		$admin  = array_map(static fn ($t): string => $t->name, $registry->forPersona(McpPersona::ADMIN));
		$public = array_map(static fn ($t): string => $t->name, $registry->forPersona(McpPersona::PUBLIC_));

		$data = [
			'enabled'       => (bool)($mcpConfig['enabled'] ?? true),
			'public_access' => (bool)($mcpConfig['publicAccess'] ?? false),
			'edition_ok'    => $editions->can(EditionFeature::MCP_SERVER),
			'edition'       => $editions->getEdition()->value,
			'tool_prefix'   => (string)($mcpConfig['toolPrefix'] ?? ''),
			'tools'         => [
				'admin'  => $admin,
				'public' => $public,
			],
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
		$output->writeln('');

		$tools = (array)$data['tools'];
		$admin = is_array($tools['admin']) ? $tools['admin'] : [];
		$pub   = is_array($tools['public']) ? $tools['public'] : [];

		$output->writeln(sprintf('<info>Admin persona</info> (%d tools)', count($admin)));
		foreach ($admin as $name) {
			$output->writeln('  - ' . $name);
		}
		$output->writeln('');

		$output->writeln(sprintf('<info>Public persona</info> (%d tools)', count($pub)));
		if ($pub === []) {
			$output->writeln('  <comment>(none — flip mcp.publicAccess on and mark collections mcp.access=public)</comment>');
		}
		foreach ($pub as $name) {
			$output->writeln('  - ' . $name);
		}
		$output->writeln('');
	}
}
