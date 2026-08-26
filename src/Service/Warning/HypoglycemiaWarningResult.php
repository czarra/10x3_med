<?php

namespace App\Service\Warning;

final class HypoglycemiaWarningResult
{
    private function __construct(
        public readonly bool $available,
        public readonly ?string $message,
    ) {
    }

    public static function warn(string $message): self
    {
        return new self(true, $message);
    }

    public static function none(): self
    {
        return new self(false, null);
    }
}
