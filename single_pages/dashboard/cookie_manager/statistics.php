<?php defined('C5_EXECUTE') or die('Access Denied.');

use CookieManagerKit\Support\Output as O;

/** @var array{from: string, to: string} $range */
/** @var array<string, mixed> $report */

$percent = static fn(?float $value): string => $value === null ? t('n/a') : number_format($value, 1) . '%';
$count = static fn(int $value): string => number_format($value);
$totals = $report['totals'];
$days = $report['days'];
krsort($days);
$groups = [
    t('By language') => $report['locales'],
    t('By policy version') => $report['versions'],
];
$columns = [
    'shown' => t('Banner shown'),
    'accept_all' => t('Accept all'),
    'custom' => t('Custom'),
    'reject_all' => t('Reject all'),
    'changed' => t('Changed later'),
];
?>
<form method="get" class="row g-3 align-items-end mb-4">
    <div class="col-auto">
        <label class="form-label" for="cmk-from"><?php echo t('From') ?></label>
        <input type="date" class="form-control" id="cmk-from" name="from" value="<?php echo O::e($range['from']) ?>">
    </div>
    <div class="col-auto">
        <label class="form-label" for="cmk-to"><?php echo t('To') ?></label>
        <input type="date" class="form-control" id="cmk-to" name="to" value="<?php echo O::e($range['to']) ?>">
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-primary"><?php echo t('Show') ?></button>
    </div>
</form>

<p class="text-muted"><?php echo t('Anonymous counts only. Nothing identifies a visitor. Days are in UTC. Counting started when this version was installed, so earlier choices are not included.') ?></p>

<?php if (array_sum($totals) === 0) { ?>
    <p><?php echo t('Nothing has been counted in this date range yet.') ?></p>
<?php } else { ?>
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card"><div class="card-body">
                <div class="text-muted"><?php echo t('Banner shown') ?></div>
                <div class="fs-3"><?php echo O::e($count($totals['shown'])) ?></div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card"><div class="card-body">
                <div class="text-muted"><?php echo t('Visitors who chose') ?></div>
                <div class="fs-3"><?php echo O::e($count($report['choices'])) ?></div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card"><div class="card-body">
                <div class="text-muted"><?php echo t('Response rate') ?></div>
                <div class="fs-3"><?php echo O::e($percent($report['responseRate'])) ?></div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card"><div class="card-body">
                <div class="text-muted"><?php echo t('Changed their mind later') ?></div>
                <div class="fs-3"><?php echo O::e($count($totals['changed'])) ?></div>
            </div></div>
        </div>
    </div>

    <h3 class="h5"><?php echo t('What visitors chose') ?></h3>
    <table class="table table-striped mb-4">
        <thead>
            <tr>
                <th><?php echo t('Choice') ?></th>
                <th class="text-end"><?php echo t('Visitors') ?></th>
                <th class="text-end"><?php echo t('Share of choices') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach (['accept_all', 'custom', 'reject_all'] as $metric) { ?>
                <tr>
                    <td><?php echo O::e($columns[$metric]) ?></td>
                    <td class="text-end"><?php echo O::e($count($totals[$metric])) ?></td>
                    <td class="text-end"><?php echo O::e($percent($report['shares'][$metric])) ?></td>
                </tr>
            <?php } ?>
        </tbody>
    </table>

    <h3 class="h5"><?php echo t('By day') ?></h3>
    <table class="table table-striped mb-4">
        <thead>
            <tr>
                <th><?php echo t('Day') ?></th>
                <?php foreach ($columns as $label) { ?>
                    <th class="text-end"><?php echo O::e($label) ?></th>
                <?php } ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($days as $day => $row) { ?>
                <tr>
                    <td><?php echo O::e($day) ?></td>
                    <?php foreach (array_keys($columns) as $metric) { ?>
                        <td class="text-end"><?php echo O::e($count($row[$metric])) ?></td>
                    <?php } ?>
                </tr>
            <?php } ?>
        </tbody>
    </table>

    <?php foreach ($groups as $title => $rows) { ?>
        <h3 class="h5"><?php echo O::e($title) ?></h3>
        <table class="table table-striped mb-4">
            <thead>
                <tr>
                    <th></th>
                    <?php foreach ($columns as $label) { ?>
                        <th class="text-end"><?php echo O::e($label) ?></th>
                    <?php } ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $name => $row) { ?>
                    <tr>
                        <td><?php echo O::e($name) ?></td>
                        <?php foreach (array_keys($columns) as $metric) { ?>
                            <td class="text-end"><?php echo O::e($count($row[$metric])) ?></td>
                        <?php } ?>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    <?php } ?>
<?php } ?>
