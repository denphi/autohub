<?php
defined('_HZEXEC_') or die();

$code = isset($this->error) ? (int) $this->error->getCode() : 500;
$message = isset($this->error) ? $this->error->getMessage() : 'The requested page could not be displayed.';
?>
<!doctype html>
<html lang="<?php echo $this->language; ?>">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<title><?php echo $code; ?> | <?php echo htmlspecialchars(Config::get('sitename'), ENT_QUOTES, 'UTF-8'); ?></title>
	</head>
	<body class="ah-error-page">
		<main class="ah-shell ah-error-card">
			<p class="ah-kicker">Error <?php echo $code; ?></p>
			<h1>We could not open that page.</h1>
			<p><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
			<p><a class="button" href="<?php echo Request::root(); ?>">Return home</a></p>
		</main>
	</body>
</html>
