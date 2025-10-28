(function ($) {
  $(function () {
    // Minimal niceties
    $('.wpaisb-card').on('submit', function () {
      $(this).find('button[type="submit"], .button.button-primary').prop('disabled', true).text('Working...');
    });

    // Provider-specific settings toggle
    function updateProviderVisibility() {
      var provider = $('#wpaisb-provider-select').val();
      var $groups = $('.wpaisb-provider-group');
      $groups.removeClass('is-active').hide();
      $groups.filter('[data-provider="' + provider + '"]').addClass('is-active').show();
    }
    $(document).on('change', '#wpaisb-provider-select', updateProviderVisibility);
    updateProviderVisibility();

    //CODE BY HUMAN
    const form = $('#wpaisb-generate-form');

    form.on('submit', function (e) {
      e.preventDefault();
      const formData = new FormData(this);
      formData.append('action', 'wpaisb_generate_page');
      formData.append('wpaisb_nonce', WPAISB.nonce2);
      // Optional: show loading state
      form.find('button').prop('disabled', true).text('Generating...');
      $.ajax({
        url: WPAISB.ajaxUrl,
        method: 'POST',
        data: formData,
        contentType: false,
        processData: false,
        success: function (res) {
          form.find('button').prop('disabled', false).text('Generate Page');
          $('.wpaisb-notice').remove();
          if (res.success) {
            const editUrl = res.data.edit_url || '#';
            const messageHtml = `
                <div class="notice notice-success is-dismissible wpaisb-notice">
                  <p><strong>${res.data.message}</strong></p>
                  <p><a href="${editUrl}" target="_blank" class="button button-primary">Edit Page</a></p>
                </div>
              `;
            $('#response-shown').html(messageHtml);
          } else {
            const messageHtml = `
                <div class="notice notice-error is-dismissible wpaisb-notice">
                  <p><strong>${res.data.message || 'Error occurred.'}</strong></p>
                </div>
              `;
            $('#response-shown').html(messageHtml);
          }
        },
        error: function () {
          form.find('button').prop('disabled', false).text('Generate Page');
          alert('Something went wrong.');
        },
      });
    });





  });
})(jQuery);
