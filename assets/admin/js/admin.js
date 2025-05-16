jQuery(function($) {
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
});
