<?php

namespace Wonder\Plugin\CustomerAccount\Models;

use Wonder\App\Model;
use Wonder\App\Schema\Extensions\AddressExtension;
use Wonder\App\Support\ModelCode;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Plugin\CustomerAccount\Models\Concerns\HasPartyColumns;
use Wonder\Sql\TableSchema as Column;

/**
 * Una persona della rubrica, con o senza account. I dati da società stanno
 * su `Company`; le opzioni commerciali le aggiungono i moduli.
 */
class Contact extends Model
{
    use HasPartyColumns;

    public static string $table = 'contacts';
    public static string $folder = 'contacts';
    public static string $icon = 'bi bi-person-vcard';

    /** Fatturazione da privato: nome, cognome, CF e indirizzo. */
    public static function billing(): AddressExtension
    {
        // Niente link a Google Maps: l'indirizzo di fatturazione non si visita.
        return AddressExtension::billing(countryDefault: 'IT')->withLink(false)->asPrivate();
    }

    /** Operational identities must not travel through configuration sync. */
    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema(['code', 'email', 'color']),
            Column::key('birth_date')->date(),
            ...static::billing()->tableSchema(),
            Column::key('user_id')->int(),
            ...static::partyColumns(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_customer' => ['index' => 'is_customer'],
            'ind_user' => ['index' => 'user_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode('con_'),
            ...static::billing()->dataSchema(),
            Field::key('birth_date')->date(),
            Field::key('user_id')->number()->decimals(0),
            ...static::partyFields(),
        ];
    }

    /** Codice nuovo per chi inserisce una scheda da codice (`prepare()` non lo genera). */
    public static function newCode(): string
    {
        return ModelCode::make(static::class, 'con_');
    }
}
