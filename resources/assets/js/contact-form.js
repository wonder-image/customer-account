/**
 * customer-account: il riquadro «Contatti» e la ricerca per nome negli indirizzi
 * dei form del backend. Lo stampa una volta `Support\ContactFormScript`.
 *
 * Le colonne per tipo (email o prefisso e numero) le mostra e nasconde il
 * framework (`visibleWhen('kind', …)`), oppure il riquadro è diviso in un
 * elenco per tipo e il tipo è un campo nascosto. Qui restano la riga
 * dell'email di accesso bloccata, il nome dell'attività scelta da Google e il
 * conto dei brand dell'azienda: con più di uno il campo nascosto
 * `data-ca-brands-many` vale «1» e il framework mostra i «Brand seguiti» dei
 * referenti (`visibleWhen`).
 * Le regole vere stanno sul server (`ContactPoints::split()`): qui c'è solo
 * quello che serve a vederle subito.
 */
(function () {

    if (window.caContactForm) { return; }
    window.caContactForm = true;

    function rowOf(input) { return input.closest('.wi-repeater-row'); }

    function kindOf(row) {
        // Una tendina (riquadro unico) o un campo nascosto con il tipo fisso (elenchi per tipo).
        var kind = row && row.querySelector('[data-ca-point-kind]');
        if (!kind) { return ''; }
        return (kind.getAttribute('data-ca-point-kind') || kind.value) === 'email' ? 'email' : 'phone';
    }

    function emit(el) {
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function refreshRow(row) {
        if (!kindOf(row)) { return; }

        // Un'email storica che il browser non accetta, lasciata com'è: il server la tiene (`ContactPoints::split()`).
        row.querySelectorAll('input[type="email"][data-ca-point-only]').forEach(function (input) {
            if (input.value !== '' && input.value === input.defaultValue && input.validity && input.validity.typeMismatch) {
                input.type = 'text';
                input.setAttribute('inputmode', 'email');
            }
        });

        var locked = row.querySelector('[data-ca-point-locked]');
        var fieldset = row.querySelector('fieldset');

        // L'email di accesso si vede ma non si cambia né si toglie: il server la ignora comunque.
        if (locked && locked.value === '1' && fieldset) {
            fieldset.disabled = true;
            row.setAttribute('data-ca-point-locked-row', 'true');
            return;
        }
    }

    function refreshAll(root) {
        (root || document).querySelectorAll('[data-ca-point-kind]').forEach(function (kind) {
            var row = rowOf(kind);
            if (row) { refreshRow(row); }
        });
    }

    // Quanti brand diversi ha scelto l'azienda nelle righe dello stesso form.
    function refreshBrands() {
        document.querySelectorAll('[data-ca-brands-many]').forEach(function (flag) {
            var scope = flag.form || document;
            var selects = scope.querySelectorAll('[data-ca-company-brand]');
            // Senza le righe dei brand nel form vale il conto fatto dal server.
            if (!selects.length && !flag.hasAttribute('data-ca-brands-seen')) { return; }
            flag.setAttribute('data-ca-brands-seen', 'true');

            var seen = {};
            selects.forEach(function (select) {
                if (!select.disabled && String(select.value || '') !== '') { seen[select.value] = true; }
            });

            var value = Object.keys(seen).length > 1 ? '1' : '0';
            if (flag.value !== value) {
                flag.value = value;
                emit(flag);
            }
        });
    }

    document.addEventListener('change', function (event) {
        var target = event.target;
        if (!target || !target.matches) { return; }

        if (target.matches('select[data-ca-point-kind]')) {
            var row = rowOf(target);
            if (row) { refreshRow(row); }
        }

        if (target.matches('[data-ca-company-brand]')) { refreshBrands(); }
    });

    /*
     * Ricerca per nome (`AddressExtension::withNameSearch()`): scelta
     * un'attività, il suo nome va nel campo indicato da
     * `data-ca-place-name="<chiave>"` sulla via (con lo stesso prefisso), se è
     * vuoto. Il suggerimento scelto si legge dallo stato che `places.js` tiene
     * sull'input (`input.wiPlace.items`), prima che la lib lo gestisca.
     */
    function placeTarget(street) {
        var key = street.getAttribute('data-ca-place-name');
        if (!key) { return null; }

        var declared = street.getAttribute('data-wi-place') || '';
        var inRow = declared.slice(-1) === '[' && /\[[^\[\]]+\]$/.test(street.name);
        var prefix = inRow ? street.name.replace(/[^\[\]]+\]$/, '') : declared;
        var name = prefix.slice(-1) === '[' ? prefix + key + ']' : prefix + key;
        var scope = street.closest('[data-wi-place-scope]') || street.form || document;

        return Array.from(scope.querySelectorAll('[name]')).find(function (el) { return el.name === name; }) || null;
    }

    function pick(street, index) {
        var state = street.wiPlace;
        var prediction = state && state.items ? state.items[index] : null;
        var target = prediction ? placeTarget(street) : null;
        if (!target || String(target.value || '').trim() !== '') { return; }

        // Solo un'attività: per un indirizzo il «nome» è la via.
        if ((prediction.types || []).indexOf('establishment') === -1) { return; }

        var name = prediction.mainText && prediction.mainText.text ? prediction.mainText.text : '';
        if (name) {
            target.value = name;
            emit(target);
        }
    }

    document.addEventListener('click', function (event) {
        var option = event.target && event.target.closest ? event.target.closest('.wi-place-option') : null;
        var list = option && option.closest('.wi-place-list');
        if (!list) { return; }

        var street = document.querySelector('[aria-controls="' + list.id + '"]');
        var index = parseInt(option.id.slice(list.id.length + 1), 10);
        if (street && !isNaN(index)) { pick(street, index); }
    }, true);

    document.addEventListener('keydown', function (event) {
        var street = event.target;
        if (event.key !== 'Enter' || !street || !street.wiPlace || !street.wiPlace.open || street.wiPlace.active < 0) { return; }
        pick(street, street.wiPlace.active);
    }, true);

    // Le righe aggiunte dopo (repeater) partono come le altre.
    var brandsChanged = false;

    if (typeof MutationObserver === 'function') {
        new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1) { refreshAll(node.matches && node.matches('.wi-repeater-row') ? node.parentElement : node); }
                });
                // Una riga dei brand aggiunta o tolta cambia il conto.
                if (mutation.addedNodes.length || mutation.removedNodes.length) { brandsChanged = true; }
            });

            if (brandsChanged) {
                brandsChanged = false;
                refreshBrands();
            }
        }).observe(document.documentElement, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { refreshAll(document); refreshBrands(); });
    } else {
        refreshAll(document);
        refreshBrands();
    }

})();
