<?php

namespace Wonder\Plugin\CustomerAccount\Support\Import;

use Wonder\App\Dataset\Column;
use Wonder\App\Import\Context;

/**
 * Un modulo che allarga i tracciati della rubrica (`contacts_import.extensions`):
 * colonne e valori in più, chi può caricare, quali schede vede e con quale
 * Model si scrive. Ogni metodo riceve la chiave del tracciato
 * (`PeopleLayout::KEY`, `CompanyLayout::KEY`, ...) e di base non cambia niente:
 * si ridefinisce solo quello che serve. Le estensioni si sommano e l'ordine
 * non conta.
 */
abstract class ImportExtension
{
    /** true concede, false nega (vince su tutto), null lascia decidere agli altri. */
    public function permits(string $layout, object $user): ?bool
    {
        return null;
    }

    /** La lista a cui torna la pagina di caricamento; null = quella del tracciato. */
    public function backUrl(string $layout): ?string
    {
        return null;
    }

    /** @return list<Column> colonne in più, dopo quelle del tracciato */
    public function columns(string $layout): array
    {
        return [];
    }

    /**
     * Il Model con cui scrivere (una sottoclasse di quello del tracciato,
     * sulla stessa tabella); null = quello del tracciato. Due estensioni con
     * Model diversi sono un errore di configurazione.
     *
     * @return class-string|null
     */
    public function model(string $layout): ?string
    {
        return null;
    }

    /** Una scheda che chi carica non vede non si riconosce e non si aggiorna. */
    public function visible(string $layout, array $row): bool
    {
        return true;
    }

    /**
     * I valori in più di una riga. `$current` è la scheda trovata (null se la
     * riga la crea). I valori uguali a quelli salvati si scartano da soli.
     *
     * @param array<string, string> $data celle della riga per chiave di colonna
     * @param array<string, mixed>|null $current
     * @return array{values?: array<string, scalar|null>, errors?: list<string>, warnings?: list<string>}
     */
    public function values(string $layout, array $data, ?array $current, Context $context): array
    {
        return [];
    }
}
