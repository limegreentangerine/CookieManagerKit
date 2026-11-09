<?php

defined('C5_EXECUTE') or die('Access Denied.');

use CookieManagerKit\Support\Output as O;

if (is_object($c) && $c->isEditMode()) { ?>
    <div class="ccm-edit-mode-disabled-item"><?php echo t('Cookie declaration') ?></div>
    <?php return;
} ?>
<div class="cmk-declaration">
    <?php foreach ($sections as $section) { ?>
        <h3><?php echo O::e($section['title']) ?></h3>
        <?php if ($section['description'] !== '') { ?>
            <p><?php echo O::e($section['description']) ?></p>
        <?php } ?>
        <table class="cmk-declaration__table">
            <thead>
                <tr>
                    <th><?php echo t('Cookie') ?></th>
                    <th><?php echo t('Provider') ?></th>
                    <th><?php echo t('Purpose') ?></th>
                    <th><?php echo t('Duration') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($section['cookies'] as $cookie) { ?>
                    <tr>
                        <td><?php echo O::e($cookie['name']) ?></td>
                        <td><?php echo O::e($cookie['provider']) ?></td>
                        <td><?php echo O::e($cookie['purpose']) ?></td>
                        <td><?php echo O::e($cookie['duration']) ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    <?php } ?>
    <?php if ($sections === []) { ?>
        <p class="cmk-declaration__empty"><?php echo t('This site does not list any cookies.') ?></p>
    <?php } ?>
    <p><button type="button" class="cmk-declaration__open" data-cmk-open><?php echo t('Change my consent') ?></button></p>
</div>
