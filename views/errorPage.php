<?php declare(strict_types=1);
$this->layout('base', ['pageTitle' => $this->t('Error')]); ?>
<?php $this->start('content'); ?>
    <h2><?= $this->e((string) $code); ?></h2>

    <p><?= $this->t('An error occurred.'); ?></p>

    <p class="error">
        <code><?= $this->e($message); ?></code>
    </p>
<?php $this->stop('content'); ?>
