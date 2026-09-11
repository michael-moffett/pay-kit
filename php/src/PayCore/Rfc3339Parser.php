<?php

declare(strict_types=1);

namespace PayKit\PayCore;

use DateTimeImmutable;
use DateTimeZone;

/**
 * RFC 3339 date-time parser used by Challenge::isExpired().
 *
 * Extracted from Challenge.php per PR #102 review (inline comment 3298110199)
 * so RFC parsing logic lives in a dedicated file. Lua already keeps the
 * parser in lua/mpp/expires.lua; Ruby moves the regex to Rfc3339Parser
 * in the same review round.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc3339 RFC 3339 Date and Time on the Internet
 */
final class Rfc3339Parser
{
    /**
     * Strict RFC 3339 date-time grammar (sec 5.6); accepts lowercase t/z on
     * parse (RFC 3339 sec 5.6 PARSE caveat), year exactly 4 digits,
     * fractional seconds 1 or more digits (time-secfrac = 1*DIGIT).
     */
    private const RFC3339_PATTERN = '/^(\d{4})-(\d{2})-(\d{2})[Tt](\d{2}):(\d{2}):(\d{2})(?:\.(\d+))?(Z|z|([+-])(\d{2}):(\d{2}))$/';

    private function __construct()
    {
    }

    /**
     * Parse an RFC 3339 timestamp into a DateTimeImmutable, or null when the
     * input is not a valid RFC 3339 date-time. Returns null for any
     * out-of-range component so callers can fail-closed.
     */
    public static function parse(string $value): ?DateTimeImmutable
    {
        if (preg_match(self::RFC3339_PATTERN, $value, $m) !== 1) {
            return null;
        }
        $year = (int)$m[1];
        $month = (int)$m[2];
        $day = (int)$m[3];
        $hour = (int)$m[4];
        $minute = (int)$m[5];
        $second = (int)$m[6];
        $offsetTag = $m[8];
        // RFC 3339 section 5.7 allows seconds = 60 for positive leap seconds.
        // Lua and Go SDKs accept the value at the parser level; PHP must too,
        // otherwise a credential timestamped exactly at 23:59:60 UTC is
        // wrongly flagged expired.
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31 || $hour > 23 || $minute > 59 || $second > 60) {
            return null;
        }
        // year 0000 is a leap year (date-fullyear = 4DIGIT); checkdate() rejects it, so validate against leap-year 4.
        if ($year > 9999 || !checkdate($month, $day, $year === 0 ? 4 : $year)) {
            return null;
        }
        if ($offsetTag !== 'Z' && $offsetTag !== 'z') {
            $offHour = isset($m[10]) ? (int)$m[10] : 0;
            $offMin = isset($m[11]) ? (int)$m[11] : 0;
            if ($offHour > 23 || $offMin > 59) {
                return null;
            }
        }

        // Normalize lowercase t/z to uppercase before delegating to DateTimeImmutable (DATE_ATOM is strict).
        // PHP's `u` format only accepts exactly six fractional digits; RFC 3339 permits 1..9.
        // Truncate the regex-captured fractional component to microseconds (the regex already
        // bounded it to 1..9 digits, so truncation is safe). Sub-microsecond precision is dropped
        // for expiry comparison purposes which is acceptable since we only need second-level resolution.
        $normalized = strtr($value, ['t' => 'T', 'z' => 'Z']);
        // RFC 3339 §5.7 leap second normalization. The range guard above
        // accepts sec=60 for spec parity with Lua/Go/Ruby, but PHP's
        // DateTimeImmutable::createFromFormat rejects :60 outright (it
        // does not implement the leap-second extension). Downshift to
        // :59 before delegating so a credential timestamped exactly at
        // 23:59:60 UTC parses to 23:59:59 UTC for expiry comparison.
        // The 1-second resolution slip at the leap-second boundary has
        // no operational impact: expiry comparison is whole-second.
        if ($second === 60) {
            $normalized = preg_replace('/:60(?=\.|Z|[+\-])/', ':59', $normalized, 1) ?? $normalized;
        }
        $frac = $m[7];
        if ($frac !== '') {
            $truncated = substr($frac, 0, 6);
            $normalized = preg_replace('/\.\d+/', '.' . $truncated, $normalized, 1) ?? $normalized;
        }
        $parsed = DateTimeImmutable::createFromFormat(DATE_ATOM, $normalized);
        if ($parsed === false) {
            $parsed = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.up', $normalized);
        }
        if ($parsed === false) {
            $parsed = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.uP', $normalized);
        }
        if ($parsed === false) {
            return null;
        }
        if ($second === 60) {
            $utc = $parsed->setTimezone(new DateTimeZone('UTC'));
            if ($utc->format('H') !== '23' || $utc->format('i') !== '59' || $utc->format('j') !== $utc->format('t')) {
                return null;
            }
        }
        return $parsed;
    }
}
