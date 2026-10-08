<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Decides whether a concierge answer is actually substantiated by the operator's
 * FAQ, by verifying the supporting quote the model was required to copy out of it.
 *
 * The concierge is the only AI feature in this app whose output reaches an
 * anonymous third party with no operator review, and until this existed the whole
 * grounding gate was a `confident` boolean the MODEL filled in — so a jailbreak
 * that set it true shipped a fabricated policy inside the operator's branded
 * widget.
 *
 * # Why a quote rather than word overlap
 *
 * That finding suggested comparing the answer's vocabulary against the FAQ.
 * That is wrong here, and would have broken a supported configuration:
 * Tenant::localizedSetting() deliberately falls back across languages ("some
 * content beats none"), while FaqConciergeService picks the reply language from
 * the VISITOR's locale. An Albanian visitor reading a tenant that filled in only
 * faq_content_en therefore gets an English source and an Albanian answer by
 * design — where any answer-to-source overlap is near zero for a perfectly good
 * answer. It would also still pass a fabrication that reuses FAQ vocabulary.
 *
 * A quote sidesteps both: it is copied FROM the FAQ, so it is in the FAQ's
 * language whatever language the answer is in, and containment is exact rather
 * than a tuned threshold.
 */
final class FaqGrounding
{
    /**
     * Shorter than this is not evidence. Deliberately low — a real supporting
     * span can be as short as "Deposit: 200 EUR" — but long enough that quoting
     * a single common word cannot stand in for having read the source.
     */
    public const int MIN_QUOTE_LENGTH = 12;

    /**
     * Longer than this and the check is vacuous: a model that quotes the entire
     * 6000-character FAQ (ConciergeSettings caps it there) would satisfy
     * containment no matter what its answer claimed.
     */
    public const int MAX_QUOTE_LENGTH = 500;

    /**
     * Whether $quote is genuinely a span of $knowledge, allowing for the ways a
     * model rewrites text it believes it copied verbatim.
     */
    public static function supports(string $knowledge, string $quote): bool
    {
        $quote = self::trimQuoteMarkers(self::normalize($quote));

        $length = mb_strlen($quote);

        if ($length < self::MIN_QUOTE_LENGTH || $length > self::MAX_QUOTE_LENGTH) {
            return false;
        }

        return str_contains(self::normalize($knowledge), $quote);
    }

    /**
     * Fold away the differences that are not differences in meaning: case,
     * typographic punctuation (models routinely return a curly apostrophe for a
     * straight one), and any run of whitespace — including the non-breaking
     * space, which survives a copy-paste into the operator's textarea.
     */
    private static function normalize(string $text): string
    {
        $text = mb_strtolower($text);

        $text = strtr($text, [
            "\u{2018}" => "'", "\u{2019}" => "'",
            "\u{201C}" => '"', "\u{201D}" => '"',
            "\u{2013}" => '-', "\u{2014}" => '-',
            "\u{2026}" => '...',
        ]);

        $text = preg_replace('/[\s\x{00A0}]+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Strip leading/trailing ellipsis and sentence punctuation from the QUOTE
     * only. Doing it to one side is safe for a containment test — a shorter
     * needle still has to occur in the haystack — and it absorbs the model
     * marking an excerpt with "..." or closing on a period the source continues
     * past. A mid-quote ellipsis is left alone: it marks omitted text that
     * cannot be rejoined, so such a quote fails, which is the safe direction.
     */
    private static function trimQuoteMarkers(string $quote): string
    {
        return trim(trim($quote, ". \u{00A0}"));
    }
}
