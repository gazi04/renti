<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The pages of the central marketing site, and the one place their URLs are defined.
 *
 * Every page exists once per locale under a language prefix (/sq/..., /en/...) with a
 * translated slug, so Google indexes each language separately. Routes
 * (routes/web.php), the language switcher, the hreflang alternates and the sitemap
 * are all generated from this enum — never hardcode a marketing path.
 */
enum MarketingPage: string
{
    case Home = 'home';
    case Pricing = 'pricing';
    case Faq = 'faq';

    /** Albanian first: it is the default language of the marketing site. */
    public const array LOCALES = ['sq', 'en'];

    public const string DEFAULT_LOCALE = 'sq';

    public function slug(string $locale): string
    {
        return match ($this) {
            self::Home => '',
            self::Pricing => $locale === 'sq' ? 'cmimet' : 'pricing',
            self::Faq => $locale === 'sq' ? 'pyetje-te-shpeshta' : 'faq',
        };
    }

    public function path(string $locale): string
    {
        $slug = $this->slug($locale);

        return $slug === '' ? $locale : "{$locale}/{$slug}";
    }

    public function routeName(string $locale): string
    {
        return "marketing.{$locale}.{$this->value}";
    }

    /**
     * @return view-string
     */
    public function view(): string
    {
        return match ($this) {
            self::Home => 'marketing.home',
            self::Pricing => 'marketing.pricing',
            self::Faq => 'marketing.faq',
        };
    }

    /**
     * Absolute URL of this page, in the given locale or the current one.
     */
    public function url(?string $locale = null): string
    {
        return route($this->routeName(self::normalizeLocale($locale ?? app()->getLocale())));
    }

    /**
     * Any value outside the supported set (e.g. APP_LOCALE on a non-marketing
     * request) resolves to the default locale rather than a missing route name.
     */
    public static function normalizeLocale(mixed $locale): string
    {
        return is_string($locale) && in_array($locale, self::LOCALES, strict: true)
            ? $locale
            : self::DEFAULT_LOCALE;
    }
}
