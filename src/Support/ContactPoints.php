<?php

namespace Wonder\Plugin\CustomerAccount\Support;

use RuntimeException;
use Throwable;
use Wonder\App\Support\RequestMemo;
use Wonder\Data\Validators\EmailValidator;
use Wonder\Plugin\CustomerAccount\Models\CompanyPoint;
use Wonder\Plugin\CustomerAccount\Models\CompanyPointDepartment;
use Wonder\Plugin\CustomerAccount\Models\ContactPoint;
use Wonder\Plugin\CustomerAccount\Models\ContactPointDepartment;
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
 * I tipi sono quattro (`kinds()`): email, telefono fisso, cellulare e
 * WhatsApp; i tre telefoni sono una famiglia sola (`isPhone()`, `family()`),
 * con un solo principale il cui tipo sta in `phone_kind`.
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
    public const MOBILE = 'mobile';
    public const WHATSAPP = 'whatsapp';

    /** Le due famiglie (email, telefono): gli elenchi per tipo dei form. Tutti i tipi: `kinds()`. */
    public const KINDS = [self::EMAIL, self::PHONE];

    /** I tipi che sono un numero di telefono: stesso prefisso, stessa pulizia. */
    public const PHONE_KINDS = [self::PHONE, self::MOBILE, self::WHATSAPP];

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
     * Tutti i tipi di recapito, nell'ordine dei select.
     *
     * @return list<string>
     */
    public static function kinds(): array
    {
        return [self::EMAIL, ...self::PHONE_KINDS];
    }

    /** Il tipo è un numero di telefono (fisso, cellulare, WhatsApp)? */
    public static function isPhone(mixed $kind): bool
    {
        return in_array($kind, self::PHONE_KINDS, true);
    }

    /** La famiglia di un tipo: `email` o `phone` (i tre tipi di telefono). */
    public static function family(mixed $kind): string
    {
        return $kind === self::EMAIL ? self::EMAIL : self::PHONE;
    }

    /**
     * I tipi per un select: chiave => etichetta.
     *
     * @return array<string, string>
     */
    public static function kindOptions(): array
    {
        $out = [];

        foreach (self::kinds() as $kind) {
            $out[$kind] = self::kindLabel($kind);
        }

        return $out;
    }

    /** L'etichetta di un tipo («Telefono fisso», «Cellulare», ...); vuota se il tipo non esiste. */
    public static function kindLabel(mixed $kind): string
    {
        if (!in_array($kind, self::kinds(), true)) {
            return '';
        }

        // `point_kind_phone` è l'etichetta della colonna del numero: il tipo ha la sua.
        return (string) __t('contacts.point_kind_'.($kind === self::PHONE ? 'landline' : $kind));
    }

    /**
     * Dove stanno i tipi ufficio dei recapiti di una scheda: modello e colonna
     * del proprietario.
     *
     * @return array{0: class-string<ContactPointDepartment|CompanyPointDepartment>, 1: string}
     */
    private static function departmentStore(string $owner): array
    {
        return $owner === self::COMPANY
            ? [CompanyPointDepartment::class, 'company_id']
            : [ContactPointDepartment::class, 'contact_id'];
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
     * principali (dalle colonne della scheda; un valore storico che le
     * regole di oggi non riconoscono si mostra grezzo, vedi `split()`), poi
     * gli altri nell'ordine scelto. Ogni riga ha `is_primary`, `locked` (l'email di accesso di una
     * persona con account, etichetta `access`) e `departments` (i tipi ufficio noti,
     * di aziende e persone; vuota = tutto).
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

        $areas = self::departmentsByValue($owner, $ownerId);
        $locked = self::lockedEmail($owner, $card);
        $out = [];

        foreach (self::primaryRows($card) as $row) {
            $isLocked = $row['kind'] === self::EMAIL && $locked !== null;
            $departments = self::knownDepartments($areas[$row['kind'].'|'.$row['value']] ?? []);
            $out[] = array_diff_key($row, ['valid' => true]) + [
                'label' => $isLocked ? self::ACCESS_LABEL : '',
                'is_primary' => true,
                'locked' => $isLocked,
                // L'email della scheda di una persona: accesso e predefinita, con o senza account.
                'access' => $owner === self::CONTACT && $row['kind'] === self::EMAIL,
                'departments' => $departments,
                'department' => (string) ($departments[0] ?? ''),
            ];
        }

        $points = RequestMemo::remember($model::$table, 'owner:'.$ownerId, static fn (): array => self::of($owner, $ownerId));

        foreach ($points as $row) {
            $departments = self::knownDepartments($areas[$row['kind'].'|'.$row['value']] ?? []);
            $out[] = $row + ['is_primary' => false, 'locked' => false, 'access' => false, 'departments' => $departments, 'department' => (string) ($departments[0] ?? '')];
        }

        return $out;
    }

    /**
     * Le righe di `all()` senza l'email di accesso della persona (`access`):
     * per i form che la mostrano in un campo a parte.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function withoutAccess(array $rows): array
    {
        return array_values(array_filter($rows, static fn (array $row): bool => empty($row['access'])));
    }

    /**
     * Le righe del riquadro per il form (`formRows()`) senza l'email di
     * accesso: per ogni persona, con o senza account. Per un'azienda sono
     * tutte le righe.
     *
     * @param array<string, mixed>|null $card la scheda, se chi chiama l'ha già letta
     * @return list<array<string, mixed>>
     */
    public static function formRowsWithoutAccess(string $owner, int $ownerId, ?array $card = null, bool $withDepartments = true): array
    {
        return self::formRows(self::withoutAccess(self::all($owner, $ownerId, $card)), $withDepartments);
    }

    /**
     * Le righe di `all()` come le vuole il repeater del riquadro: email e
     * numero in colonne separate, `is_primary` come `'true'` / `'false'`.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function formRows(array $rows, bool $withDepartments = true): array
    {
        $out = [];

        foreach ($rows as $row) {
            $email = ($row['kind'] ?? '') === self::EMAIL;
            $form = [
                'kind' => $email ? self::EMAIL : (self::isPhone($row['kind'] ?? null) ? $row['kind'] : self::PHONE),
                'email' => $email ? (string) $row['value'] : '',
                'phone_prefix' => $email ? '' : (string) ($row['phone_prefix'] ?? ''),
                'phone' => $email ? '' : (string) $row['value'],
                'label' => (string) ($row['label'] ?? ''),
                'is_primary' => !empty($row['is_primary']) ? 'true' : 'false',
                'locked' => !empty($row['locked']) ? '1' : '',
                'access' => !empty($row['access']) ? '1' : '',
            ];

            if ($withDepartments) {
                $form['departments'] = $row['departments'] ?? [];
                // L'ufficio singolo: la prima voce.
                $form['department'] = (string) ($row['department'] ?? ($form['departments'][0] ?? ''));
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
     * Come `clean()`, per righe nella forma del riquadro (`kind`, `email`
     * oppure `phone` secondo il tipo, o `value`; `phone_prefix`, `label`):
     * i recapiti di un referente per un'azienda (`ContactRelations::sync()`).
     * I doppioni si scartano senza problema.
     *
     * @param list<mixed> $rows
     * @return array{0: list<array{kind: string, value: string, phone_prefix: string, label: string}>, 1: list<string>}
     */
    public static function cleanBox(array $rows, bool $checkDomain = false): array
    {
        $out = [];
        $problems = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            [$clean, $problem] = self::cleanRow(self::boxRow($row), $checkDomain);

            if ($problem !== null) {
                $problems[] = $problem;
            } elseif ($clean !== null && !isset($out[$clean['kind'].'|'.$clean['value']])) {
                $out[$clean['kind'].'|'.$clean['value']] = $clean;
            }
        }

        return [array_values($out), $problems];
    }

    /**
     * Le righe del riquadro «Contatti» (principale compreso) divise fra le
     * colonne della scheda e le righe in più. Una riga ha `kind`, il valore
     * (`email` o `phone`, oppure `value` come le righe di `sync()`),
     * `phone_prefix`, `label`, `is_primary` e, per le aziende, `departments`.
     *
     * La regola sta qui e non nel form: al massimo un principale per tipo (il
     * primo segnato; gli altri tornano righe normali). Se nessuna è segnata
     * resta il principale di prima, poi la prima riga nuova; un contatto in
     * più già salvato (`$saved`, le righe di `of()`) sale a principale solo se
     * un principale c'era ed è stato tolto: una scheda senza principale non
     * ne guadagna uno a ogni salvataggio. Nessuna riga del tipo = colonne
     * vuote; senza telefono né prima né dopo `phone_prefix` non compare. Con
     * `lockedEmail()` l'email della scheda non cambia: la sua riga si salta,
     * le altre email restano righe normali e `columns` non porta `email`.
     * Le colonne `locked` e `label = access` postate non contano.
     *
     * Un valore salvato non si perde senza un gesto dell'utente: se il
     * principale di un tipo è un valore storico che le regole di oggi non
     * riconoscono (`all()` lo mostra grezzo) e il form lo riposta com'era, o
     * non posta nessuna riga di quel tipo, le colonne di quel tipo restano come
     * sono (`kept`) e le altre righe del tipo sono righe normali; nessun
     * problema per quella riga. Toglierla o cambiarla è il gesto.
     *
     * @param list<mixed> $rows
     * @param array<string, mixed> $card la scheda salvata (`user_id`, `email`); vuota per una scheda nuova
     * I tre tipi di telefono (`PHONE_KINDS`) sono una famiglia sola: un solo
     * telefono principale, di un tipo qualsiasi; il suo tipo va in
     * `phone_kind` (in `columns` solo se cambia). `kept` elenca le famiglie
     * (`email`, `phone`). Una riga può portare `department` (l'ufficio
     * singolo, vedi `ContactRelations::singleDepartment()`) al posto di
     * `departments`.
     *
     * `$options`:
     * - `first_primary`: il principale di ogni famiglia è la prima riga per
     *   posizione (`is_primary` e il principale di prima non contano);
     * - `separate_email`: l'email della scheda arriva da un campo a parte
     *   (true = quella salvata, una stringa = quella postata): come
     *   l'email bloccata, non entra in `columns`, la sua riga si salta e le
     *   altre email sono righe normali;
     * - `primary_only`: contano solo la prima email e il primo telefono
     *   postati (il form con un'email e un telefono soli); i recapiti in
     *   più salvati non si toccano (`syncAll()`).
     *
     * @param list<array<string, mixed>> $saved i contatti in più già salvati della scheda
     * @param array{first_primary?: bool, separate_email?: bool|string, primary_only?: bool} $options
     * @return array{columns: array<string, string>, rows: list<array<string, mixed>>, problems: list<string>, kept: list<string>}
     */
    public static function split(string $owner, array $rows, array $card = [], bool $checkDomain = false, array $saved = [], array $options = []): array
    {
        self::model($owner);
        $primaryOnly = !empty($options['primary_only']);
        $firstPrimary = $primaryOnly || !empty($options['first_primary']);
        $locked = self::lockedEmail($owner, $card);
        $separate = $options['separate_email'] ?? false;

        // L'email a parte vale come quella bloccata: il riquadro non la scrive.
        if ($locked === null && $separate !== false && $separate !== null && $separate !== '') {
            $locked = self::email(is_string($separate) ? $separate : ($card['email'] ?? ''));
        } elseif ($locked === null && $separate === '') {
            $locked = '';
        }

        $stored = [];
        $extra = [];

        $labels = [];

        foreach ($saved as $row) {
            $extra[($row['kind'] ?? '').'|'.($row['value'] ?? '')] = true;
            $labels[($row['kind'] ?? '').'|'.($row['value'] ?? '')] = self::label($row['label'] ?? '');
        }

        foreach (self::primaryRows($card) as $row) {
            $stored[self::family($row['kind'])] = $row;
        }

        $kept = [];
        $keep = [];
        $posted = [];
        $seen = [];
        $problems = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $box = self::boxRow($row);
            $raw = is_scalar($box['value']) ? trim((string) $box['value']) : '';

            if ($raw === '') {
                continue;
            }

            $slot = self::family($box['kind'] ?? (str_contains($raw, '@') ? self::EMAIL : self::PHONE));
            $posted[$slot] = true;

            // Il valore storico riportato com'era: si tiene, valido o no.
            if (isset($stored[$slot]) && !$stored[$slot]['valid'] && self::sameValue($slot, $raw, (string) $stored[$slot]['value'])) {
                $keep[$slot] = true;
                continue;
            }

            [$clean, $problem] = self::cleanRow($box, $checkDomain);

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
            // Il riquadro non posta più l'etichetta: resta quella salvata su quel valore.
            if (!array_key_exists('label', $row)) {
                $clean['label'] = $labels[$key] ?? '';
            }

            $clean['departments'] = array_key_exists('departments', $row)
                ? ContactRelations::cleanDepartments($row['departments'])
                : null;

            // L'ufficio singolo: lo risolve la scrittura, che conosce le voci salvate.
            if ($clean['departments'] === null && array_key_exists('department', $row)) {
                $clean['department'] = (string) (ContactRelations::cleanDepartments($row['department'])[0] ?? '');
            }

            $kept[] = $clean;
        }

        foreach ($stored as $slot => $row) {
            if (!$row['valid'] && !isset($posted[$slot])) {
                $keep[$slot] = true;
            }
        }

        if ($primaryOnly) {
            // Un'email e un telefono soli: le altre righe postate non contano.
            $firsts = [];

            foreach ($kept as $row) {
                $firsts[self::family($row['kind'])] ??= $row;
            }

            $kept = array_values($firsts);
        }

        $columns = [];

        foreach (self::KINDS as $kind) {
            $primary = null;
            $untouched = isset($keep[$kind]) || ($kind === self::EMAIL && $locked !== null);

            foreach ($kept as $i => $row) {
                if (self::family($row['kind']) !== $kind) {
                    continue;
                }

                if ($untouched) {
                    $kept[$i]['is_primary'] = false;
                    continue;
                }

                if (($firstPrimary || $row['is_primary']) && $primary === null) {
                    $primary = $i;
                }

                $kept[$i]['is_primary'] = false;
            }

            if ($untouched) {
                continue;
            }

            // Nessuna segnata: resta il principale di prima, poi la prima riga nuova;
            // un contatto in più già salvato sale solo se un principale c'era.
            if ($primary === null) {
                $first = null;
                $fresh = null;
                $same = null;

                foreach ($kept as $i => $row) {
                    if (self::family($row['kind']) !== $kind) {
                        continue;
                    }

                    if (isset($stored[$kind]) && $row['value'] === $stored[$kind]['value']) {
                        // Lo stesso numero può stare su due tipi (cellulare e WhatsApp): prima quello del tipo salvato.
                        if ($row['kind'] === $stored[$kind]['kind']) {
                            $primary = $i;
                            break;
                        }

                        $same ??= $i;
                        continue;
                    }

                    if ($same !== null) {
                        continue;
                    }

                    $first ??= $i;

                    if (!isset($extra[$row['kind'].'|'.$row['value']])) {
                        $fresh ??= $i;
                    }
                }

                $primary ??= $same ?? $fresh ?? (isset($stored[$kind]) ? $first : null);
            }

            if ($primary !== null) {
                $kept[$primary]['is_primary'] = true;
            }

            $row = $primary !== null ? $kept[$primary] : null;

            if ($kind === self::EMAIL) {
                $columns['email'] = (string) ($row['value'] ?? '');
            } else {
                // Senza telefono né prima né dopo il prefisso salvato resta com'è.
                if ($row !== null || isset($stored[$kind])) {
                    $columns['phone_prefix'] = (string) ($row['phone_prefix'] ?? '');
                }

                $columns['phone'] = (string) ($row['value'] ?? '');
                // Il tipo del telefono principale: si scrive solo se cambia.
                $phoneKind = (string) ($row['kind'] ?? self::PHONE);

                if ($phoneKind !== self::primaryPhoneKind($card)) {
                    $columns['phone_kind'] = $phoneKind;
                }
            }
        }

        return ['columns' => $columns, 'rows' => $kept, 'problems' => $problems, 'kept' => array_keys($keep)];
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
     * Con `$primaryOnly` (il form mostra solo un'email e un telefono) non
     * scrive niente: i contatti in più salvati restano come sono.
     *
     * @param list<mixed> $rows
     * @return list<array{kind: string, value: string, phone_prefix: string, label: string}> i contatti rimasti
     */
    public static function sync(string $owner, int $ownerId, array $rows, bool $primaryOnly = false): array
    {
        if ($primaryOnly) {
            return self::of($owner, $ownerId);
        }

        $model = self::model($owner);
        $card = $ownerId > 0 ? $model::ownerModel()::findById($ownerId) : null;

        if (!is_array($card) || !isset($card['id'])) {
            return [];
        }

        [$wanted] = self::clean($rows, $card);

        return Transaction::run(static function () use ($owner, $model, $ownerId, $card, $wanted): array {
            $before = self::currentKeys($owner, $ownerId, $card);
            self::writePoints($model, $ownerId, $wanted);
            // I tipi dei contatti tolti se ne vanno con loro; un contatto nuovo non eredita quelli rimasti.
            self::writeDepartments($owner, $ownerId, [...self::primaryRows($card), ...$wanted], $before);

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
     * `$options` sono quelle di `split()`. Con `primary_only` scrive solo la
     * prima email e il primo telefono nelle colonne della scheda: i recapiti
     * in più salvati restano (quello diventato principale non resta due
     * volte) e un principale tolto non ne promuove uno.
     *
     * @param list<mixed> $rows
     * @param array{first_primary?: bool, separate_email?: bool|string, primary_only?: bool} $options
     * @return list<array<string, mixed>> le righe di `all()` dopo la scrittura
     */
    public static function syncAll(string $owner, int $ownerId, array $rows, array $options = []): array
    {
        $model = self::model($owner);
        $ownerModel = $model::ownerModel();
        $card = $ownerId > 0 ? $ownerModel::findById($ownerId) : null;

        if (!is_array($card) || !isset($card['id'])) {
            return [];
        }

        $saved = self::of($owner, $ownerId);
        $split = self::split($owner, $rows, $card, false, $saved, $options);
        $primaryOnly = !empty($options['primary_only']);
        $emailFixed = self::lockedEmail($owner, $card) !== null || !empty($options['separate_email']);

        Transaction::run(static function () use ($owner, $model, $ownerModel, $ownerId, $card, $split, $saved, $primaryOnly, $emailFixed): void {
            $before = self::currentKeys($owner, $ownerId, $card);
            $changes = array_filter(
                $split['columns'],
                static fn (string $value, string $column): bool => (string) ($card[$column] ?? '') !== $value,
                ARRAY_FILTER_USE_BOTH
            );

            if ($changes !== [] && !($ownerModel::query()->Update($ownerModel::$table, $changes, 'id', $ownerId)->success ?? false)) {
                throw new RuntimeException('Contatti principali non salvati.');
            }

            $others = array_values(array_filter($split['rows'], static fn (array $row): bool => !$row['is_primary']));
            $stay = [];

            if ($primaryOnly) {
                // I recapiti in più salvati restano dove sono, tranne quello salito a principale.
                $taken = [];

                foreach ($split['rows'] as $row) {
                    $taken[$row['kind'].'|'.$row['value']] = true;
                }

                $stay = array_values(array_filter($saved, static fn (array $row): bool => !isset($taken[$row['kind'].'|'.$row['value']])));
            }

            self::writePoints($model, $ownerId, array_map([self::class, 'shape'], [...$stay, ...$others]));

            // I principali che il riquadro non scrive (valore storico, email a parte) tengono i loro uffici.
            $untouched = array_values(array_filter(
                self::primaryRows($card),
                static fn (array $row): bool => in_array(self::family($row['kind']), $split['kept'], true) || ($row['kind'] === self::EMAIL && $emailFixed)
            ));
            self::writeDepartments($owner, $ownerId, [...$split['rows'], ...$untouched, ...$stay], $before);
        });

        return self::all($owner, $ownerId);
    }

    /**
     * Le email di un'azienda a cui scrivere per un'area: quelle che se ne
     * occupano o si occupano di tutto (nessuna area o «Generale»), la principale per
     * prima. Area vuota = tutte le email. Una casella con sole aree non più
     * note (tolte dalla lista) non si occupa di nessuna area: esce solo con
     * l'area vuota. Per i destinatari dei documenti,
     * accanto ai referenti di `ContactRelations::peopleFor()`.
     *
     * @return list<string>
     */
    public static function companyEmailsFor(int $companyId, string $department = ''): array
    {
        $department = trim($department);
        $out = [];
        // Le aree salvate, anche quelle non più note: una casella che ne ha solo di sconosciute non si occupa «di tutto».
        $areas = self::departmentsByValue(self::COMPANY, $companyId);

        foreach (self::all(self::COMPANY, $companyId) as $row) {
            if ($row['kind'] !== self::EMAIL || $row['value'] === '') {
                continue;
            }

            $stored = $areas[$row['kind'].'|'.$row['value']] ?? [];

            if (ContactRelations::coversDepartment($stored, $department)) {
                $out[] = $row['value'];
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Toglie le aree rimaste su valori che non sono più contatti dell'azienda
     * (l'email principale cambiata dai campi singoli, da un import, da un altro
     * modulo): senza, una casella aggiunta poi con quel valore le erediterebbe.
     * Le scritture del modulo che cambiano i contatti di un'azienda lo fanno
     * da sé (`sync()`, `syncAll()`, il salvataggio delle Resource senza
     * riquadro, `AccountBilling::saveCompany()`); chi cambia `companies.email`
     * o `phone` da fuori lo chiama dopo.
     *
     * @return int le righe tolte
     */
    public static function dropOrphanDepartments(int $ownerId, string $owner = self::COMPANY): int
    {
        $card = $ownerId > 0 ? self::model($owner)::ownerModel()::findById($ownerId) : null;

        if (!is_array($card) || !isset($card['id'])) {
            return 0;
        }

        [$store, $fk] = self::departmentStore($owner);
        $current = self::currentKeys($owner, $ownerId, $card);
        $dropped = 0;

        foreach (self::rows($store::find([$fk => $ownerId])) as $row) {
            if (!isset($current[$row['kind'].'|'.$row['value']])) {
                $store::delete((int) $row['id']);
                $dropped++;
            }
        }

        return $dropped;
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

        $kind = in_array($row['kind'] ?? null, self::kinds(), true) ? $row['kind'] : (str_contains($raw, '@') ? self::EMAIL : self::PHONE);
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
        $kind = in_array($row['kind'] ?? null, self::kinds(), true) ? $row['kind'] : null;
        $email = is_scalar($row['email'] ?? null) ? trim((string) $row['email']) : '';
        $phone = is_scalar($row['phone'] ?? null) ? trim((string) $row['phone']) : '';
        $value = is_scalar($row['value'] ?? null) ? trim((string) $row['value']) : '';

        if ($kind === null && $value === '') {
            $kind = $email !== '' ? self::EMAIL : ($phone !== '' ? self::PHONE : null);
        }

        if ($value === '') {
            $value = $kind === self::EMAIL ? $email : (self::isPhone($kind) ? $phone : '');
        }

        return ['kind' => $kind, 'value' => $value, 'phone_prefix' => $row['phone_prefix'] ?? '', 'label' => $row['label'] ?? ''];
    }

    /**
     * L'email e il telefono principali di una scheda come righe. Un valore
     * salvato che le regole di oggi non riconoscono (dati storici: un telefono
     * con lettere, un'email con uno spazio) resta, grezzo, con `valid` falso:
     * non si perde senza un gesto dell'utente (`split()`). Il telefono prova
     * prima a separare un prefisso noto (`+39 333…`, `0039333…`).
     *
     * @return list<array{kind: string, value: string, phone_prefix: string, valid: bool}>
     */
    private static function primaryRows(array $card): array
    {
        $out = [];
        $raw = is_scalar($card['email'] ?? null) ? trim((string) $card['email']) : '';

        if ($raw !== '') {
            $valid = self::emailFormat(strtolower($raw));
            $out[] = ['kind' => self::EMAIL, 'value' => $valid ? strtolower($raw) : $raw, 'phone_prefix' => '', 'valid' => $valid];
        }

        $raw = is_scalar($card['phone'] ?? null) ? trim((string) $card['phone']) : '';

        if ($raw !== '') {
            [$prefix, $number] = self::storedPhone($card['phone_prefix'] ?? '', $raw);
            $valid = $prefix !== null && $number !== null && $number !== '';
            $out[] = [
                'kind' => self::primaryPhoneKind($card),
                'value' => $valid ? $number : $raw,
                'phone_prefix' => $valid ? $prefix : (is_scalar($card['phone_prefix'] ?? null) ? trim((string) $card['phone_prefix']) : ''),
                'valid' => $valid,
            ];
        }

        return $out;
    }

    /** Il tipo del telefono principale di una scheda (`phone_kind`); senza la colonna è il fisso. */
    private static function primaryPhoneKind(array $card): string
    {
        return self::isPhone($card['phone_kind'] ?? null) ? (string) $card['phone_kind'] : self::PHONE;
    }

    /**
     * Prefisso e numero di un telefono salvato: senza prefisso nella sua
     * colonna, un numero che inizia con `+` o `00` e un prefisso noto del
     * framework (`phonePrefix()`) si divide. Null dove il valore non va.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function storedPhone(mixed $prefix, string $raw): array
    {
        $prefix = self::phonePrefix($prefix);
        $compact = (string) preg_replace('/[\s\-.\/()]+/', '', $raw);

        if (($prefix === null || $prefix === '') && preg_match('/^(?:\+|00)(\d+)$/', $compact, $match) === 1) {
            foreach (self::knownPrefixes() as $digits) {
                $rest = substr($match[1], strlen($digits));

                if (str_starts_with($match[1], $digits) && preg_match('/^\d{4,20}$/', $rest) === 1) {
                    return ['+'.$digits, $rest];
                }
            }
        }

        return [$prefix, self::phoneNumber($raw)];
    }

    /**
     * I prefissi noti del framework (`phonePrefix()` del core), solo cifre, dal
     * più lungo. Vuoto se il core non li dà (comandi, test).
     *
     * @return list<string>
     */
    private static function knownPrefixes(): array
    {
        if (!function_exists('phonePrefix')) {
            return [];
        }

        $out = [];

        foreach (array_keys((array) \phonePrefix()) as $key) {
            if (preg_match('/^\+(\d{1,4})$/', (string) $key, $match) === 1) {
                $out[] = $match[1];
            }
        }

        $out = array_values(array_unique($out));
        usort($out, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $out;
    }

    /** Lo stesso valore a meno di maiuscole (email) o di spazi e trattini (telefono). */
    private static function sameValue(string $kind, string $a, string $b): bool
    {
        if (self::family($kind) === self::EMAIL) {
            return strtolower(trim($a)) === strtolower(trim($b));
        }

        $compact = static fn (string $value): string => (string) preg_replace('/[\s\-.\/()]+/', '', $value);

        return $compact($a) === $compact($b);
    }

    /**
     * I contatti di oggi di una scheda (`kind|value`): principali e in più.
     *
     * @return array<string, true>
     */
    private static function currentKeys(string $owner, int $ownerId, array $card): array
    {
        $out = [];

        foreach ([...self::primaryRows($card), ...self::of($owner, $ownerId)] as $row) {
            $out[$row['kind'].'|'.$row['value']] = true;
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
     * (lista) le scrive; quelle senza la chiave tengono le aree di prima, ma
     * solo se il valore era già un contatto (`$before`): uno nuovo non eredita
     * le aree rimaste su quel valore. Le aree non più note non si vedono nel
     * form e non si possono togliere da lì: restano finché il contatto resta.
     * Le aree di un valore che non c'è più fra `$rows` si tolgono.
     *
     * @param list<array<string, mixed>> $rows tutti i contatti dell'azienda, principali compresi
     * @param array<string, true>|null $before i contatti prima della scrittura (`kind|value`); null = tutti
     */
    private static function writeDepartments(string $owner, int $ownerId, array $rows, ?array $before = null): void
    {
        [$store, $fk] = self::departmentStore($owner);
        $current = [];
        $known = ContactRelations::departments();

        foreach (self::rows($store::find([$fk => $ownerId], null, 'id', 'ASC')) as $row) {
            $current[$row['kind'].'|'.$row['value']][(string) $row['department']] = (int) $row['id'];
        }

        foreach ($rows as $row) {
            $key = $row['kind'].'|'.$row['value'];
            $stored = $current[$key] ?? [];
            unset($current[$key]);
            $existed = $before === null || isset($before[$key]);
            $wanted = $row['departments'] ?? null;

            // Un ufficio solo (`department`): lo stesso di prima non tocca gli altri salvati, uno diverso li sostituisce.
            if (!is_array($wanted) && array_key_exists('department', $row)) {
                $wanted = ContactRelations::singleDepartment(
                    $row['department'],
                    $existed ? self::knownDepartments(array_map('strval', array_keys($stored))) : []
                );
            }

            if (!is_array($wanted)) {
                if ($existed) {
                    continue;
                }

                $wanted = [];
            }

            // Un tipo creato a mano ha per chiave il suo id: negli array torna intero, qui sempre testo.
            $wanted = array_map('strval', $wanted);

            foreach (array_keys($stored) as $department) {
                if ($existed && !isset($known[$department])) {
                    $wanted[] = (string) $department;
                }
            }

            foreach ($wanted as $department) {
                if (!isset($stored[$department])) {
                    $result = $store::create([$fk => $ownerId, 'kind' => $row['kind'], 'value' => $row['value'], 'department' => $department]);

                    if (!($result->success ?? false)) {
                        throw new RuntimeException('Area del contatto non salvata.');
                    }
                }
            }

            foreach ($stored as $department => $id) {
                if (!in_array((string) $department, $wanted, true)) {
                    $store::delete($id);
                }
            }
        }

        foreach ($current as $stored) {
            foreach ($stored as $id) {
                $store::delete($id);
            }
        }
    }

    /**
     * Le aree salvate dei contatti di un'azienda, per `kind|value`, in una
     * query, comprese quelle non più note (le filtra `knownDepartments()`).
     *
     * @return array<string, list<string>>
     */
    private static function departmentsByValue(string $owner, int $ownerId): array
    {
        [$store, $fk] = self::departmentStore($owner);

        return RequestMemo::remember($store::$table, 'owner:'.$ownerId, static function () use ($store, $fk, $ownerId): array {
            $out = [];

            try {
                $rows = self::rows($store::find([$fk => $ownerId], null, 'id', 'ASC'));
            } catch (Throwable) {
                return $out;
            }

            foreach ($rows as $row) {
                $out[$row['kind'].'|'.$row['value']][] = (string) $row['department'];
            }

            return $out;
        });
    }

    /**
     * @param list<string> $departments
     * @return list<string> solo le aree note
     */
    private static function knownDepartments(array $departments): array
    {
        $known = ContactRelations::departments();

        return array_values(array_filter($departments, static fn (string $department): bool => isset($known[$department])));
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
