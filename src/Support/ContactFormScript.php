<?php

namespace Wonder\Plugin\CustomerAccount\Support;

/**
 * Lo script dei form della rubrica (`resources/assets/js/contact-form.js`):
 * righe del riquadro «Contatti» (colonne per tipo, un principale per tipo,
 * email di accesso bloccata) e nome dell'attività scelta da Google negli
 * indirizzi. Esce in linea: il modulo non ha un modo suo per servire file, e
 * così non dipende da dove sta installato. Il testo è del modulo, mai dati
 * della richiesta. Stampato due volte non fa danni: lo script parte una volta.
 */
final class ContactFormScript
{
    public const FILE = __DIR__.'/../../resources/assets/js/contact-form.js';

    public static function html(): string
    {
        $js = is_file(self::FILE) ? (string) file_get_contents(self::FILE) : '';

        return $js !== '' ? "<script>\n".$js."</script>\n" : '';
    }
}
