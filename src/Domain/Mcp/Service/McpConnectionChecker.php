<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Mcp\Service;

use TotalCMS\Domain\Mcp\Data\McpCheckResult;
use TotalCMS\Support\Config;
use TotalCMS\Support\HttpClientInterface;

/**
 * Outbound self-probes that verify the MCP surface as external AI clients
 * see it. Born from a live incident: a customer's Cloudflare zone blocked
 * the `Claude-User` user-agent, so claude.ai authenticated via OAuth and
 * then failed on the token exchange with a generic client error nobody
 * could diagnose from the T3 side.
 *
 * Every probe is wrapped: a thrown transport error becomes `unreachable`
 * ("couldn't test") — never `fail`. Shared hosts commonly can't resolve
 * their own public domain from PHP, and a red X on a healthy site is worse
 * than no checker at all.
 *
 * Probe catalogue (ids are stable — the settings partial and ServerChecker
 * key off them):
 *  - endpoint       discovery doc + JSON-RPC initialize on the advertised endpoint
 *  - ai_agents      same initialize with Claude-User / ChatGPT-User UAs; 403
 *                   while the default UA passes = WAF/CDN blocking AI clients
 *  - bearer_header  initialize with a garbage Bearer token; expected 401 —
 *                   a 200 means Apache stripped Authorization before PHP
 *                   (see AuthorizationHeaderMiddleware) or validation is off
 *  - root_rewrite   subpath installs only: is /.well-known/mcp.json reachable
 *                   at the domain root (the setup wizard's catch-all rules)?
 *  - dual_authority subpath installs with root rewrite: do the two discovery
 *                   docs advertise different endpoints? Fix = pin `api` in tcms.php
 *  - oauth_surface  jwks reachable when oauth.enabled
 */
readonly class McpConnectionChecker
{
	private const TIMEOUT         = ['timeout' => 6, 'connect_timeout' => 3];
	private const AI_AGENTS       = ['Claude-User', 'ChatGPT-User'];
	private const INVALID_BEARER  = 'tcms-connection-check-invalid-token';
	private const STATE_FILE      = 'mcp-check.json';

	private const INITIALIZE_BODY = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"tcms-connection-check","version":"1.0"}}}';

	public function __construct(
		private Config $config,
		private HttpClientInterface $http,
	) {
	}

	/** @return array<int,McpCheckResult> */
	public function run(): array
	{
		$results   = [];
		$results[] = $this->checkEndpoint();
		$results[] = $this->checkAiAgents();
		$results[] = $this->checkBearerHeader();
		$results[] = $this->checkRootRewrite();
		$results[] = $this->checkDualAuthority();
		$results[] = $this->checkOauthSurface();

		$this->persist($results);

		return $results;
	}

	/**
	 * Last persisted run, for ServerChecker display without re-probing.
	 *
	 * @return array{time:int,results:array<int,array<string,string>>}|null
	 */
	public function lastRun(): ?array
	{
		$file = $this->stateFile();
		if (!is_file($file)) {
			return null;
		}
		$data = json_decode((string)file_get_contents($file), true);

		return is_array($data) && isset($data['time'], $data['results'])
			? ['time' => (int)$data['time'], 'results' => (array)$data['results']]
			: null;
	}

	private function checkEndpoint(): McpCheckResult
	{
		$discoveryUrl = rtrim($this->config->url, '/') . $this->config->api . '/.well-known/mcp.json';
		try {
			$discovery = $this->http->request('GET', $discoveryUrl, self::TIMEOUT);
			if ($discovery->statusCode !== 200 || $discovery->json() === null) {
				return new McpCheckResult(
					'endpoint',
					'MCP endpoint',
					'fail',
					"Discovery document at $discoveryUrl answered HTTP {$discovery->statusCode}.",
					'Check the MCP server is enabled and the URL rewrites reach Total CMS.'
				);
			}

			$init = $this->http->request('POST', $this->config->mcpEndpoint(), self::TIMEOUT + [
				'headers' => ['Content-Type: application/json', 'Accept: application/json, text/event-stream'],
				'body'    => self::INITIALIZE_BODY,
			]);
			// This probe sends no credentials, so on a site with anonymous access
			// switched off a 401 is the CORRECT answer, not a fault. Reporting it
			// as a failure — and blaming a WAF — sent a real support case chasing
			// a firewall that did not exist, on a server whose only problem was
			// that it was configured exactly as the operator intended.
			$anonymousDisabled = !(bool)($this->config->mcp['publicAccess'] ?? false);
			if ($init->statusCode === 401 && $anonymousDisabled) {
				return new McpCheckResult(
					'endpoint',
					'MCP endpoint',
					'pass',
					'Discovery answers, and initialize correctly requires credentials — '
					. 'Public Access is off, so an unauthenticated 401 is the expected response.'
				);
			}

			if ($init->statusCode !== 200) {
				return new McpCheckResult(
					'endpoint',
					'MCP endpoint',
					'fail',
					"initialize at {$this->config->mcpEndpoint()} answered HTTP {$init->statusCode}.",
					$init->statusCode === 401
						? 'The endpoint requires credentials but Public Access is on, so anonymous callers should be served. '
							. 'Check whether something upstream is demanding authentication.'
						: 'Check the MCP server is enabled and nothing (maintenance mode, WAF) intercepts the endpoint.'
				);
			}

			return new McpCheckResult(
				'endpoint',
				'MCP endpoint',
				'pass',
				'Discovery and initialize both answer correctly.'
			);
		} catch (\Throwable $e) {
			return $this->unreachable('endpoint', 'MCP endpoint', $e);
		}
	}

	private function checkAiAgents(): McpCheckResult
	{
		$blocked = [];
		try {
			foreach (self::AI_AGENTS as $agent) {
				$response = $this->http->request('POST', $this->config->mcpEndpoint(), self::TIMEOUT + [
					'headers'    => ['Content-Type: application/json', 'Accept: application/json, text/event-stream'],
					'body'       => self::INITIALIZE_BODY,
					'user_agent' => $agent,
				]);
				if (in_array($response->statusCode, [403, 429, 503], true)) {
					$blocked[] = "$agent (HTTP {$response->statusCode})";
				}
			}
		} catch (\Throwable $e) {
			return $this->unreachable('ai_agents', 'AI clients allowed', $e);
		}

		if ($blocked !== []) {
			return new McpCheckResult(
				'ai_agents',
				'AI clients allowed',
				'fail',
				'Requests using AI-client user agents are blocked before reaching Total CMS: ' . implode(', ', $blocked) . '. '
				. 'MCP clients like Claude will authenticate via OAuth and then fail to connect with a generic error.',
				'Your CDN/WAF is blocking AI agents. On Cloudflare: Security → Bots → allow AI bots '
				. '(or add a WAF skip rule for verified bots / these user agents), at least for the MCP, /oauth/*, and /.well-known/* paths.'
			);
		}

		return new McpCheckResult(
			'ai_agents',
			'AI clients allowed',
			'pass',
			'No user-agent-based blocking detected. (An IP/ASN-based WAF rule would not be visible from this server.)'
		);
	}

	private function checkBearerHeader(): McpCheckResult
	{
		try {
			$response = $this->http->request('POST', $this->config->mcpEndpoint(), self::TIMEOUT + [
				'headers' => [
					'Content-Type: application/json',
					'Accept: application/json, text/event-stream',
					'Authorization: Bearer ' . self::INVALID_BEARER,
				],
				'body' => self::INITIALIZE_BODY,
			]);

			// Control request: same call, no Authorization at all.
			//
			// Comparing the two is the only discriminator that holds in every
			// configuration. Judging the Bearer response alone — "401 means the
			// header arrived" — is vacuous on a site with Public Access off,
			// because a stripped header also yields 401 there (login_required
			// instead of invalid_token). That false pass hid a genuinely
			// stripped header on a live site while every other probe agreed
			// nothing was wrong.
			//
			// If the credential reaches PHP the two answers must differ. If they
			// are identical, the header made no difference to the outcome, which
			// means it never arrived.
			$control = $this->http->request('POST', $this->config->mcpEndpoint(), self::TIMEOUT + [
				'headers' => [
					'Content-Type: application/json',
					'Accept: application/json, text/event-stream',
				],
				'body' => self::INITIALIZE_BODY,
			]);
		} catch (\Throwable $e) {
			return $this->unreachable('bearer_header', 'Bearer auth reaches PHP', $e);
		}

		$identical = $response->statusCode === $control->statusCode
			&& $response->body === $control->body;

		if ($identical) {
			return new McpCheckResult(
				'bearer_header',
				'Bearer auth reaches PHP',
				'fail',
				"A request carrying a Bearer token and one carrying none were answered identically (HTTP {$response->statusCode}). "
				. 'The Authorization header is being stripped before PHP (SAPI: ' . PHP_SAPI . '), so OAuth clients authenticate '
				. 'successfully and are then treated as anonymous on every call. OAuth cannot work until this is resolved — '
				. 'the tokens are valid, but no client can present them.',
				'Apache running PHP as CGI/FastCGI drops the Authorization header unless the vhost sets '
				. '"CGIPassAuth On" (2.4.13+) or an .htaccess exports it — and on a subfolder install that must cover '
				. 'every .htaccess the request passes through, not just public/.htaccess. Switching the domain to '
				. 'PHP-FPM avoids the CGI handler entirely. If neither takes effect the host is stripping it above '
				. 'the account level.'
			);
		}

		if ($response->statusCode === 401) {
			return new McpCheckResult(
				'bearer_header',
				'Bearer auth reaches PHP',
				'pass',
				'An invalid Bearer token is rejected differently from an unauthenticated request — '
				. 'the Authorization header reaches Total CMS.'
			);
		}

		return new McpCheckResult(
			'bearer_header',
			'Bearer auth reaches PHP',
			'fail',
			"An invalid Bearer token was answered HTTP {$response->statusCode} instead of 401. "
			. 'The Authorization header is being stripped before PHP (Apache CGI/FastCGI) or Bearer validation is not active — '
			. 'OAuth-authenticated clients silently see only anonymous/public content.',
			'Ensure the shipped public/.htaccess Authorization rewrite idiom is present (added in 3.5.x), '
			. 'or set "CGIPassAuth On" in the vhost.'
		);
	}

	private function checkRootRewrite(): McpCheckResult
	{
		if ($this->config->api === '') {
			return new McpCheckResult(
				'root_rewrite',
				'Root URL rewrite',
				'skip',
				'Install already lives at the domain root.'
			);
		}

		$rootDiscovery = rtrim($this->config->url, '/') . '/.well-known/mcp.json';
		try {
			$response = $this->http->request('GET', $rootDiscovery, self::TIMEOUT);
		} catch (\Throwable $e) {
			return $this->unreachable('root_rewrite', 'Root URL rewrite', $e);
		}

		if ($response->statusCode === 200 && $response->json() !== null) {
			return new McpCheckResult(
				'root_rewrite',
				'Root URL rewrite',
				'pass',
				'The domain root routes unmatched URLs into Total CMS — short URLs and RFC discovery locations work.'
			);
		}

		return new McpCheckResult(
			'root_rewrite',
			'Root URL rewrite',
			'warn',
			"$rootDiscovery is not reachable ({$response->statusCode}). The MCP server still works at its full subpath URL, "
			. 'but /.well-known/* discovery at the domain root (which some MCP clients try first) will 404.',
			'Add the recommended catch-all rules from the setup wizard\'s Server Config step to the site root .htaccess.'
		);
	}

	private function checkDualAuthority(): McpCheckResult
	{
		if ($this->config->api === '') {
			return new McpCheckResult(
				'dual_authority',
				'Single discovery authority',
				'skip',
				'Install already lives at the domain root.'
			);
		}

		$rootUrl    = rtrim($this->config->url, '/') . '/.well-known/mcp.json';
		$subpathUrl = rtrim($this->config->url, '/') . $this->config->api . '/.well-known/mcp.json';
		try {
			$root    = $this->http->request('GET', $rootUrl, self::TIMEOUT);
			$subpath = $this->http->request('GET', $subpathUrl, self::TIMEOUT);
		} catch (\Throwable $e) {
			return $this->unreachable('dual_authority', 'Single discovery authority', $e);
		}

		if ($root->statusCode !== 200 || $subpath->statusCode !== 200) {
			return new McpCheckResult(
				'dual_authority',
				'Single discovery authority',
				'skip',
				'Only one URL shape answers; nothing to compare.'
			);
		}

		$rootEp    = (string)(($root->json() ?? [])['endpoint'] ?? '');
		$subpathEp = (string)(($subpath->json() ?? [])['endpoint'] ?? '');

		if ($rootEp !== '' && $rootEp === $subpathEp) {
			return new McpCheckResult(
				'dual_authority',
				'Single discovery authority',
				'pass',
				'Both URL shapes advertise the same endpoint.'
			);
		}

		// Differing endpoints used to mean a broken connector: the subpath
		// shape led a client to an RFC 8414 §3.1 metadata URL that answered
		// at the domain root and advertised the bare-host issuer, so the
		// issuer never matched the one the client was verifying and OAuth
		// died right after the grant. OAuthDiscoveryAction now answers that
		// URL for the issuer that was queried, which leaves each shape
		// internally consistent — a client that discovers and connects
		// through either one works. So this is reported, not flagged: two
		// working authorities is a preference to settle, not a fault.
		return new McpCheckResult(
			'dual_authority',
			'Single discovery authority',
			'pass',
			"This site answers on two base paths, each advertising its own endpoint ($rootEp vs $subpathEp). "
			. 'Both work, and a client stays on whichever shape it discovered through. '
			. "To standardise on one, pin 'api' — see the Subfolder installs section of the MCP troubleshooting docs "
			. 'for which file that goes in on your install type.'
		);
	}

	private function checkOauthSurface(): McpCheckResult
	{
		if (!(bool)($this->config->oauth['enabled'] ?? true)) {
			return new McpCheckResult('oauth_surface', 'OAuth discovery', 'skip', 'OAuth is disabled.');
		}

		$jwksUrl = rtrim($this->config->url, '/') . $this->config->api . '/.well-known/jwks.json';
		try {
			$jwks = $this->http->request('GET', $jwksUrl, self::TIMEOUT);
		} catch (\Throwable $e) {
			return $this->unreachable('oauth_surface', 'OAuth discovery', $e);
		}

		if ($jwks->statusCode === 200) {
			return new McpCheckResult('oauth_surface', 'OAuth discovery', 'pass', 'JWKS endpoint answers.');
		}

		return new McpCheckResult(
			'oauth_surface',
			'OAuth discovery',
			'fail',
			"$jwksUrl answered HTTP {$jwks->statusCode}.",
			'Run `tcms oauth:setup` to generate keys, or check rewrites for /.well-known/*.'
		);
	}

	private function unreachable(string $id, string $label, \Throwable $e): McpCheckResult
	{
		return new McpCheckResult(
			$id,
			$label,
			'unreachable',
			'Could not test: this server cannot reach its own public URL (' . $e->getMessage() . '). '
			. 'This does NOT mean external clients are affected.',
			'Some hosts block loopback requests to their own domain; test the URLs from another machine.'
		);
	}

	/** @param array<int,McpCheckResult> $results */
	private function persist(array $results): void
	{
		$file = $this->stateFile();
		$dir  = dirname($file);
		if (!is_dir($dir)) {
			return; // system dir missing — persistence is best-effort
		}
		file_put_contents($file, json_encode([
			'time'    => time(),
			'results' => array_map(fn (McpCheckResult $r): array => $r->toArray(), $results),
		], JSON_PRETTY_PRINT));
	}

	private function stateFile(): string
	{
		return $this->config->systemDir() . '/' . self::STATE_FILE;
	}
}
