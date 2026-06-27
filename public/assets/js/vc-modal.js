(function () {
    'use strict';

    var MODAL_META = {
        add_contract: {
            title: 'إضافة عقد جديد',
            subtitle: 'أكمل الخطوات الست ثم احفظ العقد — المورد، البنود، والملاحظات',
            icon: 'ri-file-add-line'
        },
        add_items: {
            title: 'إضافة أصناف',
            subtitle: 'اختر المورد وأدخل الأصناف والأسعار بدقة',
            icon: 'ri-add-box-line'
        },
        add_payment_request: {
            title: 'طلب سداد جديد',
            subtitle: 'أدخل بيانات السداد والمرفقات ثم أرسل للاعتماد',
            icon: 'ri-bank-card-2-line'
        },
        rents: {
            title: 'إضافة عقد إيجار',
            subtitle: 'سجّل بيانات الإيجار والفروع المرتبطة',
            icon: 'ri-building-2-line'
        }
    };

    function parsePageHref(href) {
        if (!href || href.indexOf('javascript:') === 0 || href.charAt(0) === '#') {
            return null;
        }
        var url;
        try {
            url = new URL(href, window.location.href);
        } catch (e) {
            return null;
        }
        var match = url.pathname.match(/\/([A-Za-z0-9_]+)\.php$/);
        if (!match) {
            return null;
        }
        return {
            page: match[1],
            url: url,
            hasId: url.searchParams.has('id')
        };
    }

    function shouldOpenModal(link, parsed) {
        if (!parsed) {
            return false;
        }
        if (link.dataset.vcModal === '0') {
            return false;
        }
        if (link.dataset.vcModal === '1') {
            return true;
        }
        if (!Object.prototype.hasOwnProperty.call(MODAL_META, parsed.page)) {
            return false;
        }
        if (parsed.hasId) {
            return false;
        }
        if (link.target === '_blank') {
            return false;
        }
        return true;
    }

    function getOverlay() {
        return document.getElementById('vcModalOverlay');
    }

    function getFrame() {
        return document.getElementById('vcModalFrame');
    }

    function getLoading() {
        return document.getElementById('vcModalLoading');
    }

    function setModalChrome(parsed, titleOverride) {
        var meta = parsed && MODAL_META[parsed.page] ? MODAL_META[parsed.page] : null;
        var titleEl = document.getElementById('vcModalTitleText');
        var subtitleEl = document.getElementById('vcModalSubtitleText');
        var iconEl = document.getElementById('vcModalBadgeIcon');

        if (titleEl) {
            titleEl.textContent = titleOverride || (meta ? meta.title : 'نموذج');
        }
        if (subtitleEl) {
            subtitleEl.textContent = meta ? meta.subtitle : 'املأ الحقول المطلوبة ثم احفظ';
        }
        if (iconEl && meta) {
            iconEl.className = meta.icon;
        }
    }

    window.openVcModal = function (href, title) {
        var overlay = getOverlay();
        var frame = getFrame();
        var loading = getLoading();
        if (!overlay || !frame) {
            window.location.href = href;
            return;
        }

        var parsed = parsePageHref(href);
        var modalUrl = href;
        if (parsed) {
            parsed.url.searchParams.set('embed', '1');
            modalUrl = parsed.url.pathname.split('/').pop() + parsed.url.search;
        }

        setModalChrome(parsed, title);

        if (loading) {
            loading.classList.remove('is-hidden');
        }

        overlay.classList.add('is-open');
        document.body.style.overflow = 'hidden';
        frame.src = modalUrl;
    };

    window.closeVcModal = function (reloadParent) {
        var overlay = getOverlay();
        var frame = getFrame();
        if (!overlay) {
            return;
        }
        overlay.classList.remove('is-open');
        document.body.style.overflow = '';
        if (frame) {
            frame.src = 'about:blank';
        }
        if (reloadParent) {
            window.location.reload();
        }
    };

    function onFrameLoad() {
        var frame = getFrame();
        var loading = getLoading();
        if (loading) {
            loading.classList.add('is-hidden');
        }
        if (!frame || !frame.contentWindow) {
            return;
        }
        try {
            var loc = frame.contentWindow.location.href;
            if (/[?&](success|saved)=1/.test(loc)) {
                setTimeout(function () {
                    closeVcModal(true);
                }, 1200);
            }
        } catch (e) {
            /* cross-origin */
        }
    }

    document.addEventListener('click', function (e) {
        var link = e.target.closest('a[href]');
        if (!link) {
            return;
        }
        var parsed = parsePageHref(link.getAttribute('href'));
        if (!shouldOpenModal(link, parsed)) {
            return;
        }
        e.preventDefault();
        openVcModal(link.getAttribute('href'), link.getAttribute('data-vc-title') || link.textContent.trim());
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            var overlay = getOverlay();
            if (overlay && overlay.classList.contains('is-open')) {
                closeVcModal(false);
            }
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        var frame = getFrame();
        if (frame) {
            frame.addEventListener('load', onFrameLoad);
        }
    });
})();
