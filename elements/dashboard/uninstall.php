<?php defined('C5_EXECUTE') or die('Access Denied.'); ?>

<div class="form-group">
    <p><?php echo t('Are you sure you want to uninstall this package?') ?></p>
</div>
<div class="checkbox">
    <label>
        <input type="checkbox" name="purge_database_tables" value="1">
        <?php echo t('Also delete all banner text, categories, services and settings (this cannot be undone)') ?>
    </label>
</div>
