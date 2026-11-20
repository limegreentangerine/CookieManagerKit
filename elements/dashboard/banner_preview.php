<?php defined('C5_EXECUTE') or die('Access Denied.');

use CookieManagerKit\Support\Output as O;
use CookieManagerKit\Support\{BannerIcon, Catalog};

/** @var CookieManagerKit\Entity\Settings $settings */
/** @var CookieManagerKit\Entity\BannerContent|null $banner */

$defaults = Catalog::DEFAULTS;
$text = static fn(string $value, string $fallback): string => $value !== '' ? $value : $fallback;
$title = $text($banner ? $banner->getTitle() : '', $defaults['title']);
$body = $text($banner ? $banner->getBody() : '', $defaults['body']);
$accept = $text($banner ? $banner->getAcceptAllLabel() : '', $defaults['acceptAll']);
$reject = $text($banner ? $banner->getRejectAllLabel() : '', $defaults['rejectAll']);
$customise = $text($banner ? $banner->getCustomiseLabel() : '', $defaults['customise']);
$widget = $text($banner ? $banner->getWidgetLabel() : '', $defaults['widget']);
$iconUrl = BannerIcon::url($settings->getIconFileId());
$tokens = $settings->getTokens();
$display = $iconUrl !== '' ? $settings->getWidgetDisplay() : 'text';
// cmk-dashboard.js sets the colours on load, dropping unreadable pairs the same way the live banner does.
$style = '--cmk-radius: ' . $settings->getRadius() . 'px';
// %s is replaced with the measured contrast ratio by cmk-dashboard.js; the kept wording is used when the
// "use my colours anyway" setting is on.
$kept = [
    'text' => t('Text on the background has a contrast of %s:1, below the 4.5:1 accessibility minimum. These colours are used anyway, so some visitors may struggle to read the banner.'),
    'button' => t('Button text on the button background has a contrast of %s:1, below the 4.5:1 accessibility minimum. These colours are used anyway, so some visitors may struggle to read the buttons.'),
    'widget' => t('The cookie settings button text on its background has a contrast of %s:1, below the 4.5:1 accessibility minimum. These colours are used anyway, so some visitors may struggle to read it.'),
    'accent' => t('The accent colour on the background has a contrast of %s:1, below the 4.5:1 accessibility minimum. It is used anyway, so some visitors may struggle to see links and the keyboard focus outline.'),
];
$warnings = [
    'text' => t('Text on the background has a contrast of %s:1. The live banner needs at least 4.5:1, so it uses the default text and background colours instead.'),
    'button' => t('Button text on the button background has a contrast of %s:1. The live banner needs at least 4.5:1, so it uses the default button colours instead.'),
    'widget' => t('The cookie settings button text on its background has a contrast of %s:1. The live button needs at least 4.5:1, so it uses the default button colours instead.'),
    'accent' => t('The accent colour on the background has a contrast of %s:1. Links and focus outlines need at least 4.5:1, so the live banner uses the default accent instead.'),
];
?>
<div class="alert alert-warning" data-cmk-contrast hidden>
    <ul class="mb-0">
        <?php foreach ($warnings as $pair => $message) { ?>
            <li data-cmk-contrast-pair="<?php echo O::e($pair) ?>" data-message="<?php echo O::e($message) ?>" data-kept="<?php echo O::e($kept[$pair]) ?>" hidden></li>
        <?php } ?>
    </ul>
</div>
<div class="cmk-preview-stage">
    <div class="cmk" data-cmk-preview data-position="<?php echo O::e($settings->getPosition()) ?>" data-layout="<?php echo O::e($settings->getLayout()) ?>" data-font="<?php echo O::e($settings->getFont()) ?>" data-shadow="<?php echo O::e($settings->hasShadow() ? 'on' : 'off') ?>" style="<?php echo O::e($style) ?>">
        <div class="cmk__panel">
            <div class="cmk__header">
                <img class="cmk__icon" data-cmk-preview-icon src="<?php echo O::e($iconUrl) ?>" alt="" <?php echo O::e($iconUrl === '' ? 'hidden' : '') ?>>
                <h2 class="cmk__title"><?php echo O::e($title) ?></h2>
            </div>
            <p class="cmk__body">
                <?php foreach (explode("\n", $body) as $line => $part) { ?>
                    <?php if ($line > 0) { ?><br><?php } ?>
                    <?php echo O::e($part) ?>
                <?php } ?>
            </p>
            <div class="cmk__actions">
                <button type="button" class="cmk__btn cmk__btn--primary"><?php echo O::e($accept) ?></button>
                <button type="button" class="cmk__btn"><?php echo O::e($reject) ?></button>
                <button type="button" class="cmk__btn cmk__btn--link"><?php echo O::e($customise) ?></button>
            </div>
        </div>
    </div>
    <button type="button" class="cmk-widget" data-cmk-preview-widget data-display="<?php echo O::e($display) ?>" style="<?php echo O::e($style) ?>">
        <img class="cmk-widget__icon" src="<?php echo O::e($iconUrl) ?>" alt="" <?php echo O::e($display === 'text' ? 'hidden' : '') ?>>
        <svg class="cmk-widget__tint" aria-hidden="true" focusable="false"><filter id="cmk-widget-tint" color-interpolation-filters="sRGB"><feFlood></feFlood><feComposite in2="SourceAlpha" operator="in"></feComposite></filter></svg>
        <span <?php echo O::e($display === 'icon' ? 'hidden' : '') ?>><?php echo O::e($widget) ?></span>
    </button>
</div>
