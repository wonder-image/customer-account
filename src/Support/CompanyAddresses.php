<?php

namespace Wonder\Plugin\CustomerAccount\Support;

use RuntimeException;
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

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
