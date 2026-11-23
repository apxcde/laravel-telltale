<?php

namespace Apxcde\LaravelTelltale\Sampling;

use Spatie\FlareClient\EntryPoint\EntryPoint;
use Spatie\FlareClient\Enums\EntryPointType;
use Spatie\FlareClient\Sampling\SamplingRule;

/**
 * Gives a kind of entry point (requests, queued jobs or commands) a sample rate of its own.
 *
 * The kind is known the moment a trace starts, so this rule never defers the decision. A rule
 * for a route, a command or a queue that is listed before it is asked first.
 */
class EntryPointTypeSamplingRule extends SamplingRule
{
    /** @var array<string, float> */
    protected array $rates = [];

    /**
     * @param array<string, mixed> $rates Rates by entry point type: `web`, `queue` or `cli`.
     *                                    A rate that is not a number from 0 to 1 is left out.
     */
    public function __construct(array $rates)
    {
        foreach ($rates as $type => $rate) {
            $rate = self::normalizeRate($rate);

            if ($rate !== null && EntryPointType::tryFrom((string) $type) !== null) {
                $this->rates[(string) $type] = $rate;
            }
        }
    }

    public function appliesTo(EntryPointType $entryPointType): bool
    {
        return array_key_exists($entryPointType->value, $this->rates);
    }

    public function getMatchedRate(EntryPoint $entryPoint): ?float
    {
        return $this->rates[$entryPoint->type->value] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->rates === [];
    }

    /**
     * A rate comes from an env variable, so it arrives as a string, or as something that is no
     * rate at all. This is called while the config is read, where throwing would take the
     * application down, so a bad value is dropped.
     */
    public static function normalizeRate(mixed $rate): ?float
    {
        if (! is_int($rate) && ! is_float($rate) && ! (is_string($rate) && is_numeric(trim($rate)))) {
            return null;
        }

        $rate = (float) (is_string($rate) ? trim($rate) : $rate);

        if (is_nan($rate) || $rate < 0.0 || $rate > 1.0) {
            return null;
        }

        return $rate;
    }
}
