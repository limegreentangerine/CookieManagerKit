<?php defined('C5_EXECUTE') or die('Access Denied.');

use CookieManagerKit\Support\Output as O;

/** @var CookieManagerKit\Consent\ConsentLogFilter $filter */
/** @var list<array<string, mixed>> $rows */
/** @var int $total */
/** @var int $page */
/** @var int $perPage */
/** @var list<string> $triggers */
/** @var list<string> $categories */
/** @var int $retentionMonths */
/** @var bool $capped */

$pages = max(1, (int) ceil($total / $perPage));
$suffix = '&from=' . rawurlencode($filter->from) . '&to=' . rawurlencode($filter->to) . '&trigger=' . rawurlencode($filter->trigger)
    . '&version=' . rawurlencode($filter->version ? (string) $filter->version : '') . '&category=' . rawurlencode($filter->category)
    . '&id=' . rawurlencode($filter->consentId);
?>
<p class="text-muted"><?php echo O::e(t('Every choice a visitor makes is recorded under a random consent ID that lives in their cookie. No IP address or raw user agent is stored. A browser\'s history is deleted %s months after its last choice (set on the Banner page).', $retentionMonths)) ?></p>

<form method="get" class="row g-3 align-items-end mb-4">
    <div class="col-auto">
        <label class="form-label" for="cmk-from"><?php echo t('From') ?></label>
        <input type="date" class="form-control" id="cmk-from" name="from" value="<?php echo O::e($filter->from) ?>">
    </div>
    <div class="col-auto">
        <label class="form-label" for="cmk-to"><?php echo t('To') ?></label>
        <input type="date" class="form-control" id="cmk-to" name="to" value="<?php echo O::e($filter->to) ?>">
    </div>
    <div class="col-auto">
        <label class="form-label" for="cmk-trigger"><?php echo t('Trigger') ?></label>
        <select class="form-select" id="cmk-trigger" name="trigger">
            <option value=""><?php echo t('Any') ?></option>
            <?php foreach ($triggers as $value) { ?>
                <option value="<?php echo O::e($value) ?>" <?php echo O::e($filter->trigger === $value ? 'selected' : '') ?>><?php echo O::e($value) ?></option>
            <?php } ?>
        </select>
    </div>
    <div class="col-auto">
        <label class="form-label" for="cmk-version"><?php echo t('Policy version') ?></label>
        <input type="number" min="1" class="form-control" style="width: 8rem" id="cmk-version" name="version" value="<?php echo O::e($filter->version ?: '') ?>">
    </div>
    <div class="col-auto">
        <label class="form-label" for="cmk-category"><?php echo t('Category') ?></label>
        <select class="form-select" id="cmk-category" name="category">
            <option value=""><?php echo t('Any') ?></option>
            <?php foreach ($categories as $handle) { ?>
                <option value="<?php echo O::e($handle) ?>" <?php echo O::e($filter->category === $handle ? 'selected' : '') ?>><?php echo O::e($handle) ?></option>
            <?php } ?>
        </select>
    </div>
    <div class="col-auto">
        <label class="form-label" for="cmk-id"><?php echo t('Consent ID') ?></label>
        <input type="text" class="form-control" style="width: 22rem" maxlength="64" id="cmk-id" name="id" value="<?php echo O::e($filter->consentId !== '' ? $filter->consentId : $filter->rejectedId) ?>">
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-primary"><?php echo t('Filter') ?></button>
    </div>
</form>

<?php if ($filter->rejectedId !== '') { ?>
    <div class="alert alert-warning"><?php echo O::e(t('"%s" is not a valid consent ID, so it was ignored.', $filter->rejectedId)) ?></div>
<?php } ?>

<form method="post" action="<?php echo O::e($view->action('export')) ?>" class="mb-3">
    <?php echo $token->output('submit') ?>
    <input type="hidden" name="from" value="<?php echo O::e($filter->from) ?>">
    <input type="hidden" name="to" value="<?php echo O::e($filter->to) ?>">
    <input type="hidden" name="trigger" value="<?php echo O::e($filter->trigger) ?>">
    <input type="hidden" name="version" value="<?php echo O::e($filter->version ?: '') ?>">
    <input type="hidden" name="category" value="<?php echo O::e($filter->category) ?>">
    <input type="hidden" name="id" value="<?php echo O::e($filter->consentId) ?>">
    <button type="submit" class="btn btn-secondary"><?php echo t('Download CSV') ?></button>
    <?php if ($capped) { ?><span class="text-muted"><?php echo t('The export is limited to the newest 100,000 rows.') ?></span><?php } ?>
</form>

<?php if ($rows === []) { ?>
    <p><?php echo t('No consent records match.') ?></p>
<?php } else { ?>
    <table class="table table-striped">
        <thead><tr>
            <th><?php echo t('Date (UTC)') ?></th><th><?php echo t('Consent ID') ?></th><th><?php echo t('Version') ?></th>
            <th><?php echo t('Categories') ?></th><th><?php echo t('Trigger') ?></th><th><?php echo t('Locale') ?></th>
            <th><?php echo t('Browser') ?></th><th><?php echo t('Re-consent reason') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $row) { ?>
            <tr>
                <td><?php echo O::e(gmdate('Y-m-d H:i', (int) $row['createdAt'])) ?></td>
                <td><a href="<?php echo O::e('?id=' . rawurlencode((string) $row['consentId'])) ?>"><code><?php echo O::e($row['consentId']) ?></code></a></td>
                <td><?php echo O::e((int) $row['version']) ?></td>
                <td><?php echo O::e(implode(', ', $row['categories'])) ?></td>
                <td><?php echo O::e($row['trigger']) ?></td>
                <td><?php echo O::e($row['locale']) ?></td>
                <td><?php echo O::e($row['userAgentFamily']) ?></td>
                <td><?php echo O::e($row['reason']) ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>

    <?php if ($pages > 1) { ?>
        <div class="d-flex gap-1 flex-wrap">
            <?php for ($n = 1; $n <= $pages; $n++) { ?>
                <a class="btn btn-sm <?php echo O::e($n === $page ? 'btn-primary' : 'btn-outline-secondary') ?>" href="<?php echo O::e('?page=' . (int) $n . $suffix) ?>"><?php echo O::e($n) ?></a>
            <?php } ?>
        </div>
    <?php } ?>
<?php } ?>
