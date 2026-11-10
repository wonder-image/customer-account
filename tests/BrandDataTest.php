<?php
/** php tests/BrandDataTest.php — dati del brand e brand kit: URL, dominio, colori, social, riconoscimento da email, form, logo, record (database finto). */
declare(strict_types=1);

require __DIR__.'/support/FakeDb.php';
require __DIR__.'/bootstrap.php';

use Wonder\Plugin\CustomerAccount\Models\CompanyBrand;
use Wonder\Plugin\CustomerAccount\Resources\CompanyBrandResource;
use Wonder\Plugin\CustomerAccount\Support\BrandData;
use Wonder\Plugin\CustomerAccount\Support\CompanyBrands;
use Wonder\Plugin\CustomerAccount\Support\CompanyRecord;
use Wonder\Sql\Query;

$brand = static fn (int $id, string $name, array $extra = []): array
    => $extra + ['id' => $id, 'code' => 'cbr_'.$id, 'name' => $name, 'slug' => 'b'.$id, 'name_key' => 'b'.$id, 'archived' => 'false', 'parent_id' => 0, 'deleted' => 'false'];
$reject = static function (callable $fn): ?string {
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }

    return null;
};

check('url: solo http e https, senza credenziali né caratteri da HTML; un dominio nudo prende https', function () {
    return BrandData::url('https://lapiana.it/menu?a=1&b=2') === 'https://lapiana.it/menu?a=1&b=2'
        && BrandData::url(' www.lapiana.it/menu ') === 'https://www.lapiana.it/menu'
        && BrandData::url('') === ''
        && BrandData::url('javascript:alert(1)') === null
        && BrandData::url('JavaScript://lapiana.it/%0Aalert(1)') === null
        && BrandData::url('data:text/html,<script>alert(1)</script>') === null
        && BrandData::url('ftp://lapiana.it') === null
        && BrandData::url('https://utente:segreta@lapiana.it') === null
        && BrandData::url('https://lapiana.it/"onmouseover="alert(1)') === null
        && BrandData::url('//lapiana.it') === null
        && BrandData::url(['https://lapiana.it']) === '';
});

check('domain: minuscolo, senza schema, www, porta né percorso; un nome senza punto non vale', function () {
    return BrandData::domain('https://www.LaPiana.it/menu') === 'lapiana.it'
        && BrandData::domain('WWW.lapiana.it.') === 'lapiana.it'
        && BrandData::domain('lapiana.it:8080/x') === 'lapiana.it'
        && BrandData::domain('news.lapiana.co.uk') === 'news.lapiana.co.uk'
        && BrandData::domain('') === ''
        && BrandData::domain('lapiana') === null && BrandData::domain('la piana.it') === null
        && BrandData::domain('<script>.it') === null;
});

check('colori, social e lingua: formati validati, chiavi dalla lista estendibile', function () {
    BrandData::addSocial('Threads', 'Threads');
    BrandData::addSocial('no spazi', 'X');
    $many = implode(',', array_fill(0, 13, '#000')).',#111';

    return BrandData::colors('#FFF, 1a2b3c;#1A2B3C') === ['#ffffff', '#1a2b3c']
        && BrandData::colors(['#abcdef']) === ['#abcdef'] && BrandData::colors('') === []
        && BrandData::colors('red') === null && BrandData::colors('#12345') === null && BrandData::colors('url(x)') === null
        && count(BrandData::colors(implode(',', ['#000', '#111', '#222', '#333', '#444', '#555', '#666', '#777', '#888', '#999', '#aaa', '#bbb']))) === 12
        && BrandData::colors(implode(',', ['#000', '#111', '#222', '#333', '#444', '#555', '#666', '#777', '#888', '#999', '#aaa', '#bbb', '#ccc'])) === null
        && BrandData::colors($many) === ['#000000', '#111111']
        && BrandData::socialLinks([['network' => 'instagram', 'url' => 'instagram.com/lapiana'], ['network' => 'facebook', 'url' => ''], ['network' => 'threads', 'url' => 'https://threads.net/@lapiana']])
            === ['instagram' => 'https://instagram.com/lapiana', 'threads' => 'https://threads.net/@lapiana']
        && BrandData::socialLinks(['linkedin' => 'https://linkedin.com/company/x']) === ['linkedin' => 'https://linkedin.com/company/x']
        && BrandData::socialLinks([['network' => 'myspace', 'url' => 'https://myspace.com/x']]) === null
        && BrandData::socialLinks([['network' => 'instagram', 'url' => 'javascript:alert(1)']]) === null
        && !array_key_exists('no spazi', BrandData::socials())
        && BrandData::language('it_it') === 'it-IT' && BrandData::language('EN') === 'en' && BrandData::language('italiano') === null;
});

check('clean: kit spento ignora i suoi campi, dominio ricavato dal sito (mai da una casella generica), errori per campo', function () {
    [$off, $offErrors] = BrandData::clean(['website' => 'https://www.lapiana.it', 'email' => 'x@lapiana.it', 'colors' => 'red', 'description' => 'Ristorante'], false);
    [$on, $onErrors] = BrandData::clean([
        'website' => 'javascript:alert(1)', 'email' => 'ordini@lapiana.it', 'phone_prefix' => '0039', 'phone' => '02 123 456',
        'colors' => '#fff', 'socials' => [['network' => 'facebook', 'url' => 'https://facebook.com/lapiana']], 'assets_url' => 'data:x', 'sector' => str_repeat('x', 101),
    ], true);
    [$gmail] = BrandData::clean(['website' => 'https://gmail.com'], false);
    [$kept] = BrandData::clean(['website' => 'https://www.lapiana.it', 'domain' => 'piana.com'], false);

    return $off === ['website' => 'https://www.lapiana.it', 'description' => 'Ristorante', 'domain' => 'lapiana.it'] && $offErrors === []
        && $onErrors === ['website' => 'invalid', 'sector' => 'invalid', 'assets_url' => 'invalid']
        && $on['email'] === 'ordini@lapiana.it' && $on['phone_prefix'] === '+39' && $on['phone'] === '02123456'
        && $on['colors'] === '[{"color":"#ffffff","name":""}]' && $on['socials'] === '{"facebook":"https://facebook.com/lapiana"}'
        && !isset($gmail['domain']) && $kept['domain'] === 'piana.com';
});

check('byDomain: email o dominio, sottodomini, mai una casella generica né un brand archiviato; doppioni solo fra padre e linee', function () use ($brand) {
    Query::reset(['company_brands' => [
        1 => $brand(1, 'La Piana', ['domain' => 'lapiana.it']),
        2 => $brand(2, 'Piana Mare', ['domain' => 'lapiana.it', 'parent_id' => 1]),
        3 => $brand(3, 'Truffa', ['domain' => 'gmail.com']),
        4 => $brand(4, 'Chiuso', ['domain' => 'chiuso.it', 'archived' => 'true']),
        5 => $brand(5, 'Uno', ['domain' => 'doppio.it']),
        6 => $brand(6, 'Due', ['domain' => 'doppio.it']),
        7 => $brand(7, 'Shop', ['domain' => 'shop.lapiana.it', 'parent_id' => 1]),
        8 => $brand(8, 'Posta', ['domain' => 'posta-mia.it']),
    ]]);
    BrandData::addMailProvider('https://www.Posta-Mia.it');
    $id = static fn (?array $row): int => (int) ($row['id'] ?? 0);

    return $id(CompanyBrands::byDomain('Ordini@LaPiana.it')) === 1
        && $id(CompanyBrands::byDomain('news@mail.lapiana.it')) === 1
        && $id(CompanyBrands::byDomain('x@shop.lapiana.it')) === 7
        && $id(CompanyBrands::byDomain('https://www.lapiana.it/menu')) === 1
        && CompanyBrands::byDomain('mario.rossi@gmail.com') === null
        && CompanyBrands::byDomain('x@posta-mia.it') === null
        && CompanyBrands::byDomain('x@chiuso.it') === null
        && CompanyBrands::byDomain('x@doppio.it') === null
        && CompanyBrands::byDomain('x@altro.it') === null && CompanyBrands::byDomain('') === null && CompanyBrands::byDomain('non un dominio') === null
        && BrandData::isMailProvider('GMAIL.com') && !BrandData::isMailProvider('lapiana.it');
});

check('form del brand: due gruppi, il kit solo se acceso; URL non validi rifiutati; il kit spento non si salva', function () use ($reject) {
    Query::reset(['company_brands' => []]);
    $names = static fn (): array => array_map(static fn ($field) => $field->name, CompanyBrandResource::formSchema());
    $off = $names();
    $offCards = count(CompanyBrandResource::formLayoutSchema()->components);
    $values = CompanyBrandResource::mutateRequestValues(['name' => 'La Piana', 'website' => 'lapiana.it', 'email' => 'x@lapiana.it', 'colors' => '#fff'], 'store');
    $bad = $reject(static fn () => CompanyBrandResource::mutateRequestValues(['name' => 'La Piana', 'website' => 'javascript:alert(1)'], 'store'));
    CompanyBrands::useKit();
    $on = $names();
    $onCards = count(CompanyBrandResource::formLayoutSchema()->components);
    $kit = CompanyBrandResource::mutateRequestValues(['name' => 'La Piana', 'email' => 'X@LaPiana.it', 'colors' => '#fff, #000', 'socials' => ['row_1' => ['network' => 'instagram', 'url' => 'https://instagram.com/lapiana']]], 'store');
    $badColor = $reject(static fn () => CompanyBrandResource::mutateRequestValues(['name' => 'La Piana', 'colors' => 'expression(alert(1))'], 'store'));
    $form = CompanyBrandResource::mutateFormValues(['id' => 1, 'colors' => '["#ffffff","#000000"]', 'socials' => '{"instagram":"https://instagram.com/lapiana"}'], 'edit');
    CompanyBrands::useKit(false);

    return !in_array('email', $off, true) && in_array('logo', $off, true) && in_array('website', $off, true)
        && in_array('socials', $on, true) && in_array('colors', $on, true) && CompanyBrands::kitEnabled() === false
        && $offCards === 1 && $onCards === 2
        && $values['website'] === 'https://lapiana.it' && $values['domain'] === 'lapiana.it' && !isset($values['email']) && !isset($values['colors'])
        && $bad === 'contacts.brand_invalid' && $badColor === 'contacts.brand_invalid'
        && $kit['email'] === 'x@lapiana.it' && $kit['colors'] === '[{"color":"#ffffff","name":""},{"color":"#000000","name":""}]' && $kit['socials'] === '{"instagram":"https://instagram.com/lapiana"}'
        && $form['colors'] === [['color' => '#ffffff', 'name' => ''], ['color' => '#000000', 'name' => '']] && $form['socials'] === [['network' => 'instagram', 'url' => 'https://instagram.com/lapiana']];
});

check('palette: nomi dei colori puliti e tenuti, doppioni col primo nome, vecchie liste di stringhe rilette', function () {
    $palette = BrandData::palette([
        ['color' => '#C00', 'name' => ' Rosso Piana '],
        ['color' => '#cc0000', 'name' => 'Doppione'],
        ['color' => '#000000'],
    ]);
    $legacy = BrandData::palette(['#ffffff', '#000']);

    return $palette === [['color' => '#cc0000', 'name' => 'Rosso Piana'], ['color' => '#000000', 'name' => '']]
        && $legacy === [['color' => '#ffffff', 'name' => ''], ['color' => '#000000', 'name' => '']]
        && BrandData::palette([['color' => 'red', 'name' => 'x']]) === null
        && BrandData::colors([['color' => '#fff', 'name' => 'Bianco']]) === ['#ffffff'];
});

check('domini, recapiti, settore e lingua: liste pulite, primo valore nelle colonne singole, vecchi record riletti', function () use ($brand) {
    $domains = BrandData::domains(['row_1' => ['domain' => 'LaPiana.it'], 'row_2' => ['domain' => 'https://www.lapiana.it/x'], 'row_3' => ['domain' => 'piana.com'], 'row_4' => ['domain' => '']]);
    $contacts = BrandData::contacts([
        ['kind' => 'email', 'email' => 'Ordini@LaPiana.it', 'label' => 'Ordini'],
        ['kind' => 'phone', 'phone_prefix' => '+39', 'phone' => '02 123 456'],
        ['kind' => 'email', 'email' => 'ordini@lapiana.it'],
        ['kind' => 'email', 'email' => ''],
    ]);
    $badContact = BrandData::contacts([['kind' => 'email', 'email' => 'non-una-email']]);
    [$clean, $errors] = BrandData::clean(['domains' => [['domain' => 'lapiana.it'], ['domain' => 'piana.com']], 'contacts' => [['kind' => 'email', 'email' => 'x@lapiana.it'], ['kind' => 'phone', 'phone_prefix' => '+39', 'phone' => '02123456']]], true);
    $legacy = $brand(1, 'La Piana', ['domain' => 'lapiana.it', 'email' => 'x@lapiana.it', 'phone_prefix' => '+39', 'phone' => '02123456']);

    return $domains === ['lapiana.it', 'piana.com'] && $errors === []
        && $contacts === [['kind' => 'email', 'email' => 'ordini@lapiana.it', 'label' => ''], ['kind' => 'phone', 'phone_prefix' => '+39', 'phone' => '02123456', 'label' => '']]
        && $badContact === null
        && $clean['domain'] === 'lapiana.it' && json_decode($clean['domains'], true) === ['lapiana.it', 'piana.com']
        && $clean['email'] === 'x@lapiana.it' && $clean['phone'] === '02123456' && $clean['phone_prefix'] === '+39'
        && BrandData::domainsOf($legacy) === ['lapiana.it']
        && count(BrandData::contactsOf($legacy)) === 2
        && BrandData::sector('c') === 'C' && BrandData::sector('Ristorazione') === 'Ristorazione' && count(BrandData::sectors()) === 21
        && isset(BrandData::languages()['it']) && BrandData::DEFAULT_LANGUAGE === 'it';
});

check('logo: passa solo un file caricato; nel record è un percorso, mai un URL o un percorso scritti a mano', function () use ($brand) {
    $upload = ['name' => ['logo.svg'], 'type' => ['image/svg+xml'], 'tmp_name' => ['/tmp/x'], 'error' => [0], 'size' => [10]];
    $file = CompanyBrandResource::mutateRequestValues(['name' => 'A', 'logo' => $upload, 'logo__wi_files' => '[0]'], 'store');
    $text = CompanyBrandResource::mutateRequestValues(['name' => 'B', 'logo' => 'javascript:alert(1)', 'logo__wi_files' => ['x']], 'store');
    $logo = null;

    foreach (CompanyBrand::dataSchema() as $field) {
        if ((string) $field->key === 'logo') {
            $logo = $field;
        }
    }

    return $file['logo'] === $upload && $file['logo__wi_files'] === '[0]'
        && !array_key_exists('logo', $text) && !array_key_exists('logo__wi_files', $text)
        && in_array('svg', (array) ($logo->schema['extensions'] ?? []), true) && ($logo->schema['max_file'] ?? null) === 1
        && CompanyBrand::logoPath(['logo' => '["la-piana.png"]']) === 'contacts/brand-logos/la-piana.png'
        && CompanyBrand::logoPath(['logo' => '["../../config.php"]']) === ''
        && CompanyBrand::logoPath(['logo' => '["https://evil.test/x.svg"]']) === ''
        && CompanyBrand::logoPath(['logo' => '']) === '' && CompanyBrand::logoUrl(['logo' => '["../x"]']) === '';
});

check('CompanyRecord: i brand portano i loro dati, riletti con le regole', function () use ($brand) {
    Query::reset([
        'companies' => [1 => ['id' => 1, 'business_name' => 'Steli srl', 'deleted' => 'false']],
        'company_brands' => [1 => $brand(1, 'La Piana', [
            'logo' => '["b1.png"]', 'website' => 'https://lapiana.it', 'domain' => 'lapiana.it', 'description' => 'Ristorante &amp; bar',
            'email' => 'ordini@lapiana.it', 'phone_prefix' => '+39', 'phone' => '02123456', 'tone' => 'Caldo', 'sector' => 'Ristorazione', 'language' => 'it',
            'socials' => '{"instagram":"https://instagram.com/lapiana","facebook":"javascript:alert(1)"}', 'colors' => '["#ffffff","red"]', 'assets_url' => 'javascript:x',
        ])],
        'company_brand_links' => [1 => ['id' => 1, 'company_id' => 1, 'brand_id' => 1, 'role' => 'owner', 'is_default' => 'true', 'position' => 0, 'ended_at' => null, 'deleted' => 'false']],
        'contact_relations' => [], 'company_points' => [], 'external_references' => [],
    ]);
    $record = CompanyRecord::from(1)['brands'][0];

    return $record['logo'] === 'contacts/brand-logos/b1.png' && $record['website'] === 'https://lapiana.it' && $record['domain'] === 'lapiana.it'
        && $record['description'] === 'Ristorante & bar' && $record['email'] === 'ordini@lapiana.it' && $record['phone'] === '02123456'
        && $record['socials'] === [] && $record['colors'] === [] && $record['assets_url'] === ''
        && $record['tone'] === 'Caldo' && $record['language'] === 'it' && array_key_exists('logo_url', $record);
});

check('dominio dal sito: mai da un social o da un servizio di siti; caselle generiche per paese e PEC', function () {
    $derived = static fn (string $site): string => (string) (BrandData::clean(['website' => $site], false)[0]['domain'] ?? '');
    BrandData::addSiteHost('vetrine-mie.it');

    return $derived('https://www.facebook.com/lapiana') === '' && $derived('https://lapiana.wixsite.com/menu') === ''
        && $derived('https://sites.google.com/view/lapiana') === '' && $derived('https://linktr.ee/lapiana') === ''
        && $derived('https://shop.vetrine-mie.it/lapiana') === '' && $derived('https://www.lapiana.it') === 'lapiana.it'
        && BrandData::isMailProvider('yahoo.co.uk') && BrandData::isMailProvider('hotmail.fr') && BrandData::isMailProvider('gmx.de')
        && BrandData::isMailProvider('pec.libero.it') && BrandData::isMailProvider('arubapec.it') && BrandData::isMailProvider('web.de')
        && !BrandData::isMailProvider('live.events') && !BrandData::isMailProvider('yahoostore.it')
        && BrandData::isSiteHost('instagram.com') && !BrandData::isSiteHost('lapiana.it');
});

check('byDomain: un brand con dominio di un social o di una casella generica non si riconosce mai', function () use ($brand) {
    Query::reset(['company_brands' => [
        1 => $brand(1, 'Social', ['domain' => 'facebook.com']),
        2 => $brand(2, 'Yahoo', ['domain' => 'yahoo.co.uk']),
        3 => $brand(3, 'La Piana', ['domain' => 'lapiana.it']),
    ]]);

    return CompanyBrands::byDomain('x@facebook.com') === null && CompanyBrands::byDomain('x@mail.facebook.com') === null
        && CompanyBrands::byDomain('x@yahoo.co.uk') === null && (int) CompanyBrands::byDomain('x@lapiana.it')['id'] === 3;
});

check('form del brand: valori non scalari dalla richiesta valgono vuoto', function () use ($reject) {
    Query::reset(['company_brands' => []]);
    $name = $reject(static fn () => CompanyBrandResource::mutateRequestValues(['name' => ['x']], 'store'));
    $values = CompanyBrandResource::mutateRequestValues(['name' => 'A', 'note' => ['x'], 'description' => ['y'], 'website' => ['z']], 'store');
    $quick = CompanyBrandResource::quickCreateStore(['name' => ['x']], 'name');

    return $name === 'contacts.brand_required' && $values['note'] === '' && $values['description'] === '' && $values['website'] === ''
        && isset($quick['error']);
});

summary();
