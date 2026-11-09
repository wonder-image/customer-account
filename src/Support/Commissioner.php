<?php

namespace Wonder\Plugin\CustomerAccount\Support;

use Throwable;
use Wonder\Plugin\CustomerAccount\Models\Company;
use Wonder\Plugin\CustomerAccount\Models\Contact;
use Wonder\Plugin\CustomerAccount\Resources\ContactResource;

/**
 * Il nome con cui chiamare chi commissiona un lavoro (progetti, testi
 * scritti dall'AI): il brand, altrimenti la ragione sociale, altrimenti il
 * nome della persona.
 */
final class Commissioner
{
    /**
     * Testo semplice, entità già decodificate: chi lo stampa in HTML lo escapa.
     * Vuoto se nessuno dei tre esiste.
     */
    public static function label(int $brandId, int $companyId, int $personId): string
    {
        $brand = CompanyBrands::find($brandId);

        if ($brand !== null && trim((string) ($brand['name'] ?? '')) !== '') {
            return CompanyBrands::label($brand);
        }

        $company = self::row(Company::class, $companyId);

        if ($company !== null && trim((string) ($company['business_name'] ?? '')) !== '') {
            return self::decode(ContactResource::displayName($company));
        }

        $person = self::row(Contact::class, $personId);

        if ($person !== null && trim(((string) ($person['name'] ?? '')).((string) ($person['surname'] ?? ''))) !== '') {
            return self::decode(ContactResource::displayName($person));
        }

        return '';
    }

    /** @param class-string<\Wonder\App\Model> $model */
    private static function row(string $model, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        try {
            $row = $model::findById($id);
        } catch (Throwable) {
            return null;
        }

        return is_array($row) && isset($row['id']) && ($row['deleted'] ?? 'false') === 'false' ? $row : null;
    }

    private static function decode(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
