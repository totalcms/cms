<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Tool\Service;

use Psr\Container\ContainerInterface;
use TotalCMS\Domain\Mcp\Service\ContentRenderer;
use TotalCMS\Domain\Mcp\Service\McpSchemaResolver;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Tool\Data\SavedQueryToolDefinition;
use TotalCMS\Domain\Mcp\Tool\SavedQuery\SavedQueryTool;

/**
 * Builds runtime SavedQueryTool instances from validated definitions.
 *
 * Closure construction strategy: dynamic named-parameter closure via eval().
 * SDK accepts these and dispatches via reflection — chosen 2026-05-23 via
 * spike (B0). Confirmed against mcp/sdk ~0.5 dispatch path:
 *
 *   CallToolHandler::handle() → ReferenceHandler::handle() →
 *   ReferenceHandler::prepareArguments() reflects on \Closure via
 *   ReflectionFunction::getParameters() and looks each up by name in
 *   $arguments. Type casting (string/int/float/bool) is built-in.
 *
 * The eval() input is strictly validated upstream: param types restricted
 * to string|number|integer|boolean, param names enforced against
 * ^[a-z][a-z0-9_]*$. No user-supplied free-form string ever reaches eval().
 *
 * If the SDK reflection layer ever rejects named-param closures, fall back
 * to a function (array $args) shape and route caller-arg validation
 * through the inputSchema only.
 */
final readonly class SavedQueryToolFactory
{
	public function __construct(
		private ContainerInterface $container,
	) {
	}

	public function build(SavedQueryToolDefinition $definition): SavedQueryTool
	{
		return new SavedQueryTool(
			definition:           $definition,
			indexQueryService:    $this->container->get(\TotalCMS\Domain\Index\Service\IndexQueryService::class),
			filterValueResolver:  $this->container->get(FilterValueResolver::class),
			contentRenderer:      $this->container->get(ContentRenderer::class),
			personaContext:       $this->container->get(PersonaContext::class),
			objectUrlBuilder:     $this->container->get(\TotalCMS\Domain\Collection\Service\ObjectUrlBuilder::class),
			schemaResolver:       $this->container->get(McpSchemaResolver::class),
			collectionRepository: $this->container->get(\TotalCMS\Domain\Collection\Repository\CollectionRepository::class),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function inputSchemaFor(SavedQueryToolDefinition $definition): array
	{
		$properties = [];
		$required   = [];

		foreach ($definition->params as $name => $spec) {
			$prop = ['type' => $spec['type']];
			foreach (['description', 'enum', 'minimum', 'maximum', 'format', 'default'] as $key) {
				if (array_key_exists($key, $spec)) {
					$prop[$key] = $spec[$key];
				}
			}

			$properties[$name] = $prop;

			if (!empty($spec['required'])) {
				$required[] = $name;
			}
		}

		$schema = [
			'type'       => 'object',
			'properties' => $properties,
		];

		if ($required !== []) {
			$schema['required'] = $required;
		}

		return $schema;
	}

	public function descriptionFor(SavedQueryToolDefinition $definition): string
	{
		$lines = [$definition->description, ''];

		if ($definition->params !== []) {
			$lines[] = 'Parameters:';
			foreach ($definition->params as $name => $spec) {
				$type     = $spec['type'];
				$required = !empty($spec['required']) ? 'required' : 'optional';
				$desc     = isset($spec['description']) && is_string($spec['description']) ? $spec['description'] : '';
				$bounds   = [];
				if (isset($spec['minimum'])) {
					$bounds[] = "\u{2265} {$spec['minimum']}";
				}

				if (isset($spec['maximum'])) {
					$bounds[] = "\u{2264} {$spec['maximum']}";
				}

				if (isset($spec['enum']) && is_array($spec['enum'])) {
					$bounds[] = 'one of ' . implode('|', $spec['enum']);
				}

				$boundsStr = $bounds === [] ? '' : ' (' . implode(', ', $bounds) . ')';

				$line = sprintf('- %s (%s, %s)%s', $name, $type, $required, $boundsStr);
				if ($desc !== '') {
					$line .= ': ' . $desc;
				}
				$lines[] = $line;
			}

			$lines[] = '';
		}

		$lines[] = sprintf(
			'Returns: paginated %s objects with url per item.',
			$definition->collectionName,
		);

		return implode("\n", $lines);
	}

	/**
	 * Build the actual closure the SDK registers as the handler.
	 *
	 * The closure's named parameters match the definition's params block
	 * so SDK reflection maps JSON args correctly. The closure delegates
	 * to SavedQueryTool::handle() with an `$args` map.
	 *
	 * The eval() input is strictly validated: types restricted to
	 * string|number|integer|boolean, names enforced against
	 * ^[a-z][a-z0-9_]*$ by SavedQueryToolDefinition::fromArray().
	 */
	public function closureFor(SavedQueryToolDefinition $definition, SavedQueryTool $tool): \Closure
	{
		if ($definition->params === []) {
			return static fn (): array => $tool->handle([]);
		}

		$paramSrc = [];
		foreach ($definition->params as $name => $spec) {
			$type   = self::phpTypeHintFor((string)$spec['type']);
			$nullok = empty($spec['required']);
			$prefix = $nullok ? '?' : '';
			$tail   = $nullok ? ' = null' : '';
			$paramSrc[] = "{$prefix}{$type} \${$name}{$tail}";
		}

		$paramList   = implode(', ', $paramSrc);
		$argMapPairs = [];
		foreach (array_keys($definition->params) as $name) {
			$argMapPairs[] = "'{$name}' => \${$name}";
		}

		$argMapSrc  = implode(', ', $argMapPairs);
		$closureSrc = "return function ({$paramList}) use (\$tool): array { return \$tool->handle([{$argMapSrc}]); };";

		/** @var \Closure */
		$closure = eval($closureSrc);

		return $closure;
	}

	private static function phpTypeHintFor(string $jsonType): string
	{
		return match ($jsonType) {
			'string'  => 'string',
			'integer' => 'int',
			'number'  => 'float',
			'boolean' => 'bool',
			default   => 'mixed',
		};
	}
}
