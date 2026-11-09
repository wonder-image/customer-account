# Changelog

## Unreleased

### Breaking

- Le aziende non sono più schede di `contacts`: stanno in `companies` / `company_addresses` (`Company`, `CompanyAddress`, `CompanyResource` su `/backend/companies/`, `CompanyAddressResource`). `contacts` perde `type`, `business_name`, `pi`, `sdi`, `pec` e `is_supplier`; i fornitori sono sempre aziende.
- `contact_relations.company_id` punta a `companies`. Ogni scheda ha un solo repeater: «Referenti» (`contact_people`) sulle aziende, «Aziende» (`contact_companies`) sulle persone.
- `ContactRecord` passa a `SCHEMA` 2 con `entity` e `code`; gli id esterni usano `entity_type = contact` (prima `contacts`) e `company`.

### Added

- `CompanySplitMigration`: al `forge update` sposta le aziende senza account in `companies` (scheda vecchia nel cestino), lega a una nuova azienda con accesso `admin` le aziende con account, crea un'azienda fornitore per i privati fornitori, lascia come contatti le aziende senza ragione sociale. Scrive `contact_company_map` per i moduli dipendenti. L'azienda tiene il codice del contatto con prefisso `cmp_` (`CompanySplitMigration::codeFromContact()`). Idempotente.
- Comando `php forge customer-account:companies`: cosa farà la migrazione, senza scrivere.
- `contact_relations.access` (`none`, `buyer`, `admin`), colonna «Accesso» nei repeater, `ContactRelations::accessOf()` e filtro `companiesOf($personId, $access)`.
- `CompanyRecord` e la base comune `PartyRecord`; eventi `company.created` / `company.updated` (`CompanyEvents`).
- `ContactExport::PERSON_FIELDS` e `COMPANY_FIELDS`; `columns()` accetta l'elenco dei campi.
- Traduzioni delle aziende e degli accessi (`contacts.json`, it/en).

### Removed

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
