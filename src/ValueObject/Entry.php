<?php

declare(strict_types=1);

namespace App\ValueObject;

use DateTimeImmutable;
use DateTimeInterface;

final class Entry
{
	public function __construct(
		public readonly ?string $id,
		public readonly string $issue,
		public readonly string $description,
		public readonly DateTimeImmutable $start,
		public readonly int $duration,
	) {
	}

	public function withId(?string $id): self
	{
		return new self(
			$id,
			$this->issue,
			$this->description,
			$this->start,
			$this->duration
		);
	}

	/**
	 * @throws \Exception
	 */
	public function withRoundedDuration(Rounding $rounding): self
	{
		return new self(
			$this->id,
			$this->issue,
			$this->description,
			$this->start,
			$rounding->round($this->duration)
		);
	}

	/**
	 * @throws \Exception
	 */
	public function __toString(): string
	{
		$end = $this->start->modify(sprintf(
			'+%d seconds',
			$this->duration
		));

		$interval = $this->start->diff($end);
		$hours = $interval->h;
		$minutes = $interval->i;

		return sprintf(
			'"%s %s" [%s - %s, %s]',
			$this->issue,
			str_replace("\n", ' \n ', $this->description),
			$this->start->format(DateTimeInterface::ATOM),
			$end->format(DateTimeInterface::ATOM),
			0 < $hours ? ($hours . 'h ' . $minutes . 'm') : ($minutes . 'm')
		);
	}
}
