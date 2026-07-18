// ============================================================
// Fachkonferenzen FRG – gesamtes Frontend (Vanilla JS, kein Build)
// Gehört komplett hierher, NICHT in index.html (63-KB-Proxy-Limit).
// ============================================================
'use strict';

const $ansicht = document.getElementById('ansicht');
const $nav     = document.getElementById('nav');
const $benutzer = document.getElementById('benutzer');

let me = null;                 // aktueller Benutzer (/api/auth/me)
let stammdatenTab = 'pruefung';
let vorgabenOffen = false;   // Auf/Zu-Zustand von „Vorgaben & Archiv" übersteht Neuzeichnen

// ------------------------------------------------------------
// API-Helfer
// ------------------------------------------------------------
async function api(pfad, optionen = {}) {
    const antwort = await fetch('/api' + pfad, {
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        ...optionen,
        body: optionen.body !== undefined ? JSON.stringify(optionen.body) : undefined,
    });
    const text = await antwort.text();
    let daten = null;
    try { daten = text ? JSON.parse(text) : null; }
    catch (e) {
        // "<!DOCTYPE" statt JSON = PHP-500, siehe CLAUDE.md
        throw new Error('Serverfehler (kein JSON) – supervisorctl tail fachkonferenzen stderr');
    }
    if (!antwort.ok) {
        const fehler = new Error((daten && daten.fehler) || ('HTTP ' + antwort.status));
        fehler.status = antwort.status;
        throw fehler;
    }
    return daten;
}

function meldung(text, istFehler = false) {
    const m = document.getElementById('meldung');
    m.textContent = text;
    m.className = 'meldung' + (istFehler ? ' fehler' : '');
    m.hidden = false;
    clearTimeout(m._t);
    m._t = setTimeout(() => { m.hidden = true; }, istFehler ? 6000 : 3000);
}

// HTML-Escaping
function q(s) {
    return String(s ?? '').replace(/[&<>"']/g,
        c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
}

function datumDE(iso) {
    if (!iso) return '';
    const [j, m, t] = iso.split('-');
    return `${t}.${m}.${j}`;
}
function zeitKurz(z) { return (z || '').slice(0, 5); }

// ------------------------------------------------------------
// Start + Router
// ------------------------------------------------------------
async function start() {
    try {
        me = await api('/auth/me');
    } catch (e) {
        me = null;
    }
    // NIE nur "me.id" prüfen – WebUntis-Konten haben id = 0!
    if (me && (me.id || me.id === 0)) {
        zeigeKopf();
        route();
    } else {
        zeigeLogin();
    }
}

window.addEventListener('hashchange', () => {
    if (me && (me.id || me.id === 0)) route();
});

function route() {
    const teile = (location.hash.replace(/^#\/?/, '') || '').split('/');
    navMarkieren(teile[0] || 'start');
    if (me.rolle === 'admin') {
        if (teile[0] === 'planungen')  return ansichtPlanungen();
        if (teile[0] === 'planung')    return ansichtPlanung(parseInt(teile[1], 10));
        if (teile[0] === 'stammdaten') return ansichtStammdaten();
        if (teile[0] === 'sync')       return ansichtSync();
        if (teile[0] === 'termine')    return ansichtTermine();
        return ansichtPlanungen();
    }
    return ansichtTermine();
}

function navMarkieren(aktiv) {
    $nav.querySelectorAll('a').forEach(a => {
        a.classList.toggle('aktiv', a.dataset.ziel === aktiv);
    });
}

function zeigeKopf() {
    if (me.rolle === 'admin') {
        $nav.innerHTML = `
            <a href="#/planungen"  data-ziel="planungen">Planungen</a>
            <a href="#/stammdaten" data-ziel="stammdaten">Stammdaten</a>
            <a href="#/sync"       data-ziel="sync">WebUntis-Sync</a>
            <a href="#/termine"    data-ziel="termine">Meine Termine</a>`;
    } else {
        $nav.innerHTML = `<a href="#/termine" data-ziel="termine">Meine Termine</a>`;
    }
    $benutzer.innerHTML = `
        <span>${q(me.name || me.kuerzel || '')}</span>
        <span class="rolle">${me.rolle === 'admin' ? 'Admin' : 'Lehrkraft'}</span>
        <button type="button" id="abmelden">Abmelden</button>`;
    document.getElementById('abmelden').onclick = async () => {
        await api('/auth/logout', { method: 'POST' });
        location.hash = '';
        location.reload();
    };
}

// ------------------------------------------------------------
// Login
// ------------------------------------------------------------
function zeigeLogin(modus = 'webuntis') {
    $nav.innerHTML = '';
    $benutzer.innerHTML = '';
    $ansicht.innerHTML = `
    <div class="login-buehne"><div class="login-karte">
        <div class="karte">
            <h1>Anmelden</h1>
            <p class="untertitel">Planung der Fachkonferenzen</p>
            <div class="login-tabs">
                <button type="button" id="tab-webuntis" class="${modus === 'webuntis' ? '' : 'inaktiv'}">WebUntis</button>
                <button type="button" id="tab-lokal"    class="${modus === 'lokal' ? '' : 'inaktiv'}">Lokales Konto</button>
            </div>
            <div id="login-formular"></div>
        </div>
    </div></div>`;

    document.getElementById('tab-webuntis').onclick = () => zeigeLogin('webuntis');
    document.getElementById('tab-lokal').onclick    = () => zeigeLogin('lokal');

    const f = document.getElementById('login-formular');
    if (modus === 'webuntis') {
        f.innerHTML = `
            <label for="l-benutzer">WebUntis-Benutzername</label>
            <input id="l-benutzer" autocomplete="username">
            <label for="l-passwort">Passwort</label>
            <input id="l-passwort" type="password" autocomplete="current-password">
            <p><button type="button" id="l-los">Anmelden</button></p>`;
    } else {
        f.innerHTML = `
            <label for="l-email">E-Mail</label>
            <input id="l-email" type="email" autocomplete="username">
            <label for="l-passwort">Passwort</label>
            <input id="l-passwort" type="password" autocomplete="current-password">
            <p><button type="button" id="l-los">Anmelden</button></p>`;
    }
    const los = document.getElementById('l-los');
    f.addEventListener('keydown', e => { if (e.key === 'Enter') los.click(); });
    los.onclick = async () => {
        los.disabled = true;
        try {
            const body = modus === 'webuntis'
                ? { quelle: 'webuntis',
                    benutzername: document.getElementById('l-benutzer').value.trim(),
                    passwort: document.getElementById('l-passwort').value }
                : { quelle: 'lokal',
                    email: document.getElementById('l-email').value.trim(),
                    passwort: document.getElementById('l-passwort').value };
            me = await api('/auth/login', { method: 'POST', body });
            zeigeKopf();
            location.hash = '';
            route();
        } catch (e) {
            meldung(e.message, true);
            los.disabled = false;
        }
    };
}

// ------------------------------------------------------------
// Planungen (Liste)
// ------------------------------------------------------------
async function ansichtPlanungen() {
    const planungen = await api('/planungen');
    $ansicht.innerHTML = `
        <h1>Planungen</h1>
        <p class="untertitel">Pädagogische Tage und Konferenz-Zeiträume</p>
        <div class="karte">
            <table>
                <thead><tr><th>Titel</th><th>Art</th><th>Schuljahr</th><th>Slots</th>
                <th>Konferenzen</th><th>Status</th><th></th></tr></thead>
                <tbody>${planungen.map(p => `
                    <tr>
                        <td><a href="#/planung/${p.id}">${q(p.titel)}</a></td>
                        <td>${p.typ === 'paed_tag' ? 'Pädagogischer Tag' : 'Zeitraum'}</td>
                        <td>${q(p.schuljahr)}</td>
                        <td>${p.slot_anzahl}</td>
                        <td>${p.konferenz_anzahl}</td>
                        <td><span class="status-marke ${p.status}">${p.status === 'veroeffentlicht' ? 'veröffentlicht' : 'Entwurf'}</span></td>
                        <td><button type="button" class="klein gefahr" data-loeschen="${p.id}">Löschen</button></td>
                    </tr>`).join('') ||
                    '<tr><td colspan="7" class="leer">Noch keine Planung angelegt.</td></tr>'}
                </tbody>
            </table>
        </div>
        <div class="karte">
            <h2 style="margin-top:0">Neue Planung anlegen</h2>
            <div class="raster zweispaltig">
                <div>
                    <label for="p-titel">Titel</label>
                    <input id="p-titel" placeholder="z. B. Fachkonferenzen 1. Halbjahr 2026/27">
                    <label for="p-schuljahr">Schuljahr</label>
                    <input id="p-schuljahr" placeholder="2026/27">
                </div>
                <div>
                    <label for="p-typ">Art</label>
                    <select id="p-typ">
                        <option value="zeitraum">Zeitraum (Termine über mehrere Wochen)</option>
                        <option value="paed_tag">Pädagogischer Tag (Schienen an einem Tag)</option>
                    </select>
                    <p><button type="button" id="p-anlegen">Anlegen</button></p>
                </div>
            </div>
        </div>`;

    document.getElementById('p-anlegen').onclick = async () => {
        try {
            const neu = await api('/planungen', { method: 'POST', body: {
                titel: document.getElementById('p-titel').value.trim(),
                schuljahr: document.getElementById('p-schuljahr').value.trim(),
                typ: document.getElementById('p-typ').value,
            }});
            location.hash = '#/planung/' + neu.id;
        } catch (e) { meldung(e.message, true); }
    };
    $ansicht.querySelectorAll('[data-loeschen]').forEach(b => b.onclick = async () => {
        if (!confirm('Planung samt Slots und Zuweisungen löschen?')) return;
        await api('/planungen/' + b.dataset.loeschen, { method: 'DELETE' });
        ansichtPlanungen();
    });
}

// ------------------------------------------------------------
// Planung (Detail): Slots, Konferenzen, Konflikte, Berechnung
// ------------------------------------------------------------
async function ansichtPlanung(id) {
    let p, raeume;
    try {
        [p, raeume] = await Promise.all([api('/planungen/' + id), api('/raeume')]);
    } catch (e) { meldung(e.message, true); return; }

    const konflikteVon = {};   // konferenz_id -> [Konflikttexte]
    p.konflikte.forEach(k => {
        (konflikteVon[k.konferenz_a.id] = konflikteVon[k.konferenz_a.id] || [])
            .push(`${k.konferenz_b.name} (${k.lehrer.join(', ')})`);
        (konflikteVon[k.konferenz_b.id] = konflikteVon[k.konferenz_b.id] || [])
            .push(`${k.konferenz_a.name} (${k.lehrer.join(', ')})`);
    });

    const slotAuswahl = (aktuell) => `
        <select class="klein" data-feld="slot_id">
            <option value="">– kein Slot –</option>
            ${p.slots.map(s => `<option value="${s.id}" ${String(aktuell) === String(s.id) ? 'selected' : ''}>
                ${datumDE(s.datum)} ${zeitKurz(s.beginn)} ${q(s.bezeichnung)}</option>`).join('')}
        </select>`;
    const raumAuswahl = (aktuell) => `
        <select class="klein" data-feld="raum_id">
            <option value="">– Raum –</option>
            ${raeume.filter(r => Number(r.aktiv) === 1).map(r =>
                `<option value="${r.id}" ${String(aktuell) === String(r.id) ? 'selected' : ''}>${q(r.kuerzel)}</option>`).join('')}
        </select>`;

    const chip = (k) => {
        const konflikt = konflikteVon[k.id];
        return `<span class="chip ${konflikt ? 'konflikt' : ''}"
                      title="${q((k.lehrer || []).join(', ') || 'keine Lehrer zugeordnet')}${konflikt ? '\nKONFLIKT mit: ' + q(konflikt.join(' | ')) : ''}"
                      data-konferenz="${k.id}">
                    <strong>${q(k.einheit_name)}</strong>
                    <span class="raum">${(k.lehrer || []).length} LK</span>
                    ${slotAuswahl(k.slot_id)} ${raumAuswahl(k.raum_id)}
                    <button type="button" data-entfernen="${k.id}" title="Konferenz entfernen">×</button>
                </span>`;
    };

    const ohneSlot = p.konferenzen.filter(k => k.slot_id === null);
    const istVeroeffentlicht = p.status === 'veroeffentlicht';

    $ansicht.innerHTML = `
        <p><a href="#/planungen">← Alle Planungen</a></p>
        <h1>${q(p.titel)}
            <span class="status-marke ${p.status}">${istVeroeffentlicht ? 'veröffentlicht' : 'Entwurf'}</span></h1>
        <p class="untertitel">${p.typ === 'paed_tag' ? 'Pädagogischer Tag' : 'Zeitraum'}
            ${p.schuljahr ? '· ' + q(p.schuljahr) : ''}</p>

        ${p.konflikte.length
            ? `<div class="konflikt-kasten"><strong>${p.konflikte.length} Konflikt(e):</strong><br>
               ${p.konflikte.map(k =>
                   `${q(k.konferenz_a.name)} ↔ ${q(k.konferenz_b.name)} – gemeinsam: ${q(k.lehrer.join(', '))}`
               ).join('<br>')}</div>`
            : (p.konferenzen.some(k => k.slot_id !== null)
                ? '<div class="ok-kasten">Keine Konflikte im aktuellen Plan.</div>' : '')}

        <div class="karte">
            <h2 style="margin-top:0">Automatische Berechnung</h2>
            <p>
                <label style="display:inline"><input type="checkbox" id="b-raeume" style="width:auto"> Räume mit vorschlagen</label>
            </p>
            <p>
                <button type="button" id="b-ungeplante">Nur ungeplante Konferenzen einplanen</button>
                <button type="button" id="b-alles" class="sekundaer">Alles neu berechnen</button>
            </p>
            <p>
                <button type="button" id="b-status" class="sekundaer">
                    ${istVeroeffentlicht ? 'Zurück auf Entwurf setzen' : 'Veröffentlichen (für Lehrkräfte sichtbar)'}</button>
                ${istVeroeffentlicht
                    ? `<a href="/api/ical/planung/${p.id}.ics">Kalender-Datei (.ics) der Planung</a>` : ''}
            </p>
        </div>

        <div class="karte">
            <h2 style="margin-top:0">Minimalplan <span class="leer">– so wenige Slots wie möglich</span></h2>
            <p class="untertitel">Berechnet die kleinste Slot-Anzahl, mit der alle Lehrkräfte an all ihren
            Konferenzen teilnehmen können, legt genau diese Slots an und weist konfliktfrei zu.
            <strong>Ersetzt vorhandene Slots und Zuweisungen dieser Planung.</strong></p>
            ${p.typ === 'paed_tag' ? `
            <div class="raster zweispaltig"><div>
                <label>Datum des pädagogischen Tags</label><input type="date" id="mp-datum">
                <label>Beginn der ersten Schiene</label><input type="time" id="mp-start" value="13:00">
            </div><div>
                <label>Dauer je Schiene (Minuten)</label><input type="number" id="mp-dauer" value="90" min="15">
                <label>Pause zwischen Schienen (Minuten)</label><input type="number" id="mp-pause" value="15" min="0">
            </div></div>` : `
            <label>Termin-Kandidaten (einer je Zeile: <code>JJJJ-MM-TT;HH:MM;HH:MM;Bezeichnung</code> –
                gern mehr als nötig, es werden die ersten k verwendet)</label>
            <textarea id="mp-kandidaten" rows="5" placeholder="2026-10-06;15:00;16:30
2026-10-13;15:00;16:30
2026-10-27;15:00;16:30
2026-11-03;15:00;16:30
2026-11-10;15:00;16:30"></textarea>`}
            <p><label style="display:inline"><input type="checkbox" id="mp-raeume" style="width:auto"> Räume mit vorschlagen</label></p>
            <p><button type="button" id="mp-los">Minimalplan erzeugen</button></p>
        </div>

        <h2>Zeitslots</h2>
        ${p.slots.map(s => `
            <div class="slot-block">
                <div class="slot-kopf">
                    <span>${datumDE(s.datum)}</span>
                    <span class="zeit">${zeitKurz(s.beginn)}–${zeitKurz(s.ende)} Uhr</span>
                    <span>${q(s.bezeichnung)}</span>
                    <span class="werkzeuge">
                        <button type="button" class="klein sekundaer" data-slot-loeschen="${s.id}"
                                style="border-color:rgba(252,253,249,.5);color:var(--kreide)">Slot löschen</button>
                    </span>
                </div>
                <div class="slot-inhalt">
                    ${p.konferenzen.filter(k => String(k.slot_id) === String(s.id)).map(chip).join('')
                      || '<span class="leer">Noch keine Konferenz in diesem Slot.</span>'}
                </div>
            </div>`).join('') || '<p class="leer">Noch keine Slots angelegt.</p>'}

        <div class="karte">
            <h2 style="margin-top:0">Neuen Slot anlegen</h2>
            <div class="raster zweispaltig"><div>
                <label>Datum</label><input type="date" id="s-datum">
                <label>Bezeichnung (optional)</label><input id="s-bez" placeholder="z. B. Schiene 1">
            </div><div>
                <label>Beginn</label><input type="time" id="s-beginn" value="15:00">
                <label>Ende</label><input type="time" id="s-ende" value="16:30">
                <p><button type="button" id="s-anlegen">Slot anlegen</button></p>
            </div></div>
        </div>

        <h2>Noch nicht eingeplant (${ohneSlot.length})</h2>
        <div class="karte">
            <div class="slot-inhalt" style="padding:0">
                ${ohneSlot.map(chip).join('') || '<span class="leer">Alle Konferenzen sind eingeplant.</span>'}
            </div>
            <p style="margin-bottom:0">
                <button type="button" id="k-alle" class="sekundaer">Alle Fächer &amp; Gruppen als Konferenzen anlegen</button>
            </p>
        </div>`;

    // --- Aktionen verdrahten ---
    const neuLaden = () => ansichtPlanung(id);

    document.getElementById('s-anlegen').onclick = async () => {
        try {
            await api(`/planungen/${id}/slots`, { method: 'POST', body: {
                datum: document.getElementById('s-datum').value,
                beginn: document.getElementById('s-beginn').value,
                ende: document.getElementById('s-ende').value,
                bezeichnung: document.getElementById('s-bez').value.trim(),
            }});
            neuLaden();
        } catch (e) { meldung(e.message, true); }
    };
    $ansicht.querySelectorAll('[data-slot-loeschen]').forEach(b => b.onclick = async () => {
        if (!confirm('Slot löschen? Zugewiesene Konferenzen werden wieder ungeplant.')) return;
        await api(`/planungen/${id}/slots/${b.dataset.slotLoeschen}`, { method: 'DELETE' });
        neuLaden();
    });

    document.getElementById('k-alle').onclick = async () => {
        const r = await api(`/planungen/${id}/konferenzen`, { method: 'POST', body: { alle_faecher: true } });
        meldung(`${r.angelegt} Konferenz(en) angelegt`);
        neuLaden();
    };
    $ansicht.querySelectorAll('[data-entfernen]').forEach(b => b.onclick = async () => {
        await api(`/planungen/${id}/konferenzen/${b.dataset.entfernen}`, { method: 'DELETE' });
        neuLaden();
    });
    $ansicht.querySelectorAll('.chip select').forEach(sel => sel.onchange = async () => {
        const kid = sel.closest('.chip').dataset.konferenz;
        const feld = sel.dataset.feld;
        try {
            await api(`/planungen/${id}/konferenzen/${kid}`, { method: 'PATCH',
                body: { [feld]: sel.value === '' ? null : parseInt(sel.value, 10) } });
            neuLaden();
        } catch (e) { meldung(e.message, true); }
    });

    const berechnen = async (allesNeu) => {
        try {
            const r = await api(`/planungen/${id}/berechnen`, { method: 'POST', body: {
                alles_neu: allesNeu,
                raeume_vorschlagen: document.getElementById('b-raeume').checked,
            }});
            let text = `${r.zugewiesen} Konferenz(en) eingeplant`;
            if (r.fixiert) text += `, ${r.fixiert} blieben fixiert`;
            if (r.unplatzierbar.length) text += ` – ${r.unplatzierbar.length} NICHT platzierbar!`;
            meldung(text, r.unplatzierbar.length > 0);
            if (r.unplatzierbar.length) {
                alert('Nicht platzierbar:\n' + r.unplatzierbar.map(u => `• ${u.name}: ${u.grund}`).join('\n')
                    + '\n\nTipp: weitere Slots anlegen.');
            }
            neuLaden();
        } catch (e) { meldung(e.message, true); }
    };
    document.getElementById('b-ungeplante').onclick = () => berechnen(false);
    document.getElementById('b-alles').onclick = () => {
        if (confirm('Alle bisherigen Zuweisungen verwerfen und neu berechnen?')) berechnen(true);
    };
    document.getElementById('mp-los').onclick = async () => {
        if (!confirm('Minimalplan erzeugen? Vorhandene Slots und Zuweisungen dieser Planung werden ersetzt.')) return;
        const body = { raeume_vorschlagen: document.getElementById('mp-raeume').checked };
        if (p.typ === 'paed_tag') {
            body.datum = document.getElementById('mp-datum').value;
            body.startzeit = document.getElementById('mp-start').value;
            body.dauer_min = parseInt(document.getElementById('mp-dauer').value, 10);
            body.pause_min = parseInt(document.getElementById('mp-pause').value, 10);
        } else {
            body.kandidaten = document.getElementById('mp-kandidaten').value
                .split('\n').map(z => z.trim()).filter(z => z !== '')
                .map(z => { const t = z.split(';').map(x => x.trim());
                    return { datum: t[0], beginn: t[1], ende: t[2], bezeichnung: t[3] || '' }; });
        }
        try {
            const r = await api(`/planungen/${id}/minimalplan`, { method: 'POST', body });
            meldung(`Minimum: ${r.k} Slots ${r.exakt ? '(bewiesen minimal)' : '(Heuristik)'} – ${r.zugewiesen} Konferenzen zugewiesen`);
            if (r.clique.length > 1) {
                alert(`Minimale Slot-Anzahl: ${r.k}` + (r.exakt ? ' (bewiesen)' : '') +
                    `\n\nBegründung – diese ${r.clique.length} Konferenzen kollidieren paarweise\n` +
                    `und brauchen daher zwingend eigene Slots:\n• ` + r.clique.join('\n• '));
            }
            neuLaden();
        } catch (e) { meldung(e.message, true); }
    };

    document.getElementById('b-status').onclick = async () => {
        await api('/planungen/' + id, { method: 'PATCH',
            body: { status: istVeroeffentlicht ? 'entwurf' : 'veroeffentlicht' } });
        neuLaden();
    };
}

// ------------------------------------------------------------
// Stammdaten (Admin)
// ------------------------------------------------------------
async function ansichtStammdaten() {
    $ansicht.innerHTML = `
        <h1>Stammdaten</h1>
        <p class="untertitel">Quelle: WebUntis-Sync · Ergänzungen manuell oder per CSV</p>
        <div class="tab-leiste">
            ${['pruefung:Prüfung', 'faecher:Fächer', 'gruppen:Fachgruppen', 'lehrer:Lehrkräfte', 'raeume:Räume', 'zuordnungen:Zuordnungen']
                .map(t => { const [k, n] = t.split(':');
                    return `<button type="button" data-tab="${k}" class="${stammdatenTab === k ? 'aktiv' : ''}">${n}</button>`;
                }).join('')}
        </div>
        <div id="tab-inhalt"></div>`;
    $ansicht.querySelectorAll('[data-tab]').forEach(b => b.onclick = () => {
        stammdatenTab = b.dataset.tab;
        ansichtStammdaten();
    });
    const ziel = document.getElementById('tab-inhalt');
    if (stammdatenTab === 'pruefung')     return tabPruefung(ziel);
    if (stammdatenTab === 'faecher')      return tabFaecher(ziel);
    if (stammdatenTab === 'gruppen')      return tabGruppen(ziel);
    if (stammdatenTab === 'lehrer')       return tabLehrer(ziel);
    if (stammdatenTab === 'raeume')       return tabRaeume(ziel);
    if (stammdatenTab === 'zuordnungen')  return tabZuordnungen(ziel);
}

async function tabPruefung(ziel, suche = '') {
    const [daten, faecher] = await Promise.all([api('/pruefung'), api('/faecher')]);
    const s = suche.trim().toLowerCase();

    // Warn-Schwelle relativ zur größten Einheit der Lehrkraft:
    // auffällig, wenn <= 2 Std. ODER weniger als 1/6 des Maximums
    const istWarn = (e, maxStd) => e.stunden !== null
        && (e.stunden <= 2 || (maxStd > 0 && e.stunden * 6 < maxStd));
    const bewerte = (l) => {
        const hinweise = [];
        const maxStd = Math.max(0, ...l.einheiten.map(e => e.stunden ?? 0));
        if (l.einheiten.length > 3) hinweise.push(`${l.einheiten.length} Konferenzen`);
        l.einheiten.forEach(e => {
            if (istWarn(e, maxStd)) hinweise.push(`${e.name}: nur ${e.stunden} Std. – Vertretung?`);
        });
        l.maxStd = maxStd;
        return hinweise;
    };
    const zeilen = daten
        .map(l => ({ ...l, hinweise: bewerte(l) }))
        .filter(l => s === '' || l.kuerzel.toLowerCase().includes(s) || l.name.toLowerCase().includes(s))
        .sort((a, b) => (b.hinweise.length - a.hinweise.length) || a.kuerzel.localeCompare(b.kuerzel));
    const auffaellig = zeilen.filter(l => l.hinweise.length).length;
    const stundenFehlen = daten.every(l => l.einheiten.every(e => e.stunden === null));

    ziel.innerHTML = `
        ${stundenFehlen ? `<div class="hinweis-kasten">Noch keine Stundenzahlen vorhanden –
            einmal <a href="#/sync">WebUntis-Sync</a> (Vorschau → Übernehmen) ausführen,
            dann zeigt jede Konferenz die Unterrichtsstunden im Zeitraum an.</div>` : ''}
        <div class="karte">
            <div style="display:flex;gap:.8rem;align-items:center;flex-wrap:wrap;margin-bottom:.8rem">
                <input id="pr-suche" class="klein" style="min-width:220px"
                       placeholder="Lehrkraft suchen …" value="${q(suche)}">
                <span class="leer">${zeilen.length} Lehrkräfte, ${auffaellig} auffällig ·
                    <span class="chip warn" style="padding:.1rem .5rem">⚠ wenig Stunden</span> = Vertretungsverdacht,
                    × schließt die Zuordnung dauerhaft aus</span>
            </div>
            <table>
            <thead><tr><th>Lehrkraft</th><th>Konferenzen (mit Stunden im Sync-Zeitraum)</th><th>Hinweise</th></tr></thead>
            <tbody>${zeilen.map(l => `
                <tr>
                    <td><strong>${q(l.kuerzel)}</strong> <span class="leer">${q(l.name)}</span></td>
                    <td>${l.einheiten.map(e => {
                        const warn = istWarn(e, l.maxStd);
                        const std = e.stunden === null
                            ? (e.quellen.includes('webuntis') ? '–' : e.quellen.join('/'))
                            : e.stunden + ' Std.';
                        return `<span class="chip ${warn ? 'warn' : ''}"
                                      title="Quelle: ${q(e.quellen.join(', '))}">
                                    ${warn ? '⚠ ' : ''}${q(e.name)} <span class="raum">${q(std)}</span>
                                    <button type="button" data-ausschluss='${JSON.stringify(e.ids)}'
                                            data-text="${q(l.kuerzel)} – ${q(e.name)}"
                                            title="Zuordnung ausschließen (zählt dann nirgends mehr mit)">×</button>
                                </span>`;
                    }).join(' ')}
                        <select class="klein" data-fach-neu="${l.lehrer_id}" title="Fach manuell ergänzen (sync-geschützt)">
                            <option value="">+ Fach…</option>
                            ${faecher.filter(f => Number(f.aktiv) === 1).map(f =>
                                `<option value="${f.id}">${q(f.kuerzel)} – ${q(f.gruppe_name || f.name)}</option>`).join('')}
                        </select></td>
                    <td class="${l.hinweise.length ? '' : 'leer'}">${l.hinweise.map(q).join('<br>') || '–'}</td>
                </tr>`).join('') || '<tr><td colspan="3" class="leer">Keine Daten – zuerst Sync ausführen.</td></tr>'}
            </tbody></table>
        </div>`;

    let tippTimer;
    document.getElementById('pr-suche').oninput = () => {
        clearTimeout(tippTimer);
        tippTimer = setTimeout(() => tabPruefung(ziel, document.getElementById('pr-suche').value), 250);
    };
    ziel.querySelectorAll('[data-fach-neu]').forEach(sel => sel.onchange = async () => {
        if (sel.value === '') return;
        await api('/lehrer-fach', { method: 'POST', body: {
            lehrer_id: parseInt(sel.dataset.fachNeu, 10),
            fach_id: parseInt(sel.value, 10) } });
        meldung('Zuordnung ergänzt (Quelle: manuell, sync-geschützt)');
        tabPruefung(ziel, document.getElementById('pr-suche').value);
    });
    ziel.querySelectorAll('[data-ausschluss]').forEach(b => b.onclick = async () => {
        if (!confirm(`„${b.dataset.text}" ausschließen? Die Zuordnung zählt dann nirgends mehr mit;\nder Sync legt sie nicht erneut an. Rückgängig: Tab Zuordnungen.`)) return;
        for (const id of JSON.parse(b.dataset.ausschluss)) {
            await api('/lehrer-fach/' + id, { method: 'PATCH', body: { ausgeschlossen: 1 } });
        }
        meldung('Ausgeschlossen');
        tabPruefung(ziel, document.getElementById('pr-suche').value);
    });
}

async function tabFaecher(ziel, suche = '', nurAktive = false) {
    const [faecher, gruppen, vorgaben, archiv] = await Promise.all(
        [api('/faecher'), api('/fachgruppen'), api('/fach-vorgaben'), api('/konfig-archiv')]);
    const s = suche.trim().toLowerCase();
    const gefiltert = faecher.filter(f =>
        (!nurAktive || Number(f.aktiv) === 1) &&
        (s === '' || f.kuerzel.toLowerCase().includes(s) || f.name.toLowerCase().includes(s)));

    ziel.innerHTML = `<div class="karte">
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-bottom:.8rem">
            <input id="f-suche" class="klein" style="min-width:220px" placeholder="Suchen (Kürzel oder Name) …" value="${q(suche)}">
            <label style="display:inline;margin:0"><input type="checkbox" id="f-nuraktive" style="width:auto" ${nurAktive ? 'checked' : ''}> nur aktive</label>
            <span style="flex:1"></span>
            <button type="button" class="klein sekundaer" id="f-bulk-an">Alle ${gefiltert.length} gefilterten aktivieren</button>
            <button type="button" class="klein sekundaer" id="f-bulk-aus">… deaktivieren</button>
        </div>
        <table>
        <thead><tr><th>Kürzel</th><th>Name</th><th>Fachgruppe</th><th>Aktiv</th></tr></thead>
        <tbody>${gefiltert.map(f => `
            <tr data-id="${f.id}">
                <td>${q(f.kuerzel)}</td><td>${q(f.name)}</td>
                <td><select class="klein" data-feld="gruppe_id">
                    <option value="">– eigene Konferenz –</option>
                    ${gruppen.map(g => `<option value="${g.id}" ${String(f.gruppe_id) === String(g.id) ? 'selected' : ''}>${q(g.name)}</option>`).join('')}
                </select></td>
                <td><input type="checkbox" style="width:auto" data-feld="aktiv" ${Number(f.aktiv) === 1 ? 'checked' : ''}></td>
            </tr>`).join('') || '<tr><td colspan="4" class="leer">Keine Treffer.</td></tr>'}
        </tbody></table>
        <p class="untertitel" style="margin-bottom:0">Nur <strong>aktive</strong> Fächer werden bei „Alle Fächer &amp; Gruppen anlegen"
        zu Konferenzen. Fächer in derselben Fachgruppe tagen als <em>eine</em> Konferenz. Empfohlener Ablauf nach dem
        ersten Sync: alles deaktivieren, dann die Fachschafts-Fächer per Suche aktivieren –
        oder unten die <strong>Vorgaben</strong> nutzen.</p></div>

        <div class="karte"><details id="v-details" ${vorgabenOffen ? 'open' : ''}>
        <summary style="cursor:pointer"><strong>Vorgaben &amp; Archiv</strong>
            <span class="leer">(${vorgaben.length} Regeln, ${archiv.length} Schnappschüsse)</span></summary>
        <p class="untertitel">Vorgaben sind das dauerhafte Regelwerk (Kürzel oder Präfix-Muster wie <code>LZ*</code>).
        Sie werden auf Knopfdruck und automatisch auf neue Fächer nach jedem Sync angewendet.
        Vor jeder Änderung wird ein Schnappschuss gesichert.</p>
        <p>
            <button type="button" id="v-standard" class="sekundaer">Standard-Vorbelegung (FRG) laden &amp; anwenden</button>
            <button type="button" id="v-anwenden" class="sekundaer">Vorgaben jetzt anwenden</button>
            <button type="button" id="v-sichern" class="sekundaer" title="Schreibt den aktuellen Stand als exakte Vorgaben fest">Aktuellen Stand als Vorgaben sichern</button>
            <a href="/api/faecher-konfig.csv" download>Konfiguration als CSV exportieren</a>
        </p>
        <div class="raster zweispaltig">
            <div>
                <h2 style="margin-top:0">Vorgaben (Regelwerk)</h2>
                <table><thead><tr><th>Kürzel/Muster</th><th>Aktiv</th><th>Fachgruppe</th><th></th></tr></thead>
                <tbody>${vorgaben.map(v => `
                    <tr><td>${q(v.kuerzel)}</td><td>${Number(v.aktiv) === 1 ? 'ja' : 'nein'}</td>
                        <td>${q(v.fachgruppe || '–')}</td>
                        <td><button type="button" class="klein gefahr" data-v-loeschen="${v.id}">×</button></td></tr>`).join('')
                    || '<tr><td colspan="4" class="leer">Noch keine Vorgaben.</td></tr>'}
                </tbody></table>
                <label>CSV importieren (Format: <code>Fachkürzel;aktiv;Fachgruppe</code>)</label>
                <textarea id="v-csv" rows="4" placeholder="LI G1;1;Litauisch&#10;Schw;1;Sport&#10;LZ*;0;"></textarea>
                <p><button type="button" id="v-import">Importieren &amp; anwenden</button></p>
            </div>
            <div>
                <h2 style="margin-top:0">Schnappschüsse</h2>
                <table><thead><tr><th>Zeitpunkt</th><th>Anlass</th><th></th></tr></thead>
                <tbody>${archiv.map(a => `
                    <tr><td>${q(a.zeitpunkt)}</td><td>${q(a.grund)} <span class="leer">${q(a.kuerzel)}</span></td>
                        <td><button type="button" class="klein sekundaer" data-a-restore="${a.id}">Wiederherstellen</button></td></tr>`).join('')
                    || '<tr><td colspan="3" class="leer">Noch keine Schnappschüsse.</td></tr>'}
                </tbody></table>
            </div>
        </div>
        </details></div>`;

    document.getElementById('v-details').addEventListener('toggle',
        e => { vorgabenOffen = e.target.open; });

    const vorgabenMeldung = (r) => meldung(
        `${r.geaendert ?? 0} Fächer geändert, ${r.gruppen_neu ?? 0} Gruppen neu, ${r.ohne_vorgabe ?? 0} ohne Vorgabe`);
    document.getElementById('v-standard').onclick = async () => {
        if (!confirm('Standard-Regelwerk laden und auf alle Fächer anwenden? (Schnappschuss wird vorher gesichert)')) return;
        vorgabenMeldung(await api('/fach-vorgaben/standard', { method: 'POST', body: {} }));
        neuZeichnen();
    };
    document.getElementById('v-anwenden').onclick = async () => {
        vorgabenMeldung(await api('/fach-vorgaben/anwenden', { method: 'POST', body: {} }));
        neuZeichnen();
    };
    document.getElementById('v-sichern').onclick = async () => {
        const r = await api('/fach-vorgaben/sichern', { method: 'POST', body: {} });
        meldung(`${r.vorgaben} Vorgaben festgeschrieben`);
        neuZeichnen();
    };
    document.getElementById('v-import').onclick = async () => {
        const r = await api('/fach-vorgaben/import', { method: 'POST',
            body: { csv: document.getElementById('v-csv').value } });
        if ((r.uebersprungen || []).length) alert('Übersprungen:\n' + r.uebersprungen.join('\n'));
        vorgabenMeldung(r);
        neuZeichnen();
    };
    ziel.querySelectorAll('[data-v-loeschen]').forEach(b => b.onclick = async () => {
        await api('/fach-vorgaben/' + b.dataset.vLoeschen, { method: 'DELETE' });
        neuZeichnen();
    });
    ziel.querySelectorAll('[data-a-restore]').forEach(b => b.onclick = async () => {
        if (!confirm('Diesen Schnappschuss wiederherstellen? (Der aktuelle Stand wird vorher gesichert)')) return;
        const r = await api('/konfig-archiv/' + b.dataset.aRestore + '/wiederherstellen', { method: 'POST', body: {} });
        meldung(`${r.wiederhergestellt} Fächer wiederhergestellt`);
        neuZeichnen();
    });

    const neuZeichnen = () => tabFaecher(ziel,
        document.getElementById('f-suche').value,
        document.getElementById('f-nuraktive').checked);
    let tippTimer;
    document.getElementById('f-suche').oninput = () => {
        clearTimeout(tippTimer); tippTimer = setTimeout(neuZeichnen, 250);
    };
    document.getElementById('f-nuraktive').onchange = neuZeichnen;

    const bulk = async (aktiv) => {
        if (!gefiltert.length) return;
        if (!confirm(`${gefiltert.length} Fächer ${aktiv ? 'aktivieren' : 'deaktivieren'}?`)) return;
        await api('/faecher/bulk-aktiv', { method: 'POST',
            body: { ids: gefiltert.map(f => f.id), aktiv } });
        meldung('Gespeichert');
        neuZeichnen();
    };
    document.getElementById('f-bulk-an').onclick  = () => bulk(1);
    document.getElementById('f-bulk-aus').onclick = () => bulk(0);

    ziel.querySelectorAll('select,[type=checkbox][data-feld]').forEach(el => el.onchange = async () => {
        const id = el.closest('tr').dataset.id;
        const wert = el.type === 'checkbox' ? (el.checked ? 1 : 0)
            : (el.value === '' ? null : parseInt(el.value, 10));
        await api('/faecher/' + id, { method: 'PATCH', body: { [el.dataset.feld]: wert } });
        meldung('Gespeichert');
    });
}

async function tabGruppen(ziel) {
    const gruppen = await api('/fachgruppen');
    ziel.innerHTML = `<div class="karte">
        <table><thead><tr><th>Name</th><th></th></tr></thead>
        <tbody>${gruppen.map(g => `
            <tr><td>${q(g.name)}</td>
                <td><button type="button" class="klein gefahr" data-id="${g.id}">Löschen</button></td></tr>`).join('')
            || '<tr><td colspan="2" class="leer">Keine Fachgruppen.</td></tr>'}
        </tbody></table>
        <label for="g-name">Neue Fachgruppe</label>
        <div style="display:flex;gap:.5rem">
            <input id="g-name" placeholder="z. B. Religion / Praktische Philosophie">
            <button type="button" id="g-anlegen">Anlegen</button>
        </div></div>`;
    document.getElementById('g-anlegen').onclick = async () => {
        await api('/fachgruppen', { method: 'POST', body: { name: document.getElementById('g-name').value.trim() } });
        tabGruppen(ziel);
    };
    ziel.querySelectorAll('[data-id]').forEach(b => b.onclick = async () => {
        if (!confirm('Fachgruppe löschen? Die Fächer bleiben erhalten (wieder eigene Konferenz).')) return;
        await api('/fachgruppen/' + b.dataset.id, { method: 'DELETE' });
        tabGruppen(ziel);
    });
}

async function tabLehrer(ziel, suche = '') {
    const lehrer = await api('/lehrer');
    const s = suche.trim().toLowerCase();
    const gefiltert = lehrer.filter(l => s === ''
        || l.kuerzel.toLowerCase().includes(s)
        || (l.vorname + ' ' + l.nachname).toLowerCase().includes(s));

    ziel.innerHTML = `<div class="karte">
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-bottom:.8rem">
            <input id="l-suche" class="klein" style="min-width:220px" placeholder="Suchen (Kürzel oder Name) …" value="${q(suche)}">
            <span style="flex:1"></span>
            <button type="button" class="klein sekundaer" id="l-bulk-an">Alle ${gefiltert.length} gefilterten aktivieren</button>
            <button type="button" class="klein sekundaer" id="l-bulk-aus">… deaktivieren</button>
        </div>
        <table>
        <thead><tr><th>Kürzel</th><th>Vorname</th><th>Nachname</th><th>WebUntis-ID</th><th>Aktiv</th></tr></thead>
        <tbody>${gefiltert.map(l => `
            <tr data-id="${l.id}">
                <td>${q(l.kuerzel)}</td><td>${q(l.vorname)}</td><td>${q(l.nachname)}</td>
                <td>${l.webuntis_id ?? '<span class="leer">–</span>'}</td>
                <td><input type="checkbox" style="width:auto" data-feld="aktiv" ${Number(l.aktiv) === 1 ? 'checked' : ''}></td>
            </tr>`).join('') || '<tr><td colspan="5" class="leer">Keine Treffer.</td></tr>'}
        </tbody></table>
        <p class="untertitel" style="margin-bottom:0">Inaktive Lehrkräfte (z. B. Dummy-Konten) zählen nicht für den
        Konfliktgraphen und erzeugen keine Schein-Konflikte.</p></div>`;

    const neuZeichnen = () => tabLehrer(ziel, document.getElementById('l-suche').value);
    let tippTimer;
    document.getElementById('l-suche').oninput = () => {
        clearTimeout(tippTimer); tippTimer = setTimeout(neuZeichnen, 250);
    };
    const bulk = async (aktiv) => {
        if (!gefiltert.length) return;
        if (!confirm(`${gefiltert.length} Lehrkräfte ${aktiv ? 'aktivieren' : 'deaktivieren'}?`)) return;
        await api('/lehrer/bulk-aktiv', { method: 'POST',
            body: { ids: gefiltert.map(l => l.id), aktiv } });
        meldung('Gespeichert');
        neuZeichnen();
    };
    document.getElementById('l-bulk-an').onclick  = () => bulk(1);
    document.getElementById('l-bulk-aus').onclick = () => bulk(0);

    ziel.querySelectorAll('[type=checkbox][data-feld]').forEach(el => el.onchange = async () => {
        await api('/lehrer/' + el.closest('tr').dataset.id, { method: 'PATCH',
            body: { aktiv: el.checked ? 1 : 0 } });
        meldung('Gespeichert');
    });
}

async function tabRaeume(ziel) {
    const raeume = await api('/raeume');
    ziel.innerHTML = `<div class="karte"><table>
        <thead><tr><th>Kürzel</th><th>Name</th><th>Aktiv (für Raumvorschläge)</th></tr></thead>
        <tbody>${raeume.map(r => `
            <tr data-id="${r.id}">
                <td>${q(r.kuerzel)}</td><td>${q(r.name)}</td>
                <td><input type="checkbox" style="width:auto" ${Number(r.aktiv) === 1 ? 'checked' : ''}></td>
            </tr>`).join('') || '<tr><td colspan="3" class="leer">Noch keine Räume – WebUntis-Sync ausführen oder manuell anlegen.</td></tr>'}
        </tbody></table>
        <label>Neuen Raum anlegen (Kürzel)</label>
        <div style="display:flex;gap:.5rem">
            <input id="r-kuerzel" placeholder="z. B. A101"><button type="button" id="r-anlegen">Anlegen</button>
        </div></div>`;
    document.getElementById('r-anlegen').onclick = async () => {
        await api('/raeume', { method: 'POST', body: { kuerzel: document.getElementById('r-kuerzel').value.trim() } });
        tabRaeume(ziel);
    };
    ziel.querySelectorAll('[type=checkbox]').forEach(el => el.onchange = async () => {
        await api('/raeume/' + el.closest('tr').dataset.id, { method: 'PATCH',
            body: { aktiv: el.checked ? 1 : 0 } });
        meldung('Gespeichert');
    });
}

async function tabZuordnungen(ziel, nurAktive = true) {
    const [zuordnungen, lehrer, faecher] = await Promise.all(
        [api('/lehrer-fach'), api('/lehrer'), api('/faecher')]);
    const gefiltert = zuordnungen.filter(z => !nurAktive || Number(z.fach_aktiv) === 1);

    ziel.innerHTML = `
        <div class="karte">
            <p style="margin-top:0">
                <label style="display:inline"><input type="checkbox" id="z-nuraktive" style="width:auto"
                    ${nurAktive ? 'checked' : ''}> nur aktive Fächer zeigen</label>
                <span class="leer">(${gefiltert.length} von ${zuordnungen.length} Zuordnungen)</span>
            </p>
            <table>
            <thead><tr><th>Lehrkraft</th><th>Fach</th><th>Quelle</th>
                <th title="Gesperrte Einträge entfernt der Sync nie">Gesperrt</th>
                <th title="Zählt nirgends mit (Konflikte, Termine); Sperrvermerk gegen erneutes Anlegen durch den Sync – z. B. für Vertretungsunterricht">Ausgeschlossen</th>
                <th></th></tr></thead>
            <tbody>${gefiltert.map(z => `
                <tr data-id="${z.id}" style="${Number(z.ausgeschlossen) === 1 ? 'opacity:.5' : ''}">
                    <td>${q(z.lehrer_kuerzel)} <span class="leer">${q(z.vorname)} ${q(z.nachname)}</span></td>
                    <td>${q(z.fach_kuerzel)} – ${q(z.fach_name)}${Number(z.fach_aktiv) === 1 ? '' : ' <span class="leer">(inaktiv)</span>'}</td>
                    <td>${q(z.quelle)}</td>
                    <td><input type="checkbox" style="width:auto" data-feld="gesperrt" ${Number(z.gesperrt) === 1 ? 'checked' : ''}></td>
                    <td><input type="checkbox" style="width:auto" data-feld="ausgeschlossen" ${Number(z.ausgeschlossen) === 1 ? 'checked' : ''}></td>
                    <td><button type="button" class="klein gefahr" title="Achtung: Der nächste Sync legt die Zuordnung ggf. wieder an – für dauerhaftes Ignorieren „Ausgeschlossen" setzen">Entfernen</button></td>
                </tr>`).join('') || '<tr><td colspan="6" class="leer">Keine Zuordnungen.</td></tr>'}
            </tbody></table></div>
        <div class="raster zweispaltig">
            <div class="karte">
                <h2 style="margin-top:0">Zuordnung manuell ergänzen</h2>
                <p class="untertitel">z. B. Facultas ohne aktuellen Unterricht im Fach</p>
                <label>Lehrkraft</label>
                <select id="z-lehrer">${lehrer.map(l => `<option value="${l.id}">${q(l.kuerzel)} – ${q(l.nachname)}</option>`).join('')}</select>
                <label>Fach</label>
                <select id="z-fach">${faecher.map(f => `<option value="${f.id}">${q(f.kuerzel)} – ${q(f.name)}</option>`).join('')}</select>
                <p><button type="button" id="z-anlegen">Hinzufügen</button></p>
            </div>
            <div class="karte">
                <h2 style="margin-top:0">CSV-Import (Fallback)</h2>
                <p class="untertitel">Eine Zeile je Paar: <code>Kürzel;Fachkürzel</code></p>
                <textarea id="csv" rows="6" placeholder="Hor;M&#10;Hor;IF&#10;Mei;PH"></textarea>
                <p><button type="button" id="csv-import">Importieren</button></p>
            </div>
        </div>`;

    const neuZeichnen = () => tabZuordnungen(ziel, document.getElementById('z-nuraktive').checked);
    document.getElementById('z-nuraktive').onchange = neuZeichnen;

    document.getElementById('z-anlegen').onclick = async () => {
        await api('/lehrer-fach', { method: 'POST', body: {
            lehrer_id: parseInt(document.getElementById('z-lehrer').value, 10),
            fach_id: parseInt(document.getElementById('z-fach').value, 10) } });
        neuZeichnen();
    };
    document.getElementById('csv-import').onclick = async () => {
        const r = await api('/import/csv', { method: 'POST',
            body: { csv: document.getElementById('csv').value } });
        meldung(`${r.importiert} importiert, ${r.uebersprungen.length} übersprungen`);
        if (r.uebersprungen.length) alert('Übersprungen:\n' + r.uebersprungen.join('\n'));
        neuZeichnen();
    };
    ziel.querySelectorAll('tbody tr[data-id]').forEach(tr => {
        const id = tr.dataset.id;
        tr.querySelectorAll('[data-feld]').forEach(kasten => kasten.onchange = async () => {
            await api('/lehrer-fach/' + id, { method: 'PATCH',
                body: { [kasten.dataset.feld]: kasten.checked ? 1 : 0 } });
            meldung('Gespeichert');
            neuZeichnen();
        });
        const knopf = tr.querySelector('button');
        if (knopf) knopf.onclick = async () => {
            await api('/lehrer-fach/' + id, { method: 'DELETE' });
            neuZeichnen();
        };
    });
}

// ------------------------------------------------------------
// WebUntis-Sync (Admin)
// ------------------------------------------------------------
function ansichtSync() {
    const heute = new Date();
    const vor10Wochen = new Date(Date.now() - 70 * 86400e3);
    const iso = d => d.toISOString().slice(0, 10);
    $ansicht.innerHTML = `
        <h1>WebUntis-Sync</h1>
        <p class="untertitel">Lehrkräfte, Fächer, Räume und die Zuordnung „wer unterrichtet was" aus dem Stundenplan</p>
        <div class="hinweis-kasten">Die Zugangsdaten werden <strong>nur für diesen Abruf</strong> verwendet und
            nicht gespeichert. Als Zeitraum empfiehlt sich ein längeres Fenster (8–12 normale Unterrichtswochen):
            Je länger, desto trennschärfer das Stunden-Signal. Die Beta-API filtert
            Vertretungsstunden zusätzlich von vornherein heraus. Manuelle und gesperrte Zuordnungen bleiben immer erhalten.</div>
        <div class="karte">
            <div class="raster zweispaltig"><div>
                <label>WebUntis-Benutzername</label><input id="sy-benutzer" autocomplete="off">
                <label>Passwort</label><input id="sy-passwort" type="password" autocomplete="off">
            </div><div>
                <label>Von</label><input type="date" id="sy-von" value="${iso(vor10Wochen)}">
                <label>Bis</label><input type="date" id="sy-bis" value="${iso(heute)}">
            </div></div>
            <label>API</label>
            <p style="margin-top:.2rem">
                <label style="display:inline"><input type="radio" name="sy-api" value="rpc" checked style="width:auto"> Standard (JSON-RPC, offiziell)</label>
                &nbsp;&nbsp;
                <label style="display:inline"><input type="radio" name="sy-api" value="rest_beta" style="width:auto"> Beta: interne REST-API (experimentell)</label>
            </p>
            <p>
                <button type="button" id="sy-vorschau">Vorschau abrufen</button>
                <button type="button" id="sy-uebernehmen" class="sekundaer" disabled>Übernehmen</button>
                <button type="button" id="sy-sondierung" class="sekundaer" hidden title="Diagnose: klopft die interne REST-API ab, schreibt nichts">REST-Sondierung ausführen</button>
            </p>
        </div>
        <div id="sy-ergebnis"></div>`;

    const lauf = async (modus) => {
        const knopfV = document.getElementById('sy-vorschau');
        const knopfU = document.getElementById('sy-uebernehmen');
        knopfV.disabled = knopfU.disabled = true;
        knopfV.textContent = 'Läuft … (kann eine Minute dauern)';
        try {
            const r = await api('/sync/webuntis', { method: 'POST', body: {
                benutzername: document.getElementById('sy-benutzer').value.trim(),
                passwort: document.getElementById('sy-passwort').value,
                von: document.getElementById('sy-von').value,
                bis: document.getElementById('sy-bis').value,
                api: document.querySelector('[name=sy-api]:checked').value,
                modus,
            }});
            document.getElementById('sy-ergebnis').innerHTML = `
                <div class="karte">
                    <h2 style="margin-top:0">${modus === 'vorschau' ? 'Vorschau' : 'Übernommen'}</h2>
                    <table><tbody>
                        <tr><td>Zuordnungen laut Stundenplan</td><td>${r.zuordnungen_gesamt_webuntis}</td></tr>
                        <tr><td>Neue Zuordnungen</td><td>${r.zuordnungen_neu}</td></tr>
                        ${r.zuordnungen_unaufgeloest
                            ? `<tr><td>Zuordnungen zu noch nicht angelegten Lehrkräften/Fächern
                                   <span class="leer">(werden beim Übernehmen mit angelegt)</span></td>
                                   <td>${r.zuordnungen_unaufgeloest}</td></tr>` : ''}
                        <tr><td>Entfallende Zuordnungen (Quelle webuntis)</td><td>${r.zuordnungen_entfernt}</td></tr>
                        <tr><td>Neue Lehrkräfte / Fächer / Räume</td>
                            <td>${r.lehrer_neu} / ${r.faecher_neu} / ${r.raeume_neu}</td></tr>
                    </tbody></table>
                    ${r.fehler.length ? `<div class="konflikt-kasten">${r.fehler.map(q).join('<br>')}</div>` : ''}
                    ${(() => {
                        const d = r.duplikate || {};
                        const teile = [];
                        if ((d.faecher || []).length) teile.push('Fächer: ' + d.faecher.map(q).join(', '));
                        if ((d.lehrer  || []).length) teile.push('Lehrkräfte: ' + d.lehrer.map(q).join(', '));
                        if ((d.raeume  || []).length) teile.push('Räume: ' + d.raeume.map(q).join(', '));
                        return teile.length
                            ? `<div class="hinweis-kasten"><strong>Doppelte Kürzel in WebUntis zusammengeführt:</strong><br>${teile.join('<br>')}</div>`
                            : '';
                    })()}
                    ${modus === 'uebernehmen' && r.datenquelle === 'vorschau_zwischenspeicher'
                        ? '<p class="untertitel">Übernommen aus den Daten der Vorschau (kein erneuter WebUntis-Abruf).</p>' : ''}
                </div>`;
            if (modus === 'vorschau') {
                knopfU.disabled = false;
                meldung('Vorschau geladen – „Übernehmen" schreibt genau diese Daten (schnell, ohne zweiten Abruf)');
            } else {
                meldung('Sync übernommen');
            }
        } catch (e) {
            meldung(e.message, true);
        } finally {
            knopfV.disabled = false;
            knopfV.textContent = 'Vorschau abrufen';
        }
    };
    // Sondierung ist ein Diagnosewerkzeug für die Beta-API -> nur dann zeigen
    document.querySelectorAll('[name=sy-api]').forEach(radio => radio.onchange = () => {
        document.getElementById('sy-sondierung').hidden =
            document.querySelector('[name=sy-api]:checked').value !== 'rest_beta';
    });

    document.getElementById('sy-vorschau').onclick = () => lauf('vorschau');
    document.getElementById('sy-uebernehmen').onclick = () => lauf('uebernehmen');

    document.getElementById('sy-sondierung').onclick = async () => {
        const knopf = document.getElementById('sy-sondierung');
        knopf.disabled = true; knopf.textContent = 'Sondierung läuft …';
        try {
            const r = await api('/sync/rest-sondierung', { method: 'POST', body: {
                benutzername: document.getElementById('sy-benutzer').value.trim(),
                passwort: document.getElementById('sy-passwort').value,
            }});
            const bericht = JSON.stringify(r, null, 2);
            document.getElementById('sy-ergebnis').innerHTML = `
                <div class="karte">
                    <h2 style="margin-top:0">REST-Sondierung</h2>
                    <table><thead><tr><th>Endpunkt</th><th>Status</th><th>Paare erkannt</th><th>Details</th></tr></thead>
                    <tbody>${(r.schritte || []).map(z => `
                        <tr>
                            <td style="word-break:break-all">${q(z.pfad)}</td>
                            <td>${z.status ?? q(z.ergebnis || '')}</td>
                            <td>${z.extrahierte_paare ?? z.extrahierte_faecher ?? '–'}</td>
                            <td>${q((z.json_schluessel || []).join(', ') || (z.auszug || '').slice(0, 80))}</td>
                        </tr>`).join('')}
                    </tbody></table>
                    <p>Vollständiger Bericht (zum Kopieren für die Weiterentwicklung):</p>
                    <textarea rows="10" readonly onclick="this.select()">${q(bericht)}</textarea>
                </div>`;
            meldung('Sondierung abgeschlossen');
        } catch (e) {
            meldung(e.message, true);
        } finally {
            knopf.disabled = false; knopf.textContent = 'REST-Sondierung ausführen';
        }
    };
}

// ------------------------------------------------------------
// Meine Termine (alle Rollen)
// ------------------------------------------------------------
async function ansichtTermine() {
    const r = await api('/meine-termine');
    let icalHtml = '';
    try {
        const ical = await api('/mein-ical');
        icalHtml = `<div class="karte"><h2 style="margin-top:0">Kalender-Abo</h2>
            <p>Diese Adresse in Outlook / Apple Kalender / Google Kalender als Abonnement einfügen –
               Änderungen erscheinen dann automatisch:</p>
            <input readonly value="${q(ical.url)}" onclick="this.select()"></div>`;
    } catch (e) { /* kein Stammsatz -> kein Abo */ }

    $ansicht.innerHTML = `
        <h1>Meine Fachkonferenzen</h1>
        <p class="untertitel">Alle veröffentlichten Termine deiner Fächer</p>
        ${r.hinweis ? `<div class="hinweis-kasten">${q(r.hinweis)}</div>` : ''}
        <div class="karte"><table>
            <thead><tr><th>Datum</th><th>Zeit</th><th>Konferenz</th><th>Raum</th><th>Planung</th></tr></thead>
            <tbody>${r.termine.map(t => `
                <tr>
                    <td>${datumDE(t.datum)}</td>
                    <td>${zeitKurz(t.beginn)}–${zeitKurz(t.ende)}</td>
                    <td><strong>${q(t.konferenz)}</strong>${t.slot ? ' <span class="leer">' + q(t.slot) + '</span>' : ''}</td>
                    <td>${q(t.raum || '–')}</td>
                    <td>${q(t.planung)}</td>
                </tr>`).join('') || '<tr><td colspan="5" class="leer">Zurzeit sind keine Termine veröffentlicht.</td></tr>'}
            </tbody></table></div>
        ${icalHtml}`;
}

// ------------------------------------------------------------
start();
