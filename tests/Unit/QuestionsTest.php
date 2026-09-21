<?php

declare(strict_types=1);

use Marcemarin\TypeSafe\Exceptions\InvalidQuestionException;
use Marcemarin\TypeSafe\Questions\Choice;
use Marcemarin\TypeSafe\Questions\Noul;
use Marcemarin\TypeSafe\Questions\Score;
use Marcemarin\TypeSafe\Tests\Support\DescribedIntent;
use Marcemarin\TypeSafe\Tests\Support\Intent;
use Marcemarin\TypeSafe\Tests\Support\NotAnEnum;
use Marcemarin\TypeSafe\Tests\Support\Priority;

describe('Choice', function () {
    it('serializes to the documented shape', function () {
        $choice = Choice::make('Which team?')->options(['billing' => 'Payments', 'technical' => 'Bugs']);

        expect(json_decode(json_encode($choice->toArray()), true))->toBe([
            'type' => 'choice',
            'instructions' => 'Which team?',
            'criteria' => ['billing' => 'Payments', 'technical' => 'Bugs'],
        ]);
    });

    it('keeps numeric-looking option names as a JSON object', function () {
        $choice = Choice::make('Pick')->options(['0' => 'zero', '1' => 'one']);

        expect(json_encode($choice->toArray()['criteria']))->toBe('{"0":"zero","1":"one"}');
    });

    it('is immutable', function () {
        $base = Choice::make('Pick');
        $withOptions = $base->options(['a' => 'A', 'b' => 'B'])->option('c', 'C')->instructions('Pick again');

        expect($base->options)->toBe([])
            ->and($withOptions->options)->toBe(['a' => 'A', 'b' => 'B', 'c' => 'C'])
            ->and($withOptions->instructions)->toBe('Pick again');
    });

    it('builds options from a backed enum with explicit descriptions', function () {
        $choice = Choice::fromEnum(Intent::class, [
            'complaint' => 'Reports a problem',
            'request' => 'Asks for something',
        ], 'What is the purpose?');

        expect($choice->options)->toBe(['complaint' => 'Reports a problem', 'request' => 'Asks for something'])
            ->and($choice->instructions)->toBe('What is the purpose?');
    });

    it('reads descriptions from an enum that implements DescribedOption', function () {
        $choice = Choice::make('Purpose?')->enum(DescribedIntent::class);

        expect($choice->options)->toBe(['complaint' => 'Reports a problem', 'request' => 'Asks for something']);
    });

    it('supports int-backed enums', function () {
        $choice = Choice::make('Priority?')->enum(Priority::class, ['1' => 'Can wait', '2' => 'Now']);

        expect($choice->options)->toBe(['1' => 'Can wait', '2' => 'Now']);
    });

    it('explains which enum case is missing a description', function () {
        Choice::make('Purpose?')->enum(Intent::class, ['complaint' => 'Reports a problem']);
    })->throws(InvalidQuestionException::class, 'No description for '.Intent::class.'::Request');

    it('rejects a class that is not a backed enum', function () {
        Choice::make('Purpose?')->enum(NotAnEnum::class);
    })->throws(InvalidQuestionException::class, 'backed enum');

    it('needs at least two options', function () {
        Choice::make('Pick')->options(['a' => 'A'])->validate('pick');
    })->throws(InvalidQuestionException::class, "Question 'pick' (choice) needs at least 2 options, got 1.");

    it('allows at most 255 options', function () {
        $options = array_fill_keys(array_map(fn (int $i) => "o{$i}", range(1, 256)), 'x');

        Choice::make('Pick')->options($options)->validate('pick');
    })->throws(InvalidQuestionException::class, 'at most 255 options, got 256');

    it('accepts exactly 255 options', function () {
        $options = array_fill_keys(array_map(fn (int $i) => "o{$i}", range(1, 255)), 'x');

        Choice::make('Pick')->options($options)->validate('pick');
    })->throwsNoExceptions();

    it('rejects empty option names', function () {
        Choice::make('Pick')->options(['a' => 'A', ' ' => 'B'])->validate('pick');
    })->throws(InvalidQuestionException::class, 'empty name');

    it('needs instructions', function () {
        Choice::make('  ')->options(['a' => 'A', 'b' => 'B'])->validate('pick');
    })->throws(InvalidQuestionException::class, "Question 'pick' (choice) needs non-empty instructions.");
});

describe('Score', function () {
    it('serializes levels as an ordered list', function () {
        $score = Score::make('How angry?')->levels(['Calm', 'Annoyed', 'Furious']);

        expect($score->toArray())->toBe([
            'type' => 'score',
            'instructions' => 'How angry?',
            'criteria' => ['Calm', 'Annoyed', 'Furious'],
        ]);
    });

    it('reindexes levels so they always encode as a JSON array', function () {
        $score = Score::make('How angry?')->levels([3 => 'Calm', 7 => 'Furious']);

        expect(json_encode($score->toArray()['criteria']))->toBe('["Calm","Furious"]');
    });

    it('is immutable', function () {
        $base = Score::make('How angry?');
        $with = $base->levels(['a', 'b'])->instructions('How happy?');

        expect($base->levels)->toBe([])
            ->and($with->levels)->toBe(['a', 'b'])
            ->and($with->instructions)->toBe('How happy?');
    });

    it('needs at least two levels', function () {
        Score::make('How angry?')->levels(['Calm'])->validate('anger');
    })->throws(InvalidQuestionException::class, "Question 'anger' (score) needs between 2 and 10 levels, got 1.");

    it('allows at most ten levels', function () {
        Score::make('How angry?')->levels(range(1, 11))->validate('anger');
    })->throws(InvalidQuestionException::class, 'between 2 and 10 levels, got 11');

    it('accepts the 2 and 10 boundaries', function (int $count) {
        Score::make('How angry?')->levels(array_map(fn ($i) => "level {$i}", range(1, $count)))->validate('anger');
    })->with([2, 10])->throwsNoExceptions();

    it('needs instructions', function () {
        Score::make('')->levels(['a', 'b'])->validate('anger');
    })->throws(InvalidQuestionException::class, 'non-empty instructions');
});

describe('Noul', function () {
    it('serializes without criteria by default', function () {
        expect(Noul::make('Is it urgent?')->toArray())->toBe([
            'type' => 'noul',
            'instructions' => 'Is it urgent?',
        ]);
    });

    it('serializes optional criteria for true and false', function () {
        $noul = Noul::make('Is it urgent?')->criteria(true: 'Needs action today', false: 'Can wait');

        expect($noul->toArray())->toBe([
            'type' => 'noul',
            'instructions' => 'Is it urgent?',
            'criteria' => ['true' => 'Needs action today', 'false' => 'Can wait'],
        ]);
    });

    it('can replace its instructions', function () {
        $noul = Noul::make('Is it urgent?')->criteria('yes', 'no')->instructions('Is it late?');

        expect($noul->instructions)->toBe('Is it late?')
            ->and($noul->criteria)->toBe(['true' => 'yes', 'false' => 'no']);
    });

    it('needs instructions', function () {
        Noul::make(" \n")->validate('urgent');
    })->throws(InvalidQuestionException::class, "Question 'urgent' (noul) needs non-empty instructions.");
});
