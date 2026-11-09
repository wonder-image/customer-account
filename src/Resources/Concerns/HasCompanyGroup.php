<?php

namespace Wonder\Plugin\CustomerAccount\Resources\Concerns;

use InvalidArgumentException;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\Input;
use Wonder\Plugin\CustomerAccount\Support\CompanyGroups;

/**
 * La «Capogruppo» di una Resource delle aziende (`companies.parent_id`).
 *
 * È una colonna: il framework la scrive dal POST. Per questo
 * `mutateRequestValues()` la valida sempre con `CompanyGroups::problem()`,
 * anche quando il form non mostra il campo, e la porta a un intero: un id
 * inesistente, cancellato, l'azienda stessa o un secondo livello si
 * rifiutano. `HasCompanyBrands` lo usa: ogni Resource delle aziende con i
 * brand ha anche questa difesa.
 */
trait HasCompanyGroup
{
    public static string $groupInput = 'parent_id';

    /** @return list<Input> */
    public static function groupFields(): array
    {
        return [
            FormField::key(static::$groupInput)
                ->selectSearch(['' => '—'] + CompanyGroups::options())
                ->label((string) __t('contacts.company_parent')),
        ];
    }

    public static function mutateRequestValues(array $values, string $action, string $context = 'backend', ?array $oldValues = null): array
    {
        if (array_key_exists(static::$groupInput, $values)) {
            $raw = $values[static::$groupInput];
            $parentId = is_scalar($raw) && preg_match('/^\d*$/', trim((string) $raw)) === 1 ? (int) $raw : -1;
            $problem = $parentId < 0 ? 'missing' : CompanyGroups::problem((int) ($oldValues['id'] ?? 0), $parentId);

            if ($problem !== '') {
                throw new InvalidArgumentException((string) __t('contacts.company_parent_'.$problem));
            }

            $values[static::$groupInput] = $parentId;
        }

        return parent::mutateRequestValues($values, $action, $context, $oldValues);
    }
}
