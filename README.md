# wonder-image/customer-account

Modulo Composer per [wonder-image/app](https://github.com/wonder-image/app): l'account cliente persistente.

Contiene le persone (`contacts`, `contact_addresses`), le aziende (`companies`, `company_addresses`), i legami fra le due (`contact_relations`), il collegamento fra utente e scheda, i dati di fatturazione, gli indirizzi di spedizione multipli nell'area account, il pannello backend generico dei contatti e la migrazione delle vecchie tabelle `gst_*`.

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
| Modelli `Contact`, `ContactAddress` | `src/Models` | Persone: tabelle `contacts` e `contact_addresses`, escluse dalla sync dei dati operativi. |
| Modelli `Company`, `CompanyAddress` | `src/Models` | Aziende: tabelle `companies` e `company_addresses`, con fatturazione aziendale (`asBusiness()`), `is_customer` e `is_supplier`. |
| Migrazione `CompanySplitMigration` | `database.migrations` | Separa le aziende dai contatti, una volta sola: vedi «Aziende separate dai contatti». |
| Comando `customer-account:companies` | `console` | `php forge customer-account:companies` mostra cosa farà la migrazione, senza scrivere. |
| Migrazione `LegacyContactTablesMigration` | `database.migrations` | `forge update` la esegue dopo la rinomina del core (`gst_external_references` → `external_references`) e prima dell'allineamento delle tabelle (`runTables()`): rinomina `gst_contacts` e `gst_contact_addresses` con un solo `RENAME TABLE` (ID, righe e FK intatti). Se esistono sia il nome vecchio sia il nuovo si ferma con un errore: la rinomina del core resta fatta, le tabelle non si allineano, niente si cancella né si unisce. Riconciliate le tabelle, il `forge update` seguente riprende da lì. Idempotente. |
| Resource `ContactResource`, `ContactAddressResource`, `CompanyResource`, `CompanyAddressResource` | `src/Resources` | `/backend/contacts/`, `/backend/contact-addresses/`, `/backend/companies/` e `/backend/company-addresses/`, solo `admin`/`administrator`, CSRF, niente API né eliminazione. Sono fallback di tabella (`isTableFallback()`): per la tabella vince la prima Resource non fallback nell'ordine di scoperta del registro (`ResourceRegistry::resolveByTable()`), e il menu non mostra «Contatti» se un'altra Resource, che non ne è sottoclasse, usa `contacts`. Il «Clienti» del gestionale è uno di questi casi. |
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
- La scheda si collega con i dati dell'utente su `email.verify`, `signup-completion` e `federated` (mai su `signup-request`). Su `email.verify` è best effort: il token è già consumato, quindi un errore va nel log (`error_log()`, come gli altri errori dell'auth del core) e l'utente prosegue verso la conferma, dove il collegamento si rifà in transazione. Su `signup-completion` e `federated` una scheda che non si collega ferma il passo con `AccountSaveFailed`.
- Scheda, data di nascita, fatturazione e indirizzo si salvano nella transazione della conferma: un errore annulla la registrazione.
- Una sezione `required` che manca nella scheda fa passare dal secondo passo anche chi entra con un accesso federato (`requiresCompletion()`). Con sole sezioni `optional` l'accesso federato non le chiede.

## Export dei contatti

`ContactResource` e `CompanyResource` esportano in Excel (.xlsx) e CSV dal bottone «Esporta» della tabella. Le colonne vengono da `Support\ContactExport::columns()` (`PERSON_FIELDS` per le persone, `COMPANY_FIELDS` per le aziende), condivise con le Resource del gestionale sulla stessa tabella: anagrafica, recapiti, fatturazione, stato e data di creazione. Le celle escono come testo (`downloadAsText()` del core): niente formule, zeri iniziali di CAP e telefoni intatti. L'export prende tutte le righe della `querySchema()` della Resource, non i filtri della tabella.

## Persone e aziende

Una persona (`contacts`) e un'azienda (`companies`) sono schede distinte. Non c'è più il tipo `private`/`business`: la persona ha nome, cognome e codice fiscale; l'azienda ha ragione sociale, P.IVA, SDI e PEC. Tutte e due hanno la loro fatturazione e i loro indirizzi di spedizione.

- Un'azienda può stare da sola, come cliente (`is_customer`), fornitore (`is_supplier`) o tutti e due. I fornitori sono sempre aziende.
- Una persona può avere un account (`user_id`); un'azienda no: ci accede tramite le persone legate.
- `CompanyResource` estende `ContactResource`: cambiano campi obbligatori (`business_name`), ricerca, export, indirizzi ed eventi.

## Referenti delle aziende

Un'azienda può avere più referenti, e una persona può stare in più aziende. Il legame è la tabella `contact_relations` (`Models\ContactRelation`): `company_id` (→ `companies`), `person_id` (→ `contacts`), `job_title`, `is_primary`, `receives_documents`, `access`, `position`, `deleted`. Ogni coppia azienda/persona compare una volta sola, anche fra le righe cancellate: chi la ricollega riprende la riga di prima.

`access` dice cosa può fare la persona per l'azienda, per esempio dall'account sul negozio:

| Valore | Significato |
|---|---|
| `none` | solo referente, nessun accesso (default) |
| `buyer` | può comprare a nome dell'azienda |
| `admin` | compra e gestisce i dati dell'azienda |

I referenti sono sempre attivi: non c'è un interruttore. `ContactRelations::enabled()` vale sempre `true` (i test lo spengono con `useEnabled(false)`).

- Ogni scheda ha un solo repeater: «Referenti» su `CompanyResource` (input `contact_people`), «Aziende» su `ContactResource` (input `contact_companies`). Il lato lo dice `relationSide()` del trait. Una riga dei referenti sceglie una persona esistente o ne crea una con nome, cognome ed email facoltativa: la nuova persona è attiva, non cliente e senza account. Se la creazione non riesce (per esempio un'email non valida), la riga si scarta senza errore.
- Al massimo un referente principale per azienda: un nuovo principale, anche dal lato della persona, toglie il titolo agli altri.
- I repeater si salvano solo se il form li ha mostrati (campo nascosto `contact_relations_form`): un salvataggio da API o da un form senza repeater non tocca i legami. Una riga senza `access` tiene quello che aveva.
- `Support\ContactRelations::peopleOf($companyId)` dà le righe vive con la persona sotto `contact`; `companiesOf($personId, $access = null)` con l'azienda sotto `company`, filtrabili per accesso (`['buyer', 'admin']`). `accessOf($personId, $companyId)` dà l'accesso, `none` se non sono legate.
- Una Resource sulla tabella delle persone o delle aziende prende il repeater con il trait `Resources\Concerns\HasContactRelations` (`...static::contactRelationFields()` nel `formSchema()`). Il gancio `relationPersonPayload(array $values)` ritocca i valori della persona creata al volo.
- `Support\ContactRelations::sync($parentColumn, $parentId, $rows)` è l'unico punto che scrive i legami: i repeater, un'API, un import o una sync ci passano. Le righe assenti si cancellano (soft delete).

## Aziende separate dai contatti

Fino alla 0.1 un'azienda era una scheda di `contacts` con `type = business`. `Migrations\CompanySplitMigration` le sposta in `companies` al primo `forge update`, prima che l'allineamento delle tabelle tolga da `contacts` le colonne `type`, `business_name`, `pi`, `sdi`, `pec` e `is_supplier`:

| Scheda | Cosa succede |
|---|---|
| azienda senza account | diventa un'azienda con i suoi indirizzi e legami; la scheda contatto va nel cestino (`deleted = true`) |
| azienda con account | la persona resta e si lega alla nuova azienda con accesso `admin` |
| privato fornitore | nasce un'azienda fornitore «Nome Cognome», legata alla persona |
| azienda senza ragione sociale | resta un contatto: va sistemata a mano |

- Il codice dell'azienda è quello del contatto con `cmp_` al posto di `con_` (`con_demo-filati-nord` → `cmp_demo-filati-nord`), così chi riconosce le righe dal codice le ritrova; se è già preso ne nasce uno nuovo.
- Gli id esterni (`external_references`) passano da `entity_type = contacts` a `contact`, e a `company` per le schede spostate.
- Ogni scheda trattata finisce in `contact_company_map` (`contact_id`, `company_id`, `action`): i moduli che puntano ai contatti (documenti, fornitori del gestionale) la leggono per spostare i loro riferimenti. È l'unica eccezione alla regola «niente spostamenti di dati» delle migrazioni del modulo.
- È idempotente: senza la colonna `type` non fa niente, e una scheda già nella mappa non si ritratta.
- Prima di aggiornare: `php forge customer-account:companies` elenca le schede di ogni caso, senza scrivere.

## Integrazioni (export, import, sync)

Il modulo non contiene connettori per CRM o newsletter: dà i ganci per scriverli nel sito o in un modulo.

**Eventi** (`Support\ContactEvents`):

| Evento | Quando | Payload |
|---|---|---|
| `contact.created` | scheda creata dal backend o persona creata al volo da un referente | `id`, `contact` |
| `contact.updated` | scheda salvata dal backend | `id`, `contact` |
| `contact.linked` | nuovo legame azienda/persona (anche una riga cancellata che torna viva) | `company_id`, `person_id`, `relation` |
| `contact.unlinked` | legame tolto | `company_id`, `person_id`, `relation` |
| `company.created` | azienda creata dal backend | `id`, `company` |
| `company.updated` | azienda salvata dal backend | `id`, `company` |

Gli eventi delle aziende sono in `Support\CompanyEvents`, sullo stesso registro. Gli ascoltatori si registrano in codice con `ContactEvents::listen($evento, $callable)` oppure dal sito:

```php
// custom/config/modules/customer-account.php
return [
    'events' => [
        'listeners' => [
            'contact.updated' => [\App\Crm\PushContact::class], // handle($evento, $payload) o __invoke
        ],
    ],
];
```

Un ascoltatore riceve `($evento, $payload)`. Se lancia un'eccezione, l'errore finisce nel log e il salvataggio prosegue. Un ascoltatore lento rallenta il salvataggio: per una chiamata esterna conviene accodare un lavoro (scheduler del core) e farlo lì.

**Forma stabile della scheda** (`Support\ContactRecord` per le persone, `Support\CompanyRecord` per le aziende, base comune `Support\PartyRecord`):

- `from($rowOrId)` dà un array serializzabile con `schema` (oggi `2`), `entity` (`contact` o `company`), `id`, `code`, i campi (`ContactExport::PERSON_FIELDS` più `birth_date` per la persona, `ContactExport::COMPANY_FIELDS` per l'azienda), `is_customer`, `active`, `relations` (legami vivi: id dell'altro lato, `job_title`, `is_primary`, `receives_documents`, `access`) ed `external_ids` (righe di `external_references` con `entity_type` uguale a `entity`: `provider`, `environment`, `object_type`, `external_id`, `synced_at`). La persona aggiunge `user_id` e `consents` (stato corrente dei consensi dell'utente collegato, dal registro del core); l'azienda `is_supplier`. È la base per un export o un payload verso un CRM.
- `fromArray($input)` porta un record in ingresso agli stessi campi, ripuliti; le chiavi assenti restano assenti, così un import parziale non svuota la scheda.
- `validate($record)` dà gli errori (campo → `required` / `invalid`) con le regole di base: nome o cognome per la persona, ragione sociale per l'azienda, formato di email e PEC, legami e id esterni completi.

L'id di un sistema esterno si salva in `external_references` (modello `ExternalReference` del core), non in una colonna nuova della scheda. I consensi restano quelli del core: il modulo non ne aggiunge.

## Per i moduli che si appoggiano alla scheda

- `Account\ContactAccount::link($userId, $input)` collega l'utente a una scheda: riusa quella già collegata o una email non assegnata, rifiuta una scheda di un altro account (`contact_link_conflict`). Nome, cognome ed email della scheda diventano quelli dell'utente; il telefono solo se arriva pieno.
- `Account\ContactAccount::linkKeepingIdentity($userId, $input)` fa lo stesso, ma una scheda che esiste già tiene nome, cognome, email e telefono: scrive solo `user_id`, `is_customer` e `active`. È per la scheda compilata da un ordine da ospite. Senza scheda la crea con i dati dell'utente.
- Il collegamento avviene anche da solo: `CustomerContact::forUser()` crea la scheda che manca e `HasSignupFields::afterUserSaved()` la collega nella registrazione. Un profilo con il trait non lo rifà: chiama `parent::afterUserSaved()` e usa i due ganci `protected`, `linksContactOn($surface)` (su quali superfici collegare) e `afterContactLinked($userId, $contactId, $surface, $input)` (dopo il collegamento, prima dei campi del secondo passo). Su `email.verify` un collegamento fallito non lancia eccezioni né chiama `afterContactLinked()`: finisce nel log e si ritenta su `signup-completion`. Su `signup-completion` e `federated` lancia `AccountSaveFailed`. L'auth generica del core, senza il trait, non crea schede.
- `Account\CustomerContact::forUser($userId)` restituisce la scheda (letta una volta per richiesta) e, se manca, la crea con `link()`: non è una lettura pura. `forget($userId)` la invalida dopo una scrittura.
- `Account\Concerns\ManagesContact` dà ai controller del pannello `contact($userId)` e `contactErrors($contact)`.
- `Account\AccountAddresses` e `Account\AccountBilling` leggono e salvano indirizzi e fatturazione con `AccountAddressValidation` del core, che vale sempre. Un nuovo indirizzo diventa il predefinito (`is_default`) se la scheda non ne ha già uno attivo: così si riparano anche le schede create prima, con indirizzi ma senza predefinito, al primo indirizzo aggiunto (la modifica di un indirizzo non tocca `is_default`). La posizione viene da `nextPosition()` (un solo `MAX`). Se si elimina il predefinito, lo diventa l'indirizzo rimasto con la posizione più bassa; la promozione arriva dopo l'eliminazione, fuori transazione, e se non riesce la scheda resta senza predefinito fino al prossimo indirizzo aggiunto.
- I modelli si estendono come fa il gestionale, che aggiunge solo i campi commerciali.

Un'estensione che lavora sulle righe o le voci di questo modulo deve registrarsi dopo di lui. I file di route dei moduli si caricano in ordine alfabetico di percorso, non di dipendenza: oggi `customer-account` precede `ecommerce` e `gestionale`; un modulo con un nome che lo precede non può contare sulle sue righe.

## Compatibilità

Il modulo non carica alias dei vecchi namespace del core (`Wonder\App\Models\Contacts\*`, `Wonder\App\Resources\Contacts\*`, `Wonder\Auth\Frontend\{ContactAccount,AccountAddresses,AccountBilling}`): chi li importava passa a `Wonder\Plugin\CustomerAccount\…`. Le tabelle e i dati non cambiano.

## Verifica

```bash
WI_APP=/percorso/di/wonder-image/app php tests/run.php
```

Da un sito con il modulo: `php forge update --local`, poi area account (indirizzi, fatturazione, dati personali), registrazione con `signup.fields` ed export di `/backend/contacts/` e `/backend/companies/`. Prima del primo aggiornamento dopo la 0.1: `php forge customer-account:companies`.
