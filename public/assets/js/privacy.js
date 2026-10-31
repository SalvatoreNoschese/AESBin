// privacy.js — /privacy language switcher: load ?lang=<code> on change;
// the server stores the choice in a cookie and redirects back without it
// (visitor_lang_choice()). Without JS the GET form's <noscript> button does
// the same.
(function() {
    'use strict';
    const sel = document.getElementById('privacy-lang-sel');
    if (!sel) return;
    sel.addEventListener('change', function() {
        location.href = location.pathname + '?lang=' + encodeURIComponent(sel.value);
    });
})();
