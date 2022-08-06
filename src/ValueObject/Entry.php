<?php

declare(strict_types=1);

namespace App\ValueObject;

use DateTimeImmutable;
use DateTimeInterface;

final class Entry
{
	public function __construct(
		public readonly string $issue,
		public readonly string $description,
		public readonly DateTimeImmutable $start,
		public readonly DateTimeImmutable $end,
		public readonly int $duration,
	) {
	}

	public function __toString(): string
	{
		return sprintf(
			'"%s %s" [%s - %s]',
			$this->issue,
			$this->description,
			$this->start->format(DateTimeInterface::ATOM),
			$this->end->format(DateTimeInterface::ATOM),
		);
	}
}
