<?php
/**
 * I test non interrogano il DNS: `DomainValidator` del core chiama
 * `checkdnsrr()` e senza rete ogni email risulterebbe non valida.
 * Dentro il namespace dei validatori questa funzione ha la precedenza
 * su quella di PHP: ogni dominio vale.
 */
declare(strict_types=1);

namespace Wonder\Data\Validators;

function checkdnsrr(string $hostname, string $type = 'MX'): bool
{
    return true;
}
