<?php

namespace Wonder\Plugin\CustomerAccount\Support\Import;

use Throwable;
use Wonder\App\Import\Layouts;
use Wonder\App\Resources\Config\ImportResource;
use Wonder\App\Visibility\RowVisibility;
use Wonder\Elements\Components\Button;

/**
 * I bottoni «Importa ...» dei tracciati che non hanno un elenco proprio
 * (indirizzi, referenti), tra i bottoni dell'elenco: la pagina di caricamento
 * del core non è nel menu e `importLayout()` mette un solo tracciato nel
 * bottone «File». Solo i tracciati che chi guarda può usare.
 */
final class ImportLinks
{
    /**
     * @param list<string> $keys chiavi dei tracciati, nell'ordine dei bottoni
     * @param callable(string): string|null $url per i test; null = la pagina di caricamento
     * @return list<Button>
     */
    public static function buttons(array $keys, ?object $user = null, ?callable $url = null): array
    {
        $user ??= RowVisibility::currentUser();

        if ($user === null) {
            return [];
        }

        $url ??= static fn (string $key): string => ImportResource::url(['layout' => $key]);
        $buttons = [];

        foreach ($keys as $key) {
            try {
                $layout = Layouts::find($key);
            } catch (Throwable) {
                continue;
            }

            if ($layout === null || !$layout->permits($user)) {
                continue;
            }

            $buttons[] = Button::to($url($key), (string) __t('contacts.import.link', ['label' => mb_strtolower($layout->label(), 'UTF-8')]))
                ->variant('secondary')->outline()->size('sm')->icon('bi bi-upload', 'start');
        }

        return $buttons;
    }
}
