<?php
/**
 * I test non interrogano il DNS: `DomainValidator` del core chiama
 * `checkdnsrr()` e senza rete ogni email risulterebbe non valida.
 * Dentro il namespace dei validatori questa funzione ha la precedenza
 * su quella di PHP: ogni dominio vale, salvo `$GLOBALS['__dns_ok'] = false`.
 */
declare(strict_types=1);

namespace Wonder\Data\Validators;

function checkdnsrr(string $hostname, string $type = 'MX'): bool
{
    // Un test può simulare un DNS che non risponde: `$GLOBALS['__dns_ok'] = false`.
    return $GLOBALS['__dns_ok'] ?? true;
}
