<?php
/** php tests/ContactExportTest.php — colonne dell'export dei contatti. */
declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use Wonder\Plugin\CustomerAccount\Support\ContactExport;

$labels = ['type' => 'Tipo', 'name' => 'Nome', 'surname' => 'Cognome', 'email' => 'Email', 'phone' => 'Telefono', 'city' => 'Città', 'active' => 'Stato'];
$value = static function (array $column, array $row): string {
    return is_callable($column['value']) ? (string) ($column['value'])($row) : (string) ($row[$column['value']] ?? '');
};
$headers = static fn (array $columns): array => array_column($columns, 'label');

check('solo i campi con etichetta, nell\'ordine del file, tra ID e stato/creazione', function () use ($labels, $headers) {
    return $headers(ContactExport::columns($labels))
        === ['ID', 'Tipo', 'Nome', 'Cognome', 'Email', 'Telefono', 'Città', 'Stato', 'contacts.created'];
});

check('nome dell\'elenco e colonne in più prima dello stato', function () use ($labels, $headers, $value) {
    $columns = ContactExport::columns($labels, static fn (array $row): string => 'Sig. '.$row['surname'], [['label' => 'Ruolo', 'value' => 'role']]);
    $row = ['id' => 7, 'surname' => 'Rossi', 'role' => 'cliente'];
    return array_slice($headers($columns), 0, 2) === ['ID', 'contacts.contact']
        && $value($columns[1], $row) === 'Sig. Rossi'
        && $headers($columns)[count($columns) - 3] === 'Ruolo';
});

check('tipo e stato escono tradotti', function () use ($labels, $value) {
    $columns = array_column(array_map(null, array_column(ContactExport::columns($labels), 'label'), ContactExport::columns($labels)), 1, 0);
    return $value($columns['Tipo'], ['type' => 'business']) === 'components.forms.options.address_type.business'
        && $value($columns['Tipo'], ['type' => 'altro']) === 'altro'
        && $value($columns['Stato'], ['active' => 'false']) === 'contacts.disabled'
        && $value($columns['Stato'], ['active' => 'true']) === 'contacts.enabled';
});

check('i formati sono Excel e CSV', fn () => array_keys(ContactExport::FORMATS) === ['xlsx', 'csv']);

summary();
