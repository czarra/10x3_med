<?php

namespace App\Service\Suggestion;

final class RatioSuggestionResult
{
    private function __construct(
        public readonly bool $available,
        public readonly ?float $currentRatio,
        public readonly ?float $suggestedRatio,
        public readonly ?string $context,
    ) {
    }

    public static function suggest(float $currentRatio, float $suggestedRatio, string $context): self
    {
        return new self(true, $currentRatio, $suggestedRatio, $context);
    }

    public static function none(): self
    {
        return new self(false, null, null, null);
    }
}
