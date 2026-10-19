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
$iconUrl = BannerIcon::url($settings->getIconFileId());
$tokens = $settings->getTokens();
$style = '--cmk-bg: ' . $tokens['bg'] . '; --cmk-fg: ' . $tokens['fg'] . '; --cmk-accent: ' . $tokens['accent']
    . '; --cmk-button-bg: ' . $tokens['buttonBg'] . '; --cmk-button-fg: ' . $tokens['buttonFg'] . '; --cmk-radius: ' . $settings->getRadius() . 'px';
?>
<div class="cmk-preview-stage">
    <div class="cmk" data-cmk-preview data-position="<?php echo O::e($settings->getPosition()) ?>" data-layout="<?php echo O::e($settings->getLayout()) ?>" data-font="<?php echo O::e($settings->getFont()) ?>" data-shadow="<?php echo O::e($settings->hasShadow() ? 'on' : 'off') ?>" style="<?php echo O::e($style) ?>">
        <div class="cmk__panel">
            <div class="cmk__header">
                <?php if ($iconUrl !== '') { ?><img class="cmk__icon" src="<?php echo O::e($iconUrl) ?>" alt=""><?php } ?>
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
</div>
