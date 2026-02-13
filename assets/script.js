/* ==========================================
   Waypoint Documentation — Scripts
   ========================================== */

document.addEventListener('DOMContentLoaded', () => {

    /* ---------- Syntax Highlighting ---------- */
    hljs.highlightAll();

    /* ---------- Mobile Sidebar ---------- */
    const sidebar      = document.getElementById('sidebar');
    const hamburger    = document.getElementById('hamburger');
    const sidebarClose = document.getElementById('sidebar-close');
    const overlay      = document.getElementById('overlay');

    function openSidebar() {
        sidebar.classList.add('open');
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {
        sidebar.classList.remove('open');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    }

    hamburger.addEventListener('click', openSidebar);
    sidebarClose.addEventListener('click', closeSidebar);
    overlay.addEventListener('click', closeSidebar);

    /* Close sidebar on nav link click (mobile) */
    sidebar.querySelectorAll('a[href^="#"]').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 768) {
                closeSidebar();
            }
        });
    });

    /* ---------- Active Nav Tracking ---------- */
    const sections = document.querySelectorAll('.doc-section, .hero');
    const navLinks = document.querySelectorAll('.sidebar-nav a');

    const observerOptions = {
        root: null,
        rootMargin: '-80px 0px -60% 0px',
        threshold: 0
    };

    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const id = entry.target.id;
                navLinks.forEach(link => {
                    link.classList.toggle('active', link.getAttribute('href') === `#${id}`);
                });
            }
        });
    }, observerOptions);

    sections.forEach(section => {
        if (section.id) observer.observe(section);
    });

    /* ---------- Copy Buttons ---------- */
    document.querySelectorAll('.copy-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const text = btn.dataset.copy;
            try {
                await navigator.clipboard.writeText(text);
                btn.classList.add('copied');
                btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>';
                setTimeout(() => {
                    btn.classList.remove('copied');
                    btn.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>';
                }, 2000);
            } catch {
                /* Fallback: select the text */
                const range = document.createRange();
                const sel = window.getSelection();
                const node = btn.previousElementSibling;
                range.selectNodeContents(node);
                sel.removeAllRanges();
                sel.addRange(range);
            }
        });
    });

    /* ---------- Smooth scroll with offset ---------- */
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', e => {
            const target = document.querySelector(anchor.getAttribute('href'));
            if (target) {
                e.preventDefault();
                const offset = 80;
                const top = target.getBoundingClientRect().top + window.pageYOffset - offset;
                window.scrollTo({ top, behavior: 'smooth' });
                history.pushState(null, '', anchor.getAttribute('href'));
            }
        });
    });
});
