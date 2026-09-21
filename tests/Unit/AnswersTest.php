<?php

declare(strict_types=1);

use Marcemarin\TypeSafe\Answers\Answer;
use Marcemarin\TypeSafe\Answers\ChoiceAnswer;
use Marcemarin\TypeSafe\Answers\NoulAnswer;
use Marcemarin\TypeSafe\Answers\ScoreAnswer;
use Marcemarin\TypeSafe\Exceptions\UnexpectedResponseException;
use Marcemarin\TypeSafe\Tests\Support\Intent;
use Marcemarin\TypeSafe\Tests\Support\Priority;

describe('ChoiceAnswer', function () {
    beforeEach(function () {
        $this->answer = Answer::fromArray('intent', jevBody()['answers']['intent']);
    });

    it('parses the documented shape', function () {
        expect($this->answer)->toBeInstanceOf(ChoiceAnswer::class)
            ->and($this->answer->value)->toBe('complaint')
            ->and($this->answer->confidence)->toBe(0.97)
            ->and($this->answer->probabilities)->toBe(['complaint' => 0.97, 'request' => 0.03])
            ->and($this->answer->type())->toBe('choice');
    });

    it('maps to a backed enum', function () {
        expect($this->answer->as(Intent::class))->toBe(Intent::Complaint);
    });

    it('maps to an int-backed enum even though JSON option names are strings', function () {
        $answer = new ChoiceAnswer('2', ['1' => 0.1, '2' => 0.9], 0.9);

        expect($answer->as(Priority::class))->toBe(Priority::High);
    });

    it('explains when the option is not a case of the enum', function () {
        (new ChoiceAnswer('spam', ['spam' => 1.0], 1.0))->as(Intent::class);
    })->throws(UnexpectedResponseException::class, "'spam' is not a case of ".Intent::class);

    it('compares against strings and enum cases', function () {
        expect($this->answer->is('complaint'))->toBeTrue()
            ->and($this->answer->is(Intent::Complaint))->toBeTrue()
            ->and($this->answer->is(Intent::Request))->toBeFalse()
            ->and($this->answer->probability(Intent::Request))->toBe(0.03)
            ->and($this->answer->probability('unknown'))->toBe(0.0);
    });

    it('checks confidence against a threshold', function () {
        expect($this->answer->isConfident())->toBeTrue()
            ->and($this->answer->isConfident(0.99))->toBeFalse();
    });

    it('rejects a missing choice', function () {
        Answer::fromArray('intent', ['type' => 'choice', 'probabilities' => [], 'confidence' => 1]);
    })->throws(UnexpectedResponseException::class, "Answer 'intent' is missing a string 'choice'.");

    it('rejects missing probabilities', function () {
        Answer::fromArray('intent', ['type' => 'choice', 'choice' => 'a', 'confidence' => 1]);
    })->throws(UnexpectedResponseException::class, "missing 'probabilities'");

    it('rejects a non-numeric confidence', function () {
        Answer::fromArray('intent', ['type' => 'choice', 'choice' => 'a', 'probabilities' => ['a' => 1], 'confidence' => 'high']);
    })->throws(UnexpectedResponseException::class, "numeric 'confidence'");
});

describe('ScoreAnswer', function () {
    beforeEach(function () {
        $this->answer = Answer::fromArray('on_air', jevBody()['answers']['on_air']);
    });

    it('parses a score that falls between levels', function () {
        expect($this->answer)->toBeInstanceOf(ScoreAnswer::class)
            ->and($this->answer->value)->toBe(3.6)
            ->and($this->answer->confidence)->toBe(0.7)
            ->and($this->answer->legend)->toBe([0 => 'Unusable', 1 => 'Weak', 2 => 'Acceptable', 3 => 'Good', 4 => 'Excellent'])
            ->and($this->answer->probabilities)->toBe([0 => 0.0, 1 => 0.0, 2 => 0.1, 3 => 0.2, 4 => 0.7])
            ->and($this->answer->type())->toBe('score');
    });

    it('normalizes to 0.0–1.0 using the number of levels', function () {
        expect($this->answer->normalized())->toBe(0.9);
    });

    it('normalizes the extremes and clamps out-of-range values', function (float $value, float $expected) {
        $answer = new ScoreAnswer($value, [0 => 'a', 1 => 'b', 2 => 'c'], [], 1.0);

        expect($answer->normalized())->toBe($expected);
    })->with([[0.0, 0.0], [1.0, 0.5], [2.0, 1.0], [2.5, 1.0], [-0.5, 0.0]]);

    it('normalizes to zero when the legend has fewer than two levels', function () {
        expect((new ScoreAnswer(1.0, [], [], 1.0))->normalized())->toBe(0.0)
            ->and((new ScoreAnswer(1.0, [0 => 'only'], [], 1.0))->normalized())->toBe(0.0);
    });

    it('exposes the nearest level and its label', function () {
        expect($this->answer->level())->toBe(4)
            ->and($this->answer->label())->toBe('Excellent')
            ->and((new ScoreAnswer(9.0, [0 => 'a'], [], 1.0))->label())->toBe('');
    });

    it('sorts a legend that arrives out of order', function () {
        $answer = Answer::fromArray('x', [
            'type' => 'score', 'score' => 1, 'confidence' => 1, 'probabilities' => [],
            'legend' => ['1' => 'b', '0' => 'a'],
        ]);

        expect($answer->legend)->toBe([0 => 'a', 1 => 'b']);
    });

    it('tolerates a missing legend', function () {
        $answer = Answer::fromArray('x', ['type' => 'score', 'score' => 1, 'confidence' => 1, 'probabilities' => []]);

        expect($answer->legend)->toBe([]);
    });

    it('checks confidence against a threshold', function () {
        expect($this->answer->isConfident(0.7))->toBeTrue()
            ->and($this->answer->isConfident())->toBeFalse();
    });

    it('rejects a missing score', function () {
        Answer::fromArray('x', ['type' => 'score', 'confidence' => 1, 'probabilities' => []]);
    })->throws(UnexpectedResponseException::class, "numeric 'score'");
});

describe('NoulAnswer', function () {
    it('parses the documented shape', function () {
        $answer = Answer::fromArray('insult', ['type' => 'noul', 'noul' => 0.02]);

        expect($answer)->toBeInstanceOf(NoulAnswer::class)
            ->and($answer->probability)->toBe(0.02)
            ->and($answer->type())->toBe('noul');
    });

    it('accepts an integer probability from JSON', function () {
        expect(Answer::fromArray('x', ['type' => 'noul', 'noul' => 1])->probability)->toBe(1.0);
    });

    it('applies thresholds to isTrue and isFalse', function () {
        $answer = new NoulAnswer(0.6);

        expect($answer->isTrue())->toBeTrue()
            ->and($answer->isTrue(0.6))->toBeTrue()
            ->and($answer->isTrue(0.61))->toBeFalse()
            ->and($answer->isFalse())->toBeFalse()
            ->and($answer->isFalse(0.6))->toBeFalse()
            ->and($answer->isFalse(0.61))->toBeTrue();
    });

    it('partitions 0–1 into false, uncertain and true', function (float $p, string $zone) {
        $answer = new NoulAnswer($p);

        $zones = array_keys(array_filter([
            'false' => $answer->isFalse(0.4),
            'uncertain' => $answer->isUncertain(0.4, 0.6),
            'true' => $answer->isTrue(0.6),
        ]));

        expect($zones)->toBe([$zone]);
    })->with([
        [0.0, 'false'],
        [0.39, 'false'],
        [0.4, 'uncertain'],
        [0.5, 'uncertain'],
        [0.59, 'uncertain'],
        [0.6, 'true'],
        [1.0, 'true'],
    ]);

    it('uses 0.4 and 0.6 as the default grey zone', function () {
        expect((new NoulAnswer(0.5))->isUncertain())->toBeTrue()
            ->and((new NoulAnswer(0.7))->isUncertain())->toBeFalse();
    });

    it('rejects thresholds outside 0–1', function (Closure $call) {
        $call(new NoulAnswer(0.5));
    })->with([
        'isTrue' => [fn (NoulAnswer $a) => $a->isTrue(1.5)],
        'isFalse' => [fn (NoulAnswer $a) => $a->isFalse(-0.1)],
        'isUncertain low' => [fn (NoulAnswer $a) => $a->isUncertain(-0.1, 0.5)],
        'isUncertain high' => [fn (NoulAnswer $a) => $a->isUncertain(0.1, 2)],
    ])->throws(InvalidArgumentException::class, 'between 0 and 1');

    it('rejects an inverted grey zone', function () {
        (new NoulAnswer(0.5))->isUncertain(0.7, 0.3);
    })->throws(InvalidArgumentException::class, 'cannot be above');

    it('rejects a missing probability', function () {
        Answer::fromArray('x', ['type' => 'noul']);
    })->throws(UnexpectedResponseException::class, "numeric 'noul'");
});

it('rejects unknown answer types', function () {
    Answer::fromArray('x', ['type' => 'ranking']);
})->throws(UnexpectedResponseException::class, "Answer 'x' has an unknown type");
