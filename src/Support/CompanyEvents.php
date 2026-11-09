<?php

namespace Wonder\Plugin\CustomerAccount\Support;

/**
 * Gli eventi delle aziende. Usano lo stesso registro di `ContactEvents`
 * (`listen()` o `events.listeners` nella configurazione del modulo): cambia
 * solo il nome dell'evento. I legami azienda/persona restano
 * `ContactEvents::LINKED` e `UNLINKED`; quelli azienda/brand sono
 * `BRAND_LINKED` e `BRAND_UNLINKED`.
 */
final class CompanyEvents
{
    /** payload: `id`, `company` (la scheda) */
    public const CREATED = 'company.created';
    /** payload: `id`, `company` */
    public const UPDATED = 'company.updated';
    /** payload: `id`, `brand` (la riga di `company_brands`) */
    public const BRAND_CREATED = 'company.brand_created';
    /** payload: `company_id`, `brand_id`, `brand` */
    public const BRAND_LINKED = 'company.brand_linked';
    /** payload: `company_id`, `brand_id`, `brand` */
    public const BRAND_UNLINKED = 'company.brand_unlinked';

    public const EVENTS = [self::CREATED, self::UPDATED, self::BRAND_CREATED, self::BRAND_LINKED, self::BRAND_UNLINKED];

    public static function listen(string $event, callable|string $listener): void
    {
        ContactEvents::listen($event, $listener);
    }

    /** @param array<string, mixed> $payload */
    public static function dispatch(string $event, array $payload): void
    {
        ContactEvents::dispatch($event, $payload);
    }
}
