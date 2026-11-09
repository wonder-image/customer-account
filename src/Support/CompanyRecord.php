<?php

namespace Wonder\Plugin\CustomerAccount\Support;

use Wonder\Plugin\CustomerAccount\Models\Company;

/** Il record di un'azienda (vedi `PartyRecord`), con in più `is_supplier`. */
final class CompanyRecord extends PartyRecord
{
    public const ENTITY = 'company';

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

        return $record;
    }
}
