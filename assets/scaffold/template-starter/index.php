<?php
/**
 * Shared site chrome for __TEMPLATE_LABEL__.
 * Editable records are rendered by HUBzero's component buffer.
 */
defined('_HZEXEC_') or die();

$stylesheet = \Hubzero\Document\Assets::getSystemStylesheet();

// Core scopes a large share of its component CSS under the component name and
// the content id (`.com_resources .resource-type`, `#content.com_members`).
// A shell that omits either makes those rules match nothing, on every
// component route -- which reads as "core's CSS is ugly" rather than "core's
// CSS never applied". Both hooks are required, not decorative.
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
	<body class="ah-site <?php echo $esc($option); ?>">
		<a class="ah-skip-link" href="#content">Skip to main content</a>

		<?php if ($this->countModules('notices')) : ?>
			<div class="ah-notices" aria-label="Site notices">
				<jdoc:include type="modules" name="notices" />
			</div>
		<?php endif; ?>

		<header class="ah-site-header">
			<div class="ah-shell ah-site-header__inner">
				<a class="ah-brand" href="<?php echo Request::root(); ?>">
					<?php echo $esc(Config::get('sitename')); ?>
				</a>
				<nav class="ah-primary-nav" aria-label="Primary navigation">
					<jdoc:include type="modules" name="user3" />
				</nav>
				<div class="ah-account">
					<?php if (User::isGuest()) : ?>
						<a class="ah-account__link" href="<?php echo Route::url('index.php?option=com_users&view=login'); ?>">Sign in</a>
					<?php else : ?>
						<span class="ah-account__name"><?php echo $esc(User::get('name') ?: User::get('username')); ?></span>
						<?php
						// Immediate logout requires the session form token; the
						// tokenless com_users logout route only reaches a
						// confirmation view. A template with no sign-out at all
						// strands the user, which is a real bug report.
						$logout = 'index.php?option=com_login&task=logout&'
							. Session::getFormToken() . '=1';
						?>
						<a class="ah-account__link" href="<?php echo Route::url($logout); ?>">Sign out</a>
					<?php endif; ?>
				</div>
			</div>
		</header>

		<?php if ($this->getBuffer('message')) : ?>
			<div class="ah-shell ah-system-messages" aria-live="polite">
				<jdoc:include type="message" />
			</div>
		<?php endif; ?>

		<main id="content" class="ah-main <?php echo $esc($option); ?>" tabindex="-1">
			<jdoc:include type="component" />
		</main>

		<footer class="ah-site-footer">
			<div class="ah-shell">
				<p>&copy; <?php echo date('Y'); ?> <?php echo $esc(Config::get('sitename')); ?></p>
				<jdoc:include type="modules" name="footer" />
			</div>
		</footer>

		<jdoc:include type="modules" name="debug" />
	</body>
</html>
