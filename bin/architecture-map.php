<?php

declare(strict_types=1);

/**
 * Architecture map: who calls what, from every entry point down.
 *
 * Reads src/ and config/routes/ with nikic/php-parser (no code is executed)
 * and writes build/architecture/index.html, a self-contained, clickable map
 * of the entry points (REST, admin, public web, MCP, CLI, Twig, events, jobs),
 * the classes they reach, and the places a caller reaches past a service into
 * the classes underneath it.
 *
 *   php bin/architecture-map.php            # writes build/architecture/
 *   php bin/architecture-map.php --json     # also prints the findings as JSON
 *
 * Dependencies are taken from constructor parameters, typed properties,
 * `new X`, static `X::call()`, and `$container->get(X::class)`. Interfaces
 * resolve to their only implementation, or to the binding in config/container.php.
 */

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\Parser;
use PhpParser\ParserFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

const NS = 'TotalCMS\\';

/**
 * Parts of the app that are meant to have one front door. For each module:
 *
 *   front     the classes everything else should call
 *   internals classes only the module itself (and `allowed` folders) may call
 *   parallel  classes that still make the module's decision on their own,
 *             outside the front door — each caller is reported, so the list
 *             shrinks as they fold into the front door
 *   allowed   namespace prefixes (after TotalCMS\\) that may use internals
 *
 * The front door's own folder is always allowed. Add a module here once a
 * refactor (like the 2026-10-22 AccessGuard one) has made it the way in.
 *
 * @var array<string,array{label:string,front:list<string>,internals?:list<string>,parallel?:array<string,array{why:string,decides:list<string>}>,allowed?:list<string>}>
 */
const MODULES = [
	'access' => [
		'label' => 'Access control',
		'front' => [
			'TotalCMS\\Domain\\Auth\\Service\\AccessGuard',
			'TotalCMS\\Domain\\Auth\\Service\\AccessControlService',
			'TotalCMS\\Domain\\Auth\\Service\\ActorContext',
		],
		'internals' => [
			'TotalCMS\\Domain\\Collection\\Service\\OwnerPropertyResolver',
			'TotalCMS\\Domain\\Auth\\Service\\ActorResolver',
			'TotalCMS\\Domain\\Auth\\Service\\AccessDenials',
		],
		// Methods listed in `decides` make an access decision; a caller that
		// only uses the others (identity lookups) is reported as low.
		'parallel' => [
			'TotalCMS\\Domain\\Auth\\Service\\AccessManager' => [
				'why'     => 'makes its own session-based access check',
				'decides' => ['userHasAccess', 'restrictPageAccess', 'sessionIsSuperAdmin'],
			],
			'TotalCMS\\Domain\\Auth\\Service\\FileAccessManager' => [
				'why'     => 'decides file and depot access from the session itself',
				'decides' => ['userHasAccess', 'isProtectedByGroups', 'verfiyPassword', 'verfiyPasswordOnly'],
			],
			'TotalCMS\\Domain\\Auth\\Service\\UploadAccessPolicy' => [
				'why'     => 'decides raw-upload access from the session itself',
				'decides' => [],
			],
		],
		'allowed' => [
			'Middleware\\Access',
			'Middleware\\RequestScopeResetMiddleware',
			'Domain\\Mcp\\Service\\McpRequestAuthorizer',
			'Domain\\Object\\Service\\ObjectWriteGate',
		],
	],
];

$root   = dirname(__DIR__);
$outDir = $root . '/build/architecture';
$parser = (new ParserFactory())->createForHostVersion();
$finder = new NodeFinder();

/** @return list<Node\Stmt> */
function parseFile(string $path, Parser $parser, bool $parents = false): array
{
	$stmts = $parser->parse((string)file_get_contents($path)) ?? [];
	$t     = new NodeTraverser();
	$t->addVisitor(new NameResolver());
	if ($parents) {
		$t->addVisitor(new ParentConnectingVisitor());
	}

	return $t->traverse($stmts);
}

/** @return list<string> */
function typeNames(?Node $type): array
{
	if ($type === null) {
		return [];
	}
	if ($type instanceof Node\NullableType) {
		return typeNames($type->type);
	}
	if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
		return array_merge(...array_map(typeNames(...), $type->types));
	}
	if ($type instanceof Node\Name) {
		return [$type->toString()];
	}

	return [];
}

function classify(string $fqcn, string $kind, bool $abstract): array
{
	$parts  = explode('\\', substr($fqcn, strlen(NS)));
	$short  = end($parts);
	$area   = $parts[0];
	$domain = match ($area) {
		'Domain', 'Action', 'Middleware' => count($parts) > 2 ? $parts[1] : $area,
		default                          => $area,
	};
	$path = implode('\\', array_slice($parts, 0, -1));

	$role = match (true) {
		$kind === 'interface'                                                                                       => 'interface',
		$kind === 'trait'                                                                                           => 'trait',
		$kind === 'enum'                                                                                            => 'data',
		$area === 'Action'                                                                                          => 'action',
		$area === 'CLI' && str_contains($path, 'Command')                                                           => 'cli',
		$area === 'Middleware'                                                                                      => 'middleware',
		(bool)preg_match('/^Domain\\\\Mcp\\\\(Tool|Resource|Prompt)/', $path) && !str_contains($path, 'Data')       => 'mcp',
		str_starts_with($path, 'Domain\\Twig\\Adapter')                                                             => 'twig',
		str_contains($path, 'Exception') || str_ends_with($short, 'Exception')                                      => 'exception',
		str_contains($path, 'Listener') || str_ends_with($short, 'Listener') || str_ends_with($short, 'Subscriber') => 'listener',
		str_contains($path, '\\Job') || str_ends_with($short, 'Job')                                                => 'job',
		str_contains($path, 'Repository') || str_ends_with($short, 'Repository')                                    => 'repository',
		str_contains($path, '\\Data') || str_ends_with($short, 'Data') || str_ends_with($short, 'Payload')          => 'data',
		str_contains($path, 'Service')                                                                              => 'service',
		default                                                                                                     => 'other',
	};

	return [
		'short'    => $short,
		'area'     => $area,
		'domain'   => $domain,
		'path'     => $path,
		'role'     => $role,
		'kind'     => $kind,
		'abstract' => $abstract,
	];
}

// ---------------------------------------------------------------- classes

$classes        = [];
$rawEdges       = [];
$implements     = [];
$serviceLocator = [];
/** @var array<string,array<string,true>> $calls edge key → methods called */
$calls = [];

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
	if ($file->getExtension() !== 'php') {
		continue;
	}
	$rel   = substr($file->getPathname(), strlen($root) + 1);
	$stmts = parseFile($file->getPathname(), $parser);

	foreach ($finder->findInstanceOf($stmts, Node\Stmt\ClassLike::class) as $cl) {
		if ($cl->namespacedName === null) {
			continue;
		}
		$fqcn = $cl->namespacedName->toString();
		$kind = match (true) {
			$cl instanceof Node\Stmt\Interface_ => 'interface',
			$cl instanceof Node\Stmt\Trait_     => 'trait',
			$cl instanceof Node\Stmt\Enum_      => 'enum',
			default                             => 'class',
		};
		$abstract        = $cl instanceof Node\Stmt\Class_ && $cl->isAbstract();
		$classes[$fqcn]  = classify($fqcn, $kind, $abstract) + ['file' => $rel, 'line' => $cl->getStartLine(), 'methods' => []];

		// Property name → class, so `$this->prop->method()` can be attributed.
		$propTypes = [];
		foreach ($cl->getProperties() as $prop) {
			foreach ($prop->props as $pp) {
				$propTypes[$pp->name->toString()] = typeNames($prop->type)[0] ?? null;
			}
		}
		$ctor = $cl->getMethod('__construct');
		foreach ($ctor?->params ?? [] as $p) {
			if ($p->flags !== 0 && $p->var instanceof Node\Expr\Variable && is_string($p->var->name)) {
				$propTypes[$p->var->name] = typeNames($p->type)[0] ?? null;
			}
		}
		foreach ($finder->findInstanceOf($cl->stmts, Node\Expr\MethodCall::class) as $mc) {
			if ($mc->var instanceof Node\Expr\PropertyFetch && $mc->var->var instanceof Node\Expr\Variable && $mc->var->var->name === 'this'
				&& $mc->var->name instanceof Node\Identifier && $mc->name instanceof Node\Identifier
				&& ($t = $propTypes[$mc->var->name->toString()] ?? null) !== null && str_starts_with($t, NS)
			) {
				$calls[$fqcn . '>' . $t][$mc->name->toString()] = true;
			}
		}

		$add = static function (string $to, string $type, int $line) use (&$rawEdges, $fqcn): void {
			if ($to !== $fqcn && str_starts_with($to, NS)) {
				$rawEdges[] = [$fqcn, $to, $type, $line];
			}
		};

		if ($cl instanceof Node\Stmt\Class_) {
			if ($cl->extends) {
				$add($cl->extends->toString(), 'extends', $cl->getStartLine());
			}
			foreach ($cl->implements as $i) {
				$implements[$i->toString()][] = $fqcn;
			}
		}
		if ($cl instanceof Node\Stmt\Interface_) {
			foreach ($cl->extends as $i) {
				$implements[$i->toString()][] = $fqcn;
			}
		}

		foreach ($cl->getMethods() as $m) {
			if ($m->isPublic() && !$m->isStatic() && $m->name->toString() !== '__construct') {
				$classes[$fqcn]['methods'][] = $m->name->toString();
			}
			if ($m->name->toString() === '__construct') {
				foreach ($m->params as $p) {
					foreach (typeNames($p->type) as $t) {
						$add($t, 'inject', $p->getStartLine());
					}
				}
			}
		}
		foreach ($cl->getProperties() as $prop) {
			foreach (typeNames($prop->type) as $t) {
				$add($t, 'inject', $prop->getStartLine());
			}
		}
		foreach ($finder->findInstanceOf($cl->stmts, Node\Expr\New_::class) as $new) {
			if ($new->class instanceof Node\Name && !in_array($new->class->toLowerString(), ['self', 'static', 'parent'], true)) {
				$add($new->class->toString(), 'new', $new->getStartLine());
			}
		}
		foreach ($finder->findInstanceOf($cl->stmts, Node\Expr\StaticCall::class) as $sc) {
			if ($sc->class instanceof Node\Name && !in_array($sc->class->toLowerString(), ['self', 'static', 'parent'], true)) {
				$add($sc->class->toString(), 'static', $sc->getStartLine());
			}
		}
		foreach ($finder->findInstanceOf($cl->stmts, Node\Expr\MethodCall::class) as $mc) {
			if ($mc->name instanceof Node\Identifier && in_array($mc->name->toString(), ['get', 'make'], true)
				&& isset($mc->args[0]) && $mc->args[0] instanceof Node\Arg
				&& $mc->args[0]->value instanceof Node\Expr\ClassConstFetch
				&& $mc->args[0]->value->name instanceof Node\Identifier && $mc->args[0]->value->name->toLowerString() === 'class'
				&& $mc->args[0]->value->class instanceof Node\Name
			) {
				$target = $mc->args[0]->value->class->toString();
				if (str_starts_with($target, NS)) {
					$add($target, 'container', $mc->getStartLine());
					$serviceLocator[] = [$fqcn, $target, $mc->getStartLine()];
				}
			}
		}
	}
}

// ---------------------------------------------------------------- container bindings

$bindings  = [];
$container = parseFile($root . '/config/container.php', $parser);
foreach ($finder->findInstanceOf($container, Node\Expr\ArrayItem::class) as $item) {
	if (!$item->key instanceof Node\Expr\ClassConstFetch || !$item->key->class instanceof Node\Name) {
		continue;
	}
	$key = $item->key->class->toString();
	if (($classes[$key]['kind'] ?? '') !== 'interface') {
		continue;
	}
	foreach ($finder->find([$item->value], fn (Node $n) => $n instanceof Node\Expr\New_ || $n instanceof Node\Expr\ClassConstFetch) as $n) {
		$target = $n instanceof Node\Expr\New_ ? ($n->class instanceof Node\Name ? $n->class->toString() : null) : ($n->class instanceof Node\Name ? $n->class->toString() : null);
		if ($target !== null && $target !== $key && isset($classes[$target]) && $classes[$target]['kind'] === 'class') {
			$bindings[$key] = $target;
			break;
		}
	}
}
foreach ($implements as $iface => $impls) {
	$impls = array_values(array_filter(array_unique($impls), fn ($c) => ($classes[$c]['kind'] ?? '') === 'class'));
	if (!isset($bindings[$iface]) && count($impls) === 1 && ($classes[$iface]['kind'] ?? '') === 'interface') {
		$bindings[$iface] = $impls[0];
	}
}

// ---------------------------------------------------------------- edges

$edges = [];
foreach ($rawEdges as [$from, $to, $type, $line]) {
	if (!isset($classes[$to])) {
		continue;
	}
	$key = $from . '>' . $to;
	if (!isset($edges[$key])) {
		$edges[$key] = ['from' => $from, 'to' => $to, 'types' => [], 'line' => $line];
	}
	$edges[$key]['types'][$type] = true;
}
// An interface dependency also reaches the implementation behind it.
foreach ($bindings as $iface => $impl) {
	$edges[$iface . '>' . $impl] = ['from' => $iface, 'to' => $impl, 'types' => ['binds' => true], 'line' => 0];
}
foreach ($edges as &$e) {
	$e['types'] = array_keys($e['types']);
}
unset($e);

/** @var array<string,list<string>> $out */
$out = [];
/** @var array<string,list<string>> $in */
$in = [];
foreach ($edges as $e) {
	if (in_array('extends', $e['types'], true) && count($e['types']) === 1) {
		continue;
	}
	$out[$e['from']][] = $e['to'];
	$in[$e['to']][]    = $e['from'];
}

// ---------------------------------------------------------------- routes

$routes     = [];
$surfaceFor = static function (string $file): string {
	$base = basename($file, '.php');
	if (str_contains($file, '/api/')) {
		return 'rest';
	}
	if (str_contains($file, '/admin/')) {
		return 'admin';
	}

	return match ($base) {
		'mcp'                 => 'mcp',
		'oauth'               => 'oauth',
		'xmlrpc'              => 'xmlrpc',
		'automations', 'cron' => 'webhook',
		default               => 'public',
	};
};

$chainMiddleware = static function (Node $call): array {
	$mw  = [];
	$cur = $call;
	while (($parent = $cur->getAttribute('parent')) instanceof Node\Expr\MethodCall && $parent->var === $cur) {
		if ($parent->name instanceof Node\Identifier && $parent->name->toString() === 'add'
			&& ($parent->args[0] ?? null) instanceof Node\Arg && $parent->args[0]->value instanceof Node\Expr\ClassConstFetch
			&& $parent->args[0]->value->class instanceof Node\Name
		) {
			$mw[] = $parent->args[0]->value->class->toString();
		}
		$cur = $parent;
	}

	return $mw;
};

$routeFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/config/routes', FilesystemIterator::SKIP_DOTS));
foreach ($routeFiles as $file) {
	if ($file->getExtension() !== 'php') {
		continue;
	}
	$rel     = substr($file->getPathname(), strlen($root) + 1);
	$surface = $surfaceFor($rel);
	$prefix  = $surface === 'rest' ? '/api' : '';
	$stmts   = parseFile($file->getPathname(), $parser, true);

	$walk = static function (array $nodes, string $path, array $mw) use (&$walk, &$routes, $rel, $surface, $chainMiddleware): void {
		foreach ($nodes as $node) {
			if (!$node instanceof Node) {
				continue;
			}
			if ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier) {
				$name = strtolower($node->name->toString());
				$arg0 = ($node->args[0] ?? null) instanceof Node\Arg ? $node->args[0]->value : null;
				$seg  = $arg0 instanceof Node\Scalar\String_ ? $arg0->value : '';
				if ($name === 'group' && ($node->args[1] ?? null) instanceof Node\Arg) {
					$walk([$node->var], $path, $mw);
					$walk([$node->args[1]->value], $path . $seg, array_merge($mw, $chainMiddleware($node)));
					continue;
				}
				if (in_array($name, ['get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'map'], true)) {
					$methods = [strtoupper($name)];
					$pathArg = $seg;
					$handler = $node->args[1] ?? null;
					if ($name === 'map') {
						$methods = $arg0 instanceof Node\Expr\Array_
							? array_map(fn ($i) => $i->value instanceof Node\Scalar\String_ ? $i->value->value : '?', $arg0->items)
							: ['*'];
						$pathArg = ($node->args[1] ?? null) instanceof Node\Arg && $node->args[1]->value instanceof Node\Scalar\String_ ? $node->args[1]->value->value : '';
						$handler = $node->args[2] ?? null;
					}
					if ($handler instanceof Node\Arg && $handler->value instanceof Node\Expr\ClassConstFetch && $handler->value->class instanceof Node\Name) {
						$routes[] = [
							'methods'    => $methods,
							'path'       => $path . $pathArg,
							'action'     => $handler->value->class->toString(),
							'surface'    => $surface,
							'file'       => $rel,
							'line'       => $node->getStartLine(),
							'middleware' => array_values(array_unique(array_merge($mw, $chainMiddleware($node)))),
						];
						continue;
					}
				}
			}
			foreach ($node->getSubNodeNames() as $sub) {
				$child = $node->$sub;
				$walk(is_array($child) ? $child : [$child], $path, $mw);
			}
		}
	};
	$walk($stmts, $prefix, []);
}

// ---------------------------------------------------------------- entry points

$surfaces = [
	'rest'     => ['label' => 'REST API', 'detail' => '/api/* routes'],
	'admin'    => ['label' => 'Admin UI', 'detail' => '/admin pages and HTMX partials'],
	'public'   => ['label' => 'Public web', 'detail' => 'Feeds, sitemaps, ImageWorks, downloads'],
	'builder'  => ['label' => 'Site pages', 'detail' => 'PageRouterMiddleware + Twig cms.*'],
	'mcp'      => ['label' => 'MCP', 'detail' => 'Tools, resources, prompts at /mcp'],
	'oauth'    => ['label' => 'OAuth', 'detail' => 'Authorization server'],
	'xmlrpc'   => ['label' => 'XML-RPC', 'detail' => 'Desktop publishing clients'],
	'webhook'  => ['label' => 'Webhooks & cron', 'detail' => 'Automation webhooks, cron URL'],
	'cli'      => ['label' => 'CLI', 'detail' => 'tcms commands'],
	'events'   => ['label' => 'Events & jobs', 'detail' => 'Listeners and queued jobs'],
];

/** @var array<string,array<string,true>> $entries surface → entry class */
$entries = array_fill_keys(array_keys($surfaces), []);
foreach ($routes as $r) {
	if (isset($classes[$r['action']])) {
		$entries[$r['surface']][$r['action']] = true;
	}
}
foreach ($classes as $fqcn => $c) {
	if ($c['abstract'] || $c['kind'] !== 'class') {
		continue;
	}
	match ($c['role']) {
		'cli'              => $entries['cli'][$fqcn]     = true,
		'mcp'              => $entries['mcp'][$fqcn]     = true,
		'twig'             => $entries['builder'][$fqcn] = true,
		'listener', 'job'  => $entries['events'][$fqcn]  = true,
		default            => null,
	};
}
$entries['builder']['TotalCMS\\Middleware\\PageRouterMiddleware'] = true;
foreach ($entries as $s => $list) {
	$entries[$s] = array_keys($list);
}

// ---------------------------------------------------------------- findings

$isPlumbing = static fn (string $c): bool => in_array($classes[$c]['role'] ?? 'other', ['data', 'exception', 'interface', 'trait'], true)
	|| in_array($classes[$c]['area'] ?? '', ['Support', 'Utils', 'Traits'], true);

$domainOf = static fn (string $c): string => ($classes[$c]['area'] ?? '') === 'Domain' ? $classes[$c]['domain'] : '';

$findings = [];

// 1. Declared modules: callers reach past the front door, or use a class
//    that still makes the module's decision on its own.
$moduleOf = [];
foreach (MODULES as $id => $m) {
	foreach ($m['front'] as $c) {
		$moduleOf[$c] = $id;
	}
}
foreach (MODULES as $id => $m) {
	$allowed = array_merge($m['allowed'] ?? [], array_map(fn ($c) => $classes[$c]['path'] ?? '-', $m['front']));
	$inside  = static function (string $caller) use ($allowed, $m): bool {
		if (in_array($caller, $m['front'], true) || in_array($caller, $m['internals'] ?? [], true) || isset(($m['parallel'] ?? [])[$caller])) {
			return true;
		}
		$name = substr($caller, strlen(NS));
		foreach ($allowed as $prefix) {
			if ($name === $prefix || str_starts_with($name, $prefix . '\\')) {
				return true;
			}
		}

		return false;
	};
	foreach ($m['internals'] ?? [] as $x) {
		foreach (array_unique($in[$x] ?? []) as $caller) {
			if (!$inside($caller)) {
				$findings[] = [
					'kind'     => 'gateway',
					'severity' => 'high',
					'caller'   => $caller,
					'target'   => $x,
					'via'      => $m['front'][0],
					'line'     => $edges[$caller . '>' . $x]['line'] ?? 0,
					'note'     => sprintf('%s is internal to %s; go through %s.', $classes[$x]['short'] ?? $x, $m['label'], implode(' / ', array_map(fn ($c) => $classes[$c]['short'], $m['front']))),
				];
			}
		}
	}
	foreach ($m['parallel'] ?? [] as $x => $p) {
		foreach (array_unique($in[$x] ?? []) as $caller) {
			if ($inside($caller)) {
				continue;
			}
			$used    = array_keys($calls[$caller . '>' . $x] ?? []);
			$decides = array_values(array_intersect($used, $p['decides']));
			// No `decides` list means every use is a decision.
			$isDecision = $p['decides'] === [] || $decides !== [] || $used === [];
			$findings[] = [
				'kind'     => 'parallel',
				'severity' => $isDecision ? 'medium' : 'low',
				'caller'   => $caller,
				'target'   => $x,
				'via'      => $m['front'][0],
				'line'     => $edges[$caller . '>' . $x]['line'] ?? 0,
				'methods'  => $used,
				'note'     => $isDecision
					? sprintf('%s %s, outside the %s front door%s.', $classes[$x]['short'] ?? $x, $p['why'], $m['label'], $decides ? ' (' . implode(', ', $decides) . ')' : '')
					: sprintf('Uses %s for identity only (%s); no access decision.', $classes[$x]['short'] ?? $x, implode(', ', $used)),
			];
		}
	}
}

// 2. Inferred hierarchies: a service in a domain wraps a class, but callers
//    outside that domain reach the wrapped class directly.
foreach ($classes as $x => $cx) {
	if ($isPlumbing($x) || $cx['area'] !== 'Domain' || in_array($cx['role'], ['repository', 'mcp', 'twig', 'listener', 'job'], true)) {
		continue;
	}
	$callers  = array_unique($in[$x] ?? []);
	$owners   = [];
	$outside  = [];
	$internal = 0;
	foreach ($callers as $c) {
		if ($domainOf($c) === $cx['domain']) {
			$internal++;
			if (in_array($classes[$c]['role'], ['service', 'other'], true) && !isset($moduleOf[$c])) {
				$owners[] = $c;
			}
		} elseif (!$isPlumbing($c)) {
			$outside[] = $c;
		}
	}
	if (!$owners || !$outside) {
		continue;
	}
	// A wrapper only counts as the front door if outsiders use it too.
	$frontDoors = array_values(array_filter($owners, fn ($o) => array_filter($in[$o] ?? [], fn ($c) => $domainOf($c) !== $cx['domain'])));
	if (!$frontDoors) {
		continue;
	}
	$share = count($outside) / (count($outside) + $internal);
	// A class most of the app uses is a shared utility, not a bypassed internal.
	if (count($outside) > 6 || $share > 0.75) {
		continue;
	}
	foreach ($outside as $caller) {
		$alsoUsesDoor = array_values(array_intersect($frontDoors, $out[$caller] ?? []));
		$findings[]   = [
			'kind'     => 'bypass',
			'severity' => $alsoUsesDoor ? 'medium' : 'low',
			'caller'   => $caller,
			'target'   => $x,
			'via'      => $alsoUsesDoor[0] ?? $frontDoors[0],
			'line'     => $edges[$caller . '>' . $x]['line'] ?? 0,
			'note'     => $alsoUsesDoor
				? sprintf('Uses %s and also reaches the %s underneath it.', $classes[$alsoUsesDoor[0]]['short'], $cx['short'])
				: sprintf('%s wraps %s, but this caller goes to %s directly.', $classes[$frontDoors[0]]['short'], $cx['short'], $cx['short']),
		];
	}
}

// 3. Entry points that skip the service layer and talk to storage.
foreach ($classes as $c => $cc) {
	if (!in_array($cc['role'], ['action', 'cli', 'mcp', 'middleware'], true)) {
		continue;
	}
	foreach (array_unique($out[$c] ?? []) as $d) {
		if (($classes[$d]['role'] ?? '') === 'repository') {
			$findings[] = [
				'kind'     => 'layer',
				'severity' => 'low',
				'caller'   => $c,
				'target'   => $d,
				'via'      => null,
				'line'     => $edges[$c . '>' . $d]['line'] ?? 0,
				'note'     => sprintf('A %s talks to %s without a service in between.', $cc['role'] === 'cli' ? 'CLI command' : $cc['role'], $classes[$d]['short']),
			];
		}
	}
}

// 4. Service locator: pulling from the container outside wiring code.
foreach ($serviceLocator as [$c, $target, $line]) {
	$cc = $classes[$c] ?? null;
	if ($cc === null || str_contains($cc['short'], 'Factory') || in_array($cc['area'], ['Infrastructure', 'Composer'], true)
		|| str_starts_with($cc['path'], 'Domain\\Extension')) {
		continue;
	}
	$findings[] = [
		'kind'     => 'locator',
		'severity' => 'low',
		'caller'   => $c,
		'target'   => $target,
		'via'      => null,
		'line'     => $line,
		'note'     => 'Pulled from the container at runtime instead of injected, so it is invisible in the constructor.',
	];
}

$sevRank = ['high' => 0, 'medium' => 1, 'low' => 2];
usort($findings, fn ($a, $b) => [$sevRank[$a['severity']], $a['kind'], $a['target'], $a['caller']] <=> [$sevRank[$b['severity']], $b['kind'], $b['target'], $b['caller']]);
$findings = array_values(array_unique($findings, SORT_REGULAR));

// ---------------------------------------------------------------- output

$data = [
	'generated' => date('Y-m-d H:i'),
	'commit'    => trim((string)shell_exec('git -C ' . escapeshellarg($root) . ' rev-parse --short HEAD 2>/dev/null')),
	'surfaces'  => $surfaces,
	'entries'   => $entries,
	'classes'   => $classes,
	'edges'     => array_values(array_map(fn ($e) => [$e['from'], $e['to'], implode(',', $e['types']), array_keys($calls[$e['from'] . '>' . $e['to']] ?? [])], $edges)),
	'routes'    => $routes,
	'modules'   => MODULES,
	'findings'  => $findings,
];

if (!is_dir($outDir)) {
	mkdir($outDir, 0o755, true);
}
$json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
file_put_contents($outDir . '/data.json', $json);
// The template is a page body: artifact.html publishes as-is (the host adds
// the document shell), index.html wraps it so it opens straight from disk.
$page = str_replace('/*__DATA__*/null', $json, (string)file_get_contents(__DIR__ . '/architecture-map.html'));
file_put_contents($outDir . '/artifact.html', $page);
file_put_contents($outDir . '/index.html', "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n</head>\n<body>\n" . $page . "\n</body>\n</html>\n");

$counts = array_count_values(array_column($findings, 'kind'));
fwrite(STDERR, sprintf(
	"%d classes, %d edges, %d routes. Findings: %s\nWrote %s/index.html\n",
	count($classes),
	count($edges),
	count($routes),
	json_encode($counts),
	$outDir,
));
if (in_array('--json', $argv, true)) {
	echo json_encode($findings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
}
