/**
 * public/assets/js/lang-select.js — language dropdown with flags
 *
 * A native <option> holds text only, so the flags of lang_select() (see
 * app/includes/helpers.php) cannot appear in the list. This turns each
 * .lang-flag-sel into a button and a listbox that show them. The <select>
 * stays in the page, hidden, and keeps the value: a pick sets it and fires
 * its "change" event, so the handlers each page already has (reload, form
 * submit, Settings' Save button) work unchanged. Without JavaScript the
 * native select, with the chosen flag drawn inside it, is what remains.
 *
 * Loaded by every page with a language select: dashboard, share, privacy,
 * and the installer.
 */
(function() {
    'use strict';

    const flagImg = src => {
        const img = document.createElement('img');
        img.className = 'lang-dd-flag';
        img.alt = '';
        if (src) img.src = src;
        else img.hidden = true;
        return img;
    };

    function enhance(wrap) {
        const sel = wrap.querySelector('select');
        if (!sel || wrap.classList.contains('lang-dd-ready')) return;

        const btn = document.createElement('button');
        btn.type = 'button';
        // The select's classes give the button the same look in each place
        // (.field-input, .page-lang-sel, .setup-lang-select).
        btn.className = (sel.className + ' lang-dd-btn').trim();
        btn.setAttribute('aria-haspopup', 'listbox');
        btn.setAttribute('aria-expanded', 'false');
        const label = sel.getAttribute('aria-label');
        if (label) btn.setAttribute('aria-label', label);
        const btnFlag = flagImg('');
        const btnText = document.createElement('span');
        btnText.className = 'lang-dd-text';
        btn.append(btnFlag, btnText);

        const list = document.createElement('ul');
        list.className = 'lang-dd-list';
        list.setAttribute('role', 'listbox');
        if (label) list.setAttribute('aria-label', label);
        list.hidden = true;

        const items = [...sel.options].map(opt => {
            const li = document.createElement('li');
            li.className = 'lang-dd-opt';
            li.setAttribute('role', 'option');
            li.tabIndex = -1;
            li.dataset.value = opt.value;
            const text = document.createElement('span');
            text.textContent = opt.textContent.trim();
            li.append(flagImg(opt.dataset.flag || ''), text);
            li.addEventListener('click', () => pick(li));
            list.appendChild(li);
            return li;
        });

        // Mirrors the select: its value (changed by a pick, or by a page
        // script followed by a change event) and its disabled state.
        function sync() {
            const opt = sel.selectedOptions[0];
            btnText.textContent = opt ? opt.textContent.trim() : '';
            const flag = opt?.dataset.flag || '';
            btnFlag.hidden = !flag;
            if (flag) btnFlag.src = flag;
            items.forEach(li => li.setAttribute('aria-selected', String(li.dataset.value === sel.value)));
            btn.disabled = sel.disabled;
        }

        function open() {
            if (btn.disabled) return;
            list.hidden = false;
            btn.setAttribute('aria-expanded', 'true');
            (items.find(li => li.dataset.value === sel.value) || items[0])?.focus();
        }

        function close(refocus) {
            if (list.hidden) return;
            list.hidden = true;
            btn.setAttribute('aria-expanded', 'false');
            if (refocus) btn.focus();
        }

        function pick(li) {
            close(true);
            if (li.dataset.value === sel.value) return;
            sel.value = li.dataset.value;
            sel.dispatchEvent(new Event('change', { bubbles: true }));
        }

        btn.addEventListener('click', () => (list.hidden ? open() : close(false)));
        btn.addEventListener('keydown', e => {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                open();
            }
        });
        list.addEventListener('keydown', e => {
            const i = items.indexOf(document.activeElement);
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                const next = e.key === 'ArrowDown' ? Math.min(items.length - 1, i + 1) : Math.max(0, i - 1);
                items[next].focus();
            } else if (e.key === 'Home' || e.key === 'End') {
                e.preventDefault();
                items[e.key === 'Home' ? 0 : items.length - 1].focus();
            } else if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                if (i >= 0) pick(items[i]);
            } else if (e.key === 'Escape') {
                e.preventDefault();
                close(true);
            } else if (e.key === 'Tab') {
                close(false);
            }
        });
        document.addEventListener('click', e => {
            if (!wrap.contains(e.target)) close(false);
        });

        sel.addEventListener('change', sync);
        new MutationObserver(sync).observe(sel, { attributes: true, attributeFilter: ['disabled'] });

        wrap.append(btn, list);
        wrap.classList.add('lang-dd-ready');
        sync();
    }

    document.querySelectorAll('.lang-flag-sel').forEach(enhance);
})();
