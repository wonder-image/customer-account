<?php

namespace Wonder\Plugin\CustomerAccount\Support\Import;

use Wonder\Plugin\CustomerAccount\Support\ContactPoints;

/**
 * Lettura delle celle comune ai tracciati della rubrica: flag sì/no, paese e
 * provincia dal codice o dal nome, telefono con o senza prefisso.
 */
trait ReadsImportCells
{
    /** `true`/`false` da sì, no, 1, 0, x, Attiva, Non attiva...; null se non si capisce. */
    protected function flag(string $value): ?string
    {
        $normalized = mb_strtolower(trim($value), 'UTF-8');
        $true = ['true', '1', 'si', 'sì', 'yes', 'x', 'vero', 'y', 's'];
        $false = ['false', '0', 'no', 'falso', 'n'];

        if (in_array($normalized, $true, true) || $normalized === mb_strtolower((string) __t('contacts.enabled'), 'UTF-8')
            || $normalized === mb_strtolower((string) __t('contacts.yes'), 'UTF-8')) {
            return 'true';
        }

        if (in_array($normalized, $false, true) || $normalized === mb_strtolower((string) __t('contacts.disabled'), 'UTF-8')
            || $normalized === mb_strtolower((string) __t('contacts.no'), 'UTF-8')) {
            return 'false';
        }

        return null;
    }

    /** I valori di testo come li legge chi carica (salvati possono essere codificati in HTML). */
    protected function decoded(array $row): array
    {
        return array_map(
            static fn ($value) => is_string($value) ? html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $value,
            $row,
        );
    }

    /** La chiave di un elenco codice ⇒ nome, cercata per codice o per nome; null se non c'è. */
    protected function lookup(string $cell, array $options): ?string
    {
        $needle = $this->plain($cell);

        foreach ($options as $code => $name) {
            if ($this->plain((string) $code) === $needle || $this->plain((string) $name) === $needle) {
                return (string) $code;
            }
        }

        return null;
    }

    protected function plain(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT', $value) : false;

        return (string) preg_replace('/[^a-z0-9]+/', '', $ascii !== false ? strtolower($ascii) : $value);
    }

    /** Il codice del paese da codice o nome; null se non c'è in `countries()`. */
    protected function countryCode(string $cell): ?string
    {
        return $this->lookup($cell, function_exists('countries') ? (array) countries() : []);
    }

    /** La provincia da codice o nome; fuori dai paesi con l'elenco la cella resta com'è. */
    protected function provinceCode(string $cell, string $country): ?string
    {
        $states = function_exists('states') ? (array) states($country !== '' ? $country : 'IT') : [];

        return $states === [] ? $cell : $this->lookup($cell, $states);
    }

    /**
     * Prefisso e numero: «+39 333 1234567» si divide allo spazio dopo il
     * prefisso; senza prefisso vale la colonna «Prefisso», poi quello salvato,
     * poi quello del paese. Restituisce `phone` e `phone_prefix` (vuoto se
     * le celle sono vuote).
     *
     * @param array<string, mixed>|null $current i valori salvati (`phone_prefix`, `country`)
     * @param list<string> $errors
     * @return array<string, string>
     */
    protected function phoneCells(string $cell, string $prefixCell, ?array $current, ?string $country, string $phoneLabel, string $prefixLabel, array &$errors): array
    {
        $cell = trim($cell);
        $prefixCell = trim($prefixCell);
        $country ??= (string) ($current['country'] ?? '');

        $prefix = $prefixCell !== '' ? ContactPoints::phonePrefix($prefixCell) : '';
        if ($prefix === null) {
            $errors[] = $this->text('errors.invalid_value', ['field' => $prefixLabel, 'value' => $prefixCell]);
            $prefix = '';
        }

        if ($cell === '') {
            return $prefix !== '' ? ['phone_prefix' => $prefix] : [];
        }

        $number = $cell;
        if (preg_match('/^((?:\+|00)\d{1,4})[\s\-.\/]+(.+)$/', $cell, $match) === 1) {
            $prefix = (string) ContactPoints::phonePrefix($match[1]);
            $number = $match[2];
        } elseif (preg_match('/^(?:\+|00)(\d+)$/', (string) preg_replace('/[\s\-.\/()]+/', '', $cell), $match) === 1) {
            [$prefix, $number] = $this->splitPrefix($match[1], $prefix !== '' ? $prefix : (string) ($current['phone_prefix'] ?? ''), $country);
        }

        $digits = $number !== null ? ContactPoints::phoneNumber($number) : null;

        if ($digits === null || $digits === '') {
            $errors[] = $this->text('errors.invalid_value', ['field' => $phoneLabel, 'value' => $cell]);

            return [];
        }

        if ($prefix === '' || $prefix === null) {
            $prefix = (string) ($current['phone_prefix'] ?? '');
        }
        if ($prefix === '') {
            $prefix = $this->countryPrefix($country) ?? '';
        }

        $values = ['phone' => $digits];
        if ($prefix !== '') {
            $values['phone_prefix'] = $prefix;
        }

        return $values;
    }

    /**
     * Un numero scritto tutto attaccato al prefisso («+393331234567»): si
     * prova il prefisso noto (colonna, salvato, paese), poi quelli del
     * framework dal più lungo.
     *
     * @return array{0: string, 1: ?string}
     */
    protected function splitPrefix(string $digits, string $known, string $country): array
    {
        $candidates = [];
        foreach ([$known, $this->countryPrefix($country !== '' ? $country : 'IT') ?? ''] as $prefix) {
            if (preg_match('/^\+(\d{1,4})$/', $prefix, $match) === 1) {
                $candidates[] = $match[1];
            }
        }

        if (function_exists('phonePrefix')) {
            $all = [];
            foreach (array_keys((array) \phonePrefix()) as $key) {
                if (preg_match('/^\+(\d{1,4})$/', (string) $key, $match) === 1) {
                    $all[] = $match[1];
                }
            }
            usort($all, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
            $candidates = [...$candidates, ...$all];
        }

        foreach (array_unique($candidates) as $prefix) {
            $rest = substr($digits, strlen($prefix));

            if (str_starts_with($digits, $prefix) && preg_match('/^\d{4,20}$/', $rest) === 1) {
                return ['+'.$prefix, $rest];
            }
        }

        return ['', null];
    }

    /** Il prefisso del paese come `+39`; null se il core non lo conosce. */
    protected function countryPrefix(string $country): ?string
    {
        if ($country === '' || !function_exists('countryPhonePrefix')) {
            return null;
        }

        $prefix = (string) countryPhonePrefix($country);

        if ($prefix === '') {
            return null;
        }

        $prefix = str_starts_with($prefix, '+') ? $prefix : '+'.ltrim($prefix, '0');

        return preg_match('/^\+\d{1,4}$/', $prefix) === 1 ? $prefix : null;
    }

    protected function text(string $key, array $replace = []): string
    {
        return (string) __t('contacts.import.'.$key, $replace);
    }
}
