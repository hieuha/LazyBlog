// hello-world plugin client. Loads on plugin routes only.
// Purpose: prove that plugin JS is route-scoped + cache-busted via mtime.
// Behavior: highlight the input border once the user types anything,
// fade back when empty.
(function () {
    var form = document.querySelector('.hello-form');
    if (!form) return;

    var input = form.querySelector('input[name="text"]');
    if (!input) return;

    input.addEventListener('input', function () {
        if (input.value.length > 0) {
            input.classList.add('is-active');
        } else {
            input.classList.remove('is-active');
        }
    });
})();
