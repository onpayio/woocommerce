jQuery(function($) {
    // ---- Conditional show/hide for method description fields ---------------
    $('.field-deselect-hide').each(function() {
        let field = $(this);
        let reference = field.data('selection-source');
        if (undefined !== reference) {
            let referenceObject = $(reference);
            // Add change event listener to the reference object
            referenceObject.on('change', function() {
                if (referenceObject.is(':checked') == true) {
                    field.addClass('show');
                } else {
                    field.removeClass('show');
                }
            });
        }
    });

    // ---- Toggle secret visibility -----------------------------------------
    $(document).on('click', '.onpay-toggle-secret', function(e) {
        e.preventDefault();
        var input = $(this).closest('.onpay-info-row__control').find('.onpay-secret-input');
        if (!input.length) { return; }
        if (input.attr('type') === 'password') {
            input.attr('type', 'text');
        } else {
            input.attr('type', 'password');
        }
    });

    // ---- Copy-to-clipboard for readonly inputs ----------------------------
    $(document).on('click', '.onpay-copy', function(e) {
        e.preventDefault();
        var btn = $(this);
        var target = btn.data('copy-target');
        var input;
        if (target === 'prev') {
            input = btn.prev('input');
        } else if (target === 'prev-prev') {
            input = btn.prev().prev('input');
        } else {
            input = btn.closest('.onpay-info-row__control').find('input').first();
        }
        if (!input.length) { return; }
        var value = input.val();
        var done = function() {
            btn.addClass('is-copied');
            setTimeout(function() { btn.removeClass('is-copied'); }, 1200);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(value).then(done, function() {
                // fallback below
                fallbackCopy(input.get(0), done);
            });
        } else {
            fallbackCopy(input.get(0), done);
        }
    });

    function fallbackCopy(inputEl, done) {
        try {
            var prevType = inputEl.type;
            inputEl.type = 'text';
            inputEl.select();
            inputEl.setSelectionRange(0, inputEl.value.length);
            document.execCommand('copy');
            inputEl.type = prevType;
            inputEl.blur();
            if (typeof done === 'function') { done(); }
        } catch (err) {}
    }
});
