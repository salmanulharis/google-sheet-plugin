(function($) {
    'use strict';

    $(document).ready(function() {
        // Generate secret key functionality
        $('#generate-secret-key').on('click', function(e) {
            e.preventDefault();
            
            // Generate a random 32-character secret key
            const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
            let result = '';
            for (let i = 0; i < 32; i++) {
                result += chars.charAt(Math.floor(Math.random() * chars.length));
            }
            
            $('input[name="sheets_api_options[secret_key]"]').val(result);
        });

        // Add any additional admin JavaScript here
    });

})(jQuery);