<?php

namespace App\Service\Suggestion;

final class BaseDoseSuggestionResult
{
    private function __construct(
        public readonly bool $available,
        public readonly ?int $currentBaseDose,
        public readonly ?int $suggestedBaseDose,
        public readonly ?string $context,
    ) {
    }

    public static function suggest(int $currentBaseDose, int $suggestedBaseDose, string $context): self
    {
        return new self(true, $currentBaseDose, $suggestedBaseDose, $context);
    }

    public static function none(): self
    {
        return new self(false, null, null, null);
    }
}
