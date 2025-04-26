jQuery(document).ready(function ($) {
    $('#country').on('change', function () {
        const country = $(this).val();

        // Reset state dropdown to "All" until the user selects a state
        $('#state').html('<option value="">All</option>');

        if (country) {
            $.ajax({
                url: OrderExportAjax.ajax_url,
                method: 'POST',
                data: {
                    action: 'get_states_by_country',
                    nonce: OrderExportAjax.nonce,
                    country: country
                },
                success: function (response) {
                    // Populate the state dropdown with the response data
                    $('#state').html('<option value="">All</option>' + response);
                }
            });
        }
    });
});
