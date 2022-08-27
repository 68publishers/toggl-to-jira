<?php

declare(strict_types=1);

namespace App\ValueObject;

enum GroupMode
{
	case DEFAULT;
	case GROUP_BY_DAY;
}
