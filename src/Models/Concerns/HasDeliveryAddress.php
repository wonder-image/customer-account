<?php

namespace Wonder\Plugin\CustomerAccount\Models\Concerns;

use Throwable;
use Wonder\App\Schema\Extensions\AddressExtension;

/**
 * Indirizzo di consegna di una scheda (persona o azienda): destinatario,
 * telefono e geo dall'estensione del core, ricerca anche per nome
 * dell'attività. Cambia solo la colonna del padrone. «Presso»
 * (`business_name`, l'attività o la società a quell'indirizzo) non è
 * dell'estensione: `withCompanyData()` porterebbe anche P.IVA, SDI e PEC.
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
            ->withGeo()
            ->withNameSearch();
    }

    /** «Presso»: stesso nome di `shipping_business_name` degli ordini del gestionale. */
    protected static function businessNameColumn(): \Wonder\Sql\TableSchema
    {
        return \Wonder\Sql\TableSchema::key('business_name');
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
