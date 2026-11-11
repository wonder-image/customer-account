<?php

namespace Wonder\Plugin\CustomerAccount\Support;

use Throwable;
use Wonder\App\Support\RequestMemo;
use Wonder\Plugin\CustomerAccount\Models\OfficeType;

/**
 * I tipi ufficio creati a mano. `ContactRelations::departments()` li unisce a
 * quelli di serie: la chiave è l'id, che sui recapiti e sui referenti si
 * salva come testo.
 */
final class OfficeTypes
{
    /**
     * I tipi creati, in ordine di nome. Vuoto se la tabella non c'è ancora.
     *
     * @return array<int, string> id => nome
     */
    public static function options(): array
    {
        return RequestMemo::remember(OfficeType::$table, 'options', static function (): array {
            try {
                $found = OfficeType::find(['deleted' => 'false'], null, 'name', 'ASC');
            } catch (Throwable) {
                return [];
            }

            $out = [];

            foreach (is_array($found) ? $found : [] as $row) {
                if (is_array($row) && (int) ($row['id'] ?? 0) > 0) {
                    $out[(int) $row['id']] = self::label((string) ($row['name'] ?? ''));
                }
            }

            return $out;
        });
    }

    /** Il nome come si legge: senza le entità del salvataggio. */
    public static function label(string $name): string
    {
        return trim(html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** Il nome ripulito: uno spazio solo fra le parole. */
    public static function clean(mixed $name): string
    {
        return is_scalar($name) ? trim(preg_replace('/\s+/u', ' ', self::label((string) $name)) ?? '') : '';
    }

    /** Due nomi sono lo stesso tipo? Maiuscole e spazi non contano. */
    public static function same(string $a, string $b): bool
    {
        return mb_strtolower(self::clean($a), 'UTF-8') === mb_strtolower(self::clean($b), 'UTF-8');
    }

    /** L'id del tipo creato con questo nome; 0 se non c'è. */
    public static function idByName(string $name, int $exceptId = 0): int
    {
        foreach (self::options() as $id => $label) {
            if ($id !== $exceptId && self::same($label, $name)) {
                return $id;
            }
        }

        return 0;
    }

    /** Il nome è già di un tipo di serie (o aggiunto da un modulo)? */
    public static function builtIn(string $name): bool
    {
        foreach (ContactRelations::departments() as $key => $label) {
            if (!is_int($key) && self::same((string) $label, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Il tipo creato con questo nome, o uno nuovo.
     *
     * @return array{id: int, name: string, created: bool}|null `null` se il nome è vuoto, troppo lungo, di serie o il salvataggio fallisce
     */
    public static function findOrCreate(mixed $name): ?array
    {
        $name = self::clean($name);

        if ($name === '' || mb_strlen($name, 'UTF-8') > 80 || self::builtIn($name)) {
            return null;
        }

        $id = self::idByName($name);

        if ($id > 0) {
            return ['id' => $id, 'name' => self::options()[$id], 'created' => false];
        }

        try {
            $result = OfficeType::create(['name' => $name]);
        } catch (Throwable) {
            return null;
        }

        $id = (int) ($result->insert_id ?? 0);

        return ($result->success ?? false) && $id > 0 ? ['id' => $id, 'name' => $name, 'created' => true] : null;
    }
}
