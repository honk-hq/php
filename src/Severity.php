<?php

declare(strict_types=1);

namespace Honk;

/**
 * Severity, lowest to highest. Every case has a horn name on the Honk scale; the horn names are
 * aliases of the canonical cases: Severity::Loud === Severity::Warning.
 *
 * Inputs (Message::severity(), arrays) accept the canonical value or the horn name, any case;
 * the SDK always sends the canonical value.
 */
enum Severity: string
{
    case Info = 'info';
    case Success = 'success';
    case Warning = 'warning';
    case Error = 'error';
    case Critical = 'critical';

    /** Light. */
    public const Light = self::Info;
    /** Beep. */
    public const Beep = self::Success;
    /** Loud. */
    public const Loud = self::Warning;
    /** Long. */
    public const Long = self::Error;
    /** Blast. */
    public const Blast = self::Critical;

    /** Horn name => canonical value. */
    public const ALIASES = ['light' => 'info', 'beep' => 'success', 'loud' => 'warning', 'long' => 'error', 'blast' => 'critical'];

    /** The canonical case for a canonical value or horn name (case-insensitive), or null. */
    public static function tryParse(string $value): ?self
    {
        $v = strtolower(trim($value));

        return self::tryFrom(self::ALIASES[$v] ?? $v);
    }

    /** Like tryParse(), but throws for unknown values. */
    public static function parse(string $value): self
    {
        return self::tryParse($value) ?? throw new \ValueError(sprintf('"%s" is not a Honk severity (light, beep, loud, long, blast or info, success, warning, error, critical)', $value));
    }

    /** The horn name: light, beep, loud, long or blast. */
    public function horn(): string
    {
        return (string) array_search($this->value, self::ALIASES, true);
    }
}
