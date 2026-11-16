<?php
/**
 * Statistiche spese pulizie — solo Administrator
 */
defined('ABSPATH') || exit;

/* ── MENU ── */
add_action('admin_menu', 'cvcal_stats_menu', 20);
function cvcal_stats_menu() {
    add_submenu_page('cv-cleaning-calendar', 'Statistiche Pulizie', '📊 Statistiche',
        'manage_options', 'cv-cleaning-stats', 'cvcal_stats_page');
}

/* ── FETCH STORICO (parametri corretti da documentazione Smoobu) ── */
function cvcal_fetch_all() {
    $ck = 'cvcal_all_v5';
    $cached = get_transient($ck);
    if ($cached !== false) return $cached;

    $apid = (int)get_option('cvcal_apartment_id', 0);

    $all = []; $page = 1; $pages = 1;
    // 'from'/'to' = range generale Smoobu che include sia arrivi che partenze
    $from = date('Y-m-d', strtotime('-24 months'));
    $to   = date('Y-m-d', strtotime('+12 months'));

    do {
        // Richiesta firmata HMAC (helper in cv-cleaning-calendar.php)
        $body = cvcal_smoobu_get('/api/reservations', [
            'pageSize' => 100,
            'page'     => $page,
            'from'             => $from,
            'to'               => $to,
            'showCancellation' => 'false',
            'excludeBlocked'   => 'true',
        ], 25);
        if (is_wp_error($body)) return $body;
        if (!isset($body['bookings'])) break;
        $pages = max(1, (int)($body['page_count'] ?? $body['pageCount'] ?? 1));

        foreach ($body['bookings'] as $b) {
            if ($apid > 0 && isset($b['apartment']['id']) && (int)$b['apartment']['id'] !== $apid) continue;
            $type = strtolower($b['type'] ?? 'reservation');
            if ($type === 'block' || $type === 'unavailability' || $type === 'cancellation' || strpos($type,'cancell')!==false) continue;
            $all[] = $b;
        }
        $page++;
    } while ($page <= $pages);

    set_transient($ck, $all, CVCAL_TTL);
    return $all;
}

/* ── AJAX: salva voto pulizia manuale ── */
add_action('wp_ajax_cvcal_save_review', function() {
    check_ajax_referer('cvcal_rev_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_die('Forbidden', 403);
    $bid   = sanitize_text_field($_POST['bid']   ?? '');
    $score = (int)($_POST['score']               ?? 0);
    $note  = sanitize_textarea_field($_POST['note'] ?? '');
    $src   = sanitize_text_field($_POST['src']   ?? '');
    if (!$bid) { wp_send_json_error('ID mancante'); return; }
    $reviews = get_option('cvcal_reviews', []);
    if ($score >= 1 && $score <= 5) {
        $reviews[$bid] = ['score' => $score, 'note' => $note, 'src' => $src, 'saved' => date('Y-m-d')];
    } else {
        unset($reviews[$bid]);
    }
    update_option('cvcal_reviews', $reviews);
    wp_send_json_success();
});

/* ── PAGINA ── */
function cvcal_stats_page() {
    if (!current_user_can('manage_options')) wp_die('Accesso negato.');

    /* Salva costo */
    if (isset($_POST['cvcal_save_cost']) && check_admin_referer('cvcal_cost_nonce')) {
        update_option('cvcal_cleaning_cost', (float)str_replace(',', '.', $_POST['cvcal_cleaning_cost'] ?? '0'));
        echo '<div class="notice notice-success is-dismissible"><p>✅ Costo salvato.</p></div>';
    }
    /* Svuota cache */
    if (isset($_POST['cvcal_flush']) && check_admin_referer('cvcal_cost_nonce')) {
        delete_transient('cvcal_all_v5');
        echo '<div class="notice notice-success is-dismissible"><p>✅ Cache svuotata — ricaricamento in corso.</p></div>';
    }

    $cost_per_co = (float)get_option('cvcal_cleaning_cost', 55);
    $reviews     = get_option('cvcal_reviews', []);
    $nonce       = wp_create_nonce('cvcal_rev_nonce');
    $today       = date('Y-m-d');
    $cur_year    = (int)date('Y');

    $bk = cvcal_fetch_all();
    $api_error = is_wp_error($bk) ? $bk->get_error_message() : null;
    if (is_wp_error($bk)) $bk = [];

    /* ── Aggrega: per ogni checkout (pulizia) → mese e anno di departure ── */
    // Struttura: $data[$year][$month_num] = ['checkouts'=>[], 'cost'=>float, 'scores'=>[]]
    $data = [];
    foreach ($bk as $b) {
        $dep = $b['departure'] ?? '';
        if (!$dep || $dep > date('Y-m-d', strtotime('+1 day'))) {
            // includi solo checkout passati o di oggi
        }
        if (!$dep) continue;
        $year = (int)date('Y', strtotime($dep));
        $mon  = (int)date('n', strtotime($dep)); // 1-12

        if (!isset($data[$year])) $data[$year] = [];
        if (!isset($data[$year][$mon])) {
            $data[$year][$mon] = ['checkouts' => [], 'cost' => 0.0, 'scores' => []];
        }

        $data[$year][$mon]['checkouts'][] = $b;
        $data[$year][$mon]['cost'] += $cost_per_co;

        $bid = (string)($b['id'] ?? '');
        if ($bid && isset($reviews[$bid]['score'])) {
            $data[$year][$mon]['scores'][] = (int)$reviews[$bid]['score'];
        }
    }

    // Ordina anni decrescente, mesi crescente (Gen→Dic) all'interno di ogni anno
    krsort($data);
    foreach ($data as $y => &$months) {
        ksort($months); // 1=Gen, 12=Dic
    }
    unset($months);

    /* ── Totali per anno ── */
    $year_totals = [];
    foreach ($data as $y => $months) {
        $yt = ['n' => 0, 'cost' => 0.0, 'scores' => []];
        foreach ($months as $m => $mv) {
            $yt['n']      += count($mv['checkouts']);
            $yt['cost']   += $mv['cost'];
            $yt['scores']  = array_merge($yt['scores'], $mv['scores']);
        }
        $year_totals[$y] = $yt;
    }

    /* ── Dati grafico: ultimi 12 mesi, da più vecchio a più recente (sinistra→destra) ── */
    $chart_data = [];
    for ($i = 11; $i >= 0; $i--) {
        $ts   = strtotime("-$i months");
        $y    = (int)date('Y', $ts);
        $m    = (int)date('n', $ts);
        $mk   = "$y-" . str_pad($m, 2, '0', STR_PAD_LEFT); // YYYY-MM per etichetta
        $mv   = $data[$y][$m] ?? ['checkouts' => [], 'cost' => 0.0, 'scores' => []];
        $chart_data[$mk] = [
            'cost'     => $mv['cost'],
            'n'        => count($mv['checkouts']),
            'scores'   => $mv['scores'],
            'is_cur'   => ($i === 0),
        ];
    }

    $max_cost = 0.0;
    foreach ($chart_data as $cd) {
        if ($cd['cost'] > $max_cost) $max_cost = $cd['cost'];
    }
    if ($max_cost < 1) $max_cost = 1.0;

    /* ── Nomi mesi italiani ── */
    $mesi = ['','Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno',
             'Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'];
    $mesi_short = ['','Gen','Feb','Mar','Apr','Mag','Giu','Lug','Ago','Set','Ott','Nov','Dic'];

    /* ── Alert voti bassi ── */
    $alerts = [];
    foreach ($data as $y => $months) {
        foreach ($months as $m => $mv) {
            $mk_full = "$y-".str_pad($m,2,'0',STR_PAD_LEFT);
            if ($mk_full < date('Y-m', strtotime('-3 months'))) continue;
            foreach ($mv['checkouts'] as $b) {
                $bid = (string)($b['id'] ?? '');
                if ($bid && isset($reviews[$bid]) && $reviews[$bid]['score'] <= 2) {
                    $alerts[] = ['b' => $b, 'r' => $reviews[$bid]];
                }
            }
        }
    }
    ?>
    <div class="wrap" id="cvs">

    <!-- HEADER -->
    <div class="cvs-hdr">
        <div>
            <h1 style="margin:0 0 3px">📊 Statistiche Spese Pulizie</h1>
            <p style="margin:0;color:#666;font-size:13px">€<?php echo number_format($cost_per_co,2,',','.'); ?> per intervento (checkout)</p>
        </div>
        <a href="<?php echo admin_url('admin.php?page=cv-cleaning-calendar'); ?>" class="button">← Calendario</a>
    </div>

    <?php if ($api_error): ?>
    <div class="notice notice-error"><p>⚠️ Errore API Smoobu: <?php echo esc_html($api_error); ?></p></div>
    <?php endif; ?>

    <!-- CONFIG -->
    <div class="cvs-box">
        <form method="post" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <?php wp_nonce_field('cvcal_cost_nonce'); ?>
            <label style="font-weight:700;font-size:13px">💶 Costo per checkout (€)</label>
            <input type="text" name="cvcal_cleaning_cost"
                value="<?php echo esc_attr(number_format($cost_per_co,2,',','.')); ?>"
                style="width:80px;padding:5px 9px;border:1px solid #ccc;border-radius:6px;font-size:14px;font-weight:700;text-align:right">
            <button name="cvcal_save_cost" value="1" class="button button-primary">Salva costo</button>
            <button name="cvcal_flush" value="1" class="button" style="margin-left:4px">🔄 Ricarica da Smoobu</button>
        </form>
    </div>

    <!-- ALERT -->
    <?php if (!empty($alerts)): ?>
    <div class="cvs-box cvs-alert">
        <strong>🚨 Voti pulizie bassi negli ultimi 3 mesi</strong>
        <?php foreach ($alerts as $al): $name = cvcal_name($al['b']) ?: 'Ospite'; ?>
        <div class="cvs-alert-row">
            <span class="cvs-stars-disp" style="color:#ef4444"><?php
                echo str_repeat('★',$al['r']['score']).str_repeat('☆',5-$al['r']['score']);
            ?></span>
            <strong><?php echo esc_html($name); ?></strong> —
            CO <?php echo esc_html(cvcal_fmt_date($al['b']['departure']??'')); ?>
            <?php if($al['r']['note']??''): ?><em> — "<?php echo esc_html($al['r']['note']); ?>"</em><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ANNO CORRENTE + PRECEDENTE CARDS -->
    <div class="cvs-year-grid">
    <?php foreach (array_slice($year_totals, 0, 3, true) as $yr => $yt):
        $is_cur = ($yr === $cur_year);
        $avg    = count($yt['scores']) ? round(array_sum($yt['scores'])/count($yt['scores']),1) : null;
    ?>
    <div class="cvs-box cvs-ycard <?php echo $is_cur?'cvs-ycard-cur':''; ?>">
        <div class="cvs-y-lbl"><?php echo $yr; ?><?php echo $is_cur?' ▸ in corso':''; ?></div>
        <div class="cvs-y-cost">€<?php echo number_format($yt['cost'],0,',','.'); ?></div>
        <div class="cvs-y-sub">
            <span>🧹 <?php echo $yt['n']; ?> interventi</span>
            <?php if ($avg !== null): ?>
            <span>⭐ Media voti: <strong><?php echo $avg; ?>/5</strong></span>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    </div>

    <!-- GRAFICO 12 MESI -->
    <div class="cvs-box">
        <div class="cvs-box-title">📈 Spese mensili — ultimi 12 mesi</div>
        <?php
        $tot12 = array_sum(array_column($chart_data,'cost'));
        $n12   = array_sum(array_column($chart_data,'n'));
        ?>
        <div style="font-size:12px;color:#888;margin-bottom:14px">
            Totale: <strong>€<?php echo number_format($tot12,0,',','.'); ?></strong>
            &nbsp;·&nbsp; <?php echo $n12; ?> interventi
        </div>
        <div class="cvs-chart">
        <?php foreach ($chart_data as $mk => $cd):
            $parts   = explode('-', $mk);
            $lbl     = $mesi_short[(int)$parts[1]] . "'" . substr($parts[0],2);
            $pct     = round($cd['cost'] / $max_cost * 100);
            $avg_s   = count($cd['scores']) ? round(array_sum($cd['scores'])/count($cd['scores']),1) : null;
        ?>
        <div class="cvs-col">
            <div class="cvs-col-val"><?php echo $cd['cost'] > 0 ? '€'.(int)$cd['cost'] : ''; ?></div>
            <div class="cvs-col-bar-wrap">
                <div class="cvs-col-bar <?php echo $cd['is_cur']?'cur':''; ?>"
                     style="height:<?php echo max(2,$pct); ?>%"
                     title="<?php echo $cd['n']; ?> pulizie — €<?php echo number_format($cd['cost'],0,',','.'); ?>">
                </div>
            </div>
            <div class="cvs-col-lbl <?php echo $cd['is_cur']?'cur':''; ?>"><?php echo $lbl; ?></div>
            <?php if($cd['n'] > 0): ?>
            <div class="cvs-col-n"><?php echo $cd['n']; ?></div>
            <?php else: ?>
            <div class="cvs-col-n"></div>
            <?php endif; ?>
            <?php if($avg_s !== null): ?>
            <div class="cvs-col-star"><?php echo $avg_s; ?>⭐</div>
            <?php else: ?>
            <div class="cvs-col-star"></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>
        <div class="cvs-chart-legend">n = numero interventi &nbsp;·&nbsp; ⭐ = media voti inseriti</div>
    </div>

    <!-- DETTAGLIO PER ANNO → MESI GEN-DIC -->
    <div class="cvs-box">
        <div class="cvs-box-title">🗓️ Dettaglio interventi per anno</div>
        <p style="font-size:12px;color:#888;margin-top:-10px;margin-bottom:16px">
            Clicca su un mese per espandere e vedere le pulizie. Puoi inserire il voto ricevuto dagli ospiti su Airbnb/Booking.
        </p>

        <?php if (empty($data)): ?>
        <p style="color:#aaa;text-align:center;padding:24px">Nessun dato — verifica API Key e clicca "Ricarica da Smoobu".</p>
        <?php endif; ?>

        <?php foreach ($data as $yr => $months):
            $yt  = $year_totals[$yr];
            $avg = count($yt['scores']) ? round(array_sum($yt['scores'])/count($yt['scores']),1) : null;
        ?>
        <!-- ANNO -->
        <div class="cvs-yr-block" id="yr-<?php echo $yr; ?>">
            <div class="cvs-yr-hdr" onclick="cvsToggleYear(<?php echo $yr; ?>)">
                <span class="cvs-yr-arrow" id="arrow-yr-<?php echo $yr; ?>">▶</span>
                <strong><?php echo $yr; ?></strong>
                <span class="cvs-yr-tag">€<?php echo number_format($yt['cost'],0,',','.'); ?></span>
                <span class="cvs-yr-tag cvs-yr-n"><?php echo $yt['n']; ?> interventi</span>
                <?php if($avg!==null): ?>
                <span class="cvs-yr-tag cvs-yr-avg">⭐ <?php echo $avg; ?>/5</span>
                <?php endif; ?>
            </div>

            <div class="cvs-yr-body" id="yrb-<?php echo $yr; ?>" style="display:<?php echo $yr===$cur_year?'block':'none'; ?>">
            <!-- Mesi Gen→Dic (solo quelli con dati) -->
            <?php for ($m = 1; $m <= 12; $m++):
                if (!isset($months[$m])) continue;
                $mv     = $months[$m];
                $n      = count($mv['checkouts']);
                $avg_m  = count($mv['scores']) ? round(array_sum($mv['scores'])/count($mv['scores']),1) : null;
                $mon_id = "m-{$yr}-{$m}";
                $is_fut = ("$yr-".str_pad($m,2,'0',STR_PAD_LEFT)) > date('Y-m');
            ?>
            <div class="cvs-month" id="<?php echo $mon_id; ?>">
                <div class="cvs-month-hdr" onclick="cvsToggleMon('<?php echo $mon_id; ?>')">
                    <span class="cvs-m-arrow" id="arrow-<?php echo $mon_id; ?>">▶</span>
                    <span class="cvs-m-name"><?php echo $mesi[$m]; ?></span>
                    <span class="cvs-m-cost">€<?php echo number_format($mv['cost'],0,',','.'); ?></span>
                    <span class="cvs-m-n"><?php echo $n; ?> intervento<?php echo $n!==1?'i':''; ?></span>
                    <?php if($avg_m!==null):
                        $cls=$avg_m>=4?'hi':($avg_m>=3?'mid':'lo');
                    ?>
                    <span class="cvs-m-avg cvs-avg-<?php echo $cls; ?>">⭐ <?php echo $avg_m; ?>/5</span>
                    <?php endif; ?>
                </div>
                <div class="cvs-month-body" id="body-<?php echo $mon_id; ?>" style="display:none">
                <?php foreach ($mv['checkouts'] as $b):
                    $bid   = (string)($b['id'] ?? '');
                    $dep   = $b['departure'] ?? '';
                    $arr   = $b['arrival']   ?? '';
                    $name  = cvcal_name($b) ?: '(nome non disp.)';
                    $ch    = $b['channel']['name'] ?? '';
                    $nights= (int)($b['nights'] ?? 0);
                    $pax   = cvcal_pax($b);
                    $rev   = ($bid && isset($reviews[$bid])) ? $reviews[$bid] : null;
                    $sc    = $rev ? (int)$rev['score'] : 0;
                    $is_past_dep = ($dep <= $today);
                ?>
                <div class="cvs-row" id="row-<?php echo esc_attr($bid); ?>">
                    <!-- Info prenotazione -->
                    <div class="cvs-row-info">
                        <div class="cvs-row-name"><?php echo esc_html($name); ?></div>
                        <div class="cvs-row-meta">
                            🧹 CO <strong><?php echo esc_html(cvcal_fmt_date($dep)); ?></strong>
                            <?php if($arr && $arr !== $dep): ?>&nbsp;·&nbsp; 📥 CI <?php echo esc_html(cvcal_fmt_date($arr)); ?><?php endif; ?>
                            <?php if($nights>0): ?>&nbsp;·&nbsp; <?php echo $nights; ?> notti<?php endif; ?>
                            <?php if($pax): ?>&nbsp;·&nbsp; <?php echo esc_html($pax); ?><?php endif; ?>
                            <?php if($ch): ?>&nbsp;·&nbsp; <strong><?php echo esc_html($ch); ?></strong><?php endif; ?>
                        </div>
                    </div>
                    <!-- Costo -->
                    <div class="cvs-row-price">€<?php echo number_format($cost_per_co,0,',','.'); ?></div>
                    <!-- Voto pulizie (solo per checkout passati) -->
                    <?php if($is_past_dep): ?>
                    <div class="cvs-rating" data-bid="<?php echo esc_attr($bid); ?>">
                        <div class="cvs-rating-lbl">Voto pulizie:</div>
                        <div class="cvs-stars" id="stars-<?php echo esc_attr($bid); ?>">
                            <?php for($s=1;$s<=5;$s++): ?>
                            <span class="cvs-st <?php echo $s<=$sc?'on':''; ?>"
                                  data-v="<?php echo $s; ?>"
                                  onclick="cvsRate(this,'<?php echo esc_js($bid); ?>')">★</span>
                            <?php endfor; ?>
                        </div>
                        <div class="cvs-rev-form" id="form-<?php echo esc_attr($bid); ?>" style="<?php echo $sc?'display:flex':'display:none'; ?>">
                            <input type="text"
                                   id="src-<?php echo esc_attr($bid); ?>"
                                   value="<?php echo esc_attr($rev['src']??''); ?>"
                                   placeholder="Fonte (es: 4.8/5 Airbnb)"
                                   class="cvs-inp-src">
                            <textarea id="note-<?php echo esc_attr($bid); ?>"
                                      placeholder="Note (cosa è stato segnalato?)"
                                      class="cvs-inp-note"
                                      rows="2"><?php echo esc_textarea($rev['note']??''); ?></textarea>
                            <div style="display:flex;gap:8px;align-items:center">
                                <button class="cvs-save-btn" onclick="cvsSave('<?php echo esc_js($bid); ?>')">Salva</button>
                                <?php if($sc): ?>
                                <button class="cvs-del-btn" onclick="cvsDel('<?php echo esc_js($bid); ?>')">✕ Rimuovi</button>
                                <?php endif; ?>
                                <span class="cvs-ok" id="ok-<?php echo esc_attr($bid); ?>">✅</span>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div style="font-size:11px;color:#ccc;font-style:italic;align-self:center">futuro</div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                </div><!-- .cvs-month-body -->
            </div><!-- .cvs-month -->
            <?php endfor; ?>
            </div><!-- .cvs-yr-body -->
        </div><!-- .cvs-yr-block -->
        <?php endforeach; ?>
    </div>

    </div><!-- #cvs -->

<script>
const CVN='<?php echo esc_js($nonce); ?>';
const AJURL='<?php echo esc_js(admin_url('admin-ajax.php')); ?>';

function cvsToggleYear(yr){
    var b=document.getElementById('yrb-'+yr);
    var a=document.getElementById('arrow-yr-'+yr);
    if(b.style.display==='none'){b.style.display='block';a.textContent='▼';}
    else{b.style.display='none';a.textContent='▶';}
}
function cvsToggleMon(id){
    var b=document.getElementById('body-'+id);
    var a=document.getElementById('arrow-'+id);
    if(b.style.display==='none'){b.style.display='block';a.textContent='▼';}
    else{b.style.display='none';a.textContent='▶';}
}

function cvsRate(star,bid){
    var val=parseInt(star.dataset.v);
    var container=document.getElementById('stars-'+bid);
    container.querySelectorAll('.cvs-st').forEach(function(s){
        s.classList.toggle('on',parseInt(s.dataset.v)<=val);
    });
    var form=document.getElementById('form-'+bid);
    if(form) form.style.display='flex';
    if(!container._score) container._score=0;
    container._score=val;
}
function cvsSave(bid){
    var stars=document.getElementById('stars-'+bid);
    var sc=stars._score||stars.querySelectorAll('.cvs-st.on').length;
    if(!sc){alert('Seleziona prima le stelle.');return;}
    var src=(document.getElementById('src-'+bid)||{}).value||'';
    var note=(document.getElementById('note-'+bid)||{}).value||'';
    var fd=new FormData();
    fd.append('action','cvcal_save_review');fd.append('nonce',CVN);
    fd.append('bid',bid);fd.append('score',sc);fd.append('src',src.trim());fd.append('note',note.trim());
    fetch(AJURL,{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
        if(d.success){
            var ok=document.getElementById('ok-'+bid);
            if(ok){ok.style.display='inline';setTimeout(function(){ok.style.display='none';},2000);}
        }
    });
}
function cvsDel(bid){
    if(!confirm('Rimuovere il voto?')) return;
    var fd=new FormData();
    fd.append('action','cvcal_save_review');fd.append('nonce',CVN);
    fd.append('bid',bid);fd.append('score',0);
    fetch(AJURL,{method:'POST',body:fd}).then(function(){location.reload();});
}

// Apri automaticamente anno corrente
(function(){
    var yr=<?php echo $cur_year; ?>;
    var a=document.getElementById('arrow-yr-'+yr);
    if(a) a.textContent='▼';
})();
</script>

<style>
#cvs{max-width:920px;margin-top:10px}
.cvs-hdr{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:18px;gap:10px;flex-wrap:wrap}
.cvs-box{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px;margin-bottom:16px}
.cvs-box-title{font-size:15px;font-weight:700;color:#111;margin-bottom:12px}
.cvs-alert{background:#fff5f5;border-color:#fca5a5}
.cvs-alert strong{color:#991b1b;display:block;margin-bottom:8px}
.cvs-alert-row{display:flex;align-items:center;gap:8px;padding:5px 0;border-bottom:1px solid #fee2e2;font-size:13px;flex-wrap:wrap}
.cvs-alert-row:last-child{border:none}

/* Anno cards */
.cvs-year-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:16px}
@media(max-width:680px){.cvs-year-grid{grid-template-columns:1fr 1fr}}
@media(max-width:420px){.cvs-year-grid{grid-template-columns:1fr}}
.cvs-ycard{margin-bottom:0;padding:16px}
.cvs-ycard-cur{border-color:#3b82f6;background:linear-gradient(135deg,#eff6ff,#fff)}
.cvs-y-lbl{font-size:11px;font-weight:700;text-transform:uppercase;color:#999;margin-bottom:4px;letter-spacing:.4px}
.cvs-y-cost{font-size:30px;font-weight:800;color:#111;line-height:1;margin-bottom:8px}
.cvs-y-sub{display:flex;flex-direction:column;gap:3px;font-size:12px;color:#666}

/* Grafico */
.cvs-chart{display:flex;align-items:flex-end;gap:4px;height:200px;padding:28px 0 44px;border-bottom:2px solid #f0f0f0;overflow-x:auto}
.cvs-col{display:flex;flex-direction:column;align-items:center;flex:1;min-width:36px;height:100%;position:relative}
.cvs-col-val{font-size:9px;font-weight:700;color:#333;text-align:center;width:100%;position:absolute;top:-18px;white-space:nowrap;background:#fff;padding:1px 2px;border-radius:3px}
.cvs-col-bar-wrap{width:100%;flex:1;display:flex;align-items:flex-end;position:relative}
.cvs-col-bar{width:85%;background:#bfdbfe;border-radius:4px 4px 0 0;min-height:3px;margin:0 auto}
.cvs-col-bar.cur{background:#3b82f6}
.cvs-col-lbl{font-size:10px;color:#999;position:absolute;bottom:20px;white-space:nowrap;font-weight:600}
.cvs-col-lbl.cur{color:#3b82f6;font-weight:800}
.cvs-col-n{font-size:10px;color:#aaa;position:absolute;bottom:8px;font-weight:700}
.cvs-col-star{font-size:9px;color:#f59e0b;position:absolute;bottom:0;white-space:nowrap}
.cvs-chart-legend{font-size:11px;color:#aaa;text-align:center;margin-top:6px}

/* Anno accordion */
.cvs-yr-block{margin-bottom:10px;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden}
.cvs-yr-hdr{background:#f8fafc;padding:12px 16px;display:flex;align-items:center;gap:10px;cursor:pointer;flex-wrap:wrap;user-select:none}
.cvs-yr-hdr:hover{background:#f0f4f8}
.cvs-yr-arrow{font-size:11px;color:#888;width:14px;flex-shrink:0}
.cvs-yr-tag{font-size:12px;padding:2px 8px;background:#e9ecef;border-radius:8px;color:#555}
.cvs-yr-n{background:#f0f4ff;color:#3b82f6}
.cvs-yr-avg{background:#fef9c3;color:#854d0e}
.cvs-yr-body{border-top:1px solid #e5e7eb}

/* Mese accordion */
.cvs-month{border-bottom:1px solid #f3f4f6}
.cvs-month:last-child{border:none}
.cvs-month-hdr{padding:10px 20px;display:flex;align-items:center;gap:10px;cursor:pointer;flex-wrap:wrap;background:#fff;user-select:none}
.cvs-month-hdr:hover{background:#f9fafb}
.cvs-m-arrow{font-size:10px;color:#ccc;width:12px;flex-shrink:0}
.cvs-m-name{font-size:14px;font-weight:700;color:#111;min-width:90px}
.cvs-m-cost{font-size:15px;font-weight:800;color:#059669;margin-left:auto}
.cvs-m-n{font-size:12px;color:#888}
.cvs-m-avg{font-size:12px;font-weight:700;padding:2px 7px;border-radius:8px}
.cvs-avg-hi{background:#dcfce7;color:#166534}
.cvs-avg-mid{background:#fef9c3;color:#854d0e}
.cvs-avg-lo{background:#fee2e2;color:#991b1b}

/* Righe checkout */
.cvs-month-body{padding:6px 16px 10px 32px;background:#fcfcfc;display:flex;flex-direction:column;gap:0}
.cvs-row{display:flex;align-items:flex-start;gap:12px;padding:10px 0;border-bottom:1px dashed #f0f0f0;flex-wrap:wrap}
.cvs-row:last-child{border:none}
.cvs-row-info{flex:1;min-width:160px}
.cvs-row-name{font-size:14px;font-weight:700;color:#111}
.cvs-row-meta{font-size:11px;color:#888;margin-top:2px;line-height:1.5}
.cvs-row-price{font-size:14px;font-weight:700;color:#059669;white-space:nowrap;padding-top:3px;min-width:40px;text-align:right}

/* Rating */
.cvs-rating{display:flex;flex-direction:column;gap:4px;min-width:180px}
.cvs-rating-lbl{font-size:10px;font-weight:700;color:#999;text-transform:uppercase;letter-spacing:.4px}
.cvs-stars{display:flex;gap:1px}
.cvs-st{font-size:26px;color:#e5e7eb;cursor:pointer;line-height:1;transition:color .1s;user-select:none}
.cvs-st.on,.cvs-st:hover{color:#f59e0b}
.cvs-stars:hover .cvs-st{color:#f59e0b}
.cvs-stars:hover .cvs-st:hover~.cvs-st{color:#e5e7eb}
.cvs-rev-form{flex-direction:column;gap:5px;margin-top:4px}
.cvs-inp-src,.cvs-inp-note{padding:5px 8px;border:1px solid #e5e7eb;border-radius:6px;font-size:12px;font-family:inherit;width:100%}
.cvs-save-btn{background:#059669;color:#fff;border:none;padding:5px 14px;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer}
.cvs-save-btn:hover{background:#047857}
.cvs-del-btn{background:none;border:1px solid #fca5a5;color:#ef4444;padding:4px 10px;border-radius:6px;font-size:11px;cursor:pointer}
.cvs-ok{font-size:13px;color:#059669;font-weight:700;display:none}
</style>
    <?php
}
