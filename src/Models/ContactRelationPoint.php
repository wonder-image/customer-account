<?php

namespace Wonder\Plugin\CustomerAccount\Models;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Plugin\CustomerAccount\Models\Concerns\HasContactPointColumns;

/**
 * Le email e i telefoni di un referente per un'azienda (`relation_id`): i
 * suoi recapiti di lavoro per quel legame, tutti, primo compreso. La prima
 * email e il primo telefono stanno anche su `contact_relations` (`email`,
 * `phone_prefix`, `phone`), che il resto del sistema legge. Non servono mai
 * per l'accesso. Si scrive solo da `Support\ContactRelations`.
 */
class ContactRelationPoint extends Model
{
    use HasContactPointColumns;

    public static string $table = 'contact_relation_points';
    public static string $folder = 'contacts';
    public static string $icon = 'bi bi-telephone';

    public static function ownerKey(): string
    {
        return 'relation_id';
    }

    public static function ownerModel(): string
    {
        return ContactRelation::class;
    }

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }
}
