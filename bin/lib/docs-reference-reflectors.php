<?php

declare(strict_types=1);

/**
 * Source-level reflection that feeds resources/docs/reference-index.json's
 * `twig_functions` (cms.* namespace) and `extension_api` sections.
 *
 * Ported from mcp.totalcms.co's bin/reflect-twig-functions.php and
 * bin/reflect-extension-api.php, merged into one file per the docs-mcp
 * extension plan. Those scripts reflected an EXTERNAL totalcms checkout
 * passed in as a CLI path argument ($totalcmsPath). This generator now lives
 * INSIDE the codebase it reflects, so every reflected class is resolved
 * relative to this file's location — no vendored-path resolution, no path
 * argument threading.
 *
 * Code is the source of truth for *what exists*. Docs (parsed separately in
 * docs-reference-parsers.php) are the source of truth for examples and richer
 * prose — when reflection and docs both have a method, the entry merges
 * signature/description from reflection with examples from docs.
 */

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use phpDocumentor\Reflection\DocBlockFactory;

/**
 * Repo root, resolved once relative to this file (bin/lib/ -> bin/ -> root).
 */
function referenceRepoRoot(): string
{
	return dirname(__DIR__, 2);
}

// =======================================================
// cms.* Twig function reflection
// =======================================================

/**
 * Methods on TotalCMSTwigAdapter that shouldn't be exposed as twig functions —
 * they're internal plumbing (request handling, asset collection, magic dispatch).
 */
const TWIG_SKIP_ROOT_METHODS = [
	'__construct',
	'__call',
	'log',                // Internal — twig templates shouldn't be logging.
	'addFrontendAssets',  // Setters used by extensions during boot, not by templates.
	'addAdminAssets',
];

/**
 * Property names on TotalCMSTwigAdapter that don't map to a sub-adapter we
 * want to reflect — they're scalar runtime values (env, base, etc.) rather
 * than namespaces with methods.
 */
const TWIG_SKIP_ROOT_PROPERTIES = [
	'env', 'base', 'api', 'dashboard', 'login', 'domain',
	'clearcache', 'version', 'currentUrl',
];

/**
 * Docs URL prefix for each sub-namespace. Not every adapter has a 1:1 doc
 * file; this map captures the conventions we use. Unknown namespaces fall
 * back to the generic /twig/ index.
 */
const TWIG_NAMESPACE_DOCS = [
	'form'       => 'https://docs.totalcms.co/forms/builder/',
	'license'    => 'https://docs.totalcms.co/twig/edition/',
	'edition'    => 'https://docs.totalcms.co/twig/edition/',
	'render'     => 'https://docs.totalcms.co/twig/render/',
	'view'       => 'https://docs.totalcms.co/twig/views/',
	'schema'     => 'https://docs.totalcms.co/schemas/twig/',
	'auth'       => 'https://docs.totalcms.co/auth/twig/',
	'data'       => 'https://docs.totalcms.co/twig/data/',
	'media'      => 'https://docs.totalcms.co/twig/media/',
	'collection' => 'https://docs.totalcms.co/twig/collections/',
	'admin'      => 'https://docs.totalcms.co/admin/twig/',
	'builder'    => 'https://docs.totalcms.co/site-builder/twig/',
	'locale'     => 'https://docs.totalcms.co/twig/locale/',
	'utils'      => 'https://docs.totalcms.co/twig/utils/',
];

/**
 * Reflect TotalCMSTwigAdapter and emit every public twig-callable method,
 * qualified with its cms.* namespace.
 *
 * @return list<array{name: string, signature: string, description: string, url: string}>
 */
function reflectCmsTwigFunctions(): array
{
	$totalcmsPath = referenceRepoRoot();
	$rootFile = $totalcmsPath . '/src/Domain/Twig/Adapter/TotalCMSTwigAdapter.php';
	if (!is_file($rootFile)) {
		throw new RuntimeException("TotalCMSTwigAdapter source not found at {$rootFile}");
	}

	$functions = [];

	// 1. Top-level cms.* methods (config, etc.)
	$rootClass = parseClassFromFile($rootFile, 'TotalCMSTwigAdapter');
	foreach (publicMethodsOf($rootClass, TWIG_SKIP_ROOT_METHODS) as $method) {
		$functions[] = formatTwigFunction(
			namespace: 'cms',
			method: $method,
			url: 'https://docs.totalcms.co/twig/totalcms/',
		);
	}

	// 2. cms.<namespace>.* methods from each sub-adapter property on the constructor.
	$ctor = constructorOf($rootClass);
	if ($ctor === null) {
		return $functions;
	}

	foreach ($ctor->params as $param) {
		if (($param->flags & Modifiers::PUBLIC) === 0) {
			continue; // Only public promoted properties.
		}
		$propName = $param->var->name;
		if (in_array($propName, TWIG_SKIP_ROOT_PROPERTIES, true)) {
			continue;
		}
		if (!$param->type instanceof Node\Name && !$param->type instanceof Node\Identifier) {
			continue;
		}
		$adapterClass = $param->type instanceof Node\Name ? $param->type->toString() : $param->type->name;
		$adapterShort = basename(str_replace('\\', '/', $adapterClass));

		$adapterFile = $totalcmsPath . '/src/Domain/Twig/Adapter/' . $adapterShort . '.php';
		if (!is_file($adapterFile)) {
			// Sub-adapter from a different namespace (e.g. TotalFormFactory under Admin/).
			$adapterFile = findClassFile($totalcmsPath . '/src', $adapterShort);
		}
		if ($adapterFile === null || !is_file($adapterFile)) {
			continue;
		}

		$adapterClassNode = parseClassFromFile($adapterFile, $adapterShort);
		$url = TWIG_NAMESPACE_DOCS[$propName] ?? 'https://docs.totalcms.co/twig/';

		foreach (publicMethodsOf($adapterClassNode, ['__construct', '__call']) as $method) {
			$functions[] = formatTwigFunction(
				namespace: 'cms.' . $propName,
				method: $method,
				url: $url,
			);
		}
	}

	// Sort for stable output.
	usort($functions, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

	return $functions;
}

/**
 * @param list<string> $skip
 * @return list<Node\Stmt\ClassMethod>
 */
function publicMethodsOf(Node\Stmt\Class_ $class, array $skip): array
{
	$methods = [];
	foreach ($class->getMethods() as $method) {
		if (!$method->isPublic() || $method->isStatic()) {
			continue;
		}
		if (in_array($method->name->toString(), $skip, true)) {
			continue;
		}
		$methods[] = $method;
	}
	return $methods;
}

function constructorOf(Node\Stmt\Class_ $class): ?Node\Stmt\ClassMethod
{
	foreach ($class->getMethods() as $method) {
		if ($method->name->toString() === '__construct') {
			return $method;
		}
	}
	return null;
}

/**
 * @return array{name: string, signature: string, description: string, url: string}
 */
function formatTwigFunction(string $namespace, Node\Stmt\ClassMethod $method, string $url): array
{
	$shortName = $method->name->toString();
	return [
		'name'        => $namespace . '.' . $shortName,
		'signature'   => formatMethodSignature($method),
		'description' => extractMethodDescription($method, DocBlockFactory::createInstance()),
		'url'         => $url,
	];
}

/**
 * Recursively search src/ for a file whose class name matches $shortName.
 * Used for adapter types that live outside src/Domain/Twig/Adapter/
 * (e.g. TotalFormFactory under Admin/).
 */
function findClassFile(string $searchDir, string $shortName): ?string
{
	$target = $shortName . '.php';
	$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($searchDir));
	foreach ($iterator as $file) {
		if ($file->isFile() && $file->getFilename() === $target) {
			return $file->getPathname();
		}
	}
	return null;
}

/**
 * Merge reflection-derived twig functions with docs-parsed entries.
 *
 * Reflection is the canonical existence check (code IS the truth about
 * what twig can call). Docs supply richer descriptions and example
 * snippets where they're written. Doc-only entries (i.e. methods that
 * exist in markdown but not in code) are returned separately as a drift
 * report — they're stale documentation.
 *
 * @param list<array{name:string,signature:string,description:string,url:string}> $reflected
 * @param list<array{name:string,signature:string,description:string,examples:string[],url:string}> $documented
 * @return array{0: list<array<string,mixed>>, 1: list<string>} [merged entries, names of stale doc-only entries]
 */
function mergeTwigFunctions(array $reflected, array $documented): array
{
	$docsByName = [];
	foreach ($documented as $entry) {
		$docsByName[$entry['name']] = $entry;
	}

	$merged = [];
	foreach ($reflected as $entry) {
		$doc = $docsByName[$entry['name']] ?? null;
		if ($doc !== null) {
			$merged[] = [
				'name'        => $entry['name'],
				'signature'   => $entry['signature'],
				// Prefer docs description if reflection's docblock summary is empty.
				'description' => $entry['description'] !== '' ? $entry['description'] : ($doc['description'] ?? ''),
				'examples'    => $doc['examples'] ?? [],
				'url'         => $doc['url'] ?? $entry['url'],
			];
			unset($docsByName[$entry['name']]);
		} else {
			$merged[] = [
				'name'        => $entry['name'],
				'signature'   => $entry['signature'],
				'description' => $entry['description'],
				'examples'    => [],
				'url'         => $entry['url'],
			];
		}
	}

	// Anything left in $docsByName is documented but doesn't exist in code → stale.
	$stale = array_values(array_keys($docsByName));
	sort($stale);

	return [$merged, $stale];
}

// =======================================================
// Extension API reflection
// =======================================================

/**
 * Methods we want to skip when emitting context_methods — internal collectors
 * used by ExtensionManager, not part of the extension-facing API.
 */
const REFLECTION_SKIP_METHODS = [
	'__construct',
	'getCapabilities',
	'capabilityLabels',
];

/**
 * Phase + permission mapping for each public method. Auto-derived data
 * (signature, description) doesn't tell us which lifecycle phase a method
 * belongs to or which permission it grants. This small table fills that gap.
 *
 * When a new public method is added to ExtensionContext, the build will
 * surface it with phase='register' and no permission unless an entry is
 * added here — the worst case is the AI agent gets a method listed without
 * its permission tag, not a missing method.
 */
const METHOD_METADATA = [
	// Identity / lifecycle (both phases)
	'extensionId'             => ['phase' => 'both',     'permission' => null],
	'extensionPath'           => ['phase' => 'both',     'permission' => null],
	'manifest'                => ['phase' => 'both',     'permission' => null],
	'settings'                => ['phase' => 'both',     'permission' => 'settings:read'],
	'setting'                 => ['phase' => 'both',     'permission' => null],
	'logger'                  => ['phase' => 'both',     'permission' => null],

	// Service resolution (boot only)
	'get'                     => ['phase' => 'boot',     'permission' => null],
	'has'                     => ['phase' => 'boot',     'permission' => null],
	'installSchema'           => ['phase' => 'boot',     'permission' => 'schemas'],

	// Registration (register only)
	'addTwigFunction'         => ['phase' => 'register', 'permission' => 'twig:functions'],
	'addTwigFilter'           => ['phase' => 'register', 'permission' => 'twig:filters'],
	'addTwigGlobal'           => ['phase' => 'register', 'permission' => 'twig:globals'],
	'addCommand'              => ['phase' => 'register', 'permission' => 'cli:commands'],
	'addRoutes'               => ['phase' => 'register', 'permission' => 'routes:api'],
	'addPublicRoutes'         => ['phase' => 'register', 'permission' => 'routes:public'],
	'addAdminRoutes'          => ['phase' => 'register', 'permission' => 'routes:admin'],
	'addAdminNavItem'         => ['phase' => 'register', 'permission' => 'admin:nav'],
	'addDashboardWidget'      => ['phase' => 'register', 'permission' => 'admin:widgets'],
	'addAdminAsset'           => ['phase' => 'register', 'permission' => 'admin:assets'],
	'addFrontendAsset'        => ['phase' => 'register', 'permission' => 'frontend:assets'],
	'addFieldType'            => ['phase' => 'register', 'permission' => 'fields'],
	'addEventListener'        => ['phase' => 'register', 'permission' => 'events:listen'],
	'addContainerDefinition'  => ['phase' => 'register', 'permission' => 'container'],
	'addPageMiddleware'       => ['phase' => 'register', 'permission' => 'page-middleware'],
];

/**
 * Reflect the public methods on T3's ExtensionContext class.
 *
 * @return list<array{name: string, signature: string, description: string, phase: string, permission: string|null}>
 */
function reflectContextMethods(): array
{
	$file = referenceRepoRoot() . '/src/Domain/Extension/ExtensionContext.php';
	if (!is_file($file)) {
		throw new RuntimeException("ExtensionContext source not found at {$file}");
	}

	$classNode = parseClassFromFile($file, 'ExtensionContext');
	$docBlockFactory = DocBlockFactory::createInstance();
	$methods = [];

	foreach ($classNode->getMethods() as $method) {
		if (!$method->isPublic()) {
			continue;
		}
		$name = $method->name->toString();
		if (in_array($name, REFLECTION_SKIP_METHODS, true)) {
			continue;
		}
		if (str_starts_with($name, 'getRegistered')) {
			// Internal collectors used by ExtensionManager — not extension-facing.
			continue;
		}

		$signature   = formatMethodSignature($method);
		$description = extractMethodDescription($method, $docBlockFactory);
		$metadata    = METHOD_METADATA[$name] ?? ['phase' => 'register', 'permission' => null];

		$methods[] = [
			'name'        => $name,
			'signature'   => $signature,
			'description' => $description,
			'phase'       => $metadata['phase'],
			'permission'  => $metadata['permission'],
		];
	}

	return $methods;
}

/**
 * Reflect ExtensionContext::capabilityLabels() — extract the array literal
 * returned by the static method so we have an authoritative permissions list.
 *
 * @return list<array{id: string, description: string}>
 */
function reflectCapabilityLabels(): array
{
	$file = referenceRepoRoot() . '/src/Domain/Extension/ExtensionContext.php';
	if (!is_file($file)) {
		throw new RuntimeException("ExtensionContext source not found at {$file}");
	}

	$classNode = parseClassFromFile($file, 'ExtensionContext');
	$labelsMethod = null;
	foreach ($classNode->getMethods() as $method) {
		if ($method->name->toString() === 'capabilityLabels') {
			$labelsMethod = $method;
			break;
		}
	}
	if ($labelsMethod === null) {
		throw new RuntimeException('capabilityLabels() not found on ExtensionContext');
	}

	$arrayNode = null;
	$finder = new NodeFinder();
	$return = $finder->findFirstInstanceOf((array) $labelsMethod->stmts, Node\Stmt\Return_::class);
	if ($return && $return->expr instanceof Node\Expr\Array_) {
		$arrayNode = $return->expr;
	}
	if ($arrayNode === null) {
		throw new RuntimeException('capabilityLabels() does not return an array literal we can parse');
	}

	$permissions = [];
	foreach ($arrayNode->items as $item) {
		if (!$item instanceof Node\Expr\ArrayItem) {
			continue;
		}
		if (!$item->key instanceof Node\Scalar\String_) {
			continue;
		}
		$permissions[] = [
			'id'          => $item->key->value,
			'description' => $item->value instanceof Node\Scalar\String_ ? $item->value->value : '',
		];
	}

	return $permissions;
}

/**
 * Parse a PHP file and find a specific class declaration in it.
 */
function parseClassFromFile(string $file, string $shortName): Node\Stmt\Class_
{
	$source = (string) file_get_contents($file);
	$parser = (new ParserFactory())->createForHostVersion();
	$ast = $parser->parse($source) ?? [];

	$finder = new NodeFinder();
	$class = $finder->findFirst($ast, function (Node $node) use ($shortName) {
		return $node instanceof Node\Stmt\Class_
			&& $node->name !== null
			&& $node->name->toString() === $shortName;
	});

	if (!$class instanceof Node\Stmt\Class_) {
		throw new RuntimeException("Class {$shortName} not found in {$file}");
	}

	return $class;
}

/**
 * Build a human-readable signature like `addFoo(string $bar, int $baz = 0): void`.
 */
function formatMethodSignature(Node\Stmt\ClassMethod $method): string
{
	$params = [];
	foreach ($method->params as $param) {
		$type = $param->type ? renderType($param->type) . ' ' : '';
		$prefix = $param->byRef ? '&' : ($param->variadic ? '...' : '');
		$name = '$' . $param->var->name;
		$default = '';
		if ($param->default !== null) {
			$default = ' = ' . renderExpr($param->default);
		}
		$params[] = $type . $prefix . $name . $default;
	}

	$return = $method->returnType ? ': ' . renderType($method->returnType) : '';
	return $method->name->toString() . '(' . implode(', ', $params) . ')' . $return;
}

/**
 * Render a type AST node back to a readable string (best-effort).
 */
function renderType(Node $type): string
{
	if ($type instanceof Node\Identifier || $type instanceof Node\Name) {
		return $type->toString();
	}
	if ($type instanceof Node\NullableType) {
		return '?' . renderType($type->type);
	}
	if ($type instanceof Node\UnionType) {
		return implode('|', array_map('renderType', $type->types));
	}
	if ($type instanceof Node\IntersectionType) {
		return implode('&', array_map('renderType', $type->types));
	}
	return 'mixed';
}

/**
 * Render an expression AST node (used for default param values).
 */
function renderExpr(Node\Expr $expr): string
{
	if ($expr instanceof Node\Scalar\String_) {
		return "'" . $expr->value . "'";
	}
	if ($expr instanceof Node\Scalar\Int_) {
		return (string) $expr->value;
	}
	if ($expr instanceof Node\Scalar\Float_) {
		return (string) $expr->value;
	}
	if ($expr instanceof Node\Expr\ConstFetch) {
		return $expr->name->toString();
	}
	if ($expr instanceof Node\Expr\Array_) {
		return '[]';
	}
	return '...';
}

/**
 * Reflect the Edition enum's cases and level() values.
 *
 * @return list<array{edition: string, level: int, description: string}>
 */
function reflectEditions(): array
{
	$file = referenceRepoRoot() . '/src/Domain/License/Data/Edition.php';
	if (!is_file($file)) {
		throw new RuntimeException("Edition enum not found at {$file}");
	}

	$source = (string) file_get_contents($file);
	$parser = (new ParserFactory())->createForHostVersion();
	$ast = $parser->parse($source) ?? [];
	$finder = new NodeFinder();

	/** @var Node\Stmt\Enum_|null $enum */
	$enum = $finder->findFirstInstanceOf($ast, Node\Stmt\Enum_::class);
	if ($enum === null) {
		throw new RuntimeException("No enum found in {$file}");
	}

	// Build case-name => level map by walking the level() match expression.
	$levels = extractEditionLevels($enum);

	$descriptions = [
		'lite'        => 'Basic edition, available to all',
		'standard'    => 'Standard features including custom collections',
		'pro'         => 'Full features including custom schemas and extensions schemas',
		'enterprise'  => 'Pro features plus enterprise support',
		'development' => 'Developer license for local/staging use',
		'trial'       => 'Time-limited evaluation license',
		'unknown'     => 'No license detected',
	];

	$editions = [];
	foreach ($enum->stmts as $stmt) {
		if (!$stmt instanceof Node\Stmt\EnumCase) {
			continue;
		}
		$value = $stmt->expr instanceof Node\Scalar\String_ ? $stmt->expr->value : strtolower($stmt->name->toString());
		$editions[] = [
			'edition'     => $value,
			'level'       => $levels[$stmt->name->toString()] ?? 0,
			'description' => $descriptions[$value] ?? "Edition: {$value}",
		];
	}

	return $editions;
}

/**
 * Walk Edition::level()'s match expression and map each case identifier to its int.
 *
 * @return array<string,int>
 */
function extractEditionLevels(Node\Stmt\Enum_ $enum): array
{
	$levels = [];
	foreach ($enum->getMethods() as $method) {
		if ($method->name->toString() !== 'level') {
			continue;
		}
		$finder = new NodeFinder();
		/** @var Node\Expr\Match_|null $match */
		$match = $finder->findFirstInstanceOf((array) $method->stmts, Node\Expr\Match_::class);
		if ($match === null) {
			continue;
		}
		foreach ($match->arms as $arm) {
			if (!$arm->body instanceof Node\Scalar\Int_) {
				continue;
			}
			$level = $arm->body->value;
			foreach ((array) $arm->conds as $cond) {
				if ($cond instanceof Node\Expr\ClassConstFetch && $cond->name instanceof Node\Identifier) {
					$levels[$cond->name->toString()] = $level;
				}
			}
		}
	}
	return $levels;
}

/**
 * Reflect the ExtensionManifest constructor's parameters, pairing each with
 * its @param docblock description. The "required" flag is hand-mapped — the
 * constructor signature alone doesn't tell us which fields a user must
 * actually supply (fromArray() applies defaults for almost everything).
 *
 * @return list<array{field: string, required: bool, description: string}>
 */
function reflectManifestFields(): array
{
	$file = referenceRepoRoot() . '/src/Domain/Extension/Data/ExtensionManifest.php';
	if (!is_file($file)) {
		throw new RuntimeException("ExtensionManifest source not found at {$file}");
	}

	$classNode = parseClassFromFile($file, 'ExtensionManifest');
	$ctor = null;
	foreach ($classNode->getMethods() as $method) {
		if ($method->name->toString() === '__construct') {
			$ctor = $method;
			break;
		}
	}
	if ($ctor === null) {
		throw new RuntimeException('ExtensionManifest has no constructor');
	}

	// Pull @param descriptions out of the constructor's docblock.
	$paramDocs = [];
	$docComment = $ctor->getDocComment();
	if ($docComment !== null) {
		try {
			$docBlock = DocBlockFactory::createInstance()->create($docComment->getText());
			foreach ($docBlock->getTagsByName('param') as $tag) {
				if ($tag instanceof \phpDocumentor\Reflection\DocBlock\Tags\Param) {
					$paramDocs[$tag->getVariableName()] = cleanDocblockText((string) $tag->getDescription());
				}
			}
		} catch (\Throwable) {
			// Fall back to empty descriptions.
		}
	}

	// Required fields are those the system actually needs — id/name/version.
	// Internal fields (set by discovery, not by the manifest author) are skipped.
	$required = ['id' => true, 'name' => true, 'version' => true];
	$skip = ['bundled' => true]; // ExtensionDiscovery sets this, not the JSON

	$fields = [];
	foreach ($ctor->params as $param) {
		$name = $param->var->name;
		if (isset($skip[$name])) {
			continue;
		}
		$jsonKey = camelToSnake($name); // settingsSchema -> settings_schema
		$fields[] = [
			'field'       => $jsonKey,
			'required'    => isset($required[$jsonKey]),
			'description' => $paramDocs[$name] ?? '',
		];
	}

	return $fields;
}

/**
 * Reflect the bundled extensions that ship in resources/extensions/.
 * Reads each extension.json so adding a new bundled extension auto-shows
 * up in the reference index without a code change here.
 *
 * @return list<array{id: string, name: string, description: string, version: string, url: string}>
 */
function reflectBundledExtensions(): array
{
	$baseDir = referenceRepoRoot() . '/resources/extensions';
	if (!is_dir($baseDir)) {
		return [];
	}

	$found = [];
	foreach (glob($baseDir . '/*/*/extension.json') ?: [] as $manifestFile) {
		$decoded = json_decode((string) file_get_contents($manifestFile), true);
		if (!is_array($decoded) || empty($decoded['id'])) {
			continue;
		}
		$shortName = basename(dirname($manifestFile));
		$found[] = [
			'id'          => (string) $decoded['id'],
			'name'        => (string) ($decoded['name'] ?? $shortName),
			'description' => (string) ($decoded['description'] ?? ''),
			'version'     => (string) ($decoded['version'] ?? ''),
			'url'         => 'https://docs.totalcms.co/extensions/' . $shortName . '/',
		];
	}

	// Sort by id for stable output.
	usort($found, fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

	return $found;
}

/**
 * Parse content events from the docs file. Each event is an H3 with the
 * event name in backticks, followed by a description paragraph and a
 * payload table.
 *
 * @return list<array{name: string, description: string, payload: array<string,string>}>
 */
function parseEventsFromDocs(): array
{
	$file = referenceRepoRoot() . '/resources/docs/extensions/events.md';
	if (!is_file($file)) {
		return [];
	}

	$source = (string) file_get_contents($file);
	// Strip frontmatter.
	$source = (string) preg_replace('/^---\s*\n.*?\n---\s*\n/s', '', $source);

	$events = [];
	// Each H3 starting with a backticked name introduces an event.
	$sections = preg_split('/^###\s+`([^`]+)`\s*\n/m', $source, -1, PREG_SPLIT_DELIM_CAPTURE);

	// preg_split returns [pre-content, name1, body1, name2, body2, ...]
	for ($i = 1; $i < count($sections); $i += 2) {
		$name = trim($sections[$i]);
		$body = $sections[$i + 1] ?? '';

		// Stop at the next H1/H2/H3 (only this section's body).
		$end = preg_match('/^#{1,3}\s/m', $body, $m, PREG_OFFSET_CAPTURE);
		if ($end === 1) {
			$body = substr($body, 0, $m[0][1]);
		}

		// First non-empty paragraph is the description.
		$description = '';
		foreach (explode("\n\n", trim($body)) as $paragraph) {
			$paragraph = trim($paragraph);
			if ($paragraph === '' || str_starts_with($paragraph, '|') || str_starts_with($paragraph, '```')) {
				continue;
			}
			$description = (string) preg_replace('/\s+/', ' ', $paragraph);
			break;
		}

		// Payload table rows: | `key` | `type` | description |
		$payload = [];
		if (preg_match_all('/\|\s*`(\w+)`\s*\|\s*`([^`]+)`\s*\|\s*[^|\n]+\|/m', $body, $rows, PREG_SET_ORDER)) {
			foreach ($rows as $row) {
				$payload[$row[1]] = $row[2];
			}
		}

		$events[] = [
			'name'        => $name,
			'description' => $description,
			'payload'     => $payload,
		];
	}

	return $events;
}

/**
 * camelCase -> snake_case helper used to translate ExtensionManifest
 * constructor params (settingsSchema) to their JSON keys (settings_schema).
 */
function camelToSnake(string $input): string
{
	return strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1_$2', $input));
}

/**
 * Pull a one-line description out of the method's docblock — uses summary
 * when present, falls back to the first sentence of the long description.
 */
function extractMethodDescription(Node\Stmt\ClassMethod $method, DocBlockFactory $factory): string
{
	$docComment = $method->getDocComment();
	if ($docComment === null) {
		return '';
	}

	try {
		$docBlock = $factory->create($docComment->getText());
	} catch (\Throwable) {
		return '';
	}

	$summary = trim($docBlock->getSummary());
	$description = trim((string) $docBlock->getDescription());

	// If summary is short, append the first paragraph of the long description
	// for context. Caps at the first ~250 chars so AI tooltip blurbs stay scannable.
	$blurb = $summary;
	if ($blurb !== '' && $description !== '' && mb_strlen($blurb) < 80) {
		$firstParagraph = explode("\n\n", $description)[0];
		$blurb .= ' ' . $firstParagraph;
	} elseif ($blurb === '' && $description !== '') {
		$blurb = explode("\n\n", $description)[0];
	}

	return cleanDocblockText($blurb);
}

/**
 * Strip inline phpDoc tags, normalize whitespace, and trim.
 */
function cleanDocblockText(string $text): string
{
	// {@see Foo\Bar::method()} -> Foo\Bar::method()
	$text = (string) preg_replace('/\{@(?:see|link)\s+([^}]+)\}/', '$1', $text);
	// Any other {@tag ...} -> drop entirely
	$text = (string) preg_replace('/\{@\w+\s*[^}]*\}/', '', $text);
	// Collapse runs of whitespace (including newlines) to a single space
	$text = (string) preg_replace('/\s+/', ' ', $text);
	return trim($text);
}
