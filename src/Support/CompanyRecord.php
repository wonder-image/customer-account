<?php

namespace Wonder\Plugin\CustomerAccount\Support;

use Throwable;
use Wonder\Plugin\CustomerAccount\Models\Company;
use Wonder\Plugin\CustomerAccount\Models\CompanyBrand;

/**
 * Il record di un'azienda (vedi `PartyRecord`), con in più `is_supplier`, la
 * capogruppo (`parent_id`, `parent_code`) e i brand legati (`id`, `code`,
 * `slug`, `name`, `role`, `is_default`, `parent_id`, `parent_code`).
 * `SCHEMA` 3: dalla 2 i brand portano codice, slug, ruolo, predefinito e
 * brand principale, l'azienda la capogruppo. Senza padre: 0 e stringa vuota.
 * `SCHEMA` 4: i brand portano i loro dati (`BrandData::fromRow()`: logo come
 * percorso e URL, sito, dominio, descrizione e i campi del kit, anche se il
 * kit è spento); l'azienda `emails` e `phones`, i referenti `departments`.
 * `SCHEMA` 5: come `PartyRecord` 4 (tutti i contatti con `is_primary`, qui
 * anche con le aree `departments`; contatti di lavoro dei referenti; geo
 * della fatturazione).
 * `SCHEMA` 6: come `PartyRecord` 5 (i referenti portano
 * `is_legal_representative`).
 */
final class CompanyRecord extends PartyRecord
{
    public const ENTITY = 'company';
    public const SCHEMA = 6;

    public static function entity(): string
    {
        return self::ENTITY;
    }

    protected static function model(): string
    {
        return Company::class;
    }

    public static function fields(): array
    {
        return ContactExport::COMPANY_FIELDS;
    }

    protected static function side(): string
    {
        return ContactRelations::COMPANY;
    }

    protected static function requiredErrors(array $record): array
    {
        return trim((string) ($record['business_name'] ?? '')) === '' ? ['business_name' => 'required'] : [];
    }

    protected static function extra(array $record, array $row): array
    {
        $record['is_supplier'] = ($row['is_supplier'] ?? 'false') === 'true';
        $parent = CompanyGroups::parentOf((int) $record['id']);
        $record['parent_id'] = $parent !== null ? (int) $parent['id'] : 0;
        $record['parent_code'] = (string) ($parent['code'] ?? '');

        $links = CompanyBrands::linksOf((int) $record['id']);
        $parents = self::brandCodes(array_map(static fn (array $link): int => (int) ($link['brand']['parent_id'] ?? 0), $links));
        $record['brands'] = array_map(static function (array $link) use ($parents): array {
            $parentId = (int) ($link['brand']['parent_id'] ?? 0);

            return [
                'id' => $link['brand_id'],
                'code' => (string) ($link['brand']['code'] ?? ''),
                'slug' => (string) ($link['brand']['slug'] ?? ''),
                'name' => CompanyBrands::label($link['brand']),
                'role' => $link['role'],
                'is_default' => $link['is_default'],
                'parent_id' => isset($parents[$parentId]) ? $parentId : 0,
                'parent_code' => $parents[$parentId] ?? '',
            ] + BrandData::fromRow($link['brand']);
        }, $links);

        return $record;
    }

    /**
     * I codici dei brand padre, in una query.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    private static function brandCodes(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        try {
            $rows = CompanyBrand::find(['id' => $ids], null, null, null, ['id', 'code']);
        } catch (Throwable) {
            return [];
        }

        $rows = is_array($rows) && isset($rows['id']) ? [$rows] : array_filter((array) $rows, 'is_array');
        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row['id']] = (string) ($row['code'] ?? '');
        }

        return $out;
    }
}
