<?php

namespace KimaiPlugin\WorkTimeLiteBundle\EventSubscriber;

use App\Event\ThemeEvent;
use KimaiPlugin\WorkTimeLiteBundle\Service\Settings;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Monteur-Modus fürs Handy: Leiste unten mit den wichtigsten Punkten,
 * aufgeräumter Kopfbereich, große Touch-Flächen.
 */
final class MobileNavSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
    )
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ThemeEvent::JAVASCRIPT => ['renderJavascript', 70],
        ];
    }

    public function renderJavascript(ThemeEvent $event): void
    {
        if (!$this->settings->bool('mobile_nav', true) || $this->security->getUser() === null) {
            return;
        }

        $today = $this->settings->bool('site_today', true);
        $items = [
            ['href' => $this->urlGenerator->generate($today ? 'wtl_today' : 'homepage'), 'icon' => $today ? 'fa-sun' : 'fa-home', 'label' => $today ? 'Heute' : 'Start', 'match' => $today ? '/worktime-lite/today' : '/dashboard'],
            ['href' => $this->urlGenerator->generate('wtl_site'), 'icon' => 'fa-hard-hat', 'label' => 'Baustellen', 'match' => '/worktime-lite/site'],
            ['href' => $this->urlGenerator->generate('timesheet_create'), 'icon' => 'fa-plus', 'label' => 'Zeit', 'match' => '/timesheet/create', 'fab' => true],
            ['href' => $this->urlGenerator->generate('timesheet'), 'icon' => 'fa-clock', 'label' => 'Zeiten', 'match' => '/timesheet'],
            ['href' => '#menu', 'icon' => 'fa-bars', 'label' => 'Mehr', 'match' => '#'],
        ];
        $config = json_encode($items, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $event->addContent(<<<HTML
<style>
@media (max-width: 991.98px) {
    body.wtl-mobile { padding-bottom: calc(68px + env(safe-area-inset-bottom)); }
    .wtl-bottom-nav { position: fixed; left: 0; right: 0; bottom: 0; z-index: 1030; display: flex; align-items: stretch;
        background: var(--tblr-bg-surface, #fff); border-top: 1px solid var(--tblr-border-color, #e6e7e9);
        padding-bottom: env(safe-area-inset-bottom); box-shadow: 0 -2px 10px rgba(0,0,0,.06); }
    .wtl-bottom-nav a { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px;
        height: 62px; color: var(--tblr-secondary, #667382); text-decoration: none; font-size: .72rem; font-weight: 600; }
    .wtl-bottom-nav a i { font-size: 1.25rem; }
    .wtl-bottom-nav a.active { color: var(--tblr-primary, #206bc4); }
    .wtl-bottom-nav a.wtl-fab i { width: 52px; height: 52px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
        background: var(--tblr-success, #2fb344); color: #fff; font-size: 1.5rem; margin-top: -26px; box-shadow: 0 3px 10px rgba(0,0,0,.25); }
    .wtl-bottom-nav a.wtl-fab { color: var(--tblr-body-color, #1d273b); }
    body.wtl-mobile .page-header.wtl-empty { display: none !important; }
    body.wtl-mobile .page-body { margin-top: .75rem !important; }
    body.wtl-mobile .float-help, body.wtl-mobile .btn-help-float, body.wtl-mobile a.help-float { display: none !important; }
    body.wtl-mobile .container-fluid, body.wtl-mobile .container-xl { padding-left: .6rem; padding-right: .6rem; }
    body.wtl-mobile .btn { min-height: 2.5rem; }
}
@media (min-width: 992px) { .wtl-bottom-nav { display: none; } }
</style>
<script>
(function () {
    var items = {$config};
    function build() {
        if (document.querySelector('.wtl-bottom-nav') || !document.querySelector('.navbar')) { return; }
        document.body.classList.add('wtl-mobile');
        var nav = document.createElement('nav');
        nav.className = 'wtl-bottom-nav d-print-none';
        var path = window.location.pathname, best = null;
        items.forEach(function (it, i) { if (it.match !== '#' && path.indexOf(it.match) !== -1 && (best === null || it.match.length > items[best].match.length)) { best = i; } });
        items.forEach(function (it, i) {
            var a = document.createElement('a');
            a.href = it.href;
            a.className = (it.fab ? 'wtl-fab ' : '') + (i === best ? 'active' : '');
            a.innerHTML = '<i class="fas ' + it.icon + '"></i><span>' + it.label + '</span>';
            if (it.href === '#menu') {
                a.addEventListener('click', function (e) {
                    e.preventDefault();
                    var t = document.querySelector('.navbar-toggler');
                    if (t) { t.click(); window.scrollTo({ top: 0, behavior: 'smooth' }); }
                });
            }
            nav.appendChild(a);
        });
        document.body.appendChild(nav);
        // leeren Seitenkopf ausblenden (nimmt am Handy nur Platz weg)
        document.querySelectorAll('.page-header').forEach(function (h) {
            if (h.textContent.trim() === '' && !h.querySelector('a, button, form, img')) { h.classList.add('wtl-empty'); }
        });
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', build); } else { build(); }
})();
</script>
HTML);
    }
}
