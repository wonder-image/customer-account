# AGENTS.md

`wonder-image/customer-account` è un modulo Composer di `wonder-image/app`
(namespace `Wonder\Plugin\CustomerAccount\`, slug `customer-account`).
Le convenzioni del framework sono in `vendor/wonder-image/app/AGENTS.md`.

- Possiede persone (`contacts`, `contact_addresses`), aziende (`companies`,
  `company_addresses`) e i loro legami (`contact_relations`, con `access`
  `none`/`buyer`/`admin`), modelli in `src/Models`, il
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
- Persone e aziende sono tabelle distinte: niente colonna `type`, i fornitori
  sono sempre aziende. `CompanyResource` estende `ContactResource` e cambia
  solo i punti di estensione (`requiredKeys()`, `exportFields()`,
  `addressesPath()`, `contactEvent()`, `relationSide()`). Un solo repeater dei
  legami azienda/persona per lato; scritture dei legami solo da
  `ContactRelations::sync()`. Le aziende hanno in più il repeater dei brand
  (`HasCompanyBrands`, senza `RepeaterRelation`): legami azienda/brand solo da
  `CompanyBrands` (`sync()`, `merge()`), brand seguiti dai referenti solo da
  `ContactRelations` (`sync()` con `brand_ids`, `setBrands()`, `moveBrand()`).
- Regola di prodotto: «vuoto = tutti». Un referente senza aree si occupa di
  tutto (`contact_relation_departments`, scritte solo da `ContactRelations`:
  `sync()` con `departments`, `setDepartments()`), senza brand li segue tutti;
  un indirizzo senza brand vale per tutti (`company_address_brands`, solo da
  `CompanyAddresses`). Chi sceglie i destinatari di un documento usa
  `ContactRelations::peopleFor()`. Accesso, principale e `receives_documents`
  restano sul legame: l'accesso non deriva mai dall'area.
- Recapiti in più (`contact_points` / `company_points`) solo da
  `ContactPoints`; email e telefono principali restano colonne della scheda.
  Il collegamento account↔scheda e ogni ricerca della scheda per email usano
  solo `contacts.email`: mai un recapito in più (c'è un test).
- Dati del brand e brand kit: regole in `BrandData` (URL solo http/https,
  colori esadecimali, social da lista), kit visibile solo con
  `CompanyBrands::useKit()`; il logo passa solo come file caricato.
  `CompanyBrands::byDomain()` non riconosce mai una casella generica.
- Brand: `code` (`cbr_`) e `slug` univoci e immutabili, `name_key` per i
  doppioni; un legame staccato si chiude (`ended_at`), non si cancella; un
  brand doppione si unisce (`merge()`, evento `company.brand_merged`) e si
  archivia, non si cancella. Ruoli in `CompanyBrandLink::ROLES` (testo,
  estendibili con `CompanyBrands::addRole()`). Tenuto separato dai marchi dei
  prodotti del gestionale (`gst_brands`).
- Livelli: brand → linea (`company_brands.parent_id`) e azienda → capogruppo
  (`companies.parent_id`), sempre un livello solo. Le regole stanno in un punto:
  `CompanyBrands::parentProblem()` e `CompanyGroups::problem()`; ogni
  `parent_id` postato passa da lì (`HasCompanyGroup`, che `HasCompanyBrands`
  porta, e `CompanyBrandResource`). Il committente di un progetto si salva con
  `Commissioner::resolve()`.
- Integrazioni: `ContactRecord` / `CompanyRecord` (base `PartyRecord`,
  `SCHEMA` 3, `CompanyRecord` 4, `entity_type` `contact` / `company`). Se
  cambia la forma, alzare `SCHEMA` della classe che cambia.
- Export: le Resource su `contacts` e `companies` usano `ContactExport::FORMATS` /
  `columns()` con `downloadAsText()`; niente colonne duplicate per Resource.
- Le Resource sono fallback di tabella: gestionale e sito hanno la precedenza e
  il menu non deve duplicare la voce.
- Migrazioni solo in `database.migrations`, idempotenti, con
  `LegacyTableRenames`; niente drop né merge. Una colonna tolta dallo schema
  la cancella `runTables()`, che gira dopo le migrazioni: i dati si copiano
  prima, in una migrazione (`RelationDepartmentsMigration`). Eccezione: `CompanySplitMigration`
  sposta le aziende da `contacts` a `companies` (soft delete della scheda
  vecchia) e scrive `contact_company_map`, che i moduli dipendenti leggono per
  rimappare i loro riferimenti. Gira prima di `runTables()`; deve restare
  idempotente.
- Nessun alias dei vecchi namespace del core.
- Collegamento della scheda: `ContactAccount::link()` (riscrive l'identità) o
  `linkKeepingIdentity()` (la tiene). Nella registrazione lo fa
  `HasSignupFields::afterUserSaved()`; i profili che usano il trait chiamano
  `parent::` e personalizzano con `linksContactOn()` / `afterContactLinked()`.
- Test: `WI_APP=<app> php tests/run.php`. Chi passa dai Model richiede prima
  `tests/support/FakeDb.php` (database in memoria). Vedi `README.md`.
