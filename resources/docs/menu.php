<?php

/**
 * Total CMS documentation menu structure.
 *
 * Consumed by:
 *   - src/Action/Admin/AdminDocsAction.php — passes to admin/docs.twig
 *   - bin/build-docs-index.php             — builds path→group lookup for search
 *
 * Two shapes are supported per top-level group:
 *   1. Flat:   ['title' => 'Group', 'sub' => [['title' => 'Page', 'path' => 'foo/bar'], ...]]
 *   2. Nested: ['title' => 'Group', 'groups' => [['title' => 'Subgroup', 'sub' => [...]], ...]]
 *
 * Currently only "Managing Content" uses the nested shape — promote others to
 * nested when their flat sub-list grows beyond ~25 items.
 */

return [
	[
		'title' => 'Start Here',
		'sub'   => [
			['title' => 'Getting Started', 'path' => 'getting-started'],
			['title' => 'Requirements',    'path' => 'requirements'],
			['title' => 'Installation',    'path' => 'installation'],
		],
	],
	[
		'title' => 'Core Concepts',
		'sub'   => [
			['title' => 'Data Model',       'path' => 'advanced/data-model'],
			['title' => 'Schema Reference', 'path' => 'schemas/reference'],
			['title' => 'Twig Overview',    'path' => 'twig/overview'],
			['title' => 'CMS Variables',    'path' => 'twig/variables'],
			['title' => 'CMS Content',      'path' => 'twig/totalcms'],
		],
	],
	[
		'title' => 'Building Pages',
		'sub'   => [
			['title' => 'Site Builder Overview',  'path' => 'builder/overview'],
			['title' => 'Builder CLI',            'path' => 'builder/cli'],
			['title' => 'Builder Admin UI',       'path' => 'builder/admin'],
			['title' => 'Starter Templates',      'path' => 'builder/starters'],
			['title' => 'Frontend Assets',        'path' => 'builder/frontend'],
			['title' => 'Builder Twig Reference', 'path' => 'twig/builder'],
			['title' => 'Templates',              'path' => 'twig/templates'],
			['title' => 'Collections in Twig',    'path' => 'twig/collections'],
			['title' => 'Collection Filtering',   'path' => 'twig/collection-filtering'],
			['title' => 'Data',                   'path' => 'twig/data'],
			['title' => 'Media',                  'path' => 'twig/media'],
			['title' => 'Render',                 'path' => 'twig/render'],
			['title' => 'ImageWorks',             'path' => 'twig/imageworks'],
			['title' => 'CMS Grid Tag',           'path' => 'twig/cmsgrid-tag'],
			['title' => 'Load More',              'path' => 'twig/load-more'],
			['title' => 'Object Linking',         'path' => 'twig/object-linking'],
			['title' => 'Views',                  'path' => 'twig/views'],
		],
	],
	[
		'title'  => 'Managing Content',
		'groups' => [
			[
				'title' => 'Admin',
				'sub'   => [
					['title' => 'Dashboard',   'path' => 'admin/dashboard'],
					['title' => 'White Label', 'path' => 'admin/whitelabel'],
				],
			],
			[
				'title' => 'Collection Setup',
				'sub'   => [
					['title' => 'Collection Settings',    'path' => 'collections/settings'],
					['title' => 'Form Settings',          'path' => 'collections/form-settings'],
					['title' => 'Data Views',             'path' => 'collections/data-views'],
					['title' => 'Importing Data',         'path' => 'collections/import'],
					['title' => 'Exporting Data',         'path' => 'collections/export'],
					['title' => 'Sitemap Builder',        'path' => 'advanced/sitemap-builder'],
					['title' => 'Pushover Notifications', 'path' => 'notifications/pushover'],
				],
			],
			[
				'title' => 'Forms in Twig',
				'sub'   => [
					['title' => 'Admin Twig',            'path' => 'twig/admin'],
					['title' => 'Forms Overview',        'path' => 'twig/forms/overview'],
					['title' => 'Form Builder',          'path' => 'twig/forms/builder'],
					['title' => 'Deck Forms',            'path' => 'twig/forms/deck'],
					['title' => 'Field Settings',        'path' => 'twig/forms/fields'],
					['title' => 'Form Options',          'path' => 'twig/forms/options'],
					['title' => 'Select & List Options', 'path' => 'twig/forms/select-options'],
					['title' => 'Validation Patterns',   'path' => 'twig/forms/patterns'],
					['title' => 'Report Form',           'path' => 'twig/forms/report'],
					['title' => 'Specialized Forms',     'path' => 'twig/forms/specialized'],
					['title' => 'Forms Reference',       'path' => 'twig/forms'],
				],
			],
			[
				'title' => 'Schemas',
				'sub'   => [
					['title' => 'Schemas (Twig)',    'path' => 'twig/schemas'],
					['title' => 'Schema Validation', 'path' => 'schemas/validation'],
					['title' => 'Form Grid Layout',  'path' => 'schemas/formgrid'],
				],
			],
			[
				'title' => 'Field Settings',
				'sub'   => [
					['title' => 'All Fields',            'path' => 'property-settings/all-fields'],
					['title' => 'Card Field',            'path' => 'property-settings/card'],
					['title' => 'Code Editor Field',     'path' => 'property-settings/code-editor'],
					['title' => 'Date Field',            'path' => 'property-settings/date'],
					['title' => 'Deck Field',            'path' => 'property-settings/deck'],
					['title' => 'File & Depot Field',    'path' => 'property-settings/file-depot'],
					['title' => 'ID Field',              'path' => 'property-settings/id'],
					['title' => 'Image & Gallery Field', 'path' => 'property-settings/image-gallery'],
					['title' => 'Lists Field',           'path' => 'property-settings/lists'],
					['title' => 'Number & Range Field',  'path' => 'property-settings/number-range'],
					['title' => 'Password Field',        'path' => 'property-settings/password'],
					['title' => 'Price Field',           'path' => 'property-settings/price'],
					['title' => 'Radio & Multicheckbox', 'path' => 'property-settings/radio-multicheckbox'],
					['title' => 'Secret Field',          'path' => 'property-settings/secret'],
					['title' => 'Select Field',          'path' => 'property-settings/select'],
					['title' => 'Styled Text Field',     'path' => 'property-settings/styled-text'],
					['title' => 'SVG Field',             'path' => 'property-settings/svg'],
					['title' => 'Text Inputs Field',     'path' => 'property-settings/text-inputs'],
				],
			],
			[
				'title' => 'Field Options',
				'sub'   => [
					['title' => 'Static Options',       'path' => 'property-options/static-options'],
					['title' => 'Property Options',     'path' => 'property-options/property-options'],
					['title' => 'Relational Options',   'path' => 'property-options/relational-options'],
					['title' => 'Sorting Options',      'path' => 'property-options/sorting-options'],
					['title' => 'Access Group Options', 'path' => 'property-options/access-group-options'],
				],
			],
		],
	],
	[
		'title' => 'Auth & Access',
		'sub'   => [
			['title' => 'Authentication', 'path' => 'auth/auth'],
			['title' => 'Access Groups',  'path' => 'auth/access-groups'],
			['title' => 'Password Reset', 'path' => 'auth/password-reset'],
			['title' => 'Auth Twig',      'path' => 'twig/auth'],
		],
	],
	[
		'title' => 'APIs & Integrations',
		'sub'   => [
			['title' => 'REST API',     'path' => 'api/rest-api'],
			['title' => 'PHP API',      'path' => 'api/php-api'],
			['title' => 'API Keys',     'path' => 'api/api-keys'],
			['title' => 'Index Filter', 'path' => 'api/index-filter'],
			['title' => 'Download API', 'path' => 'api/download'],
			['title' => 'OpenAPI Docs', 'path' => 'api/openapi'],
		],
	],
	[
		'title' => 'Extending T3',
		'sub'   => [
			['title' => 'Extensions Overview', 'path' => 'extensions/overview'],
			['title' => 'Manifest',            'path' => 'extensions/manifest'],
			['title' => 'Extension Points',    'path' => 'extensions/extension-points'],
			['title' => 'Events',              'path' => 'extensions/events'],
			['title' => 'Schemas',             'path' => 'extensions/schemas'],
			['title' => 'Bundled Extensions',  'path' => 'extensions/bundled'],
			['title' => '— A/B Split',         'path' => 'extensions/bundled/ab-split'],
			['title' => '— Geo Redirect',      'path' => 'extensions/bundled/geo-redirect'],
			['title' => 'CLI',                 'path' => 'advanced/cli'],
			['title' => 'AI Integration',      'path' => 'advanced/ai-integration'],
			['title' => 'Edition Helpers',     'path' => 'twig/edition'],
		],
	],
	[
		'title' => 'Reference',
		'sub'   => [
			['title' => 'Twig Filters',   'path' => 'twig/filters'],
			['title' => 'Twig Functions', 'path' => 'twig/functions'],
			['title' => 'Conditionals',   'path' => 'twig/conditionals'],
			['title' => 'Markdown',       'path' => 'twig/markdown'],
			['title' => 'Factory',        'path' => 'twig/factory'],
			['title' => 'Locale',         'path' => 'twig/locale'],
			['title' => 'Localization',   'path' => 'twig/localization'],
			['title' => 'Barcodes',       'path' => 'twig/barcodes'],
			['title' => 'QR Codes',       'path' => 'twig/qrcodes'],
			['title' => 'Utilities',      'path' => 'twig/utils'],
			['title' => 'Configuration',  'path' => 'advanced/configuration'],
		],
	],
	[
		'title' => 'Operations',
		'sub'   => [
			['title' => 'Deployment',        'path' => 'advanced/deployment'],
			['title' => 'Nginx',             'path' => 'advanced/nginx'],
			['title' => 'Security',          'path' => 'advanced/security'],
			['title' => 'Server Sizing',     'path' => 'advanced/server-sizing'],
			['title' => 'Filesystem',        'path' => 'advanced/filesystem'],
			['title' => 'Sync',              'path' => 'advanced/sync'],
			['title' => 'Updates',           'path' => 'advanced/updates'],
			['title' => 'JumpStart',         'path' => 'advanced/jumpstart'],
			['title' => 'Search Backends',   'path' => 'advanced/search'],
			['title' => 'Licenses',          'path' => 'advanced/licenses'],
			['title' => 'Migration from v1', 'path' => 'advanced/migration-total-cms-one'],
		],
	],
];
