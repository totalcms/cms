<?php

declare(strict_types=1);

namespace TotalCMS\Support;

/**
 * PublicHttpClient refused the URL itself — a scheme other than http(s),
 * credentials in it, a host that does not resolve or resolves to a
 * non-public address — before any request was sent. The fault is in what the
 * caller supplied, so it reads as a bad request, not a failed download.
 */
final class RefusedUrlException extends \RuntimeException
{
}
