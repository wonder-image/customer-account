/**
 * customer-account: quello che il framework non fa nel riquadro «Contatti»
 * (`HasContactPoints::contactBoxFields()`). Lo stampa una volta
 * `Support\ContactFormScript`.
 *
 * Le colonne per tipo e «Email di accesso» le mostra il framework
 * (`visibleWhen()`); qui restano tre cose che dipendono dalle altre righe o dal
 * valore salvato:
 * - un solo principale per tipo: accenderne uno spegne gli altri;
 * - la riga dell'email di accesso (`data-ca-point-locked` = 1) bloccata;
 * - un'email salvata che il browser non accetta resta inviabile com'è.
 *
 * Si appoggia solo a contratti del markup: il contenitore `[data-wi-repeater]`,
 * il `fieldset` di ogni riga e gli attributi `data-ca-point-*` del modulo. Le
 * regole vere stanno sul server (`ContactPoints::split()`).
 */
(function () {

    if (window.caContactForm) { return; }
    window.caContactForm = true;

    function rowOf(el) { return el.closest('[data-wi-repeater] fieldset'); }

    function kindOf(row) {
        var kind = row.querySelector('select[data-ca-point-kind]');
        return kind ? kind.value : '';
    }

    // Un solo principale per tipo, fra le righe dello stesso riquadro. La riga bloccata non si tocca.
    function demoteOthers(row) {
        var primary = row.querySelector('select[data-ca-point-primary]');
        var box = row.closest('[data-wi-repeater]');
        if (!primary || primary.value !== 'true' || !box) { return; }

        var kind = kindOf(row);

        box.querySelectorAll('fieldset').forEach(function (other) {
            if (other === row || other.disabled || kindOf(other) !== kind) { return; }
            var select = other.querySelector('select[data-ca-point-primary]');
            if (select && select.value === 'true') {
                select.value = 'false';
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    }

    document.addEventListener('change', function (event) {
        var target = event.target;
        if (!target || !target.matches || !target.matches('select[data-ca-point-primary], select[data-ca-point-kind]')) { return; }
        var row = rowOf(target);
        if (row) { demoteOthers(row); }
    });

    // Righe arrivate dal server: le righe aggiunte nascono vuote e non bloccate.
    function prepare() {
        document.querySelectorAll('[data-wi-repeater] select[data-ca-point-locked]').forEach(function (locked) {
            var row = rowOf(locked);
            // L'email di accesso si vede ma non si cambia né si toglie: il server la ignora comunque.
            if (row && locked.value === '1') { row.disabled = true; }
        });

        document.querySelectorAll('[data-wi-repeater] select[data-ca-point-kind]').forEach(function (kind) {
            var row = rowOf(kind);
            var email = row && row.querySelector('input[type="email"]');
            // Un'email storica che il browser non accetta, lasciata com'è: il server la tiene (`ContactPoints::split()`).
            if (email && email.defaultValue !== '' && email.validity && email.validity.typeMismatch) {
                email.type = 'text';
                email.setAttribute('inputmode', 'email');
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', prepare);
    } else {
        prepare();
    }

})();
