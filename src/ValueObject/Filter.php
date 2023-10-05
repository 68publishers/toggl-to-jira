<?php

declare(strict_types=1);

namespace App\ValueObject;

use Nette\Utils\Strings;
use function array_key_exists;
use function explode;
use function settype;

final class Filter
{
    public function __construct(
        public readonly string $name,
        public readonly mixed $value,
    ) {}

    public static function fromString(string $string): self
    {
        $parts = explode('=', $string, 2);
        $value = true;

        if (array_key_exists(1, $parts)) {
            $value = Strings::trim($parts[1]);
            $value = match ($value) {
                'true' => true,
                'false' => false,
                default => $value,
            };
        }

        return new self(
            name: $parts[0],
            value: $value,
        );
    }

    public function withCastedValue(string $castTo): self
    {
        $value = $this->value;
        settype($value, $castTo);

        return new self(
            name: $this->name,
            value: $value,
        );
    }

    public function __toString(): string
    {
        return $this->name . '=' . $this->value;
    }
}
