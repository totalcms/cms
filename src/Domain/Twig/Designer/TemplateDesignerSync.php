<?php

namespace TotalCMS\Domain\Twig\Designer;

use TotalCMS\Domain\License\Data\EditionFeature;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Template\Data\TemplatePath;
use TotalCMS\Domain\Template\Service\TemplateSaver;
use TotalCMS\Support\Config;
use TotalCMS\Support\HttpClientInterface;

/**
 * Handles template syncing for the Template Designer system.
 *
 * When a {% templatedesigner %} block is rendered:
 * - On dev (URLs differ): saves locally + PUTs to production, returns badge HTML
 * - On production (URLs match): returns empty string (no-op, zero overhead)
 */
class TemplateDesignerSync
{
	public function __construct(
		private readonly Config $config,
		private readonly TemplateSaver $templateSaver,
		private readonly TemplateDesignerRegistry $registry,
		private readonly EditionFeatureService $editionFeatures,
		private readonly HttpClientInterface $httpClient,
	) {
	}

	/**
	 * Sync a designer block and return badge HTML.
	 *
	 * The `on` parameter in {% templatedesigner %} provides just the domain
	 * (e.g., 'https://example.com'). The API path is appended automatically.
	 */
	public function sync(string $registryKey): string
	{
		// Templates feature requires Standard+ edition
		if (!$this->editionFeatures->can(EditionFeature::TEMPLATES)) {
			return '';
		}

		$block = $this->registry->get($registryKey);

		// No block data = production with warm cache, return empty
		if ($block === null) {
			return '';
		}

		$templatePath      = $block['template'];
		$productionDomain  = rtrim($block['domain'], '/');
		$token             = $block['token'];
		$content           = $block['content'];
		$currentDomain     = rtrim($this->config->domain, '/');
		// Production uses clean API paths; strip /public/index.php which only appears on local PHP CLI dev servers
		$cleanApi          = (string)preg_replace('#/public/index\.php$#', '', $this->config->api);
		$productionApi     = $productionDomain . '/' . ltrim($cleanApi, '/');

		// If domains match, this IS production — no sync needed
		if ($currentDomain === $productionDomain) {
			return '';
		}

		// Dev environment: sync locally and to production
		$localError   = '';
		$remoteError  = '';
		$localStatus  = $this->syncLocal($templatePath, $content, $localError);
		$remoteStatus = $token !== '' ? $this->syncRemote($productionApi, $templatePath, $token, $content, $remoteError) : 'skipped';

		return $this->renderBadge($templatePath, $content, $localStatus, $localError, $remoteStatus, $remoteError);
	}

	/**
	 * Save template content locally.
	 */
	private function syncLocal(string $templatePath, string $content, string &$error): string
	{
		try {
			[$folder, $name] = TemplatePath::parse($templatePath);
			$this->templateSaver->saveTemplate($name, $content, $folder);

			return 'ok';
		} catch (\Exception $e) {
			$error = $e->getMessage();

			return 'error';
		}
	}

	/**
	 * PUT template content to production server.
	 */
	private function syncRemote(string $productionUrl, string $templatePath, string $token, string $content, string &$error): string
	{
		$url = $productionUrl . '/designer/templates/' . $templatePath;

		try {
			$response = $this->httpClient->request('PUT', $url, [
				'body'            => $content,
				'timeout'         => 3,
				'connect_timeout' => 2,
				'headers'         => [
					'X-Designer-Token: ' . $token,
					'Content-Type: text/plain',
				],
			]);
		} catch (\RuntimeException $e) {
			$error = $e->getMessage() !== '' ? $e->getMessage() : 'Connection failed';

			return 'error';
		}

		if ($response->statusCode !== 200) {
			$body  = trim($response->body);
			$error = "HTTP {$response->statusCode}";
			if ($body !== '') {
				$error .= ': ' . mb_substr($body, 0, 200);
			}

			return 'error';
		}

		return 'ok';
	}

	/**
	 * Render the designer badge HTML with sync status.
	 */
	private function renderBadge(string $templatePath, string $content, string $localStatus, string $localError, string $remoteStatus, string $remoteError): string
	{
		$escapedContent = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
		$localIcon      = $localStatus === 'ok' ? '&#10003;' : '&#10007;';
		$remoteIcon     = match ($remoteStatus) {
			'ok'      => '&#10003;',
			'skipped' => '&#8212;',
			default   => '&#10007;',
		};
		$remoteLabel = match ($remoteStatus) {
			'ok'      => 'synced',
			'skipped' => 'skipped',
			default   => 'failed',
		};

		$localTooltip  = $localError !== '' ? ' title="' . htmlspecialchars($localError, ENT_QUOTES, 'UTF-8') . '"' : '';
		$remoteTooltip = $remoteError !== '' ? ' title="' . htmlspecialchars($remoteError, ENT_QUOTES, 'UTF-8') . '"' : '';

		return <<<HTML
<style>
.tcms-designer-badge{position:fixed;bottom:1rem;right:1rem;z-index:999999;background:#1a1a2e;color:#e0e0e0;border-radius:8px;padding:12px 16px;font-family:-apple-system,BlinkMacSystemFont,sans-serif;font-size:13px;box-shadow:0 4px 12px rgba(0,0,0,0.3);max-width:320px;line-height:1.4}
.tcms-designer-header{display:flex;align-items:center;gap:6px;font-weight:600;margin-bottom:6px;color:#a78bfa}
.tcms-designer-template{font-family:monospace;font-size:12px;color:#93c5fd;margin-bottom:6px}
.tcms-designer-status{display:flex;gap:12px;font-size:12px;margin-bottom:8px}
.tcms-designer-status span{display:flex;align-items:center;gap:4px}
.tcms-designer-ok{color:#86efac}
.tcms-designer-err{color:#fca5a5;cursor:help}
.tcms-designer-skip{color:#94a3b8}
.tcms-designer-copy{background:#374151;color:#e0e0e0;border:1px solid #4b5563;border-radius:4px;padding:4px 10px;font-size:12px;cursor:pointer;width:100%}
.tcms-designer-copy:hover{background:#4b5563}
</style>
<div class="tcms-designer-badge" data-template="{$escapedContent}">
<div class="tcms-designer-header"><span>&#9881;</span><span>Template Designer</span></div>
<div class="tcms-designer-template">{$templatePath}</div>
<div class="tcms-designer-status">
<span class="tcms-designer-{$localStatus}"{$localTooltip}>Local: {$localIcon}</span>
<span class="tcms-designer-{$this->statusClass($remoteStatus)}"{$remoteTooltip}>Remote: {$remoteIcon} {$remoteLabel}</span>
</div>
<button class="tcms-designer-copy" onclick="navigator.clipboard.writeText(this.closest('.tcms-designer-badge').dataset.template).then(()=>{this.textContent='Copied!';setTimeout(()=>{this.textContent='Copy Template'},2000)})">Copy Template</button>
</div>
HTML;
	}

	private function statusClass(string $status): string
	{
		return match ($status) {
			'ok'      => 'ok',
			'skipped' => 'skip',
			default   => 'err',
		};
	}
}
