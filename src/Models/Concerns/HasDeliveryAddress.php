<?php

namespace Wonder\Plugin\CustomerAccount\Models\Concerns;

use Throwable;
use Wonder\App\Schema\Extensions\AddressExtension;

/**
 * Indirizzo di consegna di una scheda (persona o azienda): destinatario,
 * telefono e geo dall'estensione del core. Cambia solo la colonna del padrone.
 */
trait HasDeliveryAddress
{
    /** `$prefix` per un indirizzo dentro un altro form (`shipping` nella registrazione): i campi diventano `shipping_*`. */
    public static function address(?string $prefix = null): AddressExtension
    {
        return AddressExtension::simple($prefix, countryDefault: 'IT')
            ->withLink(false)
            ->withContactName()
            ->withPhone()
            ->withGeo();
    }

    public static function decorate(array $row): array
    {
        try {
            return static::address()->decorate($row);
        } catch (Throwable) {
            // Nei comandi di `forge` le funzioni globali del core non ci sono.
            return $row;
        }
    }
}
