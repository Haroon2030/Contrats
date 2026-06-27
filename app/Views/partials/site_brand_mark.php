<?php
/**
 * شعار الموقع الموحّد (موردون وعقود)
 *
 * @var int $brand_size حجم الصورة بالبكسل (افتراضي 42)
 * @var string $brand_class صنف CSS إضافي
 */
$brand_size = isset($brand_size) ? max(24, (int) $brand_size) : 42;
$brand_class = isset($brand_class) ? (string) $brand_class : '';
$logo_alt = $logo_alt ?? 'نظام إدارة العقود والإيجارات';

$e = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>
<span class="sidebar-brand-icon vc-site-brand-mark <?= $e(trim($brand_class)) ?>" aria-hidden="true">
    <img
        src="<?= $e(vcSiteLogoUrl()) ?>"
        alt="<?= $e($logo_alt) ?>"
        width="<?= $brand_size ?>"
        height="<?= $brand_size ?>"
        decoding="async"
    >
</span>
