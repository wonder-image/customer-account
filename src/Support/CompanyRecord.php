<?php

namespace Wonder\Plugin\CustomerAccount\Support;

use Wonder\Plugin\CustomerAccount\Models\Company;

/**
 * Il record di un'azienda (vedi `PartyRecord`), con in più `is_supplier` e i
 * brand legati (`id`, `code`, `slug`, `name`, `role`, `is_default`).
 * `SCHEMA` 3: dalla 2 i brand portano codice, slug, ruolo e predefinito.
 */
final class CompanyRecord extends PartyRecord
{
    public const ENTITY = 'company';
    public const SCHEMA = 3;

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
        $record['brands'] = array_map(static fn (array $link): array => [
            'id' => $link['brand_id'],
            'code' => (string) ($link['brand']['code'] ?? ''),
            'slug' => (string) ($link['brand']['slug'] ?? ''),
            'name' => CompanyBrands::label($link['brand']),
            'role' => $link['role'],
            'is_default' => $link['is_default'],
        ], CompanyBrands::linksOf((int) $record['id']));

        return $record;
    }
}
