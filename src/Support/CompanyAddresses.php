<?php

namespace Wonder\Plugin\CustomerAccount\Support;

use InvalidArgumentException;
use RuntimeException;
use Wonder\App\Support\GoogleMaps;
use Wonder\Plugin\CustomerAccount\Models\CompanyAddress;
use Wonder\Plugin\CustomerAccount\Models\CompanyAddressBrand;
use Wonder\Sql\Transaction;

/**
 * Gli indirizzi di un'azienda letti per brand, e l'unico punto che scrive per
 * quali brand vale un indirizzo (`company_address_brands`).
 *
 * Nessuna riga = l'indirizzo vale per tutti i brand. Contano solo i brand
 * legati oggi all'azienda dell'indirizzo: se non ne resta nessuno vale per
 * tutti, e staccare o unire un brand (`CompanyBrands::sync()` / `merge()`)
 * toglie o sposta le righe, come per i brand seguiti dai referenti.
 */
final class CompanyAddresses
{
    /** I campi di un indirizzo che la scheda del brand mostra e scrive. */
    public const BRAND_FIELDS = ['label', 'street', 'number', 'cap', 'city', 'province', 'country'];

    /**
     * Gli indirizzi vivi di un'azienda con `brand_ids` (vuota = tutti i brand).
     * Con un brand: prima quelli di quel brand, poi quelli che valgono per
     * tutti, ognuno nell'ordine scelto; quelli di altri brand restano fuori.
     * Senza brand: tutti, nell'ordine scelto.
     *
     * @return list<array<string, mixed>>
     */
    public static function addressesFor(int $companyId, int $brandId = 0): array
    {
        if ($companyId <= 0) {
            return [];
        }

        $rows = self::rows(CompanyAddress::find(['company_id' => $companyId], null, 'position', 'ASC'));
        $brands = self::brandsByAddress(array_map(static fn (array $row): int => (int) $row['id'], $rows), $rows);
        $own = [];
        $all = [];

        foreach ($rows as $row) {
            $row['brand_ids'] = $brands[(int) $row['id']] ?? [];

            if ($brandId > 0 && in_array($brandId, $row['brand_ids'], true)) {
                $own[] = $row;
            } elseif ($brandId <= 0 || $row['brand_ids'] === []) {
                $all[] = $row;
            }
        }

        return [...$own, ...$all];
    }

    /**
     * I brand per cui vale un indirizzo; vuota = tutti.
     *
     * @return list<int>
     */
    public static function brandsOf(int $addressId): array
    {
        return self::brandsByAddress([$addressId])[$addressId] ?? [];
    }

    /**
     * I brand di più indirizzi, ristretti a quelli legati oggi all'azienda di
     * ciascuno. Gli indirizzi per tutti i brand non compaiono.
     *
     * @param list<int> $addressIds
     * @param list<array<string, mixed>>|null $addresses le righe degli indirizzi, se chi chiama le ha già
     * @return array<int, list<int>>
     */
    public static function brandsByAddress(array $addressIds, ?array $addresses = null): array
    {
        $addressIds = array_values(array_unique(array_filter(array_map('intval', $addressIds), static fn (int $id): bool => $id > 0)));

        if ($addressIds === []) {
            return [];
        }

        $stored = [];

        foreach (self::rows(CompanyAddressBrand::find(['address_id' => $addressIds], null, 'id', 'ASC')) as $row) {
            $stored[(int) $row['address_id']][] = (int) $row['brand_id'];
        }

        if ($stored === []) {
            return [];
        }

        $companyOf = [];

        foreach ($addresses ?? self::rows(CompanyAddress::find(['id' => array_keys($stored), 'deleted' => ['true', 'false']], null, null, null, ['id', 'company_id'])) as $row) {
            $companyOf[(int) $row['id']] = (int) ($row['company_id'] ?? 0);
        }

        $open = CompanyBrands::openIdsByCompany(array_values($companyOf));
        $out = [];

        foreach ($stored as $addressId => $brandIds) {
            $kept = array_values(array_intersect($brandIds, $open[$companyOf[$addressId] ?? 0] ?? []));

            if ($kept !== []) {
                $out[$addressId] = $kept;
            }
        }

        return $out;
    }

    /**
     * Scrive per quali brand vale un indirizzo: solo brand legati oggi alla
     * sua azienda, gli altri si ignorano. Una lista vuota = tutti i brand.
     *
     * @param list<int|string> $brandIds
     * @return list<int> i brand rimasti
     */
    public static function setBrands(int $addressId, array $brandIds): array
    {
        $address = $addressId > 0 ? CompanyAddress::findById($addressId) : null;

        if (!is_array($address) || !isset($address['id']) || ($address['deleted'] ?? 'false') !== 'false') {
            return [];
        }

        $companyId = (int) ($address['company_id'] ?? 0);
        $allowed = CompanyBrands::openIdsByCompany([$companyId])[$companyId] ?? [];
        $wanted = array_values(array_intersect(array_unique(array_map('intval', array_filter($brandIds, 'is_scalar'))), $allowed));

        return Transaction::run(static function () use ($addressId, $wanted): array {
            $current = [];

            foreach (self::rows(CompanyAddressBrand::find(['address_id' => $addressId])) as $row) {
                $current[(int) $row['brand_id']] = (int) $row['id'];
            }

            foreach ($wanted as $brandId) {
                if (!isset($current[$brandId]) && !(CompanyAddressBrand::create(['address_id' => $addressId, 'brand_id' => $brandId])->success ?? false)) {
                    throw new RuntimeException('Brand dell\'indirizzo non salvato.');
                }
            }

            foreach ($current as $brandId => $rowId) {
                if (!in_array($brandId, $wanted, true)) {
                    CompanyAddressBrand::delete($rowId);
                }
            }

            return $wanted;
        });
    }

    /**
     * Le righe di un editor degli indirizzi (un repeater) con `brand_ids`, per
     * mostrarle nel form: la colonna non è di `company_addresses`.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function withBrandIds(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            if (is_array($row) && !array_key_exists('brand_ids', $row) && (int) ($row['id'] ?? 0) > 0) {
                $ids[] = (int) $row['id'];
            }
        }

        $brands = self::brandsByAddress($ids);

        foreach ($rows as $key => $row) {
            if (is_array($row) && !array_key_exists('brand_ids', $row)) {
                $rows[$key]['brand_ids'] = $brands[(int) ($row['id'] ?? 0)] ?? [];
            }
        }

        return $rows;
    }

    /**
     * Toglie i brand che si staccano da un'azienda dai suoi indirizzi: lo
     * chiama `CompanyBrands::sync()`, dentro la sua transazione.
     *
     * @param list<int> $brandIds
     */
    public static function dropBrands(int $companyId, array $brandIds): void
    {
        $brandIds = array_values(array_filter(array_map('intval', $brandIds), static fn (int $id): bool => $id > 0));

        if ($brandIds === [] || $companyId <= 0) {
            return;
        }

        $addressIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            self::rows(CompanyAddress::find(['company_id' => $companyId, 'deleted' => ['true', 'false']], null, null, null, ['id']))
        );

        if ($addressIds === []) {
            return;
        }

        foreach (self::rows(CompanyAddressBrand::find(['address_id' => $addressIds, 'brand_id' => $brandIds])) as $row) {
            CompanyAddressBrand::delete((int) $row['id']);
        }
    }

    /**
     * Sposta gli indirizzi da un brand a un altro: lo chiama
     * `CompanyBrands::merge()`. Un indirizzo che valeva già per il brand di
     * arrivo perde la riga doppia.
     */
    public static function moveBrand(int $fromId, int $intoId): void
    {
        $rows = self::rows(CompanyAddressBrand::find(['brand_id' => $fromId]));

        if ($rows === [] || $intoId <= 0) {
            return;
        }

        $taken = array_flip(array_map(
            static fn (array $row): int => (int) $row['address_id'],
            self::rows(CompanyAddressBrand::find(['brand_id' => $intoId, 'address_id' => array_map(static fn (array $row): int => (int) $row['address_id'], $rows)]))
        ));

        foreach ($rows as $row) {
            if (isset($taken[(int) $row['address_id']])) {
                CompanyAddressBrand::delete((int) $row['id']);
            } else {
                CompanyAddressBrand::query()->Update(CompanyAddressBrand::$table, ['brand_id' => $intoId], 'id', (int) $row['id']);
            }
        }
    }

    /**
     * Gli indirizzi che valgono per un brand, azienda per azienda (quelle
     * legate oggi al brand): prima quelli che lo elencano, poi quelli per
     * tutti i brand. Ogni riga porta `company_name` (la ragione sociale) e
     * `all_brands` (vero se l'indirizzo vale per tutti i brand dell'azienda).
     * Con `$companyIds` solo le aziende date (chi vede solo alcune aziende).
     *
     * @param list<int>|null $companyIds
     * @return list<array<string, mixed>>
     */
    public static function forBrand(int $brandId, ?array $companyIds = null): array
    {
        $allowed = $companyIds !== null ? array_flip(array_map('intval', $companyIds)) : null;
        $out = [];

        foreach (CompanyBrands::companiesOf($brandId) as $company) {
            $companyId = (int) ($company['id'] ?? 0);

            if ($allowed !== null && !isset($allowed[$companyId])) {
                continue;
            }

            foreach (self::addressesFor($companyId, $brandId) as $row) {
                $row['company_name'] = (string) ($company['business_name'] ?? '');
                $row['all_brands'] = $row['brand_ids'] === [];
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * I campi che non vanno nelle righe che `updateFromBrand()` scriverebbe
     * (vuota = tutto bene): serve a fermare il salvataggio del brand prima
     * di scrivere.
     *
     * @param array<int|string, mixed> $rows
     * @param list<int>|null $companyIds
     * @return list<string>
     */
    public static function brandProblems(int $brandId, array $rows, ?array $companyIds = null): array
    {
        return self::brandChanges($brandId, $rows, $companyIds)[1];
    }

    /**
     * Aggiorna dalla scheda del brand gli indirizzi che valgono per lui: solo
     * le righe di `forBrand()` e solo i campi di `BRAND_FIELDS` (più place ID
     * e coordinate, se postati). Una riga con un id di un altro brand o di
     * un'altra azienda si ignora; non crea né elimina indirizzi, non tocca
     * «Presso», predefinito, ordine né i brand per cui valgono. Una riga che
     * non va ferma tutto prima di scrivere.
     *
     * @param array<int|string, mixed> $rows righe `id` + campi
     * @param list<int>|null $companyIds
     * @return list<int> gli indirizzi scritti
     */
    public static function updateFromBrand(int $brandId, array $rows, ?array $companyIds = null): array
    {
        [$changes, $problems] = self::brandChanges($brandId, $rows, $companyIds);

        if ($problems !== []) {
            throw new InvalidArgumentException('Indirizzi del brand non validi: '.implode(', ', $problems).'.');
        }

        if ($changes === []) {
            return [];
        }

        return Transaction::run(static function () use ($changes): array {
            foreach ($changes as $id => $values) {
                if (!(CompanyAddress::update($values, $id)->success ?? false)) {
                    throw new RuntimeException('Indirizzo del brand non salvato.');
                }
            }

            return array_keys($changes);
        });
    }

    /**
     * @param array<int|string, mixed> $rows
     * @param list<int>|null $companyIds
     * @return array{0: array<int, array<string, mixed>>, 1: list<string>} i valori da scrivere per indirizzo e i campi che non vanno
     */
    private static function brandChanges(int $brandId, array $rows, ?array $companyIds): array
    {
        $stored = [];

        foreach (self::forBrand($brandId, $companyIds) as $row) {
            $stored[(int) $row['id']] = $row;
        }

        $changes = [];
        $problems = [];

        foreach ($rows as $row) {
            $rawId = is_array($row) ? ($row['id'] ?? null) : null;
            $id = is_scalar($rawId) && ctype_digit(trim((string) $rawId)) ? (int) trim((string) $rawId) : 0;

            if (!isset($stored[$id]) || isset($changes[$id])) {
                continue;
            }

            $values = [];

            foreach (self::BRAND_FIELDS as $field) {
                if (!array_key_exists($field, $row)) {
                    continue;
                }

                if ($row[$field] !== null && !is_scalar($row[$field])) {
                    $problems[$field] = $field;
                    continue;
                }

                $values[$field] = trim((string) $row[$field]);
            }

            if (mb_strlen($values['label'] ?? '') > 100) {
                $problems['label'] = 'label';
            }

            $country = $values['country'] ?? (string) ($stored[$id]['country'] ?? '');

            if (($values['country'] ?? '') !== '' && function_exists('countries') && !array_key_exists($values['country'], countries())) {
                $problems['country'] = 'country';
            } elseif (($values['province'] ?? '') !== '' && function_exists('states')) {
                $states = (array) states($country);

                if ($states !== [] && !array_key_exists($values['province'], $states)) {
                    $problems['province'] = 'province';
                }
            }

            // Place ID e coordinate arrivano dal browser: ripuliti, come nella scheda dell'azienda.
            if (array_intersect_key($row, array_flip(['place_id', 'lat', 'lng'])) !== []) {
                $values += GoogleMaps::cleanGeo($row);
            }

            if ($values === []) {
                continue;
            }

            foreach (CompanyAddress::validate($values)->response as $field => $result) {
                if (!$result->isValid()) {
                    $problems[(string) $field] = (string) $field;
                }
            }

            $changes[$id] = $values;
        }

        return [$changes, array_values($problems)];
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
