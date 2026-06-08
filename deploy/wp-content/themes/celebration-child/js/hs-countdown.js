/**
 * hs-countdown.js — HitchStream wedding-page countdown.
 *
 * Drives the `.hs_CountdownHolder` markup the wedding templates emit. Moved out
 * of the parent Bold Themes library (bt_elements.js) into the child theme so it
 * lives in the deploy pipeline and survives parent updates. Behaviour is a
 * faithful port of the original block — same flip animation, same day handling,
 * and the same end-of-countdown swap that reveals #hs_player.
 *
 * Accuracy: instead of decrementing a counter once per timer tick (which falls
 * far behind when a tab is backgrounded and browsers throttle timers), it anchors
 * to an absolute target ONCE and recomputes the remaining time from the wall
 * clock on every tick — so it can't drift, and on tab refocus it snaps to the
 * correct value immediately.
 *
 * Sibling wiring this MUST preserve (do not change without checking those files):
 *   - reads   #EventStatus   (written by status.js every 60s)
 *   - toggles #hs_player      (also hidden by ended.js when the event is complete)
 *   - toggles #hs_counter / #hs_beforetextcont / #hs_aftertextcont
 */
(function () {
  'use strict';

  var $ = window.jQuery;
  if (!$) { return; } // jQuery is enqueued as a dependency; bail safely if absent.

  /** Set display on an element by id, only if it's present (avoids a hard throw). */
  function setDisplay(id, value) {
    var el = document.getElementById(id);
    if (el) { el.style.display = value; }
  }

  function initCountdown(holder) {
    var cd = $(holder);
    var countdownfinished = false;

    // Anchor ONCE to an absolute target moment; every tick recomputes from the
    // wall clock (see output()), so the countdown can't drift or fall behind.
    var endAt = Date.now() + (cd.data('init-seconds') * 1000);
    var s;

    var seconds_arr = ['', ''], seconds_arr_prev = ['', ''];
    var minutes_arr = ['', ''], minutes_arr_prev = ['', ''];
    var hours_arr = ['', ''], hours_arr_prev = ['', ''];
    var days_prev = 0;

    // Flip a single digit group, animating only when its value changed.
    function flip(selector, i, arr, arr_prev) {
      if (arr[i] === arr_prev[i]) { return; }
      var children = cd.find(selector).children();
      children.addClass('countdown_anim');
      children.eq(0).html(arr[i]);
      children.eq(1).html(arr_prev[i]);
      setTimeout(function () {
        children.eq(1).html(children.eq(0).html());
        children.removeClass('countdown_anim');
      }, 300);
    }

    // Counting-down vs event-reached layout. At zero this reveals #hs_player (the
    // live stream) unless the event is already marked complete — the exact swap
    // the wedding pages, status.js and ended.js depend on.
    function applyState() {
      var es = document.getElementById('EventStatus');
      var status = es ? es.textContent.trim() : '';
      if (countdownfinished) {
        setDisplay('hs_player', status !== 'complete' ? 'block' : 'none');
        setDisplay('hs_counter', 'none');
        setDisplay('hs_beforetextcont', 'none');
        setDisplay('hs_aftertextcont', 'block');
      } else {
        setDisplay('hs_player', 'none');
        setDisplay('hs_counter', 'inline-block');
        setDisplay('hs_beforetextcont', 'block');
        setDisplay('hs_aftertextcont', 'none');
      }
    }

    function output() {
      s = Math.round((endAt - Date.now()) / 1000);
      if (s <= 0) { s = 0; countdownfinished = true; }

      applyState();

      var delta = s;
      var days = Math.floor(delta / 86400); delta -= days * 86400;
      var hours = Math.floor(delta / 3600) % 24; delta -= hours * 3600;
      var minutes = Math.floor(delta / 60) % 60; delta -= minutes * 60;
      var seconds = delta;

      if (hours < 10) { hours = '0' + hours; }
      if (minutes < 10) { minutes = '0' + minutes; }
      if (seconds < 10) { seconds = '0' + seconds; }

      seconds_arr = seconds.toString().split('');
      minutes_arr = minutes.toString().split('');
      hours_arr = hours.toString().split('');

      for (var i = 0; i <= 1; i++) {
        flip('.hs_seconds .n' + i, i, seconds_arr, seconds_arr_prev);
        flip('.hs_minutes .n' + i, i, minutes_arr, minutes_arr_prev);
        flip('.hs_hours .n' + i, i, hours_arr, hours_arr_prev);
      }

      if (days !== days_prev) {
        var days_arr = days.toString().split('');
        var days_html = '<div class="hs_countdown_numbers">';
        for (var j = 0; j < days_arr.length; j++) {
          days_html += '<span><span>' + days_arr[j] + '</span></span>';
        }
        days_html += '</div>';
        cd.find('.hs_days').html(
          days_html +
          '<div class="hs_days_text"><div class="Seperator-wrapper"><div class="hs_Seperator"></div></div><span>' +
          cd.find('.hs_days').data('text') + '</span></div>'
        );
      }

      if (days < 1) {
        var daysEl = document.getElementsByClassName('hs_days')[0];
        if (daysEl) { daysEl.style.display = 'none'; }
      }

      days_prev = days;

      // Defer recording the "previous" digits so the flip animation has its frame.
      setTimeout(function () {
        seconds_arr_prev = seconds_arr;
        minutes_arr_prev = minutes_arr;
        hours_arr_prev = hours_arr;
      }, 400);
    }

    // Drift-free scheduler: re-read the clock each tick, align to the next whole
    // second, and stop once finished. Throttled/paused background timers can no
    // longer make the value inaccurate, because it's derived from the clock.
    function loop() {
      output();
      if (!countdownfinished) {
        setTimeout(loop, 1000 - (Date.now() % 1000));
      }
    }
    loop();

    // Snap to the correct time the instant the tab is refocused.
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden && !countdownfinished) { output(); }
    });
  }

  $(function () {
    $('.hs_CountdownHolder').each(function () { initCountdown(this); });
  });
})();
