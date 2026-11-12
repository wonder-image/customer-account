<?php
/** php tests/ContactBoxSafetyTest.php — riquadro «Contatti», regole di sicurezza: email di accesso con e senza marker (e i percorsi dell'account che la riscrivono), valori storici mai persi in silenzio, aree orfane o sconosciute (database finto). */
declare(strict_types=1);

require __DIR__.'/support/FakeDb.php';
require __DIR__.'/bootstrap.php';

// Il riquadro a righe è quello con il CRM acceso (spento: un'email e un telefono soli, vedi CrmContactsTest).
\Wonder\Plugin\CustomerAccount\Support\Crm::enable();
// I prefissi noti del framework (`phonePrefix()`), per separare quello di un telefono storico.
require_once WI_APP_ROOT.'/app/function/other/geo.php';

// Gli helper globali che Repeater::syncRelatedRows() usa, sopra il FakeDb.
if (!function_exists('sqlSelect')) {
    function sqlSelect($table, $condition = null, $limit = null, $order = null, $orderDirection = null, $attributes = '*') { return (new \Wonder\Sql\Query)->Select($table, $condition, $limit, $order, $orderDirection, $attributes); }
    function sqlInsert($table, $values) { return (new \Wonder\Sql\Query)->Insert($table, $values); }
    function sqlModify($table, $values, $column, $value) { return (new \Wonder\Sql\Query)->Update($table, $values, $column, $value); }
    function sqlDelete($table, $condition = null) { return (new \Wonder\Sql\Query)->Delete($table, $condition); }
}

use Wonder\Http\Csrf;
use Wonder\Plugin\CustomerAccount\Account\ContactAccount;
use Wonder\Plugin\CustomerAccount\Account\CustomerAccountExtension;
use Wonder\Plugin\CustomerAccount\Auth\CustomerAuthProfile;
use Wonder\Plugin\CustomerAccount\Auth\SignupFields;
use Wonder\Plugin\CustomerAccount\Resources\CompanyResource;
use Wonder\Plugin\CustomerAccount\Resources\ContactResource;
use Wonder\Plugin\CustomerAccount\Support\ContactPoints;
use Wonder\Sql\Query;

/** Una Resource di un sito o di un modulo che estende quella del modulo. */
final class SottoclasseResource extends ContactResource
{
    public static function scarta(array $values, ?array $oldValues): array { return static::withoutAccessEmail($values, $oldValues); }
}

final class ProfiloRegistrazione extends CustomerAuthProfile
{
    public function signupFields(): array { return SignupFields::resolve([]); }
}

$seed = static fn (): array => [
    'contacts' => [
        2 => ['id' => 2, 'name' => 'Anna', 'surname' => 'Bianchi', 'email' => 'anna@rossi.test', 'phone_prefix' => '+39', 'phone' => '3331112222', 'user_id' => 0, 'deleted' => 'false'],
        3 => ['id' => 3, 'name' => 'Ada', 'surname' => 'Lovelace', 'email' => 'ada@example.com', 'phone_prefix' => '', 'phone' => '', 'user_id' => 7, 'deleted' => 'false'],
    ],
    'companies' => [
        2 => ['id' => 2, 'business_name' => 'Rossi srl', 'email' => 'info@rossi.test', 'phone_prefix' => '', 'phone' => '', 'deleted' => 'false'],
    ],
    'contact_points' => [],
    'company_points' => [],
    'company_point_departments' => [],
    'contact_relations' => [],
    'external_references' => [],
];
$_SESSION = [];
$_POST = [];
$token = Csrf::token();
$base = ['name' => 'Ada', 'surname' => 'Lovelace', 'active' => 'true', '_csrf' => $token];
$reject = static function (callable $fn): ?string {
    try {
        $fn();
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }

    return null;
};
$card = static fn (string $table, int $id): array => Query::$tables[$table][$id];
$GLOBALS['__fake_users'] = [['id' => 7, 'email' => 'ada.nuova@example.com', 'name' => 'Ada', 'surname' => 'Lovelace']];

check('email di accesso senza marker: l\'email postata dal backend si scarta (persone con account), passa per le altre', function () use ($seed, $base) {
    Query::reset($seed());
    $locked = ContactResource::mutateRequestValues($base + ['email' => 'altra@example.com'], 'update', 'backend', Query::$tables['contacts'][3]);
    $free = ContactResource::mutateRequestValues(['name' => 'Anna', 'surname' => 'Bianchi'] + $base + ['email' => 'nuova@rossi.test'], 'update', 'backend', Query::$tables['contacts'][2]);
    $company = CompanyResource::mutateRequestValues(['business_name' => 'Rossi srl', 'active' => 'true', 'is_customer' => 'true', 'is_supplier' => 'false', '_csrf' => $base['_csrf'], 'email' => 'nuova@rossi.test'], 'update', 'backend', ['user_id' => 7] + Query::$tables['companies'][2]);

    return !array_key_exists('email', $locked) && $locked['name'] === 'Ada'
        && $free['email'] === 'nuova@rossi.test' && $company['email'] === 'nuova@rossi.test';
});

check('email di accesso: la regola sta in ContactResource::mutateRequestValues() e la ereditano le sottoclassi; withoutAccessEmail() per chi ha un suo mutateRequestValues()', function () use ($seed, $base) {
    Query::reset($seed());
    $sub = SottoclasseResource::mutateRequestValues($base + ['email' => 'altra@example.com', 'contact_points_form' => '1'], 'update', 'backend', Query::$tables['contacts'][3]);

    return !array_key_exists('email', $sub)
        && SottoclasseResource::scarta(['email' => 'x@y.test', 'name' => 'Ada'], Query::$tables['contacts'][3]) === ['name' => 'Ada']
        && SottoclasseResource::scarta(['email' => 'x@y.test'], Query::$tables['contacts'][2]) === ['email' => 'x@y.test']
        && SottoclasseResource::scarta(['email' => 'x@y.test'], null) === ['email' => 'x@y.test'];
});

check('i percorsi dell\'account riscrivono l\'email di accesso: ContactAccount::link()', function () use ($seed, $card) {
    Query::reset($seed());
    $link = ContactAccount::link(7);

    return $link->success && $link->contact_id === 3 && $card('contacts', 3)['email'] === 'ada.nuova@example.com';
});

check('i percorsi dell\'account riscrivono l\'email di accesso: cambio email dal pannello', function () use ($seed, $card) {
    Query::reset($seed());
    (new CustomerAccountExtension())->afterEmailConfirmed(7, 'ada.pannello@example.com');

    return $card('contacts', 3)['email'] === 'ada.pannello@example.com';
});

check('i percorsi dell\'account riscrivono l\'email di accesso: registrazione (email.verify)', function () use ($seed, $card) {
    Query::reset($seed());
    (new ProfiloRegistrazione())->afterUserSaved(7, 'email.verify', []);

    return $card('contacts', 3)['email'] === 'ada.nuova@example.com' && (int) $card('contacts', 3)['user_id'] === 7;
});

check('telefono storico: «+39 333…» si separa col prefisso noto; un numero non riconosciuto si mostra grezzo', function () use ($seed) {
    $tables = $seed();
    $tables['contacts'][2] = ['phone_prefix' => '', 'phone' => '+39 333 111-2222'] + $tables['contacts'][2];
    $tables['contacts'][3] = ['phone_prefix' => '+39', 'phone' => '12 int. 4', 'email' => 'Mario Rossi@x.it'] + $tables['contacts'][3];
    Query::reset($tables);
    $anna = ContactPoints::all(ContactPoints::CONTACT, 2);
    $ada = ContactPoints::all(ContactPoints::CONTACT, 3);

    return $anna[1] === ['kind' => 'phone', 'value' => '3331112222', 'phone_prefix' => '+39', 'label' => '', 'is_primary' => true, 'locked' => false, 'access' => false, 'departments' => [], 'department' => '']
        && $ada[0]['value'] === 'Mario Rossi@x.it' && $ada[0]['locked'] === true
        && $ada[1]['value'] === '12 int. 4' && $ada[1]['phone_prefix'] === '+39';
});

check('valori storici: riportati com\'erano o non postati, le colonne restano; nessun errore nel form', function () use ($seed, $card, $base) {
    $tables = $seed();
    $tables['contacts'][2] = ['email' => 'anna rossi@x.it', 'phone_prefix' => 'abc', 'phone' => '333 1112222'] + $tables['contacts'][2];
    Query::reset($tables);
    $rows = ContactPoints::formRows(ContactPoints::all(ContactPoints::CONTACT, 2));
    // Il form riporta le righe com'erano (il prefisso storto non è fra le scelte: arriva vuoto).
    $rows[1]['phone_prefix'] = '';
    $values = ContactResource::mutateRequestValues(['name' => 'Anna', 'surname' => 'Bianchi', 'contact_box_form' => '1', 'contact_points' => $rows] + $base, 'update', 'backend', Query::$tables['contacts'][2]);
    ContactPoints::syncAll(ContactPoints::CONTACT, 2, $rows);
    $same = $card('contacts', 2);
    // Nessuna riga postata: niente cambia.
    ContactPoints::syncAll(ContactPoints::CONTACT, 2, []);
    $none = $card('contacts', 2);

    return !array_key_exists('email', $values) && !array_key_exists('phone', $values) && !array_key_exists('phone_prefix', $values)
        && $same['email'] === 'anna rossi@x.it' && $same['phone'] === '333 1112222' && $same['phone_prefix'] === 'abc'
        && $none['email'] === 'anna rossi@x.it' && $none['phone'] === '333 1112222';
});

check('valori storici: un\'altra riga dello stesso tipo resta normale; cambiare il valore storico è il gesto che lo sostituisce', function () use ($seed, $card) {
    $tables = $seed();
    $tables['contacts'][2] = ['phone_prefix' => '', 'phone' => '12'] + $tables['contacts'][2];
    Query::reset($tables);
    $split = ContactPoints::split(ContactPoints::CONTACT, [
        ['kind' => 'phone', 'phone' => '12'],
        ['kind' => 'phone', 'phone' => '021234567', 'is_primary' => 'true'],
    ], Query::$tables['contacts'][2]);
    ContactPoints::syncAll(ContactPoints::CONTACT, 2, [['kind' => 'phone', 'phone' => '12'], ['kind' => 'phone', 'phone' => '021234567', 'is_primary' => 'true']]);
    $kept = $card('contacts', 2)['phone'];
    $points = array_column(ContactPoints::of(ContactPoints::CONTACT, 2), 'value');
    ContactPoints::syncAll(ContactPoints::CONTACT, 2, [['kind' => 'phone', 'phone' => '021234567']]);

    return $split['kept'] === ['phone'] && !array_key_exists('phone', $split['columns']) && $split['problems'] === []
        && $split['rows'][0]['is_primary'] === false
        && $kept === '12' && $points === ['021234567']
        && $card('contacts', 2)['phone'] === '021234567';
});

check('riga del riquadro: la colonna dell\'altro tipo disabilitata (non postata) non svuota niente', function () {
    $split = ContactPoints::split(ContactPoints::CONTACT, [
        ['kind' => 'email', 'email' => 'a@rossi.test', 'label' => 'work'],
        ['kind' => 'phone', 'phone_prefix' => '+39', 'phone' => '021234567'],
    ]);

    return $split['problems'] === [] && $split['columns'] === ['email' => 'a@rossi.test', 'phone_prefix' => '+39', 'phone' => '021234567'];
});

check('aree orfane: l\'email principale cambiata dai campi singoli porta via le aree del valore vecchio; un contatto nuovo non le eredita', function () use ($seed) {
    $tables = $seed();
    $tables['company_point_departments'] = [
        1 => ['id' => 1, 'company_id' => 2, 'kind' => 'email', 'value' => 'info@rossi.test', 'department' => 'sales', 'deleted' => 'false'],
        // Rimasta da un valore che non c'è più (un import l'ha cambiato).
        2 => ['id' => 2, 'company_id' => 2, 'kind' => 'email', 'value' => 'vecchia@rossi.test', 'department' => 'administration', 'deleted' => 'false'],
    ];
    Query::reset($tables);
    // Una casella nuova con quel valore, da «Altri contatti» (senza aree): non eredita.
    ContactPoints::sync(ContactPoints::COMPANY, 2, [['kind' => 'email', 'value' => 'vecchia@rossi.test']]);
    $inherited = ContactPoints::companyEmailsFor(2, 'administration');
    // Campi singoli dal backend: la Resource salva la scheda, poi syncRepeaterRelations senza marker.
    Query::$tables['companies'][2]['email'] = 'nuova@rossi.test';
    CompanyResource::syncRepeaterRelations(2, []);

    return $inherited === ['vecchia@rossi.test']
        && array_column(Query::$tables['company_point_departments'], 'value') === []
        && ContactPoints::dropOrphanDepartments(2) === 0 && ContactPoints::dropOrphanDepartments(99) === 0;
});

check('aree orfane: syncAll toglie quelle di un valore che non è più un contatto; dropOrphanDepartments() per chi scrive da fuori', function () use ($seed) {
    $tables = $seed();
    $tables['company_point_departments'] = [
        1 => ['id' => 1, 'company_id' => 2, 'kind' => 'email', 'value' => 'altra@rossi.test', 'department' => 'sales', 'deleted' => 'false'],
    ];
    Query::reset($tables);
    $dropped = ContactPoints::dropOrphanDepartments(2);
    Query::$tables['company_point_departments'][5] = ['id' => 5, 'company_id' => 2, 'kind' => 'email', 'value' => 'altra@rossi.test', 'department' => 'sales', 'deleted' => 'false'];
    // altra@ arriva come riga nuova senza la chiave delle aree: niente eredità.
    ContactPoints::syncAll(ContactPoints::COMPANY, 2, [['kind' => 'email', 'email' => 'info@rossi.test'], ['kind' => 'email', 'email' => 'altra@rossi.test']]);

    return $dropped === 1 && Query::$tables['company_point_departments'] === []
        && ContactPoints::companyEmailsFor(2, 'administration') === ['info@rossi.test', 'altra@rossi.test'];
});

check('aree sconosciute: la casella non diventa «di tutto»; il form non le toglie; restano solo nel ripiego generale', function () use ($seed) {
    $tables = $seed();
    $tables['company_points'][1] = ['id' => 1, 'company_id' => 2, 'kind' => 'email', 'value' => 'marketing@rossi.test', 'phone_prefix' => '', 'label' => '', 'position' => 0, 'deleted' => 'false'];
    $tables['company_point_departments'][1] = ['id' => 1, 'company_id' => 2, 'kind' => 'email', 'value' => 'marketing@rossi.test', 'department' => 'area_tolta', 'deleted' => 'false'];
    Query::reset($tables);
    $shown = ContactPoints::all(ContactPoints::COMPANY, 2)[1]['departments'];
    $before = [ContactPoints::companyEmailsFor(2, 'administration'), ContactPoints::companyEmailsFor(2)];
    // Il form mostra il select vuoto e lo riposta vuoto: l'area sconosciuta resta.
    ContactPoints::syncAll(ContactPoints::COMPANY, 2, [['kind' => 'email', 'email' => 'info@rossi.test'], ['kind' => 'email', 'email' => 'marketing@rossi.test', 'departments' => ['']]]);

    return $shown === [] && $before === [['info@rossi.test'], ['info@rossi.test', 'marketing@rossi.test']]
        && ContactPoints::companyEmailsFor(2, 'administration') === ['info@rossi.test']
        && array_column(Query::$tables['company_point_departments'], 'department') === ['area_tolta'];
});

summary();
