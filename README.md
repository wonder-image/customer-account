# wonder-image/customer-account

Modulo Composer per [wonder-image/app](https://github.com/wonder-image/app): l'account cliente persistente.

Contiene le schede contatto (`contacts`, `contact_addresses`), il collegamento fra utente e scheda, i dati di fatturazione, gli indirizzi di spedizione multipli nell'area account, il pannello backend generico dei contatti e la migrazione delle vecchie tabelle `gst_*`.

È la base comune di `wonder-image/gestionale` e `wonder-image/ecommerce`. Un sito con il solo `wonder-image/app` non ha tabelle, Resource né menu dei contatti.

## Installazione

```bash
composer require wonder-image/customer-account
```

Abilitalo in `custom/config/modules.php` e lancia `php forge update --local`.

Ordine consigliato in `modules.php`: `customer-account`, poi `gestionale`, poi `ecommerce`. Chi dipende dal modulo lo dichiara in `dependencies.modules` del proprio `module.json`.

## Cosa registra

| Parte | Dove | Note |
|---|---|---|
| Modelli `Contact`, `ContactAddress` | `src/Models` | Tabelle `contacts` e `contact_addresses`, escluse dalla sync dei dati operativi. |
| Migrazione `LegacyContactTablesMigration` | `database.migrations` | `forge update` la esegue prima dell'allineamento delle tabelle: rinomina `gst_contacts` e `gst_contact_addresses` con un solo `RENAME TABLE` (ID, righe e FK intatti). Se esistono sia il nome vecchio sia il nuovo si ferma senza toccare nulla. Idempotente. |
| Resource `ContactResource`, `ContactAddressResource` | `src/Resources` | `/backend/contacts/` e `/backend/contact-addresses/`, solo `admin`/`administrator`, CSRF, niente API né eliminazione. Sono fallback di tabella (`isTableFallback()`): con il gestionale vince il suo «Clienti» e il menu non mostra «Contatti». |
| `CustomerAccountExtension` | `config/routes/route.frontend.php` | Si registra con `AccountRoutes::extend()` nel pannello account del core. |
| Viste sigillate | `view/pages/account`, `view/components/account` | Non si sostituiscono da `custom/`. |

## Area account

L'estensione usa i ganci di `AccountExtension` e `AccountPageExtension` del core e rispetta le sezioni di `AccountPanel::sections()`: senza `addresses` o `billing` non registra le relative route, voci e righe.

- **Indirizzi** (`addresses`): route `account.addresses`, `.create`, `.edit`, `.delete` su `/account/indirizzi/…`, voce di menu dopo «Dati personali», modal di modifica e di conferma con CSRF, ripiego senza JS e controllo di proprietà (404 su un indirizzo di un altro cliente).
- **Fatturazione** (`billing`): righe e modal `account-billing` in «Dati personali», salvate con `savePersonalForm('billing', …)`; `/account/fatturazione/` porta lì.
- **Dati personali**: data di nascita nel modal e nella riga, telefono e prefisso presi dalla scheda; il salvataggio collega o aggiorna la scheda nella stessa transazione del core.
- **Panoramica**: righe di fatturazione e indirizzi dopo quella dei dati personali.
- **Cambio email**: la scheda collegata prende la nuova email nella transazione della conferma.
- **Avvisi**: un utente senza scheda cliente vede un avviso di pagina, fuori dai form, e non può aggiungere indirizzi.

## Campi della registrazione

Il sito decide cosa chiedere nel secondo passo della registrazione in `custom/config/modules/customer-account.php` (stesse chiavi di `config/module.php`):

```php
return [
    'signup' => [
        'fields' => [
            'phone' => 'required',
            'birth_date' => 'optional',
            'billing' => 'optional',
            'shipping_address' => 'required',
        ],
    ],
];
```

Ogni chiave vale `off`, `optional` o `required`; un valore sconosciuto vale `off`.

| Chiave | Cosa chiede | Dove finisce |
|---|---|---|
| `phone` | telefono con prefisso | utente e scheda |
| `birth_date` | data di nascita | `contacts.birth_date` |
| `billing` | dati di fatturazione (campi con prefisso `billing_`) | indirizzo della scheda |
| `shipping_address` | primo indirizzo di spedizione (prefisso `shipping_`) | `contact_addresses` |

Serve un profilo con i campi: `Auth\CustomerAuthProfile` (il profilo del core più il trait), oppure il trait `Auth\Concerns\HasSignupFields` in un profilo che ne estende un altro (ecommerce lo fa). Il profilo dà i suoi default con `signupDefaults()`, per esempio `['phone' => 'required']`: la config del sito vince, le chiavi assenti seguono il profilo e poi `off`.

- Una sezione facoltativa si salva solo se il cliente scrive un campo vero (`SignupFields::FILLED_KEYS`): paese, prefisso e nome precompilati non bastano.
- Gli errori degli indirizzi escono come avvisi di pagina con il nome della sezione davanti («Indirizzo di spedizione: Città: campo obbligatorio.»).
- Scheda, data di nascita, fatturazione e indirizzo si salvano nella transazione della conferma: un errore annulla la registrazione.
- Una sezione `required` che manca nella scheda fa passare dal secondo passo anche chi entra con un accesso federato (`requiresCompletion()`). Con sole sezioni `optional` l'accesso federato non le chiede.

## Export dei contatti

`ContactResource` esporta in Excel (.xlsx) e CSV dal bottone «Esporta» della tabella. Le colonne vengono da `Support\ContactExport::columns()`, condivise con le Resource del gestionale sulla stessa tabella: anagrafica, recapiti, fatturazione, stato e data di creazione. Le celle escono come testo (`downloadAsText()` del core): niente formule, zeri iniziali di CAP e telefoni intatti. L'export prende tutte le righe della `querySchema()` della Resource, non i filtri della tabella.

## Per i moduli che si appoggiano alla scheda

- `Account\ContactAccount::link($userId, $input)` collega l'utente a una scheda: riusa quella già collegata o una email non assegnata, rifiuta una scheda di un altro account. Va chiamato da `AuthProfile::afterUserSaved()` del progetto (ecommerce lo fa); l'auth generica non crea schede.
- `Account\CustomerContact::forUser($userId)` restituisce la scheda (letta una volta per richiesta); `forget($userId)` la invalida dopo una scrittura.
- `Account\Concerns\ManagesContact` dà ai controller del pannello `contact($userId)` e `contactErrors($contact)`.
- `Account\AccountAddresses` e `Account\AccountBilling` leggono e salvano indirizzi e fatturazione con `AccountAddressValidation` del core.
- I modelli si estendono come fa il gestionale, che aggiunge solo i campi commerciali.

Un'estensione che lavora sulle righe o le voci di questo modulo deve registrarsi dopo di lui. I file di route dei moduli si caricano in ordine alfabetico di percorso, non di dipendenza: oggi `customer-account` precede `ecommerce` e `gestionale`; un modulo con un nome che lo precede non può contare sulle sue righe.

## Compatibilità

Il modulo non carica alias dei vecchi namespace del core (`Wonder\App\Models\Contacts\*`, `Wonder\App\Resources\Contacts\*`, `Wonder\Auth\Frontend\{ContactAccount,AccountAddresses,AccountBilling}`): chi li importava passa a `Wonder\Plugin\CustomerAccount\…`. Le tabelle e i dati non cambiano.

## Verifica

```bash
WI_APP=/percorso/di/wonder-image/app php tests/run.php
```

Da un sito con il modulo: `php forge update --local`, poi area account (indirizzi, fatturazione, dati personali), registrazione con `signup.fields` ed export di `/backend/contacts/`.
