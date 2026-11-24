<?php

namespace KimaiPlugin\WorkTimeLiteBundle\EventSubscriber;

use App\Event\ThemeEvent;
use KimaiPlugin\WorkTimeLiteBundle\Service\Settings;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Knöpfe "Standardtag" und "Wie letzter Tag" oben im Formular "Zeiteintrag erstellen".
 */
final class DayButtonsSubscriber implements EventSubscriberInterface
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
            ThemeEvent::JAVASCRIPT => ['renderJavascript', 80],
        ];
    }

    public function renderJavascript(ThemeEvent $event): void
    {
        if (!$this->settings->bool('day_buttons', true) || $this->security->getUser() === null) {
            return;
        }

        $config = json_encode(['url' => $this->urlGenerator->generate('wtl_day_info')], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $event->addContent(<<<HTML
<script>
(function () {
    var cfg = {$config};

    function field(form, name) { return form.querySelector('[name$="[' + name + ']"]'); }
    function setVal(el, v) {
        if (!el) { return; }
        el.value = v;
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
    }
    function isoDate(form) {
        var d = field(form, 'begin_date');
        var m = d && d.value.match(/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/);
        if (m) { return m[3] + '-' + ('0' + m[2]).slice(-2) + '-' + ('0' + m[1]).slice(-2); }
        return '';
    }
    function waitForOption(select, value, cb, tries) {
        tries = tries || 0;
        var has = select && Array.prototype.some.call(select.options, function (o) { return o.value === value; });
        if (has || tries > 40) { cb(has); return; }
        window.setTimeout(function () { waitForOption(select, value, cb, tries + 1); }, 100);
    }
    function choose(select, value, cb) {
        if (!select || !value) { if (cb) { cb(); } return; }
        waitForOption(select, value, function (ok) {
            if (ok) {
                if (select.tomselect) { select.tomselect.setValue(value); } else { select.value = value; }
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
            if (cb) { window.setTimeout(cb, 250); }
        });
    }
    function setTimes(form, begin, end, brk) {
        setVal(field(form, 'begin_time'), begin);
        setVal(field(form, 'end_time'), end);
        var b = field(form, 'break');
        if (b) { setVal(b, brk); }
        var d = field(form, 'duration');
        if (d) { d.value = ''; }
        setVal(field(form, 'end_time'), end);
    }

    function addButtons(form) {
        if (form.dataset.wtlDay === '1') { return; }
        if (!/^timesheet/.test(form.name || '') || !/\/create/.test(form.getAttribute('action') || '')) { return; }
        if (!field(form, 'begin_time') || !field(form, 'end_time')) { return; }
        form.dataset.wtlDay = '1';

        var bar = document.createElement('div');
        bar.className = 'd-flex flex-wrap gap-2 mb-3 wtl-day-buttons';
        bar.innerHTML = '<button type="button" class="btn btn-outline-primary flex-fill" data-wtl="std"><i class="fas fa-bolt me-1"></i>Standardtag</button>'
            + '<button type="button" class="btn btn-outline-primary flex-fill" data-wtl="last"><i class="fas fa-history me-1"></i>Wie letzter Tag</button>';
        var anchor = form.querySelector('.card-body') || form;
        anchor.insertBefore(bar, anchor.firstChild);

        bar.addEventListener('click', function (ev) {
            var btn = ev.target.closest('button[data-wtl]');
            if (!btn) { return; }
            btn.disabled = true;
            fetch(cfg.url + '?date=' + encodeURIComponent(isoDate(form)), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    btn.disabled = false;
                    if (btn.dataset.wtl === 'std') {
                        if (!data.standard) { window.alert('An diesem Tag ist laut Arbeitsvertrag kein Arbeitstag.'); return; }
                        setTimes(form, data.standard.begin, data.standard.end, data.standard.break);
                        btn.title = data.standard.label;
                        return;
                    }
                    var l = data.last;
                    if (!l) { window.alert('Kein früherer Eintrag gefunden.'); return; }
                    setTimes(form, l.begin, l.end, l.break);
                    var desc = field(form, 'description');
                    if (desc && !desc.value) { setVal(desc, l.description); }
                    Object.keys(l.meta || {}).forEach(function (k) {
                        var els = form.querySelectorAll('[name$="[metaFields][' + k + '][value]"]');
                        els.forEach(function (el) {
                            if (el.type === 'radio' || el.type === 'checkbox') { el.checked = (el.value === l.meta[k]); }
                            else if (el.tomselect) { el.tomselect.setValue(l.meta[k]); }
                            else { el.value = l.meta[k]; }
                        });
                    });
                    var c = field(form, 'customer'), p = field(form, 'project'), a = field(form, 'activity');
                    choose(c, l.customer, function () {
                        choose(p, l.project, function () {
                            choose(a, l.activity);
                        });
                    });
                })
                .catch(function () { btn.disabled = false; window.alert('Konnte die Daten nicht laden.'); });
        });
    }

    function scan() { document.querySelectorAll('form').forEach(addButtons); }
    document.addEventListener('DOMContentLoaded', scan);
    new MutationObserver(function () { window.clearTimeout(window.__wtlDb); window.__wtlDb = window.setTimeout(scan, 150); })
        .observe(document.documentElement, { childList: true, subtree: true });
})();
</script>
HTML);
    }
}
