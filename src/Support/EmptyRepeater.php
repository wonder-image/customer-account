<?php

declare(strict_types=1);

namespace Wonder\Plugin\CustomerAccount\Support;

use Wonder\App\ResourceSchema\Input;

/**
 * Lo stato vuoto dei repeater facoltativi (referenti, contatti aziendali,
 * brand, social): senza righe un'area con il bottone di aggiunta al centro.
 * Con un framework che non lo conosce ancora il repeater resta com'era.
 */
final class EmptyRepeater
{
    public static function on(Input $field): Input
    {
        return method_exists($field, 'repeaterEmptyState') ? $field->repeaterEmptyState() : $field;
    }
}
