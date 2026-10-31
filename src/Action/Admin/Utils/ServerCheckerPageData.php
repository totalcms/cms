<?php

declare(strict_types=1);

namespace TotalCMS\Action\Admin\Utils;

use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Domain\Security\Data\SecurityContext;
use TotalCMS\Domain\Security\Service\SecurityAuditor;
use TotalCMS\Domain\Security\Service\SecurityReportStore;
use TotalCMS\Support\Config;

/** The Server Checker utility: runs a fresh security audit and stores it for the dashboard alert. */
final readonly class ServerCheckerPageData implements UtilsPageData
{
	public function __construct(
		private SecurityAuditor $auditor,
		private SecurityReportStore $reports,
		private Config $config,
	) {
	}

	public function build(ServerRequestInterface $request, string $page, string $action): array
	{
		$report = $this->auditor->audit(SecurityContext::fromRequest($request, $this->config));
		$this->reports->save($report);

		return ['securityReport' => $report];
	}
}
