<?php

namespace KimaiPlugin\WorkTimeLiteBundle\EventSubscriber;

use App\Event\ThemeEvent;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * "Mit wem": Mehrfach-Dropdown wie die anderen Kimai-Auswahlfelder.
 * Unbekannte Namen tippen → "anlegen" → wird für diesen Benutzer gespeichert.
 */
final class CompanionsSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly CsrfTokenManagerInterface $csrf,
    )
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [ThemeEvent::JAVASCRIPT => ['renderJavascript', 55]];
    }

    public function renderJavascript(ThemeEvent $event): void
    {
        if ($this->security->getUser() === null) {
            return;
        }
        $cfg = json_encode([
            'url' => $this->urlGenerator->generate('wtl_people_add'),
            'token' => $this->csrf->getToken('wtl_people')->getValue(),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $event->addContent(<<<HTML
<script>
(function () {
    var cfg = {$cfg};
    function enhance(input) {
        if (input.dataset.wtlCompOk) { return; }
        input.dataset.wtlCompOk = '1';
        var names = [];
        try { names = JSON.parse(input.dataset.wtlCompanions || '[]'); } catch (e) {}
        var current = input.value.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
        current.forEach(function (n) { if (names.indexOf(n) === -1) { names.push(n); } });

        var sel = document.createElement('select');
        sel.multiple = true;
        sel.className = 'form-select selectpicker';
        sel.dataset.create = '1';
        sel.setAttribute('data-placeholder', 'Kollegen / Azubis wählen oder neu tippen');
        names.forEach(function (n) {
            var o = new Option(n, n, false, current.indexOf(n) !== -1);
            sel.add(o);
        });
        input.type = 'hidden';
        input.parentNode.insertBefore(sel, input.nextSibling);

        function sync() {
            input.value = Array.prototype.filter.call(sel.options, function (o) { return o.selected; }).map(function (o) { return o.value; }).join(', ');
        }
        sel.addEventListener('change', sync);
        // neuen Namen dauerhaft für diesen Benutzer speichern
        sel.addEventListener('create', function (e) {
            var name = (e.detail && e.detail.value || '').trim();
            if (!name) { return; }
            var fd = new FormData(); fd.append('name', name); fd.append('_token', cfg.token);
            fetch(cfg.url, { method: 'POST', body: fd, credentials: 'same-origin' });
            window.setTimeout(sync, 0);
        });
        // wenn "Wie letzter Tag" den Wert setzt
        input.addEventListener('input', function () {
            var vals = input.value.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
            if (sel.tomselect) {
                vals.forEach(function (v) { if (!sel.tomselect.options[v]) { sel.tomselect.addOption({ value: v, text: v }); } });
                sel.tomselect.setValue(vals, true);
            }
            sync();
        });

        try {
            if (window.kimai && window.kimai.getPlugin('form-select')) {
                window.kimai.getPlugin('form-select').activateSelectPickerByElement(sel);
            }
        } catch (e) { /* ohne Kimai-Dropdown bleibt das normale Mehrfach-Select */ }
    }
    function scan() { document.querySelectorAll('input[data-wtl-companions]').forEach(enhance); }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', function () { window.setTimeout(scan, 50); }); } else { scan(); }
    new MutationObserver(function () { window.clearTimeout(window.__wtlComp); window.__wtlComp = window.setTimeout(scan, 150); })
        .observe(document.documentElement, { childList: true, subtree: true });
})();
</script>
HTML);
    }
}
