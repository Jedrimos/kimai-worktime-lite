<?php

namespace KimaiPlugin\WorkTimeLiteBundle\EventSubscriber;

use App\Event\ThemeEvent;
use KimaiPlugin\WorkTimeLiteBundle\Service\Settings;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Bequeme Zeiteingabe im Browser: 7.00 / 700 / 7 → 07:00 (Uhrzeit), optional 7.30 → 7:30 (Dauer).
 * Läuft beim Verlassen des Feldes und vor dem Absenden, also bevor der Browser das Format prüft.
 */
final class InputNormalizerSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ThemeEvent::JAVASCRIPT => ['renderJavascript', 100],
        ];
    }

    public function renderJavascript(ThemeEvent $event): void
    {
        $time = $this->settings->bool('input_time_normalize', true);
        $duration = $this->settings->bool('input_duration_dot_minutes', false);

        if (!$time && !$duration) {
            return;
        }

        $config = json_encode(['time' => $time, 'duration' => $duration]);

        $event->addContent(<<<HTML
<script>
(function () {
    var cfg = {$config};
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function normTime(v) {
        v = (v || '').trim();
        if (v === '') { return null; }
        var m, h = null, mi = null;
        if ((m = v.match(/^(\d{1,2})\s*[:.,;\- h]\s*(\d{1,2})$/))) { h = +m[1]; mi = m[2].length === 1 ? +m[2] * 10 : +m[2]; }
        else if ((m = v.match(/^(\d{1,2})(\d{2})$/))) { h = +m[1]; mi = +m[2]; }
        else if ((m = v.match(/^(\d{1,2})\s*h?$/))) { h = +m[1]; mi = 0; }
        if (h === null || h > 23 || mi > 59) { return null; }
        return pad(h) + ':' + pad(mi);
    }
    function normDuration(v) {
        var m = (v || '').trim().match(/^(-?)(\d{1,3})[.,](\d{1,2})$/);
        if (!m) { return null; }
        var mi = m[3].length === 1 ? +m[3] * 10 : +m[3];
        if (mi > 59) { return null; }
        return m[1] + (+m[2]) + ':' + pad(mi);
    }
    function fix(el) {
        if (!el || el.tagName !== 'INPUT') { return; }
        var res = null;
        if (cfg.time && el.hasAttribute('data-timepicker')) { res = normTime(el.value); }
        else if (cfg.duration && el.classList.contains('duration-input')) { res = normDuration(el.value); }
        if (res !== null && res !== el.value) {
            el.value = res;
            el.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }
    // Capture-Phase: vor Kimais eigenen Handlern
    document.addEventListener('blur', function (e) { fix(e.target); }, true);
    document.addEventListener('change', function (e) { fix(e.target); }, true);
    document.addEventListener('keydown', function (e) { if (e.key === 'Enter') { fix(e.target); } }, true);
    document.addEventListener('submit', function (e) {
        if (!e.target || !e.target.querySelectorAll) { return; }
        e.target.querySelectorAll('input[data-timepicker], input.duration-input').forEach(fix);
    }, true);
    // Mobile Tastatur mit Punkt/Komma statt nur Ziffern
    document.addEventListener('focusin', function (e) {
        var el = e.target;
        if (el && el.tagName === 'INPUT' && cfg.time && el.hasAttribute('data-timepicker') && !el.hasAttribute('inputmode')) {
            el.setAttribute('inputmode', 'decimal');
        }
    }, true);
})();
</script>
HTML);
    }
}
