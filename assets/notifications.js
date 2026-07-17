// assets/notifications.js
//
// Toast notification polling, shared by header.php and
// includes/embedded_header.php (both call initPolarisNotifications() -
// previously this lived only in header.php, so pages using the
// embedded-iframe shell chrome (Case Management/System Management/Asset
// Management's nav+iframe pages) never polled at all and could sit on an
// assigned-task notification indefinitely.
//
// Guarded to only run when this document is the top-level window: the
// three dashboard shells (ch_dashboard.php etc.) already poll from their
// own top-level header.php, and every embedded page loads inside that
// same shell's iframe - polling again from inside the iframe would just
// show every toast twice. A page using the embedded chrome outside an
// iframe (e.g. opened directly) still polls fine, since window.top ===
// window.self holds true there.
//
// Also polls immediately whenever the tab regains visibility - browsers
// throttle background-tab timers, so a notification created while the tab
// was in the background could otherwise sit unseen well past the normal
// poll interval until something else (like the tab becoming active again)
// triggered a check.
function initPolarisNotifications(seenKey) {
    if (window.top !== window.self) {
        return;
    }

    var seen = [];
    try {
        seen = JSON.parse(localStorage.getItem(seenKey) || '[]');
    } catch (e) {
        seen = [];
    }

    function showToast(message) {
        var container = document.getElementById('toast-container');
        if (!container) {
            return;
        }
        var el = document.createElement('div');
        el.className = 'polaris-toast';
        el.textContent = message;
        container.appendChild(el);
        setTimeout(function() {
            el.style.opacity = '0';
            setTimeout(function() {
                el.remove();
            }, 500);
        }, 10000);
    }

    function poll() {
        fetch('/notifications_poll.php')
            .then(function(r) {
                return r.json();
            })
            .then(function(list) {
                list.forEach(function(n) {
                    if (seen.indexOf(n.id) === -1) {
                        showToast(n.message);
                        seen.push(n.id);
                    }
                });
                if (seen.length > 200) {
                    seen = seen.slice(-200);
                }
                localStorage.setItem(seenKey, JSON.stringify(seen));
            })
            .catch(function() {
                // Transient network/poll failure - just try again next interval.
            });
    }

    poll();
    setInterval(poll, 8000);
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            poll();
        }
    });
}
