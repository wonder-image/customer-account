# Changelog

## Unreleased

### Added

- Primo rilascio: schede contatto e indirizzi estratti da `wonder-image/app`.
- Campi della registrazione configurabili dal sito (`signup.fields`: telefono, data di nascita, fatturazione, primo indirizzo di spedizione) con `CustomerAuthProfile` / `HasSignupFields`.
- Export CSV ed Excel dei contatti (`ContactExport`), con celle in testo.
- API Google dichiarate al registro del core (`ModuleGoogleApis`, richiede wonder-image/app#97): Maps JavaScript e Places per l'autocompletamento degli indirizzi.
- `ContactAccount::linkKeepingIdentity()`: collega l'utente a una scheda che esiste già senza riscriverne nome, cognome, email e telefono.
- `AccountAddresses`: un nuovo indirizzo diventa il predefinito se la scheda non ne ha uno attivo (si riparano anche le schede di prima), la posizione viene da `nextPosition()` (un solo `MAX`) e, eliminato il predefinito, lo diventa l'indirizzo rimasto con la posizione più bassa.
- `HasSignupFields`: la scheda si collega su `email.verify`, `signup-completion` e `federated`, con i ganci `linksContactOn()` e `afterContactLinked()` per i profili che estendono il trait. Su `email.verify` il collegamento è best effort (errore nel log, nessuna eccezione); su `signup-completion` e `federated` un collegamento fallito lancia `AccountSaveFailed`.
- Test senza database vero (`tests/support/FakeDb.php`): piano della migrazione, collegamento e conflitti, proprietà degli indirizzi, Resource dei contatti.
- Test: proprietà degli indirizzi senza scheda (`contactId` 0), a verifica di C-AUTH-Mod-2.

### Changed

- Pannello: il telefono della scheda nel campo usa `AuthValidator::localPhone()` e `defaultPrefix()` del core al posto di una copia privata. Richiede wonder-image/app con questi metodi (branch `fix/2026-10-1`, non ancora su `main`).
- Contatti e indirizzi dal pannello: il CSRF è quello del core (`_csrf`, verificato da `ResourcePageController` prima di `mutateRequestValues()`). `verifyFormToken()` resta come difesa locale con `Csrf::verify()` e lo stesso messaggio `contacts.csrf`. Un form aperto prima dell'aggiornamento va ricaricato una volta.

### Removed

- Campo nascosto `_contact_csrf` dei form di `ContactResource` e `ContactAddressResource`: chi chiama `mutateRequestValues()` a mano passa `_csrf` (stesso token di sessione).
