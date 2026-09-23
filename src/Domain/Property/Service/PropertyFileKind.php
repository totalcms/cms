<?php

declare(strict_types=1);

namespace TotalCMS\Domain\Property\Service;

enum PropertyFileKind
{
	case File;
	case Depot;
	case Nested;
}
