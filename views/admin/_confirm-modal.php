<?php
/**
 * Admin confirm dialog — drop-in replacement for `window.confirm()`.
 *
 * Any element with `data-confirm="message"` (forms or submit buttons) gets
 * intercepted by the script below; the modal opens with the configured
 * message and pops the actual submit only after the operator confirms.
 *
 * Optional attributes:
 *   data-confirm-title  — header label (default "CONFIRM")
 *   data-confirm-label  — confirm button label (default "[ CONFIRM ]")
 *   data-confirm-danger — when "1", uses the danger button colour
 *
 * Included once near the bottom of admin views so the modal exists in the
 * DOM by the time the script wires its global listeners.
 */
?>
<div class="admin-confirm-modal" id="admin-confirm-modal" hidden role="dialog" aria-modal="true" aria-labelledby="admin-confirm-modal-title">
    <div class="admin-confirm-modal-backdrop" data-admin-confirm-dismiss></div>
    <div class="admin-confirm-modal-panel">
        <div class="admin-confirm-modal-tag">
            § <span id="admin-confirm-modal-title">CONFIRM</span>
        </div>
        <p class="admin-confirm-modal-message" id="admin-confirm-modal-message"></p>
        <div class="admin-confirm-modal-actions">
            <button type="button" class="admin-btn" data-admin-confirm-dismiss>[ CANCEL ]</button>
            <button type="button" class="admin-btn admin-btn-primary" id="admin-confirm-modal-confirm">[ CONFIRM ]</button>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';
    var modal = document.getElementById('admin-confirm-modal');
    if (!modal) return;
    var titleEl = document.getElementById('admin-confirm-modal-title');
    var msgEl = document.getElementById('admin-confirm-modal-message');
    var confirmBtn = document.getElementById('admin-confirm-modal-confirm');

    // Holds both the target form AND the optional submitter button so a
    // `formaction`-overriding button (e.g. the "Remove Password" submit
    // inside the main edit form) still routes to the right URL on confirm.
    var pending = null;

    function close() {
        modal.hidden = true;
        pending = null;
        confirmBtn.classList.remove('admin-btn-danger');
        confirmBtn.classList.add('admin-btn-primary');
    }

    function open(spec) {
        pending = spec;
        var src = spec.source;
        var msg = src.getAttribute('data-confirm') || 'Are you sure?';
        var title = (src.getAttribute('data-confirm-title') || 'CONFIRM').toUpperCase();
        var label = src.getAttribute('data-confirm-label') || '[ CONFIRM ]';
        var danger = src.getAttribute('data-confirm-danger') === '1';
        titleEl.textContent = title;
        msgEl.textContent = msg;
        confirmBtn.textContent = label;
        if (danger) {
            confirmBtn.classList.remove('admin-btn-primary');
            confirmBtn.classList.add('admin-btn-danger');
        }
        modal.hidden = false;
        setTimeout(function () { confirmBtn.focus(); }, 30);
    }

    function doConfirm() {
        if (!pending) { close(); return; }
        var form = pending.form;
        var submitter = pending.submitter;
        // Mark the form so the submit listener below lets it through on
        // the re-dispatch (otherwise we'd re-prompt indefinitely).
        form.dataset.confirmed = '1';
        if (submitter && typeof form.requestSubmit === 'function') {
            // requestSubmit(submitter) preserves formaction/formmethod on
            // the button — plain form.submit() would lose them and POST
            // to the form's own action instead.
            try { form.requestSubmit(submitter); }
            catch (err) { form.submit(); }
        } else {
            form.submit();
        }
        close();
    }

    confirmBtn.addEventListener('click', doConfirm);
    var dismissers = document.querySelectorAll('[data-admin-confirm-dismiss]');
    for (var i = 0; i < dismissers.length; i++) {
        dismissers[i].addEventListener('click', close);
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) {
            e.preventDefault();
            close();
        }
    });

    // Capture-phase submit listener handles both:
    //   • <form data-confirm="...">  → prompt before the form submits
    //   • <button type="submit" data-confirm="..."> inside a form → prompt
    //     and replay the submit through the button so its formaction wins.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form) return;
        var submitter = e.submitter || null;
        var source = null;
        if (submitter && submitter.hasAttribute && submitter.hasAttribute('data-confirm')) {
            source = submitter;
        } else if (form.hasAttribute && form.hasAttribute('data-confirm')) {
            source = form;
        }
        if (!source) return;
        if (form.dataset.confirmed === '1') return;
        e.preventDefault();
        e.stopPropagation();
        open({ form: form, submitter: submitter, source: source });
    }, true);
})();
</script>
