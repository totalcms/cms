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
	case PUSHOVER_ACTIONS = 'pushover_actions';

	// API features
	case EXTERNAL_REST_API = 'external_rest_api';

	// Media features
	case QR_CODES = 'qr_codes';
	case BARCODES = 'barcodes';

	// Template features
	case TEMPLATES            = 'templates';
	case WHITELABEL_TEMPLATES = 'whitelabel_templates';

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
			self::PUSHOVER_ACTIONS     => 'Pushover Form Actions',
			self::EXTERNAL_REST_API    => 'External REST API',
			self::QR_CODES             => 'QR Codes',
			self::BARCODES             => 'Barcodes',
			self::TEMPLATES            => 'Templates',
			self::WHITELABEL_TEMPLATES => 'Whitelabel Templates',
			self::DATA_VIEWS           => 'Data Views',
			self::RSS_IMPORT           => 'RSS Import',
			self::BULK_MAILER          => 'Bulk Mailer',
			self::PASSKEYS             => 'Passkeys',
			self::ACCESS_GROUPS        => 'Access Groups',
			self::API_KEYS             => 'API Keys',
		};
	}

	/**
	 * Get the minimum edition required for this feature.
	 */
	public function requiredEdition(): Edition
	{
		return match ($this) {
			// Standard features
			self::BLOG_SCHEMA,
			self::DEPOT_SCHEMA,
			self::IMAGE_WATERMARKS,
			self::MAILER_ACTIONS,
			self::QR_CODES,
			self::TEMPLATES,
			self::PASSKEYS,
			self::ACCESS_GROUPS,
			self::RSS_IMPORT => Edition::STANDARD,

			// Pro features
			self::CUSTOM_SCHEMAS,
			self::TEXT_WATERMARKS,
			self::WEBHOOK_ACTIONS,
			self::PUSHOVER_ACTIONS,
			self::EXTERNAL_REST_API,
			self::BARCODES,
			self::WHITELABEL_TEMPLATES,
			self::DATA_VIEWS,
			self::API_KEYS,
			self::BULK_MAILER => Edition::PRO,
		};
	}
}
