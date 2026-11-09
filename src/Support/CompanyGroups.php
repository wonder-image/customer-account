<?php

namespace Wonder\Plugin\CustomerAccount\Support;

use Throwable;
use Wonder\App\Support\RequestMemo;
use Wonder\Plugin\CustomerAccount\Models\Company;

/**
 * I gruppi di aziende: una capogruppo e le sue controllate
 * (`companies.parent_id`, 0 = nessuna capogruppo). Un livello solo.
 *
 * L'unico punto che valida la capogruppo: il form la controlla con
 * `problem()` prima di salvare, il codice la scrive con `setParent()`.
 */
final class CompanyGroups
{
    /**
     * Le aziende che possono fare da capogruppo, per un select: quelle senza
     * capogruppo. L'azienda stessa c'è (il form non sa quale mostra): la
     * scarta `problem()`.
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        return RequestMemo::remember(Company::$table, 'group_options', static function (): array {
            $out = [];

            foreach (self::rows(self::safe(static fn () => Company::find(['deleted' => 'false'], null, null, null, ['id', 'business_name', 'parent_id']))) as $row) {
                if ((int) ($row['parent_id'] ?? 0) === 0) {
                    $out[(int) $row['id']] = self::label($row);
                }
            }

            asort($out, SORT_NATURAL | SORT_FLAG_CASE);

            return $out;
        });
    }

    /** La ragione sociale come testo (entità decodificate): chi la stampa la escapa. */
    public static function label(array $row): string
    {
        $name = html_entity_decode(trim((string) ($row['business_name'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $name !== '' ? $name : '#'.($row['id'] ?? '');
    }

    /** La capogruppo di un'azienda, o null. */
    public static function parentOf(int $companyId): ?array
    {
        $company = self::find($companyId);

        return $company !== null && self::parentId($company) > 0 ? self::find(self::parentId($company)) : null;
    }

    /**
     * Le controllate di un'azienda.
     *
     * @return list<array<string, mixed>>
     */
    public static function childrenOf(int $companyId): array
    {
        return $companyId > 0 ? self::rows(self::safe(static fn () => Company::find(['parent_id' => $companyId], null, 'id', 'ASC'))) : [];
    }

    /**
     * Il gruppo di un'azienda, da qualunque azienda del gruppo: la capogruppo
     * e poi le controllate. Un'azienda senza gruppo dà solo sé stessa; una
     * che non c'è, niente.
     *
     * @return list<int>
     */
    public static function groupIds(int $companyId): array
    {
        $company = self::find($companyId);

        if ($company === null) {
            return [];
        }

        $root = self::parentOf($companyId) ?? $company;

        return [(int) $root['id'], ...array_map(static fn (array $row): int => (int) $row['id'], self::childrenOf((int) $root['id']))];
    }

    /**
     * Perché `$parentId` non può essere la capogruppo di `$companyId`: vuoto
     * se può. La capogruppo esiste e non è cancellata, non è l'azienda
     * stessa, non ha una capogruppo; un'azienda con controllate non diventa
     * controllata. `$companyId` 0 è un'azienda nuova; `$parentId` 0 toglie la capogruppo.
     *
     * @return ''|'missing'|'self'|'nested'|'has_children'
     */
    public static function problem(int $companyId, int $parentId): string
    {
        if ($parentId === 0) {
            return '';
        }

        $parent = self::find($parentId);

        return match (true) {
            $parentId === $companyId => 'self',
            $parent === null => 'missing',
            self::parentId($parent) > 0 => 'nested',
            $companyId > 0 && self::childrenOf($companyId) !== [] => 'has_children',
            default => '',
        };
    }

    /** Assegna la capogruppo (0 la toglie), se le regole lo permettono. */
    public static function setParent(int $companyId, int $parentId): bool
    {
        if (self::find($companyId) === null || self::problem($companyId, $parentId) !== '') {
            return false;
        }

        $result = Company::query()->Update(Company::$table, ['parent_id' => $parentId], 'id', $companyId);

        return (bool) ($result->success ?? false);
    }

    /** @return array<string, mixed>|null l'azienda non cancellata */
    private static function find(int $companyId): ?array
    {
        if ($companyId <= 0) {
            return null;
        }

        $row = self::safe(static fn () => Company::findById($companyId));

        return is_array($row) && isset($row['id']) && ($row['deleted'] ?? 'false') === 'false' ? $row : null;
    }

    private static function parentId(array $row): int
    {
        return (int) ($row['parent_id'] ?? 0);
    }

    private static function safe(callable $read): mixed
    {
        try {
            return $read();
        } catch (Throwable) {
            return null;
        }
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
