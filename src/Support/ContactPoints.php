<?php

namespace Wonder\Plugin\CustomerAccount\Support;

use RuntimeException;
use Throwable;
use Wonder\Data\Validators\EmailValidator;
use Wonder\Plugin\CustomerAccount\Models\CompanyPoint;
use Wonder\Plugin\CustomerAccount\Models\ContactPoint;
use Wonder\Sql\Transaction;

/**
 * Le email e i telefoni in più di una persona (`contact_points`) o di
 * un'azienda (`company_points`): l'unico punto che li legge e li scrive.
 *
 * Quelli principali restano le colonne della scheda (`email`, `phone_prefix`,
 * `phone`): chi ha un solo recapito non vede cambiare nulla. Un recapito in
 * più è un dato di contatto e basta: non serve mai per l'accesso né per
 * collegare un account a una scheda (`ContactAccount` guarda solo `email`).
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

        if (preg_match('/^[a-z0-9_]{1,40}$/', $key) === 1) {
            self::$extraLabels[$key] = $label;
        }
    }

    /** Un'etichetta valida, altrimenti vuoto. */
    public static function label(mixed $value): string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return array_key_exists($value, self::labels()) ? $value : '';
    }

    /**
     * I recapiti in più di una scheda, nell'ordine scelto.
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
     * I recapiti in più di più schede, in una query.
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
     * Le righe postate ripulite, con i problemi da mostrare. Una riga senza
     * valore si salta (la riga vuota del repeater). L'email passa dal
     * validatore del framework; il telefono tiene solo le cifre e il prefisso
     * (`+` e fino a quattro cifre). Niente doppioni sulla scheda, contando
     * anche l'email e il telefono principali (`$primary`: `email`,
     * `phone_prefix`, `phone`).
     *
     * @param list<mixed> $rows
     * @param array<string, mixed> $primary
     * @return array{0: list<array{kind: string, value: string, phone_prefix: string, label: string}>, 1: list<string>}
     */
    public static function clean(array $rows, array $primary = []): array
    {
        $seen = [];
        $mainEmail = self::email($primary['email'] ?? '');
        $mainPhone = self::phoneNumber($primary['phone'] ?? '');

        if ($mainEmail !== '') {
            $seen[self::EMAIL.'|'.$mainEmail] = true;
        }

        if ($mainPhone !== null && $mainPhone !== '') {
            $seen[self::PHONE.'|'.$mainPhone] = true;
        }

        $out = [];
        $problems = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $raw = is_scalar($row['value'] ?? null) ? trim((string) $row['value']) : '';

            if ($raw === '') {
                continue;
            }

            $kind = in_array($row['kind'] ?? null, self::KINDS, true) ? $row['kind'] : (str_contains($raw, '@') ? self::EMAIL : self::PHONE);
            $shown = mb_substr($raw, 0, 80);

            if ($kind === self::EMAIL) {
                $value = self::email($raw);
                $prefix = '';

                if (!self::validEmail($value)) {
                    $problems[] = (string) __t('contacts.point_email_invalid', ['value' => $shown]);
                    continue;
                }
            } else {
                $value = self::phoneNumber($raw);
                $prefix = self::phonePrefix($row['phone_prefix'] ?? '');

                if ($value === null || $prefix === null) {
                    $problems[] = (string) __t('contacts.point_phone_invalid', ['value' => $shown]);
                    continue;
                }
            }

            // Il prefisso non conta: lo stesso numero con o senza prefisso è un doppione.
            $key = $kind.'|'.$value;

            if (isset($seen[$key])) {
                $problems[] = (string) __t('contacts.point_duplicate', ['value' => $shown]);
                continue;
            }

            $seen[$key] = true;
            $out[] = ['kind' => $kind, 'value' => $value, 'phone_prefix' => $prefix, 'label' => self::label($row['label'] ?? '')];
        }

        return [$out, $problems];
    }

    /**
     * Scrive i recapiti in più di una scheda: quelli non elencati si tolgono.
     * Le righe che non passano `clean()` si scartano (il form le ha già
     * rifiutate con un messaggio); l'email e il telefono principali si leggono
     * dalla scheda. Gli id postati non contano: un recapito si riconosce dal
     * valore, così una riga non può puntare a quella di un'altra scheda.
     *
     * @param list<mixed> $rows
     * @return list<array{kind: string, value: string, phone_prefix: string, label: string}> i recapiti rimasti
     */
    public static function sync(string $owner, int $ownerId, array $rows): array
    {
        $model = self::model($owner);
        $ownerModel = $model::ownerModel();
        $card = $ownerId > 0 ? $ownerModel::findById($ownerId) : null;

        if (!is_array($card) || !isset($card['id'])) {
            return [];
        }

        [$wanted] = self::clean($rows, $card);

        return Transaction::run(static function () use ($model, $ownerId, $wanted): array {
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

            return $wanted;
        });
    }

    /** L'email in minuscolo, senza spazi intorno. */
    private static function email(mixed $value): string
    {
        return is_scalar($value) ? strtolower(trim((string) $value)) : '';
    }

    /** Un'email valida per il validatore del framework (formato e dominio). */
    public static function validEmail(string $value): bool
    {
        if (strlen($value) > 190) {
            return false;
        }

        try {
            return (new EmailValidator())->validate($value)->isValid();
        } catch (Throwable) {
            return false;
        }
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
