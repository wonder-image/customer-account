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
 *
 * Le aree di cui si occupa il referente stanno in `ContactRelationDepartment`
 * (più di una; la vecchia colonna `department` la copia
 * `Migrations\RelationDepartmentsMigration`). `receives_documents`,
 * `is_primary` e `access` restano qui: l'accesso non dipende mai dall'area.
 *
 * `email`, `phone_prefix`, `phone` sono i contatti di lavoro del referente
 * per questa azienda (facoltativi): a chi scrivere per lei. Non sono mai
 * un'email di accesso né servono a collegare un account.
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

    /**
     * Le aree di base di cui si occupa un referente (in `contact_relation_departments`,
     * nessuna = tutte); altre si aggiungono con `ContactRelations::addDepartment()`.
     */
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
            Column::key('is_primary')->enum(['true', 'false'])->default('false'),
            Column::key('receives_documents')->enum(['true', 'false'])->default('false'),
            Column::key('access')->enum(self::ACCESS)->default(self::ACCESS_NONE),
            Column::key('email')->length(190),
            Column::key('phone_prefix')->length(8),
            Column::key('phone')->length(30),
            Column::key('position')->int(),
        ];
    }

    public static function tablePseudos(): array
    {
        return [
            'uq_company_person' => ['unique' => ['company_id', 'person_id']],
            'ind_person' => ['index' => 'person_id'],
        ];
    }

    public static function dataSchema(): array
    {
        return [
            Field::key('company_id')->number()->decimals(0),
            Field::key('person_id')->number()->decimals(0),
            Field::key('job_title')->text(),
            Field::key('is_primary')->text()->sanitize(false),
            Field::key('receives_documents')->text()->sanitize(false),
            Field::key('access')->text()->sanitize(false),
            // Già ripuliti da `ContactRelations::normalize()`.
            Field::key('email')->text()->sanitize(false),
            Field::key('phone_prefix')->text()->sanitize(false),
            Field::key('phone')->text()->sanitize(false),
            Field::key('position')->number()->decimals(0),
        ];
    }
}
