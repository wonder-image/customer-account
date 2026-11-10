<?php

namespace Wonder\Plugin\CustomerAccount\Support;

/**
 * Lo script del riquadro «Contatti» (`resources/assets/js/contact-form.js`):
 * un principale per tipo a schermo, riga dell'email di accesso bloccata,
 * email storiche non valide inviabili com'erano. Colonne per tipo e nome
 * dell'attività negli indirizzi li fa il framework. Esce in linea: il modulo
 * non ha un modo suo per servire file, e così non dipende da dove sta
 * installato. Il testo è del modulo, mai dati della richiesta. Stampato due
 * volte non fa danni: lo script parte una volta. Serve solo dove c'è il
 * riquadro.
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
