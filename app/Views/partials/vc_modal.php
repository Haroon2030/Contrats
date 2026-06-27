<div class="vc-modal-overlay" id="vcModalOverlay" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="vcModalTitleText">
    <div class="vc-modal-dialog">
        <header class="vc-modal-header">
            <div class="vc-modal-header-start">
                <span class="vc-modal-header-badge" id="vcModalBadge" aria-hidden="true">
                    <i class="ri-file-add-line" id="vcModalBadgeIcon"></i>
                </span>
                <div class="vc-modal-header-text">
                    <h2 class="vc-modal-title" id="vcModalTitle">
                        <span id="vcModalTitleText">نموذج</span>
                    </h2>
                    <p class="vc-modal-subtitle" id="vcModalSubtitleText">املأ الحقول المطلوبة ثم احفظ</p>
                </div>
            </div>
            <div class="vc-modal-actions">
                <button type="button" class="vc-modal-btn" onclick="closeVcModal(false)" title="إغلاق" aria-label="إغلاق">
                    <i class="ri-close-line"></i>
                </button>
            </div>
        </header>
        <div class="vc-modal-body">
            <div class="vc-modal-loading" id="vcModalLoading">
                <i class="ri-loader-4-line"></i>
                <span>جاري التحميل...</span>
            </div>
            <iframe class="vc-modal-frame" id="vcModalFrame" title="نموذج الإضافة" loading="lazy"></iframe>
        </div>
    </div>
</div>
