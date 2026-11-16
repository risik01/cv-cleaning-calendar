<?php
/**
 * Plugin Name: Casa Volterra Calendario Pulizie
 * Description: Calendario Smoobu per il servizio pulizie. Shortcode [cvcal].
 * Version:     4.2.0
 * Author:      Casa Volterra
 */
defined('ABSPATH') || exit;

// Carica pagina statistiche
require_once plugin_dir_path(__FILE__) . 'cleaning-stats.php';

/* ── AGGIORNAMENTI AUTOMATICI DA GITHUB ──
 *
 * Libreria: Plugin Update Checker 6.0 (licenza MIT), in /plugin-update-checker.
 * WordPress controlla il repository ogni 12 ore: quando l'header "Version" di
 * questo file sul branch main è più alto di quello installato, l'aggiornamento
 * compare in Plugin/Aggiornamenti come per i plugin ufficiali.
 *
 * Per pubblicare una versione: alza "Version" qui sopra e fai push su main.
 * (Se nel repo crei release o tag, hanno la precedenza sul branch: in quel
 * caso il numero di versione è quello del tag, es. v4.2.1.)
 *
 * Opzionali in wp-config.php:
 *   define('CVCAL_GITHUB_REPO', 'https://github.com/utente/altro-repo/');
 *   define('CV_GITHUB_TOKEN', 'ghp_...');  // serve solo se il repo è privato
 */
if (!defined('CVCAL_GITHUB_REPO'))
    define('CVCAL_GITHUB_REPO', 'https://github.com/risik01/cv-cleaning-calendar/');

function cvcal_github_updater() {
    static $checker = null;
    if ($checker !== null) return $checker;
    $checker = false;
    $library = plugin_dir_path(__FILE__) . 'plugin-update-checker/plugin-update-checker.php';
    if (PHP_VERSION_ID < 70400 || !is_readable($library)) return $checker;
    // Un problema dell'updater non deve mai bloccare il sito
    try {
        require_once $library;
        $factory = '\YahnisElsts\PluginUpdateChecker\v6\PucFactory';
        if (class_exists($factory)) {
            $checker = $factory::buildUpdateChecker(CVCAL_GITHUB_REPO, __FILE__, 'cv-cleaning-calendar');
            $checker->setBranch('main');
            if (defined('CV_GITHUB_TOKEN') && CV_GITHUB_TOKEN) $checker->setAuthentication(CV_GITHUB_TOKEN);
        }
    } catch (\Throwable $e) {
        $checker = false;
    }
    return $checker;
}
cvcal_github_updater();

define('CVCAL_CAP',   'view_cleaning_calendar');
define('CVCAL_CACHE', 'cvcal_v4');
define('CVCAL_TTL',   HOUR_IN_SECONDS);

/* ── ACTIVATION ── */
register_activation_hook(__FILE__, function() {
    if (!get_role('pulizie'))
        add_role('pulizie', 'Pulizie', ['read' => true, CVCAL_CAP => true]);
    else
        get_role('pulizie')->add_cap(CVCAL_CAP);
    $a = get_role('administrator'); if ($a) $a->add_cap(CVCAL_CAP);
});
register_deactivation_hook(__FILE__, function() { delete_transient(CVCAL_CACHE); });
function cvcal_uninstall() {
    // NON cancelliamo API key, apartment_id, page_id: sopravvivono alla reinstallazione
    delete_transient(CVCAL_CACHE);
    delete_transient('cvcal_all_v5');
    // Manteniamo il ruolo "pulizie" e le sue capability per non perdere gli utenti configurati
}
register_uninstall_hook(__FILE__, 'cvcal_uninstall');

/* ── REDIRECTS ── */
add_filter('login_redirect', function($to, $req, $user) {
    if (is_wp_error($user) || !in_array('pulizie', (array)($user->roles ?? []), true)) return $to;
    $pid = (int)get_option('cvcal_page_id', 0);
    return ($pid && ($u = get_permalink($pid))) ? $u : admin_url('admin.php?page=cv-cleaning-calendar');
}, 10, 3);
add_action('admin_init', function() {
    if (!is_admin() || wp_doing_ajax()) return;
    $u = wp_get_current_user();
    if (!$u || !in_array('pulizie', (array)$u->roles, true)) return;
    $s = get_current_screen();
    if ($s && $s->id === 'dashboard') { wp_redirect(admin_url('admin.php?page=cv-cleaning-calendar')); exit; }
});

/* ── FULLSCREEN TEMPLATE + LOGIN REDIRECT ──
 *
 * IMPORTANTE: la pagina WordPress con [cvcal] deve essere PUBBLICA
 * (non Privata). L'autenticazione è gestita dal plugin stesso.
 * Se la pagina è Privata WP restituisce 404 prima ancora che
 * il plugin possa intervenire.
 */

// Intercetta la richiesta: se non loggato → login, poi torna qui
add_action('template_redirect', function() {
    $pid = (int)get_option('cvcal_page_id', 0);
    if (!$pid) return;
    if (!is_page($pid)) return;
    if (!is_user_logged_in()) {
        wp_redirect(wp_login_url(get_permalink($pid)));
        exit;
    }
});

// Carica template fullscreen al posto del tema
add_filter('template_include', function($tpl) {
    $pid = (int)get_option('cvcal_page_id', 0);
    if (!$pid || !is_page($pid)) return $tpl;
    if (!is_user_logged_in()) return $tpl;
    $f = plugin_dir_path(__FILE__) . 'fullscreen.php';
    return file_exists($f) ? $f : $tpl;
}, 99);

/* ── MENU ── */
add_action('admin_menu', function() {
    add_menu_page('Calendario Pulizie','Calendario Pulizie', CVCAL_CAP,
        'cv-cleaning-calendar', 'cvcal_admin_page', 'dashicons-calendar-alt', 3);
    add_submenu_page('cv-cleaning-calendar','Impostazioni','Impostazioni',
        'manage_options','cv-cleaning-settings','cvcal_settings_page');
});
add_action('admin_head', function() {
    $s = get_current_screen();
    if (!$s || strpos($s->id,'cv-cleaning-calendar') === false) return;
    echo '<style>@media(max-width:782px){#adminmenuwrap,#adminmenuback{display:none!important}#wpadminbar{position:fixed!important}#wpcontent{margin-left:0!important;padding-left:0!important}.wrap{margin:0!important;padding:0!important}#wpfooter{display:none!important}}</style>';
});

/* ── SETTINGS ── */
add_action('admin_init', function() {
    register_setting('cvcal_grp','cvcal_api_key',      ['sanitize_callback'=>'sanitize_text_field']);
    register_setting('cvcal_grp','cvcal_api_secret',   ['sanitize_callback'=>'cvcal_sanitize_secret_option']);
    register_setting('cvcal_grp','cvcal_apartment_id', ['sanitize_callback'=>'absint']);
    register_setting('cvcal_grp','cvcal_page_id',      ['sanitize_callback'=>'absint']);
});

// Avviso in bacheca: API key presente ma secret mancante
add_action('admin_notices', function() {
    if (!current_user_can('manage_options')) return;
    if (!get_option('cvcal_api_key','') || cvcal_clean_secret(get_option('cvcal_api_secret','')) !== '') return;
    echo '<div class="notice notice-error"><p><strong>Calendario Pulizie — manca l\'API Secret di Smoobu.</strong><br>'
       . 'Dal 31/10/2026 Smoobu accetta solo richieste firmate: senza secret il calendario non carica le prenotazioni. '
       . '<a href="'.esc_url(admin_url('admin.php?page=cv-cleaning-settings')).'">Inseriscilo nelle impostazioni</a>.</p></div>';
});

function cvcal_settings_page() {
    if (!current_user_can('manage_options')) wp_die('Accesso negato.');
    if (isset($_POST['cvcal_clear_cache']) && check_admin_referer('cvcal_clear')) {
        delete_transient(CVCAL_CACHE);
        echo '<div class="notice notice-success is-dismissible"><p>✅ Cache svuotata.</p></div>';
    }
    if (isset($_POST['cvcal_test_conn']) && check_admin_referer('cvcal_test')) {
        $t = cvcal_smoobu_get('/api/reservations', [
            'pageSize'=>1, 'page'=>1,
            'from'=>date('Y-m-d'), 'to'=>date('Y-m-d', strtotime('+30 days')),
        ]);
        if (is_wp_error($t)) {
            echo '<div class="notice notice-error"><p>❌ <strong>Connessione a Smoobu non riuscita.</strong> '.esc_html($t->get_error_message()).'</p></div>';
        } elseif (cvcal_clean_secret(get_option('cvcal_api_secret','')) === '') {
            echo '<div class="notice notice-warning"><p>⚠️ Smoobu ha risposto, ma la richiesta <strong>non era firmata</strong>: inserisci l\'API Secret, altrimenti smetterà di funzionare.</p></div>';
        } else {
            delete_transient(CVCAL_CACHE);
            delete_transient('cvcal_all_v5');
            echo '<div class="notice notice-success is-dismissible"><p>✅ <strong>Connessione a Smoobu riuscita con richiesta firmata (HMAC).</strong> Cache svuotata.</p></div>';
        }
    }
    $pid      = (int)get_option('cvcal_page_id', 0);
    $page_url = $pid ? get_permalink($pid) : '';
    $share    = $page_url ?: admin_url('admin.php?page=cv-cleaning-calendar');
    $wa_link  = 'https://wa.me/?text='.rawurlencode("📅 Calendario Pulizie — Casa Volterra
Accedi qui:
".$share);
    $has_key    = (bool)get_option('cvcal_api_key','');
    $has_secret = cvcal_clean_secret(get_option('cvcal_api_secret','')) !== '';
    $has_id     = (bool)get_option('cvcal_apartment_id',0);

    // Avviso se la pagina selezionata è privata
    $page_status = $pid ? get_post_status($pid) : '';
    ?>
    <div class="wrap">
    <h1>⚙️ Casa Volterra Calendario Pulizie — Impostazioni</h1>

    <?php if ($page_status === 'private'): ?>
    <div class="notice notice-error">
      <p>⚠️ <strong>La pagina selezionata è impostata come "Privata".</strong><br>
      Questo causa un errore 404 per gli utenti non loggati. <br>
      Vai in <a href="<?php echo admin_url('post.php?post='.$pid.'&action=edit'); ?>">modifica pagina</a>
      e cambia lo stato in <strong>Pubblica</strong>. Il plugin gestisce già l'autenticazione internamente.</p>
    </div>
    <?php endif; ?>

    <?php if ($has_key && $has_secret && $has_id && $pid && $page_status === 'publish'): ?>
    <div class="notice notice-success">
      <p>✅ <strong>Tutto configurato correttamente.</strong>
      URL calendario: <a href="<?php echo esc_url($share); ?>" target="_blank"><?php echo esc_html($share); ?></a></p>
    </div>
    <?php endif; ?>

    <form method="post" action="options.php">
    <?php settings_fields('cvcal_grp'); ?>

    <h2>🔑 Connessione Smoobu <small style="font-size:13px;font-weight:400;color:#666">(da configurare una sola volta)</small></h2>
    <table class="form-table">
      <tr>
        <th><label for="cvcal_api_key">API Key Smoobu</label></th>
        <td>
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <input type="text" id="cvcal_api_key" name="cvcal_api_key"
                   value="<?php echo esc_attr(get_option('cvcal_api_key','')); ?>"
                   class="regular-text"
                   style="font-family:monospace;font-size:12px;<?php echo $has_key?'color:#059669;':'' ?>"
                   autocomplete="off" spellcheck="false">
            <button type="button" onclick="
              var f=document.getElementById('cvcal_api_key');
              var b=this;
              if(f.style.webkitTextSecurity==='disc'||f.getAttribute('data-hidden')==='1'){
                f.style.webkitTextSecurity='';f.style.textSecurity='';
                f.setAttribute('data-hidden','0');b.textContent='🙈 Nascondi';
              } else {
                f.style.webkitTextSecurity='disc';f.style.textSecurity='disc';
                f.setAttribute('data-hidden','1');b.textContent='👁 Mostra';
              }"
              style="background:#f0f0f0;border:1px solid #ccc;padding:5px 10px;border-radius:6px;cursor:pointer;font-size:12px">
              👁 Mostra
            </button>
            <?php if($has_key): ?>
            <span style="background:#dcfce7;color:#166534;padding:3px 10px;border-radius:6px;font-size:12px;font-weight:700">✅ Salvata</span>
            <?php else: ?>
            <span style="background:#fef3c7;color:#92400e;padding:3px 10px;border-radius:6px;font-size:12px;font-weight:700">⚠️ Non configurata</span>
            <?php endif; ?>
          </div>
          <p class="description" style="margin-top:6px">
            Smoobu → Impostazioni → Avanzate → <strong>Chiavi API</strong> → copia la chiave qui.
            <strong>Viene salvata nel database e non dovrai reinserirla.</strong>
          </p>
          <script>
          // Maschera il token al caricamento della pagina
          (function(){
            var f=document.getElementById('cvcal_api_key');
            if(f&&f.value){ f.style.webkitTextSecurity='disc'; f.style.textSecurity='disc'; f.setAttribute('data-hidden','1'); }
          })();
          </script>
        </td>
      </tr>
      <tr>
        <th><label for="cvcal_api_secret">API Secret Smoobu</label></th>
        <td>
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <input type="password" id="cvcal_api_secret" name="cvcal_api_secret" value=""
                   class="regular-text" style="font-family:monospace;font-size:12px"
                   autocomplete="new-password" spellcheck="false"
                   placeholder="<?php echo $has_secret ? 'Salvato — lascia vuoto per non cambiarlo' : 'Incolla qui il secret'; ?>">
            <?php if($has_secret): ?>
            <span style="background:#dcfce7;color:#166534;padding:3px 10px;border-radius:6px;font-size:12px;font-weight:700">✅ Salvato</span>
            <?php else: ?>
            <span style="background:#fee2e2;color:#991b1b;padding:3px 10px;border-radius:6px;font-size:12px;font-weight:700">⚠️ Mancante</span>
            <?php endif; ?>
          </div>
          <p class="description" style="margin-top:6px">
            <strong>Obbligatorio dal 31/10/2026:</strong> Smoobu accetta solo richieste firmate (HMAC).<br>
            Smoobu → Impostazioni → Avanzate → <strong>Chiavi API</strong> → genera il secret della chiave e incollalo qui (Smoobu lo mostra una volta sola).<br>
            Per sicurezza il secret salvato non viene mai mostrato in questa pagina.
          </p>
        </td>
      </tr>
      <tr>
        <th><label for="cvcal_apartment_id">ID Appartamento</label></th>
        <td>
          <input type="number" id="cvcal_apartment_id" name="cvcal_apartment_id"
                 value="<?php echo esc_attr(get_option('cvcal_apartment_id',0)); ?>"
                 class="small-text" min="0">
          <?php if($has_id): ?>
          <span style="background:#dcfce7;color:#166534;padding:3px 10px;border-radius:6px;font-size:12px;font-weight:700;margin-left:8px">✅ Salvato</span>
          <?php endif; ?>
          <p class="description">
            ID numerico dell'appartamento in Smoobu (nell'URL quando apri l'annuncio).<br>
            <strong>0</strong> = mostra tutte le unità. <strong>Viene salvato e non dovrai reinserirlo.</strong>
          </p>
        </td>
      </tr>
    </table>

    <h2>🔗 URL Calendario</h2>
    <table class="form-table">
      <tr>
        <th><label for="cvcal_page_id">Pagina WordPress</label></th>
        <td>
          <?php wp_dropdown_pages(['name'=>'cvcal_page_id','id'=>'cvcal_page_id','selected'=>$pid,
              'show_option_none'=>'— Seleziona pagina —','option_none_value'=>0,
              'post_status'=>'publish,private,draft']); ?>
          <p class="description">
            1. Crea una pagina WordPress con slug <code>calendario-pulizie</code><br>
            2. Nel contenuto inserisci: <code>[cvcal]</code><br>
            3. Imposta stato: <strong>Pubblica</strong> (non Privata — l'auth è gestita dal plugin)<br>
            4. Seleziona la pagina qui sopra e salva
          </p>
        </td>
      </tr>
    </table>
    <?php submit_button('Salva Impostazioni'); ?>
    </form>

    <hr>
    <h2>🔌 Verifica connessione Smoobu</h2>
    <p>Dopo aver salvato chiave e secret, controlla che Smoobu accetti le richieste firmate.</p>
    <form method="post"><?php wp_nonce_field('cvcal_test'); ?>
    <input type="hidden" name="cvcal_test_conn" value="1">
    <?php submit_button('Verifica connessione','secondary'); ?>
    </form>

    <hr>
    <h2>📲 Condividi con l'impresa di pulizie</h2>
    <p>URL: <code style="background:#f0f0f0;padding:4px 10px;border-radius:5px;word-break:break-all"><?php echo esc_html($share); ?></code></p>
    <a href="<?php echo esc_url($wa_link); ?>" target="_blank"
       style="display:inline-flex;align-items:center;gap:8px;background:#25D366;color:#fff;padding:11px 20px;border-radius:9px;text-decoration:none;font-weight:700;margin-top:8px">
       📲 Invia link via WhatsApp</a>
    <p class="description" style="margin-top:8px">
      L'impresa deve avere un account WordPress con ruolo <strong>"Pulizie"</strong>.
      Vai in <a href="<?php echo admin_url('users.php'); ?>">Utenti</a> per crearlo o verificarlo.
    </p>

    <hr>
    <h2>🗑️ Cache</h2>
    <p>Le prenotazioni vengono aggiornate automaticamente ogni ora. Svuota manualmente per vedere subito le ultime modifiche da Smoobu.</p>
    <form method="post"><?php wp_nonce_field('cvcal_clear'); ?>
    <input type="hidden" name="cvcal_clear_cache" value="1">
    <?php submit_button('Svuota Cache Ora','secondary'); ?>
    </form>
    </div>
    <?php
}

/* ── API SMOOBU — richieste firmate HMAC (obbligatorie dal 31/10/2026) ──
 *
 * Specifica: docs.smoobu.com → HMAC Authentication.
 * Header: X-API-Key, X-Timestamp (UTC ISO 8601), X-Nonce (UUID v4),
 *         X-Signature = base64( HMAC-SHA256( stringa canonica, API secret ) ).
 * Stringa canonica (righe separate da "\n"): METODO, path, query ordinata,
 *         timestamp, nonce, SHA-256 esadecimale del body, API key.
 */

// Ripulisce un secret incollato: via spazi, a-capo e caratteri di controllo.
// (sanitize_text_field altererebbe i secret con sequenze tipo "%2B" o "<")
function cvcal_clean_secret($v): string {
    return is_scalar($v) ? preg_replace('/[\x00-\x20\x7F]+/', '', (string)$v) : '';
}
// Il campo secret del form è sempre vuoto: vuoto = tieni quello già salvato
function cvcal_sanitize_secret_option($v) {
    $v = cvcal_clean_secret($v);
    return $v !== '' ? $v : cvcal_clean_secret(get_option('cvcal_api_secret',''));
}
// Query canonica: chiavi in ordine alfabetico, codifica RFC 3986 (spazio = %20).
// La stessa stringa va sia nell'URL sia nella firma.
function cvcal_canonical_query(array $params): string {
    $clean = [];
    foreach ($params as $k => $v) {
        if ($v === null || is_array($v) || is_object($v)) continue;
        if (is_bool($v)) $v = $v ? '1' : '0';
        $clean[(string)$k] = (string)$v;
    }
    ksort($clean, SORT_STRING);
    $pairs = [];
    foreach ($clean as $k => $v) $pairs[] = rawurlencode((string)$k).'='.rawurlencode($v);
    return implode('&', $pairs);
}
// $ts e $nonce si passano solo nei test: in produzione vengono generati qui
// (Smoobu rifiuta timestamp fuori di 5 minuti e nonce riusati).
function cvcal_signed_headers(string $method, string $path, string $query, string $body,
                              string $key, string $secret, ?string $ts = null, ?string $nonce = null): array {
    $ts    = $ts    ?: gmdate('Y-m-d\TH:i:s\Z');
    $nonce = $nonce ?: wp_generate_uuid4();
    $canonical = implode("\n", [strtoupper($method), $path, $query, $ts, $nonce, hash('sha256', $body), $key]);
    return [
        'X-API-Key'   => $key,
        'X-Timestamp' => $ts,
        'X-Nonce'     => $nonce,
        'X-Signature' => base64_encode(hash_hmac('sha256', $canonical, $secret, true)),
    ];
}
function cvcal_explain_401($response, bool $signed): string {
    if (!$signed) return 'Smoobu ha rifiutato la richiesta (HTTP 401). Dal 31/10/2026 serve la firma HMAC: inserisci l\'API Secret in Impostazioni.';
    $msg = 'Autenticazione Smoobu fallita (HTTP 401): controlla che API Key e API Secret siano quelli della stessa chiave.';
    // La firma scade se l'orologio del server è sfasato di oltre 5 minuti
    $d  = wp_remote_retrieve_header($response, 'date');
    $st = $d ? strtotime($d) : 0;
    if ($st && abs(time() - $st) > 240)
        $msg .= sprintf(' Attenzione: l\'orologio di questo server è sfasato di circa %d secondi rispetto a Smoobu (tolleranza 5 minuti).', abs(time() - $st));
    return $msg;
}
// GET firmata verso Smoobu. $path include /api (es. '/api/reservations').
// Ritorna il JSON decodificato (array) oppure WP_Error.
function cvcal_smoobu_get(string $path, array $params = [], int $timeout = 20) {
    $key    = trim((string)get_option('cvcal_api_key',''));
    $secret = cvcal_clean_secret(get_option('cvcal_api_secret',''));
    if ($key === '') return new WP_Error('no_key','API Key Smoobu non configurata. Vai in Impostazioni.');
    $query = cvcal_canonical_query($params);
    $url   = 'https://login.smoobu.com'.$path.($query !== '' ? '?'.$query : '');
    $headers = $secret !== ''
        ? cvcal_signed_headers('GET', $path, $query, '', $key, $secret)
        : ['Api-Key' => $key]; // nessun secret: vecchio header, rifiutato da Smoobu dopo il 31/10/2026
    $headers['Cache-Control'] = 'no-cache';
    $r = wp_remote_get($url, ['headers'=>$headers,'timeout'=>$timeout]);
    if (is_wp_error($r)) return $r;
    $code = wp_remote_retrieve_response_code($r);
    if ($code===401) return new WP_Error('auth', cvcal_explain_401($r, $secret !== ''));
    if ($code!==200) return new WP_Error('api',"Errore Smoobu: HTTP $code");
    $body = json_decode(wp_remote_retrieve_body($r), true);
    return is_array($body) ? $body : [];
}

function cvcal_fetch() {
    $cached = get_transient(CVCAL_CACHE);
    if ($cached !== false) return $cached;
    $apid = (int)get_option('cvcal_apartment_id',0);
    $all=[]; $page=1; $pages=1;
    $from = date('Y-m-d', strtotime('-6 months'));
    $to   = date('Y-m-d', strtotime('+12 months'));
    do {
        $body = cvcal_smoobu_get('/api/reservations', ['pageSize'=>100,'page'=>$page,'from'=>$from,'to'=>$to]);
        if (is_wp_error($body)) return $body;
        if (!isset($body['bookings'])) break;
        $pages = max(1,(int)(($body['page_count'] ?? $body['pageCount'] ?? 1)??1));
        foreach ($body['bookings'] as $b) {
            if ($apid>0 && isset($b['apartment']['id']) && (int)$b['apartment']['id']!==$apid) continue;
            $type = strtolower($b['type']??'reservation');
            if ($type==='block'||$type==='unavailability'||$type==='cancellation'||strpos($type,'cancell')!==false) continue;
            $all[] = $b;
        }
        $page++;
    } while ($page<=$pages);
    usort($all, fn($a,$b)=>strcmp($b['arrival']??'',$a['arrival']??''));
    set_transient(CVCAL_CACHE, $all, CVCAL_TTL);
    return $all;
}

/* ── HELPERS ── */
function cvcal_name(array $b): string {
    // Campo principale dell'API Smoobu v1: 'guest-name' (con trattino)
    $full = trim($b['guest-name'] ?? '');
    if ($full) return $full;
    // Campi alternativi (alcune versioni/canali)
    $f = trim($b['guestFirstName'] ?? $b['firstname'] ?? $b['first_name'] ?? $b['firstName'] ?? '');
    $l = trim($b['guestLastName']  ?? $b['lastname']  ?? $b['last_name']  ?? $b['lastName']  ?? '');
    if (!$f && !$l && isset($b['guest']) && is_array($b['guest'])) {
        $g = $b['guest'];
        $f = trim($g['firstName'] ?? $g['firstname'] ?? $g['name'] ?? '');
        $l = trim($g['lastName']  ?? $g['lastname']  ?? '');
    }
    if (!$f && !$l) {
        $full2 = trim($b['guestName'] ?? $b['name'] ?? '');
        if ($full2) return $full2;
    }
    $n = trim("$f $l");
    return $n !== '' ? $n : '';
}
function cvcal_short(array $b, int $max=9): string {
    $full = cvcal_name($b);
    if (!$full) return '—';
    $parts = explode(' ', $full);
    // Mostra cognome (ultima parola) se c'è più di una parola
    $show = count($parts) > 1 ? end($parts) : $parts[0];
    return mb_substr(ucfirst(mb_strtolower(trim($show))), 0, $max);
}
function cvcal_pax(array $b): string {
    $a=(int)($b['adults']??0); $c=(int)($b['children']??0); $p=[];
    if ($a>0) $p[]=$a.' adult'.($a>1?'i':'o');
    if ($c>0) $p[]=$c.' bambin'.($c>1?'i':'o');
    return implode(' + ', $p);
}
function cvcal_tot_pax(array $b): int {
    return (int)($b['adults']??0) + (int)($b['children']??0);
}
function cvcal_fmt_date(string $d): string {
    if (!$d) return '—';
    static $dy=['Dom','Lun','Mar','Mer','Gio','Ven','Sab'];
    static $mo=['','Gen','Feb','Mar','Apr','Mag','Giu','Lug','Ago','Set','Ott','Nov','Dic'];
    $ts=strtotime($d);
    return $dy[(int)date('w',$ts)].' '.date('d',$ts).' '.$mo[(int)date('n',$ts)];
}
function cvcal_month_it(string $mk): string {
    static $m=['01'=>'Gennaio','02'=>'Febbraio','03'=>'Marzo','04'=>'Aprile','05'=>'Maggio',
               '06'=>'Giugno','07'=>'Luglio','08'=>'Agosto','09'=>'Settembre',
               '10'=>'Ottobre','11'=>'Novembre','12'=>'Dicembre'];
    [$y,$n]=explode('-',$mk); return ($m[$n]??$n).' '.$y;
}
function cvcal_ota_badge(string $ch): string {
    if (!$ch) return '';
    $cl=strtolower($ch);
    if ((strpos($cl,'airbnb') !== false))      return "<span class='ota ota-ab'>Airbnb</span>";
    if ((strpos($cl,'booking') !== false))     return "<span class='ota ota-bk'>Booking.com</span>";
    if ((strpos($cl,'vrbo') !== false)||(strpos($cl,'homeaway') !== false)) return "<span class='ota ota-vb'>VRBO</span>";
    if ((strpos($cl,'expedia') !== false))     return "<span class='ota ota-ex'>Expedia</span>";
    if ((strpos($cl,'tripadvisor') !== false)) return "<span class='ota ota-ta'>TripAdvisor</span>";
    if ((strpos($cl,'agoda') !== false))       return "<span class='ota ota-ag'>Agoda</span>";
    if ((strpos($cl,'google') !== false))      return "<span class='ota ota-go'>Google</span>";
    if ((strpos($cl,'direct') !== false)||(strpos($cl,'diretto') !== false)||(strpos($cl,'smoobu') !== false))
        return "<span class='ota ota-di'>Diretto</span>";
    return "<span class='ota ota-xx'>".esc_html($ch)."</span>";
}
function cvcal_ota_class(string $ch): string {
    $cl=strtolower($ch);
    if ((strpos($cl,'airbnb') !== false))  return 'ob-ab';
    if ((strpos($cl,'booking') !== false)) return 'ob-bk';
    if ((strpos($cl,'vrbo') !== false)||(strpos($cl,'homeaway') !== false)) return 'ob-vb';
    if ((strpos($cl,'expedia') !== false)) return 'ob-ex';
    if ((strpos($cl,'trip') !== false))    return 'ob-ta';
    if ((strpos($cl,'agoda') !== false))   return 'ob-ag';
    return 'ob-di';
}

/* ── CARD HTML ── */
function cvcal_card(array $b, string $today, string $tomorrow): string {
    $arr=$b['arrival']??''; $dep=$b['departure']??'';
    $name=cvcal_name($b)?:'(nome non disp.)';
    $nights=(int)($b['nights']??0);
    $ch=$b['channel']['name']??'';
    $notes=trim($b['guestNotes']??'');
    $ci_t=$b['check-in']??$b['checkIn']??''; $co_t=$b['check-out']??$b['checkOut']??'';
    $pax=cvcal_pax($b);
    if      ($arr===$today)    {$cc='c-ci';  $badge='✅ Check-in OGGI';  $bc='b-green';}
    elseif  ($dep===$today)    {$cc='c-co';  $badge='🧹 Check-out OGGI';$bc='b-orange';}
    elseif  ($arr===$tomorrow) {$cc='c-tom'; $badge='📅 Domani';         $bc='b-blue';}
    elseif  ($arr<$today)      {$cc='c-old'; $badge=''; $bc='';}
    else                        {$cc='c-fut'; $badge=''; $bc='';}
    ob_start(); ?>
    <div class="cvcal-card <?php echo $cc;?>">
      <div class="cc-top">
        <div class="cc-guest"><?php echo esc_html($name);?></div>
        <?php if($badge):?><div class="cc-badge <?php echo $bc;?>"><?php echo $badge;?></div><?php endif;?>
      </div>
      <?php if($nights>0):?><div class="cc-nights">🌙 <?php echo $nights;?> notte<?php echo $nights!==1?'i':'';?></div><?php endif;?>
      <div class="cc-dates">
        <div class="cc-dbox">
          <div class="cc-dlbl">📥 Check-in</div>
          <div class="cc-dval"><?php echo esc_html(cvcal_fmt_date($arr));?></div>
          <?php if($ci_t):?><div class="cc-dtime">⏰ <?php echo esc_html($ci_t);?></div><?php endif;?>
        </div>
        <div class="cc-dbox">
          <div class="cc-dlbl">📤 Check-out</div>
          <div class="cc-dval"><?php echo esc_html(cvcal_fmt_date($dep));?></div>
          <?php if($co_t):?><div class="cc-dtime">⏰ <?php echo esc_html($co_t);?></div><?php endif;?>
        </div>
      </div>
      <div class="cc-meta">
        <?php if($pax):?><span class="cc-pill">👥 <?php echo esc_html($pax);?></span><?php endif;?>
        <?php echo cvcal_ota_badge($ch);?>
      </div>
      <?php if($notes):?><div class="cc-notes">💬 <?php echo esc_html($notes);?></div><?php endif;?>
    </div>
    <?php return ob_get_clean();
}

/* ── MAIN RENDER ── */
function cvcal_render(string $base_url): void {
    if (!is_user_logged_in() || !current_user_can(CVCAL_CAP)) {
        echo '<div class="cvcal-login"><p>🔒 Accesso riservato al personale autorizzato.</p></div>';
        return;
    }
    $bk = cvcal_fetch();
    $today    = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $view = sanitize_text_field($_GET['view'] ?? 'calendar');
    $cm   = sanitize_text_field($_GET['month'] ?? date('Y-m'));
    if (!preg_match('/^\d{4}-\d{2}$/',$cm)) $cm = date('Y-m');
    $pm = date('Y-m', strtotime($cm.'-01 -1 month'));
    $nm = date('Y-m', strtotime($cm.'-01 +1 month'));

    // Categorize
    $ci_t=$co_t=$up=$past=[];
    if (!is_wp_error($bk)) {
        foreach ($bk as $b) {
            $a=$b['arrival']??''; $d=$b['departure']??'';
            if      ($a===$today) $ci_t[]=$b;
            elseif  ($d===$today) $co_t[]=$b;
            elseif  ($a>$today)   $up[]=$b;
            else                  $past[]=$b;
        }
        usort($up, fn($a,$b)=>strcmp($a['arrival']??'',$b['arrival']??''));
    }
    $tot_f=count($ci_t)+count($co_t)+count($up);
    $tot_p=count($past);
    $tot_a=is_wp_error($bk)?0:count($bk);

    // Build day_map (CI/CO events per day) and stay_map (occupied intermediate days)
    $day_map=[]; $stay_map=[];
    if (!is_wp_error($bk)) {
        foreach ($bk as $b) {
            $a=$b['arrival']??''; $d=$b['departure']??'';
            if ($a) $day_map[$a][]=['t'=>'ci','b'=>$b];
            if ($d) $day_map[$d][]=['t'=>'co','b'=>$b];
            if ($a && $d) {
                $ts_a=strtotime($a); $ts_d=strtotime($d);
                for ($ts=$ts_a+86400; $ts<$ts_d; $ts+=86400)
                    $stay_map[date('Y-m-d',$ts)]=true;
            }
        }
    }

    // Stats
    $s_dom=0; $s_wk=0; $we=date('Y-m-d',strtotime('+7 days'));
    foreach ($up as $b) { $a=$b['arrival']??'';
        if ($a===$tomorrow) $s_dom++;
        if ($a>=$today&&$a<=$we) $s_wk++;
    }

    // List filter
    $lf = sanitize_text_field($_GET['lf'] ?? 'upcoming');
    if ($lf === 'past')     $show_list = $past;
    elseif ($lf === 'all')  $show_list = array_merge(array_merge($ci_t,$co_t,$up),$past);
    else                    $show_list = array_merge($ci_t,$co_t,$up);

    $burl = function($args) use ($base_url) { return add_query_arg($args, $base_url); };

    // Pass full JSON to JS
    $bk_json = json_encode(is_wp_error($bk)?[]:$bk, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE);
    ?>
<div id="cvcal-wrap">

<!-- HEADER -->
<div class="cvcal-hdr">
  <div><div class="ch-t">🏠 Calendario Pulizie</div><div class="ch-s">Casa Volterra · <?php echo date('d/m/Y');?></div></div>
  <div class="ch-act">
    <?php if(!is_wp_error($bk)):?><button id="cvcal-copy-btn" class="ch-btn ch-green" onclick="cvcalCopy()">📋 Copia mese</button><?php endif;?>
    <?php if(current_user_can('manage_options')):?><a href="<?php echo admin_url('admin.php?page=cv-cleaning-settings');?>" class="ch-btn">⚙️</a><?php endif;?>
  </div>
</div>

<?php if(is_wp_error($bk)):?>
<div class="cvcal-err"><span>⚠️</span><div><b>Errore caricamento</b><br><?php echo esc_html($bk->get_error_message());?></div></div>
<?php else:?>

<!-- STATS -->
<div class="cvcal-stats">
  <div class="cs cs-g"><div class="csn"><?php echo count($ci_t);?></div><div class="csl">CI Oggi</div></div>
  <div class="cs cs-o"><div class="csn"><?php echo count($co_t);?></div><div class="csl">CO Oggi</div></div>
  <div class="cs cs-b"><div class="csn"><?php echo $s_dom;?></div><div class="csl">CI Dom.</div></div>
  <div class="cs"><div class="csn"><?php echo $s_wk;?></div><div class="csl">CI Sett.</div></div>
  <div class="cs cs-p"><div class="csn"><?php echo $tot_f;?></div><div class="csl">Future</div></div>
</div>

<!-- VIEW TABS -->
<div class="cvcal-vbar">
  <a href="<?php echo esc_url($burl(['view'=>'calendar','month'=>$cm]));?>" class="vt <?php echo $view==='calendar'?'vt-on':'';?>">📅 Calendario</a>
  <a href="<?php echo esc_url($burl(['view'=>'list','month'=>$cm]));?>" class="vt <?php echo $view==='list'?'vt-on':'';?>">☰ Lista</a>
</div>

<?php if($view==='calendar'):?>
<!-- ═══ CALENDAR ═══ -->
<div class="cal-nav">
  <a href="<?php echo esc_url($burl(['view'=>'calendar','month'=>$pm]));?>" class="cal-navbtn">‹</a>
  <div class="cal-mtitle"><?php echo esc_html(cvcal_month_it($cm));?></div>
  <a href="<?php echo esc_url($burl(['view'=>'calendar','month'=>$nm]));?>" class="cal-navbtn">›</a>
</div>

<div class="cal-grid">
  <?php foreach(['L','M','M','G','V','S','D'] as $h) echo '<div class="cal-dh">'.$h.'</div>';?>
  <?php
  $ft=strtotime($cm.'-01'); $dt=(int)date('t',$ft); $fd=(int)date('N',$ft);
  for($i=1;$i<$fd;$i++) echo '<div class="cal-e"></div>';
  for($d=1;$d<=$dt;$d++){
    $ds=$cm.'-'.str_pad($d,2,'0',STR_PAD_LEFT);
    $evs=$day_map[$ds]??[];
    $is_stay=isset($stay_map[$ds]);
    $hci=$hco=false;
    foreach($evs as $e){if($e['t']==='ci')$hci=true;if($e['t']==='co')$hco=true;}
    $cls='cal-c';
    if($ds===$today) $cls.=' cal-td';
    if    ($hci&&$hco) $cls.=' cal-turn';
    elseif($hci)       $cls.=' cal-ci';
    elseif($hco)       $cls.=' cal-co';
    elseif($is_stay)   $cls.=' cal-stay';
    if(!empty($evs)) $cls.=' cal-ev';
    $onclick=!empty($evs)?sprintf(' onclick="cvcalDay(\'%s\')"',esc_js($ds)):'';
    echo '<div class="'.$cls.'"'.$onclick.'>';
    echo '<div class="cal-n">'.$d.'</div>';
    // Info ospite nella cella
    if(!empty($evs)){
      foreach($evs as $e){
        $eb=$e['b']; $et=$e['t'];
        $sn=cvcal_short($eb,8);
        $tp=cvcal_tot_pax($eb);
        $oc=cvcal_ota_class($eb['channel']['name']??'');
        echo '<div class="cel cel-'.$et.'">';
        echo '<div class="cel-bar '.$oc.'"></div>';
        echo '<div class="cel-lbl">'.($et==='ci'?'Arrivo':'Partenza').'</div>';
        echo '<div class="cel-nm">'.esc_html($sn).'</div>';
        if($tp>0) echo '<div class="cel-px">👤'.$tp.'</div>';
        echo '</div>';
      }
    } elseif($is_stay){
      foreach((!is_wp_error($bk)?$bk:[]) as $sb){
        if(strtotime($ds)>strtotime($sb['arrival']??'')&&strtotime($ds)<strtotime($sb['departure']??'')){
          echo '<div class="cel-sty">'.esc_html(cvcal_short($sb,8)).'</div>';
          break;
        }
      }
    }
    echo '</div>';
  }
  $ld=(int)date('N',strtotime($cm.'-'.$dt));
  for($i=$ld;$i<7;$i++) echo '<div class="cal-e"></div>';
  ?>
</div>

<div class="cal-leg">
  <span class="lb lb-free"></span>Libero&nbsp;&nbsp;
  <span class="lb lb-occ"></span>Occupato&nbsp;&nbsp;
  <span class="lb lb-co"></span>Check-out&nbsp;&nbsp;
  <span class="lb lb-turn"></span>Cambio ospite
</div>

<!-- PANNELLO GIORNO (sempre presente nel DOM) -->
<div id="cvcal-panel" style="display:none">
  <div class="panel-hdr">
    <div id="panel-title" class="panel-ttl"></div>
    <button class="panel-x" onclick="cvcalClose()">✕</button>
  </div>
  <div id="panel-body" class="panel-bd"></div>
</div>

<?php else:?>
<!-- ═══ LIST ═══ -->
<div class="lst-tabs">
  <?php $lb=add_query_arg(['view'=>'list','month'=>$cm],$base_url).'&lf=';?>
  <a href="<?php echo esc_url($lb.'upcoming');?>" class="lt <?php echo $lf==='upcoming'?'lt-on':'';?>">📅 Arrivi <span class="ltc"><?php echo $tot_f;?></span></a>
  <a href="<?php echo esc_url($lb.'past');?>" class="lt <?php echo $lf==='past'?'lt-on':'';?>">✓ Passate <span class="ltc"><?php echo $tot_p;?></span></a>
  <a href="<?php echo esc_url($lb.'all');?>" class="lt <?php echo $lf==='all'?'lt-on':'';?>">📋 Tutte <span class="ltc"><?php echo $tot_a;?></span></a>
</div>
<div class="lst-body">
  <?php if(empty($show_list)):?>
    <div class="cvcal-empty"><span>📭</span><p>Nessuna prenotazione</p></div>
  <?php else:
    $gr=[];
    foreach($show_list as $b){ $mk=substr($b['arrival']??$today,0,7); $gr[$mk][]=$b; }
    foreach($gr as $mk=>$ml): $n=count($ml);?>
    <div class="lst-mgrp">
      <div class="lst-mhdr"><?php echo esc_html(cvcal_month_it($mk));?><span class="lst-mcnt"><?php echo $n;?> prenotazion<?php echo $n===1?'e':'i';?></span></div>
      <?php foreach($ml as $b) echo cvcal_card($b,$today,$tomorrow);?>
    </div>
    <?php endforeach; endif;?>
</div>
<?php endif;?>

<!-- JS DATA + FUNCTIONS -->
<script>
const BK = <?php echo $bk_json;?>;
const CM = '<?php echo esc_js($cm);?>';
const DY = ['Dom','Lun','Mar','Mer','Gio','Ven','Sab'];
const MS = ['','Gen','Feb','Mar','Apr','Mag','Giu','Lug','Ago','Set','Ott','Nov','Dic'];
const MF = ['','Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno','Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'];

function xn(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function fd(s){ if(!s) return '—'; const d=new Date(s+'T00:00:00'); return DY[d.getDay()]+' '+String(d.getDate()).padStart(2,'0')+' '+MS[d.getMonth()+1]; }

// Risolve il nome ospite da tutti i possibili campi Smoobu
function gn(b){
  let f=(b.guestFirstName||b.firstname||b.first_name||b.firstName||'').trim();
  let l=(b.guestLastName ||b.lastname ||b.last_name ||b.lastName ||'').trim();
  if(!f&&!l&&b.guest){ f=(b.guest.firstName||b.guest.firstname||b.guest.name||'').trim(); l=(b.guest.lastName||b.guest.lastname||'').trim(); }
  if(!f&&!l){ const v=(b.guestName||b.name||'').trim(); if(v){const p=v.split(' ');f=p[0];l=p.slice(1).join(' ');} }
  return (l||f||'').trim()||null;
}
function pax(b){ const a=parseInt(b.adults)||0,c=parseInt(b.children)||0,p=[];
  if(a>0)p.push(a+(a===1?' adulto':' adulti')); if(c>0)p.push(c+(c===1?' bambino':' bambini')); return p.join(' + '); }
function tot(b){ return (parseInt(b.adults)||0)+(parseInt(b.children)||0); }

function otaBadge(ch){
  if(!ch) return '';
  const c=ch.toLowerCase();
  if(c.includes('airbnb'))       return `<span class='ota ota-ab'>Airbnb</span>`;
  if(c.includes('booking'))      return `<span class='ota ota-bk'>Booking.com</span>`;
  if(c.includes('vrbo')||c.includes('homeaway')) return `<span class='ota ota-vb'>VRBO</span>`;
  if(c.includes('expedia'))      return `<span class='ota ota-ex'>Expedia</span>`;
  if(c.includes('tripadvisor'))  return `<span class='ota ota-ta'>TripAdvisor</span>`;
  if(c.includes('agoda'))        return `<span class='ota ota-ag'>Agoda</span>`;
  if(c.includes('google'))       return `<span class='ota ota-go'>Google</span>`;
  if(c.includes('direct')||c.includes('diretto')||c.includes('smoobu')) return `<span class='ota ota-di'>Diretto</span>`;
  return `<span class='ota ota-xx'>${xn(ch)}</span>`;
}

// ── TAP SUL GIORNO → PANNELLO DETTAGLIO ──
function cvcalDay(dateStr){
  const entries=[];
  BK.forEach(b=>{ if(b.arrival===dateStr) entries.push({t:'ci',b}); if(b.departure===dateStr) entries.push({t:'co',b}); });
  if(!entries.length) return;

  const d=new Date(dateStr+'T00:00:00');
  document.getElementById('panel-title').textContent='📅 '+DY[d.getDay()]+' '+d.getDate()+' '+MF[d.getMonth()+1];

  let html='';
  entries.forEach(({t,b})=>{
    const isCi=t==='ci';
    const name=gn(b)||'(nome non disponibile)';
    const badge=isCi?'✅ Check-in':'🧹 Check-out';
    const bc=isCi?'b-green':'b-orange';
    const cc=isCi?'c-ci':'c-co';
    const nights=parseInt(b.nights)||0;
    const p=pax(b); const ch=b.channel?.name||''; const ota=otaBadge(ch);
    const notes=b.guestNotes||'';
    const ciTime=b.checkIn||''; const coTime=b.checkOut||'';
    html+=`<div class="cvcal-card ${cc}">
      <div class="cc-top"><div class="cc-guest">${xn(name)}</div><div class="cc-badge ${bc}">${badge}</div></div>
      ${nights?`<div class="cc-nights">🌙 ${nights} notte${nights===1?'':'i'}</div>`:''}
      <div class="cc-dates">
        <div class="cc-dbox"><div class="cc-dlbl">📥 Check-in</div><div class="cc-dval">${fd(b.arrival)}</div>${ciTime?`<div class="cc-dtime">⏰ ${xn(ciTime)}</div>`:''}</div>
        <div class="cc-dbox"><div class="cc-dlbl">📤 Check-out</div><div class="cc-dval">${fd(b.departure)}</div>${coTime?`<div class="cc-dtime">⏰ ${xn(coTime)}</div>`:''}</div>
      </div>
      <div class="cc-meta">${p?`<span class="cc-pill">👥 ${xn(p)}</span>`:''}${ota}</div>
      ${notes?`<div class="cc-notes">💬 ${xn(notes)}</div>`:''}
    </div>`;
  });

  document.getElementById('panel-body').innerHTML=html;
  const panel=document.getElementById('cvcal-panel');
  panel.style.display='block';
  setTimeout(()=>panel.scrollIntoView({behavior:'smooth',block:'start'}),80);
}
function cvcalClose(){ document.getElementById('cvcal-panel').style.display='none'; }

// ── COPIA MESE ──
function cvcalCopy(){
  const [y,m]=CM.split('-').map(Number);
  const mName=MF[m]+' '+y;

  // Una riga per prenotazione, filtra quelle con CI o CO nel mese
  const list=[];
  BK.forEach(b=>{
    const arrM=(b.arrival||'').slice(0,7);
    const depM=(b.departure||'').slice(0,7);
    if(arrM===CM||depM===CM) list.push(b);
  });
  list.sort((a,b)=>(a.arrival||'').localeCompare(b.arrival||''));

  if(!list.length){ alert('Nessuna prenotazione per '+mName); return; }

  const dd=s=>{ if(!s) return '—'; const[yy,mm,gg]=s.split('-'); return gg+'/'+mm+'/'+yy; };
  const now=new Date();
  const oggi=String(now.getDate()).padStart(2,'0')+'/'+String(now.getMonth()+1).padStart(2,'0')+'/'+now.getFullYear();

  let txt='🏠 Casa Volterra — '+mName+'\n';
  txt+='─────────────────────\n\n';
  list.forEach(b=>{
    const name=gn(b)||'—';
    const tp=tot(b);
    const ch=(b.channel?.name||'Diretto').replace('VRBO / HomeAway','VRBO');
    let line=dd(b.arrival)+' → '+dd(b.departure);
    line+=' · '+name;
    if(tp>0) line+=' · '+tp+(tp===1?' ospite':' ospiti');
    line+=' · '+ch;
    txt+=line+'\n';
  });
  txt+='\n─────────────────────\n';
  txt+='Estratto il '+oggi;

  const btn=document.getElementById('cvcal-copy-btn');
  const ok=()=>{ btn.textContent='✅ Copiato!'; setTimeout(()=>btn.textContent='📋 Copia mese',2500); };
  if(navigator.clipboard?.writeText) navigator.clipboard.writeText(txt).then(ok).catch(()=>fb(txt));
  else fb(txt);
  function fb(t){ const ta=document.createElement('textarea'); ta.value=t;
    ta.style.cssText='position:fixed;opacity:0'; document.body.appendChild(ta);
    ta.select(); document.execCommand('copy'); document.body.removeChild(ta); ok(); }
}
</script>

<?php endif; // !is_wp_error ?>
</div><!-- #cvcal-wrap -->
<?php cvcal_css(); ?>
<?php
}

/* ── ADMIN PAGE ── */
function cvcal_admin_page() {
    if (!current_user_can(CVCAL_CAP)) wp_die('Accesso negato.');
    echo '<div class="wrap">';
    cvcal_render(admin_url('admin.php?page=cv-cleaning-calendar'));
    echo '</div>';
}

/* ── SHORTCODE ── */
add_shortcode('cvcal', function() {
    $url = get_permalink() ?: home_url(add_query_arg([]));
    $url = strtok($url,'?');
    ob_start(); cvcal_render($url); return ob_get_clean();
});
add_filter('use_block_editor_for_post', function($use,$post) {
    $pid=(int)get_option('cvcal_page_id',0);
    return ($pid&&(int)$post->ID===$pid)?false:$use;
}, 10, 2);

/* ── CSS ── */
function cvcal_css(){ ?>
<style>
*,*::before,*::after{box-sizing:border-box}
#cvcal-wrap{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f0f2f5;min-height:80vh;padding:0!important;margin:0!important;color:#111}

/* HEADER */
.cvcal-hdr{background:linear-gradient(135deg,#1a1a2e,#16213e);color:#fff;padding:12px 14px;display:flex;align-items:center;justify-content:space-between;gap:8px}
.ch-t{font-size:16px;font-weight:700} .ch-s{font-size:11px;opacity:.6;margin-top:2px}
.ch-act{display:flex;gap:7px;align-items:center}
.ch-btn{background:rgba(255,255,255,.15);border:none;color:#fff;font-size:13px;font-weight:700;padding:7px 11px;border-radius:8px;cursor:pointer;text-decoration:none;display:inline-block;white-space:nowrap}
.ch-green{background:#059669!important}

/* STATS */
.cvcal-stats{display:flex;gap:6px;padding:10px 12px;background:#fff;border-bottom:1px solid #e8e8e8;overflow-x:auto;scrollbar-width:none}
.cvcal-stats::-webkit-scrollbar{display:none}
.cs{flex-shrink:0;background:#f8f9fa;border-radius:10px;padding:7px 11px;text-align:center;min-width:55px;border:1px solid #eee}
.cs-g{background:#ecfdf5;border-color:#6ee7b7} .cs-o{background:#fff7ed;border-color:#fcd34d}
.cs-b{background:#eff6ff;border-color:#93c5fd} .cs-p{background:#f5f3ff;border-color:#c4b5fd}
.csn{font-size:18px;font-weight:800;line-height:1} .csl{font-size:10px;color:#888;font-weight:600;text-transform:uppercase;letter-spacing:.4px;margin-top:2px}

/* VIEW BAR */
.cvcal-vbar{background:#fff;border-bottom:2px solid #e0e0e0;display:flex;padding:0 14px}
.vt{padding:11px 14px;font-size:13px;font-weight:600;color:#888;text-decoration:none;border-bottom:3px solid transparent;margin-bottom:-2px}
.vt-on{color:#e74c3c;border-bottom-color:#e74c3c}

/* CALENDAR */
.cal-nav{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:#fff;border-bottom:1px solid #eee}
.cal-mtitle{font-size:16px;font-weight:700}
.cal-navbtn{font-size:26px;font-weight:300;color:#555;text-decoration:none;padding:0 8px;line-height:1}
.cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:1px;background:#ccc}
.cal-dh{background:#f8f9fa;text-align:center;font-size:10px;font-weight:800;color:#999;text-transform:uppercase;padding:7px 2px}
/* Celle: default = VERDE (libero) */
.cal-c{background:#bbf7d0;min-height:80px;padding:4px 3px 3px;overflow:hidden;position:relative}
.cal-e{background:#f3f4f6;min-height:80px}
.cal-ev{cursor:pointer} .cal-ev:active{filter:brightness(.93)}
.cal-n{font-size:12px;font-weight:700;color:#166534;width:20px;height:20px;display:flex;align-items:center;justify-content:center;margin-bottom:2px}
.cal-td .cal-n{background:#e74c3c;color:#fff;border-radius:50%}
/* Rosso = occupato (CI, permanenza, turnover) */
.cal-ci   {background:#fca5a5} .cal-ci   .cal-n{color:#7f1d1d}
.cal-stay {background:#fca5a5} .cal-stay .cal-n{color:#7f1d1d}
.cal-turn {background:#f87171} .cal-turn .cal-n{color:#7f1d1d}
/* Verde = libero / check-out */
.cal-co   {background:#bbf7d0} .cal-co   .cal-n{color:#166534}

/* Info ospite nella cella */
.cel{display:flex;flex-direction:column;border-radius:4px;overflow:hidden;background:rgba(0,0,0,.09);margin-top:2px;width:100%}
.cel-bar{height:3px;width:100%}
.ob-ab{background:#FF5A5F} .ob-bk{background:#003580} .ob-vb{background:#1B5EAB}
.ob-ex{background:#c8860a} .ob-ta{background:#00A680} .ob-ag{background:#E40312} .ob-di{background:#059669}
.cel-lbl{font-size:8px;font-weight:800;text-transform:uppercase;letter-spacing:.3px;padding:1px 3px 0;line-height:1.4}
.cel-ci .cel-lbl{color:#7f1d1d} .cel-co .cel-lbl{color:#065f46}
.cel-nm{font-size:12px;font-weight:800;color:#111;padding:0 3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;line-height:1.3}
.cel-px{font-size:9px;color:#444;padding:0 3px 2px;line-height:1}
.cel-sty{font-size:9.5px;font-weight:600;color:rgba(100,0,0,.6);font-style:italic;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-top:3px;padding:0 2px}

/* Legend */
.cal-leg{display:flex;flex-wrap:wrap;gap:10px;padding:8px 14px;font-size:11px;color:#555;background:#fff;border-top:1px solid #eee;align-items:center}
.lb{display:inline-block;width:13px;height:13px;border-radius:3px;border:1px solid rgba(0,0,0,.1);vertical-align:middle;margin-right:3px}
.lb-free{background:#bbf7d0} .lb-occ{background:#fca5a5} .lb-co{background:#bbf7d0} .lb-turn{background:#f87171}

/* PANNELLO GIORNO */
#cvcal-panel{background:#f0f2f5;border-top:3px solid #e74c3c}
.panel-hdr{display:flex;align-items:center;justify-content:space-between;padding:12px 14px;background:#fff;border-bottom:1px solid #eee;position:sticky;top:0;z-index:20}
.panel-ttl{font-size:15px;font-weight:700}
.panel-x{background:none;border:none;font-size:22px;color:#999;cursor:pointer;padding:2px 6px;border-radius:6px;line-height:1}
.panel-x:hover{background:#f0f0f0}
.panel-bd{padding:12px}

/* LIST */
.lst-tabs{display:flex;background:#fff;border-bottom:1px solid #ddd}
.lt{flex:1;display:flex;align-items:center;justify-content:center;gap:4px;padding:11px 6px;font-size:12px;font-weight:600;color:#888;text-decoration:none;border-bottom:3px solid transparent;white-space:nowrap}
.lt-on{color:#e74c3c;border-bottom-color:#e74c3c}
.ltc{background:#f0f0f0;border-radius:10px;padding:1px 5px;font-size:10px}
.lt-on .ltc{background:#fde8e8;color:#c0392b}
.lst-body{padding:12px;max-width:640px;margin:0 auto}
.lst-mgrp{margin-bottom:22px}
.lst-mhdr{display:flex;align-items:center;justify-content:space-between;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#aaa;padding:4px 2px 8px}
.lst-mcnt{font-size:11px;background:#e9ecef;color:#777;padding:2px 8px;border-radius:10px;text-transform:none;letter-spacing:0;font-weight:600}

/* CARDS */
.cvcal-card{background:#fff;border-radius:13px;padding:13px;margin-bottom:10px;box-shadow:0 1px 4px rgba(0,0,0,.08);border-left:5px solid #e0e0e0}
.c-ci{border-left-color:#10b981;background:linear-gradient(to right,#f0fdf4,#fff 60%)}
.c-co{border-left-color:#f59e0b;background:linear-gradient(to right,#fffbeb,#fff 60%)}
.c-tom{border-left-color:#3b82f6} .c-fut{border-left-color:#8b5cf6} .c-old{border-left-color:#d1d5db;opacity:.7}
.cc-top{display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:8px}
.cc-guest{font-size:18px;font-weight:700;line-height:1.2}
.cc-badge{flex-shrink:0;font-size:11px;font-weight:700;padding:3px 9px;border-radius:20px;white-space:nowrap}
.b-green{background:#dcfce7;color:#166534} .b-orange{background:#fef3c7;color:#92400e} .b-blue{background:#dbeafe;color:#1e40af}
.cc-nights{display:inline-block;font-size:12px;font-weight:600;color:#777;background:#f3f4f6;padding:2px 9px;border-radius:10px;margin-bottom:8px}
.cc-dates{display:grid;grid-template-columns:1fr 1fr;gap:7px;margin-bottom:8px}
.cc-dbox{background:#f8f9fa;border-radius:9px;padding:8px 10px}
.cc-dlbl{font-size:10px;font-weight:700;color:#999;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px}
.cc-dval{font-size:14px;font-weight:700} .cc-dtime{font-size:12px;color:#888;margin-top:2px}
.cc-meta{display:flex;flex-wrap:wrap;gap:5px;margin-bottom:4px;align-items:center}
.cc-pill{font-size:12px;font-weight:600;color:#555;background:#f0f2f5;padding:3px 9px;border-radius:20px}
.cc-notes{font-size:13px;color:#555;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:7px 9px;line-height:1.45;margin-top:4px}

/* OTA */
.ota{display:inline-block;font-size:11px;font-weight:700;padding:3px 9px;border-radius:5px}
.ota-ab{background:#FF5A5F;color:#fff} .ota-bk{background:#003580;color:#fff} .ota-vb{background:#1B5EAB;color:#fff}
.ota-ex{background:#FFC72C;color:#000} .ota-ta{background:#00A680;color:#fff} .ota-ag{background:#E40312;color:#fff}
.ota-go{background:#4285F4;color:#fff} .ota-di{background:#059669;color:#fff} .ota-xx{background:#6b7280;color:#fff}

/* EMPTY / ERROR */
.cvcal-empty{text-align:center;padding:48px 24px;color:#ccc}
.cvcal-empty span{font-size:48px;display:block;margin-bottom:10px}
.cvcal-err{display:flex;gap:12px;align-items:flex-start;background:#fef2f2;border:1px solid #fca5a5;border-radius:10px;padding:16px;margin:16px;color:#991b1b;font-size:14px;line-height:1.5}
.cvcal-err>span{font-size:22px;flex-shrink:0}
.cvcal-login{padding:32px;text-align:center;font-size:15px;color:#444}

/* RESPONSIVE ≥600px */
@media(min-width:600px){
  .ch-t{font-size:18px}
  .cal-c,.cal-e{min-height:90px}
  .cel-nm{font-size:13px}
  .cc-guest{font-size:19px}
  .lst-body,.panel-bd{padding:16px}
}
</style>
<?php }
