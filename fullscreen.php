<?php
/**
 * Template fullscreen per CV Cleaning Calendar.
 * Viene caricato al posto del tema quando si accede alla pagina shortcode.
 * Niente header, menu, footer — solo il calendario come app.
 */
defined('ABSPATH') || exit;

// Forza autenticazione
if (!is_user_logged_in()) {
    $redirect = isset($_SERVER['REQUEST_URI']) ? home_url($_SERVER['REQUEST_URI']) : home_url('/');
    wp_redirect(wp_login_url($redirect));
    exit;
}
if (!current_user_can(CVCAL_CAP)) {
    wp_die('⛔ Non hai i permessi per accedere al calendario.', 'Accesso negato', ['response' => 403]);
}

// Prevent caching su questa pagina
nocache_headers();
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="theme-color" content="#1a1a2e">
<title>Calendario Pulizie — Casa Volterra</title>
<?php wp_head(); ?>
<style>
  /* Reset totale tema */
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  html, body {
      background: #f0f2f5 !important;
      padding: 0 !important;
      margin: 0 !important;
      min-height: 100vh;
      font-size: 16px;
      -webkit-text-size-adjust: 100%;
  }
  /* Nascondi assolutamente tutto WP */
  #wpadminbar,
  .admin-bar { display: none !important; }
  body.admin-bar { padding-top: 0 !important; margin-top: 0 !important; }
  /* Nascondi qualsiasi elemento del tema */
  header, footer, nav, .site-header, .site-footer,
  .navbar, .main-navigation, #masthead, #colophon,
  .wp-site-blocks > header, .wp-site-blocks > footer { display: none !important; }
  /* Il wrapper del calendario occupa tutto */
  #cvcal-app {
      min-height: 100vh;
      max-width: 500px;
      margin: 0 auto;
  }
</style>
</head>
<body>
<div id="cvcal-app">
<?php
// Determina URL base per i link interni (navigazione mese, tab, ecc.)
$base_url = get_permalink(get_queried_object_id());
if (!$base_url) $base_url = home_url($_SERVER['REQUEST_URI']);
// Rimuovi query string dal base url, le aggiungeremo noi
$base_url = strtok($base_url, '?');

cvcal_render($base_url);
?>
</div>
<?php wp_footer(); ?>
</body>
</html>
