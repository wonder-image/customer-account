<?php

namespace Wonder\Plugin\CustomerAccount\Support;

use Throwable;
use Wonder\Plugin\CustomerAccount\CustomerAccount;

/**
 * Gli eventi della rubrica: il punto dove si agganciano export, import e sync
 * verso un CRM o un sistema di newsletter.
 *
 * Un ascoltatore si registra con `listen()` oppure dal sito in
 * `custom/config/modules/customer-account.php`, chiave `events.listeners`
 * (`[evento => [callable|classe con handle() o __invoke()]]`). Riceve il nome
 * dell'evento e il payload. Un ascoltatore che lancia un'eccezione finisce nel
 * log e non ferma né il salvataggio né gli altri ascoltatori.
 */
final class ContactEvents
{
    /** payload: `id`, `contact` (la scheda) */
    public const CREATED = 'contact.created';
    /** payload: `id`, `contact` */
    public const UPDATED = 'contact.updated';
    /** payload: `company_id`, `person_id`, `relation` (la riga di `contact_relations`) */
    public const LINKED = 'contact.linked';
    /** payload: `company_id`, `person_id`, `relation` */
    public const UNLINKED = 'contact.unlinked';

    public const EVENTS = [self::CREATED, self::UPDATED, self::LINKED, self::UNLINKED];

    /** @var array<string, list<callable|string>> */
    private static array $listeners = [];

    public static function listen(string $event, callable|string $listener): void
    {
        self::$listeners[$event][] = $listener;
    }

    /** Solo per i test: toglie gli ascoltatori registrati con `listen()`. */
    public static function reset(): void
    {
        self::$listeners = [];
    }

    /** @param array<string, mixed> $payload */
    public static function dispatch(string $event, array $payload): void
    {
        foreach (self::listenersFor($event) as $listener) {
            try {
                $callable = self::callable($listener);

                if ($callable !== null) {
                    $callable($event, $payload);
                }
            } catch (Throwable $error) {
                error_log('[customer-account] ascoltatore di '.$event.' fallito: '.$error->getMessage());
            }
        }
    }

    /** @return list<callable|string> */
    private static function listenersFor(string $event): array
    {
        $configured = [];

        try {
            $configured = (array) (CustomerAccount::config('events.listeners.'.$event, []) ?? []);
        } catch (Throwable) {
            // Senza configurazione leggibile restano quelli registrati in codice.
        }

        return [...array_values($configured), ...(self::$listeners[$event] ?? [])];
    }

    private static function callable(mixed $listener): ?callable
    {
        if (is_string($listener) && class_exists($listener)) {
            $instance = new $listener();

            return method_exists($instance, 'handle') ? [$instance, 'handle'] : (is_callable($instance) ? $instance : null);
        }

        return is_callable($listener) ? $listener : null;
    }
}
