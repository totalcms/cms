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
 * Top-level groups can also carry both `sub` and `groups`; `sub` entries render
 * before the subgroup disclosures.
 *
 * Folder convention: every doc page's path begins with the kebab-cased name of
 * its top-level group folder (get-started/, collections/, fields/, etc.).
 * Subgroups (e.g. Fields > Field Types) exist only in the menu — the files
 * themselves are flat within the group folder.
 */

return [
	[
		'title' => 'Get Started',
		'sub'   => [
			['title' => 'Welcome',         'path' => 'get-started/welcome'],
			['title' => 'Requirements',    'path' => 'get-started/requirements'],
			['title' => 'Installation',    'path' => 'get-started/installation'],
			['title' => 'Your First Site', 'path' => 'get-started/your-first-site'],
		],
	],
	[
		'title' => 'Collections',
		'sub'   => [
			['title' => 'Collection Settings', 'path' => 'collections/settings'],
			['title' => 'Form Settings',       'path' => 'collections/form-settings'],
			['title' => 'Data Views',          'path' => 'collections/data-views'],
			['title' => 'Importing Data',      'path' => 'collections/import'],
			['title' => 'Exporting Data',      'path' => 'collections/export'],
			['title' => 'Sitemap Builder',     'path' => 'collections/sitemap-builder'],
		],
	],
	[
		'title' => 'Schemas',
		'sub'   => [
			['title' => 'Schema Reference',  'path' => 'schemas/reference'],
			['title' => 'Schema Validation', 'path' => 'schemas/validation'],
			['title' => 'Form Grid Layout',  'path' => 'schemas/formgrid'],
			['title' => 'Schemas in Twig',   'path' => 'schemas/twig'],
		],
	],
	[
		'title'  => 'Fields',
		'groups' => [
			[
				'title' => 'Field Types',
				'sub'   => [
					['title' => 'All Fields',            'path' => 'fields/all-fields'],
					['title' => 'Card',                  'path' => 'fields/card'],
					['title' => 'Code Editor',           'path' => 'fields/code-editor'],
					['title' => 'Date',                  'path' => 'fields/date'],
					['title' => 'Deck',                  'path' => 'fields/deck'],
					['title' => 'File & Depot',          'path' => 'fields/file-depot'],
					['title' => 'ID',                    'path' => 'fields/id'],
					['title' => 'Image & Gallery',       'path' => 'fields/image-gallery'],
					['title' => 'Lists',                 'path' => 'fields/lists'],
					['title' => 'Localized Text',        'path' => 'fields/localized-text'],
					['title' => 'Number & Range',        'path' => 'fields/number-range'],
					['title' => 'Password',              'path' => 'fields/password'],
					['title' => 'Price',                 'path' => 'fields/price'],
					['title' => 'Radio & Multicheckbox', 'path' => 'fields/radio-multicheckbox'],
					['title' => 'Secret',                'path' => 'fields/secret'],
					['title' => 'Select',                'path' => 'fields/select'],
					['title' => 'Styled Text',           'path' => 'fields/styled-text'],
					['title' => 'SVG',                   'path' => 'fields/svg'],
					['title' => 'Text Inputs',           'path' => 'fields/text-inputs'],
				],
			],
			[
				'title' => 'Field Options',
				'sub'   => [
					['title' => 'Static Options',       'path' => 'fields/static-options'],
					['title' => 'Property Options',     'path' => 'fields/property-options'],
					['title' => 'Relational Options',   'path' => 'fields/relational-options'],
					['title' => 'Sorting Options',      'path' => 'fields/sorting-options'],
					['title' => 'Access Group Options', 'path' => 'fields/access-group-options'],
				],
			],
		],
	],
	[
		'title' => 'Site Builder',
		'sub'   => [
			['title' => 'Site Builder Overview',  'path' => 'site-builder/overview'],
			['title' => 'Builder CLI',            'path' => 'site-builder/cli'],
			['title' => 'Builder Admin UI',       'path' => 'site-builder/admin'],
			['title' => 'Starter Templates',     'path' => 'site-builder/starters'],
			['title' => 'Frontend Assets',       'path' => 'site-builder/frontend'],
			['title' => 'Builder Twig Reference', 'path' => 'site-builder/twig'],
		],
	],
	[
		'title'  => 'Twig',
		'groups' => [
			[
				'title' => 'Twig Basics',
				'sub'   => [
					['title' => 'Twig Overview',        'path' => 'twig/overview'],
					['title' => 'CMS Variables',        'path' => 'twig/variables'],
					['title' => 'CMS Content',          'path' => 'twig/totalcms'],
					['title' => 'Data',                 'path' => 'twig/data'],
					['title' => 'Render',               'path' => 'twig/render'],
					['title' => 'Templates',            'path' => 'twig/templates'],
					['title' => 'Conditionals',         'path' => 'twig/conditionals'],
					['title' => 'Markdown',             'path' => 'twig/markdown'],
					['title' => 'Factory',              'path' => 'twig/factory'],
					['title' => 'Collections',          'path' => 'twig/collections'],
					['title' => 'Collection Filtering', 'path' => 'twig/collection-filtering'],
					['title' => 'Object Linking',       'path' => 'twig/object-linking'],
					['title' => 'Views',                'path' => 'twig/views'],
					['title' => 'CMS Grid Tag',         'path' => 'twig/cmsgrid-tag'],
					['title' => 'Load More',            'path' => 'twig/load-more'],
				],
			],
			[
				'title' => 'Filters & Functions',
				'sub'   => [
					['title' => 'Twig Filters',    'path' => 'twig/filters'],
					['title' => 'Twig Functions',  'path' => 'twig/functions'],
					['title' => 'Utilities',       'path' => 'twig/utils'],
					['title' => 'Edition Helpers', 'path' => 'twig/edition'],
					['title' => 'Barcodes',        'path' => 'twig/barcodes'],
					['title' => 'QR Codes',        'path' => 'twig/qrcodes'],
				],
			],
			[
				'title' => 'Media',
				'sub'   => [
					['title' => 'Media',      'path' => 'twig/media'],
					['title' => 'ImageWorks', 'path' => 'twig/imageworks'],
				],
			],
			[
				'title' => 'Internationalization',
				'sub'   => [
					['title' => 'Locale',       'path' => 'twig/locale'],
					['title' => 'Localization', 'path' => 'twig/localization'],
				],
			],
		],
	],
	[
		'title' => 'Forms',
		'sub'   => [
			['title' => 'Forms Overview',      'path' => 'forms/overview'],
			['title' => 'Form Builder',        'path' => 'forms/builder'],
			['title' => 'Deck Forms',          'path' => 'forms/deck'],
			['title' => 'Field Settings',      'path' => 'forms/fields'],
			['title' => 'Form Options',        'path' => 'forms/options'],
			['title' => 'Validation Patterns', 'path' => 'forms/patterns'],
			['title' => 'Report Form',         'path' => 'forms/report'],
			['title' => 'Specialized Forms',   'path' => 'forms/specialized'],
		],
	],
	[
		'title' => 'Admin',
		'sub'   => [
			['title' => 'Dashboard',   'path' => 'admin/dashboard'],
			['title' => 'White Label', 'path' => 'admin/whitelabel'],
			['title' => 'Admin Twig',  'path' => 'admin/twig'],
		],
	],
	[
		'title' => 'Notifications',
		'sub'   => [
			['title' => 'Mailer',   'path' => 'notifications/mailer'],
			['title' => 'Pushover', 'path' => 'notifications/pushover'],
		],
	],
	[
		'title' => 'Auth',
		'sub'   => [
			['title' => 'Authentication', 'path' => 'auth/auth'],
			['title' => 'Access Groups',  'path' => 'auth/access-groups'],
			['title' => 'Password Reset', 'path' => 'auth/password-reset'],
			['title' => 'Auth Twig',      'path' => 'auth/twig'],
		],
	],
	[
		'title' => 'APIs',
		'sub'   => [
			['title' => 'REST API',     'path' => 'apis/rest-api'],
			['title' => 'PHP API',      'path' => 'apis/php-api'],
			['title' => 'API Keys',     'path' => 'apis/api-keys'],
			['title' => 'Index Filter', 'path' => 'apis/index-filter'],
			['title' => 'OpenAPI Docs', 'path' => 'apis/openapi'],
		],
	],
	[
		'title'  => 'Extensions & CLI',
		'sub'    => [
			['title' => 'Extensions Overview', 'path' => 'extensions/overview'],
			['title' => 'Manifest',            'path' => 'extensions/manifest'],
			['title' => 'Extension Points',    'path' => 'extensions/extension-points'],
			['title' => 'Events',              'path' => 'extensions/events'],
			['title' => 'Schemas',             'path' => 'extensions/schemas'],
			['title' => 'CLI',                 'path' => 'extensions/cli'],
			['title' => 'AI Integration',      'path' => 'extensions/ai-integration'],
		],
		'groups' => [
			[
				'title' => 'Bundled Extensions',
				'sub'   => [
					['title' => 'Bundled Overview', 'path' => 'extensions/bundled'],
					['title' => 'A/B Split',        'path' => 'extensions/ab-split'],
					['title' => 'Geo Redirect',     'path' => 'extensions/geo-redirect'],
				],
			],
		],
	],
	[
		'title' => 'Operations',
		'sub'   => [
			['title' => 'Deployment',        'path' => 'operations/deployment'],
			['title' => 'Nginx',             'path' => 'operations/nginx'],
			['title' => 'Security',          'path' => 'operations/security'],
			['title' => 'Server Sizing',     'path' => 'operations/server-sizing'],
			['title' => 'Filesystem',        'path' => 'operations/filesystem'],
			['title' => 'Sync',              'path' => 'operations/sync'],
			['title' => 'Updates',           'path' => 'operations/updates'],
			['title' => 'JumpStart',         'path' => 'operations/jumpstart'],
			['title' => 'Search Backends',   'path' => 'operations/search'],
			['title' => 'Licenses',          'path' => 'operations/licenses'],
			['title' => 'Migration from v1', 'path' => 'operations/migration-total-cms-one'],
			['title' => 'Configuration',     'path' => 'operations/configuration'],
		],
	],
];
