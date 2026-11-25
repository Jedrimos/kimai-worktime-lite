<?php

namespace KimaiPlugin\WorkTimeLiteBundle\EventSubscriber;

use App\Event\ThemeEvent;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * "Mit wem": Namen als Knöpfe zum Antippen statt Tippen.
 */
final class CompanionsSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly Security $security)
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
        $event->addContent(<<<'HTML'
<script>
(function () {
    function enhance(input) {
        if (input.dataset.wtlCompOk) { return; }
        input.dataset.wtlCompOk = '1';
        var names = [];
        try { names = JSON.parse(input.dataset.wtlCompanions || '[]'); } catch (e) {}
        var box = document.createElement('div');
        box.className = 'd-flex flex-wrap gap-1 mt-1';
        input.type = 'hidden';
        input.parentNode.insertBefore(box, input.nextSibling);
        function current() { return input.value.split(',').map(function (s) { return s.trim(); }).filter(Boolean); }
        function render() {
            var cur = current();
            box.innerHTML = '';
            var all = names.slice();
            cur.forEach(function (n) { if (all.indexOf(n) === -1) { all.push(n); } });
            all.forEach(function (n) {
                var b = document.createElement('button');
                b.type = 'button';
                var on = cur.indexOf(n) !== -1;
                b.className = 'btn btn-sm ' + (on ? 'btn-primary' : 'btn-outline-secondary');
                b.innerHTML = (on ? '<i class="fas fa-check me-1"></i>' : '<i class="fas fa-user me-1"></i>') + n.replace(/</g, '&lt;');
                b.addEventListener('click', function () {
                    var c = current(), i = c.indexOf(n);
                    if (i === -1) { c.push(n); } else { c.splice(i, 1); }
                    input.value = c.join(', ');
                    // nicht neu aufbauen (Kimai hört auf Klicks), nur Aussehen umschalten
                    window.setTimeout(render, 0);
                });
                box.appendChild(b);
            });
            if (all.length === 0) { box.innerHTML = '<span class="text-secondary small">Namen in den Plugin-Einstellungen eintragen</span>'; }
        }
        input.addEventListener('input', render);
        input.addEventListener('change', render);
        render();
    }
    function scan() { document.querySelectorAll('input[data-wtl-companions]').forEach(enhance); }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', scan); } else { scan(); }
    new MutationObserver(function () { window.clearTimeout(window.__wtlComp); window.__wtlComp = window.setTimeout(scan, 150); })
        .observe(document.documentElement, { childList: true, subtree: true });
})();
</script>
HTML);
    }
}
