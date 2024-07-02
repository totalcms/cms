<?php

namespace TotalCMS\Support;

final class Config
{
    /**
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     *
     * @param array<string,mixed> $logger
     * @param array<string,mixed> $sentry
     * @param array<string,mixed> $error
     * @param array<string,mixed> $imageworks
     */
    public function __construct(
        public string $template  = '',
        public string $datadir   = '',
        public string $cachedir  = '',
        public string $tmpdir    = '',
        public string $domain    = '',
        public string $api       = '',
        public string $locale    = '',
        public array $logger     = [],
        public array $sentry     = [],
        public array $error      = [],
        public array $imageworks = [],
    ) {
    }
}
