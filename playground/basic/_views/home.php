<?php $this->extend('_layout.php'); ?>
<?php $this->block('title', 'Home'); ?>
<?php $this->block('content'); ?>
<h1>Hello <?= htmlspecialchars($name) ?></h1>
<?php $this->end(); ?>
