# Changelog

## Unreleased

### Added

- Primo rilascio: schede contatto e indirizzi estratti da `wonder-image/app`.
- Campi della registrazione configurabili dal sito (`signup.fields`: telefono, data di nascita, fatturazione, primo indirizzo di spedizione) con `CustomerAuthProfile` / `HasSignupFields`.
- Export CSV ed Excel dei contatti (`ContactExport`), con celle in testo.
- API Google dichiarate al registro del core (`ModuleGoogleApis`, richiede wonder-image/app#97): Maps JavaScript e Places per l'autocompletamento degli indirizzi.
