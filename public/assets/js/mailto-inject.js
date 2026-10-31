// mailto-inject.js — fills obfuscate_mailto()'s empty <span id="obfmail-N">
// tags with mailto links from window.AESBIN_OBFMAIL (built by document-end.php).
//
// m/l/s are hex-encoded UTF-8 bytes, reversed (see obfuscate_mailto() in
// helpers.php); unscramble() is the inverse. TextDecoder keeps non-ASCII
// labels intact.
(function() {
    'use strict';

    function unscramble(str) {
        const hex = str.split('').reverse().join('');
        const pairs = hex.match(/.{1,2}/g) || [];
        const bytes = new Uint8Array(pairs.map(function(b) { return parseInt(b, 16); }));
        return new TextDecoder('utf-8').decode(bytes);
    }

    const items = window.AESBIN_OBFMAIL || [];
    for (let i = 0; i < items.length; i++) {
        const item = items[i];
        const host = document.getElementById(item.id);
        if (!host) continue; // registry/DOM mismatch: leave it empty rather than throw

        const address = unscramble(item.m || '');
        if (!address) continue;

        const link = document.createElement('a');
        const subject = unscramble(item.s || '');
        link.href = 'mailto:' + address + (subject ? '?subject=' + encodeURIComponent(subject) : '');
        link.textContent = unscramble(item.l || '') || address;

        host.appendChild(link);
    }
})();
