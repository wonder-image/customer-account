<?php

namespace Wonder\Plugin\CustomerAccount\Resources\Concerns;

use InvalidArgumentException;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\Input;
use Wonder\App\ResourceSchema\RepeaterColumn;
use Wonder\Plugin\CustomerAccount\Support\ContactPoints;
use Wonder\Plugin\CustomerAccount\Support\ContactRelations;

/**
 * Il repeater «Altri contatti» di una Resource della rubrica: le email e i
 * telefoni in più della scheda. Quelli principali restano i campi della
 * scheda.
 *
 * Come i brand delle aziende non ha una `RepeaterRelation`: si legge in
 * `hydrateRepeaterFormValues()` e si scrive con `ContactPoints::sync()` in
 * `syncRepeaterRelations()` (tutti e due in `HasContactRelations`, che usa
 * questo trait), solo se il form l'ha mostrato (campo `contact_points_form`).
 * Gli errori (email non valida, doppioni) li dà `assertContactPoints()`, che
 * la Resource chiama in `mutateRequestValues()` prima di salvare la scheda.
 */
trait HasContactPoints
{
    public static string $pointsInput = 'contact_points';
    public static string $pointsMarker = 'contact_points_form';

    /** La scheda dei recapiti: un'azienda o una persona, come il lato dei legami. */
    public static function contactPointOwner(): string
    {
        return static::relationSide() === ContactRelations::COMPANY ? ContactPoints::COMPANY : ContactPoints::CONTACT;
    }

    /** @return list<Input> */
    public static function contactPointFields(): array
    {
        $kinds = [];

        foreach (ContactPoints::KINDS as $kind) {
            $kinds[$kind] = (string) __t('contacts.point_kind_'.$kind);
        }

        return [
            FormField::key(static::$pointsInput)
                ->repeater([
                    RepeaterColumn::key('kind')->select($kinds)->value(ContactPoints::EMAIL)
                        ->label((string) __t('contacts.point_kind'))->columnSpan(2),
                    RepeaterColumn::key('label')->select(['' => '—'] + ContactPoints::labels())->value('')
                        ->label((string) __t('contacts.point_label'))->columnSpan(3),
                    RepeaterColumn::key('phone_prefix')->text()
                        ->label((string) __t('contacts.point_prefix'))->columnSpan(2),
                    RepeaterColumn::key('value')->text()
                        ->label((string) __t('contacts.point_value'))->columnSpan(5),
                ])
                ->nested()
                ->repeaterSortable()
                ->repeaterAddLabel((string) __t('contacts.point_add'))
                ->label((string) __t('contacts.points_label')),
            FormField::key(static::$pointsMarker)->hidden()->value('1'),
        ];
    }

    /** Aggiunge al form i recapiti salvati, se il form li mostra e non arrivano già dal POST. */
    public static function withContactPointValues(array $values, int|string|null $parentId): array
    {
        $id = (int) ($parentId ?? 0);

        if ($id > 0 && !array_key_exists(static::$pointsInput, $values) && isset(static::safeFormFieldsByKey()[static::$pointsInput])) {
            $values[static::$pointsInput] = ContactPoints::of(static::contactPointOwner(), $id);
        }

        return $values;
    }

    /**
     * Rifiuta il salvataggio se un recapito in più non va: email non valida,
     * telefono non numerico, doppione (anche dell'email o del telefono
     * principali). Senza il campo `contact_points_form` non controlla niente.
     */
    protected static function assertContactPoints(array $values, ?array $oldValues = null): void
    {
        if (!isset($values[static::$pointsMarker])) {
            return;
        }

        $posted = $values[static::$pointsInput] ?? [];
        // Qui sì il controllo del dominio (DNS): è il form, che può dire cosa non va.
        [, $problems] = ContactPoints::clean(is_array($posted) ? array_values($posted) : [], $values + ($oldValues ?? []), true);

        if ($problems !== []) {
            throw new InvalidArgumentException(implode("\n", $problems));
        }
    }

    /** Scrive i recapiti postati, solo se il form li ha mostrati. */
    protected static function syncContactPoints(int $parentId, array $post): ?array
    {
        if (!isset($post[static::$pointsMarker])) {
            return null;
        }

        $posted = $post[static::$pointsInput] ?? [];

        return ContactPoints::sync(static::contactPointOwner(), $parentId, is_array($posted) ? array_values($posted) : []);
    }
}
