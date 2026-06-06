<?php

declare(strict_types=1);

namespace TotalCMS\Factory;

/**
 * The definitive list of Total CMS log files.
 *
 * Every core log line lands in one of these files — services never type a
 * filename. A service declares its LogChannel and the channel routes to a
 * file here (see LogChannel::file()). Adding a new file is a deliberate
 * taxonomy decision made in this enum, not an ad-hoc string at a call site.
 *
 * Each file answers one operational question:
 *   App        — "Is the CMS healthy?" (also mirrors Warning+ from every
 *                other file, so it is THE place to look for errors)
 *   Access     — "Who logged in / why can't they?"
 *   Importer   — "What happened during my import?"
 *   Jobs       — "What ran in the background?"
 *   Email      — "Did my email send?"
 *   Mcp        — "What did AI agents do?"
 *   Extensions — "What did third-party code do?"
 *   Twig       — "Why is my template broken?"
 *   License    — "What is my license status?"
 */
enum LogFile: string
{
	case App        = 'totalcms.log';
	case Access     = 'access.log';
	case Importer   = 'importer.log';
	case Jobs       = 'jobs.log';
	case Email      = 'email.log';
	case Mcp        = 'mcp.log';
	case Extensions = 'extensions.log';
	case Twig       = 'twig.log';
	case License    = 'license.log';
}
