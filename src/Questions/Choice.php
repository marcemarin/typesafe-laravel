<?php

declare(strict_types=1);

namespace Marcemarin\TypeSafe\Questions;

use BackedEnum;
use Marcemarin\TypeSafe\Exceptions\InvalidQuestionException;
use Marcemarin\TypeSafe\Support\JsonMap;

/** "Which of these options?" Returns the most probable option plus the full distribution. */
final readonly class Choice extends Question
{
    public const MAX_OPTIONS = 255;

    /** @param  array<string, string>  $options  option => description */
    private function __construct(string $instructions, public array $options = [])
    {
        parent::__construct($instructions);
    }

    public static function make(string $instructions): self
    {
        return new self($instructions);
    }

    /**
     * Build the options from a backed enum. Descriptions come from `$descriptions`
     * (keyed by the case's backing value) or, failing that, from `DescribedOption::description()`.
     *
     * @param  class-string<BackedEnum>  $enum
     * @param  array<string|int, string>  $descriptions
     */
    public static function fromEnum(string $enum, array $descriptions = [], string $instructions = ''): self
    {
        return self::make($instructions)->enum($enum, $descriptions);
    }

    public function instructions(string $instructions): self
    {
        return new self($instructions, $this->options);
    }

    /**
     * Replace the options.
     *
     * @param  array<string|int, string>  $options  option => description
     */
    public function options(array $options): self
    {
        $normalized = [];
        foreach ($options as $option => $description) {
            $normalized[(string) $option] = $description;
        }

        return new self($this->instructions, $normalized);
    }

    public function option(string $option, string $description): self
    {
        return new self($this->instructions, [...$this->options, $option => $description]);
    }

    /**
     * Use a backed enum's cases as the options.
     *
     * @param  class-string<BackedEnum>  $enum
     * @param  array<string|int, string>  $descriptions
     */
    public function enum(string $enum, array $descriptions = []): self
    {
        if (! enum_exists($enum) || ! is_subclass_of($enum, BackedEnum::class)) {
            throw new InvalidQuestionException("Choice::enum() expects a backed enum class, got '{$enum}'.");
        }

        $options = [];
        foreach ($enum::cases() as $case) {
            $option = (string) $case->value;
            $description = $descriptions[$option] ?? ($case instanceof DescribedOption ? $case->description() : null);

            if ($description === null) {
                throw new InvalidQuestionException(
                    "No description for {$enum}::{$case->name}. Pass one in the descriptions array (keyed by '{$option}') "
                    .'or implement '.DescribedOption::class.' on the enum.'
                );
            }

            $options[$option] = $description;
        }

        return new self($this->instructions, $options);
    }

    public function type(): string
    {
        return 'choice';
    }

    public function validate(string $id): void
    {
        parent::validate($id);

        $count = count($this->options);
        if ($count < 2) {
            throw new InvalidQuestionException("Question '{$id}' (choice) needs at least 2 options, got {$count}.");
        }
        if ($count > self::MAX_OPTIONS) {
            throw new InvalidQuestionException("Question '{$id}' (choice) allows at most ".self::MAX_OPTIONS." options, got {$count}.");
        }
        foreach (array_keys($this->options) as $option) {
            if (trim((string) $option) === '') {
                throw new InvalidQuestionException("Question '{$id}' (choice) has an option with an empty name.");
            }
        }
    }

    public function toArray(): array
    {
        return [
            'type' => 'choice',
            'instructions' => $this->instructions,
            'criteria' => JsonMap::of($this->options),
        ];
    }
}
