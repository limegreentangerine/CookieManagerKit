<?php defined('C5_EXECUTE') or die('Access Denied.');

use CookieManagerKit\Scan\Review;
use CookieManagerKit\Entity\ScanFinding;
use CookieManagerKit\Support\Output as O;

/** @var CookieManagerKit\Entity\ScanFinding[] $findings */
/** @var string $status */
/** @var string $kind */
/** @var string $categoryFilter '' (all), 'none' or a category handle */
/** @var int $page */
/** @var int $pages */
/** @var int $total */
/** @var array<string, array{id: int, title: string}> $categories handle => id and title */
/** @var CookieManagerKit\Entity\Service[] $services */

$statusLabels = [
    ScanFinding::UNCLASSIFIED => t('Unclassified'),
    ScanFinding::MATCHED => t('Matched'),
    ScanFinding::APPROVED => t('Approved'),
    ScanFinding::IGNORED => t('Ignored'),
    ScanFinding::GONE => t('Gone'),
];
$kindLabels = [
    ScanFinding::COOKIE => t('Cookie'),
    ScanFinding::STORAGE => t('Storage key'),
    ScanFinding::SCRIPT => t('Script'),
    ScanFinding::IFRAME => t('Frame'),
    ScanFinding::IMAGE => t('Image'),
    ScanFinding::RESOURCE => t('Other resource'),
];
$when = static fn(int $time): string => gmdate('Y-m-d H:i', $time) . ' UTC';
$handles = array_keys($categories);
?>
<form method="get" class="row g-3 align-items-end mb-4">
    <div class="col-auto">
        <label class="form-label" for="cmk-status"><?php echo t('Status') ?></label>
        <select class="form-select" id="cmk-status" name="status">
            <option value=""><?php echo t('All') ?></option>
            <?php foreach ($statusLabels as $value => $label) { ?>
                <option value="<?php echo O::e($value) ?>" <?php echo O::e($status === $value ? 'selected' : '') ?>><?php echo O::e($label) ?></option>
            <?php } ?>
        </select>
    </div>
    <div class="col-auto">
        <label class="form-label" for="cmk-kind"><?php echo t('Type') ?></label>
        <select class="form-select" id="cmk-kind" name="kind">
            <option value=""><?php echo t('All') ?></option>
            <?php foreach ($kindLabels as $value => $label) { ?>
                <option value="<?php echo O::e($value) ?>" <?php echo O::e($kind === $value ? 'selected' : '') ?>><?php echo O::e($label) ?></option>
            <?php } ?>
        </select>
    </div>
    <div class="col-auto">
        <label class="form-label" for="cmk-category"><?php echo t('Category') ?></label>
        <select class="form-select" id="cmk-category" name="category">
            <option value=""><?php echo t('All') ?></option>
            <option value="none" <?php echo O::e($categoryFilter === 'none' ? 'selected' : '') ?>><?php echo t('No suggestion') ?></option>
            <?php foreach ($categories as $handle => $info) { ?>
                <option value="<?php echo O::e($handle) ?>" <?php echo O::e($categoryFilter === $handle ? 'selected' : '') ?>><?php echo O::e($info['title']) ?></option>
            <?php } ?>
        </select>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-primary"><?php echo t('Filter') ?></button>
    </div>
</form>

<form method="post" action="<?php echo O::e($view->action('bulkApprove')) ?>" class="mb-3">
    <?php echo $token->output('submit') ?>
    <button type="submit" class="btn btn-outline-primary"><?php echo t('Approve all matched findings') ?></button>
    <span class="text-muted ms-2"><?php echo t('Creates one service per vendor and category. Findings without a vendor stay in the queue.') ?></span>
</form>

<p class="text-muted"><?php echo O::e(sprintf(t('%d findings.'), $total)) ?></p>

<?php if ($findings === []) { ?>
    <p><?php echo t('No findings match. Run a crawl or start scan mode on the Scanner page.') ?></p>
<?php } else { ?>
    <table class="table table-striped align-middle">
        <thead>
            <tr>
                <th><?php echo t('Type') ?></th>
                <th><?php echo t('Name') ?></th>
                <th><?php echo t('Host') ?></th>
                <th><?php echo t('Status') ?></th>
                <th><?php echo t('Suggestion') ?></th>
                <th><?php echo t('Last seen') ?></th>
                <th><?php echo t('Seen on') ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($findings as $row) { ?>
                <?php $preselect = Review::defaultCategory($row->getSuggestedCategory(), $handles); ?>
                <tr>
                    <td><?php echo O::e($kindLabels[$row->getKind()] ?? $row->getKind()) ?></td>
                    <td>
                        <code><?php echo O::e($row->getName()) ?></code>
                        <?php if ($row->getExamplePage() !== '') { ?>
                            <div class="small text-muted text-break"><?php echo t('Example page') ?>: <code><?php echo O::e($row->getExamplePage()) ?></code></div>
                        <?php } ?>
                    </td>
                    <td><?php echo O::e($row->getHost()) ?></td>
                    <td><?php echo O::e($statusLabels[$row->getStatus()] ?? $row->getStatus()) ?></td>
                    <td><?php echo O::e(trim(($row->getSuggestedCategory() ?? '') . ' ' . ($row->getSuggestedVendor() ?? ''))) ?></td>
                    <td><?php echo O::e($when($row->getLastSeen())) ?></td>
                    <td><?php echo O::e(implode(', ', $row->sources())) ?></td>
                    <td class="text-end">
                        <?php if ($row->isReviewable()) { ?>
                            <details>
                                <summary class="btn btn-sm btn-outline-primary"><?php echo t('Review') ?></summary>

                                <form method="post" action="<?php echo O::e($view->action('approve')) ?>" class="mt-2 text-start">
                                    <?php echo $token->output('submit') ?>
                                    <input type="hidden" name="id" value="<?php echo O::e($row->getID()) ?>">
                                    <label class="form-label" for="svc-<?php echo O::e($row->getID()) ?>"><?php echo t('Service') ?></label>
                                    <select class="form-select mb-2" id="svc-<?php echo O::e($row->getID()) ?>" name="serviceID">
                                        <option value=""><?php echo t('Create a new service') ?></option>
                                        <?php foreach ($services as $service) { ?>
                                            <option value="<?php echo O::e($service->getID()) ?>"><?php echo O::e($service->getName()) ?></option>
                                        <?php } ?>
                                    </select>
                                    <input type="text" class="form-control mb-2" name="name" maxlength="255" placeholder="<?php echo t('New service name') ?>" value="<?php echo O::e($row->getSuggestedVendor() ?? $row->getHost()) ?>">
                                    <input type="text" class="form-control mb-2" name="vendor" maxlength="255" placeholder="<?php echo t('Vendor') ?>" value="<?php echo O::e($row->getSuggestedVendor() ?? '') ?>">
                                    <select class="form-select mb-2" name="categoryID" aria-label="<?php echo t('Category for a new service') ?>">
                                        <?php foreach ($categories as $handle => $info) { ?>
                                            <option value="<?php echo O::e($info['id']) ?>" <?php echo O::e($handle === $preselect ? 'selected' : '') ?>><?php echo O::e($info['title']) ?></option>
                                        <?php } ?>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-primary"><?php echo t('Approve') ?></button>
                                </form>

                                <form method="post" action="<?php echo O::e($view->action('reassign')) ?>" class="mt-2 text-start">
                                    <?php echo $token->output('submit') ?>
                                    <input type="hidden" name="id" value="<?php echo O::e($row->getID()) ?>">
                                    <select class="form-select mb-2" name="categoryID">
                                        <?php foreach ($categories as $handle => $info) { ?>
                                            <option value="<?php echo O::e($info['id']) ?>" <?php echo O::e($handle === $preselect ? 'selected' : '') ?>><?php echo O::e($info['title']) ?></option>
                                        <?php } ?>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-outline-secondary"><?php echo t('Change suggested category') ?></button>
                                </form>

                                <form method="post" action="<?php echo O::e($view->action('override')) ?>" class="mt-2 text-start">
                                    <?php echo $token->output('submit') ?>
                                    <input type="hidden" name="id" value="<?php echo O::e($row->getID()) ?>">
                                    <input type="text" class="form-control mb-2" name="vendor" maxlength="255" placeholder="<?php echo t('Vendor for this rule') ?>" value="<?php echo O::e($row->getSuggestedVendor() ?? '') ?>">
                                    <select class="form-select mb-2" name="categoryID">
                                        <?php foreach ($categories as $handle => $info) { ?>
                                            <option value="<?php echo O::e($info['id']) ?>" <?php echo O::e($handle === $preselect ? 'selected' : '') ?>><?php echo O::e($info['title']) ?></option>
                                        <?php } ?>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-outline-secondary"><?php echo t('Always classify like this') ?></button>
                                </form>

                                <form method="post" action="<?php echo O::e($view->action('ignore')) ?>" class="mt-2 text-start">
                                    <?php echo $token->output('submit') ?>
                                    <input type="hidden" name="id" value="<?php echo O::e($row->getID()) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><?php echo t('Ignore') ?></button>
                                </form>
                            </details>
                        <?php } elseif ($row->getStatus() === ScanFinding::IGNORED) { ?>
                            <form method="post" action="<?php echo O::e($view->action('unignore')) ?>">
                                <?php echo $token->output('submit') ?>
                                <input type="hidden" name="id" value="<?php echo O::e($row->getID()) ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary"><?php echo t('Review again') ?></button>
                            </form>
                        <?php } ?>
                    </td>
                </tr>
            <?php } ?>
        </tbody>
    </table>

    <?php if ($pages > 1) { ?>
        <nav class="mt-3">
            <?php for ($n = 1; $n <= $pages; $n++) { ?>
                <a class="btn btn-sm <?php echo O::e($n === $page ? 'btn-primary' : 'btn-outline-secondary') ?>" href="<?php echo O::e('?page=' . (int) $n . '&status=' . rawurlencode($status) . '&kind=' . rawurlencode($kind) . '&category=' . rawurlencode($categoryFilter)) ?>"><?php echo O::e($n) ?></a>
            <?php } ?>
        </nav>
    <?php } ?>
<?php } ?>
