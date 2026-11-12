<?php

namespace Wonder\Plugin\CustomerAccount\Resources\Concerns;

use InvalidArgumentException;
use Wonder\App\ResourceSchema\FormField;
use Wonder\App\ResourceSchema\Input;
use Wonder\App\ResourceSchema\RepeaterColumn;
use Wonder\Plugin\CustomerAccount\Support\Socials;
use Wonder\Plugin\CustomerAccount\Support\EmptyRepeater;

/**
 * I «Social» di una scheda (colonna `socials`, JSON rete → URL): un repeater
 * con la rete e l'indirizzo, come quello dei brand (`Support\Socials`).
 *
 * La Resource mette `socialFields()` nel form quando li vuole mostrare,
 * chiama `withSocialValues()` in `mutateFormValues()` e `applySocials()` in
 * `mutateRequestValues()`. La colonna si scrive solo se il form ha mostrato
 * il campo (`socials_form`): un form che non lo mostra non la tocca.
 */
trait HasSocials
{
    public static string $socialsInput = 'socials';
    public static string $socialsMarker = 'socials_form';

    /** @return list<Input> */
    public static function socialFields(): array
    {
        return [
            EmptyRepeater::on(FormField::key(static::$socialsInput)
                ->repeater([
                    RepeaterColumn::key('network')->select(Socials::networks())->label((string) __t('contacts.brand_social_network'))->columnSpan(4),
                    RepeaterColumn::key('url')->url()->label((string) __t('contacts.brand_social_url'))->columnFill(),
                ])
                ->nested()
                ->repeaterAddLabel((string) __t('contacts.brand_social_add'))
                ->label((string) __t('contacts.person_socials'))),
            FormField::key(static::$socialsMarker)->hidden()->value('1'),
        ];
    }

    /** I social salvati come righe del repeater. Un POST rifiutato tiene le righe scritte. */
    public static function withSocialValues(array $values): array
    {
        $saved = $values[static::$socialsInput] ?? null;

        if (!is_array($saved) || !array_is_list($saved)) {
            $values[static::$socialsInput] = Socials::formRows(is_array($saved) ? [static::$socialsInput => $saved] : $saved);
        }

        return $values;
    }

    /**
     * Le righe postate diventano il valore della colonna. Senza
     * `socials_form` la chiave esce dai valori: la colonna resta com'è.
     *
     * @throws InvalidArgumentException se una rete o un indirizzo non vanno
     */
    protected static function applySocials(array $values): array
    {
        $shown = isset($values[static::$socialsMarker]);
        $posted = $values[static::$socialsInput] ?? [];
        unset($values[static::$socialsMarker], $values[static::$socialsInput]);

        if (!$shown) {
            return $values;
        }

        $column = Socials::column(is_array($posted) ? array_values($posted) : []);

        if ($column === null) {
            throw new InvalidArgumentException((string) __t('contacts.brand_invalid', ['field' => (string) __t('contacts.person_socials')]));
        }

        return [static::$socialsInput => $column] + $values;
    }
}
