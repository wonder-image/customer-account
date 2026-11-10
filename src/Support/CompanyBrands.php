<?php

namespace Wonder\Plugin\CustomerAccount\Support;

use RuntimeException;
use Throwable;
use Wonder\App\Support\RequestMemo;
use Wonder\Plugin\CustomerAccount\Models\Company;
use Wonder\Plugin\CustomerAccount\Models\CompanyBrand;
use Wonder\Plugin\CustomerAccount\Models\CompanyBrandLink;
use Wonder\Sql\Transaction;
use Wonder\Support\Text\Slug;

/**
 * I brand delle aziende: i nomi commerciali, legati alle ragioni sociali
 * molti a molti (`company_brand_links`).
 *
 * L'unico punto che crea brand e scrive i legami: form, creazione rapida,
 * import e sync passano da qui. Un legame staccato non si cancella: si chiude
 * (`ended_at`) e si riapre se il brand torna, così i documenti vecchi
 * restano verificabili alla loro data. Emette `CompanyEvents::BRAND_CREATED`,
 * `BRAND_LINKED`, `BRAND_UNLINKED` e `BRAND_MERGED`.
 *
 * I dati del brand seguono `BrandData`; il brand kit si accende con
 * `useKit()`, `byDomain()` riconosce il brand da un'email in arrivo.
 */
final class CompanyBrands
{
    /** @var array<string, string> ruoli aggiunti da moduli e siti: chiave => etichetta */
    private static array $extraRoles = [];

    private static bool $kit = false;

    /**
     * Accende il «brand kit» nel form del brand (recapiti, social, colori,
     * tono, …). Spento di default: il professionista non lo vede. Un modulo
     * lo accende al boot.
     */
    public static function useKit(bool $enabled = true): void
    {
        self::$kit = $enabled;
    }

    /** Il form del brand mostra e accetta il brand kit? */
    public static function kitEnabled(): bool
    {
        return self::$kit;
    }

    /**
     * Il brand di un'email in arrivo o di un dominio («ordini@lapiana.it»,
     * «https://www.lapiana.it»), dai domini email del brand (`domains`, più
     * `domain` dei brand di prima): vale anche un
     * sottodominio («news.lapiana.it»). Una casella generica (gmail.com,
     * yahoo.*, le PEC dei gestori, …: `BrandData::isMailProvider()`) o un
     * social / servizio di siti (`BrandData::isSiteHost()`) non corrisponde
     * mai, né un brand archiviato. Se più brand hanno lo stesso dominio vince
     * il brand principale delle sue linee; altrimenti non tira a indovinare: null.
     *
     * È un suggerimento (proporre il brand di un'email in arrivo), non una
     * prova: chiunque può scrivere da un dominio. Non va mai usato per
     * agganciare un account, una scheda o dei dati.
     *
     * @return array<string, mixed>|null
     */
    public static function byDomain(string $emailOrDomain): ?array
    {
        $value = trim($emailOrDomain);
        $at = strrpos($value, '@');
        $domain = BrandData::domain($at !== false ? substr($value, $at + 1) : $value);

        if ($domain === null || $domain === '' || BrandData::isMailProvider($domain) || BrandData::isSiteHost($domain)) {
            return null;
        }

        // Dal più preciso al dominio di secondo livello: news.lapiana.it, lapiana.it.
        $labels = explode('.', $domain);
        $candidates = [];

        for ($i = 0; $i < count($labels) - 1; $i++) {
            $candidate = implode('.', array_slice($labels, $i));

            if (!BrandData::isMailProvider($candidate) && !BrandData::isSiteHost($candidate)) {
                $candidates[] = $candidate;
            }
        }

        if ($candidates === []) {
            return null;
        }

        $found = [];

        // I brand sono pochi e già letti per la richiesta: il confronto si fa qui.
        foreach (self::allById() as $row) {
            if (self::archived($row)) {
                continue;
            }

            foreach (array_intersect(BrandData::domainsOf($row), $candidates) as $match) {
                $found[$match][] = $row;
            }
        }

        foreach ($candidates as $candidate) {
            $rows = $found[$candidate] ?? [];

            if (count($rows) === 1) {
                return $rows[0];
            }

            if ($rows !== []) {
                return self::familyHead($rows);
            }
        }

        return null;
    }

    /**
     * I brand per un select, per nome: quelli non archiviati, più gli
     * archiviati ancora legati all'azienda (o, senza azienda, a una
     * qualunque), così chi li ha già non li perde salvando.
     *
     * Le letture restano in `RequestMemo` per la richiesta (il framework
     * rivaluta `formSchema()` più volte): una scrittura sulla tabella le svuota.
     *
     * @return array<int, string>
     */
    public static function options(int $companyId = 0): array
    {
        $out = [];
        $archived = [];
        $all = self::allById();

        foreach ($all as $row) {
            if (self::archived($row)) {
                $archived[(int) $row['id']] = $row;
                continue;
            }

            $out[(int) $row['id']] = self::fullLabelFrom($row, $all);
        }

        if ($archived !== []) {
            $condition = ['brand_id' => array_keys($archived), 'ended_at' => null];

            if ($companyId > 0) {
                $condition['company_id'] = $companyId;
            }

            $links = RequestMemo::remember(CompanyBrandLink::$table, 'options:'.$companyId.':'.implode(',', array_keys($archived)), static fn (): array => self::links($condition));

            foreach ($links as $link) {
                $row = $archived[(int) $link['brand_id']] ?? null;

                if ($row !== null) {
                    $out[(int) $row['id']] = self::fullLabelFrom($row, $all).' '.__t('contacts.brand_archived_suffix');
                }
            }
        }

        asort($out, SORT_NATURAL | SORT_FLAG_CASE);

        return $out;
    }

    /**
     * Le scelte del «Brand principale»: i brand senza padre e non archiviati.
     * Il brand stesso c'è (il form non sa quale mostra): lo scarta `parentProblem()`.
     *
     * @return array<int, string>
     */
    public static function parentOptions(): array
    {
        $out = [];

        foreach (self::allById() as $id => $row) {
            if (!self::archived($row) && self::parentId($row) === 0) {
                $out[$id] = self::label($row);
            }
        }

        asort($out, SORT_NATURAL | SORT_FLAG_CASE);

        return $out;
    }

    /**
     * Il nome con la linea: «Padre › Figlio», o il nome se non ha padre.
     * Testo, come `label()`: chi lo stampa lo escapa.
     */
    public static function fullLabel(array $row): string
    {
        $parent = self::parentId($row) > 0 ? self::find(self::parentId($row)) : null;

        return $parent !== null ? self::label($parent).' › '.self::label($row) : self::label($row);
    }

    /** Il brand padre (la linea appartiene a lui), o null. */
    public static function parentOf(int $brandId): ?array
    {
        $brand = self::find($brandId);

        return $brand !== null && self::parentId($brand) > 0 ? self::find(self::parentId($brand)) : null;
    }

    /**
     * Le linee di un brand, archiviate comprese (i documenti le usano ancora).
     *
     * @return list<array<string, mixed>>
     */
    public static function childrenOf(int $brandId): array
    {
        return $brandId > 0 ? self::rows(self::safe(static fn () => CompanyBrand::find(['parent_id' => $brandId], null, 'name', 'ASC'))) : [];
    }

    /**
     * Il brand e le sue linee: per chi somma i totali del padre. Vuota se il brand non c'è.
     *
     * @return list<int>
     */
    public static function familyIds(int $brandId): array
    {
        if (self::find($brandId) === null) {
            return [];
        }

        return [$brandId, ...array_map(static fn (array $row): int => (int) $row['id'], self::childrenOf($brandId))];
    }

    /**
     * Perché `$parentId` non può essere il padre di `$brandId`: vuoto se può.
     * Un livello solo: il padre esiste, non è archiviato, non è il brand
     * stesso e non ha a sua volta un padre; il brand che diventa figlio non
     * ha linee. `$brandId` 0 è un brand nuovo; `$parentId` 0 toglie il padre.
     *
     * @return ''|'missing'|'archived'|'self'|'nested'|'has_children'
     */
    public static function parentProblem(int $brandId, int $parentId): string
    {
        if ($parentId === 0) {
            return '';
        }

        $parent = self::find($parentId);

        return match (true) {
            $parentId === $brandId => 'self',
            $parent === null => 'missing',
            self::archived($parent) => 'archived',
            self::parentId($parent) > 0 => 'nested',
            $brandId > 0 && self::childrenOf($brandId) !== [] => 'has_children',
            default => '',
        };
    }

    /** Fa di un brand una linea di un altro (0 toglie il padre), se le regole lo permettono. */
    public static function setParent(int $brandId, int $parentId): bool
    {
        if (self::find($brandId) === null || self::parentProblem($brandId, $parentId) !== '') {
            return false;
        }

        $result = CompanyBrand::query()->Update(CompanyBrand::$table, ['parent_id' => $parentId], 'id', $brandId);

        return (bool) ($result->success ?? false);
    }

    /**
     * Il nome leggibile di un brand. È testo, non HTML: chi lo stampa in una
     * pagina lo escapa.
     */
    public static function label(array $row): string
    {
        $name = html_entity_decode(trim((string) ($row['name'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $name !== '' ? $name : '#'.($row['id'] ?? '');
    }

    /**
     * Un brand non cancellato, anche archiviato: i documenti che lo usano
     * devono poterlo ancora leggere.
     *
     * @return array<string, mixed>|null
     */
    public static function find(int $brandId): ?array
    {
        if ($brandId <= 0) {
            return null;
        }

        $row = self::safe(static fn () => CompanyBrand::findById($brandId));

        return is_array($row) && isset($row['id']) && ($row['deleted'] ?? 'false') === 'false' ? $row : null;
    }

    /**
     * Gli id dei brand legati a un'azienda, nell'ordine scelto.
     *
     * @return list<int>
     */
    public static function idsOf(int $companyId): array
    {
        return array_column(self::linksOf($companyId), 'brand_id');
    }

    /**
     * I brand legati a un'azienda, nell'ordine scelto.
     *
     * @return list<array<string, mixed>>
     */
    public static function of(int $companyId): array
    {
        return array_column(self::linksOf($companyId), 'brand');
    }

    /**
     * I legami aperti di un'azienda, nell'ordine scelto: per il form e i record.
     *
     * @return list<array{id: int, brand_id: int, role: string, is_default: bool, position: int, brand: array<string, mixed>}>
     */
    public static function linksOf(int $companyId): array
    {
        if ($companyId <= 0) {
            return [];
        }

        $links = self::links(['company_id' => $companyId, 'ended_at' => null]);
        $brands = self::brandsById(array_map(static fn (array $link): int => (int) $link['brand_id'], $links));
        $out = [];

        foreach ($links as $link) {
            $brand = $brands[(int) $link['brand_id']] ?? null;

            if ($brand !== null) {
                $out[] = [
                    'id' => (int) $link['id'],
                    'brand_id' => (int) $brand['id'],
                    'role' => self::role($link['role'] ?? '') ?: CompanyBrandLink::ROLE_OWNER,
                    'is_default' => ($link['is_default'] ?? 'false') === 'true',
                    'position' => (int) ($link['position'] ?? 0),
                    'brand' => $brand,
                ];
            }
        }

        return $out;
    }

    /**
     * Gli id dei brand legati oggi a più aziende, in una query: per chi deve
     * controllare molti legami insieme (i brand seguiti dai referenti).
     *
     * @param list<int> $companyIds
     * @return array<int, list<int>> azienda => brand, nell'ordine scelto
     */
    public static function openIdsByCompany(array $companyIds): array
    {
        $companyIds = array_values(array_unique(array_filter(array_map('intval', $companyIds), static fn (int $id): bool => $id > 0)));
        $out = [];

        if ($companyIds === []) {
            return $out;
        }

        foreach (self::links(['company_id' => $companyIds, 'ended_at' => null]) as $link) {
            $out[(int) $link['company_id']][] = (int) $link['brand_id'];
        }

        return $out;
    }

    /**
     * Le aziende (ragioni sociali) legate oggi a un brand.
     *
     * @return list<array<string, mixed>>
     */
    public static function companiesOf(int $brandId): array
    {
        if ($brandId <= 0) {
            return [];
        }

        $ids = array_map(static fn (array $link): int => (int) $link['company_id'], self::links(['brand_id' => $brandId, 'ended_at' => null]));

        if ($ids === []) {
            return [];
        }

        $companies = [];

        foreach (self::rows(self::safe(static fn () => Company::find(['id' => $ids]))) as $row) {
            $companies[(int) $row['id']] = $row;
        }

        $out = [];

        foreach ($ids as $id) {
            if (isset($companies[$id])) {
                $out[] = $companies[$id];
            }
        }

        return $out;
    }

    /**
     * Il brand è dell'azienda? Senza `$at` vale il legame di oggi; con una
     * data/ora vale anche un legame chiuso dopo, purché aperto prima
     * (`creation` ≤ `$at` < `ended_at`). Una data senza ora vale fino a fine
     * giornata (il documento di quel giorno). Un legame riaperto tiene la sua
     * `creation`: per il periodo in cui era staccato risponde comunque sì.
     * `creation` la scrive MySQL, `ended_at` PHP: se i due fusi orari non
     * coincidono il confronto sbaglia di quella differenza nelle ore a cavallo.
     */
    public static function belongsTo(int $brandId, int $companyId, ?string $at = null): bool
    {
        if ($brandId <= 0 || $companyId <= 0) {
            return false;
        }

        $link = self::links(['company_id' => $companyId, 'brand_id' => $brandId])[0] ?? null;

        if ($link === null) {
            return false;
        }

        $ended = trim((string) ($link['ended_at'] ?? ''));

        if ($at === null) {
            $open = $ended === '';
        } else {
            $at = trim($at);
            $time = strtotime(preg_match('/^\d{4}-\d{2}-\d{2}$/', $at) === 1 ? $at.' 23:59:59' : $at);

            if ($time === false) {
                return false;
            }

            $at = date('Y-m-d H:i:s', $time);
            $open = (string) ($link['creation'] ?? '') <= $at && ($ended === '' || $ended > $at);
        }

        return $open && self::find($brandId) !== null;
    }

    /** Il brand predefinito di un'azienda: quello scelto, altrimenti il primo, altrimenti 0. */
    public static function defaultOf(int $companyId): int
    {
        $links = self::linksOf($companyId);

        foreach ($links as $link) {
            if ($link['is_default']) {
                return $link['brand_id'];
            }
        }

        return $links[0]['brand_id'] ?? 0;
    }

    /**
     * I ruoli di un'azienda verso un brand per un select: quelli di base e
     * quelli aggiunti.
     *
     * @return array<string, string>
     */
    public static function roles(): array
    {
        $out = [];

        foreach (CompanyBrandLink::ROLES as $key) {
            $out[$key] = (string) __t('contacts.brand_role_'.$key);
        }

        return $out + self::$extraRoles;
    }

    /**
     * Aggiunge un ruolo (es. `supplier`). Un modulo lo chiama al boot, un
     * sito in `custom/function`.
     */
    public static function addRole(string $key, string $label): void
    {
        $key = strtolower(trim($key));

        if (preg_match('/^[a-z0-9_]{1,40}$/', $key) === 1) {
            self::$extraRoles[$key] = $label;
        }
    }

    /** Un ruolo valido, altrimenti vuoto. */
    public static function role(mixed $value): string
    {
        $value = trim((string) $value);

        return array_key_exists($value, self::roles()) ? $value : '';
    }

    /** L'etichetta di un ruolo; vuota se non c'è. */
    public static function roleLabel(mixed $value): string
    {
        return self::roles()[self::role($value)] ?? '';
    }

    /**
     * Il brand con questo nome (maiuscole e spazi non contano) o uno nuovo,
     * con codice e slug univoci. Un brand archiviato non conta: ne nasce uno nuovo.
     *
     * @return array{id: int, created: bool, brand: array<string, mixed>}|null `null` se il nome è vuoto o il salvataggio fallisce
     */
    public static function findOrCreate(string $name): ?array
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        if ($name === '') {
            return null;
        }

        $existing = self::byName($name);

        if ($existing !== null) {
            return ['id' => (int) $existing['id'], 'created' => false, 'brand' => $existing];
        }

        try {
            $result = CompanyBrand::create([
                'code' => CompanyBrand::newCode(),
                'name' => $name,
                'slug' => self::slugFor($name),
                'name_key' => self::nameKey($name),
                'archived' => 'false',
            ]);
        } catch (Throwable) {
            // Un indice univoco violato: di solito un altro «+» con lo stesso nome, appena salvato.
            $result = null;
        }

        $id = (int) ($result->insert_id ?? 0);

        if (!($result->success ?? false) || $id <= 0) {
            $existing = self::byName($name);

            return $existing !== null ? ['id' => (int) $existing['id'], 'created' => false, 'brand' => $existing] : null;
        }

        $brand = self::find($id) ?? ['id' => $id, 'name' => $name];
        CompanyEvents::dispatch(CompanyEvents::BRAND_CREATED, ['id' => $id, 'brand' => $brand]);

        return ['id' => $id, 'created' => true, 'brand' => $brand];
    }

    /** @return array<string, mixed>|null il brand non archiviato con questo nome (maiuscole e spazi non contano) */
    public static function byName(string $name): ?array
    {
        $key = self::key($name);

        foreach (self::similar($name) as $row) {
            if (self::key(self::label($row)) === $key) {
                return $row;
            }
        }

        return null;
    }

    /** Due nomi sono lo stesso nome per `byName()`? (maiuscole, spazi ed entità non contano) */
    public static function sameName(string $a, string $b): bool
    {
        return self::key(self::label(['name' => $a])) === self::key(self::label(['name' => $b]));
    }

    /**
     * I brand non archiviati con un nome uguale a meno di maiuscole, spazi,
     * accenti e punteggiatura («LaPiana» = «La Piana»): i doppioni probabili.
     *
     * @return list<array<string, mixed>>
     */
    public static function similar(string $name, int $exceptId = 0): array
    {
        $key = self::nameKey($name);

        if ($key === '') {
            return [];
        }

        return array_values(array_filter(
            self::rows(self::safe(static fn () => CompanyBrand::find(['name_key' => $key]))),
            static fn (array $row): bool => (int) $row['id'] !== $exceptId && !self::archived($row)
        ));
    }

    /**
     * La chiave dei doppioni: il nome in minuscolo, senza accenti, spazi né
     * punteggiatura. Un nome senza lettere latine tiene lettere e cifre; uno
     * di soli simboli (emoji) il nome in minuscolo senza spazi: mai vuota se
     * il nome non lo è, così la migrazione non la riscrive a ogni giro.
     */
    public static function nameKey(string $name): string
    {
        $name = html_entity_decode(trim($name), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $key = str_replace(['_', '-'], '', Slug::make($name));

        if ($key === '') {
            $key = preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($name, 'UTF-8')) ?? '';
        }

        if ($key === '') {
            $key = preg_replace('/\s+/u', '', mb_strtolower($name, 'UTF-8')) ?? '';
        }

        return self::fitColumn($key, 150);
    }

    /**
     * Uno slug libero per questo nome: come il framework (`create_link()`),
     * con `-1`, `-2`… se è preso. Conta anche le righe cancellate, che
     * tengono il loro slug nell'indice univoco.
     */
    public static function slugFor(string $name, int $exceptId = 0): string
    {
        $base = trim(str_replace('_', '-', Slug::make(html_entity_decode(trim($name), ENT_QUOTES | ENT_HTML5, 'UTF-8'))), '-');
        $base = substr($base !== '' ? $base : 'brand', 0, 140);

        for ($i = 0; ; $i++) {
            $slug = $i === 0 ? $base : $base.'-'.$i;
            $taken = array_filter(
                self::rows(self::safe(static fn () => CompanyBrand::find(['slug' => $slug, 'deleted' => ['true', 'false']]))),
                static fn (array $row): bool => (int) $row['id'] !== $exceptId
            );

            if ($taken === []) {
                return $slug;
            }
        }
    }

    /**
     * Taglia un testo perché entri nella colonna dopo che `Query` ha scritto
     * i caratteri fuori da latin1 come entità (`&#1052;`): un carattere può
     * valere fino a dieci posti.
     */
    private static function fitColumn(string $value, int $length): string
    {
        $out = '';
        $used = 0;

        foreach (mb_str_split($value, 1, 'UTF-8') as $char) {
            $codePoint = mb_ord($char, 'UTF-8');
            $size = $codePoint !== false && $codePoint > 0xFF ? strlen('&#'.$codePoint.';') : 1;

            if ($used + $size > $length) {
                break;
            }

            $out .= $char;
            $used += $size;
        }

        return $out;
    }

    /**
     * Scrive i brand di un'azienda: quelli non elencati si staccano (il
     * legame si chiude), quelli già visti si riaprono.
     *
     * `$items` è una lista di id (il ruolo e il predefinito di prima restano,
     * i nuovi sono `owner`) o di righe `['brand_id' =>, 'role' =>, 'is_default' =>]`.
     * Un ruolo sconosciuto tiene quello di prima; al massimo un predefinito
     * (il primo). Un brand archiviato resta solo se era già legato.
     *
     * @param list<int|string|array<string, mixed>> $items nell'ordine scelto; i brand che non esistono si ignorano
     * @return list<int> i brand rimasti legati
     */
    public static function sync(int $companyId, array $items): array
    {
        if ($companyId <= 0) {
            return [];
        }

        // Gli eventi partono dopo il commit: un ascoltatore non vede scritture poi annullate.
        [$kept, $events] = Transaction::run(static fn (): array => self::writeLinks($companyId, $items));

        foreach ($events as [$event, $payload]) {
            CompanyEvents::dispatch($event, $payload);
        }

        return $kept;
    }

    /**
     * Le scritture di `sync()`, dentro la sua transazione.
     *
     * @param list<int|string|array<string, mixed>> $items
     * @return array{0: list<int>, 1: list<array{0: string, 1: array<string, mixed>}>} i brand rimasti e gli eventi da emettere
     */
    private static function writeLinks(int $companyId, array $items): array
    {
        $events = [];
        $before = [];

        foreach (self::links(['company_id' => $companyId]) as $link) {
            $before[(int) $link['brand_id']] = $link;
        }

        $wanted = [];

        foreach ($items as $item) {
            $row = is_array($item) ? $item : ['brand_id' => $item];
            $id = (int) ($row['brand_id'] ?? 0);

            if ($id > 0 && !isset($wanted[$id])) {
                $wanted[$id] = [
                    'role' => self::role($row['role'] ?? ''),
                    'is_default' => array_key_exists('is_default', $row) ? ContactRelations::flag($row['is_default']) : null,
                ];
            }
        }

        $brands = self::brandsById(array_keys($wanted));

        foreach (array_keys($wanted) as $id) {
            $linked = isset($before[$id]) && self::isOpen($before[$id]);

            if (!isset($brands[$id]) || (self::archived($brands[$id]) && !$linked)) {
                unset($wanted[$id]);
            }
        }

        $default = self::chooseDefault($wanted, $before);
        $position = 0;

        foreach ($wanted as $id => $row) {
            $values = [
                'role' => $row['role'] ?: (self::role($before[$id]['role'] ?? '') ?: CompanyBrandLink::ROLE_OWNER),
                'is_default' => $id === $default ? 'true' : 'false',
                'position' => $position++,
            ];

            if (isset($before[$id])) {
                $reopened = !self::isOpen($before[$id]);

                if ($reopened) {
                    $values['ended_at'] = null;
                }

                CompanyBrandLink::query()->Update(CompanyBrandLink::$table, $values, 'id', (int) $before[$id]['id']);

                if ($reopened) {
                    $events[] = [CompanyEvents::BRAND_LINKED, self::payload($companyId, $brands[$id], $values['role'])];
                }

                continue;
            }

            $created = CompanyBrandLink::create(['company_id' => $companyId, 'brand_id' => $id] + $values);

            if (!($created->success ?? false)) {
                throw new RuntimeException('Legame azienda/brand non salvato.');
            }

            $events[] = [CompanyEvents::BRAND_LINKED, self::payload($companyId, $brands[$id], $values['role'])];
        }

        $gone = array_filter($before, static fn (array $link, int $id): bool => !isset($wanted[$id]) && self::isOpen($link), ARRAY_FILTER_USE_BOTH);
        $goneBrands = self::brandsById(array_keys($gone));
        $now = date('Y-m-d H:i:s');

        foreach ($gone as $id => $link) {
            CompanyBrandLink::query()->Update(CompanyBrandLink::$table, ['ended_at' => $now, 'is_default' => 'false'], 'id', (int) $link['id']);
            $events[] = [
                CompanyEvents::BRAND_UNLINKED,
                self::payload($companyId, $goneBrands[$id] ?? ['id' => $id], self::role($link['role'] ?? '') ?: CompanyBrandLink::ROLE_OWNER),
            ];
        }

        // I referenti che seguivano un brand staccato non lo seguono più, e gli indirizzi non valgono più per lui.
        ContactRelations::dropBrands($companyId, array_keys($gone));
        CompanyAddresses::dropBrands($companyId, array_keys($gone));

        return [array_keys($wanted), $events];
    }

    /** Archivia un brand: sparisce dalle scelte, resta sui documenti e sui legami. */
    public static function archive(int $brandId): bool
    {
        return self::setArchived($brandId, 'true');
    }

    /** Toglie un brand dall'archivio. */
    public static function restore(int $brandId): bool
    {
        return self::setArchived($brandId, 'false');
    }

    /**
     * Unisce un doppione in un altro brand: i legami con le aziende, con i
     * referenti e con gli indirizzi passano al brand di arrivo, quello di
     * partenza si archivia.
     * Se un'azienda ha già il brand di arrivo, tiene quel legame (riaperto se
     * serve, predefinito se lo era l'altro) e chiude l'altro. Emette
     * `BRAND_MERGED`: i moduli dipendenti rimappano lì i loro riferimenti.
     * Le linee del brand di partenza passano al brand di arrivo, o al suo
     * padre se è una linea; se il padre era proprio il brand di partenza, il
     * brand di arrivo perde il padre. Mai due livelli.
     * Rilanciarla dopo un'interruzione finisce il lavoro.
     *
     * Non controlla permessi: chi la espone (una pagina, un comando) li controlla.
     */
    public static function merge(int $fromId, int $intoId): bool
    {
        $from = self::find($fromId);
        $into = self::find($intoId);

        if ($from === null || $into === null || $fromId === $intoId || self::archived($into)) {
            return false;
        }

        Transaction::run(static function () use ($fromId, $intoId): void {
            $target = [];

            foreach (self::links(['brand_id' => $intoId]) as $link) {
                $target[(int) $link['company_id']] = $link;
            }

            $now = date('Y-m-d H:i:s');

            foreach (self::links(['brand_id' => $fromId]) as $link) {
                $kept = $target[(int) $link['company_id']] ?? null;

                if ($kept === null) {
                    CompanyBrandLink::query()->Update(CompanyBrandLink::$table, ['brand_id' => $intoId], 'id', (int) $link['id']);
                    continue;
                }

                if (!self::isOpen($link)) {
                    continue;
                }

                $values = self::isOpen($kept) ? [] : ['ended_at' => null];

                if (($link['is_default'] ?? 'false') === 'true') {
                    $values['is_default'] = 'true';
                }

                if ($values !== []) {
                    CompanyBrandLink::query()->Update(CompanyBrandLink::$table, $values, 'id', (int) $kept['id']);
                }

                CompanyBrandLink::query()->Update(CompanyBrandLink::$table, ['ended_at' => $now, 'is_default' => 'false'], 'id', (int) $link['id']);
            }

            self::moveChildren($fromId, $intoId);
            ContactRelations::moveBrand($fromId, $intoId);
            CompanyAddresses::moveBrand($fromId, $intoId);
            self::archive($fromId);
        });

        // Dopo il commit, come in `sync()`.
        CompanyEvents::dispatch(CompanyEvents::BRAND_MERGED, ['from_id' => $fromId, 'into_id' => $intoId, 'brand' => self::find($intoId) ?? $into]);

        return true;
    }

    /** Le linee di `$fromId` passano sotto `$intoId` o sotto il suo padre (vedi `merge()`). */
    private static function moveChildren(int $fromId, int $intoId): void
    {
        $into = self::find($intoId) ?? [];
        $target = self::parentId($into);

        if ($target === $fromId) {
            CompanyBrand::query()->Update(CompanyBrand::$table, ['parent_id' => 0], 'id', $intoId);
            $target = 0;
        }

        $target = $target > 0 ? $target : $intoId;

        foreach (self::childrenOf($fromId) as $child) {
            if ((int) $child['id'] !== $intoId) {
                CompanyBrand::query()->Update(CompanyBrand::$table, ['parent_id' => $target], 'id', (int) $child['id']);
            }
        }
    }

    /**
     * Tutti i brand non cancellati per id, letti una volta per richiesta: una
     * scrittura sulla tabella svuota il memo.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function allById(): array
    {
        return RequestMemo::remember(CompanyBrand::$table, 'options', static function (): array {
            $out = [];

            foreach (self::rows(self::safe(static fn () => CompanyBrand::find(['deleted' => 'false']))) as $row) {
                $out[(int) $row['id']] = $row;
            }

            return $out;
        });
    }

    /**
     * Fra più brand con lo stesso dominio, il padre se gli altri sono sue
     * linee; altrimenti null.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private static function familyHead(array $rows): ?array
    {
        $heads = array_values(array_filter($rows, static fn (array $row): bool => self::parentId($row) === 0));

        if (count($heads) !== 1) {
            return null;
        }

        foreach ($rows as $row) {
            if ($row !== $heads[0] && self::parentId($row) !== (int) $heads[0]['id']) {
                return null;
            }
        }

        return $heads[0];
    }

    /** @param array<int, array<string, mixed>> $byId */
    private static function fullLabelFrom(array $row, array $byId): string
    {
        $parent = $byId[self::parentId($row)] ?? null;

        return $parent !== null ? self::label($parent).' › '.self::label($row) : self::label($row);
    }

    private static function parentId(array $row): int
    {
        return (int) ($row['parent_id'] ?? 0);
    }

    /**
     * Il predefinito fra i brand richiesti: il primo indicato come tale,
     * altrimenti quello di prima se resta e la riga non dice il contrario.
     *
     * @param array<int, array{role: string, is_default: string|null}> $wanted
     * @param array<int, array<string, mixed>> $before
     */
    private static function chooseDefault(array $wanted, array $before): int
    {
        foreach ($wanted as $id => $row) {
            if ($row['is_default'] === 'true') {
                return $id;
            }
        }

        foreach ($wanted as $id => $row) {
            if ($row['is_default'] === null && isset($before[$id]) && self::isOpen($before[$id]) && ($before[$id]['is_default'] ?? 'false') === 'true') {
                return $id;
            }
        }

        return 0;
    }

    private static function setArchived(int $brandId, string $flag): bool
    {
        if (self::find($brandId) === null) {
            return false;
        }

        $result = CompanyBrand::query()->Update(CompanyBrand::$table, ['archived' => $flag], 'id', $brandId);

        return (bool) ($result->success ?? false);
    }

    /** @return array<string, mixed> */
    private static function payload(int $companyId, array $brand, string $role): array
    {
        return ['company_id' => $companyId, 'brand_id' => (int) $brand['id'], 'brand' => $brand, 'role' => $role];
    }

    private static function isOpen(array $link): bool
    {
        return trim((string) ($link['ended_at'] ?? '')) === '';
    }

    private static function archived(array $brand): bool
    {
        return ($brand['archived'] ?? 'false') === 'true';
    }

    private static function key(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? ''), 'UTF-8');
    }

    /**
     * I brand non cancellati con questi id, in una query.
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    private static function brandsById(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $out = [];

        foreach (self::rows(self::safe(static fn () => CompanyBrand::find(['id' => $ids]))) as $row) {
            $out[(int) $row['id']] = $row;
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function links(array $condition): array
    {
        return self::rows(self::safe(static fn () => CompanyBrandLink::find($condition + ['deleted' => 'false'], null, 'position', 'ASC')));
    }

    private static function safe(callable $read): mixed
    {
        try {
            return $read();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $rows): array
    {
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        return isset($rows['id']) ? [$rows] : array_values(array_filter($rows, 'is_array'));
    }
}
