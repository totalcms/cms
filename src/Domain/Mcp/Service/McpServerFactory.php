<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Service;

use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Resource\SubscriptionManagerInterface;
use Mcp\Server\Session\SessionStoreInterface;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Mcp\Auth\Data\McpPersona;
use TotalCMS\Domain\Mcp\Auth\Service\PersonaContext;
use TotalCMS\Domain\Mcp\Prompt\Data\PromptData;
use TotalCMS\Domain\Mcp\Prompt\Service\PromptDiscoveryService;
use TotalCMS\Domain\Mcp\Prompt\Service\PromptRegistrar;
use TotalCMS\Domain\Mcp\Resource\Service\ResourceRegistry;
use TotalCMS\Domain\Mcp\Tool\Data\McpToolDefinition;
use TotalCMS\Domain\Mcp\Tool\Service\SchemaToolRegistrar;
use TotalCMS\Domain\Mcp\Tool\Service\ToolRegistry;
use TotalCMS\Domain\OAuth\Service\OAuthActivityLogger;
use TotalCMS\Domain\OAuth\Service\OAuthScopeRegistry;
use TotalCMS\Support\Config;
use TotalCMS\Support\Version;

/**
 * Builds a configured mcp/sdk Server for a given persona.
 *
 * The factory is the single integration point between T3 and the MCP SDK. It
 * filters the ToolRegistry and ResourceRegistry by persona so the tools/list
 * and resources/list responses never leak admin-only surface to an anonymous
 * caller, and wires the SDK to T3's logger, session storage, and server
 * metadata.
 *
 * A new Server is built per request because the registered tool/resource
 * surface depends on the resolved persona. The construction is cheap —
 * registries are already populated at container build time.
 *
 * Session storage and logger are constructed in the container and injected so
 * the factory stays narrow: given deps, build a configured Server.
 */
readonly class McpServerFactory
{
	public function __construct(
		private ToolRegistry $toolRegistry,
		private ResourceRegistry $resourceRegistry,
		private SubscriptionManagerInterface $subscriptionManager,
		private Config $config,
		private SessionStoreInterface $sessionStore,
		private LoggerInterface $logger,
		private SchemaToolRegistrar $schemaToolRegistrar,
		private PromptDiscoveryService $promptDiscoveryService,
		private PromptRegistrar $promptRegistrar,
		private ExtensionManager $extensions,
		private PersonaContext $personaContext,
		private OAuthScopeRegistry $scopeRegistry,
		private OAuthActivityLogger $activityLogger,
	) {
	}

	public function build(McpPersona $persona, bool $toolsOnly = false): Server
	{
		$readOnlyDefault = new ToolAnnotations(readOnlyHint: true);

		$builder = Server::builder()
			->setServerInfo(
				name: $this->config->displayName(),
				version: Version::number(),
				description: 'Total CMS site exposed as an MCP server.',
			)
			->setInstructions(
				'Total CMS site exposed via the Model Context Protocol. '
				. 'Discovery: list_collections returns collections with their filterable fields. '
				. 'Tools: query_collection / get_object / search_collection for collection content; '
				. 'admin tools (schema_*, template_*, get_site_info, clear_cache) require an API key. '
				. 'Resources: tcms://{collection}/ for collection summaries, tcms://{collection}/{id} for objects — '
				. 'reachable via resources/read or the get_resource tool. '
				. 'Drafts are hidden from anonymous callers. '
				. 'search / fetch are provided for ChatGPT and deep-research clients: search(query) returns {id,title,url}; fetch(id) returns the full document. '
				. 'Tool descriptions describe their inputs and outputs.'
			)
			->setSession($this->sessionStore)
			->setLogger($this->logger);

		// Forward MCP handler/SDK errors to Sentry. The SDK swallows every
		// Throwable into a JSON-RPC error response without rethrowing, so
		// without this hook tool bugs reach only mcp-activity.log, never Sentry.
		// Gated on config.sentry so a Sentry-disabled install keeps its exact
		// protocol surface — setting any SDK event dispatcher flips the
		// advertised listChanged capabilities to true.
		if ($this->config->sentry) {
			$builder->setEventDispatcher(new McpSentryErrorDispatcher());
		}

		// Resource subscriptions: when enabled, swap in T3's manager so subscribe/
		// unsubscribe also writes to the reverse URI→sessionIds index that the
		// McpResourceSubscriptionListener queries on object.* events. When the
		// kill switch is off the SDK falls back to its per-session-only default
		// — clients can still call resources/subscribe but no cross-session
		// push happens.
		// ChatGPT-compatibility tools-only mode. When $toolsOnly is set the server
		// advertises a tools-only capability set — no resources, no prompts, no
		// subscriptions — the minimal surface ChatGPT's connector importer accepts
		// (it rejects servers that advertise `resources` / `prompts`). The caller
		// (McpEndpointAction) sets this PER REQUEST from the client's identity:
		// ChatGPT/OpenAI clients get tools-only, everyone else (Claude, Cursor, …)
		// keeps the full surface. See ToolsOnlyClients.
		if (!$toolsOnly && ($this->config->mcp['subscriptionsEnabled'] ?? true) !== false) {
			$builder->setResourceSubscriptionManager($this->subscriptionManager);
		}

		// Auto-register schema-defined tools from all collection meta files.
		// Called once per request lifecycle. The registry is request-scoped so
		// previous-request schema tools don't carry over; if build() is ever
		// called twice on the same factory instance the second pass would
		// see the first pass's tools as collisions and skip them — don't do that.
		$this->schemaToolRegistrar->register($this->toolRegistry);

		foreach ($this->toolRegistry->forPersona($persona, $this->personaContext->getAuthority()) as $tool) {
			// Persona-aware tools (Phase 1 content tools) expose a builder that
			// renders a per-persona description — e.g., the field catalog must
			// only list collections the caller can actually see. Static-string
			// tools (Phase 0 SiteInfoTool, admin tools) leave the builder unset
			// and use $tool->description verbatim.
			$description = $tool->descriptionBuilder !== null
				? ($tool->descriptionBuilder)($persona)
				: $tool->description;

			// Per-tool annotations win over the default. Destructive admin tools
			// (delete_schema, clear_cache) MUST opt out of the read-only default
			// — mandatory before Anthropic Directory submission.
			$annotations = $tool->annotations ?? $readOnlyDefault;

			$builder->addTool(
				handler: $this->guardHandler($tool),
				name: $this->resolveToolName($tool),
				description: $description,
				annotations: $annotations,
				inputSchema: $tool->inputSchema,
				outputSchema: $tool->outputSchema,
			);
		}

		// Tools-only mode stops here — registering no resources or prompts means
		// the SDK omits those capabilities from the initialize handshake.
		if ($toolsOnly) {
			return $builder->build();
		}

		// $this->personaContext->getAuthority() mirrors the ToolRegistry call
		// above (Task 10): an AUTHENTICATED caller's resolved UserAuthority
		// additionally narrows collection-scoped resources to what their
		// access groups grant `read` on, so resources/list matches the
		// call-time gate CollectionResource::read() enforces.
		foreach ($this->resourceRegistry->forPersona($persona, $this->personaContext->getAuthority()) as $resource) {
			$builder->addResource(
				handler: $resource->handler,
				uri: $resource->uri,
				name: $resource->name,
				description: $resource->description,
				mimeType: $resource->mimeType,
			);
		}

		foreach ($this->resourceRegistry->templatesForPersona($persona, $this->personaContext->getAuthority()) as $template) {
			$builder->addResourceTemplate(
				handler: $template->handler,
				uriTemplate: $template->uriTemplate,
				name: $template->name,
				description: $template->description,
				mimeType: $template->mimeType,
			);
		}

		// Register prompts (Phase 5 Chunk B).
		// discover() returns cached results on subsequent calls within one request.
		// The persona filter hides inaccessible prompts from prompts/list; the
		// handler closure in PromptRegistrar re-checks at prompts/get call time
		// to guard against name-guessing by lower-privilege callers.
		$prompts = $this->promptDiscoveryService->discover();
		$prompts = $this->filterPromptsForPersona($prompts, $persona);
		$this->promptRegistrar->registerAll($builder, $prompts, $persona);

		// Extension-registered prompts (Phase 5 Chunk C).
		// These are code-defined prompts shipped by extensions using
		// ExtensionContext::registerMcpPrompt(). They use the SDK's Mcp\Schema\Prompt
		// type directly (not PromptData) and take a separate registration path.
		// Collision policy: soft-deny — collection-stored prompts always win.
		// If a name is already registered, the SDK throws on addPrompt(); we catch
		// that and log a warning instead of crashing the server build.
		// H5 fix: extension prompts are filtered by persona using the same access
		// logic as collection-stored prompts (PromptRegistrar::personaCanAccess).
		// The handler is also wrapped to re-check access at call time so a
		// lower-privilege caller who guesses a prompt name via prompts/get is denied.
		$collectionPromptNames = array_flip(array_map(static fn (PromptData $p): string => $p->name, $prompts));
		foreach ($this->extensions->getAllMcpPrompts() as $extensionId => $registrations) {
			foreach ($registrations as $reg) {
				$name   = $reg['prompt']->name;
				$access = $reg['access'];

				// Persona filter — hide prompts the current persona cannot access.
				if (!PromptRegistrar::personaCanAccess($persona, $access)) {
					continue;
				}

				if (isset($collectionPromptNames[$name])) {
					$this->logger->warning('Extension MCP prompt skipped: name collides with collection-stored prompt', [
						'extension' => $extensionId,
						'prompt'    => $name,
					]);
					continue;
				}
				try {
					$builder->addPrompt(
						handler: $this->buildExtensionPromptHandler($reg['handler'], $persona, $name, $access),
						name: $name,
						description: $reg['prompt']->description ?? '',
					);
				} catch (\LogicException $e) {
					$this->logger->warning('Extension MCP prompt registration failed', [
						'extension' => $extensionId,
						'prompt'    => $name,
						'error'     => $e->getMessage(),
					]);
				}
			}
		}

		return $builder->build();
	}

	/**
	 * Wraps $tool->handler with the call-time access-group + scope guard for
	 * requirement-bearing tools (McpToolDefinition::$requires, Task 6).
	 *
	 * Task 6 only gated tools/list VISIBILITY — an AUTHENTICATED caller whose
	 * groups satisfy the requirement for AT LEAST ONE target sees the tool
	 * (isSatisfiedForAny()). This is the enforcement half: re-check the
	 * requirement against the SPECIFIC target the caller is invoking with,
	 * on every call, using isSatisfiedFor() — being visible for "some
	 * collection" never implies authorized for "this collection". When
	 * $tool->requires is null this is a no-op — returns $tool->handler
	 * completely unchanged, so tools without a requirement (all of them
	 * until Task 8) pay zero overhead and behave exactly as before this
	 * task landed.
	 *
	 * ADMIN and PUBLIC_ personas skip the guard body: ADMIN's UserAuthority
	 * short-circuits every can*() check anyway, and PUBLIC_ callers never
	 * see a requirement-bearing tool in tools/list in the first place
	 * (McpToolDefinition::isVisibleTo() only offers the $requires branch to
	 * AUTHENTICATED) — there's no reachable path that calls a
	 * requirement-bearing tool as PUBLIC_ to defend against.
	 *
	 * --- Argument-binding note (see task-7-report.md for the full write-up) ---
	 * A naive wrapper — e.g. `fn (...$args) => ...` or `fn (array $args) =>
	 * ...` registered directly as the tool's handler — silently breaks the
	 * SDK's named-argument binding. Mcp\Capability\Registry\ReferenceHandler
	 * ::prepareArguments() builds the call by reflecting on the HANDLER
	 * closure's OWN parameter list and looking each name up in the JSON-RPC
	 * argument bag; reflecting on a generic wrapper finds parameters named
	 * `args`/`arguments`, which never match real tool argument names like
	 * `collection`, so the inner call would receive defaults/nulls instead
	 * of the caller's actual input.
	 *
	 * The fix reuses an escape hatch the SDK itself relies on: when a
	 * registered handler Closure's *closure scope class* is
	 * ReferenceHandler::class, `ReferenceHandler::handle()` skips reflection
	 * entirely and invokes it with the raw argument array (see
	 * vendor/mcp/sdk/src/Capability/Registry/ReferenceHandler.php:39-43).
	 * The SDK's own ExplicitElementLoader uses exactly this trick
	 * (`Closure::bind($closure, null, ReferenceHandler::class)`) to hand
	 * ToolHandlerInterface-based tools the raw bag. We do the same here: the
	 * returned closure is bound to that scope, so it receives $arguments
	 * untouched (including `_session`/`_request`). On the allowed path we
	 * then dispatch to the REAL $tool->handler via a fresh ReferenceHandler
	 * + ElementReference — i.e. we replay the exact reflection-based,
	 * type-casting dispatch the SDK would have used with no guard installed
	 * at all, so per-tool argument binding (including inputSchema-declared
	 * types) is byte-for-byte unchanged. Proven in
	 * tests/Feature/McpToolGuardTest.php, which asserts a wrapped handler
	 * receives its arguments intact.
	 */
	private function guardHandler(McpToolDefinition $tool): \Closure
	{
		$requires = $tool->requires;
		if ($requires === null) {
			return $tool->handler;
		}

		$innerHandler     = $tool->handler;
		$toolName         = $tool->name;
		$isPublicReadTool = $tool->access === 'public' && $requires->domain === 'objects' && $requires->operation === 'read';
		$personaContext   = $this->personaContext;
		$scopeRegistry    = $this->scopeRegistry;
		$activityLogger   = $this->activityLogger;

		$wrapped = static function (array $arguments) use ($requires, $innerHandler, $toolName, $isPublicReadTool, $personaContext, $scopeRegistry, $activityLogger): mixed {
			$persona = $personaContext->current();

			// The objects+read + access:'public' combination (Task 10b —
			// query_collection/get_object/search_collection) stays FULLY open
			// to PUBLIC_, unguarded by $requires — mirrors
			// McpToolDefinition::isVisibleTo()'s PUBLIC_ carve-out (see its
			// docblock for the full rationale: $access:'public' IS the entire
			// grant for an anonymous caller on a READ tool; "public" has never
			// meant "writable/administrable by everyone", so this does NOT
			// extend to $access:'public' tools with any OTHER requirement
			// shape). Falls through to the real handler dispatch at the
			// bottom, completely unguarded by $requires — exactly as an
			// access:'public' tool with no $requires at all would behave; the
			// real handler still applies its own mcp.access exposure check.
			//
			// For every other tool (any requirement shape that ISN'T
			// objects+read+public), PUBLIC_ is denied outright. Defense in
			// depth: McpToolDefinition::isVisibleTo() already keeps such a
			// tool off the registered SDK surface for PUBLIC_ callers, so
			// this branch should be unreachable in practice. It stays here
			// anyway: PUBLIC_ has neither an OAuth token (no scopes) nor a
			// resolved UserAuthority, so if a misconfigured tool or a future
			// registration path ever slipped one through, falling into the
			// AUTHENTICATED branch below would either throw on a null
			// authority (fine) or — if that branch is ever loosened —
			// silently dispatch unguarded (not fine). Fail closed explicitly
			// instead of relying on that branch's incidental behavior.
			if ($persona === McpPersona::PUBLIC_ && !$isPublicReadTool) {
				$activityLogger->scopeRejected($personaContext->getClientId(), 'tools/call:' . $toolName, $personaContext->getScopes());

				throw new ToolCallException(sprintf(
					'%s requires an authenticated caller.',
					$toolName,
				));
			}

			if ($persona === McpPersona::AUTHENTICATED) {
				$clientId = $personaContext->getClientId();

				// Layer 1 — scope (consent). expand() lets a broader granted
				// scope (cms:admin) satisfy a narrower requirement
				// (cms:read/cms:write) via the implies graph, mirroring the
				// REST Bearer scope mapping.
				$requiredScope = $requires->requiredScope();
				if (!in_array($requiredScope, $scopeRegistry->expand($personaContext->getScopes()), true)) {
					$activityLogger->scopeRejected($clientId, 'tools/call:' . $toolName, $personaContext->getScopes());

					throw new ToolCallException(sprintf(
						'This connection was not granted the %s permission.',
						$requiredScope,
					));
				}

				// Layer 2 — access-group authority for the SPECIFIC target.
				// Fail closed when PersonaContext never resolved an
				// authority for this Bearer request.
				$authority = $personaContext->getAuthority();
				if ($authority === null) {
					$activityLogger->groupRejected($clientId, $toolName, $personaContext->getUserId());

					throw new ToolCallException(sprintf(
						"Your account's groups do not grant %s.",
						$requires->operation,
					));
				}

				if ($requires->collectionArg !== null) {
					$target = $arguments[$requires->collectionArg] ?? null;
					if (!is_string($target) || $target === '') {
						$activityLogger->groupRejected($clientId, $toolName, $personaContext->getUserId());

						throw new ToolCallException(sprintf(
							"Your account's groups do not grant %s: no target was specified.",
							$requires->operation,
						));
					}
					// objects+read (Task 10b) routes through PersonaContext::
					// canReadCollection() instead of ToolRequirement::isSatisfiedFor()
					// — the single home for the "mcp.access:'public' collections stay
					// readable by everyone" carve-out that closes the privilege
					// inversion the Task 10 review found (an AUTHENTICATED caller
					// without a group grant must not be denied where an anonymous
					// caller succeeds). Every other domain/operation keeps the plain
					// group-grant check — that carve-out is read-only content
					// exposure, not a general "public" concept for writes/schemas/etc.
					$satisfied = ($requires->domain === 'objects' && $requires->operation === 'read')
						? $personaContext->canReadCollection($target)
						: $requires->isSatisfiedFor($authority, $target);
				} else {
					$target    = '';
					$satisfied = $requires->isSatisfiedForAny($authority);
				}

				if (!$satisfied) {
					$activityLogger->groupRejected($clientId, $toolName, $personaContext->getUserId());

					throw new ToolCallException(sprintf(
						"Your account's groups do not grant %s on '%s'.",
						$requires->operation,
						$target,
					));
				}
			}

			// Replay the SDK's normal reflection-based dispatch for the real
			// handler — see the argument-binding note above.
			return (new ReferenceHandler())->handle(new ElementReference($innerHandler), $arguments);
		};

		// ReferenceHandler::class always exists, so Closure::bind() cannot
		// fail here — PHPStan's stubs agree the result is always Closure,
		// never null.
		return \Closure::bind($wrapped, null, ReferenceHandler::class);
	}

	/**
	 * Filters the prompt list to those accessible by the given persona.
	 * Fails closed: prompts with unrecognised access values are treated as admin-only.
	 *
	 * @param list<PromptData> $prompts
	 *
	 * @return list<PromptData>
	 */
	private function filterPromptsForPersona(array $prompts, McpPersona $persona): array
	{
		return array_values(array_filter(
			$prompts,
			static fn (PromptData $p): bool => PromptRegistrar::personaCanAccess($persona, $p->access),
		));
	}

	/**
	 * Wraps an extension prompt handler with a runtime access re-check.
	 *
	 * Mirrors the pattern used by PromptRegistrar::buildHandler() for
	 * collection-stored prompts: the handler closure returned here re-checks
	 * personaCanAccess() at invocation time so a caller who guesses an admin-only
	 * prompt name via prompts/get receives a clean MCP error rather than content.
	 * The outer persona filter in build() already prevents the prompt from
	 * appearing in prompts/list, but the call-time guard defends against
	 * name-guessing independently.
	 */
	private function buildExtensionPromptHandler(callable $handler, McpPersona $persona, string $name, string $access): \Closure
	{
		return static function (array $arguments = []) use ($handler, $persona, $name, $access): mixed {
			if (!PromptRegistrar::personaCanAccess($persona, $access)) {
				throw new \Mcp\Exception\PromptGetException(sprintf(
					'Prompt "%s" requires %s access.',
					$name,
					$access,
				));
			}

			return $handler($arguments);
		};
	}

	/**
	 * Final tools/list name for a tool: prefix-prepended unless the tool opts
	 * out via exemptFromPrefix. ChatGPT-compat tools (search/fetch) opt out so
	 * their literal names survive a configured mcp.toolPrefix.
	 */
	public function resolveToolName(McpToolDefinition $tool): string
	{
		if ($tool->exemptFromPrefix) {
			return $tool->name;
		}

		return $this->toolNamePrefix() . $tool->name;
	}

	/**
	 * Resolves the optional tool-name prefix from config. Operators running
	 * multiple T3 sites in one AI agent can set `mcp.toolPrefix` to namespace
	 * each site's tools (e.g. `bistro` → `bistro_list_collections`). Returns
	 * the prefix with a trailing underscore, or empty string if unset.
	 *
	 * Validates against the same snake_case regex as the settings schema —
	 * invalid values silently fall back to empty so a misconfigured setting
	 * can't break the endpoint.
	 */
	private function toolNamePrefix(): string
	{
		$prefix = trim((string)($this->config->mcp['toolPrefix'] ?? ''));
		if ($prefix === '') {
			return '';
		}

		if (!preg_match('/^[a-z][a-z0-9_]{0,23}$/', $prefix)) {
			return '';
		}

		return $prefix . '_';
	}

	public function protocolVersion(): string
	{
		// Sourced from the SDK so the discovery document can never drift from
		// the version the transport actually negotiates.
		return \Mcp\Schema\JsonRpc\MessageInterface::PROTOCOL_VERSION->value;
	}

	/**
	 * Records the connecting MCP client's self-reported identity in the
	 * mcp-activity log on initialize. Used to recognise specific clients (e.g.
	 * ChatGPT) when diagnosing or tuning compatibility. `clientInfo` is
	 * informational per the spec, so this is for observability only — never
	 * behaviour gating.
	 */
	public function logClientInfo(string $name, string $version): void
	{
		$this->logger->info('MCP client connected', [
			'client'  => $name !== '' ? $name : '(unknown)',
			'version' => $version,
		]);
	}
}
