<?php
defined('_HZEXEC_') or die();

$stylesheet = \Hubzero\Document\Assets::getSystemStylesheet();
?>
<!doctype html>
<html dir="<?php echo $this->direction; ?>" lang="<?php echo $this->language; ?>">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<?php if ($stylesheet) : ?>
			<link rel="stylesheet" href="<?php echo $stylesheet; ?>" media="all">
		<?php endif; ?>
		<jdoc:include type="head" />
	</head>
	<body class="ah-component-view">
		<main class="ah-shell">
			<jdoc:include type="message" />
			<jdoc:include type="component" />
		</main>
	</body>
</html>
