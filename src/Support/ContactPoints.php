<?php

namespace Wonder\Plugin\CustomerAccount\Support;

use RuntimeException;
use Throwable;
use Wonder\App\Support\RequestMemo;
use Wonder\Data\Validators\EmailValidator;
use Wonder\Plugin\CustomerAccount\Models\CompanyPoint;
use Wonder\Plugin\CustomerAccount\Models\CompanyPointDepartment;
use Wonder\Plugin\CustomerAccount\Models\ContactPoint;
use Wonder\Sql\Transaction;

/**
 * Le email e i telefoni di una persona o di un'azienda: l'unico punto che li
 * legge e li scrive.
 *
 * Il principale di ogni tipo resta nelle colonne della scheda (`email`,
 * `phone_prefix`, `phone`), che il resto del sistema legge; gli altri stanno
 * in `contact_points` / `company_points`. Il riquadro «Contatti» li mostra
 * insieme (`all()`) e li riscrive insieme (`split()`, `syncAll()`): al
 * massimo un principale per tipo, la prima riga se nessuna lo è, e chi perde
 * il titolo diventa una riga normale. `of()` e `sync()` lavorano ancora sui
 * soli contatti in più, per chi mostra email e telefono come campi a parte.
 *
 * Un contatto non principale è un dato di contatto e basta: non serve mai per
 * l'accesso né per collegare un account (`ContactAccount` guarda solo
 * `contacts.email`). Una persona con un account tiene `contacts.email`: dal
 * riquadro non si cambia (vedi `lockedEmail()`).
 */
final class ContactPoints
{
    public const CONTACT = 'contact';
    public const COMPANY = 'company';

    public const EMAIL = 'email';
    public const PHONE = 'phone';
    public const KINDS = [self::EMAIL, self::PHONE];

    /** Le etichette di base; altre si aggiungono con `addLabel()`. */
    public const LABELS = ['work', 'personal', 'billing', 'other'];

    /** L'etichetta della sola riga bloccata: l'email con cui la persona accede. Non si sceglie. */
    public const ACCESS_LABEL = 'access';

    /** @var array<string, string> etichette aggiunte da moduli e siti: chiave => testo */
    private static array $extraLabels = [];

    /** @return class-string<ContactPoint|CompanyPoint> */
    public static function model(string $owner): string
    {
        return match ($owner) {
            self::CONTACT => ContactPoint::class,
            self::COMPANY => CompanyPoint::class,
            default => throw new RuntimeException('Scheda sconosciuta: '.$owner),
        };
    }

    /**
     * Le etichette per un select: quelle di base e quelle aggiunte.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $out = [];

        foreach (self::LABELS as $key) {
            $out[$key] = (string) __t('contacts.point_label_'.$key);
        }

        return $out + self::$extraLabels;
    }

    /** Aggiunge un'etichetta (es. `pec`, `orders`). Un modulo la chiama al boot, un sito in `custom/function`. */
    public static function addLabel(string $key, string $label): void
    {
        $key = strtolower(trim($key));

        if (preg_match('/^[a-z0-9_]{1,40}$/', $key) === 1 && $key !== self::ACCESS_LABEL) {
            self::$extraLabels[$key] = $label;
        }
    }

    /** Un'etichetta valida, altrimenti vuoto. `access` non lo è: la mette solo `all()`. */
    public static function label(mixed $value): string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return array_key_exists($value, self::labels()) ? $value : '';
    }

    /**
     * I contatti in più di una scheda (non i principali), nell'ordine scelto.
     *
     * @param string|null $kind solo `email` o solo `phone`
     * @return list<array{kind: string, value: string, phone_prefix: string, label: string}>
     */
    public static function of(string $owner, int $ownerId, ?string $kind = null): array
    {
        $rows = self::byOwners($owner, [$ownerId])[$ownerId] ?? [];

        return $kind === null ? $rows : array_values(array_filter($rows, static fn (array $row): bool => $row['kind'] === $kind));
    }

    /**
     * I contatti in più di più schede, in una query.
     *
     * @param list<int> $ownerIds
     * @return array<int, list<array{kind: string, value: string, phone_prefix: string, label: string}>>
     */
    public static function byOwners(string $owner, array $ownerIds): array
    {
        $model = self::model($owner);
        $ownerIds = array_values(array_unique(array_filter(array_map('intval', $ownerIds), static fn (int $id): bool => $id > 0)));
        $out = [];

        if ($ownerIds === []) {
            return $out;
        }

        try {
            $rows = self::rows($model::find([$model::ownerKey() => $ownerIds], null, 'position', 'ASC'));
        } catch (Throwable) {
            return $out;
        }

        foreach ($rows as $row) {
            $out[(int) $row[$model::ownerKey()]][] = self::shape($row);
        }

        return $out;
    }

    /**
     * Tutte le righe del riquadro «Contatti»: prima l'email e il telefono
     * principali (dalle colonne della scheda), poi gli altri nell'ordine
     * scelto. Ogni riga ha `is_primary`, `locked` (l'email di accesso di una
     * persona con account, etichetta `access`) e `departments` (le aree, solo
     * per le aziende; vuota = tutto).
     *
     * @param array<string, mixed>|null $card la scheda, se chi chiama l'ha già letta
     * @return list<array{kind: string, value: string, phone_prefix: string, label: string, is_primary: bool, locked: bool, departments: list<string>}>
     */
    public static function all(string $owner, int $ownerId, ?array $card = null): array
    {
        $model = self::model($owner);

        if ($card === null || (int) ($card['id'] ?? 0) !== $ownerId) {
            $card = $ownerId > 0 ? $model::ownerModel()::findById($ownerId) : null;
        }

        if (!is_array($card) || (int) ($card['id'] ?? 0) <= 0) {
            return [];
        }

        $areas = $owner === self::COMPANY ? self::departmentsByValue($ownerId) : [];
        $locked = self::lockedEmail($owner, $card);
        $out = [];

        foreach (self::primaryRows($card) as $row) {
            $isLocked = $row['kind'] === self::EMAIL && $locked !== null;
            $out[] = $row + [
                'label' => $isLocked ? self::ACCESS_LABEL : '',
                'is_primary' => true,
                'locked' => $isLocked,
                'departments' => $areas[$row['kind'].'|'.$row['value']] ?? [],
            ];
        }

        $points = RequestMemo::remember($model::$table, 'owner:'.$ownerId, static fn (): array => self::of($owner, $ownerId));

        foreach ($points as $row) {
            $out[] = $row + ['is_primary' => false, 'locked' => false, 'departments' => $areas[$row['kind'].'|'.$row['value']] ?? []];
        }

        return $out;
    }

    /**
     * Le righe di `all()` come le vuole il repeater del riquadro: email e
     * numero in colonne separate, `is_primary` come `'true'` / `'false'`.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function formRows(array $rows, bool $withDepartments = false): array
    {
        $out = [];

        foreach ($rows as $row) {
            $email = ($row['kind'] ?? '') === self::EMAIL;
            $form = [
                'kind' => $email ? self::EMAIL : self::PHONE,
                'email' => $email ? (string) $row['value'] : '',
                'phone_prefix' => $email ? '' : (string) ($row['phone_prefix'] ?? ''),
                'phone' => $email ? '' : (string) $row['value'],
                'label' => (string) ($row['label'] ?? ''),
                'is_primary' => !empty($row['is_primary']) ? 'true' : 'false',
                'locked' => !empty($row['locked']) ? '1' : '',
            ];

            if ($withDepartments) {
                $form['departments'] = $row['departments'] ?? [];
            }

            $out[] = $form;
        }

        return $out;
    }

    /**
     * Le righe postate ripulite, con i problemi da mostrare. Una riga senza
     * valore si salta (la riga vuota del repeater). L'email deve avere un
     * formato valido (`emailFormat()`, senza rete); con `$checkDomain` passa
     * anche dal validatore del framework, che interroga il DNS: lo fa solo il
     * form (`HasContactPoints`), mai la scrittura, così un DNS che non
     * risponde non cancella recapiti salvati. Il telefono tiene solo le cifre
     * e il prefisso (`+` e fino a quattro cifre). Niente doppioni sulla
     * scheda, contando anche l'email e il telefono principali (`$primary`:
     * `email`, `phone_prefix`, `phone`).
     *
     * @param list<mixed> $rows
     * @param array<string, mixed> $primary
     * @return array{0: list<array{kind: string, value: string, phone_prefix: string, label: string}>, 1: list<string>}
     */
    public static function clean(array $rows, array $primary = [], bool $checkDomain = false): array
    {
        $seen = [];

        foreach (self::primaryRows($primary) as $row) {
            $seen[$row['kind'].'|'.$row['value']] = true;
        }

        $out = [];
        $problems = [];

        foreach ($rows as $row) {
            [$clean, $problem] = self::cleanRow($row, $checkDomain);

            if ($problem !== null) {
                $problems[] = $problem;
                continue;
            }

            if ($clean === null) {
                continue;
            }

            // Il prefisso non conta: lo stesso numero con o senza prefisso è un doppione.
            $key = $clean['kind'].'|'.$clean['value'];

            if (isset($seen[$key])) {
                $problems[] = (string) __t('contacts.point_duplicate', ['value' => mb_substr((string) $clean['value'], 0, 80)]);
                continue;
            }

            $seen[$key] = true;
            $out[] = $clean;
        }

        return [$out, $problems];
    }

    /**
     * Le righe del riquadro «Contatti» (principale compreso) divise fra le
     * colonne della scheda e le righe in più. Una riga ha `kind`, il valore
     * (`email` o `phone`, oppure `value` come le righe di `sync()`),
     * `phone_prefix`, `label`, `is_primary` e, per le aziende, `departments`.
     *
     * La regola sta qui e non nel form: al massimo un principale per tipo (il
     * primo segnato; gli altri tornano righe normali), la prima riga del tipo
     * se nessuna è segnata, nessuna riga del tipo = colonne vuote. Con
     * `lockedEmail()` l'email della scheda non cambia: la sua riga si salta,
     * le altre email restano righe normali e `columns` non porta `email`.
     * Le colonne `locked` e `label = access` postate non contano.
     *
     * @param list<mixed> $rows
     * @param array<string, mixed> $card la scheda salvata (`user_id`, `email`); vuota per una scheda nuova
     * @return array{columns: array<string, string>, rows: list<array<string, mixed>>, problems: list<string>}
     */
    public static function split(string $owner, array $rows, array $card = [], bool $checkDomain = false): array
    {
        self::model($owner);
        $locked = self::lockedEmail($owner, $card);
        $kept = [];
        $seen = [];
        $problems = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            [$clean, $problem] = self::cleanRow(self::boxRow($row), $checkDomain);

            if ($problem !== null) {
                $problems[] = $problem;
                continue;
            }

            if ($clean === null || ($clean['kind'] === self::EMAIL && $locked !== null && $clean['value'] === $locked)) {
                continue;
            }

            $key = $clean['kind'].'|'.$clean['value'];

            if (isset($seen[$key])) {
                $problems[] = (string) __t('contacts.point_duplicate', ['value' => mb_substr((string) $clean['value'], 0, 80)]);
                continue;
            }

            $seen[$key] = true;
            $clean['is_primary'] = ContactRelations::flag($row['is_primary'] ?? 'false') === 'true';
            // Senza la chiave le aree restano quelle di prima; il select svuotato posta `['']`.
            $clean['departments'] = $owner === self::COMPANY && array_key_exists('departments', $row)
                ? ContactRelations::cleanDepartments($row['departments'])
                : null;
            $kept[] = $clean;
        }

        $columns = [];

        foreach (self::KINDS as $kind) {
            $primary = null;

            foreach ($kept as $i => $row) {
                if ($row['kind'] !== $kind) {
                    continue;
                }

                if ($kind === self::EMAIL && $locked !== null) {
                    $kept[$i]['is_primary'] = false;
                    continue;
                }

                if ($row['is_primary'] && $primary === null) {
                    $primary = $i;
                }

                $kept[$i]['is_primary'] = false;
            }

            if ($kind === self::EMAIL && $locked !== null) {
                continue;
            }

            // Nessuna segnata: la prima del tipo.
            if ($primary === null) {
                foreach ($kept as $i => $row) {
                    if ($row['kind'] === $kind) {
                        $primary = $i;
                        break;
                    }
                }
            }

            if ($primary !== null) {
                $kept[$primary]['is_primary'] = true;
            }

            $row = $primary !== null ? $kept[$primary] : null;

            if ($kind === self::EMAIL) {
                $columns['email'] = (string) ($row['value'] ?? '');
            } else {
                $columns['phone_prefix'] = (string) ($row['phone_prefix'] ?? '');
                $columns['phone'] = (string) ($row['value'] ?? '');
            }
        }

        return ['columns' => $columns, 'rows' => $kept, 'problems' => $problems];
    }

    /**
     * L'email che il riquadro non cambia: quella di una persona con un
     * account collegato (`user_id`). È l'email su cui `ContactAccount`
     * riconosce la scheda: cambiarla da qui sposterebbe l'aggancio, e una
     * riga in più non deve mai diventarlo. Null = nessun blocco (le aziende
     * non hanno account; una scheda nuova nemmeno).
     */
    public static function lockedEmail(string $owner, array $card): ?string
    {
        if ($owner !== self::CONTACT || (int) ($card['user_id'] ?? 0) <= 0) {
            return null;
        }

        return self::email($card['email'] ?? '');
    }

    /**
     * Scrive i contatti in più di una scheda: quelli non elencati si tolgono.
     * Le righe che non passano `clean()` si scartano (il form le ha già
     * rifiutate con un messaggio); l'email e il telefono principali si leggono
     * dalla scheda e non cambiano. Gli id postati non contano: un contatto si
     * riconosce dal valore, così una riga non può puntare a quella di un'altra
     * scheda.
     *
     * @param list<mixed> $rows
     * @return list<array{kind: string, value: string, phone_prefix: string, label: string}> i contatti rimasti
     */
    public static function sync(string $owner, int $ownerId, array $rows): array
    {
        $model = self::model($owner);
        $card = $ownerId > 0 ? $model::ownerModel()::findById($ownerId) : null;

        if (!is_array($card) || !isset($card['id'])) {
            return [];
        }

        [$wanted] = self::clean($rows, $card);

        return Transaction::run(static function () use ($owner, $model, $ownerId, $card, $wanted): array {
            self::writePoints($model, $ownerId, $wanted);

            if ($owner === self::COMPANY) {
                // Le aree dei contatti tolti se ne vanno con loro.
                self::writeDepartments($ownerId, [...self::primaryRows($card), ...$wanted]);
            }

            return $wanted;
        });
    }

    /**
     * Scrive tutto il riquadro «Contatti» di una scheda (`split()`): il
     * principale di ogni tipo nelle colonne della scheda, gli altri nella
     * tabella, le aree delle aziende. In una transazione. Le colonne si
     * scrivono solo se cambiano (di solito le ha già salvate la Resource con
     * la scheda); l'email bloccata (`lockedEmail()`) non si tocca mai. Le righe
     * che non passano i controlli si scartano: il form le ha già rifiutate.
     *
     * @param list<mixed> $rows
     * @return list<array<string, mixed>> le righe di `all()` dopo la scrittura
     */
    public static function syncAll(string $owner, int $ownerId, array $rows): array
    {
        $model = self::model($owner);
        $ownerModel = $model::ownerModel();
        $card = $ownerId > 0 ? $ownerModel::findById($ownerId) : null;

        if (!is_array($card) || !isset($card['id'])) {
            return [];
        }

        $split = self::split($owner, $rows, $card);

        Transaction::run(static function () use ($owner, $model, $ownerModel, $ownerId, $card, $split): void {
            $changes = array_filter(
                $split['columns'],
                static fn (string $value, string $column): bool => (string) ($card[$column] ?? '') !== $value,
                ARRAY_FILTER_USE_BOTH
            );

            if ($changes !== [] && !($ownerModel::query()->Update($ownerModel::$table, $changes, 'id', $ownerId)->success ?? false)) {
                throw new RuntimeException('Contatti principali non salvati.');
            }

            $others = array_values(array_filter($split['rows'], static fn (array $row): bool => !$row['is_primary']));
            self::writePoints($model, $ownerId, array_map([self::class, 'shape'], $others));

            if ($owner === self::COMPANY) {
                self::writeDepartments($ownerId, $split['rows']);
            }
        });

        return self::all($owner, $ownerId);
    }

    /**
     * Le email di un'azienda a cui scrivere per un'area: quelle che se ne
     * occupano o si occupano di tutto (nessuna area), la principale per
     * prima. Area vuota = tutte le email. Per i destinatari dei documenti,
     * accanto ai referenti di `ContactRelations::peopleFor()`.
     *
     * @return list<string>
     */
    public static function companyEmailsFor(int $companyId, string $department = ''): array
    {
        $department = trim($department);
        $out = [];

        foreach (self::all(self::COMPANY, $companyId) as $row) {
            if ($row['kind'] !== self::EMAIL || $row['value'] === '') {
                continue;
            }

            if ($department === '' || $row['departments'] === [] || in_array($department, $row['departments'], true)) {
                $out[] = $row['value'];
            }
        }

        return array_values(array_unique($out));
    }

    /** L'email in minuscolo, senza spazi intorno. */
    private static function email(mixed $value): string
    {
        return is_scalar($value) ? strtolower(trim((string) $value)) : '';
    }

    /**
     * Il formato di un'email, senza rete: `FILTER_VALIDATE_EMAIL`, al massimo
     * 190 caratteri e niente spazi, virgolette, apici, backtick né `< >` (il
     * filtro accetta `"<img src=x onerror=…>"@dominio.it`).
     */
    public static function emailFormat(string $value): bool
    {
        return $value !== '' && strlen($value) <= 190
            && preg_match('/[\s"\'`<>\\\\]/', $value) !== 1
            && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** Un'email valida per il validatore del framework: formato e dominio (interroga il DNS). */
    public static function validEmail(string $value): bool
    {
        if (!self::emailFormat($value)) {
            return false;
        }

        try {
            return (new EmailValidator())->validate($value)->isValid();
        } catch (Throwable) {
            return false;
        }
    }

    /** Un'email da salvare: in minuscolo se il formato va (`emailFormat()`), altrimenti vuota. */
    public static function cleanEmail(mixed $value): string
    {
        $value = self::email($value);

        return self::emailFormat($value) ? $value : '';
    }

    /** Il numero con le sole cifre (spazi, trattini, punti e parentesi tolti), vuoto se manca; null se non è un numero. */
    public static function phoneNumber(mixed $value): ?string
    {
        $value = is_scalar($value) ? (string) preg_replace('/[\s\-.\/()]+/', '', (string) $value) : '';

        if ($value === '') {
            return '';
        }

        return preg_match('/^\d{4,20}$/', $value) === 1 ? $value : null;
    }

    /** Il prefisso come `+39` (anche da `0039` o `39`); vuoto se non c'è, null se non è un prefisso. */
    public static function phonePrefix(mixed $value): ?string
    {
        $value = is_scalar($value) ? (string) preg_replace('/\s+/', '', (string) $value) : '';

        if ($value === '') {
            return '';
        }

        return preg_match('/^(?:\+|00)?(\d{1,4})$/', $value, $match) === 1 ? '+'.$match[1] : null;
    }

    /**
     * Una riga ripulita, o il problema da mostrare; `[null, null]` per una
     * riga vuota.
     *
     * @return array{0: array{kind: string, value: string, phone_prefix: string, label: string}|null, 1: string|null}
     */
    private static function cleanRow(mixed $row, bool $checkDomain): array
    {
        if (!is_array($row)) {
            return [null, null];
        }

        $raw = is_scalar($row['value'] ?? null) ? trim((string) $row['value']) : '';

        if ($raw === '') {
            return [null, null];
        }

        $kind = in_array($row['kind'] ?? null, self::KINDS, true) ? $row['kind'] : (str_contains($raw, '@') ? self::EMAIL : self::PHONE);
        $shown = mb_substr($raw, 0, 80);

        if ($kind === self::EMAIL) {
            $value = self::email($raw);

            if (!($checkDomain ? self::validEmail($value) : self::emailFormat($value))) {
                return [null, (string) __t('contacts.point_email_invalid', ['value' => $shown])];
            }

            return [['kind' => $kind, 'value' => $value, 'phone_prefix' => '', 'label' => self::label($row['label'] ?? '')], null];
        }

        $value = self::phoneNumber($raw);
        $prefix = self::phonePrefix($row['phone_prefix'] ?? '');

        if ($value === null || $prefix === null) {
            return [null, (string) __t('contacts.point_phone_invalid', ['value' => $shown])];
        }

        return [['kind' => $kind, 'value' => $value, 'phone_prefix' => $prefix, 'label' => self::label($row['label'] ?? '')], null];
    }

    /**
     * Una riga del riquadro nella forma di `cleanRow()`: il valore è `email`
     * o `phone` secondo il tipo (la colonna dell'altro tipo, nascosta a
     * schermo, non conta), oppure `value` come le righe di `sync()`.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function boxRow(array $row): array
    {
        $kind = in_array($row['kind'] ?? null, self::KINDS, true) ? $row['kind'] : null;
        $email = is_scalar($row['email'] ?? null) ? trim((string) $row['email']) : '';
        $phone = is_scalar($row['phone'] ?? null) ? trim((string) $row['phone']) : '';
        $value = is_scalar($row['value'] ?? null) ? trim((string) $row['value']) : '';

        if ($kind === null && $value === '') {
            $kind = $email !== '' ? self::EMAIL : ($phone !== '' ? self::PHONE : null);
        }

        if ($value === '') {
            $value = $kind === self::EMAIL ? $email : ($kind === self::PHONE ? $phone : '');
        }

        return ['kind' => $kind, 'value' => $value, 'phone_prefix' => $row['phone_prefix'] ?? '', 'label' => $row['label'] ?? ''];
    }

    /**
     * L'email e il telefono principali di una scheda come righe.
     *
     * @return list<array{kind: string, value: string, phone_prefix: string}>
     */
    private static function primaryRows(array $card): array
    {
        $out = [];
        $email = self::email($card['email'] ?? '');
        $phone = self::phoneNumber($card['phone'] ?? '');

        if ($email !== '') {
            $out[] = ['kind' => self::EMAIL, 'value' => $email, 'phone_prefix' => ''];
        }

        if ($phone !== null && $phone !== '') {
            $out[] = ['kind' => self::PHONE, 'value' => $phone, 'phone_prefix' => (string) (self::phonePrefix($card['phone_prefix'] ?? '') ?? '')];
        }

        return $out;
    }

    /**
     * Le righe di `company_points` / `contact_points` di una scheda, dentro la
     * transazione di chi chiama.
     *
     * @param class-string<ContactPoint|CompanyPoint> $model
     * @param list<array{kind: string, value: string, phone_prefix: string, label: string}> $wanted
     */
    private static function writePoints(string $model, int $ownerId, array $wanted): void
    {
        $key = $model::ownerKey();
        $current = [];

        foreach (self::rows($model::find([$key => $ownerId])) as $row) {
            $current[$row['kind'].'|'.$row['value']] = (int) $row['id'];
        }

        foreach ($wanted as $position => $row) {
            $values = $row + ['position' => $position];
            $id = $current[$row['kind'].'|'.$row['value']] ?? 0;
            unset($current[$row['kind'].'|'.$row['value']]);

            $result = $id > 0
                ? $model::query()->Update($model::$table, $values, 'id', $id)
                : $model::create([$key => $ownerId] + $values);

            if (!($result->success ?? false)) {
                throw new RuntimeException('Recapito non salvato.');
            }
        }

        foreach ($current as $id) {
            $model::delete($id);
        }
    }

    /**
     * Le aree dei contatti di un'azienda: per le righe con `departments`
     * (lista) le scrive, quelle con `null` o senza la chiave restano come
     * sono; le aree di un valore che non c'è più fra `$rows` si tolgono.
     *
     * @param list<array<string, mixed>> $rows tutti i contatti dell'azienda, principali compresi
     */
    private static function writeDepartments(int $companyId, array $rows): void
    {
        $current = [];

        foreach (self::rows(CompanyPointDepartment::find(['company_id' => $companyId])) as $row) {
            $current[$row['kind'].'|'.$row['value']][(string) $row['department']] = (int) $row['id'];
        }

        foreach ($rows as $row) {
            $key = $row['kind'].'|'.$row['value'];
            $stored = $current[$key] ?? [];
            unset($current[$key]);

            if (!is_array($row['departments'] ?? null)) {
                continue;
            }

            foreach ($row['departments'] as $department) {
                if (!isset($stored[$department])) {
                    $result = CompanyPointDepartment::create(['company_id' => $companyId, 'kind' => $row['kind'], 'value' => $row['value'], 'department' => $department]);

                    if (!($result->success ?? false)) {
                        throw new RuntimeException('Area del contatto non salvata.');
                    }
                }
            }

            foreach ($stored as $department => $id) {
                if (!in_array($department, $row['departments'], true)) {
                    CompanyPointDepartment::delete($id);
                }
            }
        }

        foreach ($current as $stored) {
            foreach ($stored as $id) {
                CompanyPointDepartment::delete($id);
            }
        }
    }

    /**
     * Le aree dei contatti di un'azienda, per `kind|value`, in una query.
     * Solo le aree note: una tolta dalla lista non conta più.
     *
     * @return array<string, list<string>>
     */
    private static function departmentsByValue(int $companyId): array
    {
        return RequestMemo::remember(CompanyPointDepartment::$table, 'company:'.$companyId, static function () use ($companyId): array {
            $out = [];

            try {
                $rows = self::rows(CompanyPointDepartment::find(['company_id' => $companyId], null, 'id', 'ASC'));
            } catch (Throwable) {
                return $out;
            }

            $known = ContactRelations::departments();

            foreach ($rows as $row) {
                if (isset($known[(string) $row['department']])) {
                    $out[$row['kind'].'|'.$row['value']][] = (string) $row['department'];
                }
            }

            return $out;
        });
    }

    /** @return array{kind: string, value: string, phone_prefix: string, label: string} */
    private static function shape(array $row): array
    {
        return [
            'kind' => (string) ($row['kind'] ?? ''),
            'value' => (string) ($row['value'] ?? ''),
            'phone_prefix' => (string) ($row['phone_prefix'] ?? ''),
            'label' => (string) ($row['label'] ?? ''),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
