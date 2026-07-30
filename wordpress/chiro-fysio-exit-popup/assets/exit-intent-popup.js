/*!
 * Chiro-Fysio exit-intent pop-up
 * -----------------------------------------------------------------------------
 * Vraagt bezoekers die de site dreigen te verlaten of ze gevonden hebben wat ze
 * zochten. Zo niet, dan tonen we het telefoonnummer en (optioneel) WhatsApp.
 *
 * Geen dependencies, geen tracking cookies. De enige opslag is een
 * localStorage-sleutel om te onthouden dat we de pop-up al getoond hebben,
 * zodat we niemand twee keer lastigvallen.
 */
(function () {
  'use strict';

  /* ==========================================================================
     INSTELLINGEN - dit is het enige blok dat je normaal hoeft aan te passen.
     ========================================================================== */
  var CONFIG = {
    // Telefoonnummer zoals de bezoeker het te zien krijgt.
    phoneDisplay: '024 - 355 88 30',

    // Zelfde nummer, maar internationaal en zonder spaties (voor de belknop).
    phoneHref: '+31243558830',

    // WhatsApp-nummer in internationaal formaat zonder + en zonder spaties,
    // bijvoorbeeld '31612345678'. Laat leeg ('') om de WhatsApp-knop te
    // verbergen - een vast 024-nummer werkt meestal niet op WhatsApp.
    whatsapp: '',

    // Tekst die alvast in het WhatsApp-bericht staat.
    whatsappText: 'Hallo, ik heb een vraag naar aanleiding van jullie website.',

    // Optionele knop "Direct een afspraak maken" in het ja-scherm.
    // Laat leeg om die knop te verbergen.
    appointmentUrl: '/uw-afspraak/',

    // Hoe lang de bezoeker minimaal op de site moet zijn voordat we de
    // pop-up scherp zetten (milliseconden). Voorkomt dat iemand die per
    // ongeluk klikt meteen een pop-up krijgt.
    //
    // Op 6 seconden gezet op basis van het Analytics-rapport van juli 2026:
    // bezoekers zijn gemiddeld 38 seconden actief en bekijken 1,98 pagina's,
    // dus grofweg 19 seconden per pagina. Met 8 seconden wachten was een
    // ruime derde van dat venster al voorbij voordat we begonnen te kijken.
    armAfterMs: 6000,

    // Wacht met scherpzetten tot de bezoeker iets gedaan heeft: bewegen,
    // scrollen, tikken of typen. Een echte bezoeker doet dat altijd binnen een
    // paar seconden; een geautomatiseerde bezoeker laadt de pagina en blijft
    // stilzitten. Dat scheelt vervuiling in het dashboard - in juli kwam 31%
    // van het verkeer uit landen zonder plausibele patiëntrelatie.
    requireInteraction: true,

    // Hoeveel dagen we iemand met rust laten nadat de pop-up getoond is.
    // Blijft op 7: van de bezoekers is 92% nieuw en vrijwel niemand komt terug,
    // dus deze grens raakt in de praktijk bijna niemand.
    cooldownDays: 7,

    // Pop-up ook op mobiel/tablet tonen? Daar bestaat geen muis, dus we
    // gebruiken een andere trigger (zie mobileIdleMs / snel omhoog scrollen).
    enableMobile: true,

    // Mobiel: na hoeveel milliseconden zonder enige interactie we de pop-up
    // tonen. Zet op 0 om deze trigger uit te schakelen.
    mobileIdleMs: 45000,

    // Pagina's waar de pop-up NOOIT moet verschijnen. Een term hoeft maar
    // ergens in het pad voor te komen, dus 'afspraak' dekt in één keer
    // '/uw-afspraak/', '/afspraak-chiro/', '/afspraak-fysio/' én
    // '/je-1e-afspraak/'. Schrijf de termen zonder schuine streep: '/afspraak'
    // zou '/je-1e-afspraak/' juist missen, omdat daar '-afspraak' staat.
    //
    // Deze lijst komt uit het Analytics-rapport. Weggehaald: 'winkelwagen' en
    // 'checkout', want die pagina's bestaan niet (geen webshop, omzet 0).
    // Toegevoegd: de screeningpagina omdat dat een aanmeldpagina is, en de
    // vacature- en sollicitatiepagina's - wie naar werk zoekt heeft niets aan
    // de vraag of die gevonden heeft wat die zocht.
    //
    // Let op: het online boekingssysteem (Crossuite) staat op een ander domein,
    // dus daar draait dit script van zichzelf al niet.
    excludePaths: [
      'contact',
      'afspraak',
      'bedankt',
      'screening',
      'vacature',
      'sollicitatie'
    ],

    // Optionele hulplink onderin het nee-scherm, voor de vraag die het vaakst
    // onbeantwoord blijft. Uit het rapport: 18% van de zoekopdrachten op de
    // site zelf gaat over kosten, tarieven of vergoeding, terwijl die
    // informatie verspreid staat over zeven pagina's die samen maar 2,5% van
    // alle weergaven halen. Laat leeg om de link te verbergen.
    helpUrl: '/kosten-en-vergoedingen/',
    helpLabel: 'Gaat uw vraag over kosten of vergoeding?',

    // Teksten. Pas gerust aan naar de toon van de praktijk.
    text: {
      question: 'Heeft u gevonden wat u zocht?',
      questionSub: 'We horen het graag - zo kunnen we de site verbeteren.',
      yes: 'Ja, gelukt',
      no: 'Nee, nog niet',

      yesTitle: 'Fijn om te horen!',
      yesBody: 'Bedankt voor uw bezoek. Tot ziens in de praktijk.',
      appointmentLabel: 'Direct een afspraak maken',

      noTitle: 'Dat lossen we even op',
      noBody: 'Bel ons gerust, dan denken we direct met u mee. U krijgt gewoon iemand van de praktijk aan de lijn.',
      callLabel: 'Bel',
      whatsappLabel: 'Stuur een WhatsApp',
      hours: 'Maandag t/m vrijdag bereikbaar tijdens openingstijden.',

      close: 'Sluiten'
    }
  };

  /* ==========================================================================
     Vanaf hier hoef je in principe niets meer te wijzigen.
     ========================================================================== */

  var STORAGE_KEY = 'cf_exit_popup_shown_at';
  var shown = false;
  var armed = false;
  var idleTimer = null;
  var lastFocused = null;
  var root = null;

  // Wordt door de WordPress-plugin gevuld met het adres van het meetpunt.
  // Buiten WordPress (bijvoorbeeld op de demopagina) blijft dit leeg en slaan
  // we simpelweg niets op.
  var SETTINGS = window.CF_EXIT_POPUP || {};

  // Willekeurige code per vertoning, zodat het dashboard de gebeurtenissen van
  // één bezoek aan elkaar kan knopen. Bevat geen enkel persoonsgegeven en
  // wordt nergens bewaard nadat het bezoek voorbij is.
  var sessionId = (function () {
    var s = '';
    var chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    for (var i = 0; i < 16; i++) {
      s += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    return s;
  })();

  /* --- Hulpfuncties ------------------------------------------------------- */

  function isTouchDevice() {
    return window.matchMedia && window.matchMedia('(hover: none), (pointer: coarse)').matches;
  }

  // localStorage kan gooien in private mode of met strenge browserinstellingen.
  function storageGet(key) {
    try {
      return window.localStorage.getItem(key);
    } catch (e) {
      return null;
    }
  }

  function storageSet(key, value) {
    try {
      window.localStorage.setItem(key, value);
    } catch (e) {
      /* niets aan te doen, we tonen de pop-up dan hooguit nog een keer */
    }
  }

  function inCooldown() {
    var stamp = parseInt(storageGet(STORAGE_KEY) || '0', 10);
    if (!stamp) return false;
    var days = (Date.now() - stamp) / 86400000;
    return days < CONFIG.cooldownDays;
  }

  function onExcludedPage() {
    var path = window.location.pathname.toLowerCase();
    return CONFIG.excludePaths.some(function (p) {
      return path.indexOf(p.toLowerCase()) !== -1;
    });
  }

  // Stuurt de gebeurtenis naar het eigen dashboard in WordPress.
  //
  // sendBeacon is hier belangrijk: die blijft ook werken als de bezoeker de
  // pagina op datzelfde moment verlaat, en dat is precies het scenario waar
  // deze pop-up voor gemaakt is. Waar sendBeacon ontbreekt vallen we terug op
  // fetch met keepalive.
  function report(action, detail) {
    if (!SETTINGS.endpoint) return;

    var body = JSON.stringify({
      sid: sessionId,
      type: action,
      page: window.location.pathname,
      device: isTouchDevice() ? 'mobiel' : 'desktop',
      trigger: detail.trigger || '',
      answer: detail.answer || ''
    });

    try {
      if (navigator.sendBeacon) {
        navigator.sendBeacon(SETTINGS.endpoint, new Blob([body], { type: 'application/json' }));
        return;
      }
      window.fetch(SETTINGS.endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: body,
        keepalive: true
      });
    } catch (e) {
      /* meten mag nooit de pagina stukmaken */
    }
  }

  // Meldt gebeurtenissen aan het eigen dashboard, aan Google Analytics/Tag
  // Manager als die aanwezig is, en aan de pagina zelf zodat je er eigen
  // scripts op kunt hangen.
  function track(action, detail) {
    var payload = detail || {};
    report(action, payload);

    if (window.dataLayer && typeof window.dataLayer.push === 'function') {
      window.dataLayer.push({ event: 'cf_exit_popup', cf_action: action, cf_detail: payload });
    }
    if (typeof window.gtag === 'function') {
      window.gtag('event', 'cf_exit_popup_' + action, payload);
    }
    try {
      window.dispatchEvent(new CustomEvent('cf-exit-popup', { detail: { action: action, data: payload } }));
    } catch (e) {
      /* oude browsers zonder CustomEvent-constructor: niet erg */
    }
  }

  /* --- Opbouw van de pop-up ---------------------------------------------- */

  function buildMarkup() {
    var t = CONFIG.text;
    var el = document.createElement('div');
    el.className = 'cf-exit';
    el.setAttribute('hidden', '');

    var appointmentBtn = CONFIG.appointmentUrl
      ? '<a class="cf-exit__btn cf-exit__btn--primary" href="' + CONFIG.appointmentUrl + '" data-cf="appointment">' +
        t.appointmentLabel + '</a>'
      : '';

    var whatsappBtn = CONFIG.whatsapp
      ? '<a class="cf-exit__btn cf-exit__btn--whatsapp" data-cf="whatsapp" rel="noopener" target="_blank" href="https://wa.me/' +
        CONFIG.whatsapp + '?text=' + encodeURIComponent(CONFIG.whatsappText) + '">' +
        '<svg class="cf-exit__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
        '<path fill="currentColor" d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2Zm0 18.13h-.01a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.22 8.22 0 0 1-1.26-4.36c0-4.54 3.7-8.24 8.25-8.24 2.2 0 4.27.86 5.83 2.42a8.19 8.19 0 0 1 2.41 5.83c0 4.54-3.7 8.21-8.24 8.21Zm4.52-6.16c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.13-.16.24-.64.8-.78.97-.14.16-.29.18-.54.06-.25-.13-1.05-.39-1.99-1.23-.74-.66-1.23-1.47-1.38-1.72-.14-.25-.01-.38.11-.5.11-.11.25-.29.37-.43.13-.15.17-.25.25-.41.08-.17.04-.31-.02-.43-.06-.12-.56-1.34-.76-1.84-.2-.48-.4-.42-.56-.43h-.47c-.16 0-.43.06-.65.31-.22.25-.85.84-.85 2.04 0 1.2.87 2.36.99 2.53.12.16 1.71 2.61 4.14 3.66.58.25 1.03.4 1.38.51.58.19 1.11.16 1.53.1.47-.07 1.47-.6 1.67-1.18.21-.58.21-1.07.15-1.18-.06-.11-.22-.17-.47-.29Z"/>' +
        '</svg><span>' + t.whatsappLabel + '</span></a>'
      : '';

    el.innerHTML =
      '<div class="cf-exit__overlay" data-cf="overlay"></div>' +
      '<div class="cf-exit__dialog" role="dialog" aria-modal="true" aria-labelledby="cf-exit-title">' +
        '<button type="button" class="cf-exit__close" data-cf="close" aria-label="' + t.close + '">' +
          '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" width="20" height="20">' +
          '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>' +
        '</button>' +

        // Stap 1: de vraag
        '<div class="cf-exit__step" data-step="ask">' +
          '<h2 class="cf-exit__title" id="cf-exit-title">' + t.question + '</h2>' +
          '<p class="cf-exit__body">' + t.questionSub + '</p>' +
          '<div class="cf-exit__actions">' +
            '<button type="button" class="cf-exit__btn cf-exit__btn--ghost" data-cf="answer-yes">' + t.yes + '</button>' +
            '<button type="button" class="cf-exit__btn cf-exit__btn--primary" data-cf="answer-no">' + t.no + '</button>' +
          '</div>' +
        '</div>' +

        // Stap 2a: bezoeker heeft het gevonden
        '<div class="cf-exit__step" data-step="yes" hidden>' +
          '<div class="cf-exit__check" aria-hidden="true">' +
            '<svg viewBox="0 0 24 24" width="28" height="28">' +
            '<path fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" d="M20 6 9 17l-5-5"/></svg>' +
          '</div>' +
          '<h2 class="cf-exit__title">' + t.yesTitle + '</h2>' +
          '<p class="cf-exit__body">' + t.yesBody + '</p>' +
          (appointmentBtn ? '<div class="cf-exit__actions">' + appointmentBtn + '</div>' : '') +
        '</div>' +

        // Stap 2b: bezoeker heeft het niet gevonden -> contact
        '<div class="cf-exit__step" data-step="no" hidden>' +
          '<h2 class="cf-exit__title">' + t.noTitle + '</h2>' +
          '<p class="cf-exit__body">' + t.noBody + '</p>' +
          '<div class="cf-exit__actions cf-exit__actions--stack">' +
            '<a class="cf-exit__btn cf-exit__btn--call" data-cf="call" href="tel:' + CONFIG.phoneHref + '">' +
              '<svg class="cf-exit__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
              '<path fill="currentColor" d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.4.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1A17 17 0 0 1 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.3.2 2.5.6 3.6.1.4 0 .8-.2 1l-2.3 2.2Z"/>' +
              '</svg><span>' + t.callLabel + ' ' + CONFIG.phoneDisplay + '</span></a>' +
            whatsappBtn +
          '</div>' +
          (CONFIG.helpUrl
            ? '<a class="cf-exit__help" data-cf="help" href="' + CONFIG.helpUrl + '">' +
              CONFIG.helpLabel + '</a>'
            : '') +
          '<p class="cf-exit__note">' + t.hours + '</p>' +
        '</div>' +
      '</div>';

    return el;
  }

  /* --- Openen, sluiten, focus -------------------------------------------- */

  function focusables() {
    return Array.prototype.filter.call(
      root.querySelectorAll('a[href], button:not([disabled])'),
      function (node) { return node.offsetParent !== null; }
    );
  }

  function trapFocus(e) {
    if (e.key !== 'Tab') return;
    var items = focusables();
    if (!items.length) return;
    var first = items[0];
    var last = items[items.length - 1];

    if (e.shiftKey && document.activeElement === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  }

  function onKeydown(e) {
    if (e.key === 'Escape') {
      close('escape');
      return;
    }
    trapFocus(e);
  }

  function showStep(name) {
    Array.prototype.forEach.call(root.querySelectorAll('.cf-exit__step'), function (step) {
      var match = step.getAttribute('data-step') === name;
      if (match) {
        step.removeAttribute('hidden');
      } else {
        step.setAttribute('hidden', '');
      }
    });
    var target = root.querySelector('[data-step="' + name + '"] .cf-exit__btn');
    if (target) target.focus();
  }

  function open(trigger) {
    if (shown) return;
    shown = true;
    disarm();
    storageSet(STORAGE_KEY, String(Date.now()));

    // Altijd bij de vraag beginnen, ook als een vorige keer op een later
    // scherm geëindigd is.
    showStep('ask');

    lastFocused = document.activeElement;
    root.removeAttribute('hidden');
    // Volgende frame, zodat de CSS-transitie daadwerkelijk afspeelt.
    requestAnimationFrame(function () {
      root.classList.add('is-open');
    });
    document.body.classList.add('cf-exit-lock');
    document.addEventListener('keydown', onKeydown, true);

    var firstBtn = root.querySelector('[data-step="ask"] .cf-exit__btn');
    if (firstBtn) firstBtn.focus();

    track('open', { trigger: trigger });
  }

  function close(reason) {
    root.classList.remove('is-open');
    document.body.classList.remove('cf-exit-lock');
    document.removeEventListener('keydown', onKeydown, true);

    window.setTimeout(function () {
      root.setAttribute('hidden', '');
    }, 220);

    if (lastFocused && typeof lastFocused.focus === 'function') {
      lastFocused.focus();
    }
    track('close', { reason: reason });
  }

  /* --- Triggers ----------------------------------------------------------- */

  // Desktop: de muis verlaat het venster aan de bovenkant (richting tabbladen,
  // adresbalk of de sluitknop). relatedTarget is dan leeg.
  function onMouseOut(e) {
    if (!armed || shown) return;
    if (e.relatedTarget || e.clientY > 0) return;
    open('mouseleave');
  }

  // Mobiel: geen inactiviteit meer -> timer opnieuw starten.
  function resetIdle() {
    if (!armed || shown || !CONFIG.mobileIdleMs) return;
    window.clearTimeout(idleTimer);
    idleTimer = window.setTimeout(function () {
      open('idle');
    }, CONFIG.mobileIdleMs);
  }

  // Mobiel: snel omhoog scrollen richting de bovenkant is vaak een teken dat
  // iemand naar de adresbalk of terugknop gaat.
  var lastScrollY = 0;
  var lastScrollT = 0;
  function onScroll() {
    if (!armed || shown) return;
    var y = window.pageYOffset;
    var now = Date.now();
    var dt = now - lastScrollT;

    if (dt > 0 && dt < 500) {
      var speed = (lastScrollY - y) / dt; // pixels per ms, positief = omhoog
      if (speed > 1.2 && y < 300) {
        open('scroll-up');
      }
    }
    lastScrollY = y;
    lastScrollT = now;
    resetIdle();
  }

  // Wacht op het eerste teken van leven en zet daarna scherp. Zonder enige
  // interactie gebeurt er niets - zo blijven bezoekers die de pagina alleen
  // ophalen en verder niets doen buiten de metingen.
  var WAKE_EVENTS = ['mousemove', 'scroll', 'keydown', 'touchstart', 'click'];
  var stopWaiting = null;

  function armWhenInteracted() {
    function go() {
      stopWaiting();
      if (shown) return;
      arm();
    }

    stopWaiting = function () {
      WAKE_EVENTS.forEach(function (evt) {
        window.removeEventListener(evt, go, true);
      });
      stopWaiting = null;
    };

    WAKE_EVENTS.forEach(function (evt) {
      window.addEventListener(evt, go, { passive: true, capture: true });
    });
  }

  function arm() {
    armed = true;
    if (isTouchDevice()) {
      if (!CONFIG.enableMobile) return;
      ['touchstart', 'click', 'keydown'].forEach(function (evt) {
        document.addEventListener(evt, resetIdle, { passive: true });
      });
      window.addEventListener('scroll', onScroll, { passive: true });
      resetIdle();
    } else {
      document.addEventListener('mouseout', onMouseOut);
    }
  }

  function disarm() {
    armed = false;
    if (stopWaiting) stopWaiting();
    window.clearTimeout(idleTimer);
    document.removeEventListener('mouseout', onMouseOut);
    window.removeEventListener('scroll', onScroll);
    ['touchstart', 'click', 'keydown'].forEach(function (evt) {
      document.removeEventListener(evt, resetIdle);
    });
  }

  /* --- Start -------------------------------------------------------------- */

  function bindUi() {
    root.addEventListener('click', function (e) {
      var el = e.target.closest ? e.target.closest('[data-cf]') : null;
      if (!el) return;
      var action = el.getAttribute('data-cf');

      switch (action) {
        case 'overlay':
        case 'close':
          close(action);
          break;
        case 'answer-yes':
          track('answer', { answer: 'ja' });
          showStep('yes');
          break;
        case 'answer-no':
          track('answer', { answer: 'nee' });
          showStep('no');
          break;
        case 'call':
          track('call', { number: CONFIG.phoneHref });
          break;
        case 'whatsapp':
          track('whatsapp', {});
          break;
        case 'help':
          track('help', {});
          break;
        case 'appointment':
          track('appointment', {});
          break;
      }
    });
  }

  function init() {
    // Debug-modus: zet ?cf-popup=test in de URL om de cooldown te negeren en
    // de pop-up direct te kunnen testen.
    var testMode = window.location.search.indexOf('cf-popup=test') !== -1;

    if (!testMode) {
      if (inCooldown() || onExcludedPage()) return;
    }
    if (isTouchDevice() && !CONFIG.enableMobile) return;

    root = buildMarkup();
    document.body.appendChild(root);
    bindUi();

    if (testMode) {
      window.setTimeout(function () { open('test'); }, 500);
      return;
    }

    window.setTimeout(
      CONFIG.requireInteraction ? armWhenInteracted : arm,
      CONFIG.armAfterMs
    );
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // Kleine publieke ingang, handig om te testen of om de pop-up aan een eigen
  // knop te hangen:
  //
  //   cfExitPopup.toon();     - toon hem meteen
  //   cfExitPopup.vergeet();  - wis het "al getoond"-geheugen
  window.cfExitPopup = {
    toon: function () {
      if (!root) {
        root = buildMarkup();
        document.body.appendChild(root);
        bindUi();
      }
      shown = false;
      open('handmatig');
    },
    vergeet: function () {
      try {
        window.localStorage.removeItem(STORAGE_KEY);
      } catch (e) {
        /* niets aan te doen */
      }

      // "Vergeten" betekent ook dat de pop-up weer mag verschijnen, dus zetten
      // we de herkenning opnieuw scherp. Zonder dit blijft hij na één keer
      // tonen definitief uit.
      shown = false;
      disarm();
      arm();
    }
  };
})();
