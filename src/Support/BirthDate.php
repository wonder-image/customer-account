<?php

namespace Wonder\Plugin\CustomerAccount\Support;

/** Data di nascita come la salva la scheda: `Y-m-d`, esistente, non nel futuro. Vuota è valida. */
final class BirthDate
{
    public static function valid(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return true;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value && $date <= new \DateTimeImmutable('today');
    }
}
