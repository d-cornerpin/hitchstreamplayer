function updateEventStatus() {
  if (typeof jsData !== "undefined") {
    jQuery.ajax({
      url: jsData.ajaxurl,
      data: {
        action: 'get_status_ajax_',
        post_id: jsData.post_id
      },
      method: 'POST',
      success: function (IsComplete) {
        var eventStatusElement = document.getElementById('EventStatus');
        if (eventStatusElement) {
          eventStatusElement.innerHTML = IsComplete;
        }
      }
    });
  }
}

if (typeof jsData !== "undefined") {
  setInterval(updateEventStatus, 60000);
}
