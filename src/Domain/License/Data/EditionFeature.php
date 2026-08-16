<?php

declare(strict_types=1);

namespace TotalCMS\Domain\License\Data;

/**
 * All features that can be gated by license edition.
 */
enum EditionFeature: string
{
	// Schema features
	case BLOG_SCHEMA    = 'blog_schema';
	case DEPOT_SCHEMA   = 'depot_schema';
	case CUSTOM_SCHEMAS = 'custom_schemas';

	// Watermark features
	case IMAGE_WATERMARKS = 'image_watermarks';
	case TEXT_WATERMARKS  = 'text_watermarks';

	// Form action features
	case MAILER_ACTIONS   = 'mailer_actions';
	case WEBHOOK_ACTIONS  = 'webhook_actions';

	// API features
	case ALGOLIA_SEARCH    = 'algolia_search';
	case EXTERNAL_REST_API = 'external_rest_api';
	case MCP_SERVER        = 'mcp_server';
	case OAUTH_SERVER      = 'oauth_server';

	// Media features
	case QR_CODES = 'qr_codes';
	case BARCODES = 'barcodes';

	// Template features
	case TEMPLATES            = 'templates';
	case WHITELABEL_STANDARD  = 'whitelabel_standard';
	case WHITELABEL_PRO       = 'whitelabel_pro';

	// Data features
	case DATA_VIEWS = 'data_views';

	// Import features
	case RSS_IMPORT = 'rss_import';

	// Bulk mailer features
	case BULK_MAILER = 'bulk_mailer';

	// Auth features
	case PASSKEYS = 'passkeys';

	// Utility features
	case ACCESS_GROUPS = 'access_groups';
	case API_KEYS      = 'api_keys';
	case AUTOMATIONS   = 'automations';

	/**
	 * Get a human-readable label for this feature.
	 */
	public function label(): string
	{
		return match ($this) {
			self::BLOG_SCHEMA          => 'Blog Schema',
			self::DEPOT_SCHEMA         => 'Depot Schema',
			self::CUSTOM_SCHEMAS       => 'Custom Schemas',
			self::IMAGE_WATERMARKS     => 'Image Watermarks',
			self::TEXT_WATERMARKS      => 'Text Watermarks',
			self::MAILER_ACTIONS       => 'Mailer Form Actions',
			self::WEBHOOK_ACTIONS      => 'Webhook Form Actions',
			self::ALGOLIA_SEARCH       => 'Algolia Search',
			self::EXTERNAL_REST_API    => 'External REST API',
			self::MCP_SERVER           => 'MCP Server',
			self::OAUTH_SERVER         => 'OAuth Server',
			self::QR_CODES             => 'QR Codes',
			self::BARCODES             => 'Barcodes',
			self::TEMPLATES            => 'Templates',
			self::WHITELABEL_STANDARD  => 'Whitelabel Standard',
			self::WHITELABEL_PRO       => 'Whitelabel Pro',
			self::DATA_VIEWS           => 'Data Views',
			self::RSS_IMPORT           => 'RSS Import',
			self::BULK_MAILER          => 'Bulk Mailer',
			self::PASSKEYS             => 'Passkeys',
			self::ACCESS_GROUPS        => 'Access Groups',
			self::API_KEYS             => 'API Keys',
			self::AUTOMATIONS          => 'Automations',
		};
	}

	/**
	 * Get the minimum edition required for this feature.
	 */
	public function requiredEdition(): Edition
	{
		return match ($this) {
			// Lite features (all editions)
			self::TEMPLATES => Edition::LITE,

			// Standard features
			self::BLOG_SCHEMA,
			self::DEPOT_SCHEMA,
			self::IMAGE_WATERMARKS,
			self::MAILER_ACTIONS,
			self::QR_CODES,
			self::WHITELABEL_STANDARD,
			self::PASSKEYS,
			self::ACCESS_GROUPS,
			self::TEXT_WATERMARKS,
			self::BARCODES,
			// Standard and above. MCP is deliberately not a Lite feature: Lite
			// exists as a free way in, and the AI surface is not what we give
			// away. Standard grants the anonymous PUBLIC_ persona only — the
			// API-key (ADMIN) and OAuth (AUTHENTICATED) personas need API_KEYS
			// and OAUTH_SERVER, both Pro, and McpAuth gates on those directly.
			self::MCP_SERVER,
			self::RSS_IMPORT => Edition::STANDARD,

			// Pro features
			self::ALGOLIA_SEARCH,
			self::CUSTOM_SCHEMAS,
			self::WEBHOOK_ACTIONS,
			self::EXTERNAL_REST_API,
			self::OAUTH_SERVER,
			self::WHITELABEL_PRO,
			self::DATA_VIEWS,
			self::API_KEYS,
			self::AUTOMATIONS,
			self::BULK_MAILER => Edition::PRO,
		};
	}
}
