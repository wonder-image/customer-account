<?php

namespace Wonder\Plugin\CustomerAccount\Models;

use Wonder\App\Model;
use Wonder\App\Support\SyncSchema;
use Wonder\Data\UploadSchema as Field;
use Wonder\Sql\TableSchema as Column;

/**
 * Il legame fra un'azienda (`companies`) e una persona (`contacts`): i referenti di
 * un'azienda, le aziende di una persona.
 *
 * Una coppia azienda/persona esiste una volta sola, anche fra le righe
 * cancellate: chi la ricollega riprende la riga di prima (vedi
 * `Support\ContactRelations::normalize()`).
 */
class ContactRelation extends Model
{
    /** Solo anagrafica, nessun accesso dall'area cliente. */
    public const ACCESS_NONE = 'none';
    /** Compra a nome dell'azienda e vede i propri ordini. */
    public const ACCESS_BUYER = 'buyer';
    /** Vede tutto dell'azienda e ne gestisce i dati. */
    public const ACCESS_ADMIN = 'admin';
    public const ACCESS = [self::ACCESS_NONE, self::ACCESS_BUYER, self::ACCESS_ADMIN];

    /** I reparti di base del referente; altri si aggiungono con `ContactRelations::addDepartment()`. */
    public const DEPARTMENTS = ['administration', 'purchasing', 'sales', 'technical', 'management', 'other'];

    public static string $table = 'contact_relations';
    public static string $folder = 'contacts';
    public static string $icon = 'bi bi-diagram-2';

    public static function syncSchema(): ?SyncSchema
    {
        return null;
    }

    public static function tableSchema(): array
    {
        return [
            Column::key('company_id')->int()->null(false)->foreign(Company::$table),
            Column::key('person_id')->int()->null(false)->foreign(Contact::$table),
            Column::key('job_title')->length(150),
            // Testo e non enum: i reparti si estendono senza toccare la tabella.
            Column::key('department')->length(40),
            Column::key('is_primary')->enum(['true', 'false'])->default('false'),
            Column::key('receives_documents')->enum(['true', 'false'])->default('false'),
            Column::key('access')->enum(self::ACCESS)->default(self::ACCESS_NONE),
            Column::key('position')->int(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'uq_company_person' => ['unique' => ['company_id', 'person_id']],
            'ind_person' => ['index' => 'person_id'],
            'ind_department' => ['index' => ['company_id', 'department']],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('company_id')->number()->decimals(0),
            Field::key('person_id')->number()->decimals(0),
            Field::key('job_title')->text(),
            Field::key('department')->text()->sanitize(false),
            Field::key('is_primary')->text()->sanitize(false),
            Field::key('receives_documents')->text()->sanitize(false),
            Field::key('access')->text()->sanitize(false),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
