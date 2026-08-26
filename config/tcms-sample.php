<?php

declare(strict_types=1);

// Total CMS Bootstrap Configuration
// Only path settings that are needed before data directory is loaded
//
// NOTE: Most settings should be configured via the Admin UI (Settings page).
// This file should only contain bootstrap settings that are needed before
// the data directory is loaded.
return [
	// Data directory location
	// Default: document root + /tcms-data
	//
	// Uncomment to customize the data directory location:
	// 'datadir' => __DIR__ . '/tcms-data',

	// URL prefix this install is mounted at. Auto-detected per request, which
	// is right for almost everyone. Pin it only for a subfolder install that
	// also answers at the domain root (the optional root catch-all rewrite) —
	// otherwise OAuth/MCP discovery advertises a different issuer depending on
	// which shape the request came in through. Empty string = domain root.
	// 'api' => '/rw_common/plugins/stacks/tcms',
];
