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
 * Un'azienda: cliente, fornitore o tutte e due. Esiste anche senza persone;
 * le persone si collegano con `ContactRelation`.
 *
 * `parent_id` è la capogruppo (0 = nessuna): un livello solo, con le regole
 * di `Support\CompanyGroups`, l'unico punto che la valida.
 */
class Company extends Model
{
    use HasPartyColumns;

    public static string $table = 'companies';
    public static string $folder = 'companies';
    public static string $icon = 'bi bi-building';

    /**
     * Fatturazione da società: ragione sociale, CF, P.IVA, SDI, PEC, con place
     * ID e coordinate e la ricerca anche per nome dell'attività (che, nel
     * form, dà la ragione sociale se è vuota).
     */
    public static function billing(): AddressExtension
    {
        return AddressExtension::billing(countryDefault: 'IT')->withLink(false)->asBusiness()->withGeo()->withNameSearch(nameKey: 'business_name');
    }

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            ...static::sqlColumnsFromDataSchema(['code', 'email', 'color']),
            ...static::billing()->tableSchema(),
            Column::key('is_supplier')->enum(['true', 'false'])->default('false'),
            Column::key('parent_id')->int()->null(false)->default('0'),
            ...static::partyColumns(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'ind_customer' => ['index' => 'is_customer'],
            'ind_supplier' => ['index' => 'is_supplier'],
            'ind_pi' => ['index' => 'pi'],
            'ind_parent' => ['index' => 'parent_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('code')->text()->uniqueCode('cmp_'),
            ...static::billing()->dataSchema(),
            Field::key('is_supplier')->text()->sanitize(false),
            Field::key('parent_id')->number()->decimals(0),
            ...static::partyFields(),
        ];
    }

    public static function newCode(): string
    {
        return ModelCode::make(static::class, 'cmp_');
    }
}
