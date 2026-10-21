<?php
/** php tests/ContactResourcesTest.php — Resource dei contatti e degli indirizzi: fallback, menu, API, pagine e token (database finto: il form degli indirizzi legge le schede). */
declare(strict_types=1);

require __DIR__.'/support/FakeDb.php';
require __DIR__.'/bootstrap.php';

use Wonder\App\Resource;
use Wonder\App\ResourceRegistry;
use Wonder\Plugin\CustomerAccount\Models\Contact;
use Wonder\Plugin\CustomerAccount\Resources\ContactAddressResource;
use Wonder\Plugin\CustomerAccount\Resources\ContactResource;

/** Una Resource di un altro modulo sulla stessa tabella (come i «Clienti» del gestionale). */
final class AltriContattiResource extends Resource
{
    public static string $model = Contact::class;
    public static function formSchema(): array { return []; }
    public static function tableSchema(): array { return []; }
}

/** Una sottoclasse della Resource generica non le toglie il menu. */
final class ContattiDelSitoResource extends ContactResource {}

// Il registro scopre le Resource dalle cartelle del sito: nei test si imposta a mano.
$registry = static function (array $classes): void {
    (new ReflectionProperty(ResourceRegistry::class, 'resources'))->setValue(null, $classes);
};
$resources = [ContactResource::class, ContactAddressResource::class];

check('le due Resource cedono la tabella a quelle dei moduli', function () use ($resources) {
    foreach ($resources as $resource) {
        if ($resource::isTableFallback() !== true) {
            return false;
        }
    }
    return true;
});

check('menu dei contatti: spento se un\'altra Resource usa la tabella, acceso senza o con una sottoclasse', function () use ($registry) {
    $registry(['contacts' => ContactResource::class, 'altri-contatti' => AltriContattiResource::class]);
    $hidden = ContactResource::navigationSchema()->get('enabled') === false;
    $registry(['contacts' => ContactResource::class, 'contatti-del-sito' => ContattiDelSitoResource::class]);
    $subclass = ContactResource::navigationSchema()->get('enabled') === true;
    $registry(['contacts' => ContactResource::class]);
    $alone = ContactResource::navigationSchema()->get('enabled') === true;
    ResourceRegistry::reset();
    return $hidden && $subclass && $alone;
});

check('API spente e nessuna pagina di eliminazione', function () use ($resources) {
    foreach ($resources as $resource) {
        if ($resource::apiSchema()->get('enabled') !== false || ($resource::pageSchema()->get('pages')['delete'] ?? null) !== false) {
            return false;
        }
    }
    return true;
});

check('i form portano il token _contact_csrf', function () use ($resources) {
    foreach ($resources as $resource) {
        $names = array_map(static fn ($field) => $field->name, $resource::formSchema());
        if (!in_array('_contact_csrf', $names, true)) {
            return false;
        }
    }
    return true;
});

summary();
