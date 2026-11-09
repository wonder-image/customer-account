<?php

namespace Wonder\Plugin\CustomerAccount\Support;

/**
 * Gli eventi delle aziende. Usano lo stesso registro di `ContactEvents`
 * (`listen()` o `events.listeners` nella configurazione del modulo): cambia
 * solo il nome dell'evento. I legami azienda/persona restano
 * `ContactEvents::LINKED` e `UNLINKED`.
 */
final class CompanyEvents
{
    /** payload: `id`, `company` (la scheda) */
    public const CREATED = 'company.created';
    /** payload: `id`, `company` */
    public const UPDATED = 'company.updated';

    public const EVENTS = [self::CREATED, self::UPDATED];

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
