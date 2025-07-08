<?php

namespace TotalCMS\Domain\Twig\Extension;

use Twig\Node\Node;
use Twig\Compiler;
use Twig\Node\Expression\ConstantExpression;

/**
 * Node for the {% cmsgrid %} Twig tag
 *
 * Compiles to PHP code that generates grid HTML
 */
final class CmsGridNode extends Node
{
	public function __construct(
		Node $objects,
		?Node $classes,
		?Node $itemTag,
		Node $template,
		int $lineno,
		string $tag = 'cmsgrid'
	) {
		$nodes = [
			'objects' => $objects,
			'template' => $template,
		];

		if ($classes !== null) {
			$nodes['classes'] = $classes;
		}

		if ($itemTag !== null) {
			$nodes['itemTag'] = $itemTag;
		}

		parent::__construct($nodes, [], $lineno, $tag);
	}

	/** @SuppressWarnings("PHPMD.ElseExpression") */
	public function compile(Compiler $compiler): void
	{
		$compiler->addDebugInfo($this);

		// Start output buffering for the grid
		$compiler->write('ob_start();' . PHP_EOL);

		// Get the objects array
		$compiler->write('$objects = ');
		$compiler->subcompile($this->getNode('objects'));
		$compiler->raw(';' . PHP_EOL);

		// Get classes (default to empty string)
		$compiler->write('$classes = ');
		if ($this->hasNode('classes')) {
			$compiler->subcompile($this->getNode('classes'));
		} else {
			$compiler->raw('""');
		}
		$compiler->raw(';' . PHP_EOL);

		// Get item tag (default to 'div')
		$compiler->write('$itemTag = ');
		if ($this->hasNode('itemTag')) {
			$compiler->subcompile($this->getNode('itemTag'));
		} else {
			$compiler->raw('"div"');
		}
		$compiler->raw(';' . PHP_EOL);

		// Check if objects is not empty
		$compiler->write('if (!empty($objects) && is_array($objects)) {' . PHP_EOL);
		$compiler->indent();

		// Output grid container opening
		$compiler->write('echo "<div class=\"cms-grid " . htmlspecialchars($classes, ENT_QUOTES, \'UTF-8\') . "\">";' . PHP_EOL);

		// Loop through objects
		$compiler->write('foreach ($objects as $item) {' . PHP_EOL);
		$compiler->indent();

		// Start grid item
		$compiler->write('echo "<" . $itemTag . " class=\"cms-grid-item\">";' . PHP_EOL);

		// Render template content with $item available
		$compiler->write('$context[\'item\'] = $item;' . PHP_EOL);
		$compiler->subcompile($this->getNode('template'));

		// End grid item
		$compiler->write('echo "</" . $itemTag . ">";' . PHP_EOL);

		$compiler->outdent();
		$compiler->write('}' . PHP_EOL);

		// Output grid container closing
		$compiler->write('echo "</div>";' . PHP_EOL);

		$compiler->outdent();
		$compiler->write('}' . PHP_EOL);

		// Output the buffered content
		$compiler->write('echo ob_get_clean();' . PHP_EOL);
	}
}