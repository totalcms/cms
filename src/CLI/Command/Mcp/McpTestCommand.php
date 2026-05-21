<?php

declare(strict_types=1);

namespace TotalCMS\CLI\Command\Mcp;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TotalCMS\CLI\Command\BaseCommand;
use TotalCMS\Domain\Mcp\Data\McpPersona;
use TotalCMS\Domain\Mcp\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Service\ToolRegistry;

/**
 * `tcms mcp:test <tool> [--params='{"key":"value"}'] [--persona=admin]` —
 * locally invoke an MCP tool without going through the HTTP endpoint.
 *
 * Useful for:
 *   - Smoke-testing a tool after schema edits ("does query_collection still
 *     return what I expect from blog?").
 *   - Debugging without firing up an agent or MCP Inspector.
 *   - Scripting one-off operations from a deploy hook.
 *
 * Persona defaults to admin (CLI runs locally with full operator authority).
 * Pass `--persona=public` to simulate an anonymous caller — useful for
 * verifying mcp.access toggles work as expected.
 */
class McpTestCommand extends BaseCommand
{
	protected function configure(): void
	{
		parent::configure();
		$this
			->setName('mcp:test')
			->setDescription('Invoke an MCP tool locally and print the result')
			->addArgument('tool', InputArgument::REQUIRED, 'Tool name (e.g. query_collection, list_collections)')
			->addOption('params', null, InputOption::VALUE_REQUIRED, 'JSON object of params (e.g. \'{"collection":"blog","limit":3}\')', '{}')
			->addOption('persona', null, InputOption::VALUE_REQUIRED, 'Persona for this call: admin | public', 'admin');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$toolName = (string)$input->getArgument('tool');
		$rawParams = (string)$input->getOption('params');
		$personaArg = (string)$input->getOption('persona');

		$params = json_decode($rawParams, true);
		if (!is_array($params)) {
			return $this->outputError($input, $output, "Invalid --params: expected a JSON object, got: {$rawParams}");
		}

		$persona = match ($personaArg) {
			'admin'  => McpPersona::ADMIN,
			'public' => McpPersona::PUBLIC_,
			default  => null,
		};
		if ($persona === null) {
			return $this->outputError($input, $output, "Unknown --persona '{$personaArg}'. Use 'admin' or 'public'.");
		}

		$container = $this->totalcms->container();
		$registry  = $container->get(ToolRegistry::class);
		$tool      = $registry->get($toolName);

		if ($tool === null) {
			$available = implode(', ', array_map(static fn ($t): string => $t->name, $registry->all()));

			return $this->outputError($input, $output, "Tool '{$toolName}' not found. Available: {$available}");
		}

		if (!$tool->isVisibleTo($persona)) {
			return $this->outputError($input, $output, "Tool '{$toolName}' is not visible to persona '{$personaArg}'.");
		}

		// Set persona BEFORE invoking — content tools read from PersonaContext
		// during dispatch to apply safety filters (e.g. public's draft:true).
		$container->get(PersonaContext::class)->set($persona);

		try {
			$result = ($tool->handler)(...$params);
		} catch (\Throwable $e) {
			return $this->outputError($input, $output, $e->getMessage());
		}

		if (!is_array($result)) {
			$result = ['result' => $result];
		}

		return $this->outputData($input, $output, $result);
	}
}
