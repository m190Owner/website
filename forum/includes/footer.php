    <div style="text-align:center; padding:30px 0 20px; font-size:0.68rem; color:#3a4060; border-top:1px solid rgba(122,162,255,0.04); margin-top:40px;">
        &copy; <?= date('Y') ?> m190 &middot; Invite Only
    </div>
<script>
// Delegated event handlers — no inline onclick needed
document.addEventListener('click', function(e) {
    // Spoiler reveal
    if (e.target.closest('[data-spoiler]')) {
        e.target.closest('[data-spoiler]').classList.toggle('revealed');
    }
    // Image expand
    if (e.target.matches('[data-expandable]')) {
        window.open(e.target.src);
    }
});
</script>
<?php if (isLoggedIn()): ?>
<script>
function toggleNotifs(e) {
    e.preventDefault();
    var dd = document.getElementById('notif-dropdown');
    if (dd.classList.contains('show')) { dd.classList.remove('show'); return; }
    dd.innerHTML = '<div style="padding:14px;text-align:center;color:#5a6480;font-size:0.75rem;">Loading...</div>';
    dd.classList.add('show');
    fetch('/forum/api.php?action=notifications_fetch')
        .then(function(r){return r.json()})
        .then(function(data) {
            if (!data.ok || !data.notifications.length) {
                dd.innerHTML = '<div style="padding:14px;text-align:center;color:#5a6480;font-size:0.75rem;">No notifications</div>';
                return;
            }
            var html = '';
            data.notifications.forEach(function(n) {
                var cls = n.read ? '' : ' unread';
                var msg = '';
                if (n.type === 'mention') msg = '<strong style="color:#e5e5e5;">' + escH(n.from) + '</strong> mentioned you in <a href="/forum/thread.php?id=' + encodeURIComponent(n.thread_id) + '#post-' + encodeURIComponent(n.post_id) + '">' + escH(n.thread_title) + '</a>';
                else if (n.type === 'bounty_submission') msg = '<strong style="color:#e5e5e5;">' + escH(n.from) + '</strong> submitted to your bounty <a href="/forum/bounty.php?id=' + encodeURIComponent(n.bounty_id) + '">' + escH(n.bounty_title || 'bounty') + '</a>';
                else if (n.type === 'bounty_awarded') msg = '<span style="color:#ffb86b;">+' + escH(n.reward) + ' rep</span> &mdash; bounty won: <a href="/forum/bounty.php?id=' + encodeURIComponent(n.bounty_id) + '">' + escH(n.bounty_title || '') + '</a>';
                else if (n.type === 'dead_drop') msg = '<span style="color:#ff6b6b;">&#128274; classified drop</span> &mdash; <a href="/forum/deaddrops.php">check inbox</a>';
                else msg = escH(n.type);
                html += '<div class="notif-item' + cls + '">' + msg + '<div class="notif-time">' + escH(n.created) + '</div></div>';
            });
            dd.innerHTML = html;
            // Mark as read
            fetch('/forum/api.php?action=notifications_read');
            var badge = document.querySelector('.notif-count');
            if (badge) badge.remove();
        });
}
function escH(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
document.addEventListener('click', function(e) {
    var dd = document.getElementById('notif-dropdown');
    if (dd && !e.target.closest('.notif-bell') && !e.target.closest('.notif-dropdown')) dd.classList.remove('show');
});
</script>
<?php endif; ?>
</body>
</html>
