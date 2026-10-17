<?php defined('C5_EXECUTE') or die('Access Denied.');

use CookieManagerKit\Support\Output as O;

/** @var array<string, string> $locales */
/** @var string $defaultLocale */
/** @var array<string, array<string, array{filled: int, total: int}>> $matrix */
/** @var array<string, array{string, string}> $areas */
?>
<p class="text-muted"><?php echo t('Shows how much text is translated in each language. Only fields that have text in the default language are counted.') ?></p>

<div class="table-responsive mb-4">
    <table class="table table-bordered align-middle">
        <thead>
            <tr>
                <th><?php echo t('Area') ?></th>
                <?php foreach ($locales as $locale => $label) { ?>
                    <th>
                        <?php echo O::e($label) ?>
                        <?php if ($locale === $defaultLocale) { ?><span class="badge text-bg-secondary"><?php echo t('Default') ?></span><?php } ?>
                    </th>
                <?php } ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($areas as $area => $info) { ?>
                <tr>
                    <th scope="row"><a href="<?php echo O::e($view->url($info[1])) ?>"><?php echo O::e($info[0]) ?></a></th>
                    <?php foreach ($locales as $locale => $label) {
                        $cell = $matrix[$area][$locale] ?? ['filled' => 0, 'total' => 0]; ?>
                        <td class="<?php echo O::e($cell['total'] > 0 && $cell['filled'] === $cell['total'] ? 'text-success' : '') ?>"><?php echo O::e($cell['filled'] . ' / ' . $cell['total']) ?></td>
                    <?php } ?>
                </tr>
            <?php } ?>
        </tbody>
    </table>
</div>

<?php if (count($locales) < 2) { ?>
    <p class="text-muted"><?php echo t('Your site has a single language, so there is nothing to translate.') ?></p>
<?php } else { ?>
    <div class="alert alert-info">
        <?php echo t('To translate in bulk: download the CSV for a language, edit only the "translation" column in your spreadsheet, and save it as "CSV UTF-8 (comma delimited)". Then import it here.') ?>
    </div>

    <?php foreach ($locales as $locale => $label) {
        if ($locale === $defaultLocale) {
            continue;
        } ?>
        <div class="card mb-4">
            <div class="card-header"><strong><?php echo O::e($label) ?></strong></div>
            <div class="card-body">
                <form method="post" action="<?php echo O::e($view->action('copy')) ?>" class="row g-2 align-items-end mb-4">
                    <?php echo $token->output('submit') ?>
                    <input type="hidden" name="locale" value="<?php echo O::e($locale) ?>">
                    <div class="col-auto">
                        <select class="form-select" name="area" aria-label="<?php echo t('Area') ?>">
                            <option value="all"><?php echo t('All areas') ?></option>
                            <?php foreach ($areas as $area => $info) { ?>
                                <option value="<?php echo O::e($area) ?>"><?php echo O::e($info[0]) ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-secondary"><?php echo t('Copy missing text from the default language') ?></button>
                    </div>
                </form>

                <form method="post" action="<?php echo O::e($view->action('export')) ?>" class="mb-4">
                    <?php echo $token->output('submit') ?>
                    <input type="hidden" name="locale" value="<?php echo O::e($locale) ?>">
                    <button type="submit" class="btn btn-secondary"><?php echo t('Download CSV of missing text') ?></button>
                </form>

                <form method="post" action="<?php echo O::e($view->action('import')) ?>" enctype="multipart/form-data">
                    <?php echo $token->output('submit') ?>
                    <input type="hidden" name="locale" value="<?php echo O::e($locale) ?>">
                    <div class="mb-2">
                        <input type="file" class="form-control" name="csv" accept=".csv,text/csv" required>
                    </div>
                    <button type="submit" class="btn btn-primary"><?php echo t('Import translated CSV') ?></button>
                    <div class="form-text"><?php echo t('The file must be a CSV downloaded here for this language. Importing replaces existing translations in this language with the non-blank values in the file. Nothing is changed if any row is invalid.') ?></div>
                </form>
            </div>
        </div>
    <?php } ?>
<?php } ?>
