# Changelog

## Unreleased

### Added

- Primo rilascio: schede contatto e indirizzi estratti da `wonder-image/app`.
- Campi della registrazione configurabili dal sito (`signup.fields`: telefono, data di nascita, fatturazione, primo indirizzo di spedizione) con `CustomerAuthProfile` / `HasSignupFields`.
- Export CSV ed Excel dei contatti (`ContactExport`), con celle in testo.
- API Google dichiarate al registro del core (`ModuleGoogleApis`, richiede wonder-image/app#97): Maps JavaScript e Places per l'autocompletamento degli indirizzi.
- `ContactAccount::linkKeepingIdentity()`: collega l'utente a una scheda che esiste già senza riscriverne nome, cognome, email e telefono.
- `AccountAddresses`: il primo indirizzo attivo diventa il predefinito, la posizione viene da `nextPosition()` (un solo `MAX`) e, eliminato il predefinito, lo diventa l'indirizzo rimasto con la posizione più bassa.
- `HasSignupFields`: la scheda si collega su `email.verify`, `signup-completion` e `federated`, con i ganci `linksContactOn()` e `afterContactLinked()` per i profili che estendono il trait. Su `email.verify` il collegamento è best effort (errore nel log, nessuna eccezione); su `signup-completion` e `federated` un collegamento fallito lancia `AccountSaveFailed`.
- Test senza database vero (`tests/support/FakeDb.php`): piano della migrazione, collegamento e conflitti, proprietà degli indirizzi, Resource dei contatti.
