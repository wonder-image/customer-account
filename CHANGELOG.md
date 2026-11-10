# Changelog

## Unreleased

### Changed

- Brand: i colori hanno un nome. `colors` si salva come lista di `{color, name}`; `BrandData::palette()` li rilegge e accetta anche la lista di soli esadecimali di prima (nome vuoto).
- Brand: l'etichetta dei recapiti del brand è una tendina con le etichette di `ContactPoints::labels()`; un'etichetta libera salvata prima diventa vuota.
- Form del brand: la testata è come quella del marchio del catalogo, logo a sinistra e accanto nome, brand principale, sito e «Archiviato».
- Referenti: con la tendina della persona vuota, la riga mostra «Nome» e «Cognome» (`new_name`, `new_surname`) e crea la persona al salvataggio; `ContactRelations::createPerson()` usa l'email di lavoro della riga quando la persona non ne ha una sua.
- Niente trattini come testo visibile: le opzioni vuote delle tendine dicono «Nessuno» / «Nessuna» (`contacts.option_none`, `option_none_f`), i valori mancanti del pannello «Non indicato» (`account.overview.not_set`).
- Richiede wonder-image/app `^2.4.0-beta.6`: la ricerca dell'indirizzo per nome dell'attività usa `AddressExtension::withNameSearch()`.
- Richiede wonder-image/app `^2.4.0-beta.7` per `repeaterMinRows()` (almeno un contatto nel riquadro «Contatti»).

### Breaking

- `HasContactPoints::contactFormScript()` restituisce un `RichText` invece di una stringa: tra i componenti di una card la stringa veniva scartata senza dire niente e lo script non arrivava nella pagina. Chi lo concatena a un HTML usa `ContactFormScript::html()`.
- Riquadro «Contatti»: richiede `wonder-image/app` `2.4.0-beta.7` (`repeaterMinRows()`) e la lib `2.1.2-alpha.41`. Le colonne per tipo le nasconde il framework (`visibleWhen('kind', …)`), non più lo script del modulo; etichetta e «Si occupa di» passano nelle informazioni avanzate della riga.
- Le aziende non sono più schede di `contacts`: stanno in `companies` / `company_addresses` (`Company`, `CompanyAddress`, `CompanyResource` su `/backend/companies/`, `CompanyAddressResource`). `contacts` perde `type`, `business_name`, `pi`, `sdi`, `pec` e `is_supplier`; i fornitori sono sempre aziende.
- `contact_relations.company_id` punta a `companies`. Ogni scheda ha un solo repeater: «Referenti» (`contact_people`) sulle aziende, «Aziende» (`contact_companies`) sulle persone.
- `ContactRecord` passa a `SCHEMA` 2 con `entity` e `code`; gli id esterni usano `entity_type = contact` (prima `contacts`) e `company`.
- Brand: staccare un brand da un'azienda non cancella più la riga di `company_brand_links`, la chiude (`ended_at`); ricollegarlo la riapre. `CompanyBrands::of()`, `idsOf()`, `companiesOf()` e `belongsTo()` senza data guardano solo i legami aperti.
- Il campo `brands` di `HasCompanyBrands` è un repeater (brand, ruolo, predefinito) e non più una select multipla: il valore del form sono righe `brand_id` / `role` / `is_default`. `brandFields()`, `withBrandValues()`, `brandResource()`, `$brandsInput` e `$brandsMarker` restano; un POST con una lista di id funziona ancora.
- `CompanyRecord` passa a `SCHEMA` 3: ogni brand porta `id`, `code`, `slug`, `name`, `role`, `is_default`, `parent_id`, `parent_code`; l'azienda porta `parent_id` e `parent_code`.
- `CompanyBrands::byName()` e `findOrCreate()` non considerano i brand archiviati.
- Il reparto singolo del referente diventa «Si occupa di», più aree per legame: `contact_relations.department` esce dallo schema (la toglie `runTables()` dopo `Migrations\RelationDepartmentsMigration`, che la copia in `contact_relation_departments`). Nessuna area = si occupa di tutto. `ContactRelations::normalize()` non porta più `department`; `peopleByDepartment()` comprende chi si occupa di tutto; la colonna del repeater è `departments` (select multipla, `departmentsColumn()` al posto di `departmentColumn()`); le chiavi `contacts.department` / `department_none` diventano `contacts.departments`. In lettura le righe di `peopleOf()` / `companiesOf()` e i record portano ancora `department` (la prima area, o vuoto) accanto a `departments`; `sync()` accetta ancora `department` (un valore).
- `ContactRelations::sync()` scrive legami, brand seguiti e aree in una transazione; `contact.linked` / `contact.unlinked` partono dopo il commit (`contact.created` per le persone create al volo resta prima).
- `ContactRecord` passa a `SCHEMA` 3 (`emails`, `phones`, `departments` nei legami), `CompanyRecord` a `SCHEMA` 4 (in più i dati dei brand).
- Riquadro «Contatti» al posto dei campi `email`, `phone_prefix`, `phone` e di «Altri contatti» nei form di `ContactResource` e `CompanyResource` (input `contact_points` con colonne `kind`, `email`, `phone_prefix`, `phone`, `label`, `is_primary`, per le aziende `departments`; marker `contact_box_form`). Il principale resta nelle colonne della scheda; senza marker i campi singoli si salvano come prima.
- `ContactRecord` passa a `SCHEMA` 4, `CompanyRecord` a 5: `emails` / `phones` portano tutti i contatti, il principale per primo con `is_primary` (prima solo quelli in più), le aziende anche `departments`; i legami `work_email`, `work_phone_prefix`, `work_phone`; la scheda `place_id`, `lat`, `lng`.
- `ContactRelations::normalize()` porta anche `email`, `phone_prefix`, `phone` (i contatti di lavoro); le righe di `peopleOf()` / `peopleFor()` / `companiesOf()` portano in più le colonne del legame `email` / `phone_prefix` / `phone` (di lavoro: la persona resta sotto `contact`), `work_*` e `contact_email`.
- I messaggi dei contatti non validi iniziano con «Contatti:» e non più «Altri contatti:».

### Fixed

- Riquadro «Contatti»: cambiando il tipo restavano visibili sia l'email sia il telefono; etichetta e «Si occupa di» non si ripetono più su ogni riga a vista. Il prefisso vuoto parte da quello del paese del sito (con `wonder-image/app` aggiornato), e una riga mai toccata non conta come contatto.
- Form del brand: la colonna dei domini email non ripete il titolo della card («Dominio» come etichetta) e nei social il bottone per eliminare la riga resta sulla stessa linea dell'indirizzo.
- Le label doppie (card «Contatti» e repeater «Contatti») tolte dal form del brand.
- Il «+» dei legami accetta il layout della finestra (quinto elemento di `relationQuickCreate()`): senza, una finestra con un campo che non è nel form del target (l'email, ora nel riquadro «Contatti») faceva andare in errore la scheda («Input resource non trovato»).
- Email di accesso: `ContactResource::mutateRequestValues()` scarta l'`email` postata per una persona con account anche senza riquadro (`HasContactPoints::withoutAccessEmail()`, ereditato dalle sottoclassi); `ContactAccount::link()`, cambio email del pannello e registrazione non ne sono toccati (test).
- Valori storici: un'email o un telefono principali che le regole di oggi non riconoscono si mostrano (il telefono prova a separare un prefisso noto) e, riportati com'erano o non postati, restano; prima aprire e salvare la scheda li svuotava. `split()` porta `kept`.
- Aree dei contatti delle aziende: si tolgono quelle di valori che non sono più contatti a ogni scrittura del modulo, un contatto nuovo non eredita quelle rimaste (`ContactPoints::dropOrphanDepartments()` per chi scrive da fuori); una casella con sole aree non più note non si occupa più «di tutto» in `companyEmailsFor()`, e il form non le toglie.
- Legami con una controparte non visibile (filtro delle controparti): in sola lettura in `sync()`, contatti di lavoro compresi.
- Script del riquadro: i campi dell'altro tipo nascosti si disabilitano (niente validazione del browser bloccante); un'email storica non accettata dal browser e lasciata com'è non blocca l'invio.

### Added

- **Almeno un contatto**: una scheda che ne aveva già uno non resta senza (`repeaterMinRows(1)` a schermo, `contacts.point_required` sul server). Non vale in creazione né quando l'unico contatto è l'email di accesso.
- **Brand: domini email e recapiti multipli.** Colonne `domains` e `contacts` (JSON) in `company_brands`; `BrandData::domains()`, `contacts()`, `domainsOf()`, `contactsOf()`, `MAX_DOMAINS`, `MAX_CONTACTS`. Le colonne singole `domain`, `email`, `phone_prefix`, `phone` tengono il primo valore delle liste; i brand di prima si leggono come prima. `CompanyBrands::byDomain()` guarda tutti i domini. Nei record del brand (`CompanyRecord`) `domains` e `contacts` in più, come liste: `SCHEMA` invariato, le chiavi di prima restano.
- **Brand: settore e lingua da tendina.** Settore con le 21 sezioni ATECO 2025 / NACE Rev. 2.1 (`BrandData::SECTORS`, `sectors()`, `sector()`): un testo libero salvato prima resta nel database ma il form lo mostra vuoto finché non si sceglie una sezione. Lingua ISO 639-1 (`BrandData::LANGUAGES`, `languages()`, `addLanguage()`), default `it` (`DEFAULT_LANGUAGE`).
- **Brand: colori con l'input HEX** del framework (repeater di `color()`) invece del testo separato da virgole; nel form `colors` sono righe `['color' => '#rrggbb']`.
- Gli input del form del brand usano quelli specifici del framework: `url()`, `email()`, `tel()`, `phonePrefix()`, `color()`, `selectSearch()`.

- Riquadro «Contatti» con «Principale» (persone e aziende): `HasContactPoints::contactBoxFields()`, `applyContactPoints()`, `usesContactBox()`, `contactFormScript()`; `ContactPoints::all()`, `formRows()`, `split()`, `syncAll()`, `lockedEmail()`, `cleanEmail()`, `ACCESS_LABEL`. Un principale per tipo (regola lato server), la prima riga se nessuna lo è, scambio senza perdere la vecchia principale. Una persona con account tiene `contacts.email` («Email di accesso», riga bloccata): il riquadro non la cambia e non promuove un'altra email.
- «Si occupa di» sui contatti delle aziende: tabella `company_point_departments` (`Models\CompanyPointDepartment`, per valore, così l'area segue l'email quando cambia il principale), `ContactPoints::companyEmailsFor($companyId, $department = '')` per i destinatari a livello di azienda.
- Contatti di lavoro del referente sul legame: `contact_relations.email`, `phone_prefix`, `phone`, colonne nei repeater dei legami (`HasContactRelations::workContactColumns()`), `ContactRelations::workContacts()` (formato senza DNS, valori non validi o non scalari vuoti, riga senza chiave tiene quelli di prima), `HasContactRelations::assertRelationContacts()` (DNS nel form), `contact_email` nelle letture. Mai email di accesso.
- Indirizzi: ricerca anche per nome dell'attività (`withNameSearch()`) in fatturazione e consegna; place ID e coordinate anche sulla fatturazione di persone e aziende (`withGeo()`: colonne `place_id`, `lat`, `lng`), salvati dal backend, da `AccountBilling` e dalla registrazione solo se postati (`AccountBilling::geo()`); «Presso» (`business_name`) negli indirizzi di consegna di persone e aziende. Il nome dell'attività scelta riempie «Presso» (o la ragione sociale vuota di un'azienda).
- Script dei form del modulo (`resources/assets/js/contact-form.js`, `Support\ContactFormScript`): colonne del riquadro per tipo, un principale per tipo a schermo, riga dell'email di accesso bloccata, nome dell'attività scelta. Supplisce a due mancanze di core e lib descritte nel README.

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
- Reparto dei referenti: lista `ContactRelation::DEPARTMENTS`, estendibile con `ContactRelations::addDepartment()`, `departments()`, `departmentLabel()`, `peopleByDepartment()` per gli invii per reparto (oggi più aree per referente: vedi sopra).
- `CompanySplitMigration`: al `forge update` sposta le aziende senza account in `companies` (scheda vecchia nel cestino), lega a una nuova azienda con accesso `admin` le aziende con account, crea un'azienda fornitore per i privati fornitori, lascia come contatti le aziende senza ragione sociale. Scrive `contact_company_map` per i moduli dipendenti. L'azienda tiene il codice del contatto con prefisso `cmp_` (`CompanySplitMigration::codeFromContact()`). Idempotente.
- Comando `php forge customer-account:companies`: cosa farà la migrazione, senza scrivere.
- `contact_relations.access` (`none`, `buyer`, `admin`), colonna «Accesso» nei repeater, `ContactRelations::accessOf()` e filtro `companiesOf($personId, $access)`.
- `CompanyRecord` e la base comune `PartyRecord`; eventi `company.created` / `company.updated` (`CompanyEvents`).
- `ContactExport::PERSON_FIELDS` e `COMPANY_FIELDS`; `columns()` accetta l'elenco dei campi.
- `AccountBilling::saveCompany()`: salva la fatturazione di un'azienda con la stessa validazione della scheda; il permesso lo controlla chi chiama (l'ecommerce lo fa solo per gli `admin`).
- Traduzioni delle aziende e degli accessi (`contacts.json`, it/en).
- Aree dei referenti («Si occupa di»): tabella `contact_relation_departments` (`Models\ContactRelationDepartment`), `ContactRelations::departmentsOf()`, `departmentsByRelation()` (una query per più legami), `setDepartments()`, `withDepartments()`, `cleanDepartments()` e `peopleFor($companyId, $department, $brandId)`: chi si occupa di quell'area o di tutte e segue quel brand o tutti, il principale per primo; è il punto da cui i moduli scelgono a chi mandare un documento. `Migrations\RelationDepartmentsMigration` copia il vecchio reparto (idempotente).
- Altri contatti: email e telefoni in più di persone (`contact_points`, `ContactPoint`) e aziende (`company_points`, `CompanyPoint`), con etichetta (`work`, `personal`, `billing`, `other`, estendibili con `ContactPoints::addLabel()`). `Support\ContactPoints` (`of()`, `byOwners()`, `clean()`, `sync()`) è l'unico punto di lettura e scrittura; repeater «Altri contatti» su `ContactResource` e `CompanyResource` (trait `HasContactPoints`, portato da `HasContactRelations`). Email e telefono principali restano le colonne della scheda; i recapiti in più non collegano mai un account (`ContactAccount` cerca solo `contacts.email`).
- Dati dei brand: `company_brands.logo` (immagine png/jpg/webp/svg, 2 MB, ripulita dal core), `website`, `domain` (ricavato dal sito, indice), `description` e il brand kit (`email`, `phone_prefix`, `phone`, `socials`, `colors`, `tone`, `sector`, `language`, `assets_url`), con le regole di `Support\BrandData` (URL solo http/https, colori esadecimali, social da una lista estendibile con `addSocial()`). Il form del brand ha due gruppi; il kit si vede e si salva solo con `CompanyBrands::useKit()` (spento di default). `CompanyBrands::byDomain($emailOrDomain)` riconosce il brand da un'email (mai da una casella generica: `BrandData::MAIL_PROVIDERS`, `addMailProvider()`). `CompanyBrand::logoPath()` / `logoUrl()`.
- `ContactRelations::attach($companyId, $personId, $values = [])`: crea o riapre un solo legame senza ripassare gli altri (per la creazione rapida). `useCounterpartFilter()` (con `allowedCounterparts()` e `linkedIds()`) per la visibilità per riga delle controparti, in scrittura (`existingCounterparts()` prende `$parentId`) e nelle scelte del repeater; `useAccessGuard()` per chi può alzare `access`. Di serie nessun filtro.
- `ContactPoints::emailFormat()` (formato senza rete) e il terzo argomento `clean(..., $checkDomain)`; `BrandData::isSiteHost()` / `addSiteHost()` / `SITE_HOSTS` e `MAIL_PROVIDER_NAMES`.
- Indirizzi per brand: tabella `company_address_brands` (`Models\CompanyAddressBrand`), `Support\CompanyAddresses` (`addressesFor($companyId, $brandId)`, `brandsOf()`, `brandsByAddress()`, `setBrands()`, `withBrandIds()`); nessuna riga = l'indirizzo vale per tutti i brand. Campo «Brand» in `CompanyAddressResource`. Staccare un brand dall'azienda (`CompanyBrands::sync()`) o unirlo (`merge()`) toglie o sposta le righe.

### Fixed

- `RelationDepartmentsMigration`: una lettura fallita non vale più «niente da copiare» (l'aggiornamento si ferma prima che `runTables()` tolga `department`); a fine copia ricontrolla che ogni reparto abbia la sua area; un reparto fuori formato si normalizza o diventa `other` invece di sparire.
- `ContactPoints::sync()` e `BrandData` controllano solo il formato delle email, senza DNS: un DNS che non risponde non cancella più recapiti salvati (il DNS resta nella validazione del form). Le email con spazi, virgolette, apici, backtick o `< >` si rifiutano.
- `ContactRelations::normalize()` dal lato persona: ogni azienda tiene il suo «principale» (prima solo la prima riga lo teneva).
- Il dominio del brand non si ricava più da un sito su un social o un servizio di siti (`facebook.com/lapiana`); più caselle generiche (yahoo.*, hotmail.*, gmx.*, PEC dei gestori, …) e i loro sottodomini; `byDomain()` documentato come suggerimento.
- Valori non scalari dalla richiesta (`x[]=1`) valgono vuoto in legami, recapiti e brand, invece di diventare `Array` o l'id 1.

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
