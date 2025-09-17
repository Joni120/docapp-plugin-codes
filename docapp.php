<?php
/**
 * Plugin Name: Doctor Appointments & Reports (Weekdays + TimeRange) - v1.4
 * Description: Appointment form showing clinic time range and allowed weekdays. Serial numbers per clinic+date, admin search/filter, appointment status toggles, collapsed lists.
 * Version: 1.4
 * Author: Your Name
 */

if (!defined('ABSPATH')) exit;

function da_enqueue_scripts() {
    // Enqueue Flatpickr CSS
    wp_enqueue_style('flatpickr-style', 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css');

    // Enqueue Frontend JS
    wp_enqueue_script('da-script', plugin_dir_url(__FILE__) . 'da-script.js', array('jquery', 'flatpickr'), '1.0', true);
    wp_localize_script('da-script', 'da_ajax', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('da_nonce')
    ));

    // Enqueue Admin JS
    wp_enqueue_script('da-admin', plugin_dir_url(__FILE__) . 'da-admin.js', array('jquery'), '1.0', true);
    wp_localize_script('da-admin', 'da_admin_ajax', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('da_admin_nonce')
    ));
}
add_action('wp_enqueue_scripts', 'da_enqueue_scripts');
add_action('admin_enqueue_scripts', 'da_enqueue_scripts');



class DA_Weekday_Plugin {

    private $tbl_appointments;
    private $tbl_reports;
    private $option_key = 'da_clinics_settings_v3';

    public function __construct(){
        global $wpdb;
        $this->tbl_appointments = $wpdb->prefix . 'da_appointments';
        $this->tbl_reports = $wpdb->prefix . 'da_reports';

        register_activation_hook(__FILE__, array($this,'activate'));

        add_action('init', array($this,'register_assets'));

        // shortcodes
        add_shortcode('doc_appointment', array($this,'shortcode_appointment'));
        add_shortcode('doc_reports', array($this,'shortcode_reports'));

        // ajax actions (front & admin)
        add_action('wp_ajax_nopriv_da_submit_appointment', array($this,'ajax_submit_appointment'));
        add_action('wp_ajax_da_submit_appointment', array($this,'ajax_submit_appointment'));

        add_action('wp_ajax_nopriv_da_submit_report', array($this,'ajax_submit_report'));
        add_action('wp_ajax_da_submit_report', array($this,'ajax_submit_report'));

        add_action('wp_ajax_nopriv_da_search_reports', array($this,'ajax_search_reports'));
        add_action('wp_ajax_da_search_reports', array($this,'ajax_search_reports'));

        add_action('wp_ajax_nopriv_da_get_clinic_info', array($this,'ajax_get_clinic_info'));
        add_action('wp_ajax_da_get_clinic_info', array($this,'ajax_get_clinic_info'));

        add_action('wp_ajax_nopriv_da_get_report_by_id', array($this,'ajax_get_report_by_id'));
        add_action('wp_ajax_da_get_report_by_id', array($this,'ajax_get_report_by_id'));

        // admin endpoints
        add_action('wp_ajax_da_delete_appointment', array($this,'ajax_delete_appointment'));
        add_action('wp_ajax_da_delete_report', array($this,'ajax_delete_report'));
        add_action('wp_ajax_da_delete_clinic', array($this,'ajax_delete_clinic'));

        add_action('wp_ajax_da_search_appointments_admin', array($this,'ajax_search_appointments_admin'));
        add_action('wp_ajax_da_search_reports_admin', array($this,'ajax_search_reports_admin'));
        add_action('wp_ajax_da_toggle_appointment_status', array($this,'ajax_toggle_appointment_status'));

        // admin
        add_action('admin_menu', array($this,'admin_menu'));
        add_action('admin_enqueue_scripts', array($this,'admin_enqueue'));

        // serve letter download
        add_action('init', array($this,'maybe_serve_letter'));
    }

    /**
     * Activation: create tables and attempt small migrations
     */
    public function activate(){
        global $wpdb;
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $charset_collate = $wpdb->get_charset_collate();

        // base tables
        $sql1 = "CREATE TABLE {$this->tbl_appointments} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(200),
            age VARCHAR(10),
            mobile VARCHAR(50),
            clinic VARCHAR(200),
            clinic_time_range VARCHAR(100),
            serial_date DATE,
            serial_no INT DEFAULT 0,
            appointment_status VARCHAR(20) DEFAULT 'pending',
            created_at DATETIME,
            sent_whatsapp TINYINT(1) DEFAULT 0,
            sent_email TINYINT(1) DEFAULT 0,
            extra TEXT,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        $sql2 = "CREATE TABLE {$this->tbl_reports} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(200),
            age VARCHAR(10),
            mobile VARCHAR(50),
            attachments TEXT,
            created_at DATETIME,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        dbDelta($sql1);
        dbDelta($sql2);

        // attempt to add missing columns if older install
        $this->maybe_add_columns();

        if (get_option($this->option_key) === false){
            $default = array(
                'whatsapp_phone' => '',
                'whatsapp_api_endpoint' => '',
                'email_recipient' => get_option('admin_email'),
                'clinics' => array() // each: ['name','time_range','present_time','weekdays'=>[0..6], 'dates'=>[...] optional]
            );
            update_option($this->option_key, $default);
        }
    }

    /**
     * Add columns if missing (migration safe)
     */
    private function maybe_add_columns(){
        global $wpdb;
        $table = $this->tbl_appointments;
        $row = $wpdb->get_row("SHOW COLUMNS FROM `{$table}` LIKE 'serial_no'");
        if (!$row){
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `serial_no` INT DEFAULT 0");
        }
        $row2 = $wpdb->get_row("SHOW COLUMNS FROM `{$table}` LIKE 'appointment_status'");
        if (!$row2){
            $wpdb->query("ALTER TABLE `{$table}` ADD COLUMN `appointment_status` VARCHAR(20) DEFAULT 'pending'");
        }
    }

    public function register_assets(){
        $base = plugin_dir_url(__FILE__);

        // plugin styles/scripts
        wp_register_style('da-style', $base . 'assets/css/da-style.css', array(), '1.4');

        // flatpickr (CDN): lightweight calendar to disable weekdays
        wp_register_style('flatpickr-css','https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css', array(), null);
        wp_register_script('flatpickr-js','https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.js', array(), null, true);

        wp_register_script('da-script', $base . 'assets/js/da-script.js', array('jquery','flatpickr-js'), '1.4', true);
        wp_register_script('da-admin-js', $base . 'assets/js/da-admin.js', array('jquery'), '1.4', true);

        wp_localize_script('da-script','da_ajax', array(
            'ajaxurl'=>admin_url('admin-ajax.php'),
            'nonce'=>wp_create_nonce('da_nonce_v3'),
        ));

        wp_localize_script('da-admin-js','da_admin_ajax', array(
            'ajaxurl'=>admin_url('admin-ajax.php'),
            'nonce'=>wp_create_nonce('da_admin_nonce'),
        ));
    }


//.................search features.............................


//...................search features end...................................



    /* ---------------- Shortcodes ---------------- */

    public function shortcode_appointment($atts){
        wp_enqueue_style('da-style');
        wp_enqueue_style('flatpickr-css');
        wp_enqueue_script('da-script');

        $opts = get_option($this->option_key);
        $clinics = isset($opts['clinics']) ? $opts['clinics'] : array();

        // min date for date input - today's date in WP timezone
        $min_date = date_i18n('Y-m-d', current_time('timestamp'));

        ob_start(); ?>
        <div class="da-appointment-wrap">
            <form id="da-appointment-form" method="post" class="da-form">
                <input type="hidden" name="action" value="da_submit_appointment">
                <?php wp_nonce_field('da_nonce_v3','da_nonce_field'); ?>

                <div class="da-row"><label>Full name</label><input name="name" required></div>

                <div class="da-row small">
                    <div class="col"><label>Age</label><input name="age" required></div>
                    <div class="col"><label>Mobile number</label><input name="mobile" required placeholder="+8801..."></div>
                </div>

                <div class="da-row">
                    <label>Clinic / Office</label>
                    <select name="clinic" id="da_clinic_select" required>
                        <option value="">-- Select clinic --</option>
                        <?php foreach($clinics as $c):
                            $weekdays = isset($c['weekdays']) ? implode(',', $c['weekdays']) : '';
                            $time_range = isset($c['time_range']) ? $c['time_range'] : '';
                            $present_time = isset($c['present_time']) ? $c['present_time'] : '';
                        ?>
                            <option value="<?php echo esc_attr($c['name']); ?>"
                                data-time="<?php echo esc_attr($time_range); ?>"
                                data-present="<?php echo esc_attr($present_time); ?>"
                                data-weekdays="<?php echo esc_attr($weekdays); ?>">
                                <?php echo esc_html($c['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="da-row">
                    <label>Clinic Available</label>
                    <div id="da_clinic_time_display" class="da-time-display">Please select a clinic</div>
                    <input type="hidden" name="clinic_time_range" id="da_clinic_time_input" value="">
                    <div id="da_clinic_present_display" class="da-time-display" style="margin-top:8px;color:#444;font-weight:600"></div>
                    <div id="da_clinic_days_display" class="da-days-display" style="margin-top:8px;color:#444;font-weight:600"></div>
                </div>

                <div class="da-row">
                    <label>Preferred Date</label>
                    <input type="text" name="serial_date" id="da_serial_date" required placeholder="Select a date" autocomplete="off">
                    <div id="da_date_error" style="color:#b00020;display:none;margin-top:6px;"></div>
                </div>

                
                <div class="da-row">
                  <label>Patient Type</label>
                  <label class="switch">
                      <input type="checkbox" name="is_old_patient" value="1">
                      <span class="slider round"></span>
                  </label>
                  <span style="margin-left:8px;font-weight:600;">Old Patient</span>
              </div>


                

                <div class="da-row">
                    <button type="submit" class="da-btn">Submit Appointment</button>
                </div>
            </form>

            <div id="da-appointment-result" class="da-result" style="display:none;"></div>
        </div>

       <script>
      /* pass clinics info to front-end flatpickr initialization */
      var da_clinics_data = <?php echo wp_json_encode($clinics); ?>;
      /* server (WP timezone) min date for flatpickr in YYYY-MM-DD (e.g. 2025-09-16) */
      var da_min_date = "<?php echo esc_js( date_i18n('Y-m-d', current_time('timestamp')) ); ?>";
      </script>


        <?php
        return ob_get_clean();
    }

    public function shortcode_reports($atts){
        wp_enqueue_style('da-style');
        wp_enqueue_script('da-script');

        ob_start(); ?>
        <div class="da-reports-wrap">
            <div class="da-toggle">
                <button class="da-toggle-btn active" data-mode="submit">Submit</button>
                <button class="da-toggle-btn" data-mode="search">Search</button>
            </div>

            <div class="da-mode da-mode-submit">
                <form id="da-report-form" method="post" enctype="multipart/form-data" class="da-form">
                    <input type="hidden" name="action" value="da_submit_report">
                    <?php wp_nonce_field('da_nonce_v3','da_nonce_field2'); ?>

                    <div class="da-row"><label>Full name</label><input name="name" required></div>

                    <div class="da-row small">
                        <div class="col"><label>Age</label><input name="age"></div>
                        <div class="col"><label>Mobile number</label><input name="mobile" required></div>
                    </div>

                    <div class="da-row"><label>Attachments (jpg/png/pdf) - multiple</label><input type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.pdf"></div>

                    <div class="da-row"><button type="submit" class="da-btn">Submit Report</button></div>
                </form>
                <div id="da-report-result" class="da-result" style="display:none;"></div>
            </div>

            <!-- <div class="da-mode da-mode-search" style="display:none;">
                <label>Search by name or mobile</label>
                <input id="da-search-input" placeholder="Type name or mobile">
                <div id="da-search-suggestions" class="da-suggestions"></div>
                <div id="da-search-detail"></div>
            </div> -->

            <div id="da-search-section" style="display:none">
                <input type="text" id="da-search-input" placeholder="Search by name or mobile">
                <div id="da-search-suggestions"></div>
                <div id="da-search-detail"></div>
            </div>

        </div>
        <?php
        return ob_get_clean();
    }

    /* ---------------- AJAX Handlers ---------------- */

    public function ajax_get_clinic_info(){
        $clinic = sanitize_text_field($_GET['clinic'] ?? '');
        $opts = get_option($this->option_key, array());
        $time = '';
        $present = '';
        $weekdays = array();
        if (!empty($clinic) && isset($opts['clinics'])){
            foreach($opts['clinics'] as $c){
                if ($c['name'] === $clinic){
                    $time = $c['time_range'] ?? '';
                    $present = $c['present_time'] ?? '';
                    $weekdays = $c['weekdays'] ?? array();
                    break;
                }
            }
        }
        wp_send_json_success(array('time_range'=>$time,'present_time'=>$present,'weekdays'=>$weekdays));
    }

    /**
     * Submit appointment - now calculates serial_no per clinic+date
     */
public function ajax_submit_appointment() {
    if (!isset($_POST['da_nonce_field']) || !wp_verify_nonce($_POST['da_nonce_field'],'da_nonce_v3')) {
        wp_send_json_error(['message' => 'Invalid request']);
    }

    global $wpdb;
    $name   = sanitize_text_field($_POST['name'] ?? '');
    $age    = sanitize_text_field($_POST['age'] ?? '');
    $mobile = sanitize_text_field($_POST['mobile'] ?? '');
    $clinic = sanitize_text_field($_POST['clinic'] ?? '');
    $serial_date = sanitize_text_field($_POST['serial_date'] ?? '');
    $time_range = sanitize_text_field($_POST['clinic_time_range'] ?? '');
    $is_old_patient = !empty($_POST['is_old_patient']) ? 1 : 0;

    if (!$name || !$mobile || !$clinic || !$serial_date) {
        wp_send_json_error(['message' => 'Required fields missing.']);
    }

    // ------------------ Check for duplicate ------------------
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$this->tbl_appointments}
         WHERE mobile = %s AND clinic = %s AND serial_date = %s
         LIMIT 1",
        $mobile, $clinic, $serial_date
    ));

    if ($existing) {
        // already has appointment → return details
        wp_send_json_error([
            'message' => 'You already have an appointment for this date.',
            'appointment' => [
                'id'     => $existing->id,
                'name'   => $existing->name,
                'mobile' => $existing->mobile,
                'clinic' => $existing->clinic,
                'date'   => $existing->serial_date,
                'serial' => $existing->serial_no,
                'time'   => $existing->clinic_time_range,
            ]
        ]);
    }

    // ------------------ Generate serial no ------------------
    $count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$this->tbl_appointments}
         WHERE clinic = %s AND serial_date = %s",
        $clinic, $serial_date
    ));
    $serial_no = intval($count) + 1;

    // ------------------ Insert Appointment ------------------
    $wpdb->insert($this->tbl_appointments, [
        'name'              => $name,
        'age'               => $age,
        'mobile'            => $mobile,
        'clinic'            => $clinic,
        'clinic_time_range' => $time_range,
        'serial_date'       => $serial_date,
        'serial_no'         => $serial_no,
        $is_old_patient = isset($_POST['is_old_patient']) ? 1 : 0,
        'is_old_patient'    => $is_old_patient,
        'created_at'        => current_time('mysql')
    ]);

    $appointment_id = $wpdb->insert_id;

    // ------------------ Send Email ------------------
    $opts = get_option($this->option_key, []);
    $sent_email = false;
    $sent_whatsapp = false;

    $admin_email = $opts['email_recipient'] ?? get_option('admin_email');
    if ($admin_email) {
        $subject = "New Appointment Submitted: #{$serial_no}";
        $body = "<p>A new appointment has been submitted:</p>
        <ul>
            <li><strong>Name:</strong> {$name}</li>
            <li><strong>Age:</strong> {$age}</li>
            <li><strong>Mobile:</strong> {$mobile}</li>
            <li><strong>Clinic:</strong> {$clinic}</li>
            <li><strong>Date:</strong> {$serial_date}</li>
            <li><strong>Serial:</strong> {$serial_no}</li>
            <li><strong>Time:</strong> {$time_range}</li>
            <li><strong>Patient Type:</strong> " . ($is_old_patient ? 'Old' : 'New') . "</li>
        </ul>";

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: Clinic System <no-reply@yourdomain.com>'
        ];

        $sent_email = wp_mail($admin_email, $subject, $body, $headers);
    }

    // ------------------ Send WhatsApp (if configured) ------------------
    if (!empty($opts['whatsapp_api_endpoint']) && !empty($opts['whatsapp_phone'])) {
        $payload = [
            'phone'   => $opts['whatsapp_phone'],
            'message' => "New appointment\nName: $name\nMobile: $mobile\nClinic: $clinic\nDate: $serial_date\nSerial: $serial_no\nTime: $time_range\nPatient: " . ($is_old_patient ? 'Old' : 'New')
        ];
        $args = [
            'body'    => json_encode($payload),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 20
        ];
        if (!empty($opts['whatsapp_api_token'])) {
            $args['headers']['Authorization'] = 'Bearer ' . $opts['whatsapp_api_token'];
        }
        $resp = wp_remote_post($opts['whatsapp_api_endpoint'], $args);
        $sent_whatsapp = !is_wp_error($resp) && wp_remote_retrieve_response_code($resp) == 200;
    }

    // ------------------ Success Response ------------------
    wp_send_json_success([
        'appointment_id'    => $appointment_id,
        'serial_no'         => $serial_no,
        'serial_date'       => $serial_date,
        'clinic_time_range' => $time_range,
        'sent_email'        => $sent_email,
        'sent_whatsapp'     => $sent_whatsapp
    ]);
}






    public function ajax_submit_report(){
        if (!isset($_POST['da_nonce_field2']) || !wp_verify_nonce($_POST['da_nonce_field2'],'da_nonce_v3')) {
            wp_send_json_error('Invalid nonce');
        }

        global $wpdb;
        $name = sanitize_text_field($_POST['name'] ?? '');
        $age = sanitize_text_field($_POST['age'] ?? '');
        $mobile = sanitize_text_field($_POST['mobile'] ?? '');

        $uploaded_ids = array();

        if (!function_exists('media_handle_upload')) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/media.php');
        }

        if (!empty($_FILES['attachments'])) {
            $files = $_FILES['attachments'];
            for ($i=0; $i < count($files['name']); $i++){
                if ($files['name'][$i] == '') continue;
                $file = array(
                    'name'     => $files['name'][$i],
                    'type'     => $files['type'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error'    => $files['error'][$i],
                    'size'     => $files['size'][$i]
                );
                $_FILES_single = array('attachments' => $file);
                $_FILES_save = $_FILES;
                $_FILES = $_FILES_single;
                $attach_id = media_handle_upload('attachments', 0);
                $_FILES = $_FILES_save;
                if (is_wp_error($attach_id)) {
                    // skip
                } else {
                    $uploaded_ids[] = $attach_id;
                }
            }
        }

        // create timezone-aware timestamp using WP timezone
$now = date_i18n('Y-m-d H:i:s', current_time('timestamp'));

        $inserted = $wpdb->insert($this->tbl_reports, array(
            'name'=>$name,
            'age'=>$age,
            'mobile'=>$mobile,
            'attachments'=> maybe_serialize($uploaded_ids),
            'created_at'=>$now
        ), array('%s','%s','%s','%s','%s'));

        if ($inserted) wp_send_json_success(array('message'=>'Report saved successfully.'));
        wp_send_json_error('Could not save.');
    }

    public function ajax_search_reports(){
        $q = sanitize_text_field($_REQUEST['q'] ?? '');
        global $wpdb;
        if (empty($q)) wp_send_json_success(array());
        $sql = $wpdb->prepare("SELECT id,name,mobile,age FROM {$this->tbl_reports} WHERE name LIKE %s OR mobile LIKE %s ORDER BY id DESC LIMIT 10", "%{$q}%","%{$q}%");
        $rows = $wpdb->get_results($sql);
        $out = array();
        foreach($rows as $r){
            $out[] = array('id'=>$r->id,'name'=>$r->name,'mobile'=>$r->mobile,'age'=>$r->age);
        }
        wp_send_json_success($out);
    }

    public function ajax_get_report_by_id(){
        $id = intval($_GET['id'] ?? 0);
        if (!$id) wp_send_json_error('missing id');
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tbl_reports} WHERE id=%d", $id));
        if (!$row) wp_send_json_error('not found');
        $atts = maybe_unserialize($row->attachments);
        $out_atts = array();
        if (is_array($atts)){
            foreach($atts as $aid){
                $url = wp_get_attachment_url($aid);
                if ($url) $out_atts[] = array('id'=>$aid,'url'=>$url);
            }
        }
        wp_send_json_success(array(
            'id'=>$row->id,'name'=>$row->name,'mobile'=>$row->mobile,'age'=>$row->age,'created_at'=>$row->created_at,
            'attachments'=>$out_atts
        ));
    }

    /* ---------------- Admin delete handlers ---------------- */

    public function ajax_delete_appointment(){
        if (!current_user_can('manage_options')) wp_send_json_error('permission');
        if (!check_ajax_referer('da_admin_nonce','nonce',false)) wp_send_json_error('nonce');
        $id = intval($_POST['id'] ?? 0);
        if (!$id) wp_send_json_error('missing id');
        global $wpdb;
        $deleted = $wpdb->delete($this->tbl_appointments, array('id'=>$id), array('%d'));
        if ($deleted !== false) wp_send_json_success(array('deleted'=>true));
        wp_send_json_error('Could not delete');
    }

    public function ajax_delete_report(){
        if (!current_user_can('manage_options')) wp_send_json_error('permission');
        if (!check_ajax_referer('da_admin_nonce','nonce',false)) wp_send_json_error('nonce');
        $id = intval($_POST['id'] ?? 0);
        if (!$id) wp_send_json_error('missing id');
        global $wpdb;
        $deleted = $wpdb->delete($this->tbl_reports, array('id'=>$id), array('%d'));
        if ($deleted !== false) wp_send_json_success(array('deleted'=>true));
        wp_send_json_error('Could not delete');
    }

    public function ajax_delete_clinic(){
        if (!current_user_can('manage_options')) wp_send_json_error('permission');
        if (!check_ajax_referer('da_admin_nonce','nonce',false)) wp_send_json_error('nonce');
        $name = sanitize_text_field($_POST['name'] ?? '');
        if (empty($name)) wp_send_json_error('missing name');
        $opts = get_option($this->option_key, array());
        $clinics = $opts['clinics'] ?? array();
        $new = array();
        $found = false;
        foreach($clinics as $c){
            if (isset($c['name']) && $c['name'] === $name){
                $found = true;
                continue; // skip (delete)
            }
            $new[] = $c;
        }
        if (!$found) wp_send_json_error('not found');
        $opts['clinics'] = $new;
        update_option($this->option_key, $opts);
        wp_send_json_success(array('deleted'=>true));
    }

    /* ---------------- Admin search endpoints ---------------- */

    public function ajax_search_appointments_admin(){
        if (!current_user_can('manage_options')) wp_send_json_error('permission');
        if (!check_ajax_referer('da_admin_nonce','nonce',false)) wp_send_json_error('nonce');

        global $wpdb;
        $q = sanitize_text_field($_POST['q'] ?? '');
        $clinic = sanitize_text_field($_POST['clinic'] ?? '');
        $date = sanitize_text_field($_POST['date'] ?? '');

        // build where clauses
        $where = "1=1";
        $params = array();
        if (!empty($q)){
            $where .= " AND (name LIKE %s OR mobile LIKE %s)";
            $params[] = "%{$q}%"; $params[] = "%{$q}%";
        }
        if (!empty($clinic)){
            $where .= " AND clinic = %s";
            $params[] = $clinic;
        }
        if (!empty($date)){
            $where .= " AND serial_date = %s";
            $params[] = $date;
        }

        $sql = "SELECT * FROM {$this->tbl_appointments} WHERE {$where} ORDER BY serial_date DESC, serial_no ASC LIMIT 500";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params));
        wp_send_json_success($rows);
    }

    public function ajax_search_reports_admin(){
        if (!current_user_can('manage_options')) wp_send_json_error('permission');
        if (!check_ajax_referer('da_admin_nonce','nonce',false)) wp_send_json_error('nonce');

        global $wpdb;
        $q = sanitize_text_field($_POST['q'] ?? '');
        $date_from = sanitize_text_field($_POST['date_from'] ?? '');
        $where = "1=1";
        $params = array();
        if (!empty($q)){
            $where .= " AND (name LIKE %s OR mobile LIKE %s)";
            $params[] = "%{$q}%"; $params[] = "%{$q}%";
        }
        if (!empty($date_from)){
            $where .= " AND DATE(created_at) = %s";
            $params[] = $date_from;
        }

        $sql = "SELECT * FROM {$this->tbl_reports} WHERE {$where} ORDER BY created_at DESC LIMIT 500";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params));
        wp_send_json_success($rows);
    }

    /**
     * Toggle appointment status (appointed/absent/pending)
     */
    public function ajax_toggle_appointment_status(){
        if (!current_user_can('manage_options')) wp_send_json_error('permission');
        if (!check_ajax_referer('da_admin_nonce','nonce',false)) wp_send_json_error('nonce');

        $id = intval($_POST['id'] ?? 0);
        $status = sanitize_text_field($_POST['status'] ?? '');
        if (!$id || !in_array($status, array('appointed','absent','pending'))) wp_send_json_error('invalid');

        global $wpdb;
        $updated = $wpdb->update($this->tbl_appointments, array('appointment_status'=>$status), array('id'=>$id), array('%s'), array('%d'));
        if ($updated !== false) wp_send_json_success(array('status'=>$status));
        wp_send_json_error('Could not update');
    }

    /* ---------------- Serve appointment letter ---------------- */

    public function maybe_serve_letter(){
        if (isset($_GET['da_download_letter'])){
            $id = intval($_GET['da_download_letter']);
            if ($id <= 0) return;
            global $wpdb;
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tbl_appointments} WHERE id=%d", $id));
            if (!$row) { status_header(404); echo 'Not found.'; exit; }

            $sitename = get_bloginfo('name');
            $html = "<!doctype html><html><head><meta charset='utf-8'><title>Appointment Letter - #{$row->id}</title>
            <style>body{font-family:Arial,Helvetica,sans-serif;max-width:800px;margin:40px auto;padding:24px;border:1px solid #e6e6e6} h1{margin-bottom:0} .meta{margin-top:8px;color:#555} .box{margin-top:18px;padding:12px;border:1px dashed #ddd} .btn{display:inline-block;margin-top:14px;padding:8px 12px;background:#0073aa;color:#fff;text-decoration:none;border-radius:4px}</style>
            </head><body>";
            $html .= "<h1>{$sitename} - Appointment Letter</h1>";
            $html .= "<p class='meta'>Appointment ID: <strong>#{$row->id}</strong> &nbsp; | &nbsp; Date: <strong>".esc_html($row->serial_date)."</strong> &nbsp; | &nbsp; Serial: <strong>".esc_html($row->serial_no)."</strong></p>";
            $html .= "<div class='box'>";
            $html .= "<p><strong>Patient Name:</strong> ".esc_html($row->name)."</p>";
            $html .= "<p><strong>Age:</strong> ".esc_html($row->age)."</p>";
            $html .= "<p><strong>Mobile:</strong> ".esc_html($row->mobile)."</p>";
            $html .= "<p><strong>Clinic / Office:</strong> ".esc_html($row->clinic)."</p>";
            $html .= "<p><strong>Available Time:</strong> ".esc_html($row->clinic_time_range)."</p>";
            $html .= "</div>";
            $html .= "<p>Please arrive 10 minutes before your appointment. Bring previous medical reports if any.</p>";
            $html .= "<p style='margin-top:30px'>Authority signature: ______________________</p>";
            $html .= "</body></html>";

            $filename = "appointment-{$row->id}.html";
            header('Content-Type: text/html; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo $html;
            exit;
        }
    }

    /* ---------------- Admin Pages & Enqueue ---------------- */

    public function admin_enqueue($hook){
        // only enqueue for plugin admin pages
        $allowed = array('toplevel_page_da_main','doctor-appointments_page_da_settings','doctor-appointments_page_da_appointments','doctor-appointments_page_da_reports');
        if (!in_array($hook, $allowed)) return;
        wp_enqueue_script('da-admin-js');
        wp_enqueue_style('da-style');
    }

    public function admin_menu(){
        add_menu_page('Doctor Appointments', 'Doctor Appointments', 'manage_options', 'da_main', array($this,'admin_main'), 'dashicons-calendar', 58);
        add_submenu_page('da_main','Settings','Settings','manage_options','da_settings', array($this,'admin_settings'));
        add_submenu_page('da_main','Appointments','Appointments','manage_options','da_appointments', array($this,'admin_appointments'));
        add_submenu_page('da_main','Reports','Reports','manage_options','da_reports', array($this,'admin_reports'));
    }

    public function admin_main(){
        echo '<div class="wrap"><h1>Doctor Appointments</h1><p>Shortcodes: <code>[doc_appointment]</code> and <code>[doc_reports]</code></p></div>';
    }

    /**
     * Settings page: add "present_time" field to each clinic
     */
    public function admin_settings(){
        if (!current_user_can('manage_options')) return;
        $opts = get_option($this->option_key, array());
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('da_settings_save_v3','da_settings_nonce')){
            $opts['whatsapp_phone'] = sanitize_text_field($_POST['whatsapp_phone'] ?? '');
            $opts['whatsapp_api_endpoint'] = sanitize_text_field($_POST['whatsapp_api_endpoint'] ?? '');
            $opts['email_recipient'] = sanitize_email($_POST['email_recipient'] ?? '');
            $clinics_in = $_POST['clinics'] ?? array();
            $clinics = array();
            if (is_array($clinics_in)) {
                foreach($clinics_in as $idx => $c){
                    if (!is_array($c)) continue;
                    $name = sanitize_text_field($c['name'] ?? '');
                    $time_range = sanitize_text_field($c['time_range'] ?? '');
                    $present_time = sanitize_text_field($c['present_time'] ?? '');
                    $weekdays_raw = isset($c['weekdays']) ? $c['weekdays'] : array();
                    $weekdays = array();
                    if (is_array($weekdays_raw)){
                        foreach($weekdays_raw as $wd){
                            $wd_int = intval($wd);
                            if ($wd_int >=0 && $wd_int <=6) $weekdays[] = $wd_int;
                        }
                    }
                    $dates = array();
                    if (!empty($c['dates_raw'])){
                        $parts = explode(';',$c['dates_raw']);
                        foreach($parts as $p){
                            $p = trim($p);
                            if ($p) $dates[] = $p;
                        }
                    }
                    if ($name) $clinics[] = array('name'=>$name,'time_range'=>$time_range,'present_time'=>$present_time,'weekdays'=>$weekdays,'dates'=>$dates);
                }
            }
            $opts['clinics'] = $clinics;
            update_option($this->option_key, $opts);
            echo '<div class="updated"><p>Settings saved.</p></div>';
            // reload list
            $opts = get_option($this->option_key, array());
        }

        $opts = get_option($this->option_key, array());
        $clinics = $opts['clinics'] ?? array();
        ?>
        <div class="wrap">
            <h1>DA Settings</h1>
            <form method="post" id="da-clinics-form">
                <?php wp_nonce_field('da_settings_save_v3','da_settings_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th>WhatsApp Phone (digits)</th>
                        <td><input name="whatsapp_phone" value="<?php echo esc_attr($opts['whatsapp_phone'] ?? ''); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th>WhatsApp API Endpoint (optional)</th>
                        <td><input name="whatsapp_api_endpoint" value="<?php echo esc_attr($opts['whatsapp_api_endpoint'] ?? ''); ?>" class="regular-text">
                        <p class="description">Optional webhook to send appointment to a WhatsApp provider.</p></td>
                    </tr>
                    <tr>
                        <th>Email recipient</th>
                        <td><input name="email_recipient" value="<?php echo esc_attr($opts['email_recipient'] ?? get_option('admin_email')); ?>" class="regular-text"></td>
                    </tr>
                </table>

                <h2>Clinics & Time Ranges</h2>
                <p>For each clinic set name, visible time range (e.g. <code>2 pm - 7 pm</code>), উপস্থিতির সময় (present time), and select weekdays when clinic is available.</p>

                <?php
                $weekday_labels = array(0=>'Sun',1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat');

                // render existing clinics with explicit numeric indexes
                foreach($clinics as $idx => $c):
                    $name = esc_attr($c['name']);
                    $time_range = esc_attr($c['time_range']);
                    $present_time = esc_attr($c['present_time'] ?? '');
                    $weekdays_selected = isset($c['weekdays']) && is_array($c['weekdays']) ? $c['weekdays'] : array();
                    $dates_raw = !empty($c['dates']) ? implode(';',$c['dates']) : '';
                ?>
                <div class="da-clinic-block" data-clinic-name="<?php echo esc_attr($c['name']); ?>" style="margin-bottom:18px;padding:12px;border:1px solid #eee;position:relative;">
                    <label style="font-weight:700">Clinic #<?php echo intval($idx+1); ?></label>
                    <p>
                        <label>Clinic name</label>
                        <input name="clinics[<?php echo $idx;?>][name]" value="<?php echo $name; ?>" style="width:100%;padding:6px;margin-bottom:6px;">
                    </p>
                    <p>
                        <label>Time range (visible on form)</label>
                        <input name="clinics[<?php echo $idx;?>][time_range]" value="<?php echo $time_range; ?>" style="width:100%;padding:6px;margin-bottom:6px;" placeholder="e.g. 2 pm - 7 pm">
                    </p>
                    <p>
                        <label>উপস্থিতির সময় (Present time)</label>
                        <input name="clinics[<?php echo $idx;?>][present_time]" value="<?php echo $present_time; ?>" style="width:100%;padding:6px;margin-bottom:6px;" placeholder="e.g. 02:00 PM">
                    </p>
                    <p>
                        <label>Available weekdays</label>
                        <?php foreach($weekday_labels as $k=>$lbl):
                            $checked = in_array($k, $weekdays_selected) ? 'checked' : '';
                        ?>
                            <label style="display:inline-block;margin-right:8px;">
                                <input type="checkbox" name="clinics[<?php echo $idx;?>][weekdays][]" value="<?php echo $k; ?>" <?php echo $checked; ?>> <?php echo $lbl; ?>
                            </label>
                        <?php endforeach; ?>
                    </p>
                    <p>
                        <label>Specific Dates (optional) - format: YYYY-MM-DD;YYYY-MM-DD</label>
                        <textarea name="clinics[<?php echo $idx;?>][dates_raw]" rows="2" style="width:100%"><?php echo esc_textarea($dates_raw); ?></textarea>
                    </p>

                    <div style="position:absolute;top:12px;right:12px">
                        <button type="button" class="button button-link-delete da-delete-clinic-js" data-clinic-name="<?php echo esc_attr($c['name']); ?>">Delete</button>
                        &nbsp;
                        <button type="button" class="button button-secondary da-remove-block">Remove (un-saved)</button>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php
                // Provide 2 blank clinic blocks with proper continuing indexes for adding new clinics
                $start_idx = count($clinics);
                for($i=0;$i<2;$i++):
                    $idx = $start_idx + $i;
                ?>
                <div class="da-clinic-block" style="margin-bottom:18px;padding:12px;border:1px solid #eee;position:relative;">
                    <label style="font-weight:700">Add new clinic</label>
                    <p>
                        <label>Clinic name</label>
                        <input name="clinics[<?php echo $idx;?>][name]" value="" style="width:100%;padding:6px;margin-bottom:6px;">
                    </p>
                    <p>
                        <label>Time range (visible on form)</label>
                        <input name="clinics[<?php echo $idx;?>][time_range]" value="" style="width:100%;padding:6px;margin-bottom:6px;" placeholder="e.g. 2 pm - 7 pm">
                    </p>
                    <p>
                        <label>উপস্থিতির সময় (Present time)</label>
                        <input name="clinics[<?php echo $idx;?>][present_time]" value="" style="width:100%;padding:6px;margin-bottom:6px;" placeholder="e.g. 02:00 PM">
                    </p>
                    <p>
                        <label>Available weekdays</label>
                        <?php foreach($weekday_labels as $k=>$lbl): ?>
                            <label style="display:inline-block;margin-right:8px;">
                                <input type="checkbox" name="clinics[<?php echo $idx;?>][weekdays][]" value="<?php echo $k; ?>"> <?php echo $lbl; ?>
                            </label>
                        <?php endforeach; ?>
                    </p>
                    <p>
                        <label>Specific Dates (optional) - format: YYYY-MM-DD;YYYY-MM-DD</label>
                        <textarea name="clinics[<?php echo $idx;?>][dates_raw]" rows="2" style="width:100%"></textarea>
                    </p>
                    <div style="position:absolute;top:12px;right:12px">
                        <button type="button" class="button button-secondary da-remove-block">Remove</button>
                    </div>
                </div>
                <?php endfor; ?>

                <p><button class="button button-primary" type="submit">Save Settings</button></p>
            </form>
        </div>
        <?php
    }

    /**
     * Admin appointments listing: grouped and collapsible by date, clinic filter, serial shown, status toggles
     */
    public function admin_appointments(){
        if (!current_user_can('manage_options')) return;
        $opts = get_option($this->option_key, array());
        $clinics = $opts['clinics'] ?? array();
        // build clinic menu
        echo '<div class="wrap"><h1>Appointments</h1>';
        echo '<div style="margin-bottom:12px;"><label>Filter by clinic: </label>';
        echo '<button class="button da-filter-clinic" data-clinic="">All</button> ';
        foreach($clinics as $c){
            echo '<button class="button da-filter-clinic" data-clinic="'.esc_attr($c['name']).'">'.esc_html($c['name']).'</button> ';
        }
        echo '</div>';

        echo '<div style="margin-bottom:12px;"><label>Search (name / mobile): </label> <input id="da-admin-search-q"> <button id="da-admin-search-btn" class="button">Search</button></div>';

        echo '<div id="da-appointments-wrapper">Loading...</div>';
        echo '</div>';

        // inline JS: fetch initial grouped view via AJAX (no params)
        ?>
        <script>
        jQuery(function($){
            function renderAppointments(clinic,q,date){
                $('#da-appointments-wrapper').html('Loading...');
                $.post(da_admin_ajax.ajaxurl, { action:'da_search_appointments_admin', clinic:clinic || '', q:q || '', date: date || '', nonce: da_admin_ajax.nonce }, function(resp){
                    if (resp && resp.success){
                        var rows = resp.data || [];
                        // group by date
                        var groups = {};
                        rows.forEach(function(r){
                            var d = r.serial_date || 'Unknown';
                            if (!groups[d]) groups[d] = [];
                            groups[d].push(r);
                        });
                        var html = '';
                        var dates = Object.keys(groups).sort().reverse();
                        if (!dates.length) html = '<p>No appointments found.</p>';
                        dates.forEach(function(d){
                            html += '<div class="da-date-group"><h3 class="da-date-toggle" data-date="'+d+'">'+d+' ('+groups[d].length+' appointment'+(groups[d].length>1?'s':'')+')</h3>';
                            html += '<div class="da-date-list" style="display:none;padding:8px;border-left:2px solid #eee;margin-bottom:12px;">';
                            html += '<table class="widefat"><thead><tr><th>Serial</th><th>Name</th><th>Type</th><th>Mobile</th><th>Clinic</th><th>Time</th><th>Created</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
                            groups[d].sort(function(a,b){ return parseInt(a.serial_no) - parseInt(b.serial_no); });
                            groups[d].forEach(function(r){
                                var status = r.appointment_status || 'pending';
                                var statusClass = '';
                                if (status === 'appointed') statusClass = 'da-status-appointed';
                                if (status === 'absent') statusClass = 'da-status-absent';
                                html += '<tr data-id="'+r.id+'" class="'+statusClass+'">';
                                html += '<td>'+ (r.serial_no||'') +'</td>';
                                html += '<td>'+ (r.name||'') +'</td>';
                                html += '<td>'+ (r.is_old_patient==1 ? 'Old' : 'New') +'</td>';
                                html += '<td>'+ (r.mobile||'') +'</td>';
                                html += '<td>'+ (r.clinic||'') +'</td>';
                                html += '<td>'+ (r.clinic_time_range||'') +'</td>';
                                html += '<td>'+ (r.created_at||'') +'</td>';
                                html += '<td class="da-status-cell">'+ (status) +'</td>';
                                html += '<td>';
                                html += '<button class="button da-mark-appointed" data-id="'+r.id+'">Appointed</button> ';
                                html += '<button class="button da-mark-absent" data-id="'+r.id+'">Absent</button> ';
                                html += '<button class="button da-delete-appointment-js" data-id="'+r.id+'">Delete</button>';
                                html += '</td></tr>';
                            });
                            html += '</tbody></table>';
                            html += '</div></div>';
                        });
                        $('#da-appointments-wrapper').html(html);
                    } else {
                        $('#da-appointments-wrapper').html('<p>Error loading appointments.</p>');
                    }
                },'json');
            }

            // initial render
            renderAppointments('', '');

            // clinic filter
            $(document).on('click', '.da-filter-clinic', function(){
                var clinic = $(this).data('clinic');
                $('#da-admin-search-q').val('');
                renderAppointments(clinic,'');
            });

            // search button
            $('#da-admin-search-btn').on('click', function(){
                var q = $('#da-admin-search-q').val();
                renderAppointments('', q);
            });

            // collapse toggle
            $(document).on('click', '.da-date-toggle', function(){
                $(this).next('.da-date-list').slideToggle(180);
            });

            // status buttons
            $(document).on('click', '.da-mark-appointed', function(){
                var id = $(this).data('id'); if (!id) return;
                if (!confirm('Mark appointment #' + id + ' as APPOINTED?')) return;
                var $row = $('tr[data-id="'+id+'"]');
                $.post(da_admin_ajax.ajaxurl, { action:'da_toggle_appointment_status', id:id, status:'appointed', nonce: da_admin_ajax.nonce }, function(resp){
                    if (resp && resp.success){
                        $row.removeClass('da-status-absent').addClass('da-status-appointed');
                        $row.find('.da-status-cell').text('appointed');
                    } else {
                        alert('Could not update status.');
                    }
                },'json');
            });

            $(document).on('click', '.da-mark-absent', function(){
                var id = $(this).data('id'); if (!id) return;
                if (!confirm('Mark appointment #' + id + ' as ABSENT?')) return;
                var $row = $('tr[data-id="'+id+'"]');
                $.post(da_admin_ajax.ajaxurl, { action:'da_toggle_appointment_status', id:id, status:'absent', nonce: da_admin_ajax.nonce }, function(resp){
                    if (resp && resp.success){
                        $row.removeClass('da-status-appointed').addClass('da-status-absent');
                        $row.find('.da-status-cell').text('absent');
                    } else {
                        alert('Could not update status.');
                    }
                },'json');
            });

            // delete appointment (delegated)
            $(document).on('click', '.da-delete-appointment-js', function(e){
                e.preventDefault();
                var id = $(this).data('id'); if (!id) return;
                if (!confirm('Delete appointment #' + id + '?')) return;
                var $btn = $(this);
                $.post(da_admin_ajax.ajaxurl, { action:'da_delete_appointment', id:id, nonce: da_admin_ajax.nonce }, function(resp){
                    if (resp && resp.success){
                        // remove row visually
                        $('tr[data-id="'+id+'"]').fadeOut(200, function(){ $(this).remove(); });
                    } else {
                        alert('Could not delete appointment.');
                    }
                },'json');
            });

        });
        </script>
        <?php
    }

    /**
     * Admin reports listing: collapsible by date + search
     */
    public function admin_reports(){
        if (!current_user_can('manage_options')) return;
        echo '<div class="wrap"><h1>Reports</h1>';
        echo '<div style="margin-bottom:12px;"><label>Search (name / mobile): </label> <input id="da-report-search-q"> <button id="da-report-search-btn" class="button">Search</button></div>';
        echo '<div id="da-reports-wrapper">Loading...</div>';
        echo '</div>';
        ?>
        <script>
        jQuery(function($){
            function renderReports(q,date){
                $('#da-reports-wrapper').html('Loading...');
                $.post(da_admin_ajax.ajaxurl, { action:'da_search_reports_admin', q:q||'', date_from: date||'', nonce: da_admin_ajax.nonce }, function(resp){
                    if (resp && resp.success){
                        var rows = resp.data || [];
                        var groups = {};
                        rows.forEach(function(r){
                            // created_at => DATE
                            var d = (r.created_at||'').slice(0,10) || 'Unknown';
                            if (!groups[d]) groups[d] = [];
                            groups[d].push(r);
                        });
                        var html = '';
                        var dates = Object.keys(groups).sort().reverse();
                        if (!dates.length) html = '<p>No reports found.</p>';
                        dates.forEach(function(d){
                            html += '<div class="da-date-group"><h3 class="da-date-toggle" data-date="'+d+'">'+d+' ('+groups[d].length+' report'+(groups[d].length>1?'s':'')+')</h3>';
                            html += '<div class="da-date-list" style="display:none;padding:8px;border-left:2px solid #eee;margin-bottom:12px;">';
                            groups[d].forEach(function(r){
                                html += '<div style="padding:8px;border-bottom:1px solid #f3f3f3;">';
                                html += '<strong>'+ (r.name||'') +'</strong> &nbsp; '+ (r.mobile||'') + ' &nbsp; <small>'+ (r.created_at||'') +'</small>';
                                html += ' <button class="button button-danger da-delete-report-js" data-id="'+r.id+'">Delete</button>';
                                html += '</div>';
                            });
                            html += '</div></div>';
                        });
                        $('#da-reports-wrapper').html(html);
                    } else {
                        $('#da-reports-wrapper').html('<p>Error loading reports.</p>');
                    }
                },'json');
            }

            renderReports('','');

            $('#da-report-search-btn').on('click', function(){
                var q = $('#da-report-search-q').val();
                renderReports(q,'');
            });

            $(document).on('click', '.da-date-toggle', function(){
                $(this).next('.da-date-list').slideToggle(180);
            });

            $(document).on('click', '.da-delete-report-js', function(e){
                e.preventDefault();
                var id = $(this).data('id'); if (!id) return;
                if (!confirm('Delete report #' + id + '?')) return;
                var $btn = $(this);
                $.post(da_admin_ajax.ajaxurl, { action:'da_delete_report', id:id, nonce: da_admin_ajax.nonce }, function(resp){
                    if (resp && resp.success){
                        $btn.closest('div').fadeOut(200, function(){ $(this).remove(); });
                    } else {
                        alert('Could not delete report.');
                    }
                },'json');
            });

        });



        
        </script>
        <?php
    }

}
new DA_Weekday_Plugin();
