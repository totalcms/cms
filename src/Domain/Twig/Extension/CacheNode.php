<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Twig\Extension;

use Twig\Attribute\YieldReady;
use Twig\Compiler;
use Twig\Node\Node;

/**
 * Compiles {% cache %} to a FragmentCache::render() call. The block body is
 * passed as a lazy closure that captures its yielded output into a string
 * (the same idiom Twig\Node\CaptureNode uses under use_yield), so the body
 * runs only on a cache miss.
 */
#[YieldReady]
final class CacheNode extends Node
{
	public function __construct(
		Node $key,
		?Node $ttl,
		?Node $tags,
		?Node $shared,
		Node $body,
		int $lineno,
	) {
		$nodes = ['key' => $key, 'body' => $body];

		if ($ttl instanceof Node) {
			$nodes['ttl'] = $ttl;
		}
		if ($tags instanceof Node) {
			$nodes['tags'] = $tags;
		}
		if ($shared instanceof Node) {
			$nodes['shared'] = $shared;
		}

		parent::__construct($nodes, [], $lineno);
	}

	public function compile(Compiler $compiler): void
	{
		$compiler->addDebugInfo($this);

		$compiler
			->write('yield $this->env->getRuntime(\\TotalCMS\\Domain\\Cache\\FragmentCache::class)->render(')
			->raw('(string) (');
		$compiler->subcompile($this->getNode('key'));
		$compiler->raw('), ');

		if ($this->hasNode('ttl')) {
			$compiler->raw('(int) (')->subcompile($this->getNode('ttl'))->raw(')');
		} else {
			$compiler->raw('null');
		}
		$compiler->raw(', ');

		if ($this->hasNode('tags')) {
			$compiler->raw('(array) (')->subcompile($this->getNode('tags'))->raw(')');
		} else {
			$compiler->raw('[]');
		}
		$compiler->raw(', ');

		if ($this->hasNode('shared')) {
			$compiler->raw('(bool) (')->subcompile($this->getNode('shared'))->raw(')');
		} else {
			$compiler->raw('false');
		}
		$compiler->raw(', ');

		// Lazy body: a closure returning the captured HTML, invoked only on a miss.
		$compiler
			->raw("function () use (&\$context, \$macros, \$blocks) {\n")
			->indent()
			->write("return implode('', iterator_to_array((function () use (&\$context, \$macros, \$blocks) {\n")
			->indent()
			->subcompile($this->getNode('body'))
			->write("yield from [];\n")
			->outdent()
			->write("})(), false));\n")
			->outdent()
			->write('}')
			->raw(");\n");
	}
}
