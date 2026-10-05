<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Feed\Exception;

/**
 * Thrown when a feed is requested for a collection that does not publish one:
 * its RSS Feed card is off, and the request is not a legacy parameterized URL
 * the site still serves. The action layer answers 404, the same as for a
 * collection that does not exist.
 */
class FeedDisabledException extends \Exception
{
}
