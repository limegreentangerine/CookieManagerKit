<?php defined('C5_EXECUTE') or die('Access Denied.');

use CookieManagerKit\Support\Output as O;

/** @var CookieManagerKit\Entity\Settings $settings */
?>
<form method="post" action="<?php echo O::e($view->action('save')) ?>">
    <?php echo $token->output('submit') ?>

    <div class="row mb-3">
        <div class="col-md-4">
            <label class="form-label" for="scanMaxPages"><?php echo t('Most pages one crawl fetches') ?></label>
            <input type="number" class="form-control" id="scanMaxPages" name="scanMaxPages" min="1" max="500" value="<?php echo O::e($settings->getScanMaxPages()) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label" for="scanStaleDays"><?php echo t('Flag a missing runtime scan after (days)') ?></label>
            <input type="number" class="form-control" id="scanStaleDays" name="scanStaleDays" min="7" max="365" value="<?php echo O::e($settings->getScanStaleDays()) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label" for="scanAlertEmail"><?php echo t('Email alerts to') ?></label>
            <input type="email" class="form-control" id="scanAlertEmail" name="scanAlertEmail" maxlength="255" value="<?php echo O::e($settings->getScanAlertEmail()) ?>">
        </div>
    </div>

    <div class="mb-3">
        <label class="form-label" for="scanExcludes"><?php echo t('Pages the crawl skips') ?></label>
        <textarea class="form-control" id="scanExcludes" name="scanExcludes" rows="4" placeholder="/members"><?php echo O::e($settings->getScanExcludesText()) ?></textarea>
        <div class="help-block form-text"><?php echo t('One path per line. "/members" skips that page and everything under it; "/shop/*" skips everything that starts with /shop/. While any page is skipped, a crawl will not mark findings as gone.') ?></div>
    </div>

    <div class="form-check mb-2">
        <input class="form-check-input" type="checkbox" id="scanSchedule" name="scanSchedule" value="1" <?php echo O::e($settings->isScanScheduleEnabled() ? 'checked' : '') ?>>
        <label class="form-check-label" for="scanSchedule"><?php echo t('Run the scheduled crawl') ?></label>
        <div class="help-block form-text"><?php echo t('Schedule the "Scan the site for cookies and scripts" task under Automation. It does nothing unless this is ticked. Set the site canonical URL first.') ?></div>
    </div>

    <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" id="classificationRefresh" name="classificationRefresh" value="1" <?php echo O::e($settings->isClassificationRefreshEnabled() ? 'checked' : '') ?>>
        <label class="form-check-label" for="classificationRefresh"><?php echo t('Let the scheduled task download newer classification data') ?></label>
        <div class="help-block form-text"><?php echo t('Off by default. When ticked, the scheduled task may download one public file (the Open Cookie Database) and sends nothing about your site. The built-in data is used if the download fails.') ?></div>
    </div>

    <button type="submit" class="btn btn-primary"><?php echo t('Save') ?></button>
</form>

<hr>

<h3><?php echo t('Crawling a local or private site') ?></h3>
<p class="text-muted"><?php echo t('A crawl needs the site canonical URL to be set (Dashboard, System, Environment, Urls and Languages). A site served from a local address is refused by default. For local development, set the config key cookie_manager_kit.scan.allow_private_network to true and give the site a dotted hostname such as site.test. Leave it off in production.') ?></p>
