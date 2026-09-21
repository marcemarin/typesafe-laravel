<?php

declare(strict_types=1);

namespace Marcemarin\TypeSafe;

/** Token usage of one request, as reported by the API. */
final readonly class Usage
{
    /** USD per million input tokens, from https://docs.typesafe.ai/models.md (checked 2026-09-21). */
    public const INPUT_USD_PER_MILLION = 0.042;

    /** Output tokens are free according to the same page. */
    public const OUTPUT_USD_PER_MILLION = 0.0;

    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
    ) {}

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            is_numeric($data['input_tokens'] ?? null) ? (int) $data['input_tokens'] : 0,
            is_numeric($data['output_tokens'] ?? null) ? (int) $data['output_tokens'] : 0,
        );
    }

    /**
     * Estimated cost in USD. Prices are per million tokens; override them if TypeSafe changes its pricing.
     */
    public function costUsd(
        float $inputPerMillion = self::INPUT_USD_PER_MILLION,
        float $outputPerMillion = self::OUTPUT_USD_PER_MILLION,
    ): float {
        return ($this->inputTokens * $inputPerMillion + $this->outputTokens * $outputPerMillion) / 1_000_000;
    }
}
