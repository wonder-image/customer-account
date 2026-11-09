<?php

namespace Wonder\Plugin\CustomerAccount\Support;

use Throwable;
use Wonder\Plugin\CustomerAccount\Models\Company;
use Wonder\Plugin\CustomerAccount\Models\Contact;
use Wonder\Plugin\CustomerAccount\Resources\ContactResource;

/**
 * Il committente di un lavoro (progetti, documenti, testi scritti dall'AI):
 * un'azienda o una persona, con il brand con cui l'azienda si presenta e il
 * referente che lo segue. Il nome con cui chiamarlo è il brand, altrimenti
 * la ragione sociale, altrimenti il nome della persona.
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

    /**
     * La forma da salvare su un progetto o un documento: i tre id ripuliti e
     * il nome copiato. Il brand resta solo se è legato oggi all'azienda (un
     * privato non ha brand); la persona, se c'è un'azienda, solo se ne è un
     * referente. `null` se non esistono né l'azienda né la persona.
     *
     * @return array{brand_id: int, company_id: int, person_id: int, label: string}|null
     */
    public static function resolve(int $brandId, int $companyId, int $personId): ?array
    {
        $companyId = self::row(Company::class, $companyId) !== null ? $companyId : 0;
        $personId = self::row(Contact::class, $personId) !== null ? $personId : 0;

        if ($companyId > 0 && $personId > 0 && !ContactRelations::isLinked($personId, $companyId)) {
            $personId = 0;
        }

        if ($companyId === 0 && $personId === 0) {
            return null;
        }

        $brandId = $companyId > 0 && CompanyBrands::belongsTo($brandId, $companyId) ? $brandId : 0;

        return [
            'brand_id' => $brandId,
            'company_id' => $companyId,
            'person_id' => $personId,
            'label' => self::label($brandId, $companyId, $personId),
        ];
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
