<?php

namespace KimaiPlugin\WorkTimeLiteBundle\EventSubscriber;

use App\Event\ThemeEvent;
use KimaiPlugin\WorkTimeLiteBundle\Service\Settings;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Bindet "+ „Name“ anlegen" in die Auswahlfelder Kunde/Projekt/Tätigkeit des Zeiteintrags ein.
 */
final class QuickCreateSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly CsrfTokenManagerInterface $csrf,
    )
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ThemeEvent::JAVASCRIPT => ['renderJavascript', 90],
        ];
    }

    public function renderJavascript(ThemeEvent $event): void
    {
        if (!$this->settings->bool('quick_create', true) || $this->security->getUser() === null) {
            return;
        }

        $allowed = [
            'customer' => $this->security->isGranted('create_customer'),
            'project' => $this->security->isGranted('create_project'),
            'activity' => $this->security->isGranted('create_activity'),
        ];
        if (!\in_array(true, $allowed, true)) {
            return;
        }

        $config = json_encode([
            'url' => $this->urlGenerator->generate('wtl_quick_create'),
            'token' => $this->csrf->getToken('wtl_quick_create')->getValue(),
            'allowed' => $allowed,
            'globalActivity' => $this->settings->bool('quick_create_global_activity', true),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $event->addContent(<<<HTML
<script>
(function () {
    var cfg = {$config};
    var TYPES = ['customer', 'project', 'activity'];

    function typeOf(select) {
        var m = (select.name || '').match(/\[(customer|project|activity)\]$/);
        return m ? m[1] : null;
    }
    function sibling(select, type) {
        var form = select.form;
        if (!form) { return null; }
        return form.querySelector('select[name$="[' + type + ']"]');
    }
    function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    function patch(select) {
        var ts = select.tomselect;
        var type = typeOf(select);
        if (!ts || !type || !cfg.allowed[type] || select.dataset.wtlQuick === '1') { return; }
        var form = select.form;
        if (!form || !/^timesheet/.test(form.name || '')) { return; }
        select.dataset.wtlQuick = '1';

        ts.settings.persist = false;
        ts.settings.createOnBlur = false;
        ts.settings.render.option_create = function (data, escape) {
            return '<div class="create text-primary fw-bold">+ „' + escape(data.input) + '“ anlegen</div>';
        };
        ts.settings.create = function (input, callback) {
            var payload = { type: type, name: input, token: cfg.token };
            if (type === 'project') {
                var c = sibling(select, 'customer');
                payload.customer = c ? c.value : '';
                if (!payload.customer) { window.alert('Bitte zuerst einen Kunden auswählen.'); callback(); return; }
            }
            if (type === 'activity') {
                var p = sibling(select, 'project');
                payload.project = p ? p.value : '';
                if (!cfg.globalActivity && !payload.project) { window.alert('Bitte zuerst ein Projekt auswählen.'); callback(); return; }
            }
            fetch(cfg.url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify(payload)
            }).then(function (r) {
                return r.json().then(function (j) { return { ok: r.ok, data: j }; });
            }).then(function (res) {
                if (!res.ok || !res.data.id) {
                    window.alert(res.data.error || 'Anlegen fehlgeschlagen');
                    callback();
                    return;
                }
                // Option direkt ins <select> schreiben und auswählen (robuster als der TomSelect-Callback,
                // weil Kimai die Liste sonst beim Synchronisieren wieder verwirft)
                callback();
                var id = String(res.data.id);
                var exists = Array.prototype.some.call(select.options, function (o) { return o.value === id; });
                if (!exists) { select.add(new Option(res.data.text, id)); }
                ts.sync();
                ts.setValue(id);
                ts.close();
                ts.blur();
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }).catch(function () {
                window.alert('Anlegen fehlgeschlagen (keine Verbindung?)');
                callback();
            });
        };
    }

    function scan() {
        document.querySelectorAll('form select').forEach(function (s) {
            if (TYPES.indexOf(typeOf(s)) !== -1) { patch(s); }
        });
    }

    // Formulare kommen oft erst per Ajax (Modal) – darum regelmäßig nachsehen
    document.addEventListener('DOMContentLoaded', scan);
    new MutationObserver(function () { window.clearTimeout(window.__wtlQc); window.__wtlQc = window.setTimeout(scan, 150); })
        .observe(document.documentElement, { childList: true, subtree: true });
    window.setInterval(scan, 1500);
})();
</script>
HTML);
    }
}
