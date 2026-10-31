<?php

declare(strict_types=1);

// phpbench bootstrap. Each iteration runs in its own PHP process, and
// BenchCase::boot() points that process at one fixture before Total CMS
// starts, so nothing else may boot Total CMS here.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
