/**
 * public/assets/js/dashboard.js — AESBin dashboard
 *
 * Main authenticated app: file browser (Container > Directory > File),
 * upload, search, sharing, trash, activity log and settings. UI strings go
 * through t(key) via AESBIN_LANG; confirm dialogs use the app's own modal.
 */
/* global API, AESBIN_IDLE_TIMEOUT, AESBIN_ELEVATED_UNTIL, AESBIN_DB_SCHEMA, AESBinPreview, AESBinHelper, AESBinStripMeta */
(function() {
    'use strict';

    const L = window.AESBIN_LANG || {};
    const t = k => L[k] || k;
    const $ = id => document.getElementById(id);
    // Shared with share.js, preview.js and setup.js (see helper.js).
    const {
        esc, fmt, fmtDesc, icon, EXT_LINK, isEditableMime, isPreviewable, mimeIcon,
        decodeText, encodeText,
        parseServerDate, relDate, fullDate, fmtDay, fmtDateTime, fmtBytes, fmtBytesBinary,
        renderHashDetails, closeAllHashes, renderQr, appUrl,
    } = AESBinHelper;

    // Dates follow the interface language, not the browser's.
    const uiLang = document.documentElement.lang || undefined;

    /** Replace an element's content with an icon and a plain-text message. */
    const setIconText = (el, name, text, cls = '') => {
        el.innerHTML = icon(name, cls) + ' ' + esc(text);
    };

    // Share statistics, one icon each wherever they appear (log, share
    // cards, button badges). Openings are not the eye, which is the preview.
    const ICON_VISITS = icon('footprints');
    const ICON_DOWNLOADS = icon('download');
    const ICON_PREVIEWS = icon('eye');

    // Count on the corner of an icon button, as the header trash count:
    // nothing for zero, "99+" past two digits. The link button carries the
    // item's live links, download and preview the file's lifetime counts by share
    // visitors. The tooltip (tip: a translated string with {count}) gives the
    // exact figure and what it counts; has-tip shows it on a touch screen
    // too, with a long press on the button (see helper.js).
    const countBadge = (n, tip) => {
        const v = Number(n) || 0;
        if (v <= 0) return '';
        return `<span class="icon-badge has-tip" title="${esc(fmt(tip, { count: v }))}">${v > 99 ? '99+' : v}</span>`;
    };

    // The share figures panel, the same on home and on the activity page:
    // live links now, then every share visitor's openings, downloads and
    // previews since installation ({P}stats on the server), which outlive
    // deleted links and files and survive emptying the log. Same icons as the
    // badges. Each row has its own tooltip. updateShareStats() refreshes the
    // values in place.
    const shareStatsRows = stats => {
        const val = key => stats ? esc(String(Number(stats[key]) || 0)) : '—';
        // share_visits & co. are lowercase in translation (reused
        // mid-sentence): only the first letter is raised here. CSS
        // text-transform would capitalise every word ("Vistas Previas").
        const cap = s => s.charAt(0).toLocaleUpperCase(uiLang) + s.slice(1);
        const row = (key, icon, label, hint) => `<div class="fs-stat-row"><span class="fs-stat-label has-tip" title="${esc(hint)}">${icon} ${esc(label)}</span><span class="fs-stat-val" data-share-stat="${key}">${val(key)}</span></div>`;
        return row('links', icon('link'), t('share_stats_links'), t('share_links_hint'))
            + row('visits', ICON_VISITS, cap(t('share_visits')), t('share_visits_hint'))
            + row('downloads', ICON_DOWNLOADS, cap(t('share_downloads')), t('share_downloads_hint'))
            + row('previews', ICON_PREVIEWS, cap(t('share_previews')), t('share_previews_hint'));
    };
    const updateShareStats = stats => {
        if (!stats) return;
        document.querySelectorAll('[data-share-stat]').forEach(el => {
            el.textContent = String(Number(stats[el.dataset.shareStat]) || 0);
        });
    };

    // ── Activity log rendering helpers ────────────────────────────────────────
    // Card titles: "container.create" → "log_container_create" (downloads and
    // previews share keys; a missing key shows the raw event). Built keys never
    // appear literally, so the directive below ships them.
    // @lang-prefix log_
    // Events whose meta.reason changes the meaning, and the reason assumed
    // for an item that has none ('' = show nothing).
    const REASON_EVENTS = {
        'logout': 'user',
        'settings.deelevated': 'manual',
        'share.revoke': 'manual',
        'login.failure': '',
        'passkey.failure': '',
        'recovery.failure': 'bad_phrase',
        'file.restore': '',
    };
    // A wrong TOTP code after the right password: whoever tried knows the
    // password, so the reason stands out.
    const ALERT_REASONS = ['login.failure:bad_totp'];
    // Card title: generic per event. The reason varies per occurrence and is
    // rendered per item by activityItemReason().
    const activityEventLabel = event => {
        // file.* and share.* downloads and previews have labels of their own
        // (log_file_preview, log_share_preview…), so the two cards differ by
        // title and not only by their border colour.
        const key = 'log_' + event.replace(/\./g, '_');
        const label = t(key);
        return label === key ? esc(event) : label;
    };
    // Per-item reason, only for events where meta.reason changes the meaning
    // (manual vs idle-timeout logout, share revoked by hand vs by deleting its
    // target, sign-in failed at the password or at the TOTP code). Returned
    // as escaped HTML.
    const activityItemReason = (event, item) => {
        if (!Object.prototype.hasOwnProperty.call(REASON_EVENTS, event)) return '';
        const reason = item.reason || REASON_EVENTS[event];
        if (!reason) return '';
        const key = 'log_' + event.replace(/\./g, '_') + '_' + reason;
        const label = t(key);
        if (label === key) return '';
        return ALERT_REASONS.includes(event + ':' + reason)
            ? `<strong class="activity-reason-alert">${esc(label)}</strong>`
            : esc(label);
    };
    // target_id is null for events without a target (e.g. login.*). Items are
    // flat: log_activity() spreads $meta onto each item, so item.name and
    // item.target_type are read directly. Name and id share one line; a name
    // whose object still exists (live_targets from /api/activity) is a button
    // to it, as a search result is.
    const activityTargetLine = (item, cls) => {
        if (item.target_id == null) return [];
        // item.name first: captured when the event happened. target_name is
        // resolved live at read time, so it is empty for deleted targets and
        // reflects later renames. Items without a captured name fall back to it.
        let name = item.name || item.target_name || '';
        // A rename carries both names: show the change, not just the result.
        if (item.prev_name && item.prev_name !== name) {
            name = `${item.prev_name} → ${name}`;
        }
        // Share items carry real_target_type/id (what the link points to). Checked
        // by presence, not by event name.
        const badgeType = item.real_target_type || item.target_type;
        const badgeId = item.real_target_id != null ? item.real_target_id : item.target_id;
        // Labelled with the type: files, folders and containers number
        // independently, so a bare "id:10" is ambiguous. Literal t() calls so
        // Lang::forScripts() ships the keys.
        const typeLabel = badgeType === 'file' ? t('type_file') :
            badgeType === 'directory' ? t('type_directory') :
            badgeType === 'container' ? t('type_container') :
            badgeType === 'share' ? t('type_share') : '';
        const idBadge = `<span class="activity-count">id:${esc(String(badgeId))}${typeLabel ? ' (' + esc(typeLabel) + ')' : ''}</span>`;
        if (!name) return [`<span class="${cls}">${idBadge}</span>`];
        const nameHtml = _liveTargets[badgeType]?.[badgeId]
            ? `<button type="button" class="btn-link activity-target-link" data-type="${esc(badgeType)}" data-id="${esc(String(badgeId))}">${esc(name)}</button>`
            : esc(name);
        return [`<span class="${cls}">${nameHtml} · ${idBadge}</span>`];
    };
    // item.real_count is the ordinal this opening, download or preview had when
    // logged, not the running total. Hints are literal t() calls so that
    // Lang::forScripts() ships them.
    const REAL_COUNT = {
        'share.visit': [ICON_VISITS, t('activity_visit_count_hint')],
        'share.download': [ICON_DOWNLOADS, t('activity_download_count_hint')],
        'share.preview': [ICON_PREVIEWS, t('activity_preview_count_hint')],
    };
    // Next to the link on the head line, as the visit count of a revoked link.
    const activityRealCountSuffix = (event, item) => {
        const [icon, hint] = REAL_COUNT[event] || [];
        if (!icon) return '';
        const n = Number(item.real_count);
        if (!(n >= 1)) return '';
        return ` · <span class="has-tip" title="${esc(hint)}">${icon} ${esc(String(n))}</span>`;
    };
    /** An address as its ipinfo.io lookup link (owner-side only: sessions, log). */
    const ipLink = ip => `<a href="https://ipinfo.io/${esc(ip)}" ${EXT_LINK}>${esc(ip)}</a>`;
    // Share-visitor items carry no address by design (VISITOR_EVENTS in
    // helpers.php), and neither do events nobody performs (ACTORLESS_EVENTS,
    // card.actorless), so the slot says so instead. Everything else gets the
    // ipinfo.io lookup link.
    const activityOriginCell = (visitor, item, actorless = false) => {
        if (visitor) {
            // No title attribute: it never opens on touch and would repeat the label.
            return `<span class="activity-visitor">${esc(t('activity_visitor'))}</span>`;
        }
        if (actorless) {
            return `<span class="activity-visitor">${esc(t('activity_no_ip'))}</span>`;
        }
        return item.ip ? ipLink(item.ip) : '';
    };
    // Client label and address share one line.
    const activityOriginLine = (visitor, item, actorless = false) => {
        const origin = activityOriginCell(visitor, item, actorless);
        if (!item.client) return origin;
        return `<span class="activity-client">${esc(item.client)}</span>${origin ? ' · ' + origin : ''}`;
    };
    // settings.update carries item.sections (top-level config groups
    // touched); the values are in item.changes (activitySettingsChangeLines()).
    // The sections show only when no change line does: each line already
    // names its setting, and a save of settings outside SETTINGS_LOG_FIELDS
    // (helpers.php) has the sections alone.
    const activitySettingsSuffix = item => {
        if (Array.isArray(item.changes) && item.changes.length) return '';
        const sections = Array.isArray(item.sections) ? item.sections : [];
        // esc(): for a missing key t() returns the raw meta value, which ends up
        // in innerHTML.
        const label = s => esc(t('log_settings_section_' + s));
        return sections.length ? ' · ' + sections.map(label).join(', ') : '';
    };
    // ── Detail lines: what changed, not only that something did ─────────────
    // A value from SETTINGS_LOG_FIELDS (helpers.php), formatted by its kind.
    // Literal t() calls so Lang::forScripts() ships the keys.
    const DURATION_LABEL = {
        300: t('dur_5min'), 600: t('dur_10min'), 900: t('dur_15min'),
        1800: t('dur_30min'), 3600: t('dur_1h'),
    };
    const CAPTCHA_LEVEL_LABEL = {
        low: t('set_captcha_level_low'), medium: t('set_captcha_level_medium'), high: t('set_captcha_level_high'),
    };
    const settingValue = (kind, v) => {
        if (v === null || v === undefined) return '—';
        switch (kind) {
            case 'bool': return v ? t('log_value_on') : t('log_value_off');
            case 'seconds': return Number(v) === 0 ? t('log_value_off') : (DURATION_LABEL[v] || v + ' s');
            case 'int': return Number(v) === 0 ? t('log_value_off') : String(v);
            case 'bytes': return Number(v) === 0 ? t('log_value_no_limit') : fmtBytesBinary(Number(v));
            case 'level': return CAPTCHA_LEVEL_LABEL[v] || String(v);
            case 'locale': return String(v).toUpperCase();
            default: return String(v);
        }
    };
    const TEXT_CHANGE = {
        set: t('log_value_set'), cleared: t('log_value_cleared'), changed: t('log_value_changed'),
    };
    // settings.update: one line per changed setting.
    const activitySettingsChangeLines = (event, item) => {
        if (event !== 'settings.update' || !Array.isArray(item.changes)) return [];
        return item.changes.map(c => {
            const label = esc(t('log_setting_' + String(c.field).replace(/\./g, '_')));
            const value = c.kind === 'text'
                ? esc(TEXT_CHANGE[c.change] || '')
                : esc(settingValue(c.kind, c.from)) + ' → ' + esc(settingValue(c.kind, c.to));
            return `<span class="activity-item-target">${label}: ${value}</span>`;
        });
    };
    // A link's expiry, null for never.
    const expiryText = v => (v ? fullDate(v) : t('share_never'));
    const SHARE_PASSWORD_CHANGE = {
        set: t('log_share_password_set'), changed: t('log_share_password_changed'), removed: t('log_share_password_removed'),
    };
    // share.create: password yes/no and expiry; share.update: what changed.
    const activityShareDetailLines = (event, item) => {
        const line = (label, value) => `<span class="activity-item-target">${esc(label)}: ${esc(value)}</span>`;
        if (event === 'share.create') {
            return [
                line(t('log_share_expiry'), expiryText(item.expires_at)),
                line(t('log_share_password'), item.password ? t('yes') : t('no')),
            ];
        }
        if (event === 'share.update') {
            const lines = [];
            if (item.expiry) {
                lines.push(line(t('log_share_expiry'), expiryText(item.expires_from) + ' → ' + expiryText(item.expires_to)));
            }
            if (SHARE_PASSWORD_CHANGE[item.password]) {
                lines.push(line(t('log_share_password'), SHARE_PASSWORD_CHANGE[item.password]));
            }
            return lines;
        }
        return [];
    };
    // file.move / directory.move: "Container › Folder → Container
    // › Folder", with the names the places had at the time of the move.
    const activityMoveLine = (event, item) => {
        if (event !== 'file.move' && event !== 'directory.move') return [];
        const place = (c, d) => [c, d].filter(Boolean).join(' › ');
        const from = place(item.from_container_name, item.from_dir_name);
        const to = place(item.to_container_name, item.to_dir_name);
        if (!from && !to) return [];
        return [`<span class="activity-item-target">${icon('folder-input')} ${esc(from || '—')} → ${esc(to || '—')}</span>`];
    };
    // These events MAY carry {referer}; share.token_probe and route.not_found
    // also {url}. See visitor_failure_context() in helpers.php for why
    // share.failure never gets a url. Shown on labelled lines; an empty value
    // renders as an em dash.
    const REFERER_CONTEXT_EVENTS = ['share.failure', 'share.token_probe', 'share.visit', 'share.preview', 'share.download', 'route.not_found'];
    const activityFailureContextLines = (event, item, cls) => {
        if (!REFERER_CONTEXT_EVENTS.includes(event)) return [];
        const cap = s => s.length > 80 ? s.slice(0, 80) + '…' : s;
        const dash = '—';
        const chip = s => `<span class="activity-count has-tip" title="${esc(s)}">${esc(cap(s))}</span>`;
        // One item is one occurrence, so it holds a single referer/url, never a
        // list (see docs/maintaining.md on the activity log).
        const single = s => (typeof s === 'string' && s) ? chip(s) : dash;
        // Separate spans, not one span with a <br>: each field gets its own row
        // and can never share a row with the timestamp (.activity-item-head).
        const lines = [];
        if (event === 'share.token_probe' || event === 'route.not_found') {
            lines.push(`<span class="${cls}">${esc(t('activity_request_label'))} ${single(item.url)}</span>`);
        }
        lines.push(`<span class="${cls}">${esc(t('activity_referral_label'))} ${single(item.referer)}</span>`);
        return lines;
    };
    // session.revoked carries item.sessions: {client, ip} per revoked session,
    // captured in sessions_revoke.php before the rows were deleted. Labelled,
    // because the revoking device often appears as a target too.
    const REVOKED_SESSION_EVENTS = ['session.revoked'];
    const activityRevokedSessionLines = (event, item) => {
        if (!REVOKED_SESSION_EVENTS.includes(event) || !Array.isArray(item.sessions)) return [];
        return item.sessions.map(s => {
            const ipHtml = s.ip ? ipLink(s.ip) : '';
            // Plain text, not .activity-client (display:block): this wants a compact
            // "Client · IP" on one line.
            const clientText = s.client ? esc(s.client) : '';
            const device = `${clientText}${clientText && ipHtml ? ' · ' : ''}${ipHtml}`;
            return `<span class="activity-item-target">${esc(t('activity_revoked_target_label'))} ${device}</span>`;
        });
    };
    // Which device performed the revoke (the acting session's client/ip).
    // Same weight as the target lines above, not the demoted meta line.
    const activityRevokedByLine = (event, item, visitor) => {
        if (event !== 'session.revoked' || visitor) return '';
        const origin = activityOriginCell(visitor, item);
        if (!item.client && !origin) return '';
        const device = `${item.client ? esc(item.client) : ''}${item.client && origin ? ' · ' : ''}${origin}`;
        return `<span class="activity-item-target">${esc(t('activity_revoked_by_label'))} ${device}</span>`;
    };
    /** "visit" or "visits", for a count shown next to ICON_VISITS. */
    const visitsWord = n => (n === 1 ? t('share_visits_one') : t('share_visits'));

    // share.expire/share.revoke carry the link's final visit_count. The live
    // counter is deleted with the link (ShareService::destroyShare(),
    // destroy_file_shares()), so this is the only place the number survives.
    const activityShareStatsSuffix = (event, item) => {
        if (event !== 'share.expire' && event !== 'share.revoke') return '';
        const n = Number(item.visit_count);
        return Number.isFinite(n) ? ` · ${ICON_VISITS} ${esc(String(n))} ${esc(visitsWord(n))}` : '';
    };
    // A link revoked because its target went (the file trashed, the folder or
    // its container deleted) is told from the link's side: "Link #12 · 7
    // visits" on top, then the cause naming the target, whose own card shows
    // the same event from its side (activityLinksLostSuffix()). A manual
    // revoke keeps its plain reason. Literal t() calls so the keys ship.
    /** "Link 12": how a link is named in the log and on its cards. */
    const shareNumber = id => fmt(t('share_link_number'), { id });
    // Every share.* item names its link, so it can be matched to a card in
    // the shares list: share_id in the meta, or the target when the item is
    // logged on the link itself (share.visit, share.failure).
    // A link still live (live_share_ids from /api/activity) is a button to
    // its card in the shares list; a revoked or expired one stays text, with
    // a tooltip saying why it does not open.
    let _liveShareIds = new Set();
    let _liveTargets = {};
    const fetchActivity = async () => {
        const r = await API.system.activity();
        _liveShareIds = new Set(r.live_share_ids || []);
        _liveTargets = r.live_targets || {};
        return r;
    };
    const activityLinkPrefix = (event, item) => {
        if (!event.startsWith('share.')) return '';
        const id = item.share_id ?? (item.target_type === 'share' ? item.target_id : null);
        if (id == null) return '';
        const label = icon('link') + ' ' + esc(shareNumber(id));
        return _liveShareIds.has(Number(id))
            ? `<button type="button" class="btn-link activity-link" data-share-id="${esc(String(id))}">${label}</button>`
            : `<span class="has-tip" title="${esc(t('share_link_gone'))}">${label}</span>`;
    };
    const REVOKE_CAUSES = {
        file_trashed: () => t('log_share_revoke_cause_file_trashed'),
        directory_deleted: () => t('log_share_revoke_cause_directory_deleted'),
        container_deleted: () => t('log_share_revoke_cause_container_deleted'),
        file_missing: () => t('log_share_revoke_cause_file_missing'),
    };
    const revokeCause = (event, item) =>
        event === 'share.revoke' && item.name && item.share_id != null && REVOKE_CAUSES[item.reason]
            ? fmt(REVOKE_CAUSES[item.reason](), { name: item.name })
            : '';
    // file.trash and directory.delete carry shares_destroyed: the links that
    // went with the target, each with its own share.revoke item.
    const activityLinksLostSuffix = (event, item) => {
        if (event !== 'file.trash' && event !== 'directory.delete') return '';
        const n = Number(item.shares_destroyed);
        if (!(n > 0)) return '';
        return ' · ' + icon('link') + ' ' + esc(fmt(n === 1 ? t('activity_links_revoked_one') : t('activity_links_revoked'), { count: n }));
    };
    // Visitor-side share events (card.visitor from the API, see VISITOR_EVENTS
    // in helpers.php) get the blue accent; the owner's own actions --mark-owner.
    // Each occurrence renders as a bordered, wrapping sub-card.
    const renderActivityItem = (event, item, visitor, actorless = false) => {
        const cause = revokeCause(event, item);
        const reason = cause ? '' : activityItemReason(event, item);
        // No event label: the card header shows it once. Only per-item detail
        // renders, and no detail means no line. A share item leads with its link
        // and that link's count (opening, download or preview number).
        let detail = `${activityLinkPrefix(event, item)}${reason ? ' · ' + reason : ''}${activityRealCountSuffix(event, item)}${activitySettingsSuffix(item)}${activityShareStatsSuffix(event, item)}${activityLinksLostSuffix(event, item)}`;
        // Every part after the first starts with ' · '; strip it when nothing
        // precedes it.
        detail = detail.replace(/^\s*·\s*/, '');

        // One field per row, so no field can collide with the timestamp.
        const lines = [];
        if (detail) lines.push(`<span class="activity-item-event">${detail}</span>`);
        if (cause) {
            // The cause names the target, so the target line keeps only its id.
            lines.push(`<span class="activity-item-target">${esc(cause)}</span>`);
            lines.push(...activityTargetLine({ ...item, name: '', target_name: '', prev_name: '' }, 'activity-item-target'));
        } else {
            lines.push(...activityTargetLine(item, 'activity-item-target'));
        }
        lines.push(...activitySettingsChangeLines(event, item));
        lines.push(...activityShareDetailLines(event, item));
        lines.push(...activityMoveLine(event, item));
        // Revoked sessions first, then the device that revoked them.
        lines.push(...activityRevokedSessionLines(event, item));
        const revokedBy = activityRevokedByLine(event, item, visitor);
        if (revokedBy) lines.push(revokedBy);
        lines.push(...activityFailureContextLines(event, item, 'activity-item-failure-context'));
        // Where the address would be: the ip, or for visitor and actorless
        // items, which carry none, a label saying so (activityOriginCell()).
        // session.revoked already has its labelled "revoked by" line.
        if (!REVOKED_SESSION_EVENTS.includes(event)) {
            lines.push(`<span class="activity-item-meta">${activityOriginLine(visitor, item, actorless)}</span>`);
        }

        // The first line shares a row with the timestamp; the rest follow below.
        const head = lines.shift() || '';
        return `<div class="activity-item-card">
            <div class="activity-item-head">${head}<span class="activity-item-time has-tip" title="${esc(fullDate(item.at))}">${relDate(item.at)}</span></div>
            ${lines.join('\n\t\t\t')}
        </div>`;
    };
    // One card: a preview of the previewCount newest items, the rest (capped
    // server-side at item_cap) behind "show more". Both numbers come from
    // SETTINGS_ALLOWED / SETTINGS_DEFAULTS in config_template.php.
    const renderActivityCard = (card, previewCount) => {
        const items = card.items || [];
        const preview = items.slice(0, previewCount);
        const rest = items.slice(previewCount);
        const wrap = document.createElement('div');
        wrap.className = 'activity-card ' + (card.visitor ? 'activity-visitor-side' : 'activity-dashboard-side');
        // No count badge: "show N more" already states the extra count.
        let html = `<div class="activity-card-header"><span class="activity-card-title">${activityEventLabel(card.event)}</span></div>`;
        html += `<div class="activity-item-list">${preview.map(it => renderActivityItem(card.event, it, card.visitor, card.actorless)).join('')}</div>`;
        if (rest.length) {
            html += `<details class="activity-more"><summary>${esc(fmt(rest.length === 1 ? t('activity_show_more_one') : t('activity_show_more'), { count: rest.length }))}</summary><div class="activity-item-list">${rest.map(it => renderActivityItem(card.event, it, card.visitor, card.actorless)).join('')}</div></details>`;
        }
        wrap.innerHTML = html;
        return wrap;
    };

    let _container = null; // { id, name }
    let _directory = null; // { id, name } | null
    // Which container/directory the file list currently shows, and a counter
    // that lets only the latest loadFiles() call render: see there.
    let _filesShownKey = null;
    let _filesLoadSeq = 0;
    let _pending = {};
    // Back-button support for modals (see pushModalHistory/popModalHistory):
    // _modalHistoryPushed is true while the open modal owns a history entry;
    // _modalCancelResolve settles an awaited promptModal()/confirmModal() when
    // Back consumes that entry, so it doesn't hang.
    let _modalHistoryPushed = false;
    let _modalCancelResolve = null;
    // The order every list opens in (helper.js nextSort() gives the others).
    const DEFAULT_SORT = Object.freeze({ by: 'name', dir: 'asc' });
    let _fileSort = DEFAULT_SORT; // files view: name | date | size
    let _fileSortKey = null; // the container/folder _fileSort belongs to (see loadFiles())
    let _highlightFileId = null; // file to flash after search/share navigation
    let _highlightDropzone = false; // flash the drop-zone after directory navigation

    let _searchCache = null; // cached full file list for search
    let _trashFiles = []; // what the trash view last listed, for "Empty trash"
    let _struct = null; // cached {containers:[{id,name,dirs:[{id,name,is_shared}]}], containerCount, dirCount}
    let _loadSettingsPanel = null; // set by bindSettings(), called by navigateToHash()
    let _shrListSplit = false; // global "split URL/key" view toggle for the shares list
    let _shrListCache = null; // last-fetched shares array, reused when toggling split view
    let _shrSort = DEFAULT_SORT; // shares list: name | date | visits
    let _existingSharesCache = null; // last "active shares for this item" array, same purpose

    // ── Toast ─────────────────────────────────────────────────────────────────
    function showToast(msg, type = 'info', duration) {
        // Errors stay longest so there is time to read them.
        if (duration === undefined) {
            duration = type === 'error' ? 6000 : type === 'warning' ? 5000 : type === 'success' ? 3000 : 4000;
        }
        let wrap = $('toast-container');
        if (!wrap) {
            wrap = Object.assign(document.createElement('div'), {
                id: 'toast-container',
                className: 'toast-container'
            });
            document.body.appendChild(wrap);
        }
        // The same message already on screen is replaced, not stacked: a
        // repeated click shows one toast with a fresh timer.
        for (const old of Array.from(wrap.children)) {
            if (old.textContent === msg && old.classList.contains(`toast-${type}`)) old.remove();
        }
        const toast = Object.assign(document.createElement('div'), {
            className: `toast toast-${type}`,
            textContent: msg
        });
        wrap.appendChild(toast);
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(6px)';
            setTimeout(() => toast.remove(), 220);
        }, duration);
    }

    // Share-link copy buttons: copy data-url, the outcome on the button.
    async function copyShareLink(btn) {
        AESBinHelper.flashCopyButton(btn, await AESBinHelper.copyText(btn.dataset.url));
    }

    // ── Boot ──────────────────────────────────────────────────────────────────
    // The viewport meta tag uses interactive-widget=resizes-content, so the
    // layout viewport shrinks when the keyboard opens and centred fixed
    // overlays stay visible without JS.

    async function boot() {
        bindNav();
        bindHeader();
        bindFileViewActions();
        bindUpload();
        bindModals();
        bindRetarget();
        bindTrashEmpty();
        bindDescToolbars();
        bindDescCounters();
        bindSettings();
        bindSearch();
        bindSessions();
        bindAutofillGuard();
        // Server-rendered banners: wired before the first await so an early click
        // is not lost.
        bindMigrateBanner();
        bindStorageMetaBanner();

        // Two checkboxes (shares list and the create dialog's "active links"
        // recap) share one split-view state. Re-renders from cache.
        $('shr-list-split-toggle')?.addEventListener('change', e => setSplitMode(e.target.checked));
        $('shr-existing-split-toggle')?.addEventListener('change', e => setSplitMode(e.target.checked));
        // Shares list order: re-renders from cache, like the split toggle.
        AESBinHelper.bindSortBar($('shr-sort-bar'), () => _shrSort, order => {
            _shrSort = order;
            if (_shrListCache) renderShareList(_shrListCache);
        });

        // Before any API call, so a 401 shows the lock overlay instead of a
        // redirect.
        API.onSessionLost = (reason) => showSessionLock(reason);

        // Reloaded (or come back to) on the entry of a dialog that no longer
        // exists: drop back to the view's own entry.
        if (history.state?.aesbinModal) history.back();
        showView('home');
        try {
            await navigateToHash();
        } catch (bootErr) {
            console.error('Boot navigation error:', bootErr);
            await loadHome();
        }
        initIdleTimer(typeof AESBIN_IDLE_TIMEOUT !== 'undefined' ? AESBIN_IDLE_TIMEOUT : 0);
        initSessionProbe();

        // Non-blocking, delayed to keep startup fast.
        setTimeout(() => checkForUpdates(false), 3000);
        // Also catches a wrong storage_path after an installer reconnect: every
        // file shows as missing and the banner names the path as the cause.
        setTimeout(() => checkIntegrity(), 3500);
        // Security-relevant, so shown early, but still off the critical path.
        setTimeout(() => checkSessions(), 1500);
    }

    // pushState, not location.hash = x, which would scroll to any element with
    // that id. Each view change is a history entry so Back moves through views;
    // repeating the current hash replaces in place.
    function setHash(hash) {
        const url = hash ? '#' + hash : location.pathname + location.search;
        const current = location.hash.replace('#', '');
        if (hash === current) {
            history.replaceState(null, '', url);
        } else {
            history.pushState(null, '', url);
        }
    }
    async function navigateToHash() {
        const hash = location.hash.replace('#', '');

        // #files/3 or #files/3/7: restore container/directory view
        const filesMatch = hash.match(/^files\/(\d+)(?:\/(\d+))?$/);
        if (filesMatch) {
            const cid = parseInt(filesMatch[1], 10);
            const did = filesMatch[2] ? parseInt(filesMatch[2], 10) : null;
            try {
                const struct = await getStruct();
                const c = struct.containers.find(x => x.id === cid);
                if (c) {
                    const dir = did ? (c.dirs.find(d => d.id === did) ?? null) : null;
                    _container = {
                        id: c.id,
                        name: c.name
                    };
                    _directory = dir;
                    showView('files');
                    await loadFiles();
                    return;
                }
            } catch {
                // Fall through to home.
            }
        }

        const known = ['home', 'shares', 'trash', 'activity', 'settings'];
        const view = known.includes(hash) ? hash : 'home';
        showView(view);
        switch (view) {
            case 'shares':
                await loadShares();
                break;
            case 'trash':
                await loadTrash();
                break;
            case 'activity':
                await loadActivity();
                break;
            case 'settings':
                if (_loadSettingsPanel) await _loadSettingsPanel();
                break;
            default:
                _container = null;
                _directory = null;
                await loadHome();
                break;
        }
    }

    // ── Navigation ────────────────────────────────────────────────────────────
    function bindNav() {
        $('btn-logout')?.addEventListener('click', async () => {
            if (await _confirmLeaveUnsaved()) leaveToLogin();
        });
        $('session-lock-btn')?.addEventListener('click', () => {
            _keepUnsaved();
            leaveToLogin();
        });
        // The upload help's size limit opens the card that sets it.
        $('upload-help-limit')?.addEventListener('click', e => {
            e.preventDefault();
            openSettingsCard('set-upload-card');
        });
        // "Link 12" in the activity log (activityLinkPrefix()), on home and on
        // the activity page alike.
        document.addEventListener('click', e => {
            const btn = e.target.closest('.activity-link');
            if (btn) openShareCard(Number(btn.dataset.shareId));
            // A file, folder or container name in the log (activityTargetLine()).
            const target = e.target.closest('.activity-target-link');
            if (target) openActivityTarget(target.dataset.type, Number(target.dataset.id));
        });
    }

    /**
     * Open the folder holding an object and flash it, as a search result does:
     * a file is highlighted in its list, a folder flashes its drop zone.
     * directoryId is null for a container's root. Returns false when the
     * container or folder is gone since the caller's data was loaded.
     */
    async function openLocation(containerId, directoryId, fileId = null) {
        let struct;
        try {
            struct = await getStruct();
        } catch (e) {
            showToast(e.message, 'error');
            return false;
        }
        const c = struct.containers.find(c => c.id === containerId);
        const d = c && directoryId != null ? (c.dirs || []).find(d => d.id === directoryId) : null;
        if (!c || (directoryId != null && !d)) return false;
        if (fileId != null) _highlightFileId = fileId;
        else if (d) _highlightDropzone = true;
        openContainer(c, d ? { id: d.id, name: d.name } : null);
        return true;
    }

    /** An object named in the activity log, from live_targets. */
    async function openActivityTarget(type, id) {
        const loc = _liveTargets[type]?.[id];
        if (!loc) return;
        const ok = type === 'file' ? await openLocation(loc.c, loc.d, id)
            : type === 'directory' ? await openLocation(loc.c, id)
            : await openLocation(id, null);
        if (!ok) showToast(t('target_gone'), 'warning');
    }

    /** The shares list, scrolled to link id and flashed. */
    async function openShareCard(id) {
        setHash('shares');
        showView('shares');
        await loadShares();
        focusShareCard(id);
    }

    /** Settings, scrolled to the card with this id and flashed. */
    async function openSettingsCard(id) {
        setHash('settings');
        showView('settings');
        if (_loadSettingsPanel) await _loadSettingsPanel();
        jumpToCard($(id));
    }

    /** On the shares page: scroll to link id's card and flash it. */
    function focusShareCard(id) {
        const card = document.querySelector(`.shr-card[data-sid="${id}"]`);
        if (!card) {
            // Revoked or expired since the list or the log was loaded.
            showToast(t('share_not_found'), 'warning');
            return;
        }
        card.scrollIntoView({ behavior: 'smooth', block: 'center' });
        flashHighlight(card);
    }

    // Best-effort server logout, then /login. A raw fetch, not the API
    // wrapper: once the session has expired or the IP changed, the call gets
    // a 401, which the wrapper routes to onSessionLost and answers with a
    // never-settling promise. A raw fetch with a timeout always gets here.
    async function rawLogout() {
        const ctrl = new AbortController();
        const tid = setTimeout(() => ctrl.abort(), 3000);
        try {
            await fetch(appUrl('api/auth/logout'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    ...AESBinHelper.csrfHeader()
                },
                signal: ctrl.signal
            });
        } catch {
            // Network error or timeout: the caller carries on regardless.
        } finally {
            clearTimeout(tid);
        }
        API.clearSessionKey();
    }

    // Set when the app itself leaves or reloads the page (logout, a saved
    // language, relocking, a finished migration): the unsaved-settings
    // prompt (see wireDirtyToggle()) must not hold that navigation up.
    let _leavingOnPurpose = false;
    // Set by bindSettings(): asks before Logout throws away unsaved settings,
    // and keeps them in the tab across an expired session's new login.
    let _confirmLeaveUnsaved = async () => true;
    let _keepUnsaved = () => {};
    function reloadPage() {
        _leavingOnPurpose = true;
        location.reload();
    }
    async function leaveToLogin() {
        await rawLogout();
        _leavingOnPurpose = true;
        window.location.href = appUrl('login');
    }

    // Header trash badge, resynced opportunistically rather than live. The
    // initial value is rendered server-side (dashboard.php); loadHome() and
    // loadTrash() resync it, and bumpTrashBadge() adds +1 on trash.
    function updateTrashBadge(count) {
        const b = $('btn-trash-badge');
        if (!b) return;
        if (count > 0) {
            b.textContent = count > 99 ? '99+' : String(count);
            b.hidden = false;
        } else {
            b.hidden = true;
        }
    }
    function bumpTrashBadge(delta) {
        const b = $('btn-trash-badge');
        if (!b) return;
        const current = b.hidden ? 0 : parseInt(b.textContent, 10) || 0;
        updateTrashBadge(Math.max(0, current + delta));
    }

    function openContainer(c, dir = null) {
        _container = {
            id: c.id,
            name: c.name
        };
        _directory = dir;
        showView('files');
        updateNav('files', {
            container: c,
            directory: dir
        });
        setHash(dir ? `files/${c.id}/${dir.id}` : `files/${c.id}`);
        loadFiles();
    }

    // The pages with a header button: the button, and what loads the page
    // (bindHeader()). The button of the page open carries aria-current
    // (showView(), styled in dashboard.css).
    const HEADER_PAGES = {
        shares: { btn: 'btn-shares-nav', load: () => loadShares() },
        activity: { btn: 'btn-activity-nav', load: () => loadActivity() },
        trash: { btn: 'btn-trash-nav', load: () => loadTrash() },
        settings: { btn: 'btn-settings', load: () => _loadSettingsPanel?.() },
    };

    // toTop: go back to the top also when this view is already the one shown
    // (a click on its own button), as arriving on it does.
    function showView(name, toTop = false) {
        // DOM ids avoid the substring "share" (uBlock cosmetic filters match it);
        // the logical name and the URL hash remain "shares".
        const domId = name === 'shares' ? 'view-shrs' : `view-${name}`;
        // Reset scroll only on an actual switch; re-showing the current view
        // (e.g. after an in-place edit) must not jump to the top.
        const isSwitch = !$(domId)?.classList.contains('active');
        document.querySelectorAll('.view').forEach(v => {
            v.classList.remove('active');
            v.hidden = true;
        });
        const v = $(domId);
        if (v) {
            v.classList.add('active');
            v.hidden = false;
        }
        if (isSwitch || toTop) window.scrollTo(0, 0);
        // The logo is the home's button: dotted underline while on the home,
        // and inside a container or folder too, which are only reached from
        // the home (the breadcrumb starts there): "page" on the home itself,
        // "true" (the section you are in) below it. The header buttons are
        // sibling sections, so on those the logo goes dark.
        const logo = document.querySelector('.app-logo');
        if (name === 'home') logo?.setAttribute('aria-current', 'page');
        else if (name === 'files') logo?.setAttribute('aria-current', 'true');
        else logo?.removeAttribute('aria-current');
        for (const [view, { btn: id }] of Object.entries(HEADER_PAGES)) {
            const btn = $(id);
            if (!btn) continue;
            if (view === name) btn.setAttribute('aria-current', 'page');
            else btn.removeAttribute('aria-current');
        }
        // A listing still in flight for the view being left must not draw
        // over this one when it answers (its breadcrumb included).
        if (name !== 'files') _filesLoadSeq++;
        if (name !== 'home') _homeLoadSeq++;
        // Coming back to a view later, it opens in the default order again.
        if (name !== 'files') _fileSortKey = null;
        if (name !== 'shares') _shrSort = DEFAULT_SORT;
        updateNav(name);
    }

    // To the dashboard home, inside the page. Shared by the "Dashboard"
    // crumb and the logo, so both behave the same: no reload, no
    // leave-the-page prompt, unsaved settings stay in their fields. On the
    // home already, it reloads the data and goes back to the top.
    function goHome() {
        _container = null;
        _directory = null;
        setHash('');
        showView('home', true);
        loadHome();
    }

    /** Renders the h2 navigation: Home [> Section [> Sub]].
     *  The last crumb (current view) is not clickable. */
    function updateNav(viewName, opts = {}) {
        const nav = $('app-nav');
        if (!nav) return;
        // crumb(label, id): with an id a clickable link, without an inert leaf.
        const crumb = (label, id = null) => id ?
            `<span class="nav-link" id="${id}">${label}</span>` :
            `<span class="nav-current">${label}</span>`;
        const homeLabel = `${icon('house')} ${esc(t('dashboard'))}`;
        const parts = [];
        if (viewName === 'home') {
            parts.push(crumb(homeLabel)); // home is the leaf
        } else {
            parts.push(crumb(homeLabel, 'nav-btn-home'));
            if (viewName === 'files' && opts.container) {
                const cLabel = `${icon('archive')} ${esc(opts.container.name)}`;
                if (opts.directory) {
                    parts.push(crumb(cLabel, 'nav-btn-container')); // link → container root
                    parts.push(crumb(`${icon('folder')} ${esc(opts.directory.name)}`));
                } else {
                    parts.push(crumb(cLabel)); // container is the leaf
                }
            } else {
                const labels = {
                    files: opts.container?.name || '…',
                    settings: t('settings'),
                    trash: t('trash'),
                    activity: t('activity'),
                    shares: t('active_shares'),
                };
                parts.push(crumb(esc(labels[viewName] || viewName)));
            }
        }
        nav.innerHTML = parts.join(' <span class="nav-sep">›</span> ');
        nav.querySelector('#nav-btn-home')?.addEventListener('click', goHome);
        // Container crumb is clickable only inside a directory.
        nav.querySelector('#nav-btn-container')?.addEventListener('click', () => {
            if (_container) {
                _directory = null;
                setHash(`files/${_container.id}`);
                loadFiles();
            }
        });
    }

    // "Loading…" in el, but only if the request is still running after a
    // short delay: a quick answer then goes straight on screen, with no
    // placeholder flashing in between. isCurrent() is checked when the timer
    // fires, so a superseded load never covers a newer one's content.
    // Returns the function that cancels it.
    const LOADING_DELAY_MS = 300;
    function showLoadingLater(el, isCurrent) {
        const timer = setTimeout(() => {
            if (isCurrent()) el.innerHTML = `<div class="home-card home-card-full home-loading">${esc(t('loading'))}</div>`;
        }, LOADING_DELAY_MS);
        return () => clearTimeout(timer);
    }

    // ── Home overview ─────────────────────────────────────────────────────────
    // Counts loadHome() calls: only the latest one renders.
    let _homeLoadSeq = 0;
    async function loadHome() {
        const _fva = $('file-view-actions');
        if (_fva) _fva.hidden = true;
        const _sb = $('sort-bar');
        if (_sb) _sb.hidden = true;
        const grid = $('home-grid');
        if (!grid) return;
        const loadSeq = ++_homeLoadSeq;
        const isCurrent = () => loadSeq === _homeLoadSeq;

        // First load: the grid is empty, so the tree goes on screen as soon as
        // it is ready and the other cards follow. Coming back to home, or a
        // refresh after an action: the previous content stays until the new
        // one is complete and is then swapped in one go, so the page neither
        // flashes a placeholder nor shrinks to the tree and grows back.
        const refresh = !!grid.querySelector('.tree-wrap');
        const out = refresh ? document.createElement('div') : grid;
        const cancelLoading = refresh ? () => {} : showLoadingLater(grid, isCurrent);

        // Step 1: containers first, so the tree shows quickly.
        let cRes = null;
        try {
            cRes = await API.containers.list();
        } catch {
            // Timeout: the retry card below.
        }
        if (!isCurrent()) return;

        if (!cRes) {
            cancelLoading();
            grid.innerHTML = `<div class="home-card home-card-full">
      <p>${icon('hourglass')} ${esc(t('loading'))} — <small>${esc(t('server_slow'))}</small></p>
      <button class="btn btn-primary btn-sm btn-retry">${icon('refresh-cw')} ${esc(t('retry'))}</button>
    </div>`;
            grid.querySelector('.btn-retry')?.addEventListener('click', loadHome);
            return;
        }

        const containers = cRes.containers || [];
        // Every directory in one request, grouped here (as getStruct()).
        const dRes = await API.directories.list().catch(() => ({
            directories: []
        }));
        const containerData = groupByContainer(containers, dRes.directories || []);

        if (!isCurrent()) return;
        cancelLoading();

        // Render tree immediately
        if (!refresh) grid.innerHTML = '';
        const cardTree = makeCard('folder-tree', t('filesystem_tree'));
        cardTree.classList.add('home-card-full');
        const treeWrap = cardTree.querySelector('.home-card-list-wrap');
        treeWrap.className = 'tree-wrap';

        if (!containers.length) {
            treeWrap.innerHTML = `<p class="home-empty">${esc(t('no_containers'))}</p>`;
        } else {
            const treeList = document.createElement('div');
            treeList.className = 'tree-list';
            containerData.forEach(({
                container: c,
                dirs
            }) => {
                const cRow = document.createElement('div');
                cRow.className = 'tree-row tree-container';
                cRow.innerHTML = `
        <span class="tree-icon">${icon('archive')}</span>
        <span class="tree-name" title="ID: ${c.id}">${esc(c.name)}<span class="fname-count"> (${dirs.length})</span></span>
        <span class="tree-actions">
          <button class="btn-icon" title="${esc(t('new_directory'))}">${icon('folder-plus')}<span class="btn-label"> ${esc(t('new_directory'))}</span></button>
          <button class="btn-icon" title="${esc(t('rename'))}">${icon('pencil')}<span class="btn-label"> ${esc(t('rename'))}</span></button>
          <button class="btn-icon btn-icon-danger" title="${esc(t('delete'))}">${icon('trash-2')}<span class="btn-label"> ${esc(t('delete'))}</span></button>
        </span>`;
                cRow.querySelector('.tree-name').addEventListener('click', () => openContainer(c));
                const [cNew, cRen, cDel] = cRow.querySelectorAll('.tree-actions .btn-icon');
                cNew.addEventListener('click', async e => {
                    e.stopPropagation();
                    openDirInfo(null, c.id);
                });
                cRen.addEventListener('click', async e => {
                    e.stopPropagation();
                    const name = await promptModal(t('container_rename_lbl'), c.name, 'ID: ' + c.id);
                    if (!name || name === c.name) return;
                    try {
                        await API.containers.rename(c.id, name);
                        _searchCache = null;
                        loadHome();
                        showToast(t('renamed'), 'success', 2000);
                    } catch (err) {
                        showToast(err.message, 'error');
                    }
                });
                cDel.addEventListener('click', async e => {
                    e.stopPropagation();
                    if (!await confirmModal(deleteConfirmText(
                        true,
                        c.name,
                        dirs.reduce((n, d) => n + (d.file_count || 0), 0),
                        // has_shares, not is_shared: a container with no shared
                        // folder can still hold shared files, which die with it.
                        dirs.some(d => d.has_shares)
                    ))) return;
                    try {
                        const res = await API.containers.delete(c.id);
                        _searchCache = null;
                        loadHome();
                        shareLossToast(res, t('container_deleted'));
                    } catch (err) {
                        showToast(err.message, 'error');
                    }
                });
                treeList.appendChild(cRow);

                dirs.forEach(dir => {
                    const dRow = document.createElement('div');
                    dRow.className = 'tree-row tree-dir';
                    dRow.innerHTML = `
          <span class="tree-indent"></span>
          <span class="tree-icon">${icon('folder')}</span>
          <span class="tree-name" title="ID: ${dir.id}">${esc(dir.name)}<span class="fname-count"> (${dir.file_count ?? 0})</span></span>
          <span class="tree-actions">
            <button class="btn-icon" title="${esc(t('shr'))}">${dir.is_shared ? icon('link') : icon('unlink')}<span class="btn-label"> ${esc(t('shr'))}</span>${countBadge(dir.share_count, t('badge_links_active'))}</button>
            ${containerData.length >= 2 ? `<button class="btn-icon" title="${esc(t('move'))}">${icon('folder-input')}<span class="btn-label"> ${esc(t('move'))}</span></button>` : ''}
            <button class="btn-icon" title="${esc(t('rename'))}">${icon('pencil')}<span class="btn-label"> ${esc(t('rename'))}</span></button>
            <button class="btn-icon btn-icon-danger" title="${esc(t('delete'))}">${icon('trash-2')}<span class="btn-label"> ${esc(t('delete'))}</span></button>
          </span>`;
                    dRow.querySelector('.tree-name').addEventListener('click', () => openContainer(c, {
                        id: dir.id,
                        name: dir.name
                    }));
                    const treeBtns = [...dRow.querySelectorAll('.tree-actions .btn-icon')];
                    let bi = 0;
                    const dShr = treeBtns[bi++];
                    const dMov = containerData.length >= 2 ? treeBtns[bi++] : null;
                    const dRen = treeBtns[bi++];
                    const dDel = treeBtns[bi];
                    dShr.addEventListener('click', e => {
                        e.stopPropagation();
                        openShareModal('directory', dir.id, dir.name);
                    });
                    if (dMov) dMov.addEventListener('click', e => {
                        e.stopPropagation();
                        openDirMoveModal(dir.id, dir.name, c.id);
                    });
                    dRen.addEventListener('click', e => {
                        e.stopPropagation();
                        openDirInfo(dir);
                    });
                    dDel.addEventListener('click', async e => {
                        e.stopPropagation();
                        if (!await confirmModal(deleteConfirmText(
                            false, dir.name, dir.file_count || 0, !!dir.has_shares
                        ))) return;
                        try {
                            const res = await API.directories.delete(dir.id);
                            _searchCache = null;
                            loadHome();
                            shareLossToast(res, t('directory_deleted'));
                        } catch (err) {
                            showToast(err.message, 'error');
                        }
                    });
                    treeList.appendChild(dRow);
                });
            });
            treeWrap.appendChild(treeList);
        }

        const addCBtn = document.createElement('button');
        addCBtn.className = 'home-card-action tree-new-container';
        addCBtn.textContent = t('container_add_btn');
        addCBtn.addEventListener('click', async () => {
            const name = await promptModal(t('container_new_lbl'));
            if (!name?.trim()) return;
            try {
                await API.containers.create(name.trim());
                _searchCache = null;
                loadHome();
                showToast(t('container_created'), 'success', 2000);
            } catch (e) {
                showToast(e.message, 'error');
            }
        });
        treeWrap.appendChild(addCBtn);

        out.appendChild(cardTree);

        // Step 2: activity and stats in parallel.
        try {
            const [actRes, infoRes] = await Promise.all([
                fetchActivity().catch(() => ({
                    cards: []
                })),
                API.system.status().catch(() => ({})),
            ]);
            if (!isCurrent()) return;
            const info = infoRes;
            updateTrashBadge(info.trash_count ?? 0);
            const cardA = makeCard('scroll-text', t('recent_activity'));

            cardA.classList.add('home-card-full');
            const listA = document.createElement('div');
            listA.id = 'home-activity';
            fillHomeActivity(listA, actRes);
            cardA.querySelector('.home-card-list-wrap').appendChild(listA);
            out.appendChild(cardA);

            const cardSh = makeCard('chart-line', t('share_stats_title'));
            cardSh.classList.add('home-card-full');
            const shWrap = cardSh.querySelector('.home-card-list-wrap');
            shWrap.innerHTML = shareStatsRows(actRes.share_stats);
            // Like every home card, a way on: the links these totals count.
            const shBtn = Object.assign(document.createElement('button'), {
                className: 'home-card-action',
                textContent: t('active_shares') + ' →'
            });
            shBtn.addEventListener('click', () => {
                setHash('shares');
                showView('shares');
                loadShares();
            });
            shWrap.appendChild(shBtn);
            out.appendChild(cardSh);

            const cardFS = makeCard('server', t('filesystem_info'));
            cardFS.classList.add('home-card-full');
            const fsWrap = cardFS.querySelector('.home-card-list-wrap');
            const spaceQuota = esc(info.quota_human || '∞');
            const quotaPct = info.quota_bytes ?
                Math.min(100, ((info.total_size || 0) / info.quota_bytes) * 100).toFixed(1) : null;
            fsWrap.innerHTML = `
      <div class="fs-stat-row${quotaPct !== null ? ' fs-has-quota' : ''}"><span class="fs-stat-label">${esc(t('total_size_label'))}</span><span class="fs-stat-val">${esc(info.total_size_human || '—')} / ${spaceQuota}</span></div>
      ${quotaPct !== null ? '<div class="fs-quota-bar"><div class="fs-quota-fill" id="_qfill"></div></div>' : ''}
      <div class="fs-stat-row"><span class="fs-stat-label">${esc(t('files_in_system'))}</span><span class="fs-stat-val">${info.file_count ?? '—'}</span></div>
      <div class="fs-stat-row"><span class="fs-stat-label">${esc(t('file_in_trash'))}</span><span class="fs-stat-val">${info.trash_count ?? '—'}</span></div>
      <div class="fs-stat-row"><span class="fs-stat-label">${esc(t('passkey_count_label'))}</span><span class="fs-stat-val">${info.passkey_count ?? '—'}</span></div>
      <div class="fs-stat-row"><span class="fs-stat-label">${esc(t('aesbin_version_label'))}</span><span class="fs-stat-val">${esc(info.aesbin_version || '—')} (DB v${esc(String(info.db_schema_version ?? '—'))})</span></div>
      <div class="fs-stat-row"><span class="fs-stat-label">${esc(t('php_version_label'))}</span><span class="fs-stat-val">${esc(info.php_version || '—')}</span></div>
      <div class="fs-stat-row"><span class="fs-stat-label">${esc(t('db_version_label'))}</span><span class="fs-stat-val">${esc(info.db_version || '—')}</span></div>`;
            const qFill = fsWrap.querySelector('#_qfill');
            if (qFill) {
                qFill.style.width = quotaPct + '%';
                qFill.removeAttribute('id');
            }

            // Every row above is changed in settings, so this links there, the same
            // way the activity card links to "all activity →".
            const setBtn = document.createElement('button');
            setBtn.className = 'home-card-action';
            setBtn.textContent = t('settings') + ' →';
            setBtn.addEventListener('click', () => {
                setHash('settings');
                showView('settings');
                if (_loadSettingsPanel) _loadSettingsPanel();
            });
            fsWrap.appendChild(setBtn);

            out.appendChild(cardFS);
        } catch {
            // An activity or stats failure must not block the home view.
        }
        if (out !== grid && isCurrent()) grid.replaceChildren(...out.childNodes);
    }

    // The home "Recent activity" card body: the 5 most recent cards with one
    // item each. Also refilled on its own when the tab comes back into view
    // (refreshLiveStats()).
    function fillHomeActivity(listA, actRes) {
        const cards = actRes.cards || [];
        listA.innerHTML = '';
        if (!cards.length) {
            listA.innerHTML = `<p class="home-empty">${esc(t('no_activity'))}</p>`;
        } else {
            // Reuses renderActivityItem() so the widget reads like the full page.
            // Capped at 5 cards, 1 item each, no "show more".
            // Already newest first (activity.php orders by created_at).
            const recent = cards.slice(0, 5);
            // No count line: with the 5-card cap it would always show the same number.
            recent.forEach(card => {
                if (!card.items?.length) return;
                const wrap = renderActivityCard({ ...card, items: card.items.slice(0, 1) }, 1);
                wrap.classList.add('home-activity-card');
                listA.appendChild(wrap);
            });
            // <button>, not <span>, so it is focusable by keyboard.
            const allLink = Object.assign(document.createElement('button'), {
                className: 'home-card-action',
                textContent: t('all_activity') + ' →'
            });
            allLink.addEventListener('click', () => {
                setHash('activity');
                showView('activity');
                loadActivity();
            });
            listA.appendChild(allLink);
        }
    }

    function makeCard(iconName, title) {
        const card = document.createElement('div');
        card.className = 'home-card';
        card.innerHTML = `
    <div class="home-card-header">
      <span class="home-card-icon">${icon(iconName)}</span>
      <span class="home-card-title">${esc(title)}</span>
    </div>
    <div class="home-card-list-wrap"></div>`;
        return card;
    }

    // ── Files view ────────────────────────────────────────────────────────────
    async function loadFiles() {
        // An action that refreshes the listing when its request answers
        // (trash, rename, upload...) may answer after the user has moved to
        // another view: redrawing the folder then would take over that view's
        // breadcrumb. The folder reloads when it is opened again.
        if (_container && !$('view-files')?.classList.contains('active')) return;

        // Consume the highlight flags up front, so an early return (API error,
        // missing container) cannot leave one to fire on the next navigation.
        // A file highlight excludes the drop-zone highlight.
        const hlFileId = _highlightFileId;
        _highlightFileId = null;
        const hlDrop = _highlightDropzone && !hlFileId;
        _highlightDropzone = false;

        // "New directory" only at container root; Upload only inside a directory.
        const newDirBtn = $('btn-new-dir');
        const newFileBtn = $('btn-new-file');
        if (newDirBtn) newDirBtn.hidden = !!_directory; // only at container root
        if (newFileBtn) newFileBtn.hidden = !_directory; // only inside a directory
        const dz = $('drop-zone');
        if (dz) dz.hidden = !_directory; // hide drop zone at container level
        // The metadata box and the formats list only next to the drop zone.
        const stripBox = $('upload-strip');
        if (stripBox) stripBox.hidden = !_directory;
        const fmtHelper = $('upload-formats');
        if (fmtHelper) fmtHelper.hidden = !_directory;
        const _fvaInner = $('file-view-actions');
        if (_fvaInner) _fvaInner.hidden = false;
        // sort-bar is shown/hidden based on isEmpty (below)

        const tbody = $('file-list');
        const noFiles = $('no-files');
        if (!tbody) return;

        if (!_container) {
            showView('home');
            loadHome();
            return;
        }

        // Opening a different container or directory: clear the old listing
        // first, or it stays on screen until the new one arrives. A reload of
        // the same place keeps it, so an in-place refresh does not blink.
        // Only the latest call renders: with quick navigation an older request
        // can answer last and would draw the folder the user already left.
        const loadSeq = ++_filesLoadSeq;
        const isCurrent = () => loadSeq === _filesLoadSeq;
        const shownKey = `${_container.id}/${_directory?.id ?? ''}`;
        let cancelLoading = () => {};
        if (shownKey !== _filesShownKey) {
            _filesShownKey = null;
            const notice = $('dir-shared-notice');
            if (notice) notice.hidden = true;
            tbody.innerHTML = '';
            if (noFiles) noFiles.hidden = true;
            const sortBar = $('sort-bar');
            if (sortBar) sortBar.hidden = true;
            cancelLoading = showLoadingLater(tbody, isCurrent);
        }

        let fRes, dRes, st;
        try {
            [fRes, dRes, st] = await Promise.all([
                API.files.list(_container.id, _directory?.id ?? null),
                API.directories.list(_container.id),
                getStruct(true).catch(() => ({
                    containers: [],
                    containerCount: 0,
                    dirCount: 0
                })),
            ]);
        } catch (loadErr) {
            cancelLoading();
            if (!isCurrent()) return;
            tbody.innerHTML = `<div class="home-card"><p class="text-danger">${icon('triangle-alert', 'ic-warn')} ${esc(loadErr.message)}</p><button class="btn btn-primary btn-sm btn-retry">${icon('refresh-cw')} ${esc(t('retry'))}</button></div>`;
            tbody.querySelector('.btn-retry')?.addEventListener('click', loadFiles);
            return;
        }
        cancelLoading();
        if (!isCurrent()) return;
        _filesShownKey = shownKey;
        renderBreadcrumb();

        // Move buttons only make sense if there is somewhere else to move to.
        const showFileMove = (st?.dirCount ?? 0) >= 2;
        const showDirMove = (st?.containerCount ?? 0) >= 2;

        tbody.innerHTML = '';
        AESBinPreview.resetPreviews();

        // A directory link serves every file in the directory, including the
        // ones added later (autoRefreshForDirectory() on the server), while
        // each file's link badge counts only links to that file. Said once, above.
        const sharedNotice = $('dir-shared-notice');
        if (sharedNotice) {
            const here = _directory ? (dRes.directories || []).find(d => d.id === _directory.id) : null;
            setIconText(sharedNotice, 'link', t('dir_shared_notice'));
            sharedNotice.hidden = !here?.is_shared;
        }

        const dirs = _directory ? [] : (dRes.directories || []);
        const files = fRes.files || [];

        // ── Apply sort ────────────────────────────────────────────────────────
        // Each container and folder opens in the default order: a reload of
        // the same one (after an upload, a rename) keeps the order chosen.
        const sortKey = `${_container?.id ?? ''}/${_directory?.id ?? ''}`;
        if (sortKey !== _fileSortKey) {
            _fileSortKey = sortKey;
            _fileSort = DEFAULT_SORT;
        }
        const sortedDirs = AESBinHelper.sortItems(dirs, _fileSort, {
            name: d => d.name,
            date: d => parseServerDate(d.created_at),
            size: d => d.file_count ?? 0,
        });
        const sortedFiles = AESBinHelper.sortItems(files, _fileSort, AESBinHelper.FILE_SORT_KEYS);

        AESBinHelper.paintSortBar($('sort-bar'), _fileSort);
        const isEmpty = files.length === 0 && dirs.length === 0;
        const totalItems = files.length + dirs.length;
        if (noFiles) {
            noFiles.hidden = !isEmpty;
            noFiles.textContent = _directory ? t('no_files') : t('no_dirs_in_container');
        }
        const sortBar2 = $('sort-bar');
        if (sortBar2) sortBar2.hidden = totalItems <= 1; // pointless to sort 0 or 1 item
        // At container level (dirs only) a date sort is not meaningful.
        const dateSortBtn = document.querySelector('#sort-bar .sort-btn[data-sort="date"]');
        if (dateSortBtn) dateSortBtn.hidden = !_directory;

        // ── Directories ───────────────────────────────────────────────────────
        sortedDirs.forEach(dir => {
            const card = document.createElement('div');
            card.className = 'file-card dir-card';
            card.innerHTML = `
      <div class="fc-main">
        <div class="fc-name">
          <span class="fname" title="ID: ${dir.id}">${icon('folder')} ${esc(dir.name)}<span class="fname-count">${dir.file_count != null ? ` (${dir.file_count})` : ''}</span></span>
          ${dir.description ? `<span class="file-sub-desc">${icon('quote')} ${fmtDesc(dir.description)}</span>` : ''}
        </div>

      </div>
      <div class="fc-actions">
        <button class="btn-icon" title="${esc(t('shr'))}" data-da="shr">${dir.is_shared ? icon('link') : icon('unlink')}<span class="btn-label"> ${esc(t('shr'))}</span>${countBadge(dir.share_count, t('badge_links_active'))}</button>
        ${showDirMove ? `<button class="btn-icon" title="${esc(t('move'))}" data-da="move">${icon('folder-input')}<span class="btn-label"> ${esc(t('move'))}</span></button>` : ''}
        <button class="btn-icon" title="${esc(t('rename'))}" data-da="ren">${icon('pencil')}<span class="btn-label"> ${esc(t('rename'))}</span></button>
        <button class="btn-icon btn-icon-danger" title="${esc(t('delete'))}" data-da="del">${icon('trash-2')}<span class="btn-label"> ${esc(t('delete'))}</span></button>
      </div>`;
            card.querySelector('.fname').addEventListener('click', () => {
                _directory = {
                    id: dir.id,
                    name: dir.name
                };
                setHash(`files/${_container.id}/${dir.id}`);
                loadFiles();
            });
            card.querySelector('[data-da="shr"]').addEventListener('click', () => openShareModal('directory', dir.id, dir.name));
            if (showDirMove) card.querySelector('[data-da="move"]')?.addEventListener('click', () => openDirMoveModal(dir.id, dir.name, _container.id));
            card.querySelector('[data-da="ren"]').addEventListener('click', () => openDirInfo(dir));
            card.querySelector('[data-da="del"]').addEventListener('click', async () => {
                if (!await confirmModal(deleteConfirmText(
                    false, dir.name, dir.file_count || 0, !!dir.has_shares
                ))) return;
                try {
                    const res = await API.directories.delete(dir.id);
                    _searchCache = null;
                    shareLossToast(res, t('directory_deleted'));
                    loadFiles();
                } catch (e) {
                    showToast(e.message, 'error');
                }
            });
            tbody.appendChild(card);
        });

        // ── Files ─────────────────────────────────────────────────────────────
        sortedFiles.forEach(f => {
            const canPreview = isPreviewable(f.mime);
            const card = document.createElement('div');
            card.className = 'file-card';
            card.dataset.fid = String(f.id);
            card.innerHTML = `
      <div class="fc-main">
        <div class="fc-name">
          <span class="fname" title="ID: ${f.id}">${mimeIcon(f.mime, f.original_name)} ${esc(f.original_name)}</span>
          ${f.description ? `<span class="file-sub-desc">${icon('quote')} ${fmtDesc(f.description)}</span>` : ''}
        </div>
        <div class="fc-meta">
          <span class="fc-meta-item">${f.size_human || fmtBytes(f.size)}</span>
          <span class="fc-meta-item">${esc(f.mime || '—')}</span>
          <span class="fc-meta-item">${relDate(f.created_at)}</span>
        </div>
        ${renderHashDetails(f, f.id)}
      </div>
      <div class="fc-actions">
        <button class="btn-icon" title="${esc(t('download'))}" data-a="dl">${icon('download')}<span class="btn-label"> ${esc(t('download'))}</span>${countBadge(f.download_count, Number(f.download_count) === 1 ? t('badge_downloads_total_one') : t('badge_downloads_total'))}</button>
        ${canPreview ? `<button class="btn-icon" id="prev-btn-${f.id}" title="${esc(t('preview'))}" data-a="open"><span class="icon">${icon('eye')}</span> <span class="btn-label"> ${esc(t('preview'))}</span>${countBadge(f.preview_count, Number(f.preview_count) === 1 ? t('badge_previews_total_one') : t('badge_previews_total'))}</button>` : ''}
        <button class="btn-icon" title="${esc(t('shr'))}" data-a="shr">${f.is_shared ? icon('link') : icon('unlink')}<span class="btn-label"> ${esc(t('shr'))}</span>${countBadge(f.share_count, t('badge_links_active'))}</button>
        ${showFileMove ? `<button class="btn-icon" title="${esc(t('move'))}" data-a="move">${icon('folder-input')}<span class="btn-label"> ${esc(t('move'))}</span></button>` : ''}
        <button class="btn-icon" title="${esc(t('edit'))}" data-a="edit">${icon('pencil')}<span class="btn-label"> ${esc(t('edit'))}</span></button>
        <button class="btn-icon btn-icon-danger" title="${esc(t('trash_move'))}" data-a="trash">${icon('trash-2')}<span class="btn-label"> ${esc(t('trash_move'))}</span></button>
      </div>
      ${canPreview ? `<div id="prev-row-${f.id}" class="fc-preview" hidden><div id="prev-body-${f.id}"></div></div>` : ''}`;
            AESBinHelper.bindCopyButtons(card);
            const byA = a => card.querySelector(`[data-a="${a}"]`);
            byA('dl').addEventListener('click', () => doDownload(f.id, f.original_name));
            if (canPreview) byA('open').addEventListener('click', () => {
                closeAllHashes();
                AESBinPreview.togglePreview(f, id => API.files.fetchBlob(id, 'preview'));
            });
            byA('shr').addEventListener('click', () => openShareModal('file', f.id, f.original_name));
            if (showFileMove) byA('move').addEventListener('click', () => openMoveModal(f.id, f.original_name, f.container_id, f.directory_id));
            byA('edit').addEventListener('click', () => openFileModal({
                mode: 'edit',
                file: f,
                cid: _container.id,
                did: _directory?.id ?? null
            }));
            byA('trash').addEventListener('click', async () => {
                // Share links die with the trashing and restoring does not bring them
                // back, so warn before the click. is_shared is the flag this row's
                // link/unlink icon already uses.
                if (!await confirmModal(withShareWarning(
                    fmt(t('file_trash_confirm'), { name: f.original_name }),
                    !!f.is_shared
                ))) return;
                try {
                    const res = await API.files.trash(f.id);
                    _searchCache = null;
                    bumpTrashBadge(1);
                    // shareLossToast() says so and stays up longer when
                    // shares_destroyed > 0: the loss is not undoable.
                    shareLossToast(res, t('file_trashed'));
                    loadFiles();
                } catch (e) {
                    showToast(e.message, 'error');
                }
            });
            tbody.appendChild(card);
        });

        // After the file loop, so the card exists. Scroll it to the middle of the
        // viewport and flash only that card.
        if (hlFileId) {
            setTimeout(() => {
                const target = tbody?.querySelector(`[data-fid="${hlFileId}"]`);
                if (target) {
                    flashHighlight(target);
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                }
            }, 150);
        }

        // Drop-zone flash, only when no file is highlighted (see the flags at the
        // top).
        else if (hlDrop) {
            setTimeout(() => {
                const dz = $('drop-zone');
                if (dz && !dz.hidden) {
                    flashHighlight(dz);
                    dz.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                }
            }, 150);
        }
    }

    function renderBreadcrumb() {
        updateNav('files', {
            container: _container,
            directory: _directory
        });
    }

    // ── Upload (a directory must be open) ─────────────────────────────────────
    function bindUpload() {
        const fi = $('file-input');
        if (!fi) return;
        // The note in the drop zone says what the box currently means.
        const strip = $('upload-strip-meta');
        strip?.addEventListener('change', () => {
            $('upload-meta-note').textContent = strip.checked
                ? t('upload_meta_note_on')
                : t('upload_meta_note_off');
        });
        fi.addEventListener('change', () => {
            const files = Array.from(fi.files || []);
            // Cleared, so picking the same file again fires 'change' again.
            fi.value = '';
            handleFiles(files);
        });

        // Files live in a directory, never at container root.
        const canUpload = () => {
            if (!_container) {
                showToast(t('select_container'), 'warning');
                return false;
            }
            if (!_directory) {
                showToast(t('upload_dir_required'), 'warning');
                return false;
            }
            return true;
        };

        const dz = $('drop-zone');
        dz?.addEventListener('click', () => {
            if (canUpload()) fi.click();
        });
        dz?.addEventListener('dragover', e => {
            e.preventDefault();
            dz.classList.add('drag-over');
        });
        dz?.addEventListener('dragleave', () => dz.classList.remove('drag-over'));
        dz?.addEventListener('drop', e => {
            e.preventDefault();
            dz.classList.remove('drag-over');
            if (canUpload()) handleFiles(e.dataTransfer.files);
        });
    }

    // Header buttons, the requirements banner and the custom-403 fix. Not in
    // bindUpload(), which returns early on a page without the file input.
    // The header in the room it has (dashboard.css, "Header that does not
    // fit"): the first of these steps that fits, else the last. All labels
    // or none: every button with its label, Logout included, or every
    // button an icon; only then, on the narrowest phones, the icons move
    // closer and get narrower.
    // Measured, not set by width breakpoints, since the room taken depends
    // on the language of the labels. It fits when nothing passes its right
    // padding either: content pushed into the padding does not make it
    // scroll, but sits against the screen's edge.
    const HEADER_STEPS = [
        ['hdr-labels'],
        [],
        ['hdr-tight'],
        ['hdr-tight', 'hdr-tighter'],
    ];
    const HEADER_CLASSES = [...new Set(HEADER_STEPS.flat())];
    const fits = el => {
        const last = el.lastElementChild;
        const edge = el.getBoundingClientRect().right - parseFloat(getComputedStyle(el).paddingRight);
        return el.scrollWidth <= el.clientWidth && (!last || last.getBoundingClientRect().right <= edge + 0.5);
    };
    function fitHeader() {
        const header = document.querySelector('.app-header');
        if (!header) return;
        for (const step of HEADER_STEPS) {
            header.classList.remove(...HEADER_CLASSES);
            header.classList.add(...step);
            if (fits(header)) return;
        }
    }

    function bindHeader() {
        fitHeader();
        window.addEventListener('resize', fitHeader);
        // ── App header navigation ─────────────────────────────────────────────
        // The logo (.app-logo) is an <a href="base_url()"> (dashboard.php), so
        // copy-link and open-in-new-tab work as on any link. A plain click
        // goes home inside the page, like the "Dashboard" crumb; a click with
        // a modifier or a middle click is left to the browser.
        document.querySelector('.app-logo')?.addEventListener('click', e => {
            if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
            e.preventDefault();
            goHome();
        });
        // A header button opens its page; on the page already open it reloads
        // it and goes back to the top, as arriving there does. Settings keeps
        // its unsaved values across the reload (loadSettingsPanel()).
        for (const [view, { btn, load }] of Object.entries(HEADER_PAGES)) {
            $(btn)?.addEventListener('click', () => {
                setHash(view);
                showView(view, true);
                load();
            });
        }
        // A card named in another card's text (the Backup card's "see the
        // Credential reset card"). The href stays #settings, so the hash, and
        // with it the view, does not change.
        document.addEventListener('click', e => {
            const link = e.target.closest('.settings-jump');
            if (!link) return;
            e.preventDefault();
            jumpToCard($(link.dataset.card));
        });
        // Requirements banner (Settings only, rendered only when a check fails):
        // to the first failing check, not to the top of its card. The list is
        // long and keeps the installer's order, so the one line that is not
        // green can be two screens below the card's title.
        $('reqs-bar-open')?.addEventListener('click', () => {
            const card = $('reqs-card');
            jumpToCard(card, card?.querySelector('.reqs-warn') || card);
        });
        // The config line a failing requirement wants pasted. user-select: all lets
        // it be copied by hand where writeText() is missing (plain HTTP); where it
        // exists, is-copyable turns that off so a tap does not raise the system
        // copy bar over the toast.
        if (navigator.clipboard?.writeText) {
            document.querySelectorAll('.reqs-detail').forEach(el => {
                el.classList.add('is-copyable');
                el.addEventListener('click', () => {
                    const text = (el.textContent || '').trim();
                    // Confirmed in the card like a settings save, not with a
                    // toast: Android draws its clipboard preview over the toast
                    // corner.
                    AESBinHelper.copyText(text).then(ok => ok
                        ? showCardMsg($('reqs-msg'), t('copied'))
                        : showCardMsg($('reqs-msg'), t('copy_failed'), 'danger'));
                });
            });
        }
        // Custom 403 fix (Requirements::wireErrorDocument()). _req() raises the
        // password prompt when elevation is needed. Reloaded so the row is
        // re-checked against the file itself.
        $('req-fix-403')?.addEventListener('click', async (ev) => {
            const btn = ev.currentTarget;
            if (!await confirmModal(t('req_fix_403_confirm'), null, t('req_fix_btn'), false)) return;
            btn.disabled = true;
            try {
                await API.system.fix403();
                showToast(t('req_fix_403_done'), 'success');
                setTimeout(reloadPage, 800);
            } catch (e) {
                btn.disabled = false;
                showCardMsg($('reqs-msg'), e.message || t('server_error'), 'danger');
            }
        });
    }

    // Sort bar and the "new file" / "new directory" buttons of the files view.
    function bindFileViewActions() {
        // ── Sort buttons ──────────────────────────────────────────────────────
        AESBinHelper.bindSortBar($('sort-bar'), () => _fileSort, order => {
            _fileSort = order;
            loadFiles();
        });

        // ── Create new text file ──────────────────────────────────────────────
        $('btn-new-file')?.addEventListener('click', () => {
            // As for an upload: files live in a directory, never at container root.
            if (!_container || !_directory) {
                showToast(_container ? t('upload_dir_required') : t('select_container'), 'warning');
                return;
            }
            openFileModal({
                mode: 'create'
            });
        });

        $('btn-new-dir')?.addEventListener('click', async () => {
            if (!_container) {
                showToast(t('select_container'), 'warning');
                return;
            }
            openDirInfo(null, _container.id);
        });
    }

    // Batches in flight, and the timer that clears the progress list after
    // the last one: a batch started meanwhile must not have its rows wiped.
    let _uploadBatches = 0;
    let _uploadClearTimer = null;

    // One pulse of .fc-highlight (dashboard.css), removed when it ends so
    // the next one can play.
    function flashHighlight(el) {
        el.classList.remove('fc-highlight');
        el.addEventListener('animationend', () => el.classList.remove('fc-highlight'), { once: true });
        el.classList.add('fc-highlight');
    }

    // Brings a Settings card into view: a closed <details> card opens first
    // (what it holds has no box otherwise), then target, the card or a line
    // in it, scrolls clear of the sticky header (scroll-margin-top) and
    // flashes. Used by both banners' buttons and the in-text card links.
    function jumpToCard(card, target = card) {
        if (!card || !target) return;
        if ('open' in card) card.open = true;
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        flashHighlight(target);
    }

    async function handleFiles(fileList) {
        if (!fileList?.length || !_container || !_directory) return;
        // Fixed for the batch: navigating away mid-batch must not send the
        // remaining files to the folder opened meanwhile.
        const containerId = _container.id;
        const dirId = _directory.id;
        const prog = $('upload-progress');
        clearTimeout(_uploadClearTimer);
        // Rows kept from an earlier batch because a file was refused (see the
        // end of this function) go when the next batch starts.
        if (prog && _uploadBatches === 0) prog.innerHTML = '';
        _uploadBatches++;
        if (prog) prog.hidden = false;
        for (const file of Array.from(fileList)) {
            const item = document.createElement('div');
            item.className = 'upload-item';
            // The name as the server will store it: without the controls and
            // invisible characters filename_clean() strips (NAME_FORBIDDEN_CHARS
            // in helpers.php, mirrored here), so a "…exe.jpg" spoof never
            // shows here either, and with the characters Windows refuses as
            // "_", as it does. The server's answer then replaces it.
            const shownName = file.name
                .replace(/[\u0000-\u001f\u007f-\u009f\u00ad\u061c\u115f\u1160\u180e\u200b\u200e\u200f\u202a-\u202e\u2060-\u2064\u2066-\u206f\u3164\ufeff\uffa0\ufff9-\ufffb]/g, '')
                .replace(/[<>:"/\\|?*]+/g, '_');
            item.innerHTML = `<span class="upload-name">${esc(shownName)}</span>
      <div class="upload-bar"><div class="upload-bar-fill"></div></div>
      <span class="upload-status">0%</span>`;
            prog?.appendChild(item);
            const fill = item.querySelector('.upload-bar-fill');
            const stat = item.querySelector('.upload-status');
            // Read per file, so unticking it mid-batch applies to the rest.
            let toSend = file;
            let metaRemoved = false;
            if ($('upload-strip-meta')?.checked) {
                try {
                    ({ file: toSend, removed: metaRemoved } = await AESBinStripMeta.strip(file));
                } catch {
                    // Refused rather than sent with the location still in it.
                    if (stat) stat.textContent = '✗ ' + t('upload_meta_unreadable');
                    item.classList.add('upload-item-failed');
                    showToast(shownName + ': ' + t('upload_meta_unreadable'), 'error');
                    continue;
                }
            }
            try {
                const result = await API.files.upload(toSend, containerId, dirId, pct => {
                    if (fill) fill.style.width = pct + '%';
                    if (stat) stat.textContent = pct + '%';
                });
                // One message for a name not kept as sent, whatever changed
                // it, from the name as sent. A name only made unique ("_01")
                // gets none: the row says it.
                const typed = file.name.normalize('NFC');
                const stored = result?.original_name ?? typed;
                const changedMsg = nameChangedMessage(result?.adjusted_from ?? result?.corrected_from ?? typed, stored,
                    [result?.adjusted, extensionReason(result)]);
                if (changedMsg) showToast(changedMsg, 'warning', 8000);
                const metaNote = metaRemoved ? t('upload_meta_removed') : '';
                // Case alone is no rename: the server lower-cases every
                // extension ("IMG.JPG" is stored as "IMG.jpg").
                if (stat && stored.toLowerCase() !== typed.toLowerCase()) {
                    // The row is the batch's record of the stored name, so it
                    // stays on screen (see the end of this function).
                    setIconText(stat, 'info', metaNote ? `${stored} · ${metaNote}` : stored);
                    item.classList.add('upload-item-renamed');
                } else if (stat) {
                    stat.textContent = metaNote ? '✓ ' + metaNote : '✓';
                }
            } catch (e) {
                if (stat) stat.textContent = '✗ ' + e.message;
                item.classList.add('upload-item-failed');
                showToast(e.message, 'error');
            }
        }
        _searchCache = null;
        _uploadBatches--;
        await loadFiles();
        if (_uploadBatches > 0) return;
        // A refused file, or one stored under another name, keeps the list on
        // screen: the toast is gone in a few seconds, and in a long batch the
        // row is the only record of which file failed and why, or of the
        // name it got. The next batch clears it.
        if (prog?.querySelector('.upload-item-failed, .upload-item-renamed')) return;
        _uploadClearTimer = setTimeout(() => {
            if (prog && _uploadBatches === 0) {
                prog.hidden = true;
                prog.innerHTML = '';
            }
        }, 3000);
    }

    async function doDownload(fileId, filename) {
        try {
            const blob = await API.files.fetchBlob(fileId, 'download');
            AESBinPreview.forceDownloadBlob(blob, filename);
            // In standalone/PWA mode there is no browser download UI, so
            // this is the only confirmation.
            showToast(t('file_downloaded'), 'success', 2000);
        } catch (e) {
            showToast(e.message, 'error');
        }
    }

    // ── Search ────────────────────────────────────────────────────────────────
    function bindSearch() {
        const inp = $('search-input');
        const panel = $('search-results');
        const toggle = $('btn-search-toggle');
        const searchBar = $('search-bar');
        if (!inp) return;

        // The button is a toggle, not a page: aria-expanded (false in
        // dashboard.php) marks it while the bar is open, styled in
        // dashboard.css like the open page's button.
        function closeSearch() {
            if (searchBar) searchBar.hidden = true;
            if (panel) panel.hidden = true;
            inp.value = '';
            toggle?.setAttribute('aria-expanded', 'false');
        }

        function openSearch() {
            if (searchBar) searchBar.hidden = false;
            toggle?.setAttribute('aria-expanded', 'true');
            setTimeout(() => inp.focus(), 50);
        }

        toggle?.addEventListener('click', () => {
            const isOpen = !(searchBar?.hidden ?? true);
            if (isOpen) closeSearch(); else openSearch();
        });

        document.addEventListener('click', e => {
            // closest(), not target.id: a click on the button's label span
            // would otherwise close the bar the same click just opened.
            if (!e.target?.closest?.('#search-bar, #search-results, #btn-search-toggle')) {
                closeSearch();
            }
        });

        // Compared in NFC on both sides: macOS/iOS often store "è" as "e" plus a
        // combining accent, which would never match a precomposed "è". New names
        // leave the browser in NFC (api.js), but older files or other clients'
        // may not.
        // File rows shown before "refine your search".
        const SEARCH_MAX_ROWS = 50;
        // Only the latest keystroke's search may render: the first one waits
        // for the whole index and would otherwise land over a later one.
        let searchSeq = 0;
        const doSearch = async () => {
            const seq = ++searchSeq;
            const q = fold(inp.value.trim());
            if (!q) {
                panel.hidden = true;
                return;
            }

            panel.hidden = false;
            panel.innerHTML = `<div class="search-loading">${esc(t('loading'))}</div>`;

            // "id:N" switches to an exact-id lookup across all three collections.
            // Case-insensitive because q is already lowercased.
            const idMatch = q.match(/^id:\s*(\d+)$/);

            try {
                if (!_searchCache) {
                    const res = await API.files.search();
                    _searchCache = res.files || [];
                }
                const struct = await getStruct().catch(() => ({
                    containers: []
                }));
                if (seq !== searchSeq) return;

                let containerMatches = [];
                const dirMatches = [];
                let fileMatches;

                if (idMatch) {
                    // Containers, directories and files have independent
                    // AUTO_INCREMENT sequences, so one id can match three objects.
                    const wantId = parseInt(idMatch[1], 10);
                    struct.containers.forEach(c => {
                        if (c.id === wantId) containerMatches.push(c);
                        (c.dirs || []).forEach(d => {
                            if (d.id === wantId) dirMatches.push({ ...d, _container: c });
                        });
                    });
                    fileMatches = _searchCache.filter(f => f.id === wantId);
                } else {
                    containerMatches = struct.containers.filter(c => fold(c.name).includes(q));
                    struct.containers.forEach(c => {
                        (c.dirs || []).forEach(d => {
                            if (fold(d.name).includes(q)) {
                                dirMatches.push({
                                    ...d,
                                    _container: c
                                });
                            }
                        });
                    });
                    fileMatches = _searchCache.filter(f =>
                        fold(f.original_name).includes(q)
                    );
                }

                if (!containerMatches.length && !dirMatches.length && !fileMatches.length) {
                    panel.innerHTML = `<div class="search-empty">${fmt(t('no_results'), { query: esc(q) })}</div>`;
                    return;
                }

                panel.innerHTML = '';

                // Shared row layout for all three result kinds.
                const nameHtml = name => idMatch ? esc(name) : highlight(name, q);
                // The location as a breadcrumb (Container › Folder ›) with ancestors
                // dimmed, then the name, and the type in words on the right. The id
                // follows only on a name search: on an id search every row would repeat
                // the same number.
                const appendResultRow = (name, pathParts, idNum, typeLabel, onClick) => {
                    const row = document.createElement('div');
                    row.className = 'search-result-row';
                    const crumbs = pathParts.length
                        ? `<span class="search-path">${pathParts.map(esc).join(' › ')} ›</span>`
                        : '';
                    row.innerHTML = `
          <span class="search-crumbs">${crumbs}<span class="search-name-text">${nameHtml(name)}</span></span>
          <span class="search-id">${esc(typeLabel)}${idMatch ? '' : ` (id: ${idNum})`}</span>`;
                    row.addEventListener('click', onClick);
                    panel.appendChild(row);
                };

                // Container results
                containerMatches.forEach(c => {
                    // A container is the top of the tree: nothing above it to show.
                    appendResultRow(c.name, [], c.id, t('type_container'), () => {
                        closeSearch();
                        openContainer(c);
                    });
                });

                // Directory results
                dirMatches.forEach(dir => {
                    appendResultRow(dir.name, [dir._container.name], dir.id, t('type_directory'), () => {
                        closeSearch();
                        _highlightDropzone = true;
                        openContainer(dir._container, {
                            id: dir.id,
                            name: dir.name
                        });
                    });
                });

                fileMatches.slice(0, SEARCH_MAX_ROWS).forEach(f => {
                    // Names come from the already loaded struct; file rows
                    // carry only ids.
                    const container = struct.containers.find(c => c.id === f.container_id);
                    const dir = container && f.directory_id
                        ? (container.dirs || []).find(d => d.id === f.directory_id)
                        : null;
                    const path = [];
                    if (container) path.push(container.name);
                    if (dir) path.push(dir.name);
                    appendResultRow(f.original_name, path, f.id, t('type_file'), () => {
                        closeSearch();
                        if (!container) return;
                        _highlightFileId = f.id;
                        openContainer(container, dir ? { id: dir.id, name: dir.name } : null);
                    });
                });
                if (fileMatches.length > SEARCH_MAX_ROWS) {
                    const more = document.createElement('div');
                    more.className = 'search-more';
                    more.textContent = fmt(t('refine_search'), { count: fileMatches.length - SEARCH_MAX_ROWS });
                    panel.appendChild(more);
                }
            } catch (e) {
                if (seq === searchSeq) panel.innerHTML = `<div class="search-error">${esc(e.message)}</div>`;
            }
        };

        let debounce;
        inp.addEventListener('input', () => {
            clearTimeout(debounce);
            debounce = setTimeout(doSearch, 280);
        });
        inp.addEventListener('keydown', e => {
            if (e.key === 'Escape') closeSearch();
        });

    }

    /** The form search compares in: NFC, lower case. */
    function fold(s) {
        return String(s).normalize('NFC').toLowerCase();
    }

    function highlight(text, query) {
        // The search's own form, so the offsets line up.
        text = String(text).normalize('NFC');
        const i = fold(text).indexOf(query);
        if (i < 0) return esc(text);
        return esc(text.slice(0, i)) + `<mark>${esc(text.slice(i, i + query.length))}</mark>` + esc(text.slice(i + query.length));
    }

    // ── Directory info modal ──────────────────────────────────────────────────
    // dir = existing directory (edit mode), or null plus a container id as
    // createCid for create mode: same modal and fields, empty values.
    function openDirInfo(dir, createCid = null) {
        const create = dir === null;
        _pending.dirInfo = create
            ? { id: null, cid: createCid, create: true, original: { name: '', desc: '' } }
            : {
                id: dir.id,
                cid: dir.container_id,
                create: false,
                original: {
                    name: dir.name,
                    desc: (dir.description || '').trim()
                }
            };
        const modal = $('modal-dir-info');
        const titleEl = modal?.querySelector('h3');
        if (titleEl) setIconText(titleEl, 'folder', create ? t('new_directory') : t('folder_info'));
        const nameInp = $('dirinfo-name');
        const descInp = $('dirinfo-desc');
        const status = $('dirinfo-status');
        const idEl = $('dirinfo-id');
        if (nameInp) nameInp.value = create ? '' : dir.name;
        if (descInp) descInp.value = create ? '' : (dir.description || '');
        updateDescCounter(descInp);
        if (status) status.textContent = '';
        if (idEl) idEl.textContent = create ? '' : 'ID: ' + dir.id;
        showModal('modal-dir-info');
        checkDirInfoDirty();
        if (create) nameInp?.focus();
    }

    // Save stays disabled until name or description differs (see
    // checkFileInfoDirty()).
    function checkDirInfoDirty() {
        const orig = _pending.dirInfo?.original;
        const saveBtn = $('btn-dirinfo-save');
        if (!orig || !saveBtn) return;
        const nameInp = $('dirinfo-name');
        const descInp = $('dirinfo-desc');
        const name = nameInp?.value?.trim() || '';
        const desc = descInp?.value?.trim() ?? '';
        const changed = name !== orig.name || desc !== orig.desc;
        saveBtn.disabled = !changed || !name;
    }

    // ── File info modal ───────────────────────────────────────────────────────
    // Shared "Create file" / "File info" modal. The flows differ only in the
    // 'btn-fileinfo-save' handler in bindModals() (upload vs write + rename).
    // mode: 'create' | 'edit'.
    async function openFileModal({
        mode,
        file = null,
        cid = null,
        did = null
    }) {
        _pending.fileInfo = mode === 'edit' ? {
            id: file.id,
            filename: file.original_name,
            mime: file.mime,
            description: file.description || '',
            cid,
            did
        } : null;
        _pending.fileModalMode = mode;

        const title = $('fileinfo-title');
        const nameLabel = $('fileinfo-name-label');
        const nameInp = $('fileinfo-name');
        const extSel = $('fileinfo-ext');
        const extBadge = $('fileinfo-ext-badge');
        const descInp = $('fileinfo-desc');
        const textarea = $('fileinfo-textarea');
        const saveBtn = $('btn-fileinfo-save');
        const idEl = $('fileinfo-id');

        // Drop any <option> a previous 'edit' open injected for an extension not
        // in MimeRegistry (see the editable branch below); the <select> is shared
        // with 'create'.
        extSel?.querySelectorAll('option[data-injected]').forEach(o => o.remove());

        setFileInfoStatus('');
        if (descInp) descInp.value = mode === 'edit' ? (file.description || '') : '';
        updateDescCounter(descInp);
        if (textarea) {
            textarea.value = '';
            delete textarea.dataset.original;
        }
        // A new file is UTF-8 with LF unless asked otherwise; an existing one
        // shows its own format once loaded, and cannot be changed before.
        setFileFormatInputs(DEFAULT_TEXT_FORMAT, mode === 'create');
        if (nameInp) delete nameInp.dataset.ext;

        if (mode === 'create') {
            if (title) title.textContent = t('create_file');
            if (nameLabel) nameLabel.textContent = t('create_file_name');
            if (saveBtn) saveBtn.textContent = t('create_file_btn');
            if (nameInp) nameInp.value = '';
            if (idEl) idEl.hidden = true;
            if (extSel) {
                extSel.hidden = false;
                // "txt" by default, selected by value rather than by
                // position in the dropdown.
                extSel.value = 'txt';
                if (extSel.selectedIndex < 0) extSel.selectedIndex = 0;
            }
            if (extBadge) extBadge.hidden = true;
            // Every new file goes through the text editor.
            const contWrap = $('fileinfo-content-wrap');
            if (contWrap) contWrap.hidden = false;
            showModal('modal-file-info');
            checkFileInfoDirty();
            return;
        }

        // mode === 'edit'
        if (title) title.textContent = file.original_name;
        if (nameLabel) nameLabel.textContent = t('sort_name');
        if (saveBtn) saveBtn.textContent = t('save');
        if (idEl) {
            idEl.textContent = 'ID: ' + file.id;
            idEl.hidden = false;
        }

        const editable = isEditableMime(file.mime);
        // A binary file's locked extension is the one the server keeps on
        // rename: a compound one (".tar.bz2") whole, as filename_split_ext()
        // splits it, so the field never offers a ".tar" the server would put
        // back anyway. A text file splits at its last dot, for the dropdown.
        const lastDot = editable ? file.original_name.lastIndexOf('.') : binaryExtOffset(file.original_name);
        const base = lastDot > 0 ? file.original_name.slice(0, lastDot) : file.original_name;
        const ext = lastDot > 0 ? file.original_name.slice(lastDot + 1).toLowerCase() : '';

        if (nameInp) nameInp.value = base;

        if (editable) {
            // Editable file: dropdown of text extensions.
            if (extSel) {
                const opts = Array.from(extSel.options);
                if (ext && !opts.find(o => o.value === ext)) {
                    const opt = document.createElement('option');
                    opt.value = ext;
                    opt.textContent = ext;
                    opt.dataset.injected = '1';
                    extSel.insertBefore(opt, extSel.firstChild);
                }
                extSel.value = ext || '';
                extSel.hidden = false;
            }
            if (extBadge) extBadge.hidden = true;
        } else {
            // Binary file: read-only extension badge. Locked on purpose
            // (see FileService::rename()): a rename must not turn a .pdf
            // into a .php or a .jpg into a .txt.
            const dotExt = lastDot > 0 ? file.original_name.slice(lastDot) : '';
            if (nameInp) nameInp.dataset.ext = dotExt;
            if (extSel) extSel.hidden = true;
            if (extBadge) {
                extBadge.textContent = dotExt || t('file_no_ext');
                extBadge.hidden = false;
            }
        }

        const isText = editable; // content editor visible for all editable types
        const contWrap = $('fileinfo-content-wrap');
        if (contWrap) contWrap.hidden = !isText;

        // Baseline for checkFileInfoDirty(). content is filled in below for text
        // files and stays null for binary ones. Must follow contWrap.hidden above,
        // which checkFileInfoDirty() reads to decide whether to compare content.
        _pending.fileInfo.original = {
            name: base,
            ext: editable ? (ext || '') : '',
            desc: (file.description || '').trim(),
            content: null,
            format: null
        };
        checkFileInfoDirty();

        if (!isText) {
            showModal('modal-file-info');
            return;
        }

        // Text file: load the content.
        setFileInfoStatus(t('content_loading'));
        showModal('modal-file-info');

        // The modal may be closed, or reopened on another file, before the
        // download returns: only the open that asked may fill the editor.
        const opened = _pending.fileInfo;
        try {
            // As bytes, so the save can write the file back in the encoding,
            // with the byte-order mark and the line endings it has now (see
            // decodeText() in helper.js).
            const { text: content, format } = decodeText(await API.files.downloadBytes(file.id));
            if (_pending.fileInfo !== opened) return;
            setFileFormatInputs(format);
            opened.original.format = fileFormatKey();
            opened.original.formatValue = format;
            textarea.value = content;
            // Read back, not `content`: a textarea returns its value with
            // every line ending as LF, so a file saved with CRLF would
            // otherwise differ from its own baseline, look edited as soon as
            // it opens and be rewritten by a change to its description alone.
            const shown = textarea.value;
            textarea.dataset.original = shown;
            opened.original.content = shown;
            checkFileInfoDirty();
            setFileInfoStatus('');
        } catch (e) {
            if (_pending.fileInfo === opened) setFileInfoStatus('✗ ' + e.message, true);
        }
    }

    /**
     * The line under the file modal's fields: progress in the usual grey, a
     * refusal or a failure in red (.hint-sm.text-danger).
     */
    function setFileInfoStatus(text, isError = false) {
        const status = $('fileinfo-status');
        if (!status) return;
        status.textContent = text;
        status.classList.toggle('text-danger', isError);
    }

    // ── Encoding and line endings of the edited text ──────────────────────────
    // The two selects under the editor, as the format object decodeText() and
    // encodeText() (helper.js) exchange. "utf-8-bom" is UTF-8 with the mark;
    // UTF-16 always carries one.
    const EOL_BY_VALUE = { lf: '\n', crlf: '\r\n', cr: '\r' };

    /** Show a format in the selects; `enabled` false while a file is loading. */
    function setFileFormatInputs(format, enabled = true) {
        const enc = $('fileinfo-encoding');
        const eol = $('fileinfo-eol');
        if (enc) {
            enc.value = format.encoding === 'utf-8' && format.bom ? 'utf-8-bom' : format.encoding;
            enc.disabled = !enabled;
            delete enc.dataset.chosen;
        }
        if (eol) {
            eol.value = Object.keys(EOL_BY_VALUE).find(k => EOL_BY_VALUE[k] === format.eol) || 'lf';
            eol.disabled = !enabled;
            delete eol.dataset.chosen;
        }
        const note = $('fileinfo-format-note');
        if (note) note.hidden = true;
    }

    /** The format the selects ask for, to encode the text with on save. */
    function fileFormatFromInputs() {
        const enc = $('fileinfo-encoding')?.value || 'utf-8';
        const eol = EOL_BY_VALUE[$('fileinfo-eol')?.value] || '\n';
        return enc === 'utf-8-bom'
            ? { encoding: 'utf-8', bom: true, eol }
            : { encoding: enc, bom: enc.startsWith('utf-16'), eol };
    }

    /** The selects' state as one string, to tell a changed format from the loaded one. */
    const fileFormatKey = () => ($('fileinfo-encoding')?.value || '') + '|' + ($('fileinfo-eol')?.value || '');

    const DEFAULT_TEXT_FORMAT = { encoding: 'utf-8', bom: false, eol: '\n' };

    /**
     * Keep the two menus true to the text now in the editor: they show what
     * the file would be found to be if it were saved and opened again, and
     * offer only what can be told apart from it.
     *
     * Nothing in a file says what its format is; it is read off the bytes
     * (decodeText() in helper.js). So:
     *
     *   - plain letters are the same bytes in UTF-8 and Windows-1252, and
     *     such a file is UTF-8. Windows-1252 is offered only while the text
     *     has a character outside ASCII, all of them in its table; a
     *     Windows-1252 file whose last accent is deleted shows UTF-8;
     *   - a text with no line break has no line ending, and shows LF. The
     *     other endings are offered once there is a break to apply them to.
     *
     * A menu the user has not touched follows the file: bring the accent or
     * the line break back and it returns to the file's own format, so that
     * passing through plain text never converts anything by itself. One the
     * user has set keeps their choice for as long as the text supports it.
     * A Windows-1252 file given a character outside its table keeps its
     * format, and the save refuses it with a message: switching a file to
     * UTF-8 over one emoji is the user's decision.
     */
    function updateFormatOptions() {
        const encSel = $('fileinfo-encoding');
        const eolSel = $('fileinfo-eol');
        // Disabled as a whole while an existing file is still loading.
        if (!encSel || !eolSel || encSel.disabled) return;
        const base = _pending.fileModalMode === 'create'
            ? DEFAULT_TEXT_FORMAT
            : _pending.fileInfo?.original?.formatValue;
        if (!base) return;

        const text = $('fileinfo-textarea')?.value ?? '';
        const baseEnc = base.encoding === 'utf-8' && base.bom ? 'utf-8-bom' : base.encoding;
        const baseEol = Object.keys(EOL_BY_VALUE).find(k => EOL_BY_VALUE[k] === base.eol) || 'lf';
        const ascii = !/[^\x00-\x7F]/.test(text);
        const fits1252 = ascii || encodeText(text, { encoding: 'windows-1252', bom: false, eol: '\n' }) !== null;
        const hasBreak = text.includes('\n');

        // What the untouched menus show.
        const naturalEnc = baseEnc === 'windows-1252' && ascii ? 'utf-8' : baseEnc;
        const naturalEol = hasBreak ? baseEol : 'lf';

        for (const o of encSel.options) {
            o.disabled = o.value === 'windows-1252' && (ascii || (!fits1252 && baseEnc !== 'windows-1252'));
        }
        for (const o of eolSel.options) {
            o.disabled = o.value !== 'lf' && !hasBreak;
        }
        // dataset.chosen: set by the change listener, cleared when a file opens.
        if (!encSel.dataset.chosen || encSel.options[encSel.selectedIndex]?.disabled) {
            delete encSel.dataset.chosen;
            encSel.value = naturalEnc;
        }
        if (!eolSel.dataset.chosen || eolSel.options[eolSel.selectedIndex]?.disabled) {
            delete eolSel.dataset.chosen;
            eolSel.value = naturalEol;
        }

        // Said in words too, for whoever does not open the menus: the text
        // as it stands has taken the file out of its own format, and a save
        // will leave it in the one named.
        const note = $('fileinfo-format-note');
        if (!note) return;
        const optionName = (sel, value) =>
            Array.from(sel.options).find(o => o.value === value)?.textContent || value;
        const from = [];
        const to = [];
        if (!encSel.dataset.chosen && naturalEnc !== baseEnc) {
            from.push(optionName(encSel, baseEnc));
            to.push(optionName(encSel, naturalEnc));
        }
        if (!eolSel.dataset.chosen && naturalEol !== baseEol) {
            from.push(optionName(eolSel, baseEol));
            to.push(optionName(eolSel, naturalEol));
        }
        note.hidden = from.length === 0;
        if (from.length) {
            const was = from.join(' · ');
            const becomes = to.join(' · ');
            note.textContent = fmt(t('file_format_auto'), { from: was, to: becomes });
        }
    }

    /**
     * Whether the format the selects ask for writes, for the text now in the
     * editor, the very bytes the file's own format does (UTF-8 and LF for a
     * new file). It happens more often than it sounds: plain letters are the
     * same in UTF-8 and Windows-1252, and a text of one line has no line
     * ending to change. The file would then come back unchanged and reopen
     * with the menus where they were, which looks like a save that failed.
     * updateFormatOptions() keeps such a choice from being made, and itself
     * shows UTF-8 or LF for a file that was something else and no longer
     * differs; this check is why neither counts as a change to save.
     */
    function formatChoiceHasNoEffect() {
        const base = _pending.fileModalMode === 'create'
            ? DEFAULT_TEXT_FORMAT
            : _pending.fileInfo?.original?.formatValue;
        const text = $('fileinfo-textarea')?.value ?? '';
        if (!base || !text) return false;
        const chosen = fileFormatFromInputs();
        if (chosen.encoding === base.encoding && chosen.bom === base.bom && chosen.eol === base.eol) return false;
        const a = encodeText(text, chosen);
        const b = encodeText(text, base);
        return !!a && !!b && a.length === b.length && a.every((v, i) => v === b[i]);
    }

    // Whether closing the file dialog now would lose text typed in its
    // editor: any text in "Create file" (where Save also waits for a name),
    // or, in an edit, whatever checkFileInfoDirty() has enabled Save for.
    // A file without the editor (rename and description only) never counts:
    // that is quick to type again. Read by dismissModals().
    function editorHasUnsavedText() {
        if ($('modal-file-info')?.hidden !== false || $('fileinfo-content-wrap')?.hidden) return false;
        if (_pending.fileModalMode === 'create') return ($('fileinfo-textarea')?.value.trim() ?? '') !== '';
        return $('btn-fileinfo-save')?.disabled === false;
    }

    // Keeps Save disabled until name, extension, description, content or
    // its format differs from what was loaded (editing back re-disables it).
    // In 'create' mode it only requires a name and some content.
    function checkFileInfoDirty() {
        const saveBtn = $('btn-fileinfo-save');
        if (!saveBtn) return;
        const nameInp = $('fileinfo-name');
        const rawName = nameInp?.value?.trim() || '';
        syncFileNameMaxLength();

        // Runs on every edit of the text and every change of the two menus,
        // which is when what the menus can offer changes.
        updateFormatOptions();
        const formatNoEffect = formatChoiceHasNoEffect();

        if (_pending.fileModalMode === 'create') {
            const content = $('fileinfo-textarea')?.value?.trim() || '';
            saveBtn.disabled = !rawName || !content;
            return;
        }

        const orig = _pending.fileInfo?.original;
        if (!orig) return;

        const extSel = $('fileinfo-ext');
        const descInp = $('fileinfo-desc');
        const textarea = $('fileinfo-textarea');
        const contWrap = $('fileinfo-content-wrap');

        // extSel is hidden for binary files and orig.ext is '' for them, so the
        // extension never counts as changed there.
        const ext = extSel && !extSel.hidden ? (extSel.value || '') : orig.ext;
        const desc = descInp?.value?.trim() ?? '';
        const content = (contWrap && !contWrap.hidden) ? (textarea?.value ?? '') : orig.content;
        // null until the content has loaded (and for a binary file): nothing
        // to compare yet.
        const format = orig.format === null || formatNoEffect ? orig.format : fileFormatKey();

        const changed = rawName !== orig.name || ext !== orig.ext ||
            desc !== orig.desc || content !== orig.content || format !== orig.format;
        saveBtn.disabled = !changed || !rawName;
    }

    /**
     * Where a binary file's extension starts, as the server splits it
     * (filename_split_ext()): before a compound extension from
     * AESBIN_COMPOUND_EXTS ("archive.tar.gz" → 7), else at the last dot; -1
     * when there is none or the name only starts with a dot.
     */
    function binaryExtOffset(name) {
        const lower = name.toLowerCase();
        for (const compound of window.AESBIN_COMPOUND_EXTS || []) {
            const suffix = '.' + compound;
            if (lower.length > suffix.length && lower.endsWith(suffix)) return name.length - suffix.length;
        }
        const dot = name.lastIndexOf('.');
        return dot > 0 ? dot : -1;
    }

    // The reason code for an upload's extension change: added to a name
    // that had no listed one ("README" → "README.md"), or replaced.
    function extensionReason(res) {
        if (!res?.corrected_from) return '';
        return res.extension_change === 'added' ? 'extension_added' : 'extension';
    }

    /**
     * The one message for a name not kept as typed: from the name as typed
     * to the name stored, with every reason (the server's "adjusted", one of
     * FILENAME_REASONS in helpers.php, and "extension" when an upload's was
     * corrected from the content). '' when there is none.
     */
    function nameChangedMessage(from, to, reasons) {
        const text = {
            reserved: t('name_reason_reserved'),
            characters: t('name_reason_characters'),
            invisible: t('name_reason_invisible'),
            dots: t('name_reason_dots'),
            length: t('name_reason_length'),
            extension: t('name_reason_extension'),
            extension_added: t('name_reason_extension_added'),
        };
        const list = reasons.filter(r => r && text[r]);
        if (!list.length || from === to) return '';
        return fmt(t('name_changed'), { from, to, reasons: list.map(r => text[r]).join('; ') });
    }

    /**
     * The name field's maxlength, without the extension shown beside it: the
     * server's FILENAME_MAX_CHARS counts the whole name ("report" + ".md"),
     * so a field that let the base alone reach it produced a name the server
     * refuses. The full limit stays in data-max (from the markup).
     */
    function syncFileNameMaxLength() {
        const nameInp = $('fileinfo-name');
        if (!nameInp) return;
        if (!nameInp.dataset.max) nameInp.dataset.max = String(nameInp.maxLength);
        const extSel = $('fileinfo-ext');
        const ext = extSel && !extSel.hidden
            ? (extSel.value || '')
            : (nameInp.dataset.ext || '').replace(/^\./, '');
        nameInp.maxLength = Math.max(1, Number(nameInp.dataset.max) - (ext ? ext.length + 1 : 0));
    }

    // ── Move modal ────────────────────────────────────────────────────────────
    // Containers, each with its directories, both by name: the server sends
    // them in creation order and cannot sort them (the names are encrypted).
    // Shared by the home tree and getStruct() (Move trees, search).
    function groupByContainer(containers, directories) {
        const byName = list => AESBinHelper.sortItems(list, { by: 'name', dir: 'asc' }, { name: x => x.name });
        return byName(containers).map(container => ({
            container,
            dirs: byName(directories.filter(d => d.container_id === container.id)),
        }));
    }

    // Structure cache (containers and their directories): decides when the
    // Move buttons appear and builds the Move trees.
    async function getStruct(force = false) {
        if (_struct && !force) return _struct;
        // Two requests whatever the number of containers: directories.list()
        // without an id returns them all, grouped here.
        const [{ containers }, { directories }] = await Promise.all([
            API.containers.list(),
            API.directories.list(),
        ]);
        const withDirs = groupByContainer(containers || [], directories || []).map(({ container: c, dirs }) => ({
            id: c.id,
            name: c.name,
            dirs: dirs.map(d => ({
                id: d.id,
                name: d.name,
                is_shared: !!d.is_shared
            }))
        }));
        _struct = {
            containers: withDirs,
            containerCount: withDirs.length,
            dirCount: withDirs.reduce((n, c) => n + c.dirs.length, 0),
        };
        return _struct;
    }

    // Move a FILE to another folder. Destination = a directory (in any container).
    async function openMoveModal(fileId, filename, curContainerId, curDirId) {
        _pending.moveKind = 'file';
        _pending.move = {
            fileId,
            filename,
            curContainerId: curContainerId ?? null,
            curDirId: curDirId ?? null
        };
        _pending.moveDest = {
            containerId: null,
            dirId: null
        };
        await renderMoveModal();
    }

    // Move a FOLDER to another container. Destination = a container.
    async function openDirMoveModal(dirId, dirName, curContainerId) {
        _pending.moveKind = 'dir';
        _pending.move = {
            dirId,
            dirName,
            curContainerId: curContainerId ?? null
        };
        _pending.moveDest = {
            containerId: null,
            dirId: null
        };
        await renderMoveModal();
    }

    // Shared renderer for both modes: the current location as read-only text,
    // and only the other valid destinations in the tree.
    async function renderMoveModal() {
        const tree = $('move-tree');
        const hint = $('move-hint');
        const cur = $('move-current');
        const okBtn = $('btn-move-ok');
        if (okBtn) okBtn.disabled = true;
        if (hint) hint.textContent = _pending.moveKind === 'dir' ? t('move_hint_container') : t('move_hint');
        if (cur) cur.textContent = '';
        $('move-msg').hidden = true;
        tree.innerHTML = `<div class="tree-msg">${esc(t('loading'))}</div>`;
        showModal('modal-move');

        // note: what the hint line says once this destination is picked. A
        // pick cannot be undone, only changed, so "select a destination" is
        // replaced by where the item is going (or what that does to a link).
        const pick = (el, dest, note) => {
            document.querySelectorAll('.move-tree-item').forEach(i => i.classList.remove('selected'));
            el.classList.add('selected');
            _pending.moveDest = dest;
            if (okBtn) okBtn.disabled = false;
            if (hint) hint.textContent = note;
            $('move-msg').hidden = true;
        };

        try {
            const st = await getStruct(true);
            tree.innerHTML = '';

            // ── Folder move: destination is another container ─────────────────
            if (_pending.moveKind === 'dir') {
                const here = st.containers.find(c => c.id === _pending.move.curContainerId);
                if (cur) cur.innerHTML = `${icon('archive')} ${esc(here ? here.name : '—')}  /  ${icon('folder')} ${esc(_pending.move.dirName)}`;
                const others = st.containers.filter(c => c.id !== _pending.move.curContainerId);
                if (!others.length) {
                    tree.innerHTML = `<div class="tree-msg">${esc(t('move_no_targets'))}</div>`;
                    return;
                }
                others.forEach(c => {
                    const item = document.createElement('div');
                    item.className = 'move-tree-item';
                    item.innerHTML = `${icon('archive')} ${esc(c.name)}`;
                    item.addEventListener('click', () => pick(item, {
                        containerId: c.id,
                        dirId: null
                    }, fmt(t('move_ready_dir'), { name: c.name })));
                    tree.appendChild(item);
                });
                return;
            }

            // ── File move: destination is another folder. Tree is shown fully expanded. ──
            const hereC = st.containers.find(c => c.id === _pending.move.curContainerId);
            const hereD = hereC && hereC.dirs.find(d => d.id === _pending.move.curDirId);
            if (cur) cur.innerHTML = `${icon('archive')} ${esc(hereC ? hereC.name : '—')}  /  ${icon('folder')} ${esc(hereD ? hereD.name : '—')}${hereD?.is_shared ? ' ' + icon('link') : ''}`;

            let shown = 0;
            st.containers.forEach(c => {
                const dirs = c.dirs.filter(d => !(c.id === _pending.move.curContainerId && d.id === _pending.move.curDirId));
                if (!dirs.length) return; // skip containers with no valid target folder
                const head = document.createElement('div');
                head.className = 'move-tree-item move-tree-head';
                head.innerHTML = `${icon('archive')} ${esc(c.name)}`;
                tree.appendChild(head);
                dirs.forEach(dir => {
                    shown++;
                    const di = document.createElement('div');
                    di.className = 'move-tree-item move-tree-dir';
                    di.innerHTML = `${icon('folder')} ${esc(dir.name)}${dir.is_shared ? ' ' + icon('link') : ''}`;
                    // A directory link serves whatever is in the directory, so
                    // the move silently changes who can read the file.
                    const note = dir.is_shared && hereD?.is_shared ? t('move_between_shared') :
                        dir.is_shared ? t('move_into_shared') :
                        hereD?.is_shared ? t('move_out_of_shared') :
                        fmt(t('move_ready_file'), { name: dir.name });
                    di.addEventListener('click', () => pick(di, {
                        containerId: c.id,
                        dirId: dir.id
                    }, note));
                    tree.appendChild(di);
                });
            });
            if (!shown) tree.innerHTML = `<div class="tree-msg">${esc(t('move_no_targets'))}</div>`;
        } catch (e) {
            tree.innerHTML = `<div class="tree-msg tree-error">${esc(e.message)}</div>`;
        }
    }

    // ── Share modal ───────────────────────────────────────────────────────────
    // name is display-only, so the user can confirm which item the dialog is
    // about.
    async function openShareModal(type, targetId, name) {
        const req = {
            type,
            targetId
        };
        const tgtEl = $('shr-target');
        if (tgtEl) {
            tgtEl.textContent = name || '';
            tgtEl.hidden = !name;
        }
        $('shr-pw').value = '';
        $('shr-exp').value = '';
        const existSplitTog = $('shr-existing-split-toggle');
        if (existSplitTog) existSplitTog.checked = _shrListSplit;
        const existEl = $('shr-existing');
        const existList = $('shr-existing-list');
        if (existEl) existEl.hidden = true;
        if (existList) existList.innerHTML = '';
        _existingSharesCache = null;
        // Shown at once; the existing links are filled in when they arrive.
        showModal('dlg-lnk');
        _pending.shr = req;
        if (!existEl || !existList) return;
        try {
            const {
                shares
            } = await API.shares.list();
            // Closed, or reopened on another target, meanwhile.
            if (_pending.shr !== req) return;
            const active = shares.filter(s => s.type === type && s.target_id === targetId);
            if (active.length) {
                existEl.hidden = false;
                _existingSharesCache = active;
                renderExistingShares(active);
            }
        } catch {
            // The dialog works without the list: creating a link needs none.
        }
    }

    // Post-action report after a delete or trash. The warning before the
    // click (withShareWarning()) has no count without an extra request; the
    // response does. Orange and longer-lived when links died, since that is
    // not undoable.
    const shareLossToast = (res, okMsg) => {
        const killed = Number(res && res.shares_destroyed) || 0;
        if (killed > 0) {
            showToast(fmt(killed === 1 ? t('deleted_shares_one') : t('deleted_shares'), { count: killed }), 'warning', 6000);
        } else {
            showToast(okMsg, 'success', 2000);
        }
    };

    // Names the link being revoked (irreversible, and both lists can show
    // many). Title too: the dialog default "Delete permanently" is for files.
    const confirmRevoke = s => confirmModal(
        fmt(t('share_revoke_confirm'), { name: s.display_name || '' }),
        t('revoke'),
        t('revoke')
    );

    // Appends the "this destroys share links" warning to a confirmation when
    // there are links to destroy. Deleting a directory or container trashes
    // the files inside, and trashing destroys their links, so the warning
    // belongs before the click.
    const withShareWarning = (msg, hasShares) =>
        hasShares ? msg + '\n\n' + t('delete_confirm_shares') : msg;

    // Confirmation for deleting a folder or container, from what the listing
    // already knows. With files: they go to the trash, and restoring one
    // recreates its folder (and container). Without: nothing comes back.
    // Links, if any, are destroyed either way (withShareWarning()).
    function deleteConfirmText(isContainer, name, fileCount, hasShares) {
        const question = isContainer ? t('container_delete_confirm') : t('dir_delete_confirm');
        const outcome = fileCount > 0
            ? (isContainer ? t('container_delete_files') : t('dir_delete_files'))
            : (isContainer ? t('container_delete_empty') : t('dir_delete_empty'));
        return withShareWarning(fmt(question, { name }) + '\n' + outcome, hasShares);
    }

    // Renders the "active links for this item" recap in the create-share
    // dialog. Also called by the split-view toggle with the cached array.
    function renderExistingShares(active) {
        const existEl = $('shr-existing');
        const existList = $('shr-existing-list');
        if (!existList) return;
        existList.innerHTML = '';
        active.forEach(s => {
            const expiryText = shareExpiryText(s.expires_at);
            const row = document.createElement('div');
            row.className = 'shr-existing-item';
            row.dataset.sid = String(s.id);
            // In orange when it expires soon, as on the shares page.
            const expiry = isExpiringSoon(s) ? `<span class="shr-expiry-soon">${expiryText}</span>` : expiryText;
            const meta = `<div class="shr-meta">${s.password_protected ? icon('lock') + ' ' : ''}${expiry}</div>${_renderShareStats(s)}`;
            row.innerHTML = meta + shareUrlRows(s);
            const copyHandler = e => copyShareLink(e.currentTarget);
            row.querySelector('.shr-url-copy').addEventListener('click', copyHandler);
            row.querySelector('.shr-url-copy-key')?.addEventListener('click', copyHandler);
            // Same actions as the shares page (see shareActionsRow()).
            row.querySelector('.btn-shr-edit')?.addEventListener('click', () => {
                openShareEditModal(s);
            });
            row.querySelector('.btn-retarget')?.addEventListener('click', () => {
                openRetargetModal(s);
            });
            row.querySelector('.btn-revoke').addEventListener('click', async e => {
                const sid = parseInt(e.currentTarget.dataset.id, 10);
                if (!await confirmRevoke(s)) return;
                try {
                    await API.shares.revoke(sid);
                    row.remove();
                    _existingSharesCache = _existingSharesCache?.filter(x => x.id !== sid) || null;
                    reloadShareViews();
                    if (existEl && !existList.children.length) existEl.hidden = true;
                    showToast(t('share_revoked_ok'), 'success');
                } catch (err) {
                    showToast(err.message, 'error');
                }
            });
            existList.appendChild(row);
        });
    }

    // After a link is created, moved or revoked: the active view's link
    // icons and counters (files, home) follow, and search starts afresh.
    function reloadShareViews() {
        _searchCache = null;
        if ($('view-files')?.classList.contains('active')) loadFiles();
        else if ($('view-home')?.classList.contains('active')) loadHome();
    }

    // Human-readable expiry from an absolute expires_at (null = never).
    // A live link ending within AESBIN_SHARE_EXPIRING_SOON seconds (helpers.php).
    const EXPIRING_SOON_MS = (window.AESBIN_SHARE_EXPIRING_SOON || 86400) * 1000;
    const isExpiringSoon = s => {
        if (!s.expires_at) return false;
        const left = parseServerDate(s.expires_at) - Date.now();
        return left > 0 && left <= EXPIRING_SOON_MS;
    };

    function shareExpiryText(expiresAt) {
        if (!expiresAt) return t('no_expiry');
        const expDate = parseServerDate(expiresAt);
        const secLeft = Math.round((expDate - Date.now()) / 1000);
        if (secLeft <= 0) return t('share_expired');
        if (secLeft < 60) return t('expires_very_soon');
        if (secLeft < 3600) return fmt(t('expires_in_min'), { n: Math.floor(secLeft / 60) });
        if (secLeft < 86400) return fmt(t('expires_in_hour'), { n: Math.floor(secLeft / 3600) });
        return fmt(t('expires_in_day'), { n: Math.floor(secLeft / 86400) });
    }

    // Keeps both split-view checkboxes in sync and re-renders whichever
    // lists are cached (display preference only, no re-fetch).
    function setSplitMode(value) {
        _shrListSplit = value;
        const a = $('shr-list-split-toggle'), b = $('shr-existing-split-toggle');
        if (a) a.checked = value;
        if (b) b.checked = value;
        if (_shrListCache) renderShareList(_shrListCache);
        if (_existingSharesCache) renderExistingShares(_existingSharesCache);
    }

    // ── Shares list view ──────────────────────────────────────────────────────
    async function loadShares() {
        let shares = [];
        try {
            const r = await API.shares.list();
            shares = r.shares || [];
        } catch (e) {
            showToast(e.message, 'error');
        }
        _shrListCache = shares;

        // Folder links: the server already keeps them in step after every
        // upload, move and restore, but never fails the file operation for
        // it (ShareService::autoRefreshForDirectory() logs and moves on).
        // This catches up any refresh that failed; it re-renders only when
        // one actually added files.
        const dirShares = shares.filter(s => s.type === 'directory');
        if (dirShares.length) {
            Promise.all(dirShares.map(s => API.shares.refresh(s.id).catch(() => null)))
                .then(results => {
                    const totalNew = results.reduce((sum, r) => sum + (r?.new_files || 0), 0);
                    if (totalNew > 0) loadShares();
                });
        }
        renderShareList(shares);
    }

    // Actions on the share itself, as opposed to those on a URL field. Shared
    // by the shares page and the create dialog's recap.
    const shareActionsRow = s => `
        <div class="shr-actions-row">
          <button class="btn btn-ghost btn-sm btn-shr-edit" data-id="${s.id}" title="${esc(t('share_edit'))}">${icon('sliders-horizontal')} <span class="btn-label">${esc(t('share_edit'))}</span></button>
          <button class="btn btn-ghost btn-sm btn-retarget" data-id="${s.id}" title="${esc(t('share_retarget'))}">${icon('repeat')} <span class="btn-label">${esc(t('share_retarget'))}</span></button>
          <button class="btn btn-danger btn-sm btn-revoke" data-id="${s.id}" title="${esc(t('revoke'))}">${icon('trash-2')} <span class="btn-label">${esc(t('revoke'))}</span></button>
        </div>`;

    // A share's link fields, then its actions: the whole URL, or in split view
    // the URL without the key and the key on its own row. A field shows the
    // path only ("…" marks the cut domain); its copy button copies it in
    // full. Next to a field go only the buttons that act on it (open, copy).
    // Shared by the shares page and the create dialog's recap.
    function shareUrlRows(s) {
        const fullUrl = window.location.origin + s.share_url;
        const hi = fullUrl.indexOf('#');
        const split = _shrListSplit && hi >= 0;
        const urlPart = split ? fullUrl.slice(0, hi) : fullUrl;
        const urlDisplay = '…' + urlPart.replace(window.location.origin, '');
        let html = `
        <div class="shr-url-row">
          <input type="text" class="field-input" value="${esc(urlDisplay)}" readonly>
          <a href="${esc(urlPart)}" class="btn btn-ghost btn-sm" title="${esc(t('open'))}">${icon('arrow-right')} <span class="btn-label">${esc(t('open'))}</span></a>
          <button class="btn btn-ghost btn-sm shr-url-copy" data-url="${esc(urlPart)}" title="${esc(t('copy_link'))}">${icon('copy')} <span class="btn-label">${esc(t('copy_link'))}</span></button>
        </div>`;
        if (split) {
            const keyPart = fullUrl.slice(hi + 1);
            html += `
        <div class="shr-url-row">
          <input type="text" class="field-input" value="${esc(keyPart)}" readonly>
          <button class="btn btn-ghost btn-sm shr-url-copy-key" data-url="${esc(keyPart)}" title="${esc(t('copy'))}">${icon('copy')} <span class="btn-label">${esc(t('copy'))}</span></button>
        </div>`;
        }
        return html + shareActionsRow(s);
    }

    // The link itself: its number, the day it was created and its openings
    // (dashboard only, never shown to visitors), "Link 4 (8 Jul 2026): 96
    // visits". The URL's own history: a retarget keeps it, a revoke ends it.
    // The date also tells several links on one item apart. Downloads and
    // previews are the file's, on its download and preview badges.
    function _renderShareStats(s) {
        const n = Number(s.visit_count) || 0;
        const label = shareNumber(s.id) + (s.created_at ? ` (${relDate(s.created_at)})` : '');
        return `<div class="shr-stats">${esc(label)}: ${ICON_VISITS} ${n} ${esc(visitsWord(n))}</div>`;
    }

    // Renders the shares list from an already fetched array (loadShares(), or
    // the split-view toggle without a refetch).
    function renderShareList(shares) {
        const list = $('shr-list');
        const label = $('shr-list-split-toggle')?.closest('label');
        if (!list) return;
        list.innerHTML = '';
        if (label) label.hidden = !shares.length;
        // Links about to expire: a card on top, their expiry in orange below,
        // and the dot on the links icon. Only here, so it never nags.
        const soon = shares.filter(isExpiringSoon)
            .sort((a, b) => parseServerDate(a.expires_at) - parseServerDate(b.expires_at));
        $('btn-shares-nav')?.classList.toggle('has-alert', soon.length > 0);
        if (soon.length) {
            const box = document.createElement('div');
            box.className = 'shr-card shr-expiring';
            box.innerHTML = `<div class="shr-expiring-title">${icon('hourglass')} ${esc(fmt(t('shares_expiring_title'), { hours: Math.round(EXPIRING_SOON_MS / 3600000) }))}</div>` +
                soon.map(s => `<button type="button" class="btn-link shr-expiring-item" data-sid="${esc(String(s.id))}">` +
                    `${icon('link')} ${esc(shareNumber(s.id))} · ${esc(s.display_name || '')} · ${esc(shareExpiryText(s.expires_at))}</button>`).join('');
            box.querySelectorAll('.shr-expiring-item').forEach(b =>
                b.addEventListener('click', () => focusShareCard(Number(b.dataset.sid))));
            list.appendChild(box);
        }
        const sortBar = $('shr-sort-bar');
        if (sortBar) sortBar.hidden = shares.length <= 1; // nothing to order
        AESBinHelper.paintSortBar(sortBar, _shrSort);
        if (!shares.length) {
            list.innerHTML = `<div class="empty-state">${esc(t('no_shares'))}</div>`;
            return;
        }
        const sorted = AESBinHelper.sortItems(shares, _shrSort, {
            name: s => s.display_name || '',
            date: s => parseServerDate(s.created_at),
            visits: s => s.visit_count,
        });
        sorted.forEach(s => {
            const typeIcon = s.type === 'file' ? icon('file') : icon('folder');
            const expiryText = shareExpiryText(s.expires_at);
            const name = s.display_name || '—';
            // A folder's file count next to its name, as in the files view.
            const fileCount = (s.type === 'directory' && s.file_count != null)
                ? `<span class="fname-count"> (${Number(s.file_count)})</span>` : '';
            const card = document.createElement('div');
            card.className = 'shr-card';
            card.dataset.sid = String(s.id);
            const nameClickable = s.nav_container_id ? ' shr-card-name-link' : '';

            const urlSection = s.share_url ? shareUrlRows(s) : '';

            card.innerHTML = `
      <div class="shr-card-info">
        <div class="shr-card-name${nameClickable}" title="${nameClickable ? esc(t('open')) : ''}">${typeIcon} ${esc(name)}${fileCount} ${s.password_protected ? icon('lock') : ''}</div>
        <div class="shr-meta">${esc(s.type === 'file' ? t('type_file') : t('type_directory'))} · ${isExpiringSoon(s) ? `<span class="shr-expiry-soon">${expiryText}</span>` : expiryText}</div>
        ${_renderShareStats(s)}
        ${urlSection}
      </div>
      <div class="shr-card-actions shr-card-actions-fallback" ${s.share_url ? 'hidden' : ''}>
        <button class="btn btn-danger btn-sm btn-revoke" data-id="${s.id}">${esc(t('revoke'))}</button>
      </div>`;
            // Name click navigates to the source file/directory, like a search result.
            if (s.nav_container_id) {
                card.querySelector('.shr-card-name-link')?.addEventListener('click', () => {
                    openLocation(s.nav_container_id, s.nav_directory_id, s.type === 'file' ? s.target_id : null)
                        .then(ok => ok || showToast(t('target_gone'), 'warning'));
                });
            }
            const copyHandler = e => copyShareLink(e.currentTarget);
            card.querySelector('.shr-url-copy')?.addEventListener('click', copyHandler);
            card.querySelector('.shr-url-copy-key')?.addEventListener('click', copyHandler);
            card.querySelector('.btn-shr-edit')?.addEventListener('click', () => {
                openShareEditModal(s);
            });
            card.querySelector('.btn-retarget')?.addEventListener('click', () => {
                openRetargetModal(s);
            });
            card.querySelector('.btn-revoke').addEventListener('click', async e => {
                const shareId = parseInt(e.currentTarget.dataset.id, 10); // capture before await
                const confirmed = await confirmRevoke(s);
                if (!confirmed) return;
                try {
                    await API.shares.revoke(shareId);
                    showToast(t('share_revoked_ok'), 'success');
                    loadShares();
                } catch (err) {
                    showToast(err.message, 'error');
                }
            });
            list.appendChild(card);
        });
    }

    // ── Share edit and retarget ───────────────────────────────────────────────
    // The URL never changes, only its expiry and password, or the file or
    // folder it serves.
    let _editShare = null;
    let _retargetShare = null;

    // Expiry and password on an existing link. Neither is part of any key
    // derivation (the password is a gate checked after the key, the expiry a
    // plain column), so the URL, the key and every wrapped DEK are untouched
    // and a posted link keeps working.
    function openShareEditModal(share) {
        _editShare = share;
        const tgtEl = $('se-target');
        if (tgtEl) {
            const label = share.display_name || '';
            tgtEl.textContent = label;
            tgtEl.hidden = !label;
        }
        const expSel = $('se-exp');
        const pwInp = $('se-pw');
        const rmWrap = $('se-pw-remove-wrap');
        const rmChk = $('se-pw-remove');
        if (!expSel || !pwInp) return;

        // Both fields default to "leave alone". Preselecting the current expiry
        // would silently restart the clock: "30 days" on a three-week-old link is
        // a different date.
        expSel.value = '';
        pwInp.value = '';
        if (rmChk) rmChk.checked = false;
        if (rmWrap) rmWrap.hidden = !share.password_protected;

        // The current expiry goes into the "leave unchanged" option instead of a
        // separate line.
        const keepOpt = expSel.querySelector('option[value=""]');
        if (keepOpt) {
            keepOpt.textContent =
                t('share_edit_keep') + ' (' + shareExpiryText(share.expires_at) + ')';
        }

        // "Never" is removed when the link already never expires: it is the only
        // option that can match the current state, since durations always move
        // the date. Hidden and disabled, because some native pickers still render
        // hidden options.
        const neverOpt = expSel.querySelector('option[value="0"]');
        if (neverOpt) {
            const redundant = !share.expires_at;
            neverOpt.hidden = redundant;
            neverOpt.disabled = redundant;
        }
        const pwNow = $('se-pw-current');
        if (pwNow) {
            pwNow.textContent = t('share_edit_pw_current') + ': ' +
                (share.password_protected ? t('yes') : t('no'));
        }

        showModal('modal-shr-edit');
    }

    async function saveShareEdit() {
        if (!_editShare) return;
        const expSel = $('se-exp');
        const pw = $('se-pw')?.value ?? '';
        const remove = $('se-pw-remove')?.checked === true;

        // Only changed keys are sent: the endpoint reads an absent key as "leave
        // alone" and '' / 0 as real values (remove the password, never expire).
        const patch = {};
        if (expSel && expSel.value !== '') patch.expires_in = parseInt(expSel.value, 10);
        if (remove) {
            patch.password = '';
        } else if (pw !== '') {
            patch.password = pw;
        }

        if (!Object.keys(patch).length) {
            showToast(t('share_edit_nothing'), 'warning', 3000);
            return;
        }

        const btn = $('btn-se-save');
        if (btn) btn.disabled = true;
        try {
            const res = await API.shares.update(_editShare.id, patch);
            // Nothing to change (the share already had these values): the
            // server writes nothing, so don't claim an update.
            if (!res.expiry_changed && !res.password_changed) {
                hideAllModals();
                showToast(t('share_edit_nothing'), 'warning', 3000);
                return;
            }
            hideAllModals();
            // Setting a password ends visitor sessions issued under the old
            // rules; say how many.
            const killed = Number(res && res.sessions_killed) || 0;
            showToast(
                killed > 0
                    ? fmt(killed === 1 ? t('share_edit_ok_sessions_one') : t('share_edit_ok_sessions'), { count: killed })
                    : t('share_edit_ok'),
                'success',
                killed > 0 ? 5000 : 2500
            );
            loadShares();
        } catch (e) {
            showToast(e.message, 'error');
        } finally {
            if (btn) btn.disabled = false;
        }
    }

    async function openRetargetModal(share) {
        _retargetShare = share;
        const dirSel = $('rt-dir');
        const fileSel = $('rt-file');
        if (!dirSel || !fileSel) return;
        dirSel.innerHTML = '';
        fileSel.innerHTML = `<option value="">${esc(t('share_whole_dir'))}</option>`;
        try {
            // For a file share, open the dropdown on the folder the file lives
            // in rather than whichever folder sorts first.
            let currentFileDir = null;
            if (share.type === 'file') {
                try {
                    const info = await API.files.metadata(share.target_id);
                    currentFileDir = info.directory_id;
                } catch { /* file may have been deleted: fall through */ }
            }

            const res = await API.directories.list();
            (res.directories || []).forEach(d => {
                const opt = document.createElement('option');
                opt.value = d.id;
                opt.textContent = d.name + (d.file_count != null ? ` (${d.file_count})` : '');
                // The current directory target is disabled: re-picking it would
                // be a no-op.
                if (share.type === 'directory' && d.id === share.target_id) {
                    opt.disabled = true;
                    opt.textContent += ' · ' + t('share_current_target');
                }
                dirSel.appendChild(opt);
            });
            // Preselect the current target. For a directory share the option is
            // disabled, but setting .value still shows it selected.
            if (share.type === 'directory') {
                dirSel.value = String(share.target_id);
            } else if (currentFileDir) {
                dirSel.value = String(currentFileDir);
            }
            await refreshRetargetFiles();
        } catch (e) {
            showToast(e.message, 'error');
            return;
        }
        showModal('modal-retarget');
    }

    async function refreshRetargetFiles() {
        const dirSel = $('rt-dir');
        const fileSel = $('rt-file');
        if (!dirSel || !fileSel) return;
        fileSel.innerHTML = `<option value="">${esc(t('share_whole_dir'))}</option>`;
        const did = parseInt(dirSel.value, 10);
        if (!did) return;
        try {
            const res = await API.files.list(null, did);
            let currentFileId = null;
            (res.files || []).forEach(f => {
                const opt = document.createElement('option');
                opt.value = f.id;
                opt.textContent = f.original_name;
                // Same rule for the share's current file target.
                if (_retargetShare?.type === 'file' && f.id === _retargetShare.target_id) {
                    opt.disabled = true;
                    opt.textContent += ' · ' + t('share_current_target');
                    currentFileId = f.id;
                }
                fileSel.appendChild(opt);
            });
            // Preselect the current file instead of "whole folder".
            if (currentFileId !== null) {
                fileSel.value = String(currentFileId);
            }
        } catch (e) {
            showToast(e.message, 'error');
        }
    }

    // ── Description char counter (fileinfo-desc / dirinfo-desc) ───────────────
    // maxlength already caps typing and pasting at 250; this makes the limit
    // visible so a clipped paste is noticed. Amber past 90%, red at the cap.
    // It does not disable Save: 250 is a valid length.
    function updateDescCounter(ta) {
        if (!ta) return;
        const counter = $(ta.id + '-counter');
        if (!counter) return;
        const max = Number(ta.maxLength) > 0 ? ta.maxLength : 250;
        const len = ta.value.length;
        counter.textContent = `${len}/${max}`;
        counter.classList.toggle('desc-counter-warn', len >= max * 0.9 && len < max);
        counter.classList.toggle('desc-counter-max', len >= max);
        ta.classList.toggle('at-limit', len >= max);
    }
    function bindDescCounters() {
        ['fileinfo-desc', 'dirinfo-desc'].forEach(id => {
            const ta = $(id);
            if (!ta) return;
            ta.addEventListener('input', () => updateDescCounter(ta));
        });
    }

    // ── Description mini-format toolbar (B / I / S / quote / code / link) ─────
    // Buttons wrap the textarea selection in the markers fmtDesc() renders.
    function bindDescToolbars() {
        document.querySelectorAll('.desc-toolbar').forEach(bar => {
            const ta = $(bar.dataset.target);
            if (!ta) return;
            bar.querySelectorAll('.desc-fmt').forEach(btn => {
                btn.addEventListener('click', () => {
                    const start = ta.selectionStart ?? 0;
                    const end = ta.selectionEnd ?? 0;
                    const sel = ta.value.slice(start, end);
                    let ins, caret;
                    if (btn.dataset.md === 'link') {
                        const label = sel || t('desc_fmt_link_text');
                        ins = `[${label}](https://)`;
                        caret = start + ins.length - 1; // caret inside the URL
                    } else if (btn.dataset.md === 'quote') {
                        // Asymmetric wrap (>> … <<), may span lines.
                        ins = '>>' + (sel || '') + '<<';
                        caret = sel ? start + ins.length : start + 2;
                    } else {
                        const m = btn.dataset.md;
                        ins = m + (sel || '') + m;
                        caret = sel ? start + ins.length : start + m.length;
                    }
                    ta.value = ta.value.slice(0, start) + ins + ta.value.slice(end);
                    ta.focus();
                    ta.setSelectionRange(caret, caret);
                    // Fire input so dirty-checks (file/dir modals) notice.
                    ta.dispatchEvent(new Event('input', { bubbles: true }));
                });
            });
        });
    }

    function bindRetarget() {
        $('rt-dir')?.addEventListener('change', refreshRetargetFiles);
        $('btn-se-save')?.addEventListener('click', saveShareEdit);
        $('btn-rt-save')?.addEventListener('click', async () => {
            if (!_retargetShare) return;
            const did = parseInt($('rt-dir')?.value, 10);
            const fid = parseInt($('rt-file')?.value, 10);
            if (!did && !fid) return;
            const btn = $('btn-rt-save');
            btn.disabled = true;
            try {
                await API.shares.retarget(
                    _retargetShare.id,
                    fid ? 'file' : 'directory',
                    fid || did
                );
                hideAllModals();
                showToast(t('share_retargeted'), 'success', 2500);
                _existingSharesCache = null;
                reloadShareViews();
                loadShares();
            } catch (e) {
                showToast(e.message, 'error');
            } finally {
                btn.disabled = false;
            }
        });
    }

    // ── Trash / Activity ──────────────────────────────────────────────────────
    async function loadTrash() {
        let files = [];
        try {
            const r = await API.system.trash();
            files = r.files || [];
        } catch (e) {
            showToast(e.message, 'error');
        }
        updateTrashBadge(files.length);
        _trashFiles = files;
        const actions = $('trash-view-actions');
        if (actions) actions.hidden = files.length === 0;

        const list = $('trash-list');
        if (!list) return;
        list.innerHTML = '';
        const no = $('no-trash');
        if (no) no.hidden = files.length > 0;

        files.forEach(f => {
            const cName = f.trashed_from_container_name || '?';
            const dName = f.trashed_from_dir_name || null;
            const pathLabel = dName ? `${icon('archive')} ${esc(cName)} › ${icon('folder')} ${esc(dName)}` : `${icon('archive')} ${esc(cName)}`;
            const card = document.createElement('div');
            // Two blocks: what the file is (name, description) above the rule,
            // where it came from and what it weighs below it.
            card.className = 'file-card file-card-trash';
            card.dataset.fid = String(f.id);
            card.innerHTML = `
      <div class="fc-main">
        <div class="fc-name">
          <span class="fname" title="ID: ${f.id}">${mimeIcon(f.mime, f.original_name)} ${esc(f.original_name)}</span>
          ${f.description ? `<span class="file-sub-desc">${icon('quote')} ${fmtDesc(f.description)}</span>` : ''}
        </div>
        <div class="fc-meta fc-meta-divided">
          <span class="fc-meta-item fc-meta-path path-label">${pathLabel}</span>
          <span class="fc-meta-item">${f.size_human || fmtBytes(f.size)}</span>
          <span class="fc-meta-item">${esc(f.mime || '—')}</span>
          <span class="fc-meta-item">${relDate(f.created_at)}</span>
        </div>
      </div>
      <div class="fc-actions">
        <button class="btn btn-ghost btn-sm" data-a="restore">${icon('undo-2')} ${esc(t('restore'))}</button>
        <button class="btn btn-danger btn-sm" data-a="del">${icon('trash-2')} ${esc(t('delete'))}</button>
      </div>`;
            card.querySelector('[data-a="restore"]').addEventListener('click', async () => {
                try {
                    const res = await API.files.restore(f.id);
                    _searchCache = null;
                    // Named, so restoring several in a row reads as several. A
                    // taken name says what the file is called now.
                    // Its folder (or container) recreated by name: said, since
                    // the file lands somewhere that did not exist a moment ago.
                    let msg = res.renamed
                        ? fmt(t('file_restored_renamed'), { name: f.original_name, new: res.name })
                        : fmt(t('file_restored'), { name: res.name });
                    if (res.recreated) {
                        msg += ' ' + (res.recreated === 'both'
                            ? t('file_restored_recreated_both')
                            : t('file_restored_recreated_directory'));
                    }
                    showToast(msg, 'success', res.renamed || res.recreated ? 5000 : 2500);
                    loadTrash();
                } catch (e) {
                    showToast(e.message, 'error');
                }
            });
            card.querySelector('[data-a="del"]').addEventListener('click', async () => {
                if (!await confirmModal(t('delete_confirm'))) return;
                try {
                    await API.files.delete(f.id);
                    _searchCache = null;
                    showToast(t('file_deleted'), 'success', 2000);
                    loadTrash();
                } catch (e) {
                    showToast(e.message, 'error');
                }
            });
            list.appendChild(card);
        });
    }

    // "Empty trash" is the cards' Delete button run over the whole list: the
    // same endpoint, one file per request, so elevation (the first request
    // asks for the password when it has lapsed, see api.js), the activity log
    // and the share clean-up are exactly those of a single delete, and no one
    // request has to wipe gigabytes within PHP's time limit. One confirmation
    // for the lot. A failure stops the run and says why; the list reloads
    // either way and shows what is left.
    function bindTrashEmpty() {
        const btn = $('btn-trash-empty');
        btn?.addEventListener('click', async () => {
            const files = _trashFiles;
            if (!files.length) return;
            const size = fmtBytes(files.reduce((sum, f) => sum + (f.size || 0), 0));
            const msg = files.length === 1
                ? fmt(t('trash_empty_confirm_one'), { size })
                : fmt(t('trash_empty_confirm'), { count: files.length, size });
            if (!await confirmModal(msg, t('trash'), t('trash_empty_btn'))) return;
            const label = btn.innerHTML;
            btn.disabled = true;
            let done = 0;
            try {
                for (const f of files) {
                    setIconText(btn, 'trash-2', fmt(t('trash_empty_progress'), { current: done + 1, count: files.length }));
                    await API.files.delete(f.id);
                    done++;
                }
                showToast(t('trash_emptied'), 'success', 2500);
            } catch (e) {
                showToast(e.message, 'error');
            } finally {
                btn.innerHTML = label;
                btn.disabled = false;
                if (done) _searchCache = null;
                loadTrash();
            }
        });
    }

    // Empty or unparseable falls back to defaultVal, never to 0: for these
    // two fields 0 means "no cap beyond what PHP allows" and must be typed on
    // purpose. Shared by the dirty check and the save handler.
    function uploadFieldValue(el, defaultVal, allowDecimal = false) {
        const raw = el?.value;
        if (raw === '' || raw == null) return defaultVal;
        const n = allowDecimal ? parseFloat(raw) : parseInt(raw, 10);
        return Number.isFinite(n) && n >= 0 ? n : defaultVal;
    }

    async function loadActivity() {
        let cards = [];
        let preview = 3;
        try {
            const r = await fetchActivity();
            cards = r.cards || [];
            preview = Number(r.preview) || 3;
        } catch (e) {
            showToast(e.message, 'error');
        }
        const list = $('activity-list');
        const countLine = $('activity-count');
        if (!list) return;
        list.innerHTML = '';
        if (countLine) {
            // One card per event type (see docs/maintaining.md on the
            // activity log), so this counts kinds of event, not a figure
            // approaching any limit.
            setIconText(countLine, 'chart-column', fmt(cards.length === 1 ? t('activity_shown_count_one') : t('activity_shown_count'), { count: cards.length }));
        }
        if (!cards.length) {
            list.innerHTML = `<div class="empty-state">${esc(t('no_activity'))}</div>`;
            return;
        }
        // Most recently active first, as activity.php orders them.
        // card.created_at tracks the latest item's timestamp (see
        // log_activity()), so a flood on one card moves only that card up.
        cards.forEach(card => {
            list.appendChild(renderActivityCard(card, preview));
        });
    }

    // ── Settings ──────────────────────────────────────────────────────────────
    // Settings messages appear in the card the action belongs to and hide
    // after 4s (success) or 6s (error), with a per-element timer so cards
    // don't clear each other. Toasts are for global events only.
    function showCardMsg(msgEl, text, kind = 'success') {
        if (!msgEl) return;
        msgEl.className = 'alert alert-' + kind + ' sv-msg-fade';
        msgEl.textContent = text;
        msgEl.hidden = false;
        clearTimeout(msgEl._fadeTimer);
        msgEl._fadeTimer = setTimeout(() => {
            msgEl.hidden = true;
        }, kind === 'danger' ? 6000 : 4000);
    }

    function showSettingsSaved(msgEl, text) {
        showCardMsg(msgEl, text, 'success');
    }

    function bindSettings() {
        // Server-persisted privacy_enabled, as opposed to the checkbox's live
        // state (see syncPrivacyPreviewLink()).
        let privacyEnabledSaved = false;

        // Last saved values, set in loadSettingsPanel() and after each save. Save
        // handlers skip an unchanged payload client-side, which for the
        // elevation-gated sections also skips a pointless reauth prompt.
        let _settingsSnapshot = {};
        // Defaults from GET /settings (SETTINGS_DEFAULTS in config_template.php).
        // The literal fallbacks below (`?? 5`) are defensive only.
        let _settingsDefaults = {};
        function settingsUnchanged(check) {
            for (const k in check) {
                if (String(_settingsSnapshot[k]) !== String(check[k])) return false;
            }
            return true;
        }

        // A card with unsaved changes: its fields differ from the last values
        // saved (_settingsSnapshot), also while a save is in flight or waits
        // for the unlock code. Nothing before the panel has loaded once.
        const _dirtyCards = []; // {btn, inputIds, buildCheck}, one per wireDirtyToggle()
        let _settingsLoaded = false;
        const unsavedCards = () => _settingsLoaded
            ? _dirtyCards.filter(c => !settingsUnchanged(c.buildCheck()))
            : [];
        const cardTitle = btn =>
            (btn.closest('.settings-card')?.querySelector('h3, summary')?.textContent || '').trim();

        // Leaving with unsaved changes. A link or Logout in the dashboard asks
        // in its own dialog, naming the cards; a reload, closing the tab or
        // the browser's Back can only get the browser's generic prompt.
        _confirmLeaveUnsaved = async () => {
            const dirty = unsavedCards();
            if (!dirty.length) return true;
            return confirmModal(
                fmt(t('set_unsaved_msg'), { sections: dirty.map(c => cardTitle(c.btn)).join(', ') }),
                t('set_unsaved_title'),
                t('set_unsaved_leave')
            );
        };
        window.addEventListener('beforeunload', e => {
            if (_leavingOnPurpose || !unsavedCards().length) return;
            e.preventDefault();
            e.returnValue = ''; // older Chromium shows the prompt only with this
        });
        document.addEventListener('click', async e => {
            const a = e.target.closest?.('a[href]');
            if (!a || e.defaultPrevented || e.button !== 0 ||
                e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
            // Another tab, a download, or a view of this page: nothing is lost.
            if ((a.target && a.target !== '_self') || a.hasAttribute('download')) return;
            const url = new URL(a.href, location.href);
            // mailto: and the like leave the page where it is.
            if (url.protocol !== 'https:' && url.protocol !== 'http:') return;
            // A fragment on this same address only switches view (#files/…);
            // the same address without one reloads the page.
            if (url.origin === location.origin && url.pathname === location.pathname &&
                url.search === location.search && a.href.includes('#')) return;
            if (!unsavedCards().length) return;
            e.preventDefault();
            if (await _confirmLeaveUnsaved()) {
                _leavingOnPurpose = true;
                location.href = a.href;
            }
        });

        // Saving the language reloads the page, and opening Settings again
        // reloads its fields. Unsaved values ride across either in this tab's
        // sessionStorage and are put back once the panel has loaded, still
        // unsaved.
        const DRAFT_KEY = 'aesbin_settings_draft';
        // Unsaved changes: the gear's dot, seen from every view, also while a
        // draft waits to be put back; in Settings, a banner naming the cards
        // (each carries the same dot), its button opening the first one.
        const cardsInPageOrder = cards => cards.sort((a, b) =>
            a.btn.compareDocumentPosition(b.btn) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1);
        // The banner appears only on arriving in Settings with changes still
        // pending (from another view, the language reload, a new login):
        // while editing there, the card's dot and Save button are enough.
        // Once shown it follows every card, added or put right, and goes
        // when none is left, to come back only on the next arrival.
        let _bannerOn = false;
        function syncUnsaved(entering = false) {
            let draft = false;
            try {
                draft = !!sessionStorage.getItem(DRAFT_KEY);
            } catch { /* no storage, no draft */ }
            const dirty = cardsInPageOrder(unsavedCards());
            $('btn-settings')?.classList.toggle('has-unsaved', draft || dirty.length > 0);
            if (entering || !dirty.length) _bannerOn = dirty.length > 0;
            const shown = _bannerOn ? dirty : [];
            const bar = $('unsaved-bar');
            if (!bar) return;
            bar.hidden = !shown.length;
            $('unsaved-bar-msg').textContent = shown.length
                ? fmt(t('set_unsaved_bar'), { sections: shown.map(c => cardTitle(c.btn)).join(', ') })
                : '';
        }
        syncUnsaved();
        $('unsaved-bar-open')?.addEventListener('click', () => {
            const first = cardsInPageOrder(unsavedCards())[0];
            jumpToCard(first?.btn.closest('.settings-card'));
        });
        function stashUnsaved(exceptBtn) {
            const draft = {};
            for (const c of unsavedCards()) {
                if (c.btn === exceptBtn) continue;
                for (const id of c.inputIds) {
                    const el = $(id);
                    if (el) draft[id] = el.type === 'checkbox' ? el.checked : el.value;
                }
            }
            try {
                if (Object.keys(draft).length) sessionStorage.setItem(DRAFT_KEY, JSON.stringify(draft));
            } catch { /* storage refused: the changes are lost, as before */ }
        }
        _keepUnsaved = () => stashUnsaved(null);
        function restoreUnsaved() {
            let draft = null;
            try {
                draft = JSON.parse(sessionStorage.getItem(DRAFT_KEY) || 'null');
                sessionStorage.removeItem(DRAFT_KEY);
            } catch { /* nothing to restore */ }
            if (!draft || typeof draft !== 'object') return;
            for (const [id, v] of Object.entries(draft)) {
                const el = $(id);
                if (!el) continue;
                if (el.type === 'checkbox') el.checked = !!v;
                else el.value = String(v);
                // The card's own listeners (labels, dependent fields, dirty check).
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.dispatchEvent(new Event('change', { bubbles: true }));
            }
            // The banner (syncUnsaved(true), once the panel has loaded) names
            // the cards put back.
            refreshDirtyButtons();
        }

        // Disables a Save button while its fields match _settingsSnapshot.
        // buildCheck() returns the flat {key: value} shape settingsUnchanged()
        // takes; inputIds are the elements to watch.
        const _dirtyUpdaters = [];
        function wireDirtyToggle(btnId, inputIds, buildCheck) {
            const btn = $(btnId);
            if (!btn) return;
            const update = () => {
                const unchanged = settingsUnchanged(buildCheck());
                btn.disabled = unchanged;
                // The gear's dot, repeated on the card's own title. From the
                // fields, not the button: it stays while a save waits for the
                // unlock code.
                btn.closest('.settings-card')?.classList.toggle('is-unsaved', _settingsLoaded && !unchanged);
                syncUnsaved();
            };
            for (const id of inputIds) {
                const el = $(id);
                if (!el) continue;
                el.addEventListener(el.type === 'checkbox' ? 'change' : 'input', update);
                if (el.tagName === 'SELECT') el.addEventListener('change', update);
            }
            // Re-run after loadSettingsPanel() repopulates snapshot and fields.
            _dirtyUpdaters.push(update);
            _dirtyCards.push({ btn, inputIds, buildCheck });
        }
        // Re-runs every card's check after a save updates the snapshot, so every
        // Save button returns to disabled, not just the one clicked. The Save
        // handlers call it from finally instead of re-enabling their button:
        // after a save the fields match the snapshot again, after a failure
        // they still differ from it.
        function refreshDirtyButtons() {
            _dirtyUpdaters.forEach(u => u());
        }

        // What a card's fields hold now, keyed as the settings snapshot and the
        // request body name them. Shared by the dirty check and the Save
        // handler, so the two cannot read a field differently.
        const intField = (id, fallback) => {
            const v = parseInt($(id)?.value ?? '', 10);
            return isNaN(v) ? fallback : v;
        };
        const readRateLimit = () => ({
            login_max_attempts: intField('set-rl-max', _settingsDefaults.login_max_attempts ?? 5),
            login_window: intField('set-rl-window', _settingsDefaults.login_window ?? 1800),
        });
        const readSessionTimeout = () => ({
            session_idle_timeout: intField('set-session-timeout', _settingsDefaults.session_idle_timeout ?? 600),
            session_ip_lock: $('set-session-ip-lock')?.checked ?? false,
        });
        // MB and GB in the fields, bytes in the settings.
        const readUploadLimits = () => ({
            max_upload_bytes: uploadFieldValue($('set-upload-maxfile'), Math.round((_settingsDefaults.max_upload_bytes ?? 20971520) / 1048576)) * 1048576,
            max_total_bytes: Math.round(uploadFieldValue($('set-upload-quota'), Math.round((_settingsDefaults.max_total_bytes ?? 10737418240) / 1073741824), true) * 1073741824),
        });
        // The upload help's "Current limit" (dashboard.php renders it at load):
        // the setting capped by PHP's limit, PHP's alone when the setting is 0,
        // as upload_file_limit_bytes() computes it.
        let _phpUploadLimit = 0;
        const paintUploadHelpLimit = maxUpload => {
            const link = $('upload-help-limit');
            if (!link || !_phpUploadLimit) return;
            const limit = maxUpload > 0 ? Math.min(maxUpload, _phpUploadLimit) : _phpUploadLimit;
            link.textContent = fmt(t('upload_help_limit'), { limit: fmtBytesBinary(limit) });
        };
        const readActivityLimits = () => ({
            activity_log_preview: intField('set-activity-preview', _settingsDefaults.activity_log_preview ?? 3),
            activity_log_item_cap: intField('set-activity-item-cap', _settingsDefaults.activity_log_item_cap ?? 10),
        });

        // Footer credit, logo and level only matter while the challenge is on:
        // disabled (still visible, so the saved preference shows) when captcha is
        // off.
        function updateCaptchaFooterState() {
            const enabled = !!$('set-captcha-enabled')?.checked;
            const footerBox = $('set-captcha-show-footer');
            if (footerBox) footerBox.disabled = !enabled;
            const logoBox = $('set-captcha-show-logo');
            if (logoBox) logoBox.disabled = !enabled;
            const levelSel = $('set-captcha-level');
            if (levelSel) levelSel.disabled = !enabled;
        }
        $('set-captcha-enabled')?.addEventListener('change', updateCaptchaFooterState);
        // Each <option> carries its hint in data-hint (rendered by
        // render_captcha_level_options()).
        function updateCaptchaLevelLabel() {
            const el = $('set-captcha-level-label');
            const opt = $('set-captcha-level')?.selectedOptions?.[0];
            if (!el) return;
            if (opt?.dataset.hint) setIconText(el, 'lightbulb', opt.dataset.hint);
            else el.textContent = '';
        }
        $('set-captcha-level')?.addEventListener('change', updateCaptchaLevelLabel);
        const captchaLevelValue = () =>
            $('set-captcha-level')?.value || _settingsDefaults.captcha_level || 'medium';
        const readCaptcha = () => ({
            captcha_enabled: !!$('set-captcha-enabled')?.checked,
            captcha_show_footer: !!$('set-captcha-show-footer')?.checked,
            captcha_show_logo: !!$('set-captcha-show-logo')?.checked,
            captcha_level: captchaLevelValue(),
        });
        const readPrivacy = () => ({
            privacy_enabled: !!$('set-privacy-enabled')?.checked,
            privacy_name: $('set-privacy-name')?.value ?? '',
            privacy_address: $('set-privacy-address')?.value ?? '',
            privacy_vat: $('set-privacy-vat')?.value ?? '',
            privacy_email: $('set-privacy-email')?.value ?? '',
        });
        const readContact = () => ({
            contact_enabled: !!$('set-contact-enabled')?.checked,
            contact_link: $('set-contact-link')?.value ?? '',
            contact_referrer: !!$('set-contact-referrer')?.checked,
        });

        wireDirtyToggle('btn-save-ratelimit', ['set-rl-max', 'set-rl-window'], readRateLimit);
        wireDirtyToggle('btn-save-captcha', ['set-captcha-enabled', 'set-captcha-show-footer', 'set-captcha-show-logo', 'set-captcha-level'], readCaptcha);
        wireDirtyToggle('btn-save-session-timeout', ['set-session-timeout', 'set-session-ip-lock'], readSessionTimeout);
        wireDirtyToggle('btn-save-activity-limit', ['set-activity-preview', 'set-activity-item-cap'], readActivityLimits);
        wireDirtyToggle('btn-save-upload', ['set-upload-maxfile', 'set-upload-quota'], readUploadLimits);
        wireDirtyToggle('btn-save-locale', ['set-locale'], () => ({
            locale: $('set-locale')?.value || 'en',
        }));
        wireDirtyToggle('btn-save-privacy', ['set-privacy-enabled', 'set-privacy-name', 'set-privacy-address', 'set-privacy-vat', 'set-privacy-email'], readPrivacy);
        wireDirtyToggle('btn-save-contact', ['set-contact-enabled', 'set-contact-link', 'set-contact-referrer'], readContact);

        // A card's Save button: "Saving…" in the card, a request only when the
        // fields differ from the snapshot, then the outcome in the card.
        // payload() turns the flat check into the request body; validate()
        // returns a message to show instead of saving; after() runs once the
        // values are saved, or already were.
        function wireSettingsSave(btnId, msgId, read, { payload = c => c, validate = null, after = null } = {}) {
            const btn = $(btnId);
            if (!btn) return;
            btn.addEventListener('click', async () => {
                const msg = $(msgId);
                const check = read();
                const invalid = validate?.(check);
                if (invalid) {
                    showCardMsg(msg, invalid, 'danger');
                    return;
                }
                btn.disabled = true;
                if (msg) {
                    msg.className = 'alert';
                    msg.textContent = t('saving') + '…';
                    msg.hidden = false;
                }
                try {
                    if (!settingsUnchanged(check)) {
                        await API.system.saveSettings(payload(check));
                        Object.assign(_settingsSnapshot, check);
                    }
                    after?.(check);
                    showSettingsSaved(msg, t('saved'));
                } catch (e) {
                    showCardMsg(msg, e.message, 'danger');
                } finally {
                    // Not btn.disabled = false: see refreshDirtyButtons().
                    refreshDirtyButtons();
                }
            });
        }

        wireSettingsSave('btn-save-ratelimit', 'rl-msg', readRateLimit, {
            payload: c => ({ security: c }),
        });
        wireSettingsSave('btn-save-captcha', 'captcha-msg', readCaptcha, {
            payload: c => ({
                captcha: {
                    enabled: c.captcha_enabled,
                    show_footer: c.captcha_show_footer,
                    show_logo: c.captcha_show_logo,
                    level: c.captcha_level,
                },
            }),
        });
        wireSettingsSave('btn-save-session-timeout', 'session-timeout-msg', readSessionTimeout, {
            payload: c => ({ security: c }),
            after: c => initIdleTimer(c.session_idle_timeout),
        });
        wireSettingsSave('btn-save-activity-limit', 'activity-limit-msg', readActivityLimits);
        wireSettingsSave('btn-save-upload', 'upload-msg', readUploadLimits, {
            after: c => paintUploadHelpLimit(c.max_upload_bytes),
        });
        // The /privacy page names a data controller: settings.php refuses it
        // enabled without a name and an email, and so does this check.
        wireSettingsSave('btn-save-privacy', 'privacy-msg', readPrivacy, {
            validate: c => c.privacy_enabled && (!c.privacy_name.trim() || !c.privacy_email.trim())
                ? t('err_privacy_needs_contact') : null,
            after: c => {
                privacyEnabledSaved = c.privacy_enabled;
                syncPrivacyPreviewLink();
                syncContactFieldsWrap();
            },
        });
        wireSettingsSave('btn-save-contact', 'contact-msg', readContact, {
            validate: c => c.contact_enabled && !c.contact_link.trim()
                ? t('err_contact_needs_link') : null,
            after: () => syncContactCard(),
        });

        // ── Credentials last-changed hint ─────────────────────────────────────
        // Rendered by dashboard.php (data-updated-at, UTC) when auth.updated_at
        // is available; only formatted here, as in bindMigrateBanner().
        const credsChangedEl = $('creds-last-changed');
        if (credsChangedEl) {
            const raw = credsChangedEl.dataset.updatedAt;
            if (raw) {
                credsChangedEl.textContent = fmt(t('creds_last_changed'), { date: fmtDay(parseServerDate(raw)) });
            }
        }

        // ── TOTP reveal ───────────────────────────────────────────────────────
        $('btn-show-totp')?.addEventListener('click', async () => {
            const wrap = $('totp-reveal-wrap');
            const btn = $('btn-show-totp');
            if (!wrap.hidden) {
                wrap.hidden = true;
                setIconText(btn, 'eye', t('set_totp_show'));
                return;
            }
            btn.disabled = true;
            btn.textContent = t('loading');
            try {
                // Elevation-gated: api.js intercepts a 403 reauth_required,
                // runs the password modal and retries.
                const res = await API.auth.totpSecret();
                $('totp-reveal-secret').textContent = res.totp_secret || '';
                const qrEl = $('totp-reveal-qr');
                qrEl.innerHTML = '';
                if (res.totp_uri) renderQr(qrEl, res.totp_uri);
                wrap.hidden = false;
                setIconText(btn, 'eye-off', t('totp_hide_btn'));
            } catch (e) {
                // In-card, like every other settings action.
                showCardMsg($('totp-msg'), e.message, 'danger');
                setIconText(btn, 'eye', t('set_totp_show'));
            } finally {
                btn.disabled = false;
            }
        });

        _loadSettingsPanel = loadSettingsPanel;

        // The ALTCHA widget (about 118 KB), only for the "Test on this
        // device" button: added the first time Settings opens, not with
        // every dashboard load. A classic script, like the login page's
        // (see document-end.php): the widget copies document.currentScript's
        // nonce onto the <style> it injects, which style-src requires.
        let _altchaLoad = null;
        function loadAltcha() {
            if (window.customElements?.get('altcha-widget')) return Promise.resolve();
            const cfg = window.AESBIN_ALTCHA;
            if (!cfg) return Promise.reject(new Error('altcha'));
            _altchaLoad ??= new Promise((resolve, reject) => {
                const s = document.createElement('script');
                s.src = cfg.src;
                s.nonce = document.querySelector('script[nonce]')?.nonce || '';
                s.onload = () => {
                    globalThis.$altcha?.i18n.set(cfg.lang, cfg.i18n);
                    resolve();
                };
                s.onerror = () => {
                    _altchaLoad = null; // a later opening tries again
                    reject(new Error('altcha'));
                };
                document.head.appendChild(s);
            });
            return _altchaLoad;
        }

        async function loadSettingsPanel() {
            loadAltcha().catch(() => {});
            // Opening Settings again reloads every field from the server:
            // unsaved values from the last visit are carried over the same
            // way as across a language change.
            stashUnsaved(null);
            try {
                const s = await API.system.getSettings();
                _settingsDefaults = s.defaults || {};

                // Flat snapshot of the saved values. Keys match the flat "check"
                // objects the Save handlers build, not the API payloads.
                _settingsSnapshot = {
                    login_max_attempts: s.security?.login_max_attempts ?? _settingsDefaults.login_max_attempts ?? 5,
                    login_window: s.security?.login_window ?? _settingsDefaults.login_window ?? 1800,
                    session_idle_timeout: s.security?.session_idle_timeout ?? _settingsDefaults.session_idle_timeout ?? 600,
                    session_ip_lock: s.security?.session_ip_lock ?? false,
                    captcha_enabled: !!s.captcha?.enabled,
                    captcha_show_footer: s.captcha?.show_footer ?? true,
                    captcha_show_logo: s.captcha?.show_logo ?? true,
                    captcha_level: s.captcha?.level ?? _settingsDefaults.captcha_level ?? 'medium',
                    locale: s.locale || 'en',
                    activity_log_preview: s.activity_log_preview ?? _settingsDefaults.activity_log_preview ?? 3,
                    activity_log_item_cap: s.activity_log_item_cap ?? _settingsDefaults.activity_log_item_cap ?? 10,
                    max_upload_bytes: s.max_upload_bytes ?? _settingsDefaults.max_upload_bytes ?? 20971520,
                    max_total_bytes: s.max_total_bytes ?? _settingsDefaults.max_total_bytes ?? 10737418240,
                    privacy_enabled: !!s.privacy_enabled,
                    privacy_name: s.privacy_name ?? '',
                    privacy_address: s.privacy_address ?? '',
                    privacy_vat: s.privacy_vat ?? '',
                    privacy_email: s.privacy_email ?? '',
                    contact_enabled: !!s.contact_enabled,
                    contact_link: s.contact_link ?? '',
                    contact_referrer: !!s.contact_referrer,
                };

                // Rate limit
                if ($('set-rl-max')) $('set-rl-max').value = s.security?.login_max_attempts ?? _settingsDefaults.login_max_attempts ?? 5;
                if ($('set-rl-window')) $('set-rl-window').value = s.security?.login_window ?? _settingsDefaults.login_window ?? 1800;

                // ALTCHA
                if ($('set-captcha-enabled')) $('set-captcha-enabled').checked = !!s.captcha?.enabled;
                if ($('set-captcha-show-footer')) $('set-captcha-show-footer').checked = s.captcha?.show_footer ?? true;
                if ($('set-captcha-show-logo')) $('set-captcha-show-logo').checked = s.captcha?.show_logo ?? true;
                if ($('set-captcha-level')) $('set-captcha-level').value = s.captcha?.level ?? _settingsDefaults.captcha_level ?? 'medium';
                updateCaptchaFooterState();
                updateCaptchaLevelLabel();

                // Session timeout
                const timeout = s.security?.session_idle_timeout ?? _settingsDefaults.session_idle_timeout ?? 600;
                // setting_int() only ever returns one of the listed values.
                if ($('set-session-timeout')) $('set-session-timeout').value = String(timeout);
                // IP lock checkbox
                const ipLock = $('set-session-ip-lock');
                if (ipLock) ipLock.checked = s.security?.session_ip_lock ?? false;

                // Locale
                // The change event keeps lang-select.js's button in step.
                const localeSel = $('set-locale');
                if (localeSel) {
                    localeSel.value = s.locale || 'en';
                    localeSel.dispatchEvent(new Event('change'));
                }

                // Activity log limits
                // Always listed values, as for the session timeout above.
                if ($('set-activity-preview')) {
                    $('set-activity-preview').value = String(_settingsSnapshot.activity_log_preview);
                    updateActivityPreviewLabel(_settingsSnapshot.activity_log_preview);
                }
                if ($('set-activity-item-cap')) {
                    $('set-activity-item-cap').value = String(_settingsSnapshot.activity_log_item_cap);
                    updateActivityItemCapLabel(_settingsSnapshot.activity_log_item_cap);
                }

                // Per-file limit: stored in bytes, edited in MB.
                if ($('set-upload-maxfile')) {
                    $('set-upload-maxfile').value = Math.round((s.max_upload_bytes ?? _settingsDefaults.max_upload_bytes ?? 20971520) / 1048576);
                    // max attribute spares a round trip for a value settings.php
                    // rejects anyway. Rounded down, since the last partial MB is
                    // not usable. 0 (allowed by min=0) means "whatever PHP
                    // allows".
                    if (s.php_upload_limit) {
                        $('set-upload-maxfile').max = Math.floor(s.php_upload_limit / 1048576);
                        _phpUploadLimit = s.php_upload_limit;
                        paintUploadHelpLimit(s.max_upload_bytes ?? _settingsDefaults.max_upload_bytes ?? 20971520);
                    }
                    // Number under the field, rule under the row.
                    const hint = $('set-upload-maxfile-hint');
                    if (hint && s.php_upload_limit) hint.textContent = fmt(t('set_upload_phpcap'), { limit: fmtBytesBinary(s.php_upload_limit) });
                    const capNote = $('set-upload-phpcap');
                    if (capNote) capNote.textContent = t('set_upload_phpcap_rule');
                }
                // Total quota: stored in bytes, edited in GB (1024^3), so the figure
                // typed matches the one on the home card and the usage bar exactly.
                if ($('set-upload-quota')) $('set-upload-quota').value = Math.round((s.max_total_bytes ?? _settingsDefaults.max_total_bytes ?? 10737418240) / 1073741824 * 100) / 100;
                updateStorageUsage(s.storage_used ?? 0, s.max_total_bytes ?? _settingsDefaults.max_total_bytes ?? 10737418240);

                // Privacy page
                if ($('set-privacy-enabled')) $('set-privacy-enabled').checked = !!s.privacy_enabled;
                if ($('set-privacy-name')) $('set-privacy-name').value = s.privacy_name ?? '';
                if ($('set-privacy-address')) $('set-privacy-address').value = s.privacy_address ?? '';
                if ($('set-privacy-vat')) $('set-privacy-vat').value = s.privacy_vat ?? '';
                if ($('set-privacy-email')) $('set-privacy-email').value = s.privacy_email ?? '';
                privacyEnabledSaved = !!s.privacy_enabled;
                syncPrivacyPreviewLink();
                syncContactFieldsWrap();

                // "Contact me" footer link (standalone card)
                if ($('set-contact-enabled')) $('set-contact-enabled').checked = !!s.contact_enabled;
                if ($('set-contact-link')) $('set-contact-link').value = s.contact_link ?? '';
                if ($('set-contact-referrer')) $('set-contact-referrer').checked = !!s.contact_referrer;
                syncContactCard();

                // Snapshot matches the fields: every Save button starts disabled.
                refreshDirtyButtons();
                _settingsLoaded = true;
                // Unless a language change carried unsaved values over.
                restoreUnsaved();
                syncUnsaved(true);

                // Same response: no second request for the passkey list.
                loadPasskeyList(s.passkeys);
            } catch (e) {
                // The fields would otherwise show markup defaults as if saved.
                showToast(e.message || t('server_error'), 'error');
            }
        }

        // ── ALTCHA benchmark ──────────────────────────────────────────────────
        // Solves one real challenge at the selected level (saved or not) with the
        // login page's widget and reports the time. /api/system/captcha/test signs
        // it with a throwaway key, so the solution is worthless elsewhere. The
        // challenge is fetched through API (session key) and handed to the
        // widget as JSON, which it parses instead of fetching a URL; the time
        // then measures the solve alone.
        $('btn-captcha-test')?.addEventListener('click', async () => {
            const btn = $('btn-captcha-test');
            const out = $('set-captcha-test-result');
            const host = $('captcha-test-host');
            if (!btn || !out || !host) return;
            await loadAltcha().catch(() => {});
            if (!window.customElements?.get('altcha-widget')) {
                setIconText(out, 'triangle-alert', t('altcha_error'), 'ic-warn');
                return;
            }
            const level = captchaLevelValue();
            const label = $('set-captcha-level')?.selectedOptions?.[0]?.textContent?.trim() || level;

            host.replaceChildren();
            btn.disabled = true;
            setIconText(out, 'hourglass', fmt(t('set_captcha_test_running'), { level: label }));
            let challenge;
            try {
                challenge = await API.system.captchaTest(level);
            } catch (e) {
                setIconText(out, 'triangle-alert', e.message || t('altcha_error'), 'ic-warn');
                btn.disabled = false;
                return;
            }

            const widget = document.createElement('altcha-widget');
            widget.setAttribute('challenge', JSON.stringify(challenge));
            widget.setAttribute('auto', 'onload');
            widget.setAttribute('configuration', JSON.stringify({ hideFooter: true, hideLogo: true }));
            const started = performance.now();
            widget.addEventListener('statechange', (e) => {
                const st = e.detail?.state;
                if (st !== 'verified' && st !== 'error') return;
                const secs = ((performance.now() - started) / 1000).toFixed(1).replace('.', AESBinHelper.decimalSep());
                if (st === 'verified') {
                    setIconText(out, 'circle-check', fmt(t('set_captcha_test_result'), { level: label, seconds: secs }), 'ic-ok');
                } else {
                    setIconText(out, 'triangle-alert', t('altcha_error'), 'ic-warn');
                }
                btn.disabled = false;
                // Leave the widget's own event dispatch before removing it.
                setTimeout(() => host.replaceChildren(), 0);
            });
            host.appendChild(widget);
            // auto="onload" covers a widget present at page load; one added
            // later may mount without starting, so start it if it hasn't.
            setTimeout(() => {
                if (widget.isConnected && typeof widget.getState === 'function' &&
                    widget.getState() === 'unverified' && typeof widget.verify === 'function') {
                    widget.verify();
                }
            }, 200);
        });

        // ── Activity log limit labels ─────────────────────────────────────────
        function updateActivityPreviewLabel(val) {
            const el = $('set-activity-preview-label');
            if (!el) return;
            const count = parseInt(val, 10);
            setIconText(el, 'lightbulb', fmt(count === 1 ? t('set_actlog_preview_hint_one') : t('set_actlog_preview_hint'), { count }));
        }
        function updateActivityItemCapLabel(val) {
            const el = $('set-activity-item-cap-label');
            if (!el) return;
            setIconText(el, 'lightbulb', fmt(t('set_actlog_item_cap_hint'), { count: parseInt(val, 10) }));
        }
        $('set-activity-preview')?.addEventListener('change', e => updateActivityPreviewLabel(e.target.value));
        $('set-activity-item-cap')?.addEventListener('change', e => updateActivityItemCapLabel(e.target.value));

        // ── Flush activity log now ────────────────────────────────────────────
        $('btn-purge-activity')?.addEventListener('click', async () => {
            const confirmed = await confirmModal(t('set_actlog_purge_confirm'), t('set_actlog_title'));
            if (!confirmed) return;
            const btn = $('btn-purge-activity');
            const msg = $('activity-limit-msg');
            btn.disabled = true;
            try {
                await API.system.activityPurge();
                showSettingsSaved(msg, t('set_actlog_purged'));
            } catch (e) {
                showCardMsg(msg, e.message, 'danger');
            } finally {
                btn.disabled = false;
            }
        });

        // ── Storage usage bar ─────────────────────────────────────────────────
        function updateStorageUsage(used, quota) {
            const wrap = $('storage-usage-wrap');
            const track = $('storage-usage-trk');
            const fill = $('storage-usage-fill');
            const label = $('storage-usage-label');
            if (!wrap) return;
            if (quota > 0) {
                const pct = Math.min(100, Math.round(used / quota * 100));
                if (track) track.hidden = false;
                if (fill) fill.style.width = pct + '%';
                if (label) label.textContent = `${fmtBytesBinary(used)} / ${fmtBytesBinary(quota)} (${pct}%)`;
            } else {
                if (track) track.hidden = true;
                if (label) label.textContent = fmt(t('set_upload_used'), { used: fmtBytesBinary(used) });
            }
            wrap.hidden = false;
        }

        // ── Save locale ───────────────────────────────────────────────────────
        $('btn-save-locale')?.addEventListener('click', async () => {
            const btn = $('btn-save-locale');
            const sel = $('set-locale');
            const msg = $('locale-msg');
            // Frozen during the write, against double clicks.
            if (btn) btn.disabled = true;
            if (sel) sel.disabled = true;
            if (msg) {
                msg.className = 'alert';
                msg.textContent = t('saving') + '…';
                msg.hidden = false;
            }
            try {
                const locale = sel?.value || 'en';
                if (settingsUnchanged({ locale })) {
                    if (msg) {
                        msg.className = 'alert alert-success';
                        msg.textContent = t('saved');
                    }
                    if (btn) btn.disabled = false;
                    if (sel) sel.disabled = false;
                    return;
                }
                const res = await API.system.saveSettings({
                    locale
                });
                _settingsSnapshot.locale = locale;
                if (msg) {
                    msg.className = 'alert alert-success';
                    // The server's message is in the language just picked;
                    // t('saved') would be in the one being left.
                    msg.textContent = res?.message || t('saved');
                }
                stashUnsaved(btn);
                setTimeout(reloadPage, 600);
            } catch (e) {
                showCardMsg(msg, e.message, 'danger');
                if (btn) btn.disabled = false;
                if (sel) sel.disabled = false;
            }
        });

        // ── Privacy page ──────────────────────────────────────────────────────
        // The /privacy preview link 404s until privacy_enabled is saved, so three
        // states: checkbox off → hidden; on but unsaved → greyed, not clickable;
        // on and saved → a real link.
        function syncPrivacyPreviewLink() {
            const link = $('privacy-preview-link');
            if (!link) return;
            const checked = !!$('set-privacy-enabled')?.checked;
            link.hidden = !checked;
            if (!checked) return;
            const pending = !privacyEnabledSaved;
            link.classList.toggle('link-pending', pending);
            link.setAttribute('aria-disabled', pending ? 'true' : 'false');
            link.title = pending ? t('privacy_preview_pending') : '';
        }
        $('privacy-preview-link')?.addEventListener('click', e => {
            if (e.currentTarget.classList.contains('link-pending')) e.preventDefault();
        });
        // Name/address/VAT/email only matter while the page is enabled. If any
        // still holds a value (box unchecked without clearing), the block stays
        // visible, or that data would only be editable in config.php.
        function syncContactFieldsWrap() {
            const wrap = $('contact-fields-wrap');
            if (!wrap) return;
            const checked = !!$('set-privacy-enabled')?.checked;
            const hasData = ['set-privacy-name', 'set-privacy-address', 'set-privacy-vat', 'set-privacy-email']
                .some(id => ($(id)?.value ?? '').trim() !== '');
            wrap.hidden = !checked && !hasData;
            // The required marks apply only while the box is on; the block
            // can stay visible with old data when it is off.
            if ($('privacy-name-required')) $('privacy-name-required').hidden = !checked;
            if ($('privacy-email-required')) $('privacy-email-required').hidden = !checked;
        }
        $('set-privacy-enabled')?.addEventListener('change', () => {
            syncPrivacyPreviewLink();
            syncContactFieldsWrap();
        });
        // The privacy email feeds the Contact card's "use privacy email" button.
        $('set-privacy-email')?.addEventListener('input', () => syncContactCard());

        // ── "Contact me" footer link: standalone card ────────────────────────
        // Independent of the privacy page: contact_link is its only source. The
        // "use privacy email" button is a one-time prefill, not a live binding.
        function syncContactCard() {
            const enabled = !!$('set-contact-enabled')?.checked;
            const hasData = ($('set-contact-link')?.value ?? '').trim() !== '';
            const fieldsWrap = $('contact-card-fields');
            if (fieldsWrap) fieldsWrap.hidden = !enabled && !hasData;
            // Required only while enabled (as in syncContactFieldsWrap()).
            if ($('contact-link-required')) $('contact-link-required').hidden = !enabled;

            // Keyed on the privacy email having a value, not on the privacy
            // page being enabled, like syncContactFieldsWrap().
            const privacyEmail = ($('set-privacy-email')?.value ?? '').trim();
            const contactLink = ($('set-contact-link')?.value ?? '').trim();
            // The referrer choice applies to an https:// link only.
            const isUrl = contactLink.toLowerCase().startsWith('https://');
            if ($('contact-referrer-row')) $('contact-referrer-row').hidden = !isUrl;
            if ($('contact-referrer-hint')) $('contact-referrer-hint').hidden = !isUrl;
            const useBtn = $('btn-use-privacy-email');
            if (useBtn) {
                // Hidden when there is nothing to copy or the two already match.
                useBtn.hidden = privacyEmail === '' || contactLink === privacyEmail;
                useBtn.textContent = fmt(t('set_contact_use_privacy_link'), { email: privacyEmail });
                useBtn.dataset.email = privacyEmail;
            }
        }
        $('set-contact-enabled')?.addEventListener('change', syncContactCard);
        $('set-privacy-enabled')?.addEventListener('change', syncContactCard);
        // Live while typing, so the button hides once the two match.
        $('set-contact-link')?.addEventListener('input', syncContactCard);
        $('btn-use-privacy-email')?.addEventListener('click', () => {
            const email = $('btn-use-privacy-email')?.dataset.email ?? '';
            const field = $('set-contact-link');
            if (field && email) {
                field.value = email;
                // Setting .value fires no 'input' event; dispatch one so
                // wireDirtyToggle() re-evaluates Save.
                field.dispatchEvent(new Event('input', { bubbles: true }));
            }
            syncContactCard();
        });

        // ── Update check / apply ──────────────────────────────────────────────
        $('btn-check-updates')?.addEventListener('click', () => checkForUpdates(true, true));

        // ── Storage integrity check ───────────────────────────────────────────
        $('btn-check-integrity')?.addEventListener('click', () => checkIntegrity(true));
        $('integrity-bar-details')?.addEventListener('click', () => {
            renderIntegrityModal(_lastIntegrityResult);
            showModal('modal-integrity');
        });
        $('btn-integrity-recheck')?.addEventListener('click', async () => {
            const btn = $('btn-integrity-recheck');
            if (btn) btn.disabled = true;
            await checkIntegrity();
            renderIntegrityModal(_lastIntegrityResult);
            if (btn) btn.disabled = false;
        });
        $('modal-integrity')?.addEventListener('click', e => {
            const btn = e.target.closest('[data-integrity-action]');
            if (btn) handleIntegrityFixClick(btn);
        });

        // ── Passkey management ────────────────────────────────────────────────
        // The list is filled by loadSettingsPanel().

        // No WebAuthn at all: nothing to register, as on the login page, where
        // the passkey button is not shown either. loadPasskeyList() explains.
        // Without PRF: registration is disabled up front, with the reason.
        (async () => {
            const btn = $('btn-register-passkey');
            const list = $('passkey-list');
            if (!btn) return;
            if (!window.AESBinPasskey?.supported) {
                btn.closest('.card-actions-row').hidden = true;
                const hint = $('passkey-provider-hint');
                if (hint) hint.hidden = true;
                return;
            }

            if (!await window.AESBinPasskey.prfSupported()) {
                btn.disabled = true;
                btn.title = t('passkey_prf_not_supported');
                if (list) {
                    const note = document.createElement('p');
                    note.className = 'hint-sm';
                    setIconText(note, 'triangle-alert', t('passkey_prf_not_supported'), 'ic-warn');
                    list.after(note);
                }
            }
        })();

        $('btn-register-passkey')?.addEventListener('click', async () => {
            if (!window.AESBinPasskey?.supported) return;
            const btn = $('btn-register-passkey');
            btn.disabled = true;
            try {
                // passkey.js has its own fetch helpers (not API._req), so the
                // elevation gate is handled here: on reauth_required, run the
                // modal and retry the ceremony once.
                try {
                    await window.AESBinPasskey.register(); // auto-names from browser/device
                } catch (e) {
                    if (e.code !== 'reauth_required') throw e;
                    const granted = await elevationPrompt();
                    if (!granted) throw new Error(t('reauth_required'));
                    await window.AESBinPasskey.register();
                }
                showPasskeyMsg(t('passkey_registered'), 'success');
                loadPasskeyList();
            } catch (e) {
                showPasskeyMsg(e.message, 'danger');
            } finally {
                btn.disabled = false;
            }
        });
    }

    // ── Passkey management (settings panel) ───────────────────────────────────

    // passkeys: the list from a settings response already in hand; fetched
    // when absent (after a registration or a removal).
    async function loadPasskeyList(passkeys = null) {
        const list = $('passkey-list');
        if (!list) return;
        if (!window.AESBinPasskey || !window.AESBinPasskey.supported) {
            // No isSecureContext branch: bootstrap.php refuses plain HTTP and
            // the __Host- cookie needs a secure context anyway.
            list.innerHTML = `<p class="hint-sm">${esc(t('passkey_not_supported'))}</p>`;
            return;
        }
        try {
            if (!passkeys) passkeys = (await API.system.getSettings())?.passkeys ?? [];
            if (!passkeys.length) {
                list.innerHTML = `<p class="hint-sm">${esc(t('passkey_none'))}</p>`;
                return;
            }
            list.innerHTML = passkeys.map((p) => `
      <div class="passkey-item">
        <span class="passkey-item-name">${esc(p.name)}</span>
        <span class="passkey-item-date hint-sm">${p.created_at ? fmtDay(parseServerDate(p.created_at)) : ''}</span>
        <button class="btn btn-ghost btn-sm" data-cid="${esc(p.id)}" aria-label="${esc(t('delete'))}" title="${esc(t('delete'))}">${icon('trash-2')}</button>
      </div>`).join('');
            list.querySelectorAll('button[data-cid]').forEach(btn => {
                btn.addEventListener('click', async () => {
                    if (!await confirmModal(t('passkey_remove_confirm'), t('passkey_setup'))) return;
                    btn.disabled = true;
                    try {
                        // Elevation-gated; api.js handles reauth_required.
                        const r = await API.auth.passkeyRemove(btn.dataset.cid);
                        loadPasskeyList();
                        // Best-effort: signalUnknownCredential tells the password
                        // manager this credential is gone. A hint only, and
                        // Chromium-only (not Firefox).
                        let signalled = false;
                        try {
                            if (r.removed_credential_id &&
                                window.PublicKeyCredential?.signalUnknownCredential) {
                                const credentialId = r.removed_credential_id
                                    .replace(/\+/g, '-').replace(/\//g, '_')
                                    .replace(/=+$/, ''); // std base64 → base64url
                                await PublicKeyCredential.signalUnknownCredential({
                                    rpId: r.rp_id || location.hostname,
                                    credentialId
                                });
                                signalled = true;
                            }
                        } catch { /* best-effort */ }
                        // When the signal went out, whether the manager acted on
                        // it is unobservable, so the note stays conditional;
                        // without the Signal API the entry must be deleted by
                        // hand. In-card, like the registration feedback.
                        showPasskeyMsg(
                            t('passkey_removed') + ' ' +
                                (signalled ? t('passkey_manager_signaled_one') : t('passkey_manager_manual_one')),
                            'success'
                        );
                    } catch (e) {
                        btn.disabled = false;
                        showToast(e.message || t('server_error'), 'error');
                    }
                });
            });
        } catch (e) {
            list.innerHTML = `<p class="hint-sm">${esc(e.message || t('server_error'))}</p>`;
        }
    }

    // Storage path record (see storage_meta_state() in helpers.php). The
    // request is elevated, so _req() raises the password prompt first when
    // needed; the confirmation says what is about to change.
    function bindStorageMetaBanner() {
        const bar = $('storage-meta-bar');
        const btn = $('storage-meta-fix');
        if (!bar || !btn) return;
        btn.addEventListener('click', async () => {
            if (!await confirmModal(t('storage_meta_confirm'), null, t('storage_meta_fix'), false)) return;
            btn.disabled = true;
            try {
                await API.system.recordStoragePath();
                bar.hidden = true;
                showToast(t('storage_meta_done'), 'success');
            } catch (e) {
                btn.disabled = false;
                showToast(e.message || t('server_error'), 'error');
            }
        });
    }

    /**
     * Wire the database-update banner. Loops POST /api/system/migrate, one chunk
     * per call, so a long migration never has to fit in one request. Needs
     * elevation: api.js prompts on 403 as usual.
     */
    function bindMigrateBanner() {
        const bar = $('migrate-bar');
        const btn = $('migrate-bar-run');
        const msgEl = $('migrate-bar-msg');
        if (!bar || !btn || !msgEl) return;

        let finished = false;
        btn.addEventListener('click', async () => {
            // After the summary the same button reads "Continue".
            if (finished) {
                reloadPage();
                return;
            }
            btn.disabled = true;
            const from = (typeof AESBIN_DB_SCHEMA === 'number') ? AESBIN_DB_SCHEMA : null;
            const applied = [];
            let offset = 0;

            try {
                // Cap: a step returning done=false with an offset that never
                // advances would loop forever against the server. 5000 is far
                // above any legitimate migration.
                const MAX_CHUNKS = 5000;
                let chunks = 0;
                for (;;) {
                    if (++chunks > MAX_CHUNKS) {
                        throw new Error(t('migrate_too_many_chunks'));
                    }
                    const r = await API.system.migrate(offset);
                    // Progress goes in the banner text; the button is too
                    // small to read on a phone.
                    setIconText(msgEl, 'database', r.message || t('migrate_running'));
                    if (r.done) {
                        // Keep each migration's closing message: a fast one
                        // finishes before the browser paints, so the summary is
                        // the only place it can be read.
                        applied.push({ version: r.version, message: r.message || '' });
                        if (r.all_done) break;
                        offset = 0;          // next migration restarts its chunks
                    } else {
                        offset = r.next;
                    }
                }

                // The summary stays until the user continues; reloading at once
                // would erase every trace of the work before it could be read.
                let summary = t('migrate_done_title');
                if (from !== null && applied.length) {
                    summary += ' ' + fmt(t('migrate_done_range'), { from, to: applied[applied.length - 1].version });
                }
                const notes = applied.filter((a) => a.message).map((a) => a.message);
                if (notes.length) {
                    summary += ' — ' + notes.join(' · ');
                }
                setIconText(msgEl, 'circle-check', summary, 'ic-ok');
                btn.textContent = t('continue');
                btn.disabled = false;
                finished = true;
            } catch (e) {
                setIconText(msgEl, 'database', t('migrate_banner_msg'));
                btn.textContent = t('migrate_run');
                btn.disabled = false;
                showToast((e && e.message) || t('migrate_failed'), 'error');
            }
        });
    }

    // ── Update check ──────────────────────────────────────────────────────────
    async function checkForUpdates(force = false, showResult = false) {
        // The button is disabled server-side when there is no curl; the
        // card already says why (dashboard.php).
        if ($('btn-check-updates')?.disabled) return;
        const resultEl = $('update-check-result');
        const banner = $('update-bar');

        if (showResult && resultEl) {
            resultEl.textContent = t('update_checking');
        }

        try {
            const info = await API.system.checkUpdate(force);

            if (info.has_update) {
                const msg = fmt(t('update_available_msg'), { latest: info.latest, current: info.current });

                // update_check.php only returns URLs under https://github.com/ (or
                // ""). Both are held to the same rule again before reaching an href.
                const githubUrl = u => typeof u === 'string' && u.startsWith('https://github.com/');
                const releaseUrlSafe = githubUrl(info.release_url);
                const downloadUrlSafe = githubUrl(info.download_url);

                if (banner) {
                    const msgEl = $('update-bar-msg');
                    const relBtn = $('update-bar-release');
                    const dlBtn = $('update-bar-download');
                    if (msgEl) setIconText(msgEl, 'circle-arrow-up', msg);
                    if (relBtn && releaseUrlSafe) relBtn.href = info.release_url;
                    if (dlBtn && downloadUrlSafe) {
                        dlBtn.href = info.download_url;
                        dlBtn.download = `aesbin-${info.latest}.zip`;
                    }
                    banner.hidden = false;
                }

                if (resultEl) {
                    resultEl.innerHTML = `<strong>${esc(t('update_available'))}:</strong> ${esc(msg)}`;
                    if (downloadUrlSafe || releaseUrlSafe) {
                        let links = '<div class="update-bar-actions">';
                        if (releaseUrlSafe) {
                            links += `<a href="${esc(info.release_url)}" ${EXT_LINK} class="btn btn-ghost btn-sm">${icon('file-text')} ${esc(t('update_view_release'))}</a>`;
                        }
                        if (downloadUrlSafe) {
                            links += `<a href="${esc(info.download_url)}" download="aesbin-${esc(info.latest)}.zip" ${EXT_LINK} class="btn btn-primary btn-sm">${icon('download')} ${esc(t('download'))}</a>`;
                        }
                        resultEl.innerHTML += links + '</div>';
                    }
                    // Release notes: third-party Markdown, added as a text node
                    // rather than via innerHTML so it can never introduce markup.
                    const notes = (info.body || '').trim();
                    if (notes) {
                        const box = document.createElement('div');
                        box.className = 'update-notes';
                        const label = document.createElement('span');
                        label.className = 'update-notes-label';
                        label.textContent = t('update_release_notes');
                        box.appendChild(label);
                        box.appendChild(document.createTextNode(notes));
                        resultEl.appendChild(box);
                    }
                }
            } else {
                if (banner) banner.hidden = true;
                if (resultEl && showResult) {
                    // Keep the outcomes distinct: collapsing to
                    // `info.error ? failed : up_to_date` would drop the server's
                    // reason and report "no release published yet" (a fresh
                    // install of an unreleased project) as a failure.
                    if (info.status === 'unavailable') {
                        setIconText(resultEl, 'info', info.error || '');
                    } else if (info.status === 'unreachable' || info.status === 'error') {
                        setIconText(resultEl, 'triangle-alert', `${t('update_failed')}: ${info.error || ''}`.trim(), 'ic-warn');
                    } else if (info.status !== 'none' && info.error) {
                        setIconText(resultEl, 'triangle-alert', t('update_failed'), 'ic-warn');
                    } else {
                        resultEl.textContent = info.status === 'none' ? t('update_no_releases') : t('update_up_to_date');
                    }
                }
            }
        } catch (e) {
            if (resultEl && showResult) setIconText(resultEl, 'triangle-alert', e.message, 'ic-warn');
        }
    }

    // ── Storage integrity check ───────────────────────────────────────────────
    let _lastIntegrityResult = null;

    async function checkIntegrity(showResult = false) {
        const resultEl = $('integrity-check-result');
        const banner = $('integrity-bar');

        if (showResult && resultEl) {
            resultEl.textContent = t('integrity_checking');
        }

        try {
            const info = await API.system.checkIntegrity();
            _lastIntegrityResult = info;

            const c = info.counts || {};
            const total = (c.missing || 0) + (c.orphans || 0) + (c.misplaced || 0) + (c.duplicates || 0) +
                (c.tmp_orphans || 0) + (c.schema_missing || 0) + (info.multi_user ? 1 : 0);

            if (!info.ok && total > 0) {
                // Files missing while storage_path is not the recorded one
                // means a wrong storage path: say so, with the path.
                const msg = info.storage_suspect
                    ? fmt(t('integrity_storage_suspect'), { path: info.storage_path || '' })
                    : fmt(t('integrity_issue_banner'), { count: total });

                if (banner) {
                    const msgEl = $('integrity-bar-msg');
                    if (msgEl) setIconText(msgEl, 'triangle-alert', msg, 'ic-warn');
                    banner.hidden = false;
                }
                if (resultEl) {
                    resultEl.innerHTML = `<strong>${icon('triangle-alert', 'ic-warn')} ${esc(msg)}</strong> — `;
                    const link = document.createElement('button');
                    link.type = 'button';
                    link.className = 'btn-link';
                    link.textContent = t('integrity_view_details');
                    link.addEventListener('click', () => {
                        renderIntegrityModal(_lastIntegrityResult);
                        showModal('modal-integrity');
                    });
                    resultEl.appendChild(link);
                }
            } else {
                if (banner) banner.hidden = true;
                if (resultEl && showResult) resultEl.textContent = t('integrity_ok');
            }
            return info;
        } catch (e) {
            if (banner) banner.hidden = true;
            if (resultEl && showResult) setIconText(resultEl, 'triangle-alert', e.message || t('integrity_failed'), 'ic-warn');
            return null;
        }
    }

    function renderIntegrityList(listId, countId, items, renderItem) {
        const listEl = $(listId);
        const countEl = $(countId);
        const sectionEl = listEl ? listEl.closest('.integrity-section') : null;
        if (countEl) countEl.textContent = `(${items.length})`;
        if (sectionEl) sectionEl.hidden = items.length === 0;
        if (!listEl) return;
        listEl.innerHTML = items.map(renderItem).join('');
    }

    function renderIntegrityModal(info) {
        if (!info) return;

        const checkedEl = $('integrity-checked-at');
        if (checkedEl) {
            checkedEl.textContent = info.checked_at ? fmtDateTime(parseServerDate(info.checked_at)) : '';
        }

        // Where a blob lives: the active tree or the trash tree.
        const locLabel = loc => loc === 'trash'
            ? `${icon('trash-2')} ${esc(t('integrity_loc_trash'))}`
            : `${icon('folder')} ${esc(t('integrity_loc_active'))}`;

        // No Remove while storage_path is not the recorded one: the files are
        // then more likely misplaced than lost, and the server refuses it too
        // (integrity_fix.php).
        renderIntegrityList('integrity-missing-list', 'integrity-missing-count', info.missing || [], item => `
            <div class="integrity-item">
                <span class="integrity-item-name">${esc(item.name)}</span>
                <span class="integrity-item-meta">${item.trashed ? icon('trash-2') + ' ' : ''}${esc(item.storage_name)} · ${fmtBytes(item.size)}</span>
                ${info.storage_suspect ? '' : `<button class="btn btn-ghost btn-sm integrity-item-action" data-integrity-action="remove_missing"
                    data-file-id="${item.file_id}" data-name="${esc(item.name)}">${icon('trash-2')} ${esc(t('integrity_action_remove'))}</button>`}
            </div>`);

        renderIntegrityList('integrity-orphan-list', 'integrity-orphan-count', info.orphans || [], item => `
            <div class="integrity-item">
                <span class="integrity-item-name">${esc(item.path)}</span>
                <span class="integrity-item-meta">${locLabel(item.location)} · ${fmtBytes(item.size)}</span>
                <button class="btn btn-ghost btn-sm integrity-item-action" data-integrity-action="delete_orphan"
                    data-path="${esc(item.path)}" data-location="${esc(item.location)}"
                    data-name="${esc(item.path)}">${icon('trash-2')} ${esc(t('delete'))}</button>
            </div>`);

        renderIntegrityList('integrity-misplaced-list', 'integrity-misplaced-count', info.misplaced || [], item => `
            <div class="integrity-item">
                <span class="integrity-item-name">${esc(item.name)}</span>
                <span class="integrity-item-meta">${esc(item.storage_name)} — ${locLabel(item.expected)} → ${locLabel(item.found_in)}${item.found_path ? ' · ' + esc(item.found_path) : ''}</span>
                <button class="btn btn-ghost btn-sm integrity-item-action" data-integrity-action="fix_misplaced"
                    data-file-id="${item.file_id}" data-name="${esc(item.name)}">${icon('arrow-left-right')} ${esc(t('move'))}</button>
            </div>`);

        renderIntegrityList('integrity-duplicate-list', 'integrity-duplicate-count', info.duplicates || [], item => `
            <div class="integrity-item">
                <span class="integrity-item-name">${esc(item.name)}</span>
                <span class="integrity-item-meta">${esc(item.storage_name)} — ${fmt(esc(t('integrity_duplicate_stray')), { location: locLabel(item.stray_location) })} · ${fmtBytes(item.stray_size)}</span>
                <button class="btn btn-ghost btn-sm integrity-item-action" data-integrity-action="delete_duplicate"
                    data-file-id="${item.file_id}" data-stray-location="${esc(item.stray_location)}"
                    data-name="${esc(item.name)}">${icon('trash-2')} ${esc(t('delete'))}</button>
            </div>`);

        renderIntegrityList('integrity-tmp-list', 'integrity-tmp-count', info.tmp_orphans || [], item => `
            <div class="integrity-item">
                <span class="integrity-item-name">${esc(item.name)}</span>
                <span class="integrity-item-meta">${fmtBytes(item.size)} · ${Math.floor(item.age_seconds / 3600)}h</span>
                <button class="btn btn-ghost btn-sm integrity-item-action" data-integrity-action="delete_tmp_orphan"
                    data-name="${esc(item.name)}" data-location="${esc(item.location)}">${icon('trash-2')} ${esc(t('delete'))}</button>
            </div>`);

        // schema_checks.supported is false on MySQL < 8.0.16 / MariaDB < 10.3.10,
        // which expose no CHECK metadata (and would accept and discard the Fix
        // ALTER). Engine/collation drift is reported without a Fix button.
        const storageDrift = info.schema_checks?.wrong_storage || [];
        const driftEl = $('integrity-storage-list');
        if (driftEl) {
            driftEl.innerHTML = storageDrift.map(s =>
                `<div class="integrity-item">${fmt(esc(t('integrity_storage_item')), { table: esc(s) })}</div>`
            ).join('');
        }
        const driftSection = $('integrity-section-storage');
        if (driftSection) driftSection.hidden = storageDrift.length === 0;

        const schemaSupported = info.schema_checks?.supported !== false;
        renderIntegrityList('integrity-schema-list', 'integrity-schema-count',
            schemaSupported ? (info.schema_checks?.missing || []) : [], name => `
            <div class="integrity-item">
                <span class="integrity-item-name">${fmt(t('integrity_schema_item'), { name: esc(name) })}</span>
                <button class="btn btn-ghost btn-sm integrity-item-action" data-integrity-action="add_missing_check"
                    data-constraint="${esc(name)}" data-name="${esc(name)}">${icon('wrench')} ${esc(t('integrity_action_fix'))}</button>
            </div>`);

        const multiUserEl = $('integrity-section-multiuser');
        const multiUserDetailEl = $('integrity-multiuser-detail');
        const multiUserCount = info.multi_user || 0;
        if (multiUserEl) multiUserEl.hidden = multiUserCount === 0;
        if (multiUserDetailEl) multiUserDetailEl.textContent = fmt(t('integrity_multiuser_detail'), { count: multiUserCount });

        const total = (info.missing || []).length + (info.orphans || []).length +
            (info.misplaced || []).length + (info.duplicates || []).length +
            (info.tmp_orphans || []).length + storageDrift.length +
            (info.schema_checks?.missing || []).length + (multiUserCount > 0 ? 1 : 0);
        const allClearEl = $('integrity-all-clear');
        if (allClearEl) allClearEl.hidden = total > 0;
    }

    /** Handles a click on any [data-integrity-action] button inside the modal. */
    async function handleIntegrityFixClick(btn) {
        const action = btn.dataset.integrityAction;
        const name = btn.dataset.name || '';
        if (!['remove_missing', 'delete_orphan', 'fix_misplaced', 'delete_duplicate', 'delete_tmp_orphan', 'add_missing_check'].includes(action)) return;

        // 'integrity_confirm_' + action, not a lookup map: Lang::forScripts()
        // scans for this t('prefix' + …) shape to ship the strings. add_missing_check
        // repairs rather than deletes, so it gets a neutral dialog, not the red
        // "Delete" one.
        const _repair = action === 'add_missing_check';
        const ok = await confirmModal(
            fmt(t('integrity_confirm_' + action), { name }),
            _repair ? t('integrity_fix_title') : null,
            _repair ? t('integrity_action_fix') : null,
            !_repair
        );
        if (!ok) return;

        const body = { action };
        if (action === 'remove_missing' || action === 'fix_misplaced') {
            body.file_id = parseInt(btn.dataset.fileId, 10);
        } else if (action === 'delete_orphan') {
            body.path = btn.dataset.path;
            body.location = btn.dataset.location;
        } else if (action === 'delete_duplicate') {
            body.file_id = parseInt(btn.dataset.fileId, 10);
            body.stray_location = btn.dataset.strayLocation;
        } else if (action === 'delete_tmp_orphan') {
            body.name = btn.dataset.name;
            body.location = btn.dataset.location;
        } else if (action === 'add_missing_check') {
            body.constraint = btn.dataset.constraint;
        }

        btn.disabled = true;
        try {
            await API.system.fixIntegrity(body);
            showToast(t('integrity_fix_success'), 'success');
            await checkIntegrity();
            renderIntegrityModal(_lastIntegrityResult);
        } catch (e) {
            showToast(fmt(t('integrity_fix_error'), { error: e.message }), 'error', 5000);
            btn.disabled = false;
        }
    }

    // ── Elevation ("sudo mode") prompt ────────────────────────────────────────
    // One password unlocks sensitive settings for a server-side window. api.js
    // calls this on a 403 reauth_required and retries on success. Resolves
    // true (elevated) or false (cancelled), never rejects.

    function elevationPrompt() {
        return new Promise(resolve => {
            const pwEl = $('elevate-pw');
            const errEl = $('elevate-err');
            const submit = $('elevate-submit');
            const cancel = $('elevate-cancel');
            const modal = $('modal-elevate');
            if (!modal || !pwEl || !submit) { resolve(false); return; }

            let done = false;
            // Disable every other field while the modal is up: Bitwarden and
            // similar extensions fill unrelated fields (e.g. the contact email)
            // when unlocking for elevate-pw. They ignore readonly but respect
            // disabled. Restored in finish().
            const outsideFields = Array.from(document.querySelectorAll('input, textarea, select'))
                .filter(el => !modal.contains(el) && !el.disabled);
            outsideFields.forEach(el => { el.disabled = true; });

            // Stacked like promptModal(): over the dialog whose action asked
            // for the password (an integrity fix, say), which comes back when
            // this one is done. The ✕, a click outside or Back close
            // everything (hideAllModals()); a pending promise would stall
            // api.js's single-flight and hang every gated action until
            // reload, so any close we didn't initiate resolves false.
            let close = null;
            const observer = new MutationObserver(() => {
                if (modal.hidden) finish(false, true);
            });
            observer.observe(modal, { attributes: true, attributeFilter: ['hidden'] });
            // `closed`: the dialog is already gone, by another path.
            const finish = (ok, closed = false) => {
                if (done) return;
                done = true;
                observer.disconnect();
                outsideFields.forEach(el => { el.disabled = false; });
                pwEl.value = '';
                if (errEl) errEl.hidden = true;
                submit.removeEventListener('click', onSubmit);
                pwEl.removeEventListener('input', onInput);
                cancel?.removeEventListener('click', onCancel);
                pwEl.removeEventListener('keydown', onKey);
                if (!closed) close();
                resolve(ok);
            };
            const onInput = () => { submit.disabled = !pwEl.value; };
            const onSubmit = async () => {
                // Enter reaches here even while the button is disabled.
                if (submit.disabled && pwEl.value) return;
                const pw = pwEl.value;
                if (!pw) { pwEl.focus(); return; }
                submit.disabled = true;
                try {
                    const r = await API.auth.elevate(pw);
                    if (r && r.expires_at) _onElevated(r.expires_at);
                    submit.disabled = false;
                    finish(true);
                } catch (e) {
                    pwEl.value = '';
                    submit.disabled = true;
                    pwEl.focus();
                    if (errEl) {
                        errEl.textContent = e.message || t('login_error');
                        errEl.hidden = false;
                    }
                }
            };
            const onCancel = () => finish(false);
            const onKey = (e) => {
                if (e.key === 'Enter') { e.preventDefault(); onSubmit(); }
                if (e.key === 'Escape') {
                    // Handled: the document-level Escape must not act again.
                    e.stopPropagation();
                    onCancel();
                }
            };

            submit.disabled = true;
            submit.addEventListener('click', onSubmit);
            pwEl.addEventListener('input', onInput);
            cancel?.addEventListener('click', onCancel);
            pwEl.addEventListener('keydown', onKey);
            $('elevate-form')?.addEventListener('submit', e => e.preventDefault());

            close = openStackedModal(modal, () => finish(false, true));
            setTimeout(() => pwEl.focus(), 50);
        });
    }

    // In-card passkey feedback, auto-hidden like other card messages.
    function showPasskeyMsg(text, kind) {
        showCardMsg($('passkey-msg'), text, kind);
    }

    // api.js interception hook (single-flighted on its side).
    API.onReauthRequired = elevationPrompt;

    // ── Early relock ("lock now") ─────────────────────────────────────────────
    // While elevated, the legend shows a button to drop the window early.
    // Client tracking of expires_at is cosmetic; the server timestamp is the
    // only real gate.
    let _elevationHideTimer = null;
    function _setElevatedUi(elevated) {
        const drop = $('btn-elevation-drop');
        const open = $('btn-elevation-open');
        if (drop) drop.hidden = !elevated;
        if (open) open.hidden = elevated;
    }
    function _onElevated(expiresAt) {
        _setElevatedUi(true);
        clearTimeout(_elevationHideTimer);
        const ms = Math.max(0, (expiresAt * 1000) - Date.now());
        _elevationHideTimer = setTimeout(() => _setElevatedUi(false), ms);
    }
    // Server-rendered state, so the relock button survives a reload
    // mid-window.
    if (typeof AESBIN_ELEVATED_UNTIL !== 'undefined' &&
        AESBIN_ELEVATED_UNTIL * 1000 > Date.now()) {
        _onElevated(AESBIN_ELEVATED_UNTIL);
    }
    // Proactive unlock with the same modal the gated actions use.
    $('btn-elevation-open')?.addEventListener('click', () => elevationPrompt());
    $('btn-elevation-drop')?.addEventListener('click', async () => {
        const btn = $('btn-elevation-drop');
        btn.disabled = true;
        try {
            await API.auth.elevateDrop();
            // Full reload rather than toggling buttons: anything the
            // window revealed (TOTP secret/QR) must not survive the relock.
            reloadPage();
            return;
        } catch (e) {
            showToast(e.message, 'error');
        }
        btn.disabled = false;
    });

    // ── Active sessions: registry banner and settings list ────────────────────
    // A banner when other sessions exist (see SessionRegistry.php), a modal to
    // end them. A revoked session ends on its next request (401 → lock overlay).

    async function checkSessions(showErrors = false) {
        try {
            const r = await API.system.sessions();
            const sessions = r.sessions || [];
            renderSessionsBanner(sessions);
            renderSessionsList(sessions);
            return sessions;
        } catch (e) {
            if (showErrors) showToast(e.message, 'error');
            return null;
        }
    }

    function renderSessionsBanner(sessions) {
        const bar = $('sessions-bar');
        if (!bar) return;
        const others = sessions.filter(x => !x.current).length;
        if (others > 0) {
            const msgEl = $('sessions-bar-msg');
            if (msgEl) setIconText(msgEl, 'monitor-smartphone', fmt(t('sessions_banner_msg'), { count: others }));
            bar.hidden = false;
        } else {
            bar.hidden = true;
        }
    }

    function renderSessionsList(sessions) {
        // Rendered into the modal (opened from the banner or the settings card).
        renderSessionsInto('sessions-list-modal', sessions);
        const revokeAll = $('btn-revoke-others-modal');
        if (revokeAll) revokeAll.hidden = !sessions.some(x => !x.current);
    }

    function renderSessionsInto(containerId, sessions) {
        const list = $(containerId);
        if (!list) return;
        list.innerHTML = '';
        sessions.forEach(sess => {
            const el = document.createElement('div');
            el.className = 'activity-item';
            const ipHtml = sess.ip ? ipLink(sess.ip) : '—';
            const badge = sess.current ? ` <strong>· ${esc(t('sessions_this'))}</strong>` : '';
            // Client label ("Firefox · Android") first: it tells apart
            // sessions sharing an IP. No label → IP first.
            const primary = sess.client ? esc(sess.client) : ipHtml;
            const secondary = sess.client ? `<span class="activity-client">${ipHtml}</span>` : '';
            el.innerHTML = `<span class="activity-event">${primary}${badge}${secondary}</span>
      <span class="activity-meta">${esc(t('sessions_created'))}: ${fmtDateTime(parseServerDate(sess.created_at))}</span>
      <span class="activity-time">${relDate(sess.last_seen)}</span>`;
            if (!sess.current) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn btn-danger btn-sm';
                btn.textContent = t('sessions_terminate');
                btn.addEventListener('click', async () => {
                    if (!await confirmModal(t('sessions_terminate_confirm'))) return;
                    btn.disabled = true;
                    try {
                        await API.system.revokeSession(sess.id);
                        showToast(t('sessions_terminated'), 'success');
                    } catch (e) {
                        showToast(e.message, 'error');
                    }
                    await checkSessions();
                });
                el.appendChild(btn);
            }
            list.appendChild(el);
        });
    }

    async function revokeOtherSessions() {
        if (!await confirmModal(t('sessions_terminate_others_confirm'))) return;
        try {
            const r = await API.system.revokeOtherSessions();
            showToast(fmt(t('sessions_terminated_others'), { count: r.revoked ?? 0 }), 'success');
        } catch (e) {
            showToast(e.message, 'error');
        }
        await checkSessions();
    }

    // ── Android platform-autofill guard ───────────────────────────────────────
    // The keyboard strip's credential suggestions come from the OS autofill
    // framework, which ignores in-page hints (autocomplete=off and vendor
    // data-* attributes only reach extensions). Autofill services skip
    // readonly fields, so the field is readonly until focused. Programmatic
    // value assignment is unaffected.
    function bindAutofillGuard() {
        ['set-privacy-email', 'set-contact-link'].forEach(id => {
            const el = $(id);
            if (!el) return;
            el.setAttribute('readonly', '');
            el.addEventListener('focus', () => el.removeAttribute('readonly'));
            el.addEventListener('blur', () => el.setAttribute('readonly', ''));
        });
    }

    function bindSessions() {
        // The list is already rendered (checkSessions() fills the modal), so show
        // it at once and refresh in the background.
        $('sessions-bar-details')?.addEventListener('click', () => {
            showModal('modal-sessions');
            checkSessions();
        });
        $('btn-open-sessions')?.addEventListener('click', () => {
            showModal('modal-sessions');
            checkSessions();
        });
        $('sessions-bar-revoke')?.addEventListener('click', revokeOtherSessions);
        $('btn-revoke-others-modal')?.addEventListener('click', revokeOtherSessions);
        $('btn-refresh-sessions-modal')?.addEventListener('click', () => checkSessions(true));
    }

    // ── Session lock overlay ──────────────────────────────────────────────────

    /**
     * Show the full-page lock overlay. reason (see _sessionLost() in api.js):
     *   'idle' | 'expired'  inactivity
     *   'ip'                IP address changed
     *   'revoked'           ended from another session
     *   'conflict'          this tab lacks the session key
     *   anything else       generic message
     */
    function showSessionLock(reason) {
        const overlay = $('session-lock');
        const msg = $('session-lock-msg');
        if (!overlay) {
            location.href = appUrl('login');
            return;
        }
        // Race guard: the request that gets a specific 401 (e.g.
        // session_revoked) also destroys the session, so parallel requests
        // then get a bare auth_required ('auth'). Never let that downgrade a
        // specific reason already on screen.
        if (!overlay.hidden && reason === 'auth') return;
        const isConflict = reason === 'conflict';
        const title = $('session-lock-title');
        if (title) title.textContent = isConflict ? t('session_lock_conflict_title') : t('session_lock_title');
        const msgText = isConflict ?
            t('session_lock_conflict') :
            (reason === 'idle' || reason === 'expired') ?
            t('session_lock_idle') :
            reason === 'ip' ?
            t('session_lock_ip') :
            reason === 'revoked' ?
            t('session_lock_revoked') :
            t('session_lock_generic');
        if (msg) msg.textContent = msgText;
        const btn = $('session-lock-btn');
        if (btn) btn.textContent = isConflict ? t('disconnect_and_login') : t('back_to_login');
        const help = $('session-lock-help');
        if (help) {
            help.textContent = isConflict ? t('session_lock_conflict_help') : '';
            help.hidden = !isConflict;
        }
        overlay.hidden = false;
        setTimeout(() => $('session-lock-btn')?.focus(), 50);
    }

    // ── Session idle timer: a client-side countdown ───────────────────────────
    // Activity resets the countdown; a warning bar shows _idleWarnSecs before
    // expiry (20% of the timeout, 10–60 s), then logout. timeout = 0 disables.
    // The server only sees requests, so input also sends a status probe at most
    // every IDLE_KEEPALIVE_MS (inside its 30 s grace). Input-driven, never a
    // timer, so an unattended tab still expires. The deadline is a timestamp,
    // rechecked on visibility, since background tabs throttle timers.
    let _idleTimer = null;
    let _idleWarn = null;
    let _idleTick = null; // setInterval for the countdown display
    let _idleCurrent = 0;
    let _idleExpiresAt = 0; // Date.now()-based deadline, the source of truth
    let _idleWarnSecs = 60;
    let _idleListenersBound = false;
    let _idleLastKeepalive = Date.now();
    const IDLE_KEEPALIVE_MS = 20000;

    function _hideIdleBar() {
        const bar = $('idle-warn-bar');
        if (bar) bar.hidden = true;
        clearInterval(_idleTick);
        _idleTick = null;
    }

    function _idleExpire() {
        clearTimeout(_idleTimer);
        clearTimeout(_idleWarn);
        _idleTimer = null;
        _idleWarn = null;
        _hideIdleBar();
        _idleExpiresAt = 0;
        rawLogout().then(() => showSessionLock('idle'));
    }

    function _showIdleWarnBar(remainingSecs) {
        const bar = $('idle-warn-bar');
        const counter = $('idle-warn-seconds');
        if (!bar || !counter) return;
        let remaining = Math.max(0, Math.round(remainingSecs));
        counter.textContent = remaining;
        bar.hidden = false;
        clearInterval(_idleTick);
        _idleTick = setInterval(() => {
            // Recompute from the deadline each tick, in case this
            // interval is throttled.
            remaining = Math.max(0, Math.round((_idleExpiresAt - Date.now()) / 1000));
            if (counter) counter.textContent = remaining;
            if (remaining <= 0) {
                clearInterval(_idleTick);
                _idleTick = null;
            }
        }, 1000);
    }

    // If the deadline passed while hidden, expire now instead of waiting for
    // a suspended setTimeout.
    function _idleRecheckOnVisible() {
        if (document.hidden || _idleExpiresAt <= 0) return;
        const remainingMs = _idleExpiresAt - Date.now();
        if (remainingMs <= 0) {
            _idleExpire();
            return;
        }
        const remainingSecs = remainingMs / 1000;
        if (remainingSecs <= _idleWarnSecs) {
            _showIdleWarnBar(remainingSecs);
        } else {
            _hideIdleBar();
        }
    }

    // Restarts the countdown on any input. A no-op while the timer is
    // disabled, so the listeners can stay bound across initIdleTimer() calls.
    function _idleReset() {
        if (_idleCurrent <= 0) return;
        if (Date.now() - _idleLastKeepalive > IDLE_KEEPALIVE_MS) {
            _idleLastKeepalive = Date.now();
            // A 401 reaches onSessionLost via _req(); network errors are
            // ignored, as in _probeSessionOnVisible().
            API.auth.status().then(data => {
                if (data && data.authenticated === false) showSessionLock('auth');
            }).catch(() => {});
        }
        clearTimeout(_idleTimer);
        clearTimeout(_idleWarn);
        _hideIdleBar();
        _idleExpiresAt = Date.now() + _idleCurrent * 1000;

        _idleWarn = setTimeout(() => {
            _showIdleWarnBar(_idleWarnSecs);
        }, (_idleCurrent - _idleWarnSecs) * 1000);

        // Foreground expiry; a late fire in a background tab is
        // covered by _idleRecheckOnVisible().
        _idleTimer = setTimeout(_idleExpire, _idleCurrent * 1000);
    }

    // Called at boot and again when the timeout is saved in Settings. The
    // listeners are bound once: rebinding on every call would pile them up.
    function initIdleTimer(timeoutSeconds) {
        clearTimeout(_idleTimer);
        clearTimeout(_idleWarn);
        _hideIdleBar();
        _idleCurrent = timeoutSeconds || 0;
        _idleExpiresAt = 0;
        if (!_idleListenersBound) {
            // Idle means no input of any kind; mouse movement counts.
            ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll', 'click'].forEach(
                ev => document.addEventListener(ev, _idleReset, { passive: true })
            );
            // The warning bar's "Stay" button needs no handler of its own:
            // its click is input like any other, and sends the keepalive.
            document.addEventListener('visibilitychange', _idleRecheckOnVisible);
            _idleListenersBound = true;
        }
        if (_idleCurrent <= 0) return; // disabled

        _idleWarnSecs = Math.min(60, Math.max(10, Math.floor(_idleCurrent * 0.2)));
        _idleReset();
    }

    // ── Session probe on tab re-focus ─────────────────────────────────────────
    // Catches a session the server ended for other reasons (mainly an IP
    // change) before the next real API call. Independent of the idle timer.
    let _sessionProbeBound = false;
    function _probeSessionOnVisible() {
        if (document.hidden) return;
        API.auth.status().then(data => {
            // A 200 does not mean "still logged in": status.php calls
            // requireAuth() (which produces the idle/IP 401s) only when
            // isAuthenticated() is true. A session destroyed from outside this
            // tab resolves with authenticated:false instead of throwing.
            if (data && data.authenticated === false) {
                showSessionLock('auth');
                return;
            }
            refreshLiveStats();
        }).catch(() => {
            // A real 401 already triggered onSessionLost via _req(). Network
            // errors are ignored: a flaky connection alone must not lock the
            // user out.
        });
    }
    // The activity figures are refreshed when the owner comes back to the tab,
    // never on a timer: every authenticated request renews the idle timeout
    // (Auth.php, last_activity), so polling would keep an unattended tab's
    // session alive forever.
    function refreshLiveStats() {
        if ($('view-activity')?.classList.contains('active')) {
            loadActivity();
            return;
        }
        const homeActivity = $('home-activity');
        if (homeActivity && $('view-home')?.classList.contains('active')) {
            fetchActivity().then(r => {
                fillHomeActivity(homeActivity, r);
                updateShareStats(r.share_stats);
            }).catch(() => {});
        }
    }

    function initSessionProbe() {
        if (_sessionProbeBound) return;
        document.addEventListener('visibilitychange', _probeSessionOnVisible);
        _sessionProbeBound = true;
    }

    // ── Modals ────────────────────────────────────────────────────────────────
    function bindModals() {
        // A click outside is ignored while dismissModals() is asking: the
        // question is answered with its buttons or Escape, never by a stray
        // click that would close the editor under it.
        $('dlg-overlay')?.addEventListener('click', e => {
            if (e.target === $('dlg-overlay') && !_askingDiscard) dismissModals();
        });
        document.querySelectorAll('.dlg-close').forEach(btn =>
            btn.addEventListener('click', hideAllModals));
        // Dialogs with their own Escape handling close first and hide the
        // overlay, so this only reaches the plain ones. A stacked dialog
        // (openStackedModal()) closes alone, back to the one underneath.
        document.addEventListener('keydown', e => {
            if (e.key !== 'Escape' || $('dlg-overlay')?.hidden) return;
            if (_stacked) {
                const { close, onCancel } = _stacked;
                close();
                onCancel();
                return;
            }
            dismissModals();
        });

        // On mobile the keyboard can hide a focused field in a tall modal;
        // .modal-body scrolls, so bring the field to its top. Twice, the second
        // after the keyboard animation: the browser's native scroll-into-view can
        // re-apply itself once the keyboard opens and override a single scroll.
        $('dlg-overlay')?.addEventListener('focusin', e => {
            const field = e.target.closest('input, textarea, select');
            if (!field) return;
            const body = field.closest('.modal-body');
            if (!body) return;
            const doScroll = () => field.scrollIntoView({
                block: 'start',
                behavior: 'smooth'
            });
            setTimeout(doScroll, 400);
            setTimeout(doScroll, 700);
        });

        $('btn-create-shr')?.addEventListener('click', async e => {
            if (!_pending.shr) return;
            const btn = e.currentTarget;
            const pw = $('shr-pw')?.value || null;
            const exp = $('shr-exp')?.value || null;
            const expLabel = $('shr-exp')?.selectedOptions?.[0]?.textContent || '';
            // A second click during the request would create a second link.
            btn.disabled = true;
            try {
                const result = await API.shares.create(
                    _pending.shr.type, _pending.shr.targetId,
                    exp ? parseInt(exp, 10) : null, pw || null);
                // Swap modals in place, not hideAllModals() first: that calls
                // popModalHistory() → history.back(), whose async popstate would
                // close the result dialog right after showShareResult() opened it.
                // With the overlay still open, pushModalHistory() returns early, so
                // the create dialog's entry covers the result one and one Back
                // still closes it.
                showShareResult(result.share_url, pw, expLabel);
                reloadShareViews();
            } catch (err) {
                showToast(err.message, 'error');
            } finally {
                btn.disabled = false;
            }
        });

        $('shr-split-toggle')?.addEventListener('change', e => {
            $('shr-url-full').hidden = e.target.checked;
            $('shr-url-split').hidden = !e.target.checked;
        });
        // The result dialog's copy buttons: the field's value, the outcome on the button.
        const mkCopier = (bid, iid) => {
            $(bid)?.addEventListener('click', async e => {
                const btn = e.currentTarget;
                AESBinHelper.flashCopyButton(btn, await AESBinHelper.copyText($(iid)?.value ?? ''));
            });
        };
        mkCopier('btn-copy-shr', 'shr-result-url');
        mkCopier('btn-copy-split-url', 'shr-split-url');
        mkCopier('btn-copy-split-key', 'shr-split-key');

        ['fileinfo-name', 'fileinfo-desc', 'fileinfo-textarea'].forEach(id => {
            $(id)?.addEventListener('input', checkFileInfoDirty);
        });
        // A menu the user has set stops following the text (see
        // updateFormatOptions()). Registered before the dirty check, which
        // reads the mark.
        ['fileinfo-encoding', 'fileinfo-eol'].forEach(id => {
            $(id)?.addEventListener('change', e => { e.currentTarget.dataset.chosen = '1'; });
        });
        ['fileinfo-ext', 'fileinfo-encoding', 'fileinfo-eol'].forEach(id => {
            $(id)?.addEventListener('change', checkFileInfoDirty);
        });

        $('btn-fileinfo-save')?.addEventListener('click', async () => {
            const mode = _pending.fileModalMode;
            const nameInp = $('fileinfo-name');
            const extSel = $('fileinfo-ext');
            const descInp = $('fileinfo-desc');
            const textarea = $('fileinfo-textarea');
                const btn = $('btn-fileinfo-save');

            // 'create': MIME from the dropdown's data-mime. 'edit': the
            // file's current MIME; the server re-derives it from the
            // extension on rename.
            const mime = mode === 'create' ?
                (extSel?.options?.[extSel.selectedIndex]?.dataset.mime || 'text/plain') :
                (_pending.fileInfo?.mime ?? null);
            const editable = mode === 'create' ? true : isEditableMime(mime);

            // Empty ext only for a binary file's locked badge without an
            // extension, or an existing file that has none; the name is then
            // used as typed.
            const rawName = nameInp?.value?.trim() || '';
            const ext = editable ?
                (extSel?.value || '') // from dropdown (text exts)
                :
                (nameInp?.dataset?.ext?.replace(/^\./, '') || ''); // from readonly badge
            const newName = ext ? `${rawName}.${ext}` : rawName;
            const newDesc = descInp?.value?.trim() ?? '';

            if (!rawName) {
                setFileInfoStatus(t('file_name_empty'), true);
                return;
            }
            // Typed before a longer extension was picked: the field keeps
            // what is already in it beyond its new maxlength, and the server
            // would cut the name silently. Said here instead.
            syncFileNameMaxLength();
            if (nameInp && rawName.length > nameInp.maxLength) {
                setFileInfoStatus(fmt(t('err_name_too_long'), { chars: Number(nameInp.dataset.max) || 200, bytes: 255 }), true);
                nameInp.focus();
                return;
            }

            // A leading "." breaks the download in every browser and app tested
            // (the dot is dropped and the file arrives as "download.bin"). Blocked
            // on the only UI path that can produce such a name; see
            // FileService::upload() for why the server cannot fix it.
            if (rawName.startsWith('.')) {
                setFileInfoStatus(t('file_name_leading_dot'), true);
                nameInp?.focus();
                return;
            }

            const isText = editable; // content editor visible for all editable types
            const newContent = isText ? (textarea?.value ?? '') : null;
            if (isText && !newContent.trim()) {
                setFileInfoStatus(t('file_content_empty'), true);
                textarea?.focus();
                return;
            }

            // The text as bytes, in the encoding and with the line endings
            // the selects under the editor ask for. null: it holds a
            // character that encoding has no byte for (only Windows-1252 can
            // refuse one). Nothing is sent: writing it as UTF-8 instead
            // would garble every accented letter already in the file.
            const bytes = isText ? encodeText(newContent, fileFormatFromInputs()) : null;
            if (isText && !bytes) {
                const encSel = $('fileinfo-encoding');
                const encName = encSel?.options?.[encSel.selectedIndex]?.textContent || '';
                setFileInfoStatus(fmt(t('file_encoding_unsupported'), { encoding: encName }), true);
                textarea?.focus();
                return;
            }

            if (btn) btn.disabled = true;
            setFileInfoStatus(t('saving'));
            try {
                if (mode === 'create') {
                    const blob = new Blob([bytes], { type: mime });
                    // A "/" or "\" becomes "_" in API.files.upload(), which
                    // then reports it like the server's own adjustments.
                    const file = new File([blob], newName, { type: mime, lastModified: Date.now() });
                    // The description goes with the file, in the same request.
                    // The server may have picked a free name ("note_01.txt").
                    const d = await API.files.upload(file, _container.id, _directory.id, null, newDesc);
                    const savedName = d.original_name || newName;
                    _searchCache = null;
                    hideAllModals();
                    // As for an edit: the confirmation, plus the reason in
                    // amber when the name typed was not kept.
                    showToast(savedName, 'success');
                    const changedMsg = nameChangedMessage(newName, savedName, [d.adjusted, extensionReason(d)]);
                    if (changedMsg) showToast(changedMsg, 'warning', 8000);
                    await loadFiles();
                } else {
                    const {
                        id
                    } = _pending.fileInfo || {};
                    if (!id) return;

                    const origContent = isText ? (textarea?.dataset?.original ?? '') : null;
                    // A change of encoding or line endings rewrites the file
                    // as an edit of its text does, unless it would write the
                    // same bytes (formatChoiceHasNoEffect()).
                    const contentChanged = isText && (newContent !== origContent ||
                        (fileFormatKey() !== _pending.fileInfo.original?.format && !formatChoiceHasNoEffect()));
                    if (contentChanged) {
                        await API.files.write(id, bytes);
                    }

                    const origName = _pending.fileInfo.filename;
                    const origDesc = _pending.fileInfo.description ?? '';
                    const descChanged = newDesc !== origDesc;
                    // One confirmation for any change (name, description,
                    // content, or several at once), and a second, amber one
                    // only when the server did not keep the name as typed:
                    // the name typed → the name stored, with the reason.
                    let adjusted = '';
                    if (newName !== origName || descChanged) {
                        const res = await API.files.rename(id, newName, descChanged ? newDesc : undefined);
                        adjusted = nameChangedMessage(newName, res?.name ?? newName, [res?.adjusted]);
                    }

                    _searchCache = null;
                    hideAllModals();
                    showToast(t('file_updated'), 'success');
                    if (adjusted) showToast(adjusted, 'warning', 8000);
                    await loadFiles();
                }
            } catch (e) {
                setFileInfoStatus('✗ ' + e.message, true);
            } finally {
                if (btn) btn.disabled = false;
            }
        });

        // A refused move stays in the modal, next to the tree, instead of a
        // toast per click. A refusal (name taken there, folder gone) keeps
        // the button off until another destination is picked: the same one
        // would fail the same way. A network error lets it be retried.
        const moveFailed = e => {
            const msg = $('move-msg');
            msg.className = 'alert alert-danger settings-msg-wrap';
            msg.textContent = e.message || t('server_error');
            msg.hidden = false;
            const refused = e.httpStatus >= 400 && e.httpStatus < 500;
            $('btn-move-ok').disabled = refused;
        };

        $('btn-move-ok')?.addEventListener('click', async () => {
            const okBtn = $('btn-move-ok');
            const {
                containerId,
                dirId
            } = _pending.moveDest || {};

            // Folder move → another container.
            if (_pending.moveKind === 'dir') {
                const movingDirId = _pending.move?.dirId;
                if (!movingDirId || !containerId) {
                    showToast(t('select_destination'), 'warning');
                    return;
                }
                try {
                    if (okBtn) okBtn.disabled = true;
                    await API.directories.move(movingDirId, containerId);
                    _struct = null;
                    _searchCache = null;
                    hideAllModals();
                    showToast(t('directory_moved'), 'success', 2000);
                    loadFiles();
                } catch (e) {
                    moveFailed(e);
                }
                return;
            }

            // File move → another folder.
            const fileId = _pending.move?.fileId;
            if (!fileId || !containerId || !dirId) {
                showToast(t('select_destination'), 'warning');
                return;
            }
            try {
                if (okBtn) okBtn.disabled = true;
                await API.files.move(fileId, containerId, dirId);
                _searchCache = null;
                hideAllModals();
                showToast(t('file_moved'), 'success', 2000);
                loadFiles();
            } catch (e) {
                moveFailed(e);
            }
        });

        // Directory info modal.
        ['dirinfo-name', 'dirinfo-desc'].forEach(id => {
            $(id)?.addEventListener('input', checkDirInfoDirty);
        });

        $('btn-dirinfo-save')?.addEventListener('click', async () => {
            const info = _pending.dirInfo || {};
            if (!info.create && !info.id) return;
            const nameInp = $('dirinfo-name');
            const descInp = $('dirinfo-desc');
            const status = $('dirinfo-status');
            const btn = $('btn-dirinfo-save');
            const newName = nameInp?.value?.trim() || '';
            if (!newName) {
                if (status) status.textContent = t('file_name_empty');
                return;
            }
            if (btn) btn.disabled = true;
            if (status) status.textContent = t('saving');
            try {
                const newDesc = descInp?.value?.trim() ?? '';
                if (info.create) {
                    await API.directories.create(info.cid, newName, newDesc || undefined);
                    showToast(t('directory_created'), 'success', 2000);
                } else {
                    const origDesc = info.original?.desc ?? '';
                    await API.directories.rename(info.id, newName, newDesc !== origDesc ? newDesc : undefined);
                    showToast(t('renamed'), 'success', 2000);
                }
                _struct = null;
                _searchCache = null;
                hideAllModals();
                await loadFiles();
                if (info.create) loadHome();
            } catch (e) {
                if (status) status.textContent = '✗ ' + e.message;
            } finally {
                if (btn) btn.disabled = false;
            }
        });

    }

    // Opening a modal pushes one history entry, so Back closes it instead of
    // leaving the app (as setHash() does for views). Switching steps while
    // the overlay is visible pushes nothing: one Back closes everything.
    function pushModalHistory(cancelResolve = null) {
        if (!$('dlg-overlay').hidden) return;
        history.pushState({
            aesbinModal: true
        }, '', location.href);
        _modalHistoryPushed = true;
        _modalCancelResolve = cancelResolve;
    }
    // Called on every explicit close (Cancel/OK/✕/outside click). Consumes
    // the entry via history.back() so the stack stays balanced; otherwise the
    // next Back would land on a same-URL entry and appear to do nothing.
    // history.back() is asynchronous: a setHash() in the same tick runs
    // first, and the queued back then undoes it. Close, then navigate only
    // after the popstate (as the create dialog's swap avoids closing at all).
    function popModalHistory() {
        if (!_modalHistoryPushed) return;
        _modalHistoryPushed = false;
        _modalCancelResolve = null;
        history.back();
    }

    // The page behind an overlay must not scroll. Every path that closes a
    // dialog must undo the lock (see also openStackedModal()).
    function lockScroll() {
        document.documentElement.style.overflow = 'hidden';
    }

    function unlockScroll() {
        document.documentElement.style.overflow = '';
    }

    function showModal(id) {
        pushModalHistory();
        const overlay = $('dlg-overlay');
        overlay.hidden = false;
        document.querySelectorAll('#dlg-overlay > .modal').forEach(m => m.hidden = true);
        $(id).hidden = false;
        lockScroll();
    }

    // Escape, a click outside and Back close a dialog without a button aimed
    // at it, so with unsaved editor text (editorHasUnsavedText()) they ask
    // first, and keeping the text is the safe answer. The ✕ and the dialog's
    // own buttons close at once: those are meant.
    let _askingDiscard = false;
    async function dismissModals() {
        if (editorHasUnsavedText()) {
            _askingDiscard = true;
            const discard = await confirmModal(t('editor_unsaved_msg'), t('set_unsaved_title'), t('editor_unsaved_close'));
            _askingDiscard = false;
            if (!discard) return;
        }
        hideAllModals();
    }

    // Also the ✕/outside-click/Escape path for promptModal() and
    // confirmModal(): their caller is still awaiting, so settle it as a cancel.
    function hideAllModals() {
        const overlay = $('dlg-overlay');
        if (overlay) {
            overlay.hidden = true;
        }
        document.querySelectorAll('.modal').forEach(m => m.hidden = true);
        unlockScroll();
        _pending = {};
        const cancel = _modalCancelResolve;
        const stacked = _stacked;
        _stacked = null;
        popModalHistory();
        if (cancel) cancel();
        if (stacked) stacked.onCancel();
    }

    // promptModal() and confirmModal() can open from inside another dialog
    // (revoking a link in the share dialog, an integrity fix, ending a
    // session). They then stack on it: the dialog underneath is hidden, not
    // closed, its _pending state survives, and it comes back when they
    // settle. Escape cancels only the stacked one (bindModals()); the ✕, a
    // click outside or Back close everything, settling it as a cancel.
    let _stacked = null; // { close, onCancel } while one is stacked

    function openStackedModal(modal, onCancel) {
        const overlay = $('dlg-overlay');
        const parent = overlay.hidden ? null :
            [...document.querySelectorAll('#dlg-overlay > .modal')].find(m => !m.hidden && m !== modal) || null;
        document.querySelectorAll('#dlg-overlay > .modal').forEach(m => m.hidden = true);
        if (!parent) {
            pushModalHistory(onCancel);
            overlay.hidden = false;
            lockScroll();
        }
        modal.hidden = false;
        // The close function for the caller's own OK/Cancel.
        const close = () => {
            modal.hidden = true;
            if (parent) {
                _stacked = null;
                parent.hidden = false;
                return;
            }
            overlay.hidden = true;
            unlockScroll();
            _pending = {};
            popModalHistory();
        };
        if (parent) _stacked = { close, onCancel };
        return close;
    }

    function showShareResult(shareUrl, pw, expLabel) {
        const fullUrl = window.location.origin + shareUrl;
        const hi = fullUrl.indexOf('#');
        $('shr-result-url').value = fullUrl;
        $('shr-split-url').value = hi >= 0 ? fullUrl.slice(0, hi) : fullUrl;
        $('shr-split-key').value = hi >= 0 ? fullUrl.slice(hi + 1) : '';
        const tog = $('shr-split-toggle');
        if (tog) tog.checked = false;
        $('shr-url-full').hidden = false;
        $('shr-url-split').hidden = true;
        // Labelled: says what never expires, and always shows password status.
        const metaEl = $('shr-result-meta');
        if (metaEl) {
            metaEl.textContent =
                `${t('shr_expiry_label')}: ${expLabel || t('share_never')}` +
                ` · ${t('password')}: ${pw ? t('yes') : t('no')}`;
        }
        showModal('dlg-lnk-result');
        renderQr($('shr-qr-output'), fullUrl);
    }

    // ── Styled modals (replace native prompt/confirm) ─────────────────────────
    function promptModal(title, defaultValue = '', hint = '') {
        return new Promise(resolve => {
            const modal = $('modal-input');
            const titleEl = $('modal-input-title');
            const field = $('modal-input-field');
            const hintEl = $('modal-input-hint');
            if (titleEl) titleEl.textContent = title;
            if (hintEl) {
                hintEl.textContent = hint;
                hintEl.hidden = !hint;
            }
            const nf = field.cloneNode(true);
            field.parentNode.replaceChild(nf, field);
            nf.value = defaultValue;
            const okEl = $('modal-input-ok');
            const c1El = $('modal-input-cancel');
            const nok = okEl.cloneNode(true);
            okEl.parentNode.replaceChild(nok, okEl);
            const nc1 = c1El.cloneNode(true);
            c1El.parentNode.replaceChild(nc1, c1El);

            let close = null;
            function done(val) {
                close();
                resolve(val ?? null);
            }
            // Disabled until the value differs from its start (no no-op
            // saves). With defaultValue === '' (create) that means
            // "disabled while empty".
            function checkPromptDirty() {
                const val = $('modal-input-field').value.trim();
                const okBtn = $('modal-input-ok');
                if (okBtn) okBtn.disabled = val === defaultValue.trim();
            }
            checkPromptDirty();
            $('modal-input-field').addEventListener('input', checkPromptDirty);
            $('modal-input-ok').addEventListener('click', () => done($('modal-input-field').value.trim() || null));
            $('modal-input-cancel').addEventListener('click', () => done(null));
            $('modal-input-field').addEventListener('keydown', e => {
                if (e.key === 'Enter' && !$('modal-input-ok').disabled) done($('modal-input-field').value.trim() || null);
                if (e.key === 'Escape') {
                    // Handled: the document-level Escape must not act again.
                    e.stopPropagation();
                    done(null);
                }
            });
            close = openStackedModal(modal, () => resolve(null));
            $('modal-input-field').focus();
            $('modal-input-field').select();
        });
    }

    // The dialog defaults to a red "Delete" button. okLabel/okDanger let
    // non-destructive actions (e.g. adding a database constraint) use a
    // neutral one. Title and button reset to the markup default when not
    // given.
    function confirmModal(message, title = null, okLabel = null, okDanger = true) {
        return new Promise(resolve => {
            const modal = $('dlg-ask');
            const msgEl = $('dlg-ask-msg');
            if (msgEl) msgEl.textContent = message;
            // Reset on every call so a title from one caller does not stick. The
            // markup default is captured once.
            const titleEl = modal?.querySelector('h3');
            if (titleEl) {
                if (!titleEl.dataset.def) titleEl.dataset.def = titleEl.textContent;
                titleEl.textContent = title || titleEl.dataset.def;
            }

            const okBtn = $('btn-dlg-ok');
            if (okBtn) {
                if (!okBtn.dataset.def) okBtn.dataset.def = okBtn.textContent.trim();
                okBtn.textContent = okLabel || okBtn.dataset.def;
                okBtn.classList.toggle('btn-danger', okDanger);
                okBtn.classList.toggle('btn-primary', !okDanger);
            }
            const canBtn = $('btn-dlg-cancel');
            if (!okBtn) {
                resolve(false);
                return;
            }

            const nok = okBtn.cloneNode(true);
            okBtn.parentNode.replaceChild(nok, okBtn);
            const nca = canBtn?.cloneNode(true);
            if (canBtn && nca) canBtn.parentNode.replaceChild(nca, canBtn);

            let close = null;
            const done = val => {
                close();
                resolve(val);
            };
            $('btn-dlg-ok').addEventListener('click', () => done(true));
            $('btn-dlg-cancel')?.addEventListener('click', () => done(false));

            close = openStackedModal(modal, () => resolve(false));
            // Into the dialog, off the button that opened it (Enter there
            // would open a second one). Cancel when the action destroys.
            ((okDanger ? $('btn-dlg-cancel') : null) ?? $('btn-dlg-ok')).focus();
        });
    }

    // ── Start ─────────────────────────────────────────────────────────────────
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
    // popstate fires on every Back/Forward, even when the fragment is
    // unchanged. A modal entry has the same URL as the view underneath, so
    // hashchange stays silent; consuming it here makes Back close the modal
    // instead of leaving the view.
    window.addEventListener('popstate', () => {
        if (_modalHistoryPushed && (_askingDiscard || editorHasUnsavedText())) {
            // The browser has already left the dialog's entry: put it back,
            // so the dialog still owns one, and ask as Escape does (unless
            // the question is already on screen). Discarding consumes it
            // again through hideAllModals().
            history.pushState({ aesbinModal: true }, '', location.href);
            if (!_askingDiscard) dismissModals();
        } else if (_modalHistoryPushed) {
            // Cleared first: hideAllModals()'s own popModalHistory() call must
            // treat the entry as consumed and not call history.back() again.
            _modalHistoryPushed = false;
            const cancel = _modalCancelResolve;
            _modalCancelResolve = null;
            hideAllModals();
            if (cancel) cancel();
        } else if (history.state?.aesbinModal) {
            // Forward onto the entry of a dialog that is closed: step off it.
            history.back();
        }
    });
    window.addEventListener('hashchange', () => {
        navigateToHash().catch(e => {
            console.warn('hashchange navigation error:', e.message);
            showView('home');
            loadHome();
        });
    });
})();
