<?php

namespace App\Enums;

enum EvaluationGrade: string
{
    case Correct = 'tocno';
    case Partial = 'djelomicno';
    case Wrong = 'netocno';
    case Refused = 'odbijeno';
    case Error = 'greska';

    public function label(): string
    {
        return match ($this) {
            self::Correct => 'Točno',
            self::Partial => 'Djelomično',
            self::Wrong => 'Netočno',
            self::Refused => 'Odbijeno',
            self::Error => 'Greška',
        };
    }

    /**
     * How bad the grade is; ties between grades are resolved towards the worse one.
     */
    public function severity(): int
    {
        return match ($this) {
            self::Correct => 0,
            self::Partial => 1,
            self::Refused => 2,
            self::Wrong => 3,
            self::Error => 4,
        };
    }

    /**
     * The grades the judge may give; errors and refusals are decided in code.
     *
     * @return list<string>
     */
    public static function judgeable(): array
    {
        return [self::Correct->value, self::Partial->value, self::Wrong->value];
    }
}
