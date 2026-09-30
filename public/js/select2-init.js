// Every list (<select>) on the page as a Select2 box with a search, as AMV (amv4local) shows its lists.
// - A list with data-placeholder shows that text while nothing is chosen, like AMV's "Sila Pilih", and can be
//   cleared with × unless it's required. Its first option, the empty one, is the placeholder.
// - A list with data-native stays as the browser draws it.
// - The page's own scripts listen for the usual change event, which Select2 only sends through jQuery, so each
//   choice is announced again the usual way.
// Without JavaScript, or if jQuery or Select2 didn't load, the lists stay as the browser draws them.
(() => {
    if (typeof jQuery === 'undefined' || !jQuery.fn.select2) {
        return;
    }

    const $ = jQuery;

    const enhance = (select) => {
        const placeholder = select.dataset.placeholder;

        $(select).select2({
            width: '100%',
            ...(placeholder === undefined ? {} : { placeholder, allowClear: !select.required }),
        });

        const selection = select.nextElementSibling?.querySelector('.select2-selection');
        if (!selection) {
            return;
        }

        // Screen readers hear the field's label with its choice, as they would for the list itself, and its hint and
        // whether it has a problem. Clicking the label opens the list, as it would move to the list.
        const label = select.id ? document.querySelector(`label[for="${CSS.escape(select.id)}"]`) : null;
        const rendered = selection.querySelector('.select2-selection__rendered');
        if (label) {
            label.id ||= `${select.id}-label`;
            selection.setAttribute('aria-labelledby', [label.id, rendered?.id].filter(Boolean).join(' '));
            label.addEventListener('click', (event) => {
                event.preventDefault();
                $(select).select2('open');
            });
        }
        if (select.hasAttribute('aria-describedby')) {
            selection.setAttribute('aria-describedby', select.getAttribute('aria-describedby'));
        }
        if (select.classList.contains('is-invalid')) {
            selection.setAttribute('aria-invalid', 'true');
        }
    };

    // Not SweetAlert2's own hidden list, which its boxes carry, like a toast shown as the page opens (toast.js). Also
    // for the lists in a part of the page that's put in afterwards, like a list refreshed in place (live-table.js).
    window.enhanceSelects = (root = document) => root.querySelectorAll('select:not([data-native]):not(.swal2-select)').forEach(enhance);
    window.enhanceSelects();

    // As in AMV: typing goes straight into the search box when a list opens, which Select2 doesn't do with jQuery 3.6+.
    $(document).on('select2:open', () => {
        document.querySelector('.select2-container--open .select2-search__field')?.focus();
    });

    $(document).on('select2:select select2:clear', 'select', (event) => {
        event.target.dispatchEvent(new Event('change', { bubbles: true }));
    });
})();
