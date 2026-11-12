<?php

namespace Wonder\Plugin\CustomerAccount\Support;

/**
 * I social di un brand o di una persona: la lista delle reti, la pulizia di
 * quello che arriva da un form e la lettura dalla colonna `socials` (una
 * mappa rete → URL in JSON). Stesse regole per tutti: chiavi di una lista
 * estendibile, URL http o https. I valori restano testo: chi li stampa li
 * escapa.
 */
final class Socials
{
    /** Le reti di base; altre con `add()`. */
    public const NETWORKS = ['facebook', 'instagram', 'linkedin', 'x', 'tiktok', 'youtube', 'pinterest', 'whatsapp'];

    /** @var array<string, string> reti aggiunte: chiave => etichetta */
    private static array $extra = [];

    /**
     * Le reti per un select: quelle di base e quelle aggiunte.
     *
     * @return array<string, string>
     */
    public static function networks(): array
    {
        $out = [];

        foreach (self::NETWORKS as $key) {
            $out[$key] = (string) __t('contacts.brand_social_'.$key);
        }

        return $out + self::$extra;
    }

    /** Aggiunge una rete (es. `threads`). Un modulo lo chiama al boot, un sito in `custom/function`. */
    public static function add(string $key, string $label): void
    {
        $key = strtolower(trim($key));

        if (preg_match('/^[a-z0-9_]{1,40}$/', $key) === 1) {
            self::$extra[$key] = $label;
        }
    }

    /**
     * I social ripuliti: da righe `network` / `url` (il repeater) o da una
     * mappa chiave → URL. Chiavi della lista, URL http/https; le righe senza
     * URL si saltano. Null se una chiave o un URL non vanno.
     *
     * @return array<string, string>|null
     */
    public static function clean(mixed $value): ?array
    {
        $out = [];
        $known = self::networks();

        foreach (is_array($value) ? $value : [] as $key => $item) {
            [$network, $url] = is_array($item) ? [$item['network'] ?? '', $item['url'] ?? ''] : [$key, $item];
            $network = is_scalar($network) ? strtolower(trim((string) $network)) : '';
            $url = BrandData::url($url);

            if ($url === '') {
                continue;
            }

            if ($url === null || !array_key_exists($network, $known)) {
                return null;
            }

            $out[$network] = $url;
        }

        return $out;
    }

    /**
     * Il valore per la colonna `socials`: JSON, vuoto senza social. Null se
     * quello che arriva non va (il form lo rifiuta, niente si scrive).
     */
    public static function column(mixed $value): ?string
    {
        $clean = self::clean($value);

        if ($clean === null) {
            return null;
        }

        return $clean === [] ? '' : (string) json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * I social salvati di una riga (brand o persona) come mappa rete → URL.
     * Una colonna vuota, illeggibile o con valori non più validi dà `[]`.
     *
     * @param array<string, mixed>|string|null $row la riga, o il valore della colonna
     * @return array<string, string>
     */
    public static function of(array|string|null $row): array
    {
        $value = is_array($row) ? ($row['socials'] ?? '') : $row;
        $decoded = is_string($value) && $value !== '' ? json_decode($value, true) : $value;

        return self::clean(is_array($decoded) ? $decoded : []) ?? [];
    }

    /**
     * I social di una riga come righe del repeater (`network`, `url`).
     *
     * @param array<string, mixed>|string|null $row
     * @return list<array{network: string, url: string}>
     */
    public static function formRows(array|string|null $row): array
    {
        $out = [];

        foreach (self::of($row) as $network => $url) {
            $out[] = ['network' => (string) $network, 'url' => $url];
        }

        return $out;
    }
}
