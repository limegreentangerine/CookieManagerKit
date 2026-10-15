<?php defined('C5_EXECUTE') or die('Access Denied.');

use CookieManagerKit\Support\Catalog;
use CookieManagerKit\Support\Output as O;

/** @var array<string, string> $locales */
/** @var string $defaultLocale */
/** @var array<string, CookieManagerKit\Entity\BannerContent> $contents */
/** @var CookieManagerKit\Entity\Settings $settings */

$pageSelector = app('helper/form/page_selector');
$defaults = Catalog::DEFAULTS;
$labelFields = [
    'acceptAll' => [t('"Accept all" button'), 'getAcceptAllLabel'],
    'rejectAll' => [t('"Reject all" button'), 'getRejectAllLabel'],
    'customise' => [t('"Customise" button'), 'getCustomiseLabel'],
    'save' => [t('"Save my choices" button'), 'getSaveLabel'],
    'widget' => [t('Floating settings button'), 'getWidgetLabel'],
];
?>
<form method="post" action="<?php echo O::e($view->action('save')) ?>">
    <?php echo $token->output('submit') ?>

    <p class="text-muted"><?php echo t('Plain text only. Leave a language empty to use the default language for those visitors. Empty button labels use built-in English text.') ?></p>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <?php $index = 0; foreach ($locales as $locale => $label) { $tabId = 'cmk-tab-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $locale); ?>
            <li class="nav-item" role="presentation">
                <button type="button" class="nav-link <?php echo O::e($index === 0 ? 'active' : '') ?>" data-bs-toggle="tab" data-bs-target="#<?php echo O::e($tabId) ?>" role="tab">
                    <?php echo O::e($label) ?>
                    <?php if ($locale === $defaultLocale) { ?><span class="badge text-bg-secondary"><?php echo t('Default') ?></span><?php } ?>
                </button>
            </li>
        <?php $index++; } ?>
    </ul>

    <div class="tab-content mb-4">
        <?php $index = 0; foreach ($locales as $locale => $label) {
            $tabId = 'cmk-tab-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $locale);
            $row = $contents[$locale] ?? null;
            $name = 'content[' . $locale . ']';
            $isDefault = $locale === $defaultLocale;
            ?>
            <div class="tab-pane fade <?php echo O::e($index === 0 ? 'show active' : '') ?>" id="<?php echo O::e($tabId) ?>" role="tabpanel">
                <div class="form-group mb-3">
                    <label class="form-label" for="<?php echo O::e($tabId) ?>-title"><?php echo t('Heading') ?></label>
                    <input type="text" class="form-control" maxlength="255" id="<?php echo O::e($tabId) ?>-title" name="<?php echo O::e($name . '[title]') ?>" value="<?php echo O::e($row ? $row->getTitle() : '') ?>" placeholder="<?php echo O::e($defaults['title']) ?>" <?php echo O::e($isDefault ? 'required' : '') ?>>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label" for="<?php echo O::e($tabId) ?>-body"><?php echo t('Message') ?></label>
                    <textarea class="form-control" rows="4" maxlength="2000" id="<?php echo O::e($tabId) ?>-body" name="<?php echo O::e($name . '[body]') ?>" placeholder="<?php echo O::e($defaults['body']) ?>" <?php echo O::e($isDefault ? 'required' : '') ?>><?php echo O::e($row ? $row->getBody() : '') ?></textarea>
                </div>

                <div class="row">
                    <?php foreach ($labelFields as $key => [$fieldLabel, $getter]) { ?>
                        <div class="col-md-6 mb-3">
                            <label class="form-label" for="<?php echo O::e($tabId . '-' . $key) ?>"><?php echo O::e($fieldLabel) ?></label>
                            <input type="text" class="form-control" maxlength="64" id="<?php echo O::e($tabId . '-' . $key) ?>" name="<?php echo O::e($name . '[' . $key . ']') ?>" value="<?php echo O::e($row ? $row->{$getter}() : '') ?>" placeholder="<?php echo O::e($defaults[$key]) ?>">
                        </div>
                    <?php } ?>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label" for="<?php echo O::e($tabId) ?>-policyLinkText"><?php echo t('Cookie policy link text') ?></label>
                        <input type="text" class="form-control" maxlength="255" id="<?php echo O::e($tabId) ?>-policyLinkText" name="<?php echo O::e($name . '[policyLinkText]') ?>" value="<?php echo O::e($row ? $row->getPolicyLinkText() : '') ?>" placeholder="<?php echo O::e($defaults['policyLinkText']) ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label"><?php echo t('Cookie policy page') ?></label>
                        <?php echo $pageSelector->selectPage('policyPage_' . $locale, $row ? $row->getPolicyPageId() : null) ?>
                    </div>
                </div>
            </div>
        <?php $index++; } ?>
    </div>

    <fieldset>
        <legend><?php echo t('Behaviour') ?></legend>

        <div class="form-group mb-3">
            <label class="form-label" for="lifetimeDays"><?php echo t('Remember a visitor\'s choice for (days)') ?></label>
            <input type="number" class="form-control" style="max-width: 12rem" id="lifetimeDays" name="lifetimeDays" min="30" max="395" step="1" value="<?php echo O::e($settings->getLifetimeDays()) ?>">
            <div class="help-block form-text"><?php echo t('Between 30 and 395 days (13 months).') ?></div>
        </div>

        <div class="form-check mb-3">
            <input type="checkbox" class="form-check-input" id="widgetEnabled" name="widgetEnabled" value="1" <?php echo O::e($settings->isWidgetEnabled() ? 'checked' : '') ?>>
            <label class="form-check-label" for="widgetEnabled"><?php echo t('Show a floating button so visitors can change their choice at any time') ?></label>
            <div class="help-block form-text"><?php echo t('You can also add the attribute data-cmk-open to any link or button to open the cookie settings.') ?></div>
        </div>

        <div class="form-check mb-3">
            <input type="checkbox" class="form-check-input" id="askAgain" name="askAgain" value="1">
            <label class="form-check-label" for="askAgain"><?php echo t('Ask every visitor again') ?></label>
            <div class="help-block form-text"><?php echo O::e(t('Tick this when your cookie policy changes. Current policy version: %s.', $settings->getPolicyVersion())) ?></div>
        </div>
    </fieldset>

    <div class="ccm-dashboard-form-actions-wrapper">
        <div class="ccm-dashboard-form-actions">
            <?php echo $form->submit('save', t('Save Banner'), ['class' => 'btn btn-primary float-end']) ?>
        </div>
    </div>
</form>
