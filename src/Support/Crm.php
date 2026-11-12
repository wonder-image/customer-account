<?php

namespace Wonder\Plugin\CustomerAccount\Support;

/**
 * L'interruttore del «CRM» delle schede: più recapiti tipizzati per persone,
 * aziende, brand e referenti, e i social di brand e persone. Spento (il
 * default) i form mostrano un'email e un telefono soli; i dati in più già
 * salvati restano dove sono (`ContactPoints::syncAll()` con `primary_only`,
 * `BrandData::clean()` con `$primaryOnly`). Lo accende un modulo al boot o
 * prima di costruire un form, come `CompanyBrands::useKit()`.
 */
final class Crm
{
    private static bool $enabled = false;

    public static function enable(bool $enabled = true): void
    {
        self::$enabled = $enabled;
    }

    public static function enabled(): bool
    {
        return self::$enabled;
    }
}
