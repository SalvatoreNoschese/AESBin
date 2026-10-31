// gotop.js — "back to top" button. It builds its own markup, so adding it
// to a view's $pageJs is the whole switch.
// Avoid "scroll-to-top" in names: ad-blocker annoyance lists block it.
(function() {
    'use strict';
    const L = window.AESBIN_LANG || {};
    const t = k => L[k] || k;

    // document-end.php ships only the strings found as t('key') literals in the
    // page's scripts, so t('scroll_top') must stay a literal.
    if (document.getElementById('aesbin-gotop')) return;
    const btn = document.createElement('button');
    btn.id = 'aesbin-gotop';
    btn.className = 'aesbin-gotop';
    btn.type = 'button';
    btn.setAttribute('aria-label', t('scroll_top'));
    const arrow = document.createElement('span');
    arrow.className = 'aesbin-gotop-arrow';
    arrow.setAttribute('aria-hidden', 'true');
    btn.appendChild(arrow);
    document.body.appendChild(btn);

    let ticking = false;
    let justClicked = false;

    function update() {
        const y = window.scrollY;
        let target = 0;
        if (y > 300) {
            target = 0.7;
        } else if (y > 100) {
            target = ((y - 100) / 200) * 0.7;
        }
        if (y < 100) {
            justClicked = false; // back at the top: normal fading again
        }

        const shown = justClicked ? 0.5 : target;
        btn.style.setProperty('--gotop-opacity', shown.toFixed(2));
        btn.classList.toggle('is-interactive', target > 0);
        ticking = false;
    }

    window.addEventListener('scroll', function() {
        if (!ticking) {
            window.requestAnimationFrame(update);
            ticking = true;
        }
    }, { passive: true });

    // Chrome does not guarantee a final 'scroll' event after a smooth
    // scrollTo(), which can leave the state on a mid-animation value.
    // 'scrollend' fires once the scroll has finished.
    if ('onscrollend' in window) {
        window.addEventListener('scrollend', update);
    } else {
        // Without scrollend: recompute once scrolling has been idle for 600 ms.
        let idleTimer = null;
        window.addEventListener('scroll', function() {
            clearTimeout(idleTimer);
            idleTimer = setTimeout(update, 600);
        }, { passive: true });
    }

    btn.addEventListener('click', function(e) {
        e.preventDefault();
        justClicked = true;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });

    update();
})();
