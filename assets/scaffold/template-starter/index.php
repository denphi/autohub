<?php
/**
 * Shared site chrome for __TEMPLATE_LABEL__.
 * Editable records are rendered by HUBzero's component buffer.
 */
defined('_HZEXEC_') or die();

$assetRoot = rtrim($this->baseurl, '/') . '/templates/' . $this->template;
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
	<body class="ah-site">
		<a class="ah-skip-link" href="#main-content">Skip to main content</a>

		<?php if ($this->countModules('notices')) : ?>
			<div class="ah-notices" aria-label="Site notices">
				<jdoc:include type="modules" name="notices" />
			</div>
		<?php endif; ?>

		<header class="ah-site-header">
			<div class="ah-shell ah-site-header__inner">
				<a class="ah-brand" href="<?php echo Request::root(); ?>">
					<?php echo htmlspecialchars(Config::get('sitename'), ENT_QUOTES, 'UTF-8'); ?>
				</a>
				<nav class="ah-primary-nav" aria-label="Primary navigation">
					<jdoc:include type="modules" name="user3" />
				</nav>
			</div>
		</header>

		<?php if ($this->getBuffer('message')) : ?>
			<div class="ah-shell ah-system-messages" aria-live="polite">
				<jdoc:include type="message" />
			</div>
		<?php endif; ?>

		<main id="main-content" class="ah-main" tabindex="-1">
			<jdoc:include type="component" />
		</main>

		<footer class="ah-site-footer">
			<div class="ah-shell">
				<p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars(Config::get('sitename'), ENT_QUOTES, 'UTF-8'); ?></p>
				<jdoc:include type="modules" name="footer" />
			</div>
		</footer>

		<jdoc:include type="modules" name="debug" />
	</body>
</html>
