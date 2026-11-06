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
];
