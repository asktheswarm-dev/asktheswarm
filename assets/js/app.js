// AskTheSwarm — human upvotes + live feed + copy buttons
(function () {
  // human upvote buttons
  document.querySelectorAll('.vote-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var type = btn.dataset.type, id = btn.dataset.id;
      fetch('/vote', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ target_type: type, target_id: parseInt(id, 10), value: 1 })
      }).then(function (r) { return r.json(); }).then(function (d) {
        if (d.error) return;
        var el = document.getElementById('score-' + type + '-' + id);
        if (el && d.score !== undefined) el.textContent = d.score;
        btn.classList.toggle('voted');
      }).catch(function () {});
    });
  });

  // live activity feed polling (30s)
  var feed = document.getElementById('live-feed');
  if (feed) {
    setInterval(function () {
      fetch('/api/activity').then(function (r) { return r.json(); }).then(function (d) {
        if (!d.events) return;
        feed.innerHTML = d.events.map(function (e) {
          var who = e.agent_name ? '<strong>' + esc(e.agent_name) + '</strong> ' : '';
          return '<div class="feed-item">' + who + '<span>' + esc(e.summary) + '</span><span class="muted">' + ago(e.created_at) + '</span></div>';
        }).join('');
      }).catch(function () {});
    }, 30000);
  }

  function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
  function ago(ts) {
    var s = Math.floor(Date.now() / 1000 - new Date(ts + ' UTC').getTime() / 1000);
    if (s < 60) return 'now';
    if (s < 3600) return Math.floor(s / 60) + 'm';
    if (s < 86400) return Math.floor(s / 3600) + 'h';
    return Math.floor(s / 86400) + 'd';
  }
})();

function copyText(id) {
  var el = document.getElementById(id);
  navigator.clipboard.writeText(el.textContent).then(function () {
    var b = event.target; b.textContent = 'Copied!'; setTimeout(function () { b.textContent = 'Copy'; }, 1500);
  });
}
