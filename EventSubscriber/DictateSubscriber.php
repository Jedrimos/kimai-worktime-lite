<?php

namespace KimaiPlugin\WorkTimeLiteBundle\EventSubscriber;

use App\Event\ThemeEvent;
use KimaiPlugin\WorkTimeLiteBundle\Service\Settings;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Mikrofon-Knopf an Textfeldern: Spracherkennung des Browsers schreibt mit.
 * Wird nur angezeigt, wenn der Browser das kann (Chrome/Android, Safari/iOS).
 */
final class DictateSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Security $security,
    )
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ThemeEvent::JAVASCRIPT => ['renderJavascript', 60],
        ];
    }

    public function renderJavascript(ThemeEvent $event): void
    {
        if (!$this->settings->bool('dictate', true) || $this->security->getUser() === null) {
            return;
        }

        $event->addContent(<<<'HTML'
<style>
.wtl-dict-wrap { position: relative; }
.wtl-dict-wrap > input, .wtl-dict-wrap > textarea { padding-right: 2.6rem !important; }
.wtl-dict-btn { position: absolute; right: .3rem; top: .3rem; width: 2rem; height: 2rem; border: 0; border-radius: 50%;
    background: transparent; color: var(--tblr-secondary, #667382); display: flex; align-items: center; justify-content: center; z-index: 3; }
.wtl-dict-btn.on { background: var(--tblr-danger, #d63939); color: #fff; animation: wtlpulse 1s infinite; }
@keyframes wtlpulse { 50% { opacity: .6; } }
</style>
<script>
(function () {
    var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SR) { return; }
    var selector = '[data-wtl-dictate], textarea[name$="[description]"]';
    function attach(el) {
        if (el.dataset.wtlDictOk || el.type === 'hidden') { return; }
        el.dataset.wtlDictOk = '1';
        var wrap = document.createElement('div');
        wrap.className = 'wtl-dict-wrap';
        if (el.style.maxWidth) { wrap.style.maxWidth = el.style.maxWidth; }
        if (el.classList.contains('flex-fill') || el.parentElement.classList.contains('d-flex')) { wrap.style.flex = '1'; }
        el.parentNode.insertBefore(wrap, el);
        wrap.appendChild(el);
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'wtl-dict-btn';
        btn.title = 'Diktieren';
        btn.innerHTML = '<i class="fas fa-microphone"></i>';
        wrap.appendChild(btn);
        var rec = null;
        btn.addEventListener('click', function () {
            if (rec) { rec.stop(); return; }
            rec = new SR();
            rec.lang = 'de-DE';
            rec.interimResults = false;
            rec.continuous = el.tagName === 'TEXTAREA';
            var base = el.value;
            rec.onresult = function (e) {
                var text = '';
                for (var i = 0; i < e.results.length; i++) { text += e.results[i][0].transcript; }
                text = text.trim();
                if (text) {
                    text = text.charAt(0).toUpperCase() + text.slice(1);
                    el.value = base ? base.replace(/\s*$/, el.tagName === 'TEXTAREA' ? '\n' : ' ') + text : text;
                    el.dispatchEvent(new Event('input', { bubbles: true }));
                    el.dispatchEvent(new Event('change', { bubbles: true }));
                }
            };
            rec.onend = function () { btn.classList.remove('on'); rec = null; };
            rec.onerror = function () { btn.classList.remove('on'); rec = null; };
            btn.classList.add('on');
            rec.start();
        });
    }
    function scan() { document.querySelectorAll(selector).forEach(attach); }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', scan); } else { scan(); }
    new MutationObserver(function () { window.clearTimeout(window.__wtlDict); window.__wtlDict = window.setTimeout(scan, 200); })
        .observe(document.documentElement, { childList: true, subtree: true });
})();
</script>
HTML);
    }
}
