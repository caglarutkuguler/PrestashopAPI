/**
 * 2019-2026 MEG Venture
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 *
 *  @author    MEG Venture
 *  @copyright 2019-2026 MEG Venture
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *
 * Vanilla on purpose: the back office ships Bootstrap 3, 4 or 5 depending on the PrestaShop
 * version, so data-toggle="tab" behaves differently across the versions this module supports.
 */
(function () {
    'use strict';

    var app = document.querySelector('.psapi-app');

    if (!app) {
        return;
    }

    var tabs = app.querySelectorAll('[data-psapi-tab]');
    var panes = app.querySelectorAll('[data-psapi-pane]');

    function show(name) {
        var found = false;

        Array.prototype.forEach.call(panes, function (pane) {
            var match = pane.getAttribute('data-psapi-pane') === name;
            pane.classList.toggle('psapi-pane--active', match);
            found = found || match;
        });

        Array.prototype.forEach.call(tabs, function (tab) {
            tab.classList.toggle('psapi-tab--active', tab.getAttribute('data-psapi-tab') === name);
        });

        return found;
    }

    Array.prototype.forEach.call(tabs, function (tab) {
        tab.addEventListener('click', function () {
            var name = tab.getAttribute('data-psapi-tab');

            if (show(name)) {
                // Replace rather than push, so the browser's back button leaves the module
                // instead of walking back through every tab that was clicked.
                history.replaceState(null, '', '#psapi-' + name);
            }
        });
    });

    // Buttons elsewhere on the page that jump to a tab.
    Array.prototype.forEach.call(app.querySelectorAll('[data-psapi-goto]'), function (button) {
        button.addEventListener('click', function () {
            show(button.getAttribute('data-psapi-goto'));
            app.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });

    // Open the tab named in the URL, so a redirect can land on the tab the merchant was using.
    var initial = window.location.hash.indexOf('#psapi-') === 0
        ? window.location.hash.substring(7)
        : 'dashboard';

    if (!show(initial)) {
        show('dashboard');
    }

    /* ---------------------------------------------------------------- *
     * Table filters
     * ---------------------------------------------------------------- */
    Array.prototype.forEach.call(app.querySelectorAll('[data-psapi-filter]'), function (input) {
        var table = document.getElementById(input.getAttribute('data-psapi-filter'));

        if (!table) {
            return;
        }

        var rows = table.querySelectorAll('tbody tr');

        input.addEventListener('input', function () {
            // Lowercase both sides in JS rather than server-side: PHP's strtolower is
            // byte-level and mangles non-ASCII product names.
            var needle = input.value.toLowerCase().trim();

            Array.prototype.forEach.call(rows, function (row) {
                var haystack = (row.getAttribute('data-psapi-search') || '').toLowerCase();
                row.style.display = needle === '' || haystack.indexOf(needle) !== -1 ? '' : 'none';
            });
        });
    });

    /* ---------------------------------------------------------------- *
     * Thumbnails
     *
     * The marketplace supplies the image URL and we do not control it, so a dead one must
     * degrade to a placeholder rather than a torn-page icon. The error event does not bubble,
     * hence a listener per image rather than one delegated listener.
     * ---------------------------------------------------------------- */
    Array.prototype.forEach.call(app.querySelectorAll('img[data-psapi-thumb]'), function (img) {
        img.addEventListener('error', function () {
            var cell = img.parentNode;

            if (cell) {
                cell.classList.add('psapi-thumb--failed');
            }
        });

        // An image that failed before this script ran fires no event we can catch.
        if (img.complete && img.naturalWidth === 0 && img.parentNode) {
            img.parentNode.classList.add('psapi-thumb--failed');
        }
    });

    /* ---------------------------------------------------------------- *
     * Copy to clipboard
     * ---------------------------------------------------------------- */
    Array.prototype.forEach.call(app.querySelectorAll('[data-psapi-copy]'), function (button) {
        button.addEventListener('click', function () {
            var field = document.getElementById(button.getAttribute('data-psapi-copy'));

            if (!field) {
                return;
            }

            field.select();
            field.setSelectionRange(0, 99999);

            var done = function () {
                var original = button.innerHTML;
                button.innerHTML = '✓';
                setTimeout(function () {
                    button.innerHTML = original;
                }, 1500);
            };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(field.value).then(done, function () {});

                return;
            }

            // execCommand is deprecated but is the only option on pages served over plain
            // HTTP, where navigator.clipboard is undefined.
            try {
                document.execCommand('copy');
                done();
            } catch (e) {
                /* The field is selected; the merchant can still copy it by hand. */
            }
        });
    });

    /* ---------------------------------------------------------------- *
     * Settings: the custom date fields only matter for the custom period
     * ---------------------------------------------------------------- */
    var period = document.getElementById('PRESTASHOPAPI_PERIOD');

    if (period) {
        var toggleDates = function () {
            var custom = period.value === 'custom';

            Array.prototype.forEach.call(document.querySelectorAll('.psapi-custom-date'), function (input) {
                var group = input.closest('.form-group');

                if (group) {
                    group.style.display = custom ? '' : 'none';
                }
            });
        };

        period.addEventListener('change', toggleDates);
        toggleDates();
    }
}());
