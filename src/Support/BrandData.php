<?php

namespace Wonder\Plugin\CustomerAccount\Support;

use Wonder\Plugin\CustomerAccount\Models\CompanyBrand;

/**
 * I dati di un brand oltre al nome: le regole per scriverli e la forma per
 * leggerli. Due gruppi: quelli per tutti (`logo`, `website`, `domain`,
 * `description`) e il «brand kit» (`email`, telefono, `socials`, `colors`,
 * `tone`, `sector`, `language`, `assets_url`), che si mostra solo se un modulo
 * lo accende (`CompanyBrands::useKit()`).
 *
 * Ogni URL è http o https (mai `javascript:`, `data:`…), i colori sono
 * esadecimali, i social hanno chiavi di una lista estendibile. Tutti i
 * valori restano testo: chi li stampa li escapa.
 */
final class BrandData
{
    /** I campi per tutti; il logo è un file e lo carica il framework. */
    public const BASE_FIELDS = ['website', 'domain', 'description'];

    /** I campi del brand kit. */
    public const KIT_FIELDS = ['email', 'phone_prefix', 'phone', 'socials', 'colors', 'tone', 'sector', 'language', 'assets_url'];

    /** I social di base; altri con `addSocial()`. */
    public const SOCIALS = ['facebook', 'instagram', 'linkedin', 'x', 'tiktok', 'youtube', 'pinterest', 'whatsapp'];

    /**
     * Le caselle di posta generica e le PEC dei gestori: un'email da qui non
     * dice di che brand è. Vale anche un loro sottodominio (`pec.libero.it`).
     * Altre con `addMailProvider()`.
     */
    public const MAIL_PROVIDERS = [
        'gmail.com', 'googlemail.com', 'msn.com', 'icloud.com', 'me.com', 'mac.com', 'ymail.com', 'rocketmail.com',
        'proton.me', 'protonmail.com', 'pm.me', 'tutanota.com', 'zoho.com', 'mail.com', 'web.de', 't-online.de',
        'orange.fr', 'free.fr', 'laposte.net', 'wanadoo.fr',
        'libero.it', 'virgilio.it', 'alice.it', 'tim.it', 'tin.it', 'tiscali.it', 'email.it', 'fastwebnet.it', 'inwind.it', 'iol.it',
        'katamail.com', 'teletu.it', 'poste.it', 'aruba.it',
        'pec.it', 'legalmail.it', 'arubapec.it', 'postecert.it', 'pec.poste.it', 'sicurezzapostale.it', 'registerpec.it', 'gigapec.it',
        'pecimprese.it', 'mypec.eu', 'casellapec.com', 'pec.buffetti.it', 'cert.legalmail.it', 'postacertificata.gov.it',
    ];

    /**
     * I gestori con un dominio per paese: `yahoo.it`, `hotmail.fr`,
     * `yahoo.co.uk`, `gmx.de` (il nome seguito da uno o due pezzi corti).
     */
    public const MAIL_PROVIDER_NAMES = ['yahoo', 'hotmail', 'live', 'outlook', 'gmx', 'yandex', 'aol'];

    /**
     * Social, siti fatti con un servizio e motori: il sito di un brand qui non
     * dice il suo dominio (`facebook.com/lapiana`). Vale anche un loro
     * sottodominio (`lapiana.wixsite.com`). Altri con `addSiteHost()`.
     */
    public const SITE_HOSTS = [
        'facebook.com', 'fb.com', 'instagram.com', 'linkedin.com', 'tiktok.com', 'youtube.com', 'youtu.be', 'x.com', 'twitter.com',
        'pinterest.com', 'threads.net', 'wa.me', 'whatsapp.com', 'linktr.ee', 'wixsite.com', 'wix.com', 'wordpress.com',
        'blogspot.com', 'google.com', 'goo.gl', 'business.site', 'tripadvisor.com', 'tripadvisor.it', 'booking.com',
        'github.io', 'netlify.app', 'vercel.app', 'shopify.com', 'myshopify.com', 'squarespace.com', 'jimdo.com', 'webnode.it',
    ];

    /** Al massimo tanti colori in un brand kit. */
    public const MAX_COLORS = 12;

    /** @var array<string, string> social aggiunti: chiave => etichetta */
    private static array $extraSocials = [];

    /** @var list<string> caselle generiche aggiunte */
    private static array $extraProviders = [];

    /** @var list<string> social e servizi di siti aggiunti */
    private static array $extraSiteHosts = [];

    /**
     * I social per un select: quelli di base e quelli aggiunti.
     *
     * @return array<string, string>
     */
    public static function socials(): array
    {
        $out = [];

        foreach (self::SOCIALS as $key) {
            $out[$key] = (string) __t('contacts.brand_social_'.$key);
        }

        return $out + self::$extraSocials;
    }

    /** Aggiunge un social (es. `threads`). Un modulo lo chiama al boot, un sito in `custom/function`. */
    public static function addSocial(string $key, string $label): void
    {
        $key = strtolower(trim($key));

        if (preg_match('/^[a-z0-9_]{1,40}$/', $key) === 1) {
            self::$extraSocials[$key] = $label;
        }
    }

    /** Aggiunge una casella di posta generica (es. `azienda-di-hosting.it`). */
    public static function addMailProvider(string $domain): void
    {
        $domain = self::domain($domain);

        if ($domain !== null && $domain !== '' && !in_array($domain, self::$extraProviders, true)) {
            self::$extraProviders[] = $domain;
        }
    }

    /** È una casella di posta generica (gmail.com, yahoo.co.uk, una PEC di un gestore, …) o un suo sottodominio? */
    public static function isMailProvider(string $domain): bool
    {
        $domain = self::domain($domain) ?? '';

        if ($domain === '') {
            return false;
        }

        $labels = explode('.', $domain);
        $rest = array_slice($labels, 1);

        if (in_array($labels[0], self::MAIL_PROVIDER_NAMES, true) && count($rest) <= 2
            && array_filter($rest, static fn (string $label): bool => strlen($label) > 3) === []) {
            return true;
        }

        return self::within($domain, [...self::MAIL_PROVIDERS, ...self::$extraProviders]);
    }

    /** Aggiunge un social o un servizio di siti (es. `ilmiosito.it` di un rivenditore). */
    public static function addSiteHost(string $domain): void
    {
        $domain = self::domain($domain);

        if ($domain !== null && $domain !== '' && !in_array($domain, self::$extraSiteHosts, true)) {
            self::$extraSiteHosts[] = $domain;
        }
    }

    /** È un social o un servizio di siti (facebook.com, lapiana.wixsite.com, …)? */
    public static function isSiteHost(string $domain): bool
    {
        $domain = self::domain($domain) ?? '';

        return $domain !== '' && self::within($domain, [...self::SITE_HOSTS, ...self::$extraSiteHosts]);
    }

    /** Il dominio è uno della lista o un suo sottodominio? @param list<string> $list */
    private static function within(string $domain, array $list): bool
    {
        foreach ($list as $item) {
            if ($domain === $item || str_ends_with($domain, '.'.$item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Un URL http o https, o vuoto. Un indirizzo senza schema che sembra un
     * dominio («www.lapiana.it/menu») prende `https://`. Null se non va.
     */
    public static function url(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        if ($value === '') {
            return '';
        }

        if (preg_match('/^[a-z0-9][a-z0-9.-]*\.[a-z]{2,63}(?:[\/?#].*)?$/i', $value) === 1) {
            $value = 'https://'.$value;
        }

        if (strlen($value) > 255 || preg_match('/[\s\x00-\x1F\x7F<>"\'`\\\\]/', $value) === 1 || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($value);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true) || trim((string) ($parts['host'] ?? '')) === '' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $value;
    }

    /**
     * Un dominio in minuscolo, senza schema, `www.`, porta né percorso
     * («https://www.LaPiana.it/menu» → «lapiana.it»), o vuoto. Null se non va.
     */
    public static function domain(mixed $value): ?string
    {
        $value = is_scalar($value) ? strtolower(trim((string) $value)) : '';

        if ($value === '') {
            return '';
        }

        if (str_contains($value, '://')) {
            $value = (string) (parse_url($value, PHP_URL_HOST) ?? '');
        }

        $value = (string) preg_replace('/[\/?#:].*$/', '', $value);
        $value = rtrim((string) preg_replace('/^www\./', '', $value), '.');

        if ($value !== '' && preg_match('/[^\x20-\x7E]/', $value) === 1 && function_exists('idn_to_ascii')) {
            $value = (string) (idn_to_ascii($value, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: '');
        }

        return preg_match('/^(?=.{4,190}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}$/', $value) === 1 ? $value : null;
    }

    /**
     * I colori del brand: esadecimali come `#1a2b3c` (anche `#abc`, che
     * diventa `#aabbcc`), da una lista o da un testo separato da virgole.
     * Null se uno non va o sono troppi.
     *
     * @return list<string>|null
     */
    public static function colors(mixed $value): ?array
    {
        $items = is_array($value) ? $value : preg_split('/[\s,;]+/', is_scalar($value) ? (string) $value : '');
        $out = [];

        foreach ((array) $items as $item) {
            $item = is_scalar($item) ? strtolower(trim((string) $item)) : null;

            if ($item === '') {
                continue;
            }

            if ($item === null || preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/', $item, $match) !== 1) {
                return null;
            }

            $hex = strlen($match[1]) === 3 ? preg_replace('/(.)/', '$1$1', $match[1]) : $match[1];

            if (!in_array('#'.$hex, $out, true)) {
                $out[] = '#'.$hex;
            }
        }

        return count($out) > self::MAX_COLORS ? null : $out;
    }

    /**
     * I social: da righe `network` / `url` (il repeater) o da una mappa
     * chiave → URL. Chiavi della lista, URL http/https; le righe senza URL
     * si saltano. Null se una chiave o un URL non vanno.
     *
     * @return array<string, string>|null
     */
    public static function socialLinks(mixed $value): ?array
    {
        $out = [];
        $known = self::socials();

        foreach (is_array($value) ? $value : [] as $key => $item) {
            [$network, $url] = is_array($item) ? [$item['network'] ?? '', $item['url'] ?? ''] : [$key, $item];
            $network = is_scalar($network) ? strtolower(trim((string) $network)) : '';
            $url = self::url($url);

            if ($url === '') {
                continue;
            }

            if ($url === null || !array_key_exists($network, $known)) {
                return null;
            }

            $out[$network] = $url;
        }

        return $out;
    }

    /** Una lingua come `it` o `it-IT`, o vuoto. Null se non va. */
    public static function language(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        if ($value === '') {
            return '';
        }

        if (preg_match('/^([a-z]{2,3})(?:[-_]([a-z]{2}))?$/i', $value, $match) !== 1) {
            return null;
        }

        return strtolower($match[1]).(isset($match[2]) ? '-'.strtoupper($match[2]) : '');
    }

    /**
     * I dati postati, ripuliti, e gli errori (campo → `invalid`). Passano solo
     * i campi per tutti e, con `$kit`, quelli del kit: gli altri si ignorano.
     * Il dominio vuoto si ricava dal sito, tranne quando il sito sta su un
     * social, un servizio di siti (`facebook.com/lapiana`, `x.wixsite.com`) o
     * una casella generica: lì il dominio non è del brand.
     *
     * @param array<string, mixed> $values
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    public static function clean(array $values, bool $kit): array
    {
        $out = [];
        $errors = [];
        $fields = $kit ? [...self::BASE_FIELDS, ...self::KIT_FIELDS] : self::BASE_FIELDS;

        foreach ($fields as $field) {
            if (!array_key_exists($field, $values)) {
                continue;
            }

            $raw = $values[$field];
            $clean = match ($field) {
                'website', 'assets_url' => self::url($raw),
                'domain' => self::domain($raw),
                'email' => self::email($raw),
                'phone_prefix' => ContactPoints::phonePrefix($raw),
                'phone' => ContactPoints::phoneNumber($raw),
                'colors' => self::json(self::colors($raw)),
                'socials' => self::json(self::socialLinks($raw)),
                'language' => self::language($raw),
                'sector' => self::text($raw, 100),
                default => self::text($raw, 2000),
            };

            if ($clean === null) {
                $errors[$field] = 'invalid';
                continue;
            }

            $out[$field] = $clean;
        }

        if (($out['domain'] ?? '') === '' && ($out['website'] ?? '') !== '' && !isset($errors['domain'])) {
            $domain = (string) self::domain($out['website']);

            if ($domain !== '' && !self::isMailProvider($domain) && !self::isSiteHost($domain)) {
                $out['domain'] = $domain;
            }
        }

        return [$out, $errors];
    }

    /**
     * I dati di un brand letti dalla riga, nella forma dei record: testi,
     * `socials` come mappa e `colors` come lista. Il logo è il percorso del
     * file sotto la cartella dei caricamenti (`CompanyBrand::logoPath()`).
     *
     * @return array<string, mixed>
     */
    public static function fromRow(array $brand): array
    {
        $socials = json_decode((string) ($brand['socials'] ?? ''), true);
        $colors = json_decode((string) ($brand['colors'] ?? ''), true);
        return [
            'logo' => CompanyBrand::logoPath($brand),
            'logo_url' => CompanyBrand::logoUrl($brand),
            'website' => (string) (self::url($brand['website'] ?? '') ?? ''),
            'domain' => (string) ($brand['domain'] ?? ''),
            'description' => self::decoded($brand['description'] ?? ''),
            'email' => (string) ($brand['email'] ?? ''),
            'phone_prefix' => (string) ($brand['phone_prefix'] ?? ''),
            'phone' => (string) ($brand['phone'] ?? ''),
            // Si rileggono con le regole: un valore scritto a mano nel database non passa.
            'socials' => self::socialLinks(is_array($socials) ? $socials : []) ?? [],
            'colors' => self::colors(is_array($colors) ? $colors : []) ?? [],
            'tone' => self::decoded($brand['tone'] ?? ''),
            'sector' => self::decoded($brand['sector'] ?? ''),
            'language' => (string) ($brand['language'] ?? ''),
            'assets_url' => (string) (self::url($brand['assets_url'] ?? '') ?? ''),
        ];
    }

    private static function email(mixed $value): ?string
    {
        $value = is_scalar($value) ? strtolower(trim((string) $value)) : '';

        // Solo il formato: niente DNS, che non deve decidere cosa si salva.
        return $value === '' || ContactPoints::emailFormat($value) ? $value : null;
    }

    private static function text(mixed $value, int $max): ?string
    {
        // Un valore non scalare dalla richiesta (`x[]=1`) vale vuoto.
        $value = is_scalar($value) ? trim((string) $value) : '';

        return mb_strlen($value) > $max ? null : $value;
    }

    /** Una lista o una mappa in JSON per la colonna; vuota resta vuota. */
    private static function json(?array $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value === [] ? '' : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function decoded(mixed $value): string
    {
        return html_entity_decode(trim((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
