/* Maho_Ai — adminhtml JS */

mahoOnReady(function () {
    // Confirm-on-click for forms that opt in via data-maho-confirm
    document.querySelectorAll('[data-maho-confirm]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            if (!window.confirm(el.dataset.mahoConfirm)) {
                e.preventDefault();
            }
        });
    });

    // Fetch-models buttons in system config
    document.querySelectorAll('[data-maho-ai-fetch-models]').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const targetId = btn.dataset.target;
            const url      = btn.dataset.url;
            if (!targetId || !url) {
                return;
            }

            const orig = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = 'Fetching\u2026';

            try {
                const data = await mahoFetch(url, { method: 'POST', loaderArea: false });
                if (data.error) {
                    alert('Error: ' + data.error);
                    return;
                }

                let el = document.getElementById(targetId);
                if (!el) return;
                const current = el.value;

                // If the element is a text input, replace with a select
                if (el.tagName === 'INPUT') {
                    const sel = document.createElement('select');
                    sel.id        = el.id;
                    sel.name      = el.name;
                    sel.className = el.className;
                    sel.style.cssText = el.style.cssText;
                    el.parentNode.replaceChild(sel, el);
                    el = sel;
                }

                el.innerHTML = '';
                (data.models || []).forEach(function (m) {
                    const opt = document.createElement('option');
                    opt.value = m.value;
                    opt.text  = m.label;
                    if (m.value === current) opt.selected = true;
                    el.appendChild(opt);
                });
                if (current && !el.querySelector('option[selected]')) {
                    el.value = current;
                }
            } catch (err) {
                alert('Error: ' + (err && err.message ? err.message : err));
            } finally {
                btn.disabled = false;
                btn.innerHTML = orig;
            }
        });
    });
});
