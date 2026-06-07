<?php

declare(strict_types=1);

namespace TotalCMS\Factory;

/**
 * Logging channels — the routing table from "what a service is" to "which
 * file it writes" (LogFile).
 *
 * A service never picks a log file. It declares its channel:
 *
 *     $this->logger = $loggerFactory->channelLogger(LogChannel::Login);
 *
 * and the channel decides the file via file(). The channel name (the enum
 * value) appears on every log line — `login.INFO: …` inside access.log — so
 * consolidating files loses no granularity; you grep the channel instead of
 * hunting for the file.
 *
 * Adding a channel: add a case, add it to the match() arm of the file it
 * belongs in. New files require a new LogFile case — a deliberate decision,
 * not a side effect.
 */
enum LogChannel: string
{
	// ------------------------------------------------------------- App
	case App               = 'totalcms';
	case Cache             = 'cachemanager';
	case DataViews         = 'dataviews';
	case ViewBuilder       = 'viewbuilder';
	case DeckCompatibility = 'deckcompatibility';
	case DeckFileCleaner   = 'deck-file-cleaner';
	case Factory           = 'factory';
	case ImageGenerator    = 'imagegenerator';
	case IndexBuilder      = 'indexbuilder';
	case Migrations        = 'migrations';
	case ObjectExporter    = 'object-exporter';
	case OrphanCleaner     = 'orphan-cleaner';
	case OrphanScanner     = 'orphan-scanner';
	case ReportExporter    = 'report-exporter';
	case Search            = 'search';
	case TextWatermark     = 'textwatermark';
	case TotalForm         = 'totalform';
	case Update            = 'update';
	case WatermarkCleanup  = 'watermark-cleanup';

	// ---------------------------------------------------------- Access
	case Access            = 'access';
	case AuthMiddleware    = 'auth-middleware';
	case AuthToken         = 'auth-token';
	case DualAuth          = 'dual-auth-middleware';
	case EmailVerification = 'email-verification';
	case FileAccess        = 'fileaccess';
	case Login             = 'login';
	case Logout            = 'logout';
	case Passkey           = 'passkey';
	case PasswordReset     = 'password-reset';
	case PersistentLogin   = 'persistent-login';

	// -------------------------------------------------------- Importer
	case AlloyImporter       = 'alloy-importer';
	case CsvImporter         = 'csv-importer';
	case DeckCsvImporter     = 'deck-csv-importer';
	case DeckJsonImporter    = 'deck-json-importer';
	case JsonImporter        = 'json-importer';
	case JumpStartExporter   = 'jumpstart-exporter';
	case JumpStartImporter   = 'jumpstart-importer';
	case RssImporter         = 'rss-importer';
	case TotalCmsOneImporter = 'totalcms-one-importer';
	case UrlImporter         = 'url-importer';
	case WordpressImporter   = 'wordpress-importer';

	// ------------------------------------------------------------ Jobs
	case Automations         = 'automations';
	case AutomationsActivity = 'automations-activity';
	case JobRunner           = 'jobrunner';
	case Reindex             = 'reindex';

	// ----------------------------------------------------------- Email
	case BulkMailer  = 'bulk-mailer';
	case EmailSender = 'email-sender';
	case Email       = 'email-service';

	// ------------------------------------------------------------- Mcp
	case McpActivity       = 'mcp-activity';
	case McpNotification   = 'mcp-notification';
	case McpPrompts        = 'mcp-prompts';
	case McpSchemaTools    = 'mcp-schema-tools';
	case McpSubscription   = 'mcp-subscription';
	case McpToolsValidator = 'mcp-tools-validator';
	case OAuthActivity     = 'oauth-activity';

	// ------------------------------------------------------ Extensions
	case Events     = 'events';
	case Extensions = 'extensions';

	// ------------------------------------------------------------ Twig
	case Builder = 'builder';
	case Twig    = 'twig';

	// --------------------------------------------------------- License
	case License = 'license';

	/**
	 * The log file this channel writes to.
	 */
	public function file(): LogFile
	{
		return match ($this) {
			self::App,
			self::Cache,
			self::DataViews,
			self::ViewBuilder,
			self::DeckCompatibility,
			self::DeckFileCleaner,
			self::Factory,
			self::ImageGenerator,
			self::IndexBuilder,
			self::Migrations,
			self::ObjectExporter,
			self::OrphanCleaner,
			self::OrphanScanner,
			self::ReportExporter,
			self::Search,
			self::TextWatermark,
			self::TotalForm,
			self::Update,
			self::WatermarkCleanup => LogFile::App,

			self::Access,
			self::AuthMiddleware,
			self::AuthToken,
			self::DualAuth,
			self::EmailVerification,
			self::FileAccess,
			self::Login,
			self::Logout,
			self::Passkey,
			self::PasswordReset,
			self::PersistentLogin => LogFile::Access,

			self::AlloyImporter,
			self::CsvImporter,
			self::DeckCsvImporter,
			self::DeckJsonImporter,
			self::JsonImporter,
			self::JumpStartExporter,
			self::JumpStartImporter,
			self::RssImporter,
			self::TotalCmsOneImporter,
			self::UrlImporter,
			self::WordpressImporter => LogFile::Importer,

			self::Automations,
			self::AutomationsActivity,
			self::JobRunner,
			self::Reindex => LogFile::Jobs,

			self::BulkMailer,
			self::EmailSender,
			self::Email => LogFile::Email,

			self::McpActivity,
			self::McpNotification,
			self::McpPrompts,
			self::McpSchemaTools,
			self::McpSubscription,
			self::McpToolsValidator,
			self::OAuthActivity => LogFile::Mcp,

			self::Events,
			self::Extensions => LogFile::Extensions,

			self::Builder,
			self::Twig => LogFile::Twig,

			self::License => LogFile::License,
		};
	}
}
