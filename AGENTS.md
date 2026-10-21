# AGENTS.md

`wonder-image/customer-account` è un modulo Composer di `wonder-image/app`
(namespace `Wonder\Plugin\CustomerAccount\`, slug `customer-account`).
Le convenzioni del framework sono in `vendor/wonder-image/app/AGENTS.md`.

- Possiede `contacts` e `contact_addresses` (modelli in `src/Models`), il
  collegamento utente↔scheda (`ContactAccount`), fatturazione e indirizzi
  dell'area account e le Resource backend generiche. Il core tiene solo
  `ExternalReference`, `AccountAddressForm`, `AccountAddressValidation` e
  `AccountModal`: non aggiungere al core proxy verso classi di questo modulo.
- Le pagine dell'account si estendono con i ganci `AccountExtension` /
  `AccountPageExtension` del core (`CustomerAccountExtension`), mai copiando il
  controller; rispettare `AccountPanel::sections()` (`addresses`, `billing`).
  Avvisi di pagina fuori dai form, tema attivo (mai `render('wonder')`), CSRF e
  controllo di proprietà su ogni scrittura.
- Campi della registrazione: config `signup.fields` (`phone`, `birth_date`,
  `billing`, `shipping_address`; `off`/`optional`/`required`) letta da
  `HasSignupFields` / `CustomerAuthProfile`, default del profilo con
  `signupDefaults()`. Indirizzi con prefisso (`billing_`, `shipping_`) via
  `AccountAddressForm` / `AccountAddressValidation` del core; salvataggio nella
  transazione della conferma. Non aggiungere sezioni fuori da
  `SignupFields::SECTIONS` senza test.
- Export: le Resource su `contacts` usano `ContactExport::FORMATS` /
  `columns()` con `downloadAsText()`; niente colonne duplicate per Resource.
- Le Resource sono fallback di tabella: gestionale e sito hanno la precedenza e
  il menu non deve duplicare la voce.
- Migrazioni solo in `database.migrations`, idempotenti, con
  `LegacyTableRenames`; niente drop né merge.
- Nessun alias dei vecchi namespace del core.
- Collegamento della scheda: `ContactAccount::link()` (riscrive l'identità) o
  `linkKeepingIdentity()` (la tiene). Nella registrazione lo fa
  `HasSignupFields::afterUserSaved()`; i profili che usano il trait chiamano
  `parent::` e personalizzano con `linksContactOn()` / `afterContactLinked()`.
- Test: `WI_APP=<app> php tests/run.php`. Chi passa dai Model richiede prima
  `tests/support/FakeDb.php` (database in memoria). Vedi `README.md`.
