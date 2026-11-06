<?php

namespace Wonder\Plugin\CustomerAccount\Resources\Concerns;

use Throwable;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\Input;
use Wonder\App\ResourceSchema\RepeaterColumn;
use Wonder\App\ResourceSchema\RepeaterRelation;
use Wonder\App\Support\Repeater;
use Wonder\Plugin\CustomerAccount\Models\Contact;
use Wonder\Plugin\CustomerAccount\Models\ContactRelation;
use Wonder\Plugin\CustomerAccount\Support\ContactRelations;

/**
 * I repeater «Referenti» (schede business) e «Aziende» (schede private) di una
 * Resource della rubrica.
 *
 * Il sync del core cancella le righe di un repeater che non arriva nel POST:
 * qui i due repeater si salvano solo se il form li ha mostrati (campo
 * `contact_relations_form`).
 */
trait HasContactRelations
{
    public const RELATION_PEOPLE = 'contact_people';
    public const RELATION_COMPANIES = 'contact_companies';
    public const RELATION_MARKER = 'contact_relations_form';

    /** @return list<Input> */
    public static function contactRelationFields(): array
    {
        if (!ContactRelations::enabled()) {
            return [];
        }

        return [
            static::contactPeopleField(),
            static::contactCompaniesField(),
            FormField::key(self::RELATION_MARKER)->hidden()->value('1'),
        ];
    }

    protected static function contactPeopleField(): Input
    {
        return static::relationRepeater(self::RELATION_PEOPLE, ContactRelations::COMPANY, [
            RepeaterColumn::key('id')->hidden(),
            RepeaterColumn::key(ContactRelations::PERSON)
                ->selectSearch(['' => '—'] + static::relationOptions('private'))
                ->label(static::relationText('person'))
                ->columnSpan(6),
            RepeaterColumn::key('job_title')->text()->label(static::relationText('job_title'))->columnSpan(6),
            RepeaterColumn::key('new_name')->text()->label(static::relationText('new_name'))->columnSpan(4),
            RepeaterColumn::key('new_surname')->text()->label(static::relationText('new_surname'))->columnSpan(4),
            RepeaterColumn::key('new_email')->email()->label(static::relationText('new_email'))->columnSpan(4),
            ...static::relationFlagColumns(),
        ], 'business', static::relationText('add_person'));
    }

    protected static function contactCompaniesField(): Input
    {
        return static::relationRepeater(self::RELATION_COMPANIES, ContactRelations::PERSON, [
            RepeaterColumn::key('id')->hidden(),
            RepeaterColumn::key(ContactRelations::COMPANY)
                ->selectSearch(['' => '—'] + static::relationOptions('business'))
                ->label(static::relationText('company'))
                ->columnSpan(6),
            RepeaterColumn::key('job_title')->text()->label(static::relationText('job_title'))->columnSpan(6),
            ...static::relationFlagColumns(),
        ], 'private', static::relationText('add_company'));
    }

    /** @return list<Input> */
    protected static function relationFlagColumns(): array
    {
        $options = ['false' => static::relationText('no'), 'true' => static::relationText('yes')];

        return [
            RepeaterColumn::key('is_primary')->select($options)->value('false')
                ->label(static::relationText('is_primary'))->columnSpan(6),
            RepeaterColumn::key('receives_documents')->select($options)->value('false')
                ->label(static::relationText('receives_documents'))->columnSpan(6),
        ];
    }

    /** @param list<Input> $columns */
    protected static function relationRepeater(string $key, string $parentColumn, array $columns, string $type, string $addLabel): Input
    {
        return FormField::key($key)
            ->repeater($columns)
            ->relation(
                RepeaterRelation::make(ContactRelation::$table, $parentColumn)
                    ->model(ContactRelation::class)
                    ->positionKey('position')
                    ->softDelete(true)
            )
            ->nested()
            ->repeaterSortable()
            ->repeaterAddLabel($addLabel)
            ->visibleWhen('type', $type)
            ->label('');
    }

    /**
     * Le schede di un tipo per il select di una riga.
     *
     * @return array<int, string>
     */
    protected static function relationOptions(string $type): array
    {
        try {
            $rows = Contact::find(['type' => $type]);
        } catch (Throwable) {
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $options = [];

        foreach (isset($rows['id']) ? [$rows] : $rows as $row) {
            if (is_array($row) && isset($row['id'])) {
                $options[(int) $row['id']] = static::relationLabel($row);
            }
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    /** Come si legge una scheda nel select: nome, ed email se c'è. */
    protected static function relationLabel(array $row): string
    {
        $name = ($row['type'] ?? '') === 'business'
            ? trim((string) ($row['business_name'] ?? ''))
            : trim(((string) ($row['name'] ?? '')).' '.((string) ($row['surname'] ?? '')));
        $name = html_entity_decode($name !== '' ? $name : '#'.($row['id'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $email = trim((string) ($row['email'] ?? ''));

        return $email !== '' ? "{$name} · {$email}" : $name;
    }

    protected static function relationText(string $key): string
    {
        return (string) __t('contacts.'.$key);
    }

    /**
     * I valori della scheda creata al volo da una riga dei referenti: chi usa
     * il trait li ritocca (ruoli, codice, …).
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    protected static function relationPersonPayload(array $values): array
    {
        return $values;
    }

    public static function stripRelationInputValues(array $values): array
    {
        unset($values[self::RELATION_MARKER]);

        return parent::stripRelationInputValues($values);
    }

    public static function syncRepeaterRelations(
        int|string $parentId,
        array $post,
        array $files = [],
        string $action = 'store',
        string $context = 'backend'
    ): array {
        $ours = [self::RELATION_PEOPLE, self::RELATION_COMPANIES];
        $summary = [];

        // Il ciclo del core, senza i nostri due repeater.
        foreach (static::repeaterRelations() as $inputName => $entry) {
            if (in_array($inputName, $ours, true)) {
                continue;
            }

            $relation = $entry['relation'];
            $rows = static::prepareRepeaterRows($inputName, Repeater::rowsFromRequest($inputName, $post, $files), $action, $context);
            $summary[$inputName] = Repeater::syncRelatedRows(
                $relation,
                $parentId,
                $rows,
                fn (array $payload, array $row, ?array $existingRow): array => static::prepareRepeaterRelationRow(
                    $inputName, $payload, $row, $existingRow, $action, $context
                )
            );
        }

        if (ContactRelations::enabled() && isset($post[self::RELATION_MARKER])) {
            $relations = static::repeaterRelations();
            $contact = Contact::findById((int) $parentId);
            $isCompany = is_array($contact) && ($contact['type'] ?? '') === 'business';
            $inputName = $isCompany ? self::RELATION_PEOPLE : self::RELATION_COMPANIES;

            if (isset($relations[$inputName])) {
                $summary[$inputName] = static::syncContactRelations($relations[$inputName]['relation'], $inputName, (int) $parentId, $post, $files);
            }
        }

        return $summary;
    }

    protected static function syncContactRelations(RepeaterRelation $relation, string $inputName, int $parentId, array $post, array $files): array
    {
        return ContactRelations::sync(
            $relation->parentKey,
            $parentId,
            Repeater::rowsFromRequest($inputName, $post, $files),
            static fn (array $values): array => static::relationPersonPayload($values)
        );
    }
}
