/* VENDORED aus hornse/ci-css v1.5.5 – dort ändern, hierher kopieren! */
/* ============================================================
   ci-shell.js – Verhalten der Seitenleiste

   Kein Framework, kein Build-Schritt. Bindet sich an die Struktur
   aus ci-shell.css und braucht nur diese Elemente:

     [data-ci-huelle]     die Hülle, trägt den Zustand
     [data-ci-schalter]   Knopf zum Ein- und Ausklappen (breit)
     [data-ci-menue]      Knopf zum Öffnen der Schublade (schmal)
     [data-ci-abdeckung]  Fläche hinter der Schublade (optional)
     [data-ci-icons]      Pfad zum Symbol-Sprite (optional)

   Zustände: "voll", "symbole", "offen" (nur schmal).

   Barrierefreiheit: Im Symbolmodus ist das Symbol die einzige
   Beschriftung. Deshalb setzt dieses Skript aus dem Text jedes
   Navigationspunkts ein title-Attribut und, falls keines vorhanden
   ist, ein aria-label. Ohne das wäre die eingeklappte Leiste für
   Bildschirmleser unbenutzbar.

   Copyright (C) 2026 Sebastian Horn, Friedrich-Rückert-Gymnasium
   SPDX-License-Identifier: GPL-3.0-or-later
   ============================================================ */

'use strict';

(function () {

  // ============================================================
  // Symbole einbetten
  // ============================================================

  /**
   * Holt das Sprite und hängt es an den Anfang des Dokuments.
   *
   * Warum nicht einfach <use href="ci-icons.svg#ci-i-x">?
   * Das funktioniert über HTTPS zwar, hat aber zwei Haken: Über
   * file:// blockieren Chrome und Safari externe use-Verweise
   * grundsätzlich, und je Symbol entsteht ein eigener Abruf.
   * Einmal einbetten und danach lokal referenzieren ist robuster.
   *
   * Aufgerufen wird das über <div data-ci-huelle data-ci-icons="/pfad">.
   * Schlägt der Abruf fehl, läuft alles Übrige weiter - eine
   * Seitenleiste ohne Symbole ist immer noch bedienbar.
   */
  function symboleEinbetten(pfad) {
    if (!pfad || document.getElementById('ci-icons-sprite')) return;

    fetch(pfad, { cache: 'force-cache' })
      .then(function (a) {
        if (!a.ok) throw new Error('HTTP ' + a.status);
        return a.text();
      })
      .then(function (text) {
        var behaelter = document.createElement('div');
        behaelter.id = 'ci-icons-sprite';
        behaelter.setAttribute('aria-hidden', 'true');
        behaelter.style.display = 'none';
        behaelter.innerHTML = text;
        document.body.insertBefore(behaelter, document.body.firstChild);

        // Verweise von der Datei auf das eingebettete Sprite umbiegen.
        document.querySelectorAll('use[href*="ci-icons.svg#"]').forEach(function (u) {
          u.setAttribute('href', '#' + u.getAttribute('href').split('#')[1]);
        });
      })
      .catch(function (f) {
        console.warn('[ci-css] Symbole nicht ladbar:', f.message,
          '- über file:// blockieren Chrome und Safari den Abruf.');
      });
  }


  var SCHMAL = 768;          // muss zur Medienabfrage in ci-shell.css passen
  var SCHLUESSEL = 'ci-leiste';

  var huelle = document.querySelector('[data-ci-huelle]');
  if (!huelle) return;

  // Kopfleiste oder Seitenleiste? Die Kopfleiste kennt nur zwei
  // Zustaende (zu / offen) - einen Symbolstreifen gibt es dort nicht.
  var istKopf = huelle.classList.contains('ci-huelle--kopf');

  var schalter  = document.querySelector('[data-ci-schalter]');
  var menue     = document.querySelector('[data-ci-menue]');
  var abdeckung = document.querySelector('[data-ci-abdeckung]');
  var leiste    = huelle.querySelector('.ci-leiste');

  function istSchmal() { return window.innerWidth <= SCHMAL; }

  /**
   * Gemerkten Zustand lesen.
   *
   * localStorage kann fehlschlagen – im privaten Modus mancher
   * Browser wirft schon der Lesezugriff. Der Zustand einer
   * Seitenleiste ist keinen Absturz wert.
   */
  function gemerkt() {
    try { return window.localStorage.getItem(SCHLUESSEL); }
    catch (e) { return null; }
  }

  function merken(wert) {
    try { window.localStorage.setItem(SCHLUESSEL, wert); }
    catch (e) { /* Absicht: ohne Speicher läuft alles weiter */ }
  }

  function setzen(zustand, speichern) {
    huelle.setAttribute('data-leiste', zustand);

    var offen = zustand === 'offen';
    var eingeklappt = zustand === 'symbole';

    if (schalter) {
      schalter.setAttribute('aria-expanded', eingeklappt ? 'false' : 'true');
      var text = schalter.querySelector('.ci-nav-text');
      if (text) text.textContent = eingeklappt ? 'Ausklappen' : 'Einklappen';
      schalter.setAttribute('title', eingeklappt ? 'Ausklappen' : 'Einklappen');
      var bild = schalter.querySelector('use');
      if (bild) {
        bild.setAttribute('href', bild.getAttribute('href')
          .replace(/#ci-i-(links|rechts)$/, eingeklappt ? '#ci-i-rechts' : '#ci-i-links'));
      }
    }
    if (menue) menue.setAttribute('aria-expanded', offen ? 'true' : 'false');
    if (abdeckung) abdeckung.hidden = !offen;

    // Eine geschlossene Schublade darf nicht per Tabulator erreichbar
    // sein – sonst wandert der Fokus in etwas Unsichtbares.
    // Eine geschlossene Schublade darf nicht per Tabulator erreichbar
    // sein. Bei der Kopfleiste entfaellt das: Dort wird die Navigation
    // mit display:none ausgeblendet und ist dadurch ohnehin nicht
    // erreichbar - der Rest der Kopfleiste bleibt bedienbar.
    if (leiste && !istKopf) {
      if (istSchmal() && !offen) leiste.setAttribute('inert', '');
      else leiste.removeAttribute('inert');
    }

    // "offen" ist ein flüchtiger Zustand der schmalen Ansicht und
    // gehört nicht in den Speicher.
    if (speichern && !offen) merken(zustand);
  }

  /** Beschriftungen als Tooltip und, falls nötig, als aria-label. */
  function beschriftungenSetzen() {
    huelle.querySelectorAll('.ci-nav a, .ci-nav button').forEach(function (el) {
      var t = el.querySelector('.ci-nav-text');
      if (!t) return;
      var text = t.textContent.trim();
      if (text === '') return;
      if (!el.hasAttribute('title')) el.setAttribute('title', text);
      if (!el.hasAttribute('aria-label')) el.setAttribute('aria-label', text);
    });
  }

  // ---- Start ------------------------------------------------
  symboleEinbetten(huelle.getAttribute('data-ci-icons'));
  beschriftungenSetzen();

  if (istKopf) {
    // In der Kopfleiste ist die Navigation auf breiten Anzeigen immer
    // sichtbar; nur unter 768 px klappt sie unter den Kopf.
    setzen('voll', false);
  } else if (istSchmal()) {
    setzen('voll', false);          // Schublade zu, Zustand nicht merken
  } else {
    var start = gemerkt();
    // Zwischen 768 und 1024 px standardmäßig eingeklappt: Dort ist der
    // Platz knapp, aber ein Symbolstreifen noch sinnvoll.
    if (start !== 'voll' && start !== 'symbole') {
      start = window.innerWidth < 1024 ? 'symbole' : 'voll';
    }
    setzen(start, false);
  }

  if (schalter) {
    schalter.addEventListener('click', function () {
      setzen(huelle.getAttribute('data-leiste') === 'symbole' ? 'voll' : 'symbole', true);
    });
  }

  if (menue) {
    menue.addEventListener('click', function () {
      setzen(huelle.getAttribute('data-leiste') === 'offen' ? 'voll' : 'offen', false);
    });
  }

  if (abdeckung) {
    abdeckung.addEventListener('click', function () { setzen('voll', false); });
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && huelle.getAttribute('data-leiste') === 'offen') {
      setzen('voll', false);
      if (menue) menue.focus();
    }
  });

  // Ein Klick in der Schublade schließt sie – sonst bleibt sie nach
  // dem Seitenwechsel über dem Inhalt stehen.
  huelle.querySelectorAll('.ci-nav a, .ci-nav button').forEach(function (el) {
    el.addEventListener('click', function () {
      if (istSchmal()) setzen('voll', false);
    });
  });

  // Wird das Fenster über die Schwelle gezogen, passt der Zustand
  // sonst nicht mehr zur Darstellung.
  var vorherSchmal = istSchmal();
  window.addEventListener('resize', function () {
    var jetztSchmal = istSchmal();
    if (jetztSchmal === vorherSchmal) return;
    vorherSchmal = jetztSchmal;
    if (istKopf) { setzen('voll', false); return; }
    setzen(jetztSchmal ? 'voll' : (gemerkt() || 'voll'), false);
  });

})();
