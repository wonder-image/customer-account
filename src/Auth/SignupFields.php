<?php

namespace Wonder\Plugin\CustomerAccount\Auth;

/**
 * Stato dei campi facoltativi della registrazione: `off`, `optional` o `required` per sezione.
 * Prima la config del sito (`signup.fields`), poi i default del profilo, poi `off`; un valore sconosciuto vale `off`.
 */
final class SignupFields
{
    public const SECTIONS = ['phone', 'birth_date', 'billing', 'shipping_address'];
    public const STATES = ['off', 'optional', 'required'];

    /**
     * Campi dell'indirizzo che dicono che la sezione facoltativa è stata compilata: paese, tipo, prefisso,
     * coordinate e nome (precompilato dall'utente) hanno un valore anche se il cliente non scrive niente.
     */
    public const FILLED_KEYS = ['street', 'number', 'city', 'cap', 'more', 'business_name', 'cf', 'pi', 'sdi', 'pec', 'phone'];

    /** @return array<string, string> sezione => stato, per tutte le SECTIONS */
    public static function resolve(array $site, array $defaults = []): array
    {
        $states = [];
        foreach (self::SECTIONS as $section) {
            $state = $site[$section] ?? $defaults[$section] ?? 'off';
            $states[$section] = is_string($state) && in_array($state, self::STATES, true) ? $state : 'off';
        }
        return $states;
    }

    /** Una sezione facoltativa conta solo se il cliente ha scritto un campo vero (vedi FILLED_KEYS). */
    public static function filled(array $values, string $prefix = ''): bool
    {
        $prefix = $prefix !== '' ? rtrim($prefix, '_').'_' : '';
        foreach (self::FILLED_KEYS as $key) {
            $value = $values[$prefix.$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return true;
            }
        }
        return false;
    }

    /** Toglie il prefisso dalle chiavi che lo hanno; le altre non passano. */
    public static function unprefix(array $values, string $prefix): array
    {
        $prefix = rtrim($prefix, '_').'_';
        $out = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && str_starts_with($key, $prefix)) {
                $out[substr($key, strlen($prefix))] = $value;
            }
        }
        return $out;
    }
}
