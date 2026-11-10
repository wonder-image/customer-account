<?php

namespace Wonder\Plugin\CustomerAccount\Models;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Plugin\CustomerAccount\Models\Concerns\HasContactPointColumns;

/**
 * Le email e i telefoni in più di una persona (`contact_id`). Quelli
 * principali restano sulla scheda (`email`, `phone_prefix`, `phone`): chi ha
 * un solo recapito non vede cambiare nulla. Non servono mai per l'accesso né
 * per collegare un account a una scheda. Si scrive solo da
 * `Support\ContactPoints`. `CompanyPoint` è la tabella gemella delle aziende.
 */
class ContactPoint extends Model
{
    use HasContactPointColumns;

    public static string $table = 'contact_points';
    public static string $folder = 'contacts';
    public static string $icon = 'bi bi-telephone';

    public static function ownerKey(): string
    {
        return 'contact_id';
    }

    public static function ownerModel(): string
    {
        return Contact::class;
    }

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }
}
