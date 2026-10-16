<?php defined('C5_EXECUTE') or die('Access Denied.');

use CookieManagerKit\Support\Output as O;

/** @var CookieManagerKit\Entity\ScanRun[] $runs */
/** @var array<string, int> $counts */
/** @var int|null $lastBeacon */
/** @var bool $stale */
/** @var int $staleDays */
/** @var bool $scanModeActive */
/** @var bool $secretMissing */
/** @var CookieManagerKit\Entity\ScanRun|null $lastCrawl */

$when = static fn(?int $time): string => $time === null ? t('never') : gmdate('Y-m-d H:i', $time) . ' UTC';
$sources = ['crawl' => t('Crawl'), 'beacon' => t('Scan mode')];
$statuses = ['running' => t('Running'), 'complete' => t('Complete'), 'failed' => t('Failed')];
?>
<?php if ($stale) { ?>
    <div class="alert alert-warning">
        <?php echo O::e(sprintf(t('No runtime scan has run in the last %d days, so cookies and storage set by scripts may be missing. Start scan mode below and browse the site.'), $staleDays)) ?>
    </div>
<?php } ?>

<?php if ($lastCrawl !== null && $lastCrawl->getStatus() === 'complete') { ?>
    <?php if ($lastCrawl->sawEverything()) { ?>
        <div class="alert alert-success">
            <?php echo t('The last crawl saw everything, so findings that no longer appear are marked as gone.') ?>
        </div>
    <?php } else { ?>
        <div class="alert alert-warning">
            <p class="mb-1"><?php echo t('The last crawl did not see everything, so nothing is marked as gone until a crawl does.') ?></p>
            <p class="mb-0"><?php echo t('Common causes: members-only pages that answer an anonymous visitor with a 403 or a redirect, pages on another host or that were dropped, a page list cut off at the page limit, and pages on the exclude list. The exclude list in Scanner Settings keeps the crawler away from members-only pages, but while any page is excluded a crawl has not seen everything either.') ?></p>
        </div>
    <?php } ?>
<?php } ?>

<div class="row g-3 mb-4">
    <div class="col-md">
        <div class="card"><div class="card-body">
            <div class="text-muted"><?php echo t('Unclassified') ?></div>
            <div class="fs-3"><?php echo O::e(number_format((int) ($counts['unclassified'] ?? 0))) ?></div>
        </div></div>
    </div>
    <div class="col-md">
        <div class="card"><div class="card-body">
            <div class="text-muted"><?php echo t('New in the last completed crawl') ?></div>
            <div class="fs-3"><?php echo O::e($lastCrawl === null ? '-' : number_format($lastCrawl->getNewCount())) ?></div>
        </div></div>
    </div>
    <div class="col-md">
        <div class="card"><div class="card-body">
            <div class="text-muted"><?php echo t('Matched, to review') ?></div>
            <div class="fs-3"><?php echo O::e(number_format((int) ($counts['matched'] ?? 0))) ?></div>
        </div></div>
    </div>
    <div class="col-md">
        <div class="card"><div class="card-body">
            <div class="text-muted"><?php echo t('Approved') ?></div>
            <div class="fs-3"><?php echo O::e(number_format((int) ($counts['approved'] ?? 0))) ?></div>
        </div></div>
    </div>
    <div class="col-md">
        <div class="card"><div class="card-body">
            <div class="text-muted"><?php echo t('Last runtime scan') ?></div>
            <div class="fs-6"><?php echo O::e($when($lastBeacon)) ?></div>
        </div></div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-6">
        <h3><?php echo t('Crawl the site') ?></h3>
        <p class="text-muted"><?php echo t('Fetches your published pages as a visitor would and lists the third-party scripts, frames, images and cookies it finds. It runs no JavaScript, so scripts that add cookies later are found by scan mode. Large sites should use the scheduled task instead.') ?></p>
        <form method="post" action="<?php echo O::e($view->action('crawl')) ?>">
            <?php echo $token->output('submit') ?>
            <button type="submit" class="btn btn-primary"><?php echo t('Run crawl now') ?></button>
        </form>
    </div>
    <div class="col-md-6">
        <h3><?php echo t('Scan mode') ?></h3>
        <p class="text-muted"><?php echo t('Start scan mode, accept all cookies on the banner, then browse the site in this browser. For 30 minutes this browser reports the names of cookies and storage keys and the hosts of what each page loaded. Values are never read, and other visitors are not affected. Scan mode is site-wide, not per administrator: starting a session ends any scan session another administrator has running.') ?></p>
        <?php if ($secretMissing) { ?>
            <div class="alert alert-danger"><?php echo t('Scan mode is unavailable: this installation has no site security token.') ?></div>
        <?php } elseif ($scanModeActive) { ?>
            <p><span class="badge bg-success"><?php echo t('Scan mode is on') ?></span></p>
            <form method="post" action="<?php echo O::e($view->action('stopScanMode')) ?>">
                <?php echo $token->output('submit') ?>
                <button type="submit" class="btn btn-outline-secondary"><?php echo t('Stop scan mode') ?></button>
            </form>
        <?php } else { ?>
            <form method="post" action="<?php echo O::e($view->action('startScanMode')) ?>">
                <?php echo $token->output('submit') ?>
                <button type="submit" class="btn btn-primary"><?php echo t('Start scan mode') ?></button>
            </form>
        <?php } ?>
    </div>
</div>

<h3><?php echo t('Recent runs') ?></h3>
<?php if ($runs === []) { ?>
    <p class="text-muted"><?php echo t('Nothing has been scanned yet.') ?></p>
<?php } else { ?>
    <table class="table table-striped">
        <thead>
            <tr>
                <th><?php echo t('Started') ?></th>
                <th><?php echo t('Type') ?></th>
                <th><?php echo t('Status') ?></th>
                <th><?php echo t('Pages') ?></th>
                <th><?php echo t('New') ?></th>
                <th><?php echo t('Changed') ?></th>
                <th><?php echo t('Unclassified') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($runs as $run) { ?>
                <tr>
                    <td><?php echo O::e($when($run->getStartedAt())) ?></td>
                    <td><?php echo O::e($sources[$run->getSource()] ?? $run->getSource()) ?></td>
                    <td><?php echo O::e($statuses[$run->getStatus()] ?? $run->getStatus()) ?></td>
                    <td><?php echo O::e($run->getPagesScanned()) ?></td>
                    <td><?php echo O::e($run->getNewCount()) ?></td>
                    <td><?php echo O::e($run->getChangedCount()) ?></td>
                    <td><?php echo O::e($run->getUnclassifiedCount()) ?></td>
                </tr>
            <?php } ?>
        </tbody>
    </table>
<?php } ?>
