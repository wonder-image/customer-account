<?php

namespace Wonder\Plugin\CustomerAccount\Support;

use Wonder\Plugin\CustomerAccount\Models\CompanyBrand;

/**
 * I dati di un brand oltre al nome: le regole per scriverli e la forma per
 * leggerli. Due gruppi: quelli per tutti (`logo`, `website`, `domains`,
 * `description`) e il «brand kit» (`contacts`, `socials`, `colors`, `tone`,
 * `sector`, `language`, `assets_url`), che si mostra solo se un modulo lo
 * accende (`CompanyBrands::useKit()`).
 *
 * `domains` (i domini email) e `contacts` (email e telefoni) sono liste in
 * JSON; le colonne `domain`, `email`, `phone_prefix`, `phone` tengono il primo
 * valore, per chi le legge già (sync, record, ricerche). Chi scrive ancora
 * solo `domain` o `email` / `phone` aggiorna quelle colonne.
 *
 * Il settore è una sezione ATECO / NACE (`A`…`U`, `SECTORS`); la lingua un
 * codice ISO 639-1 (`LANGUAGES`, estendibile). Ogni URL è http o https (mai
 * `javascript:`, `data:`…), i colori sono esadecimali, i social hanno chiavi
 * di una lista estendibile. Tutti i valori restano testo: chi li stampa li
 * escapa.
 */
final class BrandData
{
    /** I campi per tutti; il logo è un file e lo carica il framework. */
    public const BASE_FIELDS = ['website', 'domains', 'domain', 'description'];

    /** I campi del brand kit; `email`, `phone_prefix`, `phone` sono il primo dei `contacts`. */
    public const KIT_FIELDS = ['contacts', 'email', 'phone_prefix', 'phone', 'socials', 'colors', 'tone', 'sector', 'language', 'assets_url'];

    /** Le sezioni ATECO 2025 / NACE Rev. 2.1: la stessa lettera in tutta l'UE. */
    public const SECTORS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U'];

    /** Le lingue di base (ISO 639-1); altre con `addLanguage()`. */
    public const LANGUAGES = [
        'it', 'en', 'de', 'fr', 'es', 'pt', 'nl', 'pl', 'ro', 'el', 'cs', 'sk', 'sl', 'hr', 'hu', 'bg',
        'sv', 'da', 'fi', 'no', 'et', 'lv', 'lt', 'ga', 'mt', 'sq', 'sr', 'uk', 'ru', 'tr', 'ar', 'he',
        'zh', 'ja', 'ko', 'hi',
    ];

    /** La lingua proposta a un brand nuovo. */
    public const DEFAULT_LANGUAGE = 'it';

    /** Al massimo tanti domini email e tanti recapiti in un brand. */
    public const MAX_DOMAINS = 10;
    public const MAX_CONTACTS = 20;

    /** I social di base; altri con `addSocial()`. La lista è di `Socials`, comune a brand e persone. */
    public const SOCIALS = Socials::NETWORKS;

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

    /** @var list<string> caselle generiche aggiunte */
    private static array $extraProviders = [];

    /** @var list<string> social e servizi di siti aggiunti */
    private static array $extraSiteHosts = [];

    /** @var array<string, string> lingue aggiunte: codice => etichetta */
    private static array $extraLanguages = [];

    /**
     * I settori per un select: lettera => «A · Agricoltura, …».
     *
     * @return array<string, string>
     */
    public static function sectors(): array
    {
        $out = [];

        foreach (self::SECTORS as $code) {
            $out[$code] = $code.' · '.(string) __t('contacts.brand_sector_'.strtolower($code));
        }

        return $out;
    }

    /**
     * Le lingue per un select: codice => nome nella lingua del backend
     * (`Locale` di intl se c'è, altrimenti il codice).
     *
     * @return array<string, string>
     */
    public static function languages(): array
    {
        $out = [];
        $display = class_exists(\Locale::class) && function_exists('__l') ? (string) __l() : 'it';

        foreach (self::LANGUAGES as $code) {
            $name = class_exists(\Locale::class) ? (string) \Locale::getDisplayLanguage($code, $display) : '';
            $out[$code] = $name !== '' && $name !== $code ? mb_convert_case($name, MB_CASE_TITLE, 'UTF-8') : strtoupper($code);
        }

        return $out + self::$extraLanguages;
    }

    /** Aggiunge una lingua (es. `ca` => «Catalano»). */
    public static function addLanguage(string $code, string $label): void
    {
        $code = self::language($code);

        if ($code !== null && $code !== '') {
            self::$extraLanguages[$code] = $label;
        }
    }

    /**
     * I social per un select: quelli di base e quelli aggiunti.
     *
     * @return array<string, string>
     */
    public static function socials(): array
    {
        return Socials::networks();
    }

    /** Aggiunge un social (es. `threads`). Un modulo lo chiama al boot, un sito in `custom/function`. */
    public static function addSocial(string $key, string $label): void
    {
        Socials::add($key, $label);
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
     * Null se uno non va o sono troppi. Con il nome: `palette()`.
     *
     * @return list<string>|null
     */
    public static function colors(mixed $value): ?array
    {
        $palette = self::palette($value);

        return $palette === null ? null : array_column($palette, 'color');
    }

    /**
     * I colori con il loro nome («Rosso Piana»), nell'ordine scelto. Valgono
     * le righe del repeater (`color` + `name`), una lista di esadecimali (la
     * forma di prima, senza nome) o un testo separato da virgole. Un colore
     * ripetuto si tiene una volta, con il primo nome. Null se un colore o un
     * nome non vanno o sono troppi.
     *
     * @return list<array{color: string, name: string}>|null
     */
    public static function palette(mixed $value): ?array
    {
        $items = is_array($value) ? $value : preg_split('/[\s,;]+/', is_scalar($value) ? (string) $value : '');
        $out = [];

        foreach ((array) $items as $item) {
            $name = '';

            if (is_array($item)) {
                $name = self::text($item['name'] ?? '', 60);
                $item = $item['color'] ?? '';

                if ($name === null) {
                    return null;
                }
            }

            $item = is_scalar($item) ? strtolower(trim((string) $item)) : null;

            if ($item === '') {
                continue;
            }

            if ($item === null || preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/', $item, $match) !== 1) {
                return null;
            }

            $hex = '#'.(strlen($match[1]) === 3 ? preg_replace('/(.)/', '$1$1', $match[1]) : $match[1]);

            if (!in_array($hex, array_column($out, 'color'), true)) {
                $out[] = ['color' => $hex, 'name' => $name];
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
        return Socials::clean($value);
    }

    /**
     * I domini email del brand: da righe `domain` (il repeater), da una lista
     * o da un testo separato da virgole. Senza doppioni, al massimo
     * `MAX_DOMAINS`. Null se uno non va.
     *
     * @return list<string>|null
     */
    public static function domains(mixed $value): ?array
    {
        $items = is_array($value) ? $value : preg_split('/[\s,;]+/', is_scalar($value) ? (string) $value : '');
        $out = [];

        foreach ((array) $items as $item) {
            $domain = self::domain(is_array($item) ? ($item['domain'] ?? '') : $item);

            if ($domain === null) {
                return null;
            }

            if ($domain !== '' && !in_array($domain, $out, true)) {
                $out[] = $domain;
            }
        }

        return count($out) > self::MAX_DOMAINS ? null : $out;
    }

    /**
     * Il tipo ufficio di un recapito del brand: una chiave di
     * `ContactRelations::departments()`. Le etichette di prima restano come
     * sono («Fatturazione» diventa Amministrazione); una scritta a mano
     * diventa vuota.
     */
    public static function officeType(mixed $value): string
    {
        $key = is_scalar($value) ? trim((string) $value) : '';

        if ($key === 'billing') {
            $key = 'administration';
        }

        return $key !== '' && array_key_exists($key, ContactRelations::departments()) ? $key : ContactPoints::label($key);
    }

    /**
     * I recapiti del brand: righe `kind` (`email` o un tipo di telefono: `phone`, `mobile`, `whatsapp`), `email` oppure
     * `phone_prefix` + `phone`, `label` facoltativa (un tipo ufficio di `ContactRelations::departments()`). Le righe vuote si
     * saltano, i doppioni pure. Null se una riga non va o sono troppe.
     *
     * @return list<array{kind: string, email?: string, phone_prefix?: string, phone?: string, label: string}>|null
     */
    public static function contacts(mixed $value): ?array
    {
        $out = [];
        $seen = [];

        foreach (is_array($value) ? $value : [] as $row) {
            if (!is_array($row)) {
                return null;
            }

            $kind = is_scalar($row['kind'] ?? null) ? (string) $row['kind'] : '';
            $label = self::officeType($row['label'] ?? '');

            if (ContactPoints::isPhone($kind)) {
                $phone = ContactPoints::phoneNumber($row['phone'] ?? '');
                $prefix = ContactPoints::phonePrefix($row['phone_prefix'] ?? '');

                if ($phone === null || $prefix === null) {
                    return null;
                }

                if ($phone === '') {
                    continue;
                }

                $item = ['kind' => $kind, 'phone_prefix' => $prefix, 'phone' => $phone, 'label' => $label];
                $key = $kind.$prefix.$phone;
            } elseif ($kind === ContactPoints::EMAIL || $kind === '') {
                $email = self::email($row['email'] ?? '');

                if ($email === null) {
                    return null;
                }

                if ($email === '') {
                    continue;
                }

                $item = ['kind' => ContactPoints::EMAIL, 'email' => $email, 'label' => $label];
                $key = 'email'.$email;
            } else {
                return null;
            }

            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $item;
            }
        }

        return count($out) > self::MAX_CONTACTS ? null : $out;
    }

    /** Un settore: la lettera di una sezione (`c` → `C`) o un testo libero di prima, o vuoto. */
    public static function sector(mixed $value): ?string
    {
        $value = self::text($value, 100);

        if ($value === null || $value === '') {
            return $value;
        }

        return in_array(strtoupper($value), self::SECTORS, true) ? strtoupper($value) : $value;
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
     * Con `$primaryOnly` (il CRM spento: il form mostra un'email e un telefono
     * soli e nessun social) i dati in più non si perdono: `socials` non si
     * tocca e i recapiti salvati di `$stored` oltre la prima email e il primo
     * telefono restano in coda a quelli postati.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $stored la riga salvata del brand (serve con `$primaryOnly`)
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    public static function clean(array $values, bool $kit, array $stored = [], bool $primaryOnly = false): array
    {
        $out = [];
        $errors = [];
        $fields = $kit ? [...self::BASE_FIELDS, ...self::KIT_FIELDS] : self::BASE_FIELDS;

        if ($primaryOnly) {
            $fields = array_values(array_diff($fields, ['socials']));
        }

        foreach ($fields as $field) {
            if (!array_key_exists($field, $values)) {
                continue;
            }

            $raw = $values[$field];
            $clean = match ($field) {
                'website', 'assets_url' => self::url($raw),
                'domain' => self::domain($raw),
                'domains' => self::json(self::domains($raw)),
                'contacts' => self::json(self::contacts($raw)),
                'email' => self::email($raw),
                'phone_prefix' => ContactPoints::phonePrefix($raw),
                'phone' => ContactPoints::phoneNumber($raw),
                'colors' => self::json(self::palette($raw)),
                'socials' => self::json(self::socialLinks($raw)),
                'language' => self::language($raw),
                'sector' => self::sector($raw),
                default => self::text($raw, 2000),
            };

            if ($clean === null) {
                $errors[$field] = 'invalid';
                continue;
            }

            $out[$field] = $clean;
        }

        // La lista vince sulla colonna singola: `domain` è il primo dominio.
        if (array_key_exists('domains', $out)) {
            $out['domain'] = (string) ((self::decodeList($out['domains'])[0]) ?? '');
        }

        if (($out['domain'] ?? '') === '' && ($out['website'] ?? '') !== '' && !isset($errors['domain']) && !isset($errors['domains'])) {
            $domain = (string) self::domain($out['website']);

            if ($domain !== '' && !self::isMailProvider($domain) && !self::isSiteHost($domain)) {
                $out['domain'] = $domain;

                if (array_key_exists('domains', $out)) {
                    $out['domains'] = (string) self::json([$domain]);
                }
            }
        }

        if ($primaryOnly && array_key_exists('contacts', $out)) {
            $out['contacts'] = (string) self::json(self::withStoredExtras(self::decodeList($out['contacts']), $stored));
        }

        // I recapiti in lista; le colonne singole tengono la prima email e il primo telefono.
        if (array_key_exists('contacts', $out)) {
            $first = ['email' => '', 'phone_prefix' => '', 'phone' => ''];

            foreach (self::decodeList($out['contacts']) as $row) {
                if (($row['kind'] ?? '') === ContactPoints::EMAIL && $first['email'] === '') {
                    $first['email'] = (string) $row['email'];
                } elseif (ContactPoints::isPhone($row['kind'] ?? '') && $first['phone'] === '') {
                    $first['phone_prefix'] = (string) $row['phone_prefix'];
                    $first['phone'] = (string) $row['phone'];
                }
            }

            $out = $first + $out;
        }

        return [$out, $errors];
    }

    /**
     * I dati di un brand letti dalla riga, nella forma dei record: testi,
     * `socials` come mappa, `colors` come lista di esadecimali e `palette` con
     * i nomi dei colori. Il logo è il percorso del file sotto la cartella dei
     * caricamenti (`CompanyBrand::logoPath()`).
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
            'domains' => self::domainsOf($brand),
            'description' => self::decoded($brand['description'] ?? ''),
            'contacts' => self::contactsOf($brand),
            'email' => (string) ($brand['email'] ?? ''),
            'phone_prefix' => (string) ($brand['phone_prefix'] ?? ''),
            'phone' => (string) ($brand['phone'] ?? ''),
            // Si rileggono con le regole: un valore scritto a mano nel database non passa.
            'socials' => self::socialLinks(is_array($socials) ? $socials : []) ?? [],
            'colors' => self::colors(is_array($colors) ? $colors : []) ?? [],
            'palette' => self::palette(is_array($colors) ? $colors : []) ?? [],
            'tone' => self::decoded($brand['tone'] ?? ''),
            'sector' => self::decoded($brand['sector'] ?? ''),
            'language' => (string) ($brand['language'] ?? ''),
            'assets_url' => (string) (self::url($brand['assets_url'] ?? '') ?? ''),
        ];
    }

    /**
     * I domini email di una riga: la lista, più la colonna `domain` se un
     * vecchio scrittore l'ha cambiata da sola.
     *
     * @return list<string>
     */
    public static function domainsOf(array $brand): array
    {
        $list = self::domains(self::decodeList($brand['domains'] ?? '')) ?? [];
        $single = (string) (self::domain($brand['domain'] ?? '') ?? '');

        return $single !== '' && !in_array($single, $list, true) ? [$single, ...$list] : $list;
    }

    /**
     * I recapiti di una riga: la lista o, se è vuota, l'email e il telefono
     * delle colonne singole (i brand di prima).
     *
     * @return list<array<string, string>>
     */
    public static function contactsOf(array $brand): array
    {
        $list = self::contacts(self::decodeList($brand['contacts'] ?? '')) ?? [];

        if ($list !== []) {
            return $list;
        }

        return self::contacts([
            ['kind' => ContactPoints::EMAIL, 'email' => $brand['email'] ?? ''],
            ['kind' => ContactPoints::PHONE, 'phone_prefix' => $brand['phone_prefix'] ?? '', 'phone' => $brand['phone'] ?? ''],
        ]) ?? [];
    }

    /**
     * I recapiti postati da un form con un'email e un telefono soli, più
     * quelli salvati che il form non mostrava (tutto oltre la prima email e
     * il primo telefono), senza doppioni.
     *
     * @param list<array<string, string>> $posted
     * @param array<string, mixed> $stored
     * @return list<array<string, string>>
     */
    private static function withStoredExtras(array $posted, array $stored): array
    {
        $out = [];
        $shown = [];

        // Contano solo la prima email e il primo telefono postati.
        foreach ($posted as $row) {
            $family = ContactPoints::family($row['kind'] ?? '');

            if (!isset($shown[$family])) {
                $shown[$family] = true;
                $out[] = $row;
            }
        }

        $first = [];

        foreach (self::contactsOf($stored) as $row) {
            $family = ContactPoints::family($row['kind'] ?? '');

            // Il primo di ogni famiglia era quello mostrato: lo sostituisce il valore postato.
            if (!isset($first[$family])) {
                $first[$family] = true;
                continue;
            }

            $out[] = $row;
        }

        return array_slice(self::contacts($out) ?? $out, 0, self::MAX_CONTACTS);
    }

    /** @return array<int|string, mixed> */
    private static function decodeList(mixed $json): array
    {
        $decoded = is_string($json) && $json !== '' ? json_decode($json, true) : $json;

        return is_array($decoded) ? $decoded : [];
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
