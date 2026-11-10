/**
 * customer-account: il riquadro «Contatti» e la ricerca per nome negli indirizzi
 * dei form del backend. Lo stampa una volta `Support\ContactFormScript`.
 *
 * Il framework non sa ancora nascondere una colonna di repeater secondo un
 * select della stessa riga (`visibleWhen()` cerca il campo per nome in tutto
 * il form), né scrivere il nome dell'attività scelta da Google in un campo
 * (`places.js` non lo passa): finché non lo fanno core e lib, lo fa questo.
 * Le regole vere stanno sul server (`ContactPoints::split()`): qui c'è solo
 * quello che serve a vederle subito.
 */
(function () {

    if (window.caContactForm) { return; }
    window.caContactForm = true;

    // La cella di una colonna: il figlio diretto del fieldset della riga.
    function cell(input) {
        var node = input;
        while (node && node.parentElement && node.parentElement.tagName !== 'FIELDSET') { node = node.parentElement; }
        return node && node.parentElement ? node : input.parentElement;
    }

    function rowOf(input) { return input.closest('.wi-repeater-row'); }

    function kindOf(row) {
        var kind = row && row.querySelector('select[data-ca-point-kind]');
        return kind ? (kind.value === 'phone' ? 'phone' : 'email') : '';
    }

    function emit(el) {
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
    }

    // Email: solo la casella dell'email. Telefono: prefisso e numero.
    function refreshRow(row) {
        var kind = kindOf(row);
        if (!kind) { return; }

        row.querySelectorAll('[data-ca-point-only]').forEach(function (input) {
            var box = cell(input);
            var show = input.getAttribute('data-ca-point-only') === kind;
            box.style.display = show ? '' : 'none';
            box.setAttribute('aria-hidden', show ? 'false' : 'true');
        });

        var locked = row.querySelector('[data-ca-point-locked]');
        var fieldset = row.querySelector('fieldset');

        // L'email di accesso si vede ma non si cambia né si toglie: il server la ignora comunque.
        if (locked && locked.value === '1' && fieldset) {
            fieldset.disabled = true;
            row.setAttribute('data-ca-point-locked-row', 'true');
            return;
        }

        // «Email di accesso» non si sceglie: è solo della riga bloccata.
        var label = row.querySelector('select[data-ca-point-label]');
        var access = label && label.querySelector('option[value="access"]');
        if (access) { access.remove(); }
    }

    // Un solo principale per tipo: accenderne uno spegne gli altri dello stesso tipo.
    function demoteOthers(row) {
        var primary = row.querySelector('select[data-ca-point-primary]');
        var list = row.parentElement;
        if (!primary || primary.value !== 'true' || !list) { return; }

        var kind = kindOf(row);

        list.querySelectorAll('.wi-repeater-row').forEach(function (other) {
            if (other === row || kindOf(other) !== kind || other.getAttribute('data-ca-point-locked-row') === 'true') { return; }
            var select = other.querySelector('select[data-ca-point-primary]');
            if (select && select.value === 'true') {
                select.value = 'false';
                emit(select);
            }
        });
    }

    function refreshAll(root) {
        (root || document).querySelectorAll('select[data-ca-point-kind]').forEach(function (kind) {
            var row = rowOf(kind);
            if (row) { refreshRow(row); }
        });
    }

    document.addEventListener('change', function (event) {
        var target = event.target;
        if (!target || !target.matches) { return; }

        if (target.matches('select[data-ca-point-kind]')) {
            var row = rowOf(target);
            if (row) { refreshRow(row); demoteOthers(row); }
        } else if (target.matches('select[data-ca-point-primary]')) {
            var primaryRow = rowOf(target);
            if (primaryRow) { demoteOthers(primaryRow); }
        }
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
    if (typeof MutationObserver === 'function') {
        new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1) { refreshAll(node.matches && node.matches('.wi-repeater-row') ? node.parentElement : node); }
                });
            });
        }).observe(document.documentElement, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { refreshAll(document); });
    } else {
        refreshAll(document);
    }

})();
