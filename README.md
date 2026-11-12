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
| Migrazione `RelationDepartmentsMigration` | `database.migrations` | Copia il vecchio reparto singolo dei referenti nelle aree (`contact_relation_departments`) prima che `runTables()` tolga la colonna. Idempotente. |
| Migrazione `PointOfficeTypesMigration` | `database.migrations` | La vecchia etichetta «Fatturazione» dei recapiti (`label = billing`) diventa il tipo ufficio «Amministrazione», per aziende e persone, e l'etichetta si svuota: crea da sé le tabelle dei tipi, non tocca un recapito che ha già un tipo, rilanciata non fa niente. |
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

Un'azienda può avere più referenti, e una persona può stare in più aziende. Il legame è la tabella `contact_relations` (`Models\ContactRelation`): `company_id` (→ `companies`), `person_id` (→ `contacts`), `job_title`, `is_primary`, `receives_documents`, `access`, `position`, `deleted`. Le aree di cui si occupa il referente stanno in `contact_relation_departments` (vedi «Di cosa si occupa un referente»). Ogni coppia azienda/persona compare una volta sola, anche fra le righe cancellate: chi la ricollega riprende la riga di prima.

`access` dice cosa può fare la persona per l'azienda, per esempio dall'account sul negozio:

| Valore | Significato |
|---|---|
| `none` | solo referente, nessun accesso (default) |
| `buyer` | può comprare a nome dell'azienda |
| `admin` | compra e gestisce i dati dell'azienda |

I referenti sono sempre attivi: non c'è un interruttore. `ContactRelations::enabled()` vale sempre `true` (i test lo spengono con `useEnabled(false)`).

- Ogni scheda ha un solo repeater dei legami: «Referenti» su `CompanyResource` (input `contact_people`), «Aziende» su `ContactResource` (input `contact_companies`). Le aziende hanno in più il repeater dei brand (vedi «Brand delle aziende»). Il lato lo dice `relationSide()` del trait. Una riga dei referenti sceglie una persona esistente dalla tendina e ha, su una riga, controparte, «Ruolo» e «Ufficio»; sotto, i contatti aziendali. Il form non crea più persone dalla riga: `new_name`, `new_surname` e `new_email` postati si scartano, una riga senza controparte ma compilata ferma il salvataggio (`contacts.relation_person_required` / `relation_company_required`), una del tutto vuota si scarta. «Principale», «Riceve i documenti» e «Accesso» non sono più colonne del form (vedi «CRM dei recapiti», flag del referente). Nella tendina delle aziende (lato persona) si vede solo la ragione sociale. La persona nuova la crea il modulo che usa il repeater (il gestionale la crea attiva e non cliente e la seleziona). Il modulo dichiara il «+» con `relationQuickCreate()`: `[Resource, campi, campo etichetta, testo del bottone, layout]`; il layout (una `Closure`) serve quando un campo della finestra non è nel form del target, come l'email, che sta nel riquadro «Contatti». `ContactRelations::createPerson()` resta per chi crea persone da codice.
- Al massimo un referente principale per azienda: un nuovo principale, anche dal lato della persona, toglie il titolo agli altri. La regola vale per chi posta `is_primary`: i form non lo chiedono più, una riga senza tiene il valore salvato e un legame nuovo nasce principale. Dal lato della persona ogni riga è un'azienda diversa e tiene il suo «principale».
- I repeater si salvano solo se il form li ha mostrati (campo nascosto `contact_relations_form`): un salvataggio da API o da un form senza repeater non tocca i legami. Una riga senza `access` tiene quello che aveva.
- **Contatti di lavoro**: un referente può avere email e telefono di lavoro diversi per ogni azienda, sul legame (`contact_relations.email`, `phone_prefix`, `phone`, facoltativi; nei due repeater le colonne «Email aziendale», «Prefisso», «Telefono aziendale», o con il CRM acceso il repeater interno «Contatti aziendali», `points`, vedi «CRM dei recapiti»). Li pulisce `ContactRelations::workContacts()` come i contatti della scheda: email in minuscolo con il solo controllo di formato, telefono a cifre, prefisso `+NN`; un valore non valido o non scalare vale vuoto, una riga senza la chiave tiene quello di prima. Il dominio lo controlla il form (`HasContactRelations::assertRelationContacts($_POST)` in `mutateRequestValues()`). Le righe di `peopleOf()` / `peopleFor()` / `companiesOf()` portano `work_email`, `work_phone_prefix`, `work_phone` e `contact_email`: l'email di lavoro se c'è, altrimenti quella della persona. È quella che i moduli usano per scrivere a quel referente per quell'azienda. L'email di lavoro non è mai un'email di accesso e non serve a collegare un account.
- `Support\ContactRelations::peopleOf($companyId)` dà le righe vive con la persona sotto `contact`; `companiesOf($personId, $access = null)` con l'azienda sotto `company`, filtrabili per accesso (`['buyer', 'admin']`). `accessOf($personId, $companyId)` dà l'accesso, `none` se non sono legate.
- Una Resource sulla tabella delle persone o delle aziende prende il repeater con il trait `Resources\Concerns\HasContactRelations` (`...static::contactRelationFields()` nel `formSchema()`). Il gancio `relationPersonPayload(array $values)` ritocca i valori della persona creata al volo.
- Brand seguiti: un referente può seguire solo alcuni brand dell'azienda (tabella `contact_relation_brands`, colonna «Brand seguiti» del repeater dei referenti, input `brand_ids`); nessuno = tutti. La colonna si vede solo se l'azienda ha più di un brand (campo nascosto `relation_brands_many`, `RELATION_BRANDS_MANY`): altrimenti i brand postati non contano e restano quelli salvati. `ContactRelations::brandsOf($relationId)`, `setBrands($relationId, $brandIds)` (tiene solo i brand legati oggi all'azienda del legame), `peopleOf($companyId, $brandId)` (chi segue quel brand o tutti). Contano solo i brand ancora legati all'azienda: se non ne resta nessuno il referente li segue tutti, e staccare un brand dall'azienda toglie le righe di chi lo seguiva. Una riga senza `brand_ids` tiene quelli di prima; il select svuotato posta `['']` e svuota il filtro. Le scelte della colonna sono tutti i brand: il form non sa quale azienda mostra, il salvataggio scarta gli altri.
- `Support\ContactRelations::sync($parentColumn, $parentId, $rows)` è l'unico punto che scrive i legami: i repeater, un'API, un import o una sync ci passano. Le righe assenti si cancellano (soft delete). Per un legame solo c'è `attach($companyId, $personId, $values = [])`: crea o riapre quella coppia (accesso `none` se non indicato) senza ripassare gli altri legami; un legame già vivo non cambia.
- Visibilità per riga (un modulo dove ognuno vede solo le sue schede): `ContactRelations::useCounterpartFilter(fn (string $modelClass, array $ids): array => …)` dà gli id ammessi. In scrittura una controparte non ammessa e non già legata si scarta (una già legata resta, così chi non la vede non la stacca salvando); il select del repeater mostra le ammesse più quelle già legate. `useAccessGuard(fn (int $companyId, int $personId, string $access): bool => …)`: se dice no, `access` non sale oltre quello salvato (`none` per un legame nuovo o ripreso). Di serie nessun filtro.
- Con il filtro delle controparti, una riga il cui altro lato chi scrive non vede (già legata, quindi resta) è in sola lettura: ruolo, principale, documenti, accesso, contatti di lavoro, aree e brand seguiti restano quelli salvati, qualunque cosa arrivi dal POST. Così chi vede la persona non dirotta i documenti di un'azienda che non vede cambiandone `contact_email`. Dal lato azienda un principale non visibile tiene il titolo.
- Un valore non scalare dalla richiesta (`x[]=1`) vale vuoto.

## Ufficio

«Ufficio» (prima «Tipo ufficio») è lo stesso elenco ovunque: sui referenti, sui recapiti di aziende e persone e sui recapiti dei brand. Nei form è un select singolo, con «Generale» per primo (vedi «CRM dei recapiti»). Ai tipi di serie si aggiungono quelli creati a mano dal «+» accanto al campo (creazione rapida del framework su `Resources\OfficeTypeResource`, tabella `contact_office_types`, `Models\OfficeType`): `Support\OfficeTypes` li legge (`options()`, id => nome) e li crea (`findOrCreate()`: un nome già creato si riusa, uno di serie si rifiuta). `ContactRelations::departments()` li unisce a quelli di serie con l'id per chiave, e sui recapiti e sui referenti quell'id si salva come testo nella colonna `department`. Non si eliminano: si rinominano dal loro elenco (`/backend/office-types/`, senza voce di menu). Un modulo con altri ruoli estende la Resource e la indica con `officeTypeResource()`.

«Ufficio» dice di quali aree si occupa il referente, per scegliere a chi mandare email, fatture o newsletter. I dati reggono più aree per referente (il form ne sceglie una; «Generale» vale per tutte): tabella `contact_relation_departments` (`Models\ContactRelationDepartment`: `relation_id`, `department`, coppia unica). **Nessuna area = si occupa di tutto.** Il «Ruolo» (`job_title`) resta testo libero; `receives_documents`, `is_primary` e `access` restano sul legame: l'accesso non dipende mai dall'area.

Le aree sono una lista estendibile (`ContactRelation::DEPARTMENTS`: `general`, `administration`, `purchasing`, `sales`, `technical`, `management`, `other`), etichette in `contacts.department_*`.

- `ContactRelations::departments()` dà le scelte per un select; `addDepartment($key, $label)` ne aggiunge una (un modulo al boot, un sito in `custom/function`). `department($value)` e `cleanDepartments($values)` tengono solo le aree note; `departmentLabel($value)` dà l'etichetta.
- `departmentsOf($relationId)` dà le aree di un legame, `departmentsByRelation($ids)` quelle di più legami in una query; `setDepartments($relationId, $departments)` le scrive. Nei repeater dei legami la colonna «Ufficio» (`department`, select singolo con il «+»); `sync()` scrive le aree per le righe che portano `department` o `departments` (una riga senza nessuna delle due chiavi tiene quelle di prima; `departments` con più valori vale ancora per chi scrive da codice, e `['']` le toglie).
- **`ContactRelations::peopleFor($companyId, $department = '', $brandId = 0)`** è il punto da cui i moduli scelgono a chi mandare un documento: i referenti vivi che si occupano di quell'area o di tutte e seguono quel brand o tutti, con la persona sotto `contact`, il principale per primo, poi nell'ordine scelto. `receives_documents` è nella riga: lo filtra chi manda documenti. `peopleByDepartment($companyId, $aree)` resta per chi lo usa (con la stessa regola «vuoto = tutte»).
- Le righe di `peopleOf()` / `companiesOf()` portano `departments` (lista) e `department` (la prima area, o vuoto), come i legami dei record.
- `Migrations\RelationDepartmentsMigration` copia il vecchio reparto singolo (`contact_relations.department`) nella tabella nuova, prima che `runTables()` tolga la colonna: crea da sé la tabella, salta i legami che hanno già delle aree, rilanciata non fa niente. Un reparto fuori formato si normalizza (minuscole, spazi → `_`) o diventa `other`, mai «nessuna area» (che vorrebbe dire «tutto»). Una lettura o una scrittura fallita, o un legame che resta con un reparto e senza area (ricontrollato anche in SQL), ferma l'aggiornamento prima che la colonna sparisca.

## Contatti

Tutte le email e i telefoni di una scheda stanno in un riquadro solo, «Contatti» (persone e aziende): ogni riga ha tipo (`email`, `phone`, `mobile`, `whatsapp`), email oppure prefisso e numero e **«Ufficio»** (uno solo; una Resource lo toglie con `contactBoxOffice()`), in riga. Con il CRM spento il riquadro è un'email e un telefono soli (`contactBoxSimple()`, marker a `single`); per una persona l'email di accesso può stare in un campo a parte (`separateAccessEmail()`). Vedi «CRM dei recapiti». «Principale» e l'etichetta non si scelgono più: il principale è il primo dell'elenco, e l'etichetta resta solo come dato salvato (`access` segna l'email di accesso).

Il principale di ogni tipo resta nelle colonne della scheda (`email`, `phone_prefix`, `phone`): collegamento dell'account, ricerche, export e documenti continuano a leggere quelle, e i dati salvati non si spostano. Gli altri stanno in due tabelle gemelle, come gli indirizzi: `contact_points` (`Models\ContactPoint`, `contact_id`) e `company_points` (`Models\CompanyPoint`, `company_id`), con `kind`, `value`, `phone_prefix`, `label` e `position`. Le etichette sono una lista estendibile: `work`, `personal`, `billing`, `other` (`ContactPoints::addLabel()`); `access` è riservata.

- **Principale**: al massimo uno per tipo (email e telefono separati). Il form non lo chiede più: resta quello di prima, e se quello se ne va sale la prima riga. Per chi posta ancora `is_primary` (API, altri moduli) il primo segnato vince, gli altri tornano righe normali; se nessuno è segnato resta quello di prima, poi sale la prima riga nuova del tipo; un contatto in più già salvato (con la sua etichetta) sale solo se un principale c'era ed è stato tolto, così salvare una scheda senza toccarla non cambia né la scheda né le etichette; senza righe di un tipo le colonne si svuotano (il prefisso salvato senza telefono resta com'è). La regola sta in `ContactPoints::split()`, lato server; lo script del modulo spegne subito a schermo gli altri principali dello stesso tipo. Cambiare principale scambia i valori: la vecchia principale diventa una riga normale e non si perde (le aree la seguono). L'etichetta della riga principale non si salva: le colonne della scheda non ne hanno una.
- **Email di accesso**: una persona con un account collegato (`user_id`) tiene `contacts.email`, che è l'email su cui `ContactAccount` la riconosce. Nel riquadro è la prima riga, con l'etichetta «Email di accesso», bloccata (lo script la disabilita); il server comunque la ignora: le altre email non diventano principali, togliere o cambiare quella riga non cambia l'email della scheda (una email scritta lì diventa una riga in più). L'email di accesso si cambia dall'account (il cambio email del pannello la riporta sulla scheda). La regola vale anche senza riquadro: `ContactResource::mutateRequestValues()` (e quindi ogni Resource che la estende) scarta l'`email` postata per una persona con account (`HasContactPoints::withoutAccessEmail()`, da chiamare anche in un `mutateRequestValues()` scritto da capo, o sostituire con una regola propria: il gestionale rifiuta il cambio). `ContactAccount::link()`, il cambio email del pannello e la registrazione non passano dalle Resource. Le aziende non hanno account: nessun blocco.
- **Valori storici**: un'email o un telefono principali salvati prima delle regole di oggi (un telefono con lettere o troppo corto, un'email con uno spazio) non si perdono senza un gesto. `all()` li mostra (il telefono prova prima a separare un prefisso noto del framework: `+39 333…`, `0039333…`), e se il form li riposta com'erano, o non posta nessuna riga di quel tipo, `split()` lascia le colonne di quel tipo come sono (`kept`), senza errori; le altre righe del tipo restano normali. Si sostituiscono cambiandoli; la riga non si può togliere lasciandola vuota.
- **«Ufficio»** (aziende e persone): lo stesso elenco dei referenti (`ContactRelations::departments()`), vuoto o «Generale» = tutto. Una ditta piccola manda le fatture ad amministrazione@ senza creare un referente. Tabelle `company_point_departments` (`Models\CompanyPointDepartment`) e `contact_point_departments` (`Models\ContactPointDepartment`), riconosciuta per `kind` / `value` e non per id: l'area segue l'email quando cambia il principale. Le aree di un valore che non è più un contatto dell'azienda si tolgono a ogni scrittura del modulo (`sync()`, `syncAll()`, il salvataggio delle Resource senza riquadro, `AccountBilling::saveCompany()`), e un contatto nuovo non eredita quelle rimaste; chi cambia `companies.email` o `phone` da fuori chiama `ContactPoints::dropOrphanDepartments($companyId)`. Un'area tolta dalla lista resta salvata ma non si vede: una casella che ha solo aree sconosciute non si occupa «di tutto» (esce solo con l'area vuota), e il form non le toglie. **`ContactPoints::companyEmailsFor($companyId, $department = '')`** dà le email dell'azienda che si occupano di quell'area o di tutto, la principale per prima (area vuota = tutte): è il punto da cui i moduli scelgono i destinatari a livello di azienda, accanto a `ContactRelations::peopleFor()`.
- `Support\ContactPoints` è l'unico punto che li legge e li scrive: `all($owner, $id)` (tutte le righe: principali dalle colonne, poi le altre, con `is_primary`, `locked`, `departments`), `formRows()`, `split($owner, $rows, $card, $checkDomain)` (colonne, righe e problemi), `syncAll($owner, $id, $rows)` (in una transazione: colonne solo se cambiano, righe, aree), `lockedEmail()`, `companyEmailsFor()`; per chi mostra ancora i campi a parte `of()`, `byOwners()`, `clean()`, `sync()` (solo i contatti in più). Gli id postati non contano: un contatto si riconosce dal valore.
- Regole: l'email si salva in minuscolo e deve avere un formato valido (`ContactPoints::emailFormat()`: niente spazi, virgolette, apici, backtick né `< >`). Il form controlla anche il dominio con il validatore del framework (`EmailValidator`, che interroga il DNS); la scrittura no, così un DNS che non risponde non cancella contatti salvati. Il telefono tiene solo le cifre, il prefisso va nella sua colonna (`+39`, anche da `0039`); niente doppioni sulla scheda.
- **Nelle Resource**: trait `Resources\Concerns\HasContactPoints` (portato da `HasContactRelations`). Il riquadro è `...static::contactBoxFields()` (input `contact_points`, marker `contact_box_form`) al posto dei campi `email`, `phone_prefix`, `phone`; in `mutateRequestValues()` la Resource chiama `$values = static::applyContactPoints($values, $oldValues)`, che rimette il principale nei campi della scheda (si salva con lei e passa dagli stessi controlli, per esempio i doppioni del gestionale) e rifiuta righe non valide; le altre righe le scrive `syncRepeaterRelations()`. Nel layout va lo script, `static::contactFormScript()`: un `RichText` da mettere tra i componenti di una card (una stringa lì il layout la scarterebbe). `ContactResource` e `CompanyResource` lo fanno.
- **Scrittura**: la scheda la salva la Resource (con il principale già nelle colonne), poi `syncAll()` scrive le altre righe in una sua transazione. Le righe le ha già validate `applyContactPoints()` (con il DNS, più severo di `syncAll()`): lì possono fallire solo il database o un salvataggio concorrente della stessa azienda (indice unico delle aree), come qualunque scrittura del core dopo la scheda.
- Le colonne dell'altro tipo le nasconde il framework (`visibleWhen('kind', …)`, lib `2.1.2-alpha.41`): nascoste non si validano e non si postano; il server prende il valore dalla colonna del tipo scelto, quindi un campo assente non svuota niente. Il prefisso vuoto parte da quello del paese del sito (`Phone::$defaultCountryCode`), come la nazione negli indirizzi.
- **Almeno un contatto**: una scheda che ne ha già uno non resta senza. L'ultima riga non si elimina (`repeaterMinRows(1)` del framework spegne il bottone) e `applyContactPoints()` rifiuta un riquadro vuoto (`contacts.point_required`): l'ultimo contatto si cambia, non si toglie. In creazione il riquadro può restare vuoto; la regola non vale quando l'unico contatto è l'email di accesso, che il riquadro non tocca.
- **Retrocompatibilità**: senza `contact_box_form` un POST con i soli campi singoli (API, altri moduli, registrazione, `ContactAccount`) si salva come prima e i contatti in più non si toccano. Il vecchio repeater «Altri contatti» (`contactPointFields()`, marker `contact_points_form`, con `assertContactPoints()`) resta per chi non è ancora passato al riquadro.
- **Sicurezza**: un contatto non principale non serve mai per l'accesso né per collegare un account. `ContactAccount::link()` / `linkKeepingIdentity()` (e quindi `CustomerContact::forUser()` e la registrazione) cercano la scheda solo per `contacts.user_id` e `contacts.email`; il cambio email del pannello scrive solo `contacts.email`.
- **Pannello del cliente**: il profilo chiede solo il telefono e l'email di accesso e li scrive nelle colonne della scheda (`ContactAccount::link()`, `AccountBilling::save()` per il telefono della fatturazione, `afterEmailConfirmed()`), senza `syncAll()`. Dopo la scrittura chiama `ContactPoints::primaryWritten($owner, $id)`: un contatto in più uguale al nuovo principale non resta due volte (il telefono principale ne prende il tipo), gli uffici del valore tolto se ne vanno, gli altri recapiti in più e i social restano. Chi scrive `email` o `phone` di una scheda da fuori può chiamarlo allo stesso modo. Un telefono che nasce dal sito (scheda nuova, o scheda senza telefono) prende il tipo `mobile` in `phone_kind`, perché il campo si chiama «Cellulare»; un telefono già salvato tiene il suo tipo. Se l'utente non ha un telefono ma la scheda sì (ordine da ospite), Panoramica e «Dati personali» mostrano quello della scheda. Gli indirizzi del pannello non chiedono «Presso»: `AccountAddresses::save()` scrive solo i campi di `ContactAddress::address()` e il valore salvato resta.
- I record portano tutti i contatti in `emails` (`value`, `label`, `is_primary`) e `phones` (`phone_prefix`, `value`, `label`, `is_primary`), il principale per primo; per le aziende anche `departments`. L'export no: le sue colonne sono i testi della scheda, una riga per scheda.

## CRM dei recapiti

Nei form il campo si chiama «Ufficio» (prima «Tipo ufficio») e si sceglie un valore solo; le tabelle restano a più valori. Le fondamenta:

- **Tipi di recapito**: `email`, `phone` («Telefono fisso»), `mobile` («Cellulare»), `whatsapp` («WhatsApp»). I tre telefoni sono una famiglia sola: stessa pulizia, un solo telefono principale (di un tipo qualsiasi, in `phone_kind` sulla scheda). `ContactPoints::kinds()`, `kindOptions()` (chiave => etichetta), `kindLabel()`, `isPhone()`, `family()`.
- **«Generale»** (`ContactRelations::GENERAL`, il primo dell'elenco) vale come nessun ufficio: risponde per tutte le aree in `peopleFor()`, `peopleByDepartment()`, `ContactPoints::companyEmailsFor()`. La regola unica è `ContactRelations::coversDepartment($stored, $wanted)`.
- **Ufficio singolo**: una riga può portare `department` (un valore) al posto di `departments`. `ContactRelations::singleDepartment($posted, $stored)` decide cosa salvare: lo stesso valore mostrato (`firstDepartment($stored)`) tiene tutti gli uffici salvati, uno diverso o vuoto li sostituisce. Vale per i referenti (`sync()`) e per i recapiti (`ContactPoints::syncAll()`).
- **Recapiti del referente per azienda**: tabella `contact_relation_points` (`Models\ContactRelationPoint`: `relation_id`, `kind`, `value`, `phone_prefix`, `label`, `position`). Una riga di `sync()` / `normalize()` può portare `points`: una lista di righe `{kind, email}` oppure `{kind, phone_prefix, phone}` (come le posta il ripetitore interno). La prima email e il primo telefono vanno a specchio in `contact_relations.email` / `phone_prefix` / `phone`, così chi legge le colonne continua a funzionare. `points` vuoto (`[]`) toglie tutto; senza la chiave valgono i campi singoli di prima e i recapiti in più restano. `peopleOf()` / `companiesOf()` portano `points`; `pointsOf($relationId)`, `pointsByRelation($relations)`, `withPoints($rows)` (forma del ripetitore). Un legame di prima senza righe si legge dalle sue colonne: niente viene ricopiato dalla migrazione.
- **Flag del referente**: una riga senza `is_primary`, `receives_documents` o `access` tiene quelli salvati; un legame nuovo nasce `is_primary` e `receives_documents` a `'true'` e `access` `buyer` (se la guardia dell'accesso lo permette, altrimenti `none`). La regola «un solo principale» vale solo per le righe che postano `is_primary`. `peopleFor()` dà i referenti nell'ordine della scheda.
- **CRM acceso o spento**: `Support\Crm::enable()` / `enabled()` (spento di base; lo accende un modulo). Spento, i form mostrano un'email e un telefono soli e salvano con `ContactPoints::syncAll(..., ['primary_only' => true])`, `ContactPoints::sync(..., true)` e `BrandData::clean($values, $kit, $stored, true)`: i recapiti e i social in più restano salvati.
- **Brand e CRM**: `BrandData::CRM_FIELDS` (`contacts`, `socials`) sono i campi del brand che seguono il CRM anche senza kit. La firma è `BrandData::clean($values, $kit, $stored = [], $primaryOnly = false, $crm = false)`: con `$crm` e senza kit passano solo quelli, nel gruppo «Recapiti e social» del form; con il kit e senza CRM resta un'email e un telefono (`CompanyBrandResource::primaryContacts()`). Sui recapiti del brand l'ufficio sta in `label` (`BrandData::officeType()`), non in `department`. Una Resource che estende `CompanyBrandResource` ha i ganci `officeTypeResource()`, `extraFormFields()` ed `extraFormCards()`.
- **Social**: `Support\Socials` (reti, pulizia, colonna JSON) vale per i brand e per le persone (`contacts.socials`): `Socials::column($posted)`, `of($row)`, `formRows($row)`, `networks()`, `add()`. Nelle Resource il trait `Resources\Concerns\HasSocials`: `socialFields()` nel form (input `socials`, marker `socials_form`), `withSocialValues()` in `mutateFormValues()`, `applySocials()` in `mutateRequestValues()`; la colonna si scrive solo se il form ha mostrato il campo.
- **Email di accesso a parte**: `ContactPoints::formRowsWithoutAccess($owner, $id)` dà le righe del riquadro senza l'email della scheda; `split()` / `syncAll()` con `separate_email` (true = quella salvata, una stringa = quella postata) non la scrivono dal riquadro.
- `Migrations\CrmContactPointsMigration` (prima di `runTables()`) allinea il tipo dei recapiti (`mobile`, `whatsapp`) sulle tabelle già create e crea `contact_relation_points`: idempotente, non tocca le righe. Le colonne nuove (`phone_kind`, `contacts.socials`) le aggiunge `runTables()` dai Model.

## Indirizzi: ricerca per nome, «Presso», coordinate

Fatturazione (persone e aziende) e indirizzi di consegna (`contact_addresses`, `company_addresses`) usano `AddressExtension::withGeo()` e `withNameSearch()` del core: nella via si cercano anche le attività per nome (un negozio, un cantiere, un ufficio), e si salvano `place_id`, `lat`, `lng` (colonne nuove sulla fatturazione; la consegna le aveva già).

- **«Presso»** degli indirizzi di consegna è `business_name` (l'attività o la società a quell'indirizzo; stesso nome di `shipping_business_name` degli ordini del gestionale). Scegliendo un'attività da Google si riempie da solo con il suo nome, se è vuoto, e resta modificabile. Nella fatturazione di un'azienda la stessa scelta riempie la ragione sociale vuota; in quella di una persona niente (un'attività non è una persona). La fatturazione non ha un «presso»: la fattura elettronica non lo trasmette.
- Place ID e coordinate arrivano dal browser: si ripuliscono con `GoogleMaps::cleanGeo()` (numeri nei limiti, in coppia; place ID breve, solo lettere, cifre, `_` e `-`), e solo se il form li ha postati, così un salvataggio senza non li azzera. Servono per mappe e distanze, mai per decidere qualcosa.
- Area account: la ricerca per nome e le coordinate valgono anche lì (fatturazione con `AccountBilling`, indirizzi con `AccountAddresses`, registrazione); il campo «Presso» no.
- I record portano `place_id`, `lat`, `lng` della fatturazione.

### Cosa manca nel framework

Lo script del modulo fa due cose che spetterebbero a core e lib; quando ci saranno, si toglie.

1. **Colonne di repeater che compaiono secondo un select della stessa riga.** `visibleWhen()` (`Input::conditionalVisibility()` in `wonder-image/app`) e `conditional.js` della lib cercano il campo che decide per `name` in tutto il form: nella riga di un repeater `nested()` il nome è `contact_points[<riga>][kind]`, che la regola non può conoscere. Modifica minima: in `lib/src/build/global/js/form/conditional.js`, `wiConditionalControls(field, name)` deve cercare prima, se `field` sta in una `.wi-repeater-row`, i controlli della stessa riga il cui nome finisce con `[<name>]`. Così `RepeaterColumn::key('email')->visibleWhen('kind', 'email')` funziona senza altro.
2. **Nome dell'attività scelta.** `places.js` (`wiPlaceApply()`) non scrive il nome del posto né lo passa a nessuno. Modifica minima: con `data-wi-place-name="<chiave>"` sulla via, se `prediction.types` contiene `establishment` e il campo `<prefisso><chiave>` è vuoto, scriverci `prediction.mainText.text` (gratis: viene dall'autocompletamento, niente `displayName` a pagamento); e un `CustomEvent('wi:place', { detail: { placeId, name, types } })` sulla via dopo la scelta. Nel core, `AddressExtension::withNameSearch(?string $nameKey = null)` che stampa quell'attributo. Oggi lo script legge `input.wiPlace.items` e le opzioni `.wi-place-option`, interni della lib.

## Brand delle aziende

Il brand è il nome commerciale con cui un'azienda cliente si presenta: un brand può stare su più ragioni sociali e una ragione sociale può avere più brand. È separato dai marchi dei prodotti del gestionale (`gst_brands`).

Tabelle `company_brands` (`Models\CompanyBrand`: `code`, `name`, `slug`, `name_key`, `note`, `archived`) e `company_brand_links` (`Models\CompanyBrandLink`: `company_id`, `brand_id`, `role`, `is_default`, `position`, `ended_at`; una riga per coppia).

- **Codice e slug**: `code` con prefisso `cbr_` (come `cmp_` delle aziende) e `slug` dal nome, tutti e due univoci e fissi dopo la creazione. Due brand con lo stesso slug di base prendono `-1`, `-2`… come `create_link()` del framework, contando anche le righe cancellate. `name_key` è il nome senza maiuscole, accenti, spazi e punteggiatura: serve a trovare i doppioni con una query. `Migrations\CompanyBrandCodesMigration` li dà ai brand nati prima (allinea da sé la tabella, perché gira prima di `runTables()`; tocca solo i valori vuoti).
- **Ruolo** dell'azienda verso il brand (`CompanyBrandLink::ROLES`, testo): `owner` (suo), `licensee` (su licenza), `intermediary` (commissiona lavori per un brand non suo, conto terzi). `CompanyBrands::addRole($key, $label)` ne aggiunge altri (es. `supplier`); `roles()`, `role()`, `roleLabel()`, etichette in `contacts.brand_role_*`.
- **Predefinito**: al massimo un `is_default` per azienda; `defaultOf($companyId)` dà quello, altrimenti il primo, altrimenti 0.
- **Storia**: staccare un brand chiude il legame (`ended_at`), ricollegarlo riapre la stessa riga. `belongsTo($brandId, $companyId)` guarda il legame di oggi; con una data (`belongsTo($brandId, $companyId, '2026-03-01')`) vale il legame aperto in quel momento (`creation` ≤ data < `ended_at`); una data senza ora vale fino a fine giornata. Un legame riaperto tiene la sua `creation`, quindi risponde sì anche per il periodo in cui era staccato. `creation` la scrive MySQL e `ended_at` PHP: se i fusi dei due non coincidono, il confronto sbaglia di quella differenza a cavallo dell'ora del cambio.
- **Archivio**: `archive($id)` / `restore($id)` o il campo «Archiviato» del form. Un brand archiviato esce da `options()` (resta dove è già legato, con «(archiviato)»), non si lega a un'azienda nuova, resta leggibile con `find()` e sui documenti.
- **Doppioni**: `similar($name, $exceptId)` trova i nomi uguali a meno di maiuscole, spazi e punteggiatura («LaPiana» = «La Piana»; un nome di soli simboli vale per sé). La creazione rapida e il form del brand avvisano; il nome identico (maiuscole e spazi a parte) resta rifiutato in creazione e quando il nome cambia, non su un brand che resta archiviato né su un doppione storico che non cambia nome. Se due «+» con lo stesso nome arrivano insieme, il secondo riprende il brand del primo. `merge($fromId, $intoId)` sposta i legami (se l'azienda ha già il brand di arrivo tiene quel legame e chiude l'altro), i brand seguiti dai referenti, archivia il brand di partenza ed emette `company.brand_merged`. Non ha una pagina: chi la espone controlla i permessi.
- `Support\CompanyBrands` è l'unico punto che crea brand e scrive i legami: `options($companyId = 0)`, `find()`, `of($companyId)`, `idsOf()`, `linksOf()` (righe con `brand_id`, `role`, `is_default`, `brand`), `companiesOf($brandId)`, `belongsTo()`, `defaultOf()`, `findOrCreate($name)`, `byName()`, `similar()`, `slugFor()`, `nameKey()`, `sync($companyId, $items)` (lista di id o righe `brand_id` / `role` / `is_default`), `archive()`, `restore()`, `merge()`. `sync()` e `merge()` scrivono in una transazione (`Transaction::run()`) ed emettono gli eventi dopo il commit.
- Eventi (`CompanyEvents`): `company.brand_created`, `company.brand_linked` e `company.brand_unlinked` (con `role`), `company.brand_merged` (`from_id`, `into_id`, `brand`: i moduli dipendenti rimappano lì i loro riferimenti).
- `Resources\CompanyBrandResource` (`/backend/company-brands/`, admin) implementa `QuickCreateStore` di `wonder-image/app`: la creazione rapida riusa il brand con lo stesso nome invece di farne un doppione.
- Il trait `Resources\Concerns\HasCompanyBrands` dà a una Resource delle aziende il repeater `brands` (brand cercabile con «+» e ruolo; il predefinito non si sceglie più nel form: resta quello di prima se c'è ancora, altrimenti vale il primo, `CompanyBrands::defaultOf()`; da codice una riga può ancora portare `is_default`) e il marker `company_brands_form`: i brand si salvano solo se il form li ha mostrati. `brandResource()` dice quale Resource crea il brand (un modulo può ripubblicarla con altri permessi), `withBrandValues()` precompila il form. `CompanyResource` lo usa con il campo per primo.
- **Linee** (un livello solo): `company_brands.parent_id` fa di un brand la linea di un altro («La Piana» › «Piana Mare»). Le regole sono in `CompanyBrands::parentProblem($brandId, $parentId)` (il padre esiste, non è archiviato, non è il brand stesso, non ha un padre; un brand con linee non diventa linea) e valgono per `setParent()` e per il campo «Brand principale» del form. Letture: `parentOf()`, `childrenOf()` (archiviate comprese), `familyIds($brandId)` (il brand e le sue linee, per chi somma i totali del padre), `fullLabel($row)` («Padre › Figlio», testo da escapare), che `options()` usa nelle tendine. Archiviare un padre non tocca le linee. In `merge()` le linee del brand di partenza passano al brand di arrivo, o al suo padre se è una linea; se il padre era proprio il brand di partenza, l'arrivo resta senza padre.
- `CompanyRecord::from()` porta `brands` (`id`, `code`, `slug`, `name`, `role`, `is_default`, `parent_id`, `parent_code` e i dati del brand, vedi sotto) per export e sync.
- **Dati del brand** (`Support\BrandData`), per tutti: `logo` (immagine png, jpg, webp o svg fino a 2 MB in `contacts/brand-logos/`, caricata dal framework, che ripulisce gli SVG), `website` (solo http/https), `domains` («Domini email»: JSON, lista di domini minuscoli senza schema né `www.`, al massimo `MAX_DOMAINS`; se vuota si ricava dal sito, tranne quando il sito sta su un social, un servizio di siti o una casella generica: `facebook.com/lapiana` non dà `facebook.com`) e `description`. **Brand kit**: `contacts` («Recapiti del brand»: JSON, righe `kind` `email` con `email`, o `phone` con `phone_prefix` / `phone`, più `label`, scelta da una tendina con le etichette di `ContactPoints::labels()`: un testo libero di prima diventa vuoto; al massimo `MAX_CONTACTS`, senza doppioni), `socials` (JSON chiave → URL; chiavi da `BrandData::socials()`, estendibile con `addSocial()`), `colors` (JSON di righe `{color, name}`: esadecimale `#rrggbb` e nome facoltativo, come «Blu istituzionale»; al massimo 12; nel form un repeater con input colore HEX e nome; `BrandData::palette()` rilegge anche la lista di soli esadecimali dei brand di prima), `tone`, `sector` (tendina con le 21 sezioni ATECO 2025 / NACE Rev. 2.1, `A`–`U`, `BrandData::sectors()`; un testo libero di prima resta salvato ma il form lo mostra vuoto), `language` (tendina ISO 639-1, `BrandData::languages()`, estendibile con `addLanguage()`; default `it`), `assets_url`. Le colonne singole `domain`, `email`, `phone_prefix`, `phone` restano e tengono il primo valore delle liste, per chi le legge ancora; `BrandData::domainsOf($riga)` e `contactsOf($riga)` leggono le liste e ripiegano sulle colonne singole dei brand di prima. Il form del brand si apre come quello del marchio del catalogo: logo a sinistra e, accanto, nome, brand principale, sito e «Archiviato»; poi due gruppi; il kit si vede e si salva solo se un modulo lo accende al boot con `CompanyBrands::useKit()` (spento di default). Il logo passa solo come file caricato: un testo postato al suo posto si ignora. Ogni valore è testo da escapare in uscita. Nel record il logo è `logo` (percorso sotto i caricamenti) e `logo_url`; `domains` e `contacts` sono liste, accanto alle colonne singole; `socials` e `colors` si rileggono con le regole.
- `CompanyBrands::byDomain($emailOrDomain)` riconosce il brand di un'email in arrivo da uno dei suoi domini email (`domains`, più `domain` dei brand di prima; anche da un sottodominio). Una casella generica (gmail.com, yahoo.*, hotmail.*, libero.it, le PEC dei gestori…: `BrandData::isMailProvider()`, estendibile con `addMailProvider()`) o un social / servizio di siti (facebook.com, wixsite.com…: `BrandData::isSiteHost()`, `addSiteHost()`) non corrisponde mai, un brand archiviato nemmeno; se più brand hanno lo stesso dominio vince il padre delle sue linee, altrimenti `null`. **È un suggerimento, non una prova**: chiunque può scrivere da un dominio. Non usarlo mai per agganciare un account, una scheda o dei dati.
- **Indirizzi per brand**: un indirizzo di un'azienda può valere solo per alcuni dei suoi brand (tabella `company_address_brands`, `Models\CompanyAddressBrand`); nessuna riga = vale per tutti. `Support\CompanyAddresses` è l'unico punto che scrive (`setBrands($addressId, $brandIds)`, solo brand legati oggi all'azienda) e legge: `addressesFor($companyId, $brandId = 0)` dà gli indirizzi vivi con `brand_ids`, prima quelli del brand e poi quelli per tutti; `brandsOf()`, `brandsByAddress()`, `withBrandIds()` per un repeater. Staccare un brand dall'azienda o unirlo toglie o sposta le righe; un brand non più legato non conta (l'indirizzo torna per tutti). Campo «Brand» in `CompanyAddressResource`. Dal lato del brand: `forBrand($brandId, $companyIds = null)` dà gli indirizzi che le aziende hanno applicato a quel brand (con `$companyIds` solo quelli di quelle aziende); `brandProblems($brandId, $rows, $companyIds)` li controlla e `updateFromBrand(...)` li aggiorna, solo le righe di `forBrand()` e solo i campi di `BRAND_FIELDS` (`label`, `street`, `number`, `cap`, `city`, `province`, `country`, più place ID e coordinate se postati). Non crea né elimina indirizzi e non tocca «Presso», predefinito, ordine e brand per cui valgono.

## Gruppi di aziende

`companies.parent_id` è la capogruppo (0 = nessuna), a un livello solo. `Support\CompanyGroups` tiene le regole e le letture: `problem($companyId, $parentId)` (la capogruppo esiste e non è cancellata, non è l'azienda stessa, non ha una capogruppo; un'azienda con controllate non diventa controllata), `setParent()`, `parentOf()`, `childrenOf()`, `groupIds($companyId)` (capogruppo e controllate, da qualunque azienda del gruppo), `options()` per il select.

- Il campo «Capogruppo» (`parent_id`) viene da `HasCompanyGroup::groupFields()`; `CompanyResource` lo mette dopo i brand. `HasCompanyBrands` usa `HasCompanyGroup`, che in `mutateRequestValues()` valida ogni `parent_id` postato, anche dove il form non lo mostra.
- L'export (`ContactExport::COMPANY_FIELDS`) non la porta: le colonne sono testi della scheda, non id.
- `CompanyRecord::from()` porta `parent_id` e `parent_code` (0 e stringa vuota se non c'è).

## Committente

Chi commissiona un lavoro è un'azienda o una persona, con il brand con cui l'azienda si presenta. Il nome con cui chiamarlo è il brand, altrimenti la ragione sociale, altrimenti il nome della persona: `Support\Commissioner::label($brandId, $companyId, $personId)` (testo da escapare).

`Commissioner::resolve($brandId, $companyId, $personId)` dà la forma da salvare: `['brand_id', 'company_id', 'person_id', 'label']`, o `null` se non esistono né l'azienda né la persona. Il brand resta solo se è legato oggi all'azienda (un privato non ha brand); la persona, se c'è un'azienda, solo se ne è un referente. Su un progetto o un documento si salvano i tre id e il nome copiato, così il nome resta quello del momento anche se poi il brand cambia.

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

- `from($rowOrId)` dà un array serializzabile con `schema` (oggi `4` per le persone, `5` per le aziende), `entity` (`contact` o `company`), `id`, `code`, i campi (`ContactExport::PERSON_FIELDS` più `birth_date` per la persona, `ContactExport::COMPANY_FIELDS` per l'azienda), `place_id`, `lat`, `lng` della fatturazione, `is_customer`, `active`, `emails` e `phones` (tutti i contatti, il principale per primo con `is_primary`; per le aziende con `departments`; `email` e `phone` restano i principali), `relations` (legami vivi: id dell'altro lato, `job_title`, `department` (la prima area), `departments`, `is_primary`, `receives_documents`, `access`, `work_email`, `work_phone_prefix`, `work_phone`) ed `external_ids` (righe di `external_references` con `entity_type` uguale a `entity`: `provider`, `environment`, `object_type`, `external_id`, `synced_at`). La persona aggiunge `user_id` e `consents` (stato corrente dei consensi dell'utente collegato, dal registro del core); l'azienda `is_supplier`, `parent_id`, `parent_code` e `brands`. È la base per un export o un payload verso un CRM.
- `fromArray($input)` porta un record in ingresso agli stessi campi, ripuliti; le chiavi assenti restano assenti, così un import parziale non svuota la scheda.
- `validate($record)` dà gli errori (campo → `required` / `invalid`) con le regole di base: nome o cognome per la persona, ragione sociale per l'azienda, formato di email e PEC, contatti, legami (con l'email di lavoro) e id esterni completi. `fromArray()` ripulisce `place_id`, `lat`, `lng` se ci sono.

L'id di un sistema esterno si salva in `external_references` (modello `ExternalReference` del core), non in una colonna nuova della scheda. I consensi restano quelli del core: il modulo non ne aggiunge.

## Per i moduli che si appoggiano alla scheda

- `Account\ContactAccount::link($userId, $input)` collega l'utente a una scheda: riusa quella già collegata o una email non assegnata (solo `contacts.email`, mai un recapito in più), rifiuta una scheda di un altro account (`contact_link_conflict`). Nome, cognome ed email della scheda diventano quelli dell'utente; il telefono solo se arriva pieno.
- `Account\ContactAccount::linkKeepingIdentity($userId, $input)` fa lo stesso, ma una scheda che esiste già tiene nome, cognome, email e telefono: scrive solo `user_id`, `is_customer` e `active`. È per la scheda compilata da un ordine da ospite. Senza scheda la crea con i dati dell'utente.
- Il collegamento avviene anche da solo: `CustomerContact::forUser()` crea la scheda che manca e `HasSignupFields::afterUserSaved()` la collega nella registrazione. Un profilo con il trait non lo rifà: chiama `parent::afterUserSaved()` e usa i due ganci `protected`, `linksContactOn($surface)` (su quali superfici collegare) e `afterContactLinked($userId, $contactId, $surface, $input)` (dopo il collegamento, prima dei campi del secondo passo). Su `email.verify` un collegamento fallito non lancia eccezioni né chiama `afterContactLinked()`: finisce nel log e si ritenta su `signup-completion`. Su `signup-completion` e `federated` lancia `AccountSaveFailed`. L'auth generica del core, senza il trait, non crea schede.
- `Account\CustomerContact::forUser($userId)` restituisce la scheda (letta una volta per richiesta) e, se manca, la crea con `link()`: non è una lettura pura. `forget($userId)` la invalida dopo una scrittura.
- `Account\Concerns\ManagesContact` dà ai controller del pannello `contact($userId)` e `contactErrors($contact)`.
- `Account\AccountAddresses` e `Account\AccountBilling` leggono e salvano indirizzi e fatturazione con `AccountAddressValidation` del core, che vale sempre. `AccountBilling::saveCompany()` fa lo stesso sulla fatturazione di un'azienda (`Company::billing()`); chi chiama controlla che l'utente ne sia `admin`. Un nuovo indirizzo diventa il predefinito (`is_default`) se la scheda non ne ha già uno attivo: così si riparano anche le schede create prima, con indirizzi ma senza predefinito, al primo indirizzo aggiunto (la modifica di un indirizzo non tocca `is_default`). La posizione viene da `nextPosition()` (un solo `MAX`). Se si elimina il predefinito, lo diventa l'indirizzo rimasto con la posizione più bassa; la promozione arriva dopo l'eliminazione, fuori transazione, e se non riesce la scheda resta senza predefinito fino al prossimo indirizzo aggiunto.
- A chi scrivere: per un referente di un'azienda `contact_email` delle righe di `ContactRelations::peopleFor()` (email di lavoro o la sua); per l'azienda stessa `ContactPoints::companyEmailsFor($companyId, $area)`. Mai `contact_points` o le email di lavoro per riconoscere chi scrive o per agganciare un account.
- I modelli si estendono come fa il gestionale, che aggiunge solo i campi commerciali.

Un'estensione che lavora sulle righe o le voci di questo modulo deve registrarsi dopo di lui. I file di route dei moduli si caricano in ordine alfabetico di percorso, non di dipendenza: oggi `customer-account` precede `ecommerce` e `gestionale`; un modulo con un nome che lo precede non può contare sulle sue righe.

## Compatibilità

Il modulo non carica alias dei vecchi namespace del core (`Wonder\App\Models\Contacts\*`, `Wonder\App\Resources\Contacts\*`, `Wonder\Auth\Frontend\{ContactAccount,AccountAddresses,AccountBilling}`): chi li importava passa a `Wonder\Plugin\CustomerAccount\…`. Le tabelle e i dati non cambiano.

## Verifica

```bash
WI_APP=/percorso/di/wonder-image/app php tests/run.php
```

Da un sito con il modulo: `php forge update --local`, poi area account (indirizzi, fatturazione, dati personali), registrazione con `signup.fields` ed export di `/backend/contacts/` e `/backend/companies/`. Prima del primo aggiornamento dopo la 0.1: `php forge customer-account:companies`.
