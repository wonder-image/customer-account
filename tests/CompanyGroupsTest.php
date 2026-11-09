<?php
/** php tests/CompanyGroupsTest.php — gruppi di aziende: capogruppo a un livello, letture, campo del form validato lato server. */
declare(strict_types=1);

require __DIR__.'/support/FakeDb.php';
require __DIR__.'/bootstrap.php';

use Wonder\Plugin\CustomerAccount\Resources\CompanyResource;
use Wonder\Plugin\CustomerAccount\Support\CompanyGroups;
use Wonder\Plugin\CustomerAccount\Support\CompanyRecord;
use Wonder\Sql\Query;

$company = static fn (int $id, string $name, int $parent = 0, array $extra = []): array
    => $extra + ['id' => $id, 'code' => 'cmp_'.$id, 'business_name' => $name, 'parent_id' => $parent, 'active' => 'true', 'deleted' => 'false'];
// 1 capogruppo di 2 e 3; 4 da sola; 5 cancellata.
$seed = static fn (): array => ['companies' => [
    1 => $company(1, 'Steli Holding spa'),
    2 => $company(2, 'Steli Real Estate srl', 1),
    3 => $company(3, 'Steli Holiday srl', 1),
    4 => $company(4, 'Royal Immobili srl'),
    5 => $company(5, 'Chiusa srl', 0, ['deleted' => 'true']),
]];

check('letture: capogruppo, controllate e gruppo da qualunque azienda', function () use ($seed) {
    Query::reset($seed());

    return (int) CompanyGroups::parentOf(2)['id'] === 1 && CompanyGroups::parentOf(1) === null
        && array_column(CompanyGroups::childrenOf(1), 'id') === [2, 3]
        && CompanyGroups::groupIds(3) === [1, 2, 3] && CompanyGroups::groupIds(1) === [1, 2, 3]
        && CompanyGroups::groupIds(4) === [4] && CompanyGroups::groupIds(5) === [] && CompanyGroups::groupIds(99) === [];
});

check('regole a un livello: ogni rifiuto, e solo il resto si scrive', function () use ($seed) {
    Query::reset($seed());
    $problems = [
        CompanyGroups::problem(4, 4),   // sé stessa
        CompanyGroups::problem(4, 99),  // non esiste
        CompanyGroups::problem(4, 5),   // cancellata
        CompanyGroups::problem(4, 2),   // la capogruppo è una controllata
        CompanyGroups::problem(1, 4),   // ha controllate
        CompanyGroups::problem(0, 1),   // azienda nuova: va bene
        CompanyGroups::problem(2, 0),   // togliere la capogruppo: va bene
    ];
    $refused = !CompanyGroups::setParent(1, 4) && !CompanyGroups::setParent(4, 2) && !CompanyGroups::setParent(99, 1) && !CompanyGroups::setParent(5, 1);
    $moved = CompanyGroups::setParent(4, 1);
    $cleared = CompanyGroups::setParent(2, 0);

    return $problems === ['self', 'missing', 'missing', 'nested', 'has_children', '', ''] && $refused && $moved && $cleared
        && CompanyGroups::groupIds(4) === [1, 3, 4] && CompanyGroups::groupIds(2) === [2]
        && array_keys(CompanyGroups::options()) === [1, 2];
});

check('form dell\'azienda: campo «Capogruppo», validato anche dove il form non lo mostra', function () use ($seed) {
    Query::reset($seed());
    $_POST = [];
    $names = array_map(static fn ($field) => $field->name, CompanyResource::formSchema());
    $values = static fn (array $extra): array => $extra + ['business_name' => 'Royal Immobili srl', 'active' => 'true', 'is_customer' => 'true', 'is_supplier' => 'false', '_csrf' => \Wonder\Http\Csrf::token()];
    $ok = CompanyResource::mutateRequestValues($values(['parent_id' => '1']), 'update', 'backend', ['id' => 4]);
    $none = CompanyResource::mutateRequestValues($values(['parent_id' => '']), 'update', 'backend', ['id' => 4]);
    $absent = CompanyResource::mutateRequestValues($values([]), 'update', 'backend', ['id' => 4]);
    $errors = [];

    foreach ([['4', 4], ['2', 4], ['4', 1], ['5', 4], ['99', 4], ['1 OR 1', 4]] as [$parent, $id]) {
        try {
            CompanyResource::mutateRequestValues($values(['parent_id' => $parent]), 'update', 'backend', ['id' => $id]);
            $errors[] = 'ok';
        } catch (InvalidArgumentException $e) {
            $errors[] = $e->getMessage();
        }
    }

    return in_array('parent_id', $names, true)
        && $ok['parent_id'] === 1 && $none['parent_id'] === 0 && !array_key_exists('parent_id', $absent)
        && $errors === ['contacts.company_parent_self', 'contacts.company_parent_nested', 'contacts.company_parent_has_children', 'contacts.company_parent_missing', 'contacts.company_parent_missing', 'contacts.company_parent_missing'];
});

summary();
