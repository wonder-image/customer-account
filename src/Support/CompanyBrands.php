<?php

namespace Wonder\Plugin\CustomerAccount\Support;

use Throwable;
use Wonder\Plugin\CustomerAccount\Models\Company;
use Wonder\Plugin\CustomerAccount\Models\CompanyBrand;
use Wonder\Plugin\CustomerAccount\Models\CompanyBrandLink;

/**
 * I brand delle aziende: i nomi commerciali, legati alle ragioni sociali
 * molti a molti (`company_brand_links`).
 *
 * L'unico punto che crea brand e scrive i legami: form, creazione rapida,
 * import e sync passano da qui. Emette `CompanyEvents::BRAND_CREATED`,
 * `BRAND_LINKED` e `BRAND_UNLINKED`.
 */
final class CompanyBrands
{
    /**
     * Tutti i brand per un select, per nome.
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        $out = [];

        foreach (self::rows(self::safe(static fn () => CompanyBrand::find(['deleted' => 'false']))) as $row) {
            $out[(int) $row['id']] = self::label($row);
        }

        asort($out, SORT_NATURAL | SORT_FLAG_CASE);

        return $out;
    }

    /** Il nome leggibile di un brand. */
    public static function label(array $row): string
    {
        $name = html_entity_decode(trim((string) ($row['name'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $name !== '' ? $name : '#'.($row['id'] ?? '');
    }

    /** @return array<string, mixed>|null */
    public static function find(int $brandId): ?array
    {
        if ($brandId <= 0) {
            return null;
        }

        $row = self::safe(static fn () => CompanyBrand::findById($brandId));

        return is_array($row) && isset($row['id']) && ($row['deleted'] ?? 'false') === 'false' ? $row : null;
    }

    /**
     * Gli id dei brand di un'azienda, nell'ordine scelto.
     *
     * @return list<int>
     */
    public static function idsOf(int $companyId): array
    {
        return array_map(static fn (array $row): int => (int) $row['id'], self::of($companyId));
    }

    /**
     * I brand di un'azienda, nell'ordine scelto.
     *
     * @return list<array<string, mixed>>
     */
    public static function of(int $companyId): array
    {
        if ($companyId <= 0) {
            return [];
        }

        $out = [];

        foreach (self::links(['company_id' => $companyId]) as $link) {
            $brand = self::find((int) ($link['brand_id'] ?? 0));

            if ($brand !== null) {
                $out[] = $brand;
            }
        }

        return $out;
    }

    /**
     * Le aziende (ragioni sociali) di un brand.
     *
     * @return list<array<string, mixed>>
     */
    public static function companiesOf(int $brandId): array
    {
        if ($brandId <= 0) {
            return [];
        }

        $out = [];

        foreach (self::links(['brand_id' => $brandId]) as $link) {
            $company = self::safe(static fn () => Company::findById((int) ($link['company_id'] ?? 0)));

            if (is_array($company) && isset($company['id']) && ($company['deleted'] ?? 'false') === 'false') {
                $out[] = $company;
            }
        }

        return $out;
    }

    /** Il brand è dell'azienda? */
    public static function belongsTo(int $brandId, int $companyId): bool
    {
        return $brandId > 0 && in_array($brandId, self::idsOf($companyId), true);
    }

    /**
     * Il brand con questo nome (maiuscole e spazi non contano) o uno nuovo.
     *
     * @return array{id: int, created: bool, brand: array<string, mixed>}|null `null` se il nome è vuoto o il salvataggio fallisce
     */
    public static function findOrCreate(string $name): ?array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        if ($name === '') {
            return null;
        }

        $existing = self::byName($name);

        if ($existing !== null) {
            return ['id' => (int) $existing['id'], 'created' => false, 'brand' => $existing];
        }

        $result = CompanyBrand::create(['name' => $name]);
        $id = (int) ($result->insert_id ?? 0);

        if (!($result->success ?? false) || $id <= 0) {
            return null;
        }

        $brand = self::find($id) ?? ['id' => $id, 'name' => $name];
        CompanyEvents::dispatch(CompanyEvents::BRAND_CREATED, ['id' => $id, 'brand' => $brand]);

        return ['id' => $id, 'created' => true, 'brand' => $brand];
    }

    /** @return array<string, mixed>|null il brand vivo con questo nome */
    public static function byName(string $name): ?array
    {
        $key = self::key($name);

        foreach (self::rows(self::safe(static fn () => CompanyBrand::find(['deleted' => 'false']))) as $row) {
            if (self::key(self::label($row)) === $key) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Scrive i brand di un'azienda: quelli non elencati si staccano.
     *
     * @param list<int|string> $brandIds nell'ordine scelto; quelli che non esistono si ignorano
     * @return list<int> i brand rimasti legati
     */
    public static function sync(int $companyId, array $brandIds): array
    {
        if ($companyId <= 0) {
            return [];
        }

        $wanted = [];

        foreach ($brandIds as $id) {
            $id = (int) $id;

            if ($id > 0 && !in_array($id, $wanted, true) && self::find($id) !== null) {
                $wanted[] = $id;
            }
        }

        $before = [];

        foreach (self::links(['company_id' => $companyId]) as $link) {
            $before[(int) $link['brand_id']] = (int) $link['id'];
        }

        foreach ($wanted as $position => $brandId) {
            if (isset($before[$brandId])) {
                CompanyBrandLink::query()->Update(CompanyBrandLink::$table, ['position' => $position], 'id', $before[$brandId]);
                continue;
            }

            CompanyBrandLink::create(['company_id' => $companyId, 'brand_id' => $brandId, 'position' => $position]);
            CompanyEvents::dispatch(CompanyEvents::BRAND_LINKED, self::payload($companyId, $brandId));
        }

        foreach ($before as $brandId => $linkId) {
            if (!in_array($brandId, $wanted, true)) {
                $payload = self::payload($companyId, $brandId);
                CompanyBrandLink::delete($linkId);
                CompanyEvents::dispatch(CompanyEvents::BRAND_UNLINKED, $payload);
            }
        }

        return $wanted;
    }

    /** @return array<string, mixed> */
    private static function payload(int $companyId, int $brandId): array
    {
        return ['company_id' => $companyId, 'brand_id' => $brandId, 'brand' => self::find($brandId)];
    }

    private static function key(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? ''), 'UTF-8');
    }

    /** @return list<array<string, mixed>> */
    private static function links(array $condition): array
    {
        return self::rows(self::safe(static fn () => CompanyBrandLink::find($condition + ['deleted' => 'false'], null, 'position', 'ASC')));
    }

    private static function safe(callable $read): mixed
    {
        try {
            return $read();
        } catch (Throwable) {
            return null;
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
