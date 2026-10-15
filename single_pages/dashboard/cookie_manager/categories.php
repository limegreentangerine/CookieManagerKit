<?php defined('C5_EXECUTE') or die('Access Denied.');

use CookieManagerKit\Support\EssentialCookies;
use CookieManagerKit\Support\Output as O;

/** @var string $mode */
/** @var CookieManagerKit\Entity\Category|null $category */
/** @var CookieManagerKit\Entity\Category[] $categories */
/** @var array<string, string> $locales */
/** @var string $defaultLocale */
?>
<?php if ($mode === 'list') { ?>
    <div class="mb-3 clearfix">
        <a href="<?php echo O::e($view->action('add')) ?>" class="btn btn-primary float-end"><?php echo t('Add Category') ?></a>
    </div>

    <table class="table table-striped">
        <thead>
            <tr>
                <th><?php echo t('Title') ?></th>
                <th><?php echo t('Handle') ?></th>
                <th><?php echo t('Order') ?></th>
                <th><?php echo t('Services') ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($categories as $row) {
                $content = $row->getContent($defaultLocale);
                ?>
                <tr>
                    <td>
                        <a href="<?php echo O::e($view->action('edit', $row->getID())) ?>"><?php echo O::e($content ? $content->getTitle() : $row->getHandle()) ?></a>
                        <?php if ($row->isNecessary()) { ?><span class="badge text-bg-secondary"><?php echo t('Always on') ?></span><?php } ?>
                    </td>
                    <td><code><?php echo O::e($row->getHandle()) ?></code></td>
                    <td><?php echo O::e($row->getSortOrder()) ?></td>
                    <td><?php echo O::e($row->getServices()->count()) ?></td>
                    <td class="text-end">
                        <?php if ($row->canBeDeleted()) { ?>
                            <form method="post" action="<?php echo O::e($view->action('delete')) ?>" class="d-inline">
                                <?php echo $token->output('submit') ?>
                                <input type="hidden" name="id" value="<?php echo O::e($row->getID()) ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><?php echo t('Delete') ?></button>
                            </form>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
        </tbody>
    </table>

    <div class="card mt-4">
        <div class="card-body">
            <h5 class="card-title"><?php echo t('Required by ConcreteCMS') ?></h5>
            <p class="card-text"><?php echo t('These cookies are always allowed. They keep you and your visitors signed in and make the dashboard work, so they are never blocked, whatever a visitor chooses.') ?></p>
            <p class="card-text">
                <?php foreach (EssentialCookies::names() as $name) { ?>
                    <code class="me-2"><?php echo O::e($name) ?></code>
                <?php } ?>
            </p>
        </div>
    </div>
<?php } else { ?>
    <?php
    $isNew = $category === null;
    $isNecessary = !$isNew && $category->isNecessary();
    ?>
    <form method="post" action="<?php echo O::e($view->action('save')) ?>">
        <?php echo $token->output('submit') ?>
        <?php if (!$isNew) { ?><input type="hidden" name="id" value="<?php echo O::e($category->getID()) ?>"><?php } ?>

        <?php if ($isNecessary) { ?>
            <div class="alert alert-info"><?php echo t('This category is always on. It holds the cookies the site needs to work, so visitors cannot refuse it and it cannot be deleted.') ?></div>
        <?php } ?>

        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label" for="handle"><?php echo t('Handle') ?></label>
                <?php if ($isNew) { ?>
                    <input type="text" class="form-control" id="handle" name="handle" maxlength="32" pattern="[a-z0-9_\-]{1,32}" required>
                    <div class="help-block form-text"><?php echo t('Used in the data-cmk-category attribute. Lowercase letters, numbers, hyphens and underscores. It cannot be changed later.') ?></div>
                <?php } else { ?>
                    <input type="text" class="form-control" id="handle" value="<?php echo O::e($category->getHandle()) ?>" disabled>
                <?php } ?>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="sortOrder"><?php echo t('Order') ?></label>
                <input type="number" class="form-control" id="sortOrder" name="sortOrder" min="0" max="9999" step="1" value="<?php echo O::e($isNew ? 40 : $category->getSortOrder()) ?>">
            </div>
        </div>

        <ul class="nav nav-tabs mb-3" role="tablist">
            <?php $index = 0; foreach ($locales as $locale => $label) { $tabId = 'cmk-cat-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $locale); ?>
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
                $tabId = 'cmk-cat-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $locale);
                $row = $isNew ? null : $category->getContent($locale);
                $isDefault = $locale === $defaultLocale;
                ?>
                <div class="tab-pane fade <?php echo O::e($index === 0 ? 'show active' : '') ?>" id="<?php echo O::e($tabId) ?>" role="tabpanel">
                    <div class="form-group mb-3">
                        <label class="form-label" for="<?php echo O::e($tabId) ?>-title"><?php echo t('Title') ?></label>
                        <input type="text" class="form-control" maxlength="255" id="<?php echo O::e($tabId) ?>-title" name="<?php echo O::e('content[' . $locale . '][title]') ?>" value="<?php echo O::e($row ? $row->getTitle() : '') ?>" <?php echo O::e($isDefault ? 'required' : '') ?>>
                    </div>
                    <div class="form-group mb-3">
                        <label class="form-label" for="<?php echo O::e($tabId) ?>-description"><?php echo t('Description') ?></label>
                        <textarea class="form-control" rows="3" maxlength="1000" id="<?php echo O::e($tabId) ?>-description" name="<?php echo O::e('content[' . $locale . '][description]') ?>"><?php echo O::e($row ? $row->getDescription() : '') ?></textarea>
                    </div>
                </div>
            <?php $index++; } ?>
        </div>

        <?php if ($isNecessary) { ?>
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title"><?php echo t('Required by ConcreteCMS') ?></h5>
                    <p class="card-text">
                        <?php foreach (CookieManagerKit\Support\EssentialCookies::names() as $name) { ?>
                            <code class="me-2"><?php echo O::e($name) ?></code>
                        <?php } ?>
                    </p>
                </div>
            </div>
        <?php } ?>

        <div class="ccm-dashboard-form-actions-wrapper">
            <div class="ccm-dashboard-form-actions">
                <a href="<?php echo O::e($view->url('/dashboard/cookie_manager/categories')) ?>" class="btn btn-secondary float-start"><?php echo t('Cancel') ?></a>
                <?php echo $form->submit('save', t('Save Category'), ['class' => 'btn btn-primary float-end']) ?>
            </div>
        </div>
    </form>
<?php } ?>
