<?php
defined('_HZEXEC_') or die();

$stylesheet = \Hubzero\Document\Assets::getSystemStylesheet();

// Same component hooks as index.php: core's CSS is scoped under the component
// name and #content, and the reduced view needs them just as much.
$option = Request::getCmd('option', '');
$esc = function ($value)
{
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
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
	<body class="ah-component-view <?php echo $esc($option); ?>">
		<main id="content" class="ah-shell <?php echo $esc($option); ?>">
			<jdoc:include type="message" />
			<jdoc:include type="component" />
		</main>
	</body>
</html>
