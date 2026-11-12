<?php

namespace Wonder\Plugin\CustomerAccount\Resources\Concerns;

use InvalidArgumentException;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\Input;
use Wonder\App\ResourceSchema\RepeaterColumn;
use Wonder\Elements\Components\RichText;
use Wonder\Plugin\CustomerAccount\Support\ContactFormScript;
use Wonder\Plugin\CustomerAccount\Support\ContactPoints;
use Wonder\Plugin\CustomerAccount\Resources\OfficeTypeResource;
use Wonder\Plugin\CustomerAccount\Support\ContactRelations;
use Wonder\Plugin\CustomerAccount\Support\Crm;
use Wonder\Plugin\CustomerAccount\Support\EmptyRepeater;

/**
 * I contatti di una Resource della rubrica.
 *
 * - Il riquadro «Contatti» (`contactBoxFields()`, marker `contact_box_form`):
 *   una riga per recapito con il tipo (`ContactPoints::kindOptions()`),
 *   l'email oppure prefisso e numero, e l'«Ufficio» (uno solo, postato come
 *   `department`; `contactBoxOffice()` lo toglie). Prende il posto dei campi
 *   `email`, `phone_prefix`, `phone`: la Resource chiama
 *   `applyContactPoints()` in `mutateRequestValues()`, che rimette il
 *   principale (la prima riga di ogni famiglia) in quei campi; le altre righe
 *   le scrive `ContactPoints::syncAll()`.
 * - Con il CRM spento (`contactBoxSimple()`) il riquadro è un'email e un
 *   telefono soli, campi normali della scheda con il marker a `single`: si
 *   salvano con `primary_only`, così i recapiti in più già salvati restano.
 * - L'email di accesso a parte (`separateAccessEmail()`): il campo `email`
 *   sta fuori dal riquadro, che non la mostra fra le righe e non la scrive.
 * - `contactBoxByKindFields()` è il riquadro di prima diviso per tipo
 *   (`contact_points_email`, `contact_points_phone`): al salvataggio tornano
 *   un solo `contact_points` (`foldContactPointKinds()`).
 * - «Altri contatti» (`contactPointFields()`, marker `contact_points_form`):
 *   solo quelli in più, accanto ai campi della scheda.
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

    /** Il valore del marker quando il riquadro è un'email e un telefono soli. */
    public const BOX_SINGLE = 'single';

    /** La Resource del «+» accanto a «Ufficio»: un modulo la cambia per i suoi permessi. */
    public static function officeTypeResource(): string
    {
        return OfficeTypeResource::class;
    }

    /** Il riquadro è un'email e un telefono soli? Sì con il CRM spento. */
    public static function contactBoxSimple(): bool
    {
        return !Crm::enabled();
    }

    /** Le righe del riquadro hanno l'«Ufficio»? */
    public static function contactBoxOffice(): bool
    {
        return true;
    }

    /** L'email della scheda sta in un campo a parte, fuori dal riquadro (l'email di accesso di una persona)? */
    public static function separateAccessEmail(): bool
    {
        return false;
    }

    /**
     * «Ufficio» di una riga: uno solo, con la ricerca e il «+» per crearne
     * (gli stessi dei referenti). Si posta come `department`.
     */
    protected static function officeColumn(int $span): Input
    {
        return RepeaterColumn::key('department')
            ->selectSearch(ContactRelations::departments())
            ->value(ContactRelations::GENERAL)
            ->quickCreate(static::officeTypeResource(), ['name'], null, 'name', (string) __t('contacts.office_type_add'))
            ->quickCreateCompact()
            ->label((string) __t('contacts.office_type'))
            ->columnSpan($span);
    }

    /** «Ufficio» a scelta multipla, per i riquadri di prima (`departments`). */
    protected static function pointOfficeTypeColumn(int $span): Input
    {
        return RepeaterColumn::key('departments')
            ->selectSearch(ContactRelations::departments(), true)
            ->quickCreate(static::officeTypeResource(), ['name'], null, 'name', (string) __t('contacts.office_type_add'))
            ->quickCreateCompact()
            ->label((string) __t('contacts.office_type'))
            ->columnSpan($span);
    }

    /**
     * Le colonne di una riga di recapito: tipo, email oppure prefisso e
     * numero (la colonna dell'altro tipo la nasconde il framework con
     * `visibleWhen('kind', …)`: nascosta non si valida e non si posta).
     * Le usa anche il repeater dei recapiti di un referente.
     *
     * @return list<Input>
     */
    protected static function pointColumns(bool $office = false): array
    {
        return [
            RepeaterColumn::key('kind')->select(ContactPoints::kindOptions())->value(ContactPoints::EMAIL)->attribute('data-ca-point-kind')
                ->label((string) __t('contacts.point_kind'))->columnSpan(3),
            RepeaterColumn::key('email')->email()->attribute('data-ca-point-only="email"')
                ->label((string) __t('contacts.point_kind_email'))->columnFill()
                ->visibleWhen('kind', ContactPoints::EMAIL),
            RepeaterColumn::key('phone_prefix')->phonePrefix()->attribute('data-ca-point-only="phone"')
                ->label((string) __t('contacts.point_prefix'))->columnSpan($office ? 2 : 3)
                ->visibleWhen('kind', ContactPoints::PHONE_KINDS),
            RepeaterColumn::key('phone')->tel()->attribute('data-ca-point-only="phone"')
                ->label((string) __t('contacts.point_kind_phone'))->columnFill()
                ->visibleWhen('kind', ContactPoints::PHONE_KINDS),
            ...($office ? [static::officeColumn(3)] : []),
        ];
    }

    /**
     * Il riquadro «Contatti». Con il CRM acceso una riga per recapito (vedi
     * `pointColumns()`); lo script del modulo (`contactFormScript()`) blocca
     * la riga dell'email di accesso, e senza script il server applica la
     * stessa regola. Il principale non si sceglie: è la prima riga di ogni
     * famiglia. Una scheda che ha già un contatto non resta senza: l'ultima
     * riga non si elimina (`repeaterMinRows(1)`) e il server rifiuta un
     * riquadro vuoto. Con il CRM spento un'email e un telefono soli.
     * Con `separateAccessEmail()` il campo `email` viene prima, a parte.
     *
     * @return list<Input>
     */
    public static function contactBoxFields(): array
    {
        $separate = static::separateAccessEmail();
        $access = $separate ? [static::accessEmailField()] : [];

        if (static::contactBoxSimple()) {
            return [
                ...($separate ? $access : [FormField::key('email')->email()->label((string) __t('contacts.point_kind_email'))]),
                FormField::key('phone_prefix')->phonePrefix()->label((string) __t('contacts.point_prefix')),
                FormField::key('phone')->tel()->label((string) __t('contacts.point_kind_phone')),
                FormField::key(static::$boxMarker)->hidden()->value(self::BOX_SINGLE),
            ];
        }

        $box = FormField::key(static::$pointsInput)
            ->repeater([
                ...static::pointColumns(static::contactBoxOffice()),
                RepeaterColumn::key('locked')->hidden()->attribute('data-ca-point-locked'),
            ])
            ->nested()
            ->repeaterAddLabel((string) __t('contacts.point_add'))
            ->label((string) __t('contacts.box_label'));

        // Con l'email a parte il riquadro può restare vuoto: il contatto è quella.
        if (!$separate) {
            $box->repeaterMinRows(1);
        }

        return [...$access, $box, FormField::key(static::$boxMarker)->hidden()->value('1')];
    }

    /** Il campo dell'email di accesso, fuori dal riquadro. */
    protected static function accessEmailField(): Input
    {
        return FormField::key('email')->email()->label((string) __t('contacts.point_label_access'));
    }

    /**
     * Le chiavi degli elenchi per tipo, `tipo => chiave`.
     *
     * @return array<string, string>
     */
    public static function pointKindInputs(): array
    {
        $out = [];

        foreach (ContactPoints::KINDS as $kind) {
            $out[$kind] = static::$pointsInput.'_'.$kind;
        }

        return $out;
    }

    /**
     * Il riquadro «Contatti» diviso per tipo: un elenco per le email e uno per
     * i telefoni, senza la tendina del tipo. Le regole sono quelle di
     * `contactBoxFields()` (stesso marker, stesso script); il minimo di una
     * riga qui non c'è, perché varrebbe per ogni elenco: una scheda che aveva
     * un contatto non resta senza perché lo rifiuta il server.
     *
     * @return list<Input>
     */
    public static function contactBoxByKindFields(): array
    {
        $fields = [];

        foreach (static::pointKindInputs() as $kind => $key) {
            $value = $kind === ContactPoints::EMAIL
                ? [
                    RepeaterColumn::key('email')->email()->attribute('data-ca-point-only="email"')
                        ->label((string) __t('contacts.point_kind_email'))->columnFill(),
                ]
                : [
                    RepeaterColumn::key('phone_prefix')->phonePrefix()->attribute('data-ca-point-only="phone"')
                        ->label((string) __t('contacts.point_prefix'))->columnSpan(3),
                    RepeaterColumn::key('phone')->tel()->attribute('data-ca-point-only="phone"')
                        ->label((string) __t('contacts.point_kind_phone'))->columnFill(),
                ];

            $fields[] = FormField::key($key)
                ->repeater([
                    RepeaterColumn::key('kind')->hidden()->value($kind)->attribute('data-ca-point-kind="'.$kind.'"'),
                    ...$value,
                    static::pointOfficeTypeColumn($kind === ContactPoints::EMAIL ? 5 : 4),
                    RepeaterColumn::key('locked')->hidden()->attribute('data-ca-point-locked'),
                ])
                ->nested()
                ->repeaterAddLabel((string) __t('contacts.point_add_'.$kind))
                ->label((string) __t('contacts.point_kind_'.$kind));
        }

        $fields[] = FormField::key(static::$boxMarker)->hidden()->value('1');

        return $fields;
    }

    /**
     * Gli elenchi per tipo tornano un solo `contact_points`, con il tipo
     * dell'elenco (quello postato nella riga non conta), e le loro chiavi
     * escono dai valori: da qui in poi il riquadro è uno, comunque sia stato
     * disegnato.
     */
    public static function foldContactPointKinds(array $data): array
    {
        $rows = null;

        foreach (static::pointKindInputs() as $kind => $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $rows ??= [];

            foreach (is_array($data[$key]) ? $data[$key] : [] as $row) {
                if (is_array($row)) {
                    $rows[] = ['kind' => $kind] + $row;
                }
            }

            unset($data[$key]);
        }

        if ($rows !== null) {
            $before = $data[static::$pointsInput] ?? [];
            $data[static::$pointsInput] = [...(is_array($before) ? array_values($before) : []), ...$rows];
        }

        return $data;
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
            EmptyRepeater::on(FormField::key(static::$pointsInput)
                ->repeater([
                    RepeaterColumn::key('kind')->select($kinds)->value(ContactPoints::EMAIL)
                        ->label((string) __t('contacts.point_kind'))->columnSpan(2),
                    static::pointOfficeTypeColumn(3),
                    RepeaterColumn::key('phone_prefix')->text()
                        ->label((string) __t('contacts.point_prefix'))->columnSpan(2),
                    RepeaterColumn::key('value')->text()
                        ->label((string) __t('contacts.point_value'))->columnSpan(5),
                ])
                ->nested()
                ->repeaterSortable()
                ->repeaterAddLabel((string) __t('contacts.point_add'))
                ->label((string) __t('contacts.points_label'))),
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

    /**
     * Aggiunge al form i contatti salvati, se il form li mostra e non arrivano
     * già dal POST. Con gli elenchi per tipo ogni elenco prende le sue righe;
     * dopo un salvataggio rifiutato (`$post` con il marker) un elenco che non
     * è arrivato resta vuoto: le righe tolte non tornano dal database.
     */
    public static function withContactPointValues(array $values, int|string|null $parentId, ?array $post = null): array
    {
        $id = (int) ($parentId ?? 0);
        $fields = static::safeFormFieldsByKey();
        $kinds = array_filter(static::pointKindInputs(), static fn (string $key): bool => isset($fields[$key]));

        if ($kinds !== []) {
            $missing = array_filter($kinds, static fn (string $key): bool => !array_key_exists($key, $values));

            if ($missing === []) {
                return $values;
            }

            $owner = static::contactPointOwner();
            $rows = $id > 0 && !isset($post[static::$boxMarker])
                ? ContactPoints::formRows(ContactPoints::all($owner, $id), true)
                : [];

            foreach ($missing as $kind => $key) {
                $values[$key] = array_values(array_filter($rows, static fn (array $row): bool => $row['kind'] === $kind));
            }

            return $values;
        }

        if ($id <= 0 || array_key_exists(static::$pointsInput, $values) || !isset($fields[static::$pointsInput])) {
            return $values;
        }

        $owner = static::contactPointOwner();

        if (!static::usesContactBox()) {
            $values[static::$pointsInput] = ContactPoints::of($owner, $id);
        } elseif (static::separateAccessEmail()) {
            $values[static::$pointsInput] = ContactPoints::formRowsWithoutAccess($owner, $id, null, static::contactBoxOffice());
        } else {
            $values[static::$pointsInput] = ContactPoints::formRows(ContactPoints::all($owner, $id), static::contactBoxOffice());
        }

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
        $values = static::foldContactPointKinds($values);

        if (!isset($values[static::$boxMarker])) {
            static::assertContactPoints($values, $oldValues);

            return $values;
        }

        $owner = static::contactPointOwner();
        $id = (int) ($oldValues['id'] ?? 0);
        $rows = static::postedContactRows($values, $oldValues);
        $options = static::contactPointOptions($values, true, $rows);
        // Qui sì il controllo del dominio (DNS): è il form, che può dire cosa non va.
        $split = ContactPoints::split($owner, $rows, $oldValues ?? [], true, $id > 0 ? ContactPoints::of($owner, $id) : [], $options);

        if ($split['problems'] !== []) {
            throw new InvalidArgumentException(implode("\n", $split['problems']));
        }

        $separate = array_key_exists('separate_email', $options);
        // L'email a parte è un contatto: quella postata, o quella salvata se il campo non arriva.
        $email = $separate ? (array_key_exists('email', $values) ? $values['email'] : ($oldValues['email'] ?? '')) : '';
        $hasEmail = is_scalar($email) && trim((string) $email) !== '';

        // Una scheda che aveva un contatto non resta senza: l'ultimo si cambia, non si toglie.
        if ($split['rows'] === [] && $split['kept'] === [] && !$hasEmail && static::hadContact($oldValues ?? [])
            && ContactPoints::lockedEmail($owner, $oldValues ?? []) === null) {
            throw new InvalidArgumentException((string) __t('contacts.point_required'));
        }

        // Il riquadro vince sui campi singoli; un tipo che non tocca resta com'è (vedi `split()`).
        // L'email a parte resta nei valori: la salva la scheda, con i suoi controlli.
        $kept = [];

        if ($separate && array_key_exists('email', $values)) {
            // Come un contatto del riquadro: minuscolo, senza spazi, con il formato giusto.
            $clean = is_scalar($values['email']) ? strtolower(trim((string) $values['email'])) : '';

            if ($clean !== '' && !ContactPoints::emailFormat($clean)) {
                throw new InvalidArgumentException((string) __t('contacts.point_email_invalid', ['value' => $clean]));
            }

            $kept = ['email' => $clean];
        }

        unset($values['email'], $values['phone_prefix'], $values['phone']);

        return $split['columns'] + $kept + $values;
    }

    /**
     * Le righe postate dal riquadro. Con un'email e un telefono soli
     * (`BOX_SINGLE`) si ricavano dai campi della scheda; il telefono tiene il
     * tipo salvato.
     *
     * @return list<mixed>
     */
    protected static function postedContactRows(array $values, ?array $oldValues = null): array
    {
        if (($values[static::$boxMarker] ?? null) !== self::BOX_SINGLE) {
            $posted = $values[static::$pointsInput] ?? [];

            return is_array($posted) ? array_values($posted) : [];
        }

        $rows = [];

        if (!static::separateAccessEmail()) {
            $rows[] = ['kind' => ContactPoints::EMAIL, 'email' => $values['email'] ?? ''];
        }

        $kind = (string) ($oldValues['phone_kind'] ?? '');
        $rows[] = [
            'kind' => ContactPoints::isPhone($kind) ? $kind : ContactPoints::PHONE,
            'phone_prefix' => $values['phone_prefix'] ?? '',
            'phone' => $values['phone'] ?? '',
        ];

        return $rows;
    }

    /**
     * Le opzioni di `ContactPoints::split()` e `syncAll()` per il riquadro
     * postato: il principale è la prima riga (il form non lo fa scegliere; da
     * codice una riga con `is_primary` vale ancora); con un'email e un
     * telefono soli contano solo quelli (`primary_only`); con l'email a parte
     * vale quella postata (prima di salvare la scheda) o quella salvata (dopo).
     *
     * @param list<mixed> $rows le righe postate
     * @return array{first_primary: bool, primary_only: bool, separate_email?: bool|string}
     */
    protected static function contactPointOptions(array $values, bool $beforeSave = false, array $rows = []): array
    {
        $flagged = array_filter($rows, static fn ($row): bool => is_array($row) && array_key_exists('is_primary', $row));
        $options = [
            'first_primary' => $flagged === [],
            'primary_only' => ($values[static::$boxMarker] ?? null) === self::BOX_SINGLE,
        ];

        if (static::separateAccessEmail()) {
            $options['separate_email'] = $beforeSave && array_key_exists('email', $values)
                ? (is_scalar($values['email']) ? trim((string) $values['email']) : '')
                : true;
        }

        return $options;
    }

    /** La scheda com'è nel database, o null. */
    private static function savedCard(int $id): ?array
    {
        $card = $id > 0 ? static::$model::findById($id) : null;

        return is_array($card) && isset($card['id']) ? $card : null;
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
        $post = static::foldContactPointKinds($post);
        $posted = $post[static::$pointsInput] ?? [];
        $rows = is_array($posted) ? array_values($posted) : [];

        if (isset($post[static::$boxMarker])) {
            $rows = static::postedContactRows($post, static::savedCard($parentId));

            return ContactPoints::syncAll(static::contactPointOwner(), $parentId, $rows, static::contactPointOptions($post, false, $rows));
        }

        if (!isset($post[static::$pointsMarker])) {
            // I campi singoli possono aver cambiato l'email principale: i tipi del valore vecchio non restano a nessuno.
            ContactPoints::dropOrphanDepartments($parentId, static::contactPointOwner());

            return null;
        }

        return ContactPoints::sync(static::contactPointOwner(), $parentId, $rows);
    }
}
