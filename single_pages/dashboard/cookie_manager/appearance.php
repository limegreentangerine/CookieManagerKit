<?php defined('C5_EXECUTE') or die('Access Denied.');

use Concrete\Core\View\View;
use CookieManagerKit\Support\Output as O;

/** @var CookieManagerKit\Entity\Settings $settings */
/** @var CookieManagerKit\Entity\BannerContent|null $banner */
/** @var Concrete\Core\Entity\File\File|null $iconFile */

$positions = [
    'bottom-right' => t('Bottom right'),
    'bottom-left' => t('Bottom left'),
    'bottom-bar' => t('Bottom bar (full width)'),
    'center-modal' => t('Centre of the page'),
];
$layouts = ['stacked' => t('Buttons stacked'), 'inline' => t('Buttons in a row')];
$fonts = ['inherit' => t('Use the theme font'), 'system' => t('Use the system font')];
$fileSelector = app('helper/concrete/file_manager');
$colours = [
    'tokenBg' => [t('Background'), 'bg'],
    'tokenFg' => [t('Text'), 'fg'],
    'tokenAccent' => [t('Accent and links'), 'accent'],
    'tokenButtonBg' => [t('Button background'), 'buttonBg'],
    'tokenButtonFg' => [t('Button text'), 'buttonFg'],
];
?>
<div class="row">
    <div class="col-lg-5">
        <form method="post" action="<?php echo O::e($view->action('save')) ?>">
            <?php echo $token->output('submit') ?>

            <fieldset class="mb-4">
                <legend><?php echo t('Position and layout') ?></legend>

                <div class="form-group mb-3">
                    <label class="form-label" for="position"><?php echo t('Position') ?></label>
                    <select class="form-select" id="position" name="position">
                        <?php foreach ($positions as $value => $label) { ?>
                            <option value="<?php echo O::e($value) ?>" <?php echo O::e($settings->getPosition() === $value ? 'selected' : '') ?>><?php echo O::e($label) ?></option>
                        <?php } ?>
                    </select>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label" for="layout"><?php echo t('Buttons') ?></label>
                    <select class="form-select" id="layout" name="layout">
                        <?php foreach ($layouts as $value => $label) { ?>
                            <option value="<?php echo O::e($value) ?>" <?php echo O::e($settings->getLayout() === $value ? 'selected' : '') ?>><?php echo O::e($label) ?></option>
                        <?php } ?>
                    </select>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label" for="font"><?php echo t('Font') ?></label>
                    <select class="form-select" id="font" name="font">
                        <?php foreach ($fonts as $value => $label) { ?>
                            <option value="<?php echo O::e($value) ?>" <?php echo O::e($settings->getFont() === $value ? 'selected' : '') ?>><?php echo O::e($label) ?></option>
                        <?php } ?>
                    </select>
                </div>
            </fieldset>

            <fieldset class="mb-4">
                <legend><?php echo t('Icon') ?></legend>

                <div class="form-group mb-3">
                    <label class="form-label" for="iconFileId"><?php echo t('Icon next to the heading') ?></label>
                    <?php echo $fileSelector->image('cmk-icon', 'iconFileId', t('Choose icon'), $iconFile ?: null) ?>
                    <div class="form-text"><?php echo t('Optional. An image from the file manager, shown beside the banner heading. Leave empty for no icon.') ?></div>
                </div>
            </fieldset>

            <fieldset class="mb-4">
                <legend><?php echo t('Colours') ?></legend>

                <?php foreach ($colours as $id => [$label, $tokenName]) { ?>
                    <div class="form-group mb-3">
                        <label class="form-label" for="<?php echo O::e($id) ?>"><?php echo O::e($label) ?></label>
                        <input type="color" class="form-control form-control-color" id="<?php echo O::e($id) ?>" name="<?php echo O::e($id) ?>" value="<?php echo O::e($settings->getToken($tokenName)) ?>">
                    </div>
                <?php } ?>
            </fieldset>

            <fieldset class="mb-4">
                <legend><?php echo t('Shape') ?></legend>

                <div class="form-group mb-3">
                    <label class="form-label" for="radius"><?php echo t('Corner radius (px)') ?></label>
                    <input type="number" class="form-control" style="max-width: 10rem" id="radius" name="radius" min="0" max="32" step="1" value="<?php echo O::e($settings->getRadius()) ?>">
                </div>

                <div class="form-check mb-3">
                    <input type="checkbox" class="form-check-input" id="shadow" name="shadow" value="1" <?php echo O::e($settings->hasShadow() ? 'checked' : '') ?>>
                    <label class="form-check-label" for="shadow"><?php echo t('Drop shadow') ?></label>
                </div>
            </fieldset>

            <div class="ccm-dashboard-form-actions-wrapper">
                <div class="ccm-dashboard-form-actions">
                    <?php echo $form->submit('save', t('Save Appearance'), ['class' => 'btn btn-primary float-end']) ?>
                </div>
            </div>
        </form>
    </div>

    <div class="col-lg-7">
        <h5><?php echo t('Preview') ?></h5>
        <p class="text-muted"><?php echo t('Uses the default language. The real banner is built the same way and follows these settings after you save.') ?></p>
        <?php View::element('dashboard/banner_preview', ['settings' => $settings, 'banner' => $banner], 'cookie_manager_kit') ?>
    </div>
</div>
