<?php

namespace Wonder\Plugin\CustomerAccount\Resources\Concerns;

use InvalidArgumentException;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\Input;
use Wonder\App\ResourceSchema\RepeaterColumn;
use Wonder\Elements\Components\RichText;
use Wonder\Plugin\CustomerAccount\Support\ContactFormScript;
use Wonder\Plugin\CustomerAccount\Support\ContactPoints;
use Wonder\Plugin\CustomerAccount\Support\ContactRelations;

/**
 * I contatti di una Resource della rubrica, in due forme.
 *
 * - Il riquadro «Contatti» (`contactBoxFields()`, marker `contact_box_form`):
 *   tutte le email e i telefoni, con «Principale». Prende il posto dei campi
 *   `email`, `phone_prefix`, `phone`: la Resource chiama
 *   `applyContactPoints()` in `mutateRequestValues()`, che rimette il
 *   principale in quei campi (così si salva con la scheda e passa dagli
 *   stessi controlli); le altre righe le scrive `ContactPoints::syncAll()`.
 * - «Altri contatti» (`contactPointFields()`, marker `contact_points_form`):
 *   solo quelli in più, accanto ai campi della scheda. Resta per chi non è
 *   ancora passato al riquadro.
 *
 * Come i brand delle aziende non ha una `RepeaterRelation`: si legge in
 * `hydrateRepeaterFormValues()` e si scrive in `syncRepeaterRelations()`
 * (tutti e due in `HasContactRelations`, che usa questo trait), solo se il
 * form l'ha mostrato. Senza marker un POST con i soli campi singoli funziona
 * come sempre.
 */
trait HasContactPoints
{
    public static string $pointsInput = 'contact_points';
    public static string $pointsMarker = 'contact_points_form';
    public static string $boxMarker = 'contact_box_form';

    /** La scheda dei recapiti: un'azienda o una persona, come il lato dei legami. */
    public static function contactPointOwner(): string
    {
        return static::relationSide() === ContactRelations::COMPANY ? ContactPoints::COMPANY : ContactPoints::CONTACT;
    }

    /**
     * Il riquadro «Contatti»: tipo, email (o prefisso e numero), principale;
     * etichetta e, per le aziende, «Si occupa di» stanno nelle informazioni
     * avanzate della riga. La colonna dell'altro tipo la nasconde il
     * framework (`visibleWhen('kind', …)`, lib 2.1.2-alpha.41): nascosta non
     * si valida e non si posta. Lo script del modulo (`contactFormScript()`)
     * spegne gli altri principali dello stesso tipo e blocca la riga
     * dell'email di accesso; senza script il server applica le stesse regole.
     * Una scheda che ha già un contatto non resta senza: l'ultima riga non si
     * elimina (`repeaterMinRows(1)`) e il server rifiuta un riquadro vuoto.
     *
     * @return list<Input>
     */
    public static function contactBoxFields(): array
    {
        $kinds = [];

        foreach (ContactPoints::KINDS as $kind) {
            $kinds[$kind] = (string) __t('contacts.point_kind_'.$kind);
        }

        $company = static::contactPointOwner() === ContactPoints::COMPANY;
        $labels = ['' => '—'] + ContactPoints::labels() + [ContactPoints::ACCESS_LABEL => (string) __t('contacts.point_label_access')];

        return [
            FormField::key(static::$pointsInput)
                ->repeater([
                    RepeaterColumn::key('kind')->select($kinds)->value(ContactPoints::EMAIL)->attribute('data-ca-point-kind')
                        ->label((string) __t('contacts.point_kind'))->columnSpan(3),
                    RepeaterColumn::key('email')->email()->attribute('data-ca-point-only="email"')
                        ->label((string) __t('contacts.point_kind_email'))->columnFill()
                        ->visibleWhen('kind', ContactPoints::EMAIL),
                    RepeaterColumn::key('phone_prefix')->phonePrefix()->attribute('data-ca-point-only="phone"')
                        ->label((string) __t('contacts.point_prefix'))->columnSpan(2)
                        ->visibleWhen('kind', ContactPoints::PHONE),
                    RepeaterColumn::key('phone')->tel()->attribute('data-ca-point-only="phone"')
                        ->label((string) __t('contacts.point_kind_phone'))->columnFill()
                        ->visibleWhen('kind', ContactPoints::PHONE),
                    RepeaterColumn::key('is_primary')
                        ->select(['false' => (string) __t('contacts.no'), 'true' => (string) __t('contacts.yes')])->value('false')
                        ->attribute('data-ca-point-primary')
                        ->label((string) __t('contacts.point_primary'))->columnSpan(2),
                    RepeaterColumn::key('label')->select($labels)->value('')->attribute('data-ca-point-label')
                        ->label((string) __t('contacts.point_label'))->columnSpan($company ? 4 : 12),
                    ...($company ? [
                        RepeaterColumn::key('departments')->selectSearch(ContactRelations::departments(), true)
                            ->label((string) __t('contacts.departments'))->columnSpan(8),
                    ] : []),
                    RepeaterColumn::key('locked')->hidden()->attribute('data-ca-point-locked'),
                ])
                ->nested()
                ->repeaterAdvanced('label', 'departments')
                ->repeaterAdvancedLabel((string) __t($company ? 'contacts.point_advanced_company' : 'contacts.point_advanced'))
                ->repeaterMinRows(1)
                ->repeaterAddLabel((string) __t('contacts.point_add'))
                ->label((string) __t('contacts.box_label')),
            FormField::key(static::$boxMarker)->hidden()->value('1'),
        ];
    }

    /**
     * «Altri contatti»: solo i contatti in più, accanto ai campi `email`,
     * `phone_prefix`, `phone` della scheda. Per i form che non usano ancora
     * `contactBoxFields()`.
     *
     * @return list<Input>
     */
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

    /**
     * Lo script del riquadro e della ricerca per nome negli indirizzi, da
     * mettere una volta nel layout del form. È un `RichText`: una stringa nei
     * componenti di una card il layout la scarta senza dire niente.
     */
    public static function contactFormScript(): RichText
    {
        return RichText::make(ContactFormScript::html())->tag('div')->columnSpan(12);
    }

    /** Il form mostra il riquadro «Contatti»? */
    public static function usesContactBox(): bool
    {
        return isset(static::safeFormFieldsByKey()[static::$boxMarker]);
    }

    /** Aggiunge al form i contatti salvati, se il form li mostra e non arrivano già dal POST. */
    public static function withContactPointValues(array $values, int|string|null $parentId): array
    {
        $id = (int) ($parentId ?? 0);

        if ($id <= 0 || array_key_exists(static::$pointsInput, $values) || !isset(static::safeFormFieldsByKey()[static::$pointsInput])) {
            return $values;
        }

        $owner = static::contactPointOwner();
        $values[static::$pointsInput] = static::usesContactBox()
            ? ContactPoints::formRows(ContactPoints::all($owner, $id), $owner === ContactPoints::COMPANY)
            : ContactPoints::of($owner, $id);

        return $values;
    }

    /**
     * Prima di salvare la scheda. Con il riquadro (`contact_box_form`) rimette
     * il principale di ogni tipo in `email`, `phone_prefix`, `phone`
     * (`ContactPoints::split()`, con il controllo del dominio): quei valori
     * vincono su eventuali campi singoli postati insieme. Una persona con un
     * account tiene la sua email (`ContactPoints::lockedEmail()`, letta da
     * `$oldValues`, cioè dal database): `email` esce dai valori. Con «Altri
     * contatti» controlla soltanto. Senza marker non fa niente: i campi
     * singoli passano come prima.
     *
     * @throws InvalidArgumentException con i problemi, uno per riga
     */
    protected static function applyContactPoints(array $values, ?array $oldValues = null): array
    {
        if (!isset($values[static::$boxMarker])) {
            static::assertContactPoints($values, $oldValues);

            return $values;
        }

        $posted = $values[static::$pointsInput] ?? [];
        // Qui sì il controllo del dominio (DNS): è il form, che può dire cosa non va.
        $split = ContactPoints::split(static::contactPointOwner(), is_array($posted) ? array_values($posted) : [], $oldValues ?? [], true);

        if ($split['problems'] !== []) {
            throw new InvalidArgumentException(implode("\n", $split['problems']));
        }

        // Una scheda che aveva un contatto non resta senza: l'ultimo si cambia, non si toglie.
        if ($split['rows'] === [] && $split['kept'] === [] && static::hadContact($oldValues ?? [])
            && ContactPoints::lockedEmail(static::contactPointOwner(), $oldValues ?? []) === null) {
            throw new InvalidArgumentException((string) __t('contacts.point_required'));
        }

        // Il riquadro vince sui campi singoli; un tipo che non tocca resta com'è (vedi `split()`).
        unset($values['email'], $values['phone_prefix'], $values['phone']);

        return $split['columns'] + $values;
    }

    /** La scheda salvata ha un'email o un telefono (l'email di accesso compresa)? */
    protected static function hadContact(array $oldValues): bool
    {
        foreach (['email', 'phone'] as $column) {
            if (is_scalar($oldValues[$column] ?? null) && trim((string) $oldValues[$column]) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * L'email di accesso non si cambia dai form del backend: se la scheda
     * salvata (`$oldValues`, dal database) è di una persona con un account
     * (`ContactPoints::lockedEmail()`), l'`email` postata si scarta, con o
     * senza riquadro. La riscrivono solo l'account (`ContactAccount::link()`,
     * il cambio email del pannello, la registrazione), che non passano dalle
     * Resource. `ContactResource::mutateRequestValues()` lo chiama sempre (e
     * così le Resource che la estendono); chi ha un suo `mutateRequestValues()`
     * lo chiama o applica una regola sua (il gestionale rifiuta il cambio).
     */
    protected static function withoutAccessEmail(array $values, ?array $oldValues): array
    {
        if (ContactPoints::lockedEmail(static::contactPointOwner(), $oldValues ?? []) !== null) {
            unset($values['email']);
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

    /** Scrive i contatti postati, solo se il form li ha mostrati. */
    protected static function syncContactPoints(int $parentId, array $post): ?array
    {
        $posted = $post[static::$pointsInput] ?? [];
        $rows = is_array($posted) ? array_values($posted) : [];

        if (isset($post[static::$boxMarker])) {
            return ContactPoints::syncAll(static::contactPointOwner(), $parentId, $rows);
        }

        if (!isset($post[static::$pointsMarker])) {
            // I campi singoli possono aver cambiato l'email principale: le aree del valore vecchio non restano a nessuno.
            if (static::contactPointOwner() === ContactPoints::COMPANY) {
                ContactPoints::dropOrphanDepartments($parentId);
            }

            return null;
        }

        return ContactPoints::sync(static::contactPointOwner(), $parentId, $rows);
    }
}
