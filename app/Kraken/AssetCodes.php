<?php

namespace App\Kraken;

/**
 * Our own asset codes (BTC, ETH, EUR, USD, USDT — what every other part of
 * this app stores and computes with) against Kraken's two real, genuinely
 * idiosyncratic naming schemes:
 *   - altname: what a client sends in `pair=` (e.g. "XBTEUR") — BTC is XBT,
 *     everything else passes through unchanged.
 *   - internal/wsname-style legacy key: what a real Ticker response keys
 *     its result object by (e.g. "XXBTZEUR") — legacy crypto assets get an
 *     X prefix, legacy fiat gets a Z prefix; newer assets (USDT) get
 *     neither. Our own code never needs to parse this one back — only
 *     produce it, for response fidelity.
 *
 * Kept to the five assets this simulator actually prices (see
 * KrakenPriceSeeder) — add a row here and a seeded price to support another.
 */
class AssetCodes
{
    private const MAP = [
        'BTC' => ['altname' => 'XBT', 'internal' => 'XXBT'],
        'ETH' => ['altname' => 'ETH', 'internal' => 'XETH'],
        'EUR' => ['altname' => 'EUR', 'internal' => 'ZEUR'],
        'USD' => ['altname' => 'USD', 'internal' => 'ZUSD'],
        'USDT' => ['altname' => 'USDT', 'internal' => 'USDT'],
    ];

    public static function altname(string $ourCode): string
    {
        return self::MAP[strtoupper($ourCode)]['altname'] ?? strtoupper($ourCode);
    }

    public static function internalCode(string $ourCode): string
    {
        return self::MAP[strtoupper($ourCode)]['internal'] ?? strtoupper($ourCode);
    }

    public static function pairAltname(string $base, string $quote): string
    {
        return self::altname($base).self::altname($quote);
    }

    public static function pairInternalKey(string $base, string $quote): string
    {
        return self::internalCode($base).self::internalCode($quote);
    }

    public static function isKnown(string $ourCode): bool
    {
        return isset(self::MAP[strtoupper($ourCode)]);
    }

    /** @return list<string> Every asset code this simulator knows. */
    public static function known(): array
    {
        return array_keys(self::MAP);
    }

    /**
     * Splits a raw `pair=` value (e.g. "XBTEUR", "xbteur", or our own plain
     * "BTCEUR") into [base, quote] in our own codes — tries every known
     * altname as a prefix, longest first, so "USDTEUR" doesn't misparse as
     * "USD" + "TEUR". Returns null for anything it can't resolve to two
     * known assets, same as a real Kraken client would get an
     * EQuery:Unknown asset pair for.
     */
    public static function parsePair(string $raw): ?array
    {
        $raw = strtoupper(trim($raw));
        $altnames = array_map(fn ($c) => self::altname($c), self::known());
        // Longest altname first (USDT before anything 3-letter) so a
        // greedy prefix match doesn't cut a longer code short.
        usort($altnames, fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($altnames as $baseAlt) {
            if (! str_starts_with($raw, $baseAlt)) {
                continue;
            }
            $rest = substr($raw, strlen($baseAlt));
            foreach ($altnames as $quoteAlt) {
                if ($rest === $quoteAlt) {
                    return [self::codeFromAltname($baseAlt), self::codeFromAltname($quoteAlt)];
                }
            }
        }

        return null;
    }

    private static function codeFromAltname(string $altname): string
    {
        foreach (self::MAP as $code => $names) {
            if ($names['altname'] === $altname) {
                return $code;
            }
        }

        return $altname; // unreachable given parsePair()'s own lookup, kept defensive
    }
}
