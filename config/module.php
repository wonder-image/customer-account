<?php

/*
 * Default di customer-account. Il sito li sovrascrive in custom/config/modules/customer-account.php
 * (stesse chiavi).
 */
return [
    'signup' => [
        /*
         * Campi del secondo passo della registrazione: `off`, `optional` o `required`.
         * Chiavi: `phone`, `birth_date`, `billing` (dati di fatturazione), `shipping_address`
         * (primo indirizzo di spedizione). Quelle non indicate seguono il profilo (per esempio
         * ecommerce chiede il telefono), poi `off`.
         */
        'fields' => [],
    ],

    /*
     * Ascoltatori degli eventi della rubrica (`Support\ContactEvents`): punto di aggancio per
     * export, import e sync verso CRM o newsletter. `[evento => [callable o classe]]`, per
     * esempio `['contact.linked' => [App\Crm\SyncContact::class]]`.
     */
    'events' => [
        'listeners' => [],
    ],

    /*
     * Tracciati di importazione della rubrica, `[chiave => classe]`: il core
     * scarta le voci senza chiave.
     */
    'import' => [
        'layouts' => [
            \Wonder\Plugin\CustomerAccount\Support\Import\PeopleLayout::KEY => \Wonder\Plugin\CustomerAccount\Support\Import\PeopleLayout::class,
            \Wonder\Plugin\CustomerAccount\Support\Import\CompanyLayout::KEY => \Wonder\Plugin\CustomerAccount\Support\Import\CompanyLayout::class,
            \Wonder\Plugin\CustomerAccount\Support\Import\AddressLayout::KEY => \Wonder\Plugin\CustomerAccount\Support\Import\AddressLayout::class,
            \Wonder\Plugin\CustomerAccount\Support\Import\RelationLayout::KEY => \Wonder\Plugin\CustomerAccount\Support\Import\RelationLayout::class,
        ],
    ],

    /*
     * Moduli che allargano quei tracciati: classi che estendono
     * `Support\Import\ImportExtension` (colonne, valori, permessi, schede visibili).
     */
    'contacts_import' => [
        'extensions' => [],
    ],
];
