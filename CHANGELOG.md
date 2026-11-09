# Changelog

## Unreleased

### Breaking

- Le aziende non sono più schede di `contacts`: stanno in `companies` / `company_addresses` (`Company`, `CompanyAddress`, `CompanyResource` su `/backend/companies/`, `CompanyAddressResource`). `contacts` perde `type`, `business_name`, `pi`, `sdi`, `pec` e `is_supplier`; i fornitori sono sempre aziende.
- `contact_relations.company_id` punta a `companies`. Ogni scheda ha un solo repeater: «Referenti» (`contact_people`) sulle aziende, «Aziende» (`contact_companies`) sulle persone.
- `ContactRecord` passa a `SCHEMA` 2 con `entity` e `code`; gli id esterni usano `entity_type = contact` (prima `contacts`) e `company`.
- Brand: staccare un brand da un'azienda non cancella più la riga di `company_brand_links`, la chiude (`ended_at`); ricollegarlo la riapre. `CompanyBrands::of()`, `idsOf()`, `companiesOf()` e `belongsTo()` senza data guardano solo i legami aperti.
- Il campo `brands` di `HasCompanyBrands` è un repeater (brand, ruolo, predefinito) e non più una select multipla: il valore del form sono righe `brand_id` / `role` / `is_default`. `brandFields()`, `withBrandValues()`, `brandResource()`, `$brandsInput` e `$brandsMarker` restano; un POST con una lista di id funziona ancora.
- `CompanyRecord` passa a `SCHEMA` 3: ogni brand porta `id`, `code`, `slug`, `name`, `role`, `is_default`, `parent_id`, `parent_code`; l'azienda porta `parent_id` e `parent_code`.
- `CompanyBrands::byName()` e `findOrCreate()` non considerano i brand archiviati.

### Added

- Brand delle aziende (nomi commerciali, N:N con le ragioni sociali): tabelle `company_brands` e `company_brand_links`, `Support\CompanyBrands`, `CompanyBrandResource` (con `QuickCreateStore`: niente doppioni per nome), trait `HasCompanyBrands` (campo `brands` cercabile con «+», primo nel form delle aziende), eventi `company.brand_created` / `brand_linked` / `brand_unlinked`, `brands` in `CompanyRecord`.
- Brand con codice e slug univoci (`company_brands.code` con prefisso `cbr_`, `slug` dal nome con `-1`, `-2`… per gli omonimi, `name_key` per i doppioni); `Migrations\CompanyBrandCodesMigration` li dà ai brand esistenti (allinea da sé la tabella, che a quel punto `runTables()` non ha ancora toccato; idempotente).
- Archivio dei brand: `company_brands.archived`, `CompanyBrands::archive()` / `restore()`, campo «Archiviato» nel form del brand. Un brand archiviato esce da `options()` tranne dove è già legato, resta leggibile con `find()`.
- Legame azienda/brand con attributi: `role` (`CompanyBrandLink::ROLES`: `owner`, `licensee`, `intermediary`; estendibili con `CompanyBrands::addRole()`), `is_default` (uno per azienda, `defaultOf()`), `ended_at`. `belongsTo($brandId, $companyId, $at)` risponde anche per una data passata; `linksOf()` dà righe con brand, ruolo e predefinito. `sync()` accetta id o righe `brand_id` / `role` / `is_default`; gli eventi `brand_linked` / `brand_unlinked` portano `role`.
- `CompanyBrands::merge($fromId, $intoId)` unisce un doppione (legami, referenti, archivio) ed emette `company.brand_merged` (`from_id`, `into_id`, `brand`); `similar($name)` trova i nomi uguali a meno di maiuscole, spazi e punteggiatura. Creazione rapida e form del brand avvisano dei simili; dopo la creazione dal form si torna sulla scheda del brand.
- Referenti per brand: tabella `contact_relation_brands` (`Models\ContactRelationBrand`), `ContactRelations::brandsOf()`, `setBrands()`, `brandsByRelation()`, `peopleOf($companyId, $brandId)`; colonna «Brand seguiti» nel repeater dei referenti (`brand_ids`, vuota = tutti). `sync()` scrive i brand solo per le righe che portano `brand_ids`.
- Brand, robustezza: `sync()` e `merge()` in una transazione con gli eventi dopo il commit; `findOrCreate()` riprende il brand di un «+» concorrente invece di fallire; i brand seguiti da un referente contano solo se ancora legati all'azienda (altrimenti li segue tutti) e `sync()` toglie quelli staccati; `belongsTo()` con una data senza ora vale fino a fine giornata; `nameKey()` non è mai vuota per un nome di soli simboli e resta nella colonna anche con le entità di `Query`; il form del brand rifiuta il nome preso solo in creazione o se il nome cambia; `options()` memorizzata per richiesta (`RequestMemo`).
- `Support\Commissioner::label($brandId, $companyId, $personId)`: il nome di chi commissiona (brand, ragione sociale o persona); `resolve()` dà la forma da salvare su un progetto (`brand_id`, `company_id`, `person_id`, `label`), con il brand solo se legato all'azienda e la persona solo se ne è referente. `ContactRelations::isLinked($personId, $companyId)`.
- Linee dei brand a un livello: `company_brands.parent_id`, `CompanyBrands::parentProblem()`, `setParent()`, `parentOf()`, `childrenOf()`, `familyIds()`, `fullLabel()` (usata da `options()`), `parentOptions()`; campo «Brand principale» nel form del brand; `merge()` sposta le linee.
- Gruppi di aziende a un livello: `companies.parent_id`, `Support\CompanyGroups` (`problem()`, `setParent()`, `parentOf()`, `childrenOf()`, `groupIds()`, `options()`), trait `HasCompanyGroup` (campo «Capogruppo», validato in `mutateRequestValues()`; lo porta `HasCompanyBrands`).
- Reparto dei referenti: `contact_relations.department` (lista fissa `ContactRelation::DEPARTMENTS`, estendibile con `ContactRelations::addDepartment()`), colonna «Reparto» nei repeater, `departments()`, `departmentLabel()`, `peopleByDepartment()` per gli invii per reparto; `department` in `relations` dei record.
- `CompanySplitMigration`: al `forge update` sposta le aziende senza account in `companies` (scheda vecchia nel cestino), lega a una nuova azienda con accesso `admin` le aziende con account, crea un'azienda fornitore per i privati fornitori, lascia come contatti le aziende senza ragione sociale. Scrive `contact_company_map` per i moduli dipendenti. L'azienda tiene il codice del contatto con prefisso `cmp_` (`CompanySplitMigration::codeFromContact()`). Idempotente.
- Comando `php forge customer-account:companies`: cosa farà la migrazione, senza scrivere.
- `contact_relations.access` (`none`, `buyer`, `admin`), colonna «Accesso» nei repeater, `ContactRelations::accessOf()` e filtro `companiesOf($personId, $access)`.
- `CompanyRecord` e la base comune `PartyRecord`; eventi `company.created` / `company.updated` (`CompanyEvents`).
- `ContactExport::PERSON_FIELDS` e `COMPANY_FIELDS`; `columns()` accetta l'elenco dei campi.
- `AccountBilling::saveCompany()`: salva la fatturazione di un'azienda con la stessa validazione della scheda; il permesso lo controlla chi chiama (l'ecommerce lo fa solo per gli `admin`).
- Traduzioni delle aziende e degli accessi (`contacts.json`, it/en).

### Fixed

- Elenco dei brand (`/backend/company-brands/`): il nome si stampa escapato. Un brand creato da codice o dalla creazione rapida non passa da `sanitize()` e la cella finiva nell'HTML così com'era.
- Gli errori di validazione di `ContactResource` e `ContactAddressResource` mostrano il testo e non la chiave: usavano `account.validation.invalid` / `required`, che non esistono; ora `contacts.field_invalid` e `contacts.field_required`.

- Gli indirizzi di consegna mai compilati non si salvano più solo perché la nazione è precompilata su `IT` (o «Predefinito» su No): `HasContactRelations::syncRepeaterRelations()` passa `Repeater::columnDefaults()` di `wonder-image/app`.

### Removed

- Le colonne «Nome», «Cognome» ed «Email» per creare una persona dentro una riga dei referenti: la persona nuova si crea dalla finestra del modulo che usa il repeater (nel gestionale «Nuovo referente») e si sceglie dalla tendina. `ContactRelations::createPerson()` resta.
- Il tipo `private`/`business` delle schede: `ContactRelations::typesOf()`, la chiave `type` di `ContactRecord` e la colonna «Tipo» dell'export.

### Deprecated

- `ContactExport::FIELDS`: usare `PERSON_FIELDS` o `COMPANY_FIELDS`.

### Changed

- I referenti delle aziende sono sempre attivi: l'interruttore `relations.enabled` non c'è più e `ContactRelations::enabled()` vale sempre `true`.

### Added

- Primo rilascio: schede contatto e indirizzi estratti da `wonder-image/app`.
- Referenti delle aziende (wonder-image/gestionale#145): tabella `contact_relations` (`ContactRelation`), repeater «Referenti» e «Aziende» su `ContactResource` con il trait `HasContactRelations`, persone create al volo, `ContactRelations::peopleOf()` / `companiesOf()`. Interruttore `relations.enabled`, spento di default; la tabella si crea comunque.
- Integrazioni: eventi `contact.created`, `contact.updated`, `contact.linked`, `contact.unlinked` (`ContactEvents`, ascoltatori in `events.listeners` o con `listen()`), `ContactRelations::sync()` come unico punto di scrittura dei legami, `ContactRecord` (`from()`, `fromArray()`, `validate()`) come forma stabile della scheda per export, import e sync, con `external_ids` da `external_references` e i consensi del core.
- Campi della registrazione configurabili dal sito (`signup.fields`: telefono, data di nascita, fatturazione, primo indirizzo di spedizione) con `CustomerAuthProfile` / `HasSignupFields`.
- Export CSV ed Excel dei contatti (`ContactExport`), con celle in testo.
- API Google dichiarate al registro del core (`ModuleGoogleApis`, richiede wonder-image/app#97): Maps JavaScript e Places per l'autocompletamento degli indirizzi.
- `ContactAccount::linkKeepingIdentity()`: collega l'utente a una scheda che esiste già senza riscriverne nome, cognome, email e telefono.
- `AccountAddresses`: un nuovo indirizzo diventa il predefinito se la scheda non ne ha uno attivo (si riparano anche le schede di prima), la posizione viene da `nextPosition()` (un solo `MAX`) e, eliminato il predefinito, lo diventa l'indirizzo rimasto con la posizione più bassa.
- `HasSignupFields`: la scheda si collega su `email.verify`, `signup-completion` e `federated`, con i ganci `linksContactOn()` e `afterContactLinked()` per i profili che estendono il trait. Su `email.verify` il collegamento è best effort (errore nel log, nessuna eccezione); su `signup-completion` e `federated` un collegamento fallito lancia `AccountSaveFailed`.
- Test senza database vero (`tests/support/FakeDb.php`): piano della migrazione, collegamento e conflitti, proprietà degli indirizzi, Resource dei contatti.
- Test: proprietà degli indirizzi senza scheda (`contactId` 0).

### Changed

- Pannello: il telefono della scheda nel campo usa `AuthValidator::localPhone()` e `defaultPrefix()` del core al posto di una copia privata. Richiede wonder-image/app con questi metodi (branch `fix/2026-10-1`, non ancora su `main`).
- Indirizzi del pannello con la pagina scaduta (token non valido): l'editor nuovo o di modifica si riapre con l'avviso generico 929 e i valori scritti (`Csrf::oldInput`, password e token vuoti); l'eliminazione torna all'elenco con il flash 929. Niente più 419 con testo tecnico. Override di `renderCsrfFailure()`; richiede wonder-image/app `^2.4.0-beta.5` (da app#128: `Csrf::ALERT`, `oldInput()`, `flashFailure()`).
- Contatti e indirizzi dal pannello: il CSRF è quello del core (`_csrf`, emesso dalla pagina del form). Con il core da `main` (beta.4) lo verifica anche `ResourcePageController` prima di `mutateRequestValues()`; con beta.3 lo controlla solo `verifyFormToken()`, che resta come difesa locale con `Csrf::verify()` e lo stesso messaggio `contacts.csrf`.

### Removed

- Campo nascosto `_contact_csrf` dei form di `ContactResource` e `ContactAddressResource`: chi chiama `mutateRequestValues()` a mano passa `_csrf` (stesso token di sessione).
