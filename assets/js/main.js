// assets/js/main.js
document.addEventListener('DOMContentLoaded', function() {
    
    // Mobile Sidebar Toggle
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    
    if (sidebar) {
        // Create overlay if not exists
        let overlay = document.querySelector('.sidebar-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'sidebar-overlay';
            sidebar.parentNode.insertBefore(overlay, sidebar.nextSibling);
        }
        
        if (menuToggle) {
            menuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('open');
            });
        }
        
        overlay.addEventListener('click', function() {
            sidebar.classList.remove('open');
        });
        
        // Close sidebar btn if exists
        const closeSidebar = document.getElementById('closeSidebar');
        if (closeSidebar) {
            closeSidebar.addEventListener('click', function() {
                sidebar.classList.remove('open');
            });
        }

        // ==========================================
        // Mobile Swipe Gestures (Snap to Open/Close)
        // ==========================================
        let touchStartX = 0;
        let touchStartY = 0;
        let touchEndX = 0;
        let touchEndY = 0;
        let isTrackingSwipe = false;

        document.addEventListener('touchstart', function(e) {
            if (e.touches.length !== 1) return; // Ignore multi-touch
            
            touchStartX = e.touches[0].clientX;
            touchStartY = e.touches[0].clientY;
            touchEndX = touchStartX;
            touchEndY = touchStartY;

            const isSidebarOpen = sidebar.classList.contains('open');

            // Start tracking if touching near left edge (when closed) or anywhere on sidebar/overlay (when open)
            if (!isSidebarOpen) {
                // Zone: 0px to 45px from left edge
                if (touchStartX <= 45) {
                    isTrackingSwipe = true;
                } else {
                    isTrackingSwipe = false;
                }
            } else {
                isTrackingSwipe = true;
            }
        }, { passive: true });

        document.addEventListener('touchmove', function(e) {
            if (!isTrackingSwipe || e.touches.length !== 1) return;
            touchEndX = e.touches[0].clientX;
            touchEndY = e.touches[0].clientY;
        }, { passive: true });

        document.addEventListener('touchend', function(e) {
            if (!isTrackingSwipe) return;
            isTrackingSwipe = false;

            const diffX = touchEndX - touchStartX;
            const diffY = touchEndY - touchStartY;
            const absX = Math.abs(diffX);
            const absY = Math.abs(diffY);

            // Require horizontal intent: horizontal movement must be significantly greater than vertical movement
            if (absX > 45 && absX > absY * 1.25) {
                const isSidebarOpen = sidebar.classList.contains('open');

                if (!isSidebarOpen && diffX > 0 && touchStartX <= 45) {
                    // Swiped Right from left edge -> OPEN
                    sidebar.classList.add('open');
                } else if (isSidebarOpen && diffX < 0) {
                    // Swiped Left -> CLOSE
                    sidebar.classList.remove('open');
                }
            }
        }, { passive: true });
    }
    
});

// =========================================================================
// Global Smart Scroll Position Restoration (ระบบจำตำแหน่งหน้าจออัตโนมัติ)
// =========================================================================
(function() {
    // 1. Restore Scroll Position when returning to the same page/action
    function restoreScroll() {
        try {
            const savedPath = sessionStorage.getItem('restore_scroll_path');
            const savedPos = sessionStorage.getItem('restore_scroll_pos');
            sessionStorage.removeItem('_disable_scroll_restore');

            if (savedPath && savedPath === window.location.pathname && savedPos !== null) {
                const targetY = parseInt(savedPos, 10);
                if (!isNaN(targetY) && targetY > 0) {
                    window.scrollTo({ top: targetY, behavior: 'instant' });

                    // Micro-retries to handle dynamic table/chart sizing & rendering
                    requestAnimationFrame(() => {
                        window.scrollTo({ top: targetY, behavior: 'instant' });
                    });
                    setTimeout(() => {
                        window.scrollTo({ top: targetY, behavior: 'instant' });
                    }, 50);
                    setTimeout(() => {
                        window.scrollTo({ top: targetY, behavior: 'instant' });
                    }, 150);
                    setTimeout(() => {
                        window.scrollTo({ top: targetY, behavior: 'instant' });
                    }, 300);
                }
            }
            // Clear stored values
            sessionStorage.removeItem('restore_scroll_pos');
            sessionStorage.removeItem('restore_scroll_path');
        } catch (e) {}
    }

    // Try early restore
    restoreScroll();
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', restoreScroll);
    }
    window.addEventListener('load', restoreScroll);

    // 2. Helper to Save Scroll Position
    function saveScroll() {
        try {
            sessionStorage.setItem('restore_scroll_pos', window.scrollY.toString());
            sessionStorage.setItem('restore_scroll_path', window.location.pathname);
            sessionStorage.removeItem('_disable_scroll_restore');
        } catch (e) {}
    }

    // 3. Save scroll on all form submissions (e.g. Filters, Delete/Restore points, Actions)
    document.addEventListener('submit', function() {
        saveScroll();
    }, true);

    // 4. Save scroll when form.submit() is called programmatically (e.g. onchange="this.form.submit()")
    if (typeof HTMLFormElement !== 'undefined') {
        const origSubmit = HTMLFormElement.prototype.submit;
        HTMLFormElement.prototype.submit = function() {
            saveScroll();
            return origSubmit.apply(this, arguments);
        };
    }

    // 5. Detect Click on Links & Buttons (Haptic + Scroll)
    document.addEventListener('click', function(e) {
        // Haptic feedback for app-like feel
        if (navigator.vibrate) {
            const tapTarget = e.target.closest('a, button, .btn');
            if (tapTarget) {
                // Short subtle vibration (Android only)
                navigator.vibrate(15); 
            }
        }
        
        const link = e.target.closest('a');
        if (!link || !link.href) return;

        try {
            const targetUrl = new URL(link.href, window.location.origin);
            // If navigating to a DIFFERENT page, clear scroll restore so user starts at top of new page
            if (targetUrl.pathname !== window.location.pathname) {
                sessionStorage.setItem('_disable_scroll_restore', '1');
                sessionStorage.removeItem('restore_scroll_pos');
                sessionStorage.removeItem('restore_scroll_path');
            } else {
                // If staying on the same page (e.g. filter query param or reload link), remember scroll
                saveScroll();
            }
        } catch (err) {}
    }, true);

    // 6. Window beforeunload backup
    window.addEventListener('beforeunload', function() {
        try {
            if (!sessionStorage.getItem('_disable_scroll_restore')) {
                saveScroll();
            }
        } catch (e) {}
    });
})();

// =========================================================================
// PWA Service Worker Registration
// =========================================================================
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        // Find base_url equivalent from current path to register SW at root
        const basePath = window.location.pathname.startsWith('/TDJSB') ? '/TDJSB' :
                         window.location.pathname.startsWith('/tdjsb') ? '/tdjsb' :
                         window.location.pathname.startsWith('/TeeDiewJob') ? '/TeeDiewJob' : '';
        navigator.serviceWorker.register(basePath + '/sw.js')
            .then(registration => {
                console.log('[Service Worker] Registered with scope:', registration.scope);
            })
            .catch(err => {
                console.error('[Service Worker] Registration failed:', err);
            });
    });
}
