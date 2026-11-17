# Importazione delle anagrafiche (design breve)

Data: 2026-11-17. Origine: wonder-image/gestionale#119 (parte clienti; i listini per categoria sono rinviati).

## Perché

Chi passa al gestionale da un altro programma ha già persone, aziende, indirizzi e
referenti in un foglio. Oggi la rubrica si esporta ma non si carica: bisogna
ribattere tutto a mano.

## Cosa (approvato)

Quattro tracciati di base nel modulo **customer-account**, ognuno speculare
all'export e con «Importa» nel proprio elenco:

| Tracciato | Chiave | Tabella |
|---|---|---|
| Persone | `contacts_people` | `contacts` |
| Aziende | `contacts_companies` | `companies` |
| Indirizzi di consegna | `contacts_addresses` | `contact_addresses`, `company_addresses` |
| Referenti | `contacts_relations` | `contact_relations` |

Le chiavi non sono `contacts` / `companies` perché `Dataset\Sources` mette
nello stesso spazio gli slug delle Resource con export e le chiavi dei
tracciati: con lo stesso nome il tracciato sostituirebbe l'export.

Riconoscimento della scheda, in quest'ordine: ID, codice, poi P.IVA (aziende)
oppure codice fiscale ed email (persone). Trovata si aggiorna, altrimenti si
crea. Un dato che riconosce una scheda ma è già di un'altra ferma la riga in
anteprima, con il nome di chi lo ha già.

Celle vuote e colonne non abbinate non toccano il valore salvato: l'import
non svuota mai un campo. «Invariata» e i valori da ripristinare
all'annullo riguardano solo i campi davvero cambiati.

## Approcci considerati

1. **Tracciati nel gestionale** (come catalogo e listini). Scartato: la rubrica
   è di customer-account e un sito senza gestionale resterebbe senza import.
2. **Tracciati in customer-account, il gestionale li sostituisce con la stessa
   chiave in `import.layouts`.** Scartato: l'ordine delle config segue
   `custom/config/modules.php`, non le dipendenze, quindi chi vince non è
   garantito.
3. **Tracciati in customer-account con estensioni (scelto).** Il modulo
   registra i quattro tracciati; altri moduli aggiungono colonne, controlli e
   valori con `contacts_import.extensions` (un elenco di classi che
   implementano `Support\Import\ImportExtension`). Le estensioni si sommano
   e l'ordine non conta.

## Estensione del gestionale

`GestionaleContactsImport` aggiunge listino, metodo di pagamento, condizione
di pagamento (solo con più di una attiva) e commerciale, solo dove il form
del cliente li mostra a chi carica. Scrive con i Model del gestionale, dà il
permesso a chi può modificare clienti o aziende, nega aziende e referenti
quando il gestionale non ha aziende (le persone restano, anche come
referenti) e riconosce solo le schede visibili a chi carica (RowVisibility).
Il commerciale che carica non sceglie il commerciale: le schede che crea
nascono sue, quelle che aggiorna lo tengono. Il ruolo cliente/fornitore delle
aziende è del tracciato `CompanyLayout`, non dell'estensione.

## Regole replicate dal form

Codice nuovo (`newCode()`) in creazione, campi obbligatori, paese in
`countries()` e provincia in `states()`, validazione del Model già in
anteprima, flag `true`/`false` letti anche come «Sì/No», email che non cambia
su una persona con account (`user_id`). Dopo ogni scrittura partono gli
eventi `ContactEvents` / `CompanyEvents` come dal form; l'annullo riporta i
dati ma non emette eventi (gli ascoltatori ricevono il prossimo salvataggio).

Telefono: l'export dà «+39 3331234567». L'import separa il prefisso dal
numero allo spazio; senza prefisso tiene quello salvato o `+39`.

Indirizzi: una riga è un indirizzo legato alla scheda (persona o azienda,
riconosciuta come sopra). Un indirizzo con lo stesso ID si aggiorna, gli altri
si aggiungono; con «Predefinito» a sì gli altri indirizzi della scheda non
sono più predefiniti.

Referenti: coppia azienda/persona unica anche tra le cancellate; una cancellata
si riattiva. Scritture da `ContactRelations::normalize()` e query dirette solo
per `deleted`.

## Scostamento dal design approvato

`TableLayoutSchema::importLayout()` ammette un tracciato per elenco e la
pagina di caricamento del core non è nel menu. I tracciati senza un elenco
proprio diventano bottoni in più nell'elenco, accanto a «File» (`importExtraLayouts()`
della Resource e `ImportLinks::buttons()`, solo se `permits()` dice sì):

- customer-account: «Importa referenti» sulle aziende; gli indirizzi hanno il
  loro elenco con «Importa»;
- gestionale: «Importa indirizzi» sulle persone, «Importa indirizzi» e
  «Importa referenti» sulle aziende.

La freccia accanto al titolo del caricamento segue `ImportExtension::backUrl()`: nel gestionale porta agli
elenchi clienti e aziende del gestionale. Più tracciati per elenco potrebbero
andare nel core (wonder-image/app).

## Fuori

Cancellazioni da file, contatti in più del CRM, brand, aree dei referenti,
listini per categoria.
