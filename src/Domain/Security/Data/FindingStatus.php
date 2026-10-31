<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Security\Data;

enum FindingStatus: string
{
	case Found   = 'found';
	case Ok      = 'ok';
	case Skipped = 'skipped';
}
