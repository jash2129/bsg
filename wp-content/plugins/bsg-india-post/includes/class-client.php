<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Thin client for the India Post external-customer API.
 * - Logs in with username/password, caches the 15-minute bearer token (refreshed a minute early).
 * - Re-logs in once on 401/403, then gives up.
 * - Every call returns array('ok' => bool, 'status' => int, 'data' => mixed, 'error' => string).
 * - Never logs credentials or tokens.
 */
class BSG_IP_Client {
    const TOKEN_KEY = 'bsg_ip_token';
    const KEYS_OPTION = 'bsg_ip_token_keys';

    private $base;
    private $customer;

    /**
     * $customer is the 10-digit bulk customer ID the call is made for. In production India Post
     * logs in with the customer ID itself as the username (and a default password), so each customer
     * ID has its own login and its own cached token. In UAT one shared test login is used.
     */
    public function __construct($customer = '') {
        $this->base     = BSG_IP_Settings::base_url();
        $this->customer = preg_replace('/\D+/', '', (string) $customer);
    }

    /** [username, password] for this client. */
    private function creds() {
        $s = BSG_IP_Settings::get();
        if ($s['environment'] === 'production') {
            $user = $this->customer !== '' ? $this->customer : ($s['customer_sp'] !== '' ? $s['customer_sp'] : $s['customer_bp']);
            return array((string) $user, (string) $s['password']);
        }
        return array((string) $s['username'], (string) $s['password']);
    }

    private function token_key() {
        list($user) = $this->creds();
        return self::TOKEN_KEY . '_' . md5($this->base . '|' . $user);
    }

    /** Forgets every cached token (after a settings change or a 401). */
    public static function forget_token() {
        delete_transient(self::TOKEN_KEY);
        foreach ((array) get_option(self::KEYS_OPTION, array()) as $k) {
            delete_transient($k);
        }
        delete_option(self::KEYS_OPTION);
    }

    private function result($ok, $status, $data, $error = '') {
        return array('ok' => $ok, 'status' => $status, 'data' => $data, 'error' => $error);
    }

    private function raw($method, $path, $args = array(), $auth = true, $body = null, $multipart_file = null) {
        if ($this->base === '') {
            return $this->result(false, 0, null, 'The India Post address is missing or not a secure gov.in address.');
        }
        $url = $this->base . $path;
        if ($args) {
            $url = add_query_arg(array_map('rawurlencode', $args), $url);
        }
        $headers = array('Accept' => 'application/json');
        if ($auth) {
            $t = $this->token();
            if ($t === '') {
                return $this->result(false, 401, null, 'Could not log in to India Post.');
            }
            $headers['Authorization'] = 'Bearer ' . $t;
        }
        $req = array('method' => $method, 'timeout' => 30, 'headers' => $headers, 'redirection' => 0);
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $req['headers'] = $headers;
            $req['body'] = wp_json_encode($body);
        }
        $res = wp_remote_request($url, $req);
        if (is_wp_error($res)) {
            return $this->result(false, 0, null, $res->get_error_message());
        }
        $status = (int) wp_remote_retrieve_response_code($res);
        $raw    = wp_remote_retrieve_body($res);
        $ctype  = wp_remote_retrieve_header($res, 'content-type');
        $data   = (is_string($ctype) && stripos($ctype, 'application/json') !== false) ? json_decode($raw, true) : $raw;
        $ok     = $status >= 200 && $status < 300 && !(is_array($data) && isset($data['success']) && $data['success'] === false);
        $err    = '';
        if (!$ok) {
            $err = is_array($data) ? (string) ($data['message'] ?? ('HTTP ' . $status)) : 'HTTP ' . $status;
            if (is_array($data) && !empty($data['errors'])) {
                $bits = array();
                foreach ((array) $data['errors'] as $e) {
                    $bits[] = is_array($e) ? ($e['msg'] ?? wp_json_encode($e)) : $e;
                }
                $err .= ': ' . implode('; ', $bits);
            }
        }
        return $this->result($ok, $status, $data, $err);
    }

    private function call($method, $path, $args = array(), $body = null) {
        $r = $this->raw($method, $path, $args, true, $body);
        if (in_array($r['status'], array(401, 403), true)) {
            self::forget_token();
            $r = $this->raw($method, $path, $args, true, $body);
        }
        return $r;
    }

    /** POST /v1/access/login. $force skips the cache. */
    public function login($force = false) {
        list($user, $pass) = $this->creds();
        if ($user === '' || $pass === '') {
            return $this->result(false, 0, null, BSG_IP_Settings::get('environment') === 'production' ? 'Customer ID or password is missing.' : 'Username or password is missing.');
        }
        $key = $this->token_key();
        if (!$force) {
            $t = get_transient($key);
            if ($t) {
                return $this->result(true, 200, array('cached' => true));
            }
        }
        $r = $this->raw('POST', '/v1/access/login', array(), false, array('username' => $user, 'password' => $pass));
        if ($r['ok'] && !empty($r['data']['data']['access_token'])) {
            $ttl = max(60, (int) ($r['data']['data']['expires_in'] ?? 900) - 60);
            set_transient($key, $r['data']['data']['access_token'], $ttl);
            $keys = (array) get_option(self::KEYS_OPTION, array());
            if (!in_array($key, $keys, true)) {
                $keys[] = $key;
                update_option(self::KEYS_OPTION, $keys, false);
            }
            return $this->result(true, 200, array('cached' => false));
        }
        return $this->result(false, $r['status'], null, $r['error'] ?: 'Login failed.');
    }

    private function token() {
        $t = get_transient($this->token_key());
        if (!$t) {
            $l = $this->login(true);
            $t = $l['ok'] ? get_transient($this->token_key()) : '';
        }
        return is_string($t) ? $t : '';
    }

    /** GET /v1/pincode-search. Returns offices; delivery offices are those with delivery_office_flag true and type not BPO. */
    public function pincode($pincode) {
        $pincode = preg_replace('/\D+/', '', (string) $pincode);
        if (strlen($pincode) !== 6) {
            return $this->result(false, 0, null, 'Pincode must be 6 digits.');
        }
        return $this->call('GET', '/v1/pincode-search', array('pincode' => $pincode, 'office-type' => 'post'));
    }

    /** True when the pincode has at least one delivery office (flag true, not a branch post office). */
    public static function can_deliver($offices) {
        foreach ((array) $offices as $o) {
            if (!empty($o['delivery_office_flag']) && ($o['office_type_code'] ?? '') !== 'BPO') {
                return true;
            }
        }
        return false;
    }

    /** Speed Post / Business Parcel tariff. $p: type, weight_g, from, to, l, w, h, ins, pod, cod_value. */
    public function tariff(array $p) {
        $type = $p['type'] ?? 'SP_INLAND_PARCEL';
        $common = array(
            'weight' => (int) $p['weight_g'], 'source-pincode' => $p['from'], 'destination-pincode' => $p['to'],
            'length' => $p['l'] ?? 0, 'width' => $p['w'] ?? 0, 'height' => $p['h'] ?? 0,
        );
        if (!empty($p['ins'])) {
            $common['ins'] = (int) $p['ins'];
        }
        if ($type === 'BUSINESS_PARCEL') {
            return $this->call('GET', '/v1/business-parcel-tariff/calculate', array_merge(array('product-code' => 'BP'), $common));
        }
        if ($type === 'SP_INLAND_PARCEL' || $type === 'SP_INLAND_DOC') {
            $q = array_merge(array('product-code' => 'SP'), $common);
            if (!empty($p['ins'])) { unset($q['ins']); $q['INS'] = (int) $p['ins']; }
            if (!empty($p['pod'])) { $q['POD'] = 'YES'; }
            return $this->call('GET', '/v1/speed-post/tariffs', $q);
        }
        $q = array_merge(array('product-code' => $type, 'source-office-id' => $p['from_office'] ?? '', 'destination-office-id' => $p['to_office'] ?? '', 'REG' => 'FALSE', 'OTP' => $type === '24_SPP_PARSPL' ? 'TRUE' : 'FALSE', 'ACK' => 'FALSE'), $common);
        if (!empty($p['cod_value'])) {
            $q['COD'] = 'TRUE';
            $q['CODVALUE'] = (int) $p['cod_value'];
        }
        $path = $type === '24_SPP_PARSPL' ? '/v1/parspl-tariff/calculate' : '/v1/ddd-ndd-tariff/calculate';
        return $this->call('GET', $path, $q);
    }

    /** POST /process-articles/{customerId}; up to 1000 articles per call, all under the same customer ID. */
    public function book(array $articles, $cid) {
        return $this->call('POST', '/process-articles/' . rawurlencode($cid), array(), array('articles' => array_values($articles)));
    }

    /** POST /v1/label/create/domestic; returns a PDF as raw bytes in ['data']. */
    public function label(array $labels) {
        return $this->call('POST', '/v1/label/create/domestic', array(), array_values($labels));
    }

    /** POST /v1/tracking/bulk; up to 500 barcodes. */
    public function track(array $barcodes) {
        return $this->call('POST', '/v1/tracking/bulk', array(), array('bulk' => array_values(array_slice($barcodes, 0, 500))));
    }
}
