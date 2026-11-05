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
     * Referenti delle aziende (`contact_relations`): un'azienda con più persone, una persona
     * in più aziende. Spento non compare nulla; la tabella esiste comunque.
     */
    'relations' => [
        'enabled' => false,
    ],
];
