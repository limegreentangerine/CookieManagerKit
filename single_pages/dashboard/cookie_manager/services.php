<?php defined('C5_EXECUTE') or die('Access Denied.');

use CookieManagerKit\Support\CookieForm;
use CookieManagerKit\Support\Output as O;

/** @var string $mode */
/** @var CookieManagerKit\Entity\Service|null $service */
/** @var CookieManagerKit\Entity\Service[] $services */
/** @var array<int, string> $categories category ID => title */
/** @var array<string, string> $locales */
/** @var string $defaultLocale */
?>
<?php if ($mode === 'list') { ?>
    <div class="mb-3 clearfix">
        <a href="<?php echo O::e($view->action('add')) ?>" class="btn btn-primary float-end"><?php echo t('Add Service') ?></a>
    </div>

    <?php if ($services === []) { ?>
        <p class="text-muted"><?php echo t('No services yet. Add the tools your site loads (for example an analytics tool) so visitors can see what each category covers.') ?></p>
    <?php } else { ?>
        <table class="table table-striped">
            <thead>
                <tr>
                    <th><?php echo t('Name') ?></th>
                    <th><?php echo t('Vendor') ?></th>
                    <th><?php echo t('Category') ?></th>
                    <th><?php echo t('Listed') ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($services as $row) { ?>
                    <tr>
                        <td><a href="<?php echo O::e($view->action('edit', $row->getID())) ?>"><?php echo O::e($row->getName()) ?></a></td>
                        <td><?php echo O::e($row->getVendor()) ?></td>
                        <td><?php echo O::e($row->getCategory() ? ($categories[$row->getCategory()->getID()] ?? $row->getCategory()->getHandle()) : '') ?></td>
                        <td><?php echo O::e($row->isActive() ? t('Yes') : t('No')) ?></td>
                        <td class="text-end">
                            <form method="post" action="<?php echo O::e($view->action('delete')) ?>" class="d-inline">
                                <?php echo $token->output('submit') ?>
                                <input type="hidden" name="id" value="<?php echo O::e($row->getID()) ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><?php echo t('Delete') ?></button>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    <?php } ?>
<?php } else { ?>
    <?php $isNew = $service === null; ?>
    <form method="post" action="<?php echo O::e($view->action('save')) ?>">
        <?php echo $token->output('submit') ?>
        <?php if (!$isNew) { ?><input type="hidden" name="id" value="<?php echo O::e($service->getID()) ?>"><?php } ?>

        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label" for="name"><?php echo t('Name') ?></label>
                <input type="text" class="form-control" id="name" name="name" maxlength="255" required value="<?php echo O::e($isNew ? '' : $service->getName()) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="vendor"><?php echo t('Vendor') ?></label>
                <input type="text" class="form-control" id="vendor" name="vendor" maxlength="255" value="<?php echo O::e($isNew ? '' : $service->getVendor()) ?>">
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label" for="categoryID"><?php echo t('Category') ?></label>
                <select class="form-select" id="categoryID" name="categoryID" required>
                    <?php foreach ($categories as $categoryId => $title) { ?>
                        <option value="<?php echo O::e($categoryId) ?>" <?php echo O::e(!$isNew && $service->getCategory() && $service->getCategory()->getID() === $categoryId ? 'selected' : '') ?>><?php echo O::e($title) ?></option>
                    <?php } ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="domains"><?php echo t('Domains') ?></label>
                <textarea class="form-control" id="domains" name="domains" rows="3" placeholder="www.example.com"><?php echo O::e($isNew ? '' : $service->getDomainsText()) ?></textarea>
                <div class="help-block form-text"><?php echo t('One host name per line, for example www.googletagmanager.com or *.example.com. Anything that is not a plain host name is ignored.') ?></div>
            </div>
        </div>

        <div class="form-check mb-4">
            <input type="checkbox" class="form-check-input" id="active" name="active" value="1" <?php echo O::e($isNew || $service->isActive() ? 'checked' : '') ?>>
            <label class="form-check-label" for="active"><?php echo t('List this service to visitors') ?></label>
        </div>

        <ul class="nav nav-tabs mb-3" role="tablist">
            <?php $index = 0;
        foreach ($locales as $locale => $label) {
            $tabId = 'cmk-svc-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $locale); ?>
                <li class="nav-item" role="presentation">
                    <button type="button" class="nav-link <?php echo O::e($index === 0 ? 'active' : '') ?>" data-bs-toggle="tab" data-bs-target="#<?php echo O::e($tabId) ?>" role="tab">
                        <?php echo O::e($label) ?>
                        <?php if ($locale === $defaultLocale) { ?><span class="badge text-bg-secondary"><?php echo t('Default') ?></span><?php } ?>
                    </button>
                </li>
            <?php $index++;
        } ?>
        </ul>

        <div class="tab-content mb-4">
            <?php $index = 0;
        foreach ($locales as $locale => $label) {
            $tabId = 'cmk-svc-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $locale);
            $row = $isNew ? null : $service->getContent($locale);
            ?>
                <div class="tab-pane fade <?php echo O::e($index === 0 ? 'show active' : '') ?>" id="<?php echo O::e($tabId) ?>" role="tabpanel">
                    <label class="form-label" for="<?php echo O::e($tabId) ?>-description"><?php echo t('What this service does') ?></label>
                    <textarea class="form-control" rows="3" maxlength="1000" id="<?php echo O::e($tabId) ?>-description" name="<?php echo O::e('content[' . $locale . '][description]') ?>"><?php echo O::e($row ? $row->getDescription() : '') ?></textarea>
                </div>
            <?php $index++;
        } ?>
        </div>

        <h3 class="h5"><?php echo t('Cookies') ?></h3>
        <p class="text-muted"><?php echo t('The cookies this service sets, shown in the cookie declaration. Leave the name empty to remove a row.') ?> <?php echo t('0 days means the cookie lasts for the browser session') ?></p>
        <?php $cookieRows = $isNew ? [] : $service->getCookies()->toArray();
        $blankRows = CookieForm::formRows(count($cookieRows)); ?>
        <div class="table-responsive">
        <table class="table table-sm mb-4">
            <thead>
                <tr>
                    <th><?php echo t('Name') ?></th>
                    <th><?php echo t('Days') ?></th>
                    <?php foreach ($locales as $locale => $label) { ?>
                        <th><?php echo O::e($locale) ?></th>
                    <?php } ?>
                </tr>
            </thead>
            <tbody>
                <?php for ($i = 0; $i < $blankRows; $i++) {
                    $cookie = $cookieRows[$i] ?? null; ?>
                    <tr>
                        <td>
                            <input type="hidden" name="<?php echo O::e('cookies[' . $i . '][id]') ?>" value="<?php echo O::e($cookie ? $cookie->getID() : '') ?>">
                            <input type="text" class="form-control" maxlength="128" aria-label="<?php echo O::e(t('Cookie name')) ?>" name="<?php echo O::e('cookies[' . $i . '][name]') ?>" value="<?php echo O::e($cookie ? $cookie->getName() : '') ?>">
                        </td>
                        <td>
                            <input type="number" class="form-control" min="0" max="3650" aria-label="<?php echo O::e(t('Days')) ?>" name="<?php echo O::e('cookies[' . $i . '][days]') ?>" value="<?php echo O::e($cookie ? $cookie->getDurationDays() : 0) ?>">
                        </td>
                        <?php foreach ($locales as $locale => $label) {
                            $content = $cookie ? $cookie->getContent((string) $locale) : null; ?>
                            <td>
                                <input type="text" class="form-control" maxlength="500" aria-label="<?php echo O::e(t('Purpose') . ' (' . $label . ')') ?>" name="<?php echo O::e('cookies[' . $i . '][purpose][' . $locale . ']') ?>" value="<?php echo O::e($content ? $content->getPurpose() : '') ?>">
                            </td>
                        <?php } ?>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
        </div>

        <div class="ccm-dashboard-form-actions-wrapper">
            <div class="ccm-dashboard-form-actions">
                <a href="<?php echo O::e($view->url('/dashboard/cookie_manager/services')) ?>" class="btn btn-secondary float-start"><?php echo t('Cancel') ?></a>
                <?php echo $form->submit('save', t('Save Service'), ['class' => 'btn btn-primary float-end']) ?>
            </div>
        </div>
    </form>
<?php } ?>
