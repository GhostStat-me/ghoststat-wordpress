<?php
/**
 * GhostStat Insights kit 1.2 — put this file in your plugin, extension, module, add-on or own software.
 *
 * After the site owner agrees, it tells you, the developer, through GhostStat:
 *   - once a day that your product is in use on that site, with its version, the platform version and the PHP version
 *   - when it is activated, deactivated, updated or uninstalled
 *   - only when you call error(): the main line of an error message (file paths shortened)
 * It never sends: the site address, IP addresses, visitor or user data, other plugins. No cookies.
 * GhostStat looks up the country of the reporting server per request and does not store the IP address.
 * A random install ID (made after the owner agreed, not derived from anything) tells installs apart.
 *
 * How the owner agrees: by default the kit asks (opt-in, once). If your product is itself a service the owner signs up
 * for (an API key, an account), that sign-up can be the consent instead, with no question and no button: pass
 * 'consent' => function () { return <the owner has connected your service>; }. While it returns false nothing is sent
 * and the install ID is forgotten. Say what is sent in your readme / privacy text. (WordPress.org guideline 7 allows
 * this for Software as a Service; for any other plugin keep the question.)
 *
 * Works with PHP 7.2 and newer. No dependencies. Free to include in your product (MIT).
 * Your numbers: https://app.ghoststat.me/software-development → My products → your platform tab.
 *
 * WordPress (one line in your main plugin file; it asks the owner on the Plugins screen and the Dashboard):
 *     require_once __DIR__ . '/ghoststat-insights.php';
 *     $insights = GhostStat_Insights_1::wordpress(__FILE__, array('key' => 'gsp_…', 'name' => 'My Plugin', 'version' => '1.4.0'));
 *     // optional, on your settings screen, so the owner can change the answer later:  echo $insights->wpChoice();
 *     // optional wording (string or callable, e.g. function () { return __('…', 'my-plugin'); }): 'text', 'allow', 'deny', 'on', 'stop'
 *     // a service the owner connects to: add 'consent' => function () { … } and there is no question at all (see above)
 *
 * Any other PHP platform:
 *     require_once __DIR__ . '/ghoststat-insights.php';
 *     $insights = GhostStat_Insights_1::start(array('key' => 'gsp_…', 'name' => 'My Extension', 'version' => '1.4.0',
 *                                                  'platform' => 'opencart', 'platform_version' => '4.0.2.3'));
 *     // in your settings screen: show $insights->consentText() with a yes/no choice, then call $insights->setConsent(true|false)
 *     // on admin page loads:      $insights->tick();        (reports at most once a day, only after a yes)
 *     // in your install/remove:   $insights->activated(); / $insights->deactivated(); / $insights->uninstalled();
 *     // where you catch errors:   $insights->error($e->getMessage());
 * Settings are kept in a small JSON file next to this one, unless you pass 'get' and 'set' callables to keep them in
 * your platform's own settings: 'get' => function ($name) { … return $value; }, 'set' => function ($name, $value) { … }.
 */
if (!defined('ABSPATH')) {
    exit;
}
if (!class_exists('GhostStat_Insights_1')) {
    final class GhostStat_Insights_1
    {
        const KIT = '1.2';
        const ENDPOINT = 'https://api.ghoststat.me/collect/insights';
        const DAY = 86400;
        const ERRORS_PER_DAY = 10;

        /** @var array */
        private $o;

        /** @param array $o key, name, version, platform, platform_version, get, set, store, endpoint, consent */
        public static function start(array $o)
        {
            return new self($o);
        }

        private function __construct(array $o)
        {
            $this->o = array_merge(array(
                'key' => '', 'name' => 'this product', 'version' => '', 'platform' => 'custom', 'platform_version' => '',
                'get' => null, 'set' => null, 'store' => null, 'endpoint' => self::ENDPOINT,
                // consent given another way (the owner connected your service): bool or callable; null = ask (opt-in)
                'consent' => null,
                // your own (translated) wording; a string or a callable that returns one (WordPress: translate late, after init)
                'text' => null, 'allow' => 'Allow', 'deny' => 'No thanks', 'on' => null, 'stop' => 'Stop sharing',
            ), $o);
            if (!is_callable($this->o['get']) || !is_callable($this->o['set'])) {
                $opt = 'ghoststat_insights_' . substr(md5((string)$this->o['key']), 0, 12);
                $this->o['get'] = function ($k) use ($opt) { $a = get_option($opt, array()); return is_array($a) && array_key_exists($k, $a) ? $a[$k] : null; };
                $this->o['set'] = function ($k, $v) use ($opt) {
                    $a = get_option($opt, array()); if (!is_array($a)) $a = array();
                    if ($v === null) unset($a[$k]); else $a[$k] = $v;
                    update_option($opt, $a, false);
                };
            }
        }

        private function get($k) { return call_user_func($this->o['get'], $k); }
        private function set($k, $v) { call_user_func($this->o['set'], $k, $v); }

        /** true = the owner agreed, false = declined, null = not asked yet. */
        public function consent()
        {
            if ($this->o['consent'] !== null) return $this->implied();
            $c = $this->get('consent');
            return $c === 'yes' ? true : ($c === 'no' ? false : null);
        }

        /** Consent through the owner's sign-up to your service: no question; forgets the install ID while it is off. */
        private function implied()
        {
            $c = $this->o['consent'];
            $on = (bool)(is_callable($c) ? call_user_func($c) : $c);
            if ($on && !$this->get('install')) {
                $this->set('install', bin2hex(random_bytes(16)));
                $this->set('seen_version', $this->o['version']);
                $this->set('last', null);
            } elseif (!$on && $this->get('install')) {
                foreach (array('install', 'last', 'seen_version') as $k) $this->set($k, null);
            }
            return $on;
        }

        private function label($k, $fallback)
        {
            $v = $this->o[$k] ?? null;
            if (is_callable($v)) $v = call_user_func($v);
            return is_string($v) && $v !== '' ? $v : $fallback;
        }

        /** The text to show the site owner next to the yes/no choice. */
        public function consentText()
        {
            if ($this->o['text'] !== null) return $this->label('text', '');
            return 'Help improve ' . $this->o['name'] . '? Allow it to tell its developer once a day, through GhostStat, that it is in use: '
                . 'its version, the platform and PHP version, the server\'s country and, when something goes wrong, the main line of an error message. '
                . 'No site address, no visitor data. You can change this at any time.';
        }

        public function setConsent($yes)
        {
            if ($this->o['consent'] !== null) return;
            $was = $this->consent();
            if ($yes) {
                $this->set('consent', 'yes');
                if (!$this->get('install')) $this->set('install', bin2hex(random_bytes(16)));
                if ($was !== true) { $this->send('activated'); $this->set('last', time()); $this->set('seen_version', $this->o['version']); }
            } else {
                if ($was === true) $this->send('opted_out');
                $this->set('consent', 'no');
                $this->set('install', null);
            }
        }

        /** Call on admin page loads (or from a daily task). Reports at most once a day, and only after a yes. */
        public function tick()
        {
            if ($this->consent() !== true) return;
            $last = (int)$this->get('last');
            if ($last > 0 && time() - $last < self::DAY) return;
            $this->set('last', time());
            $seen = (string)$this->get('seen_version');
            if ($seen !== '' && $seen !== (string)$this->o['version']) $this->send('updated', array('from' => $seen));
            $this->set('seen_version', $this->o['version']);
            $this->send('heartbeat');
        }

        public function activated()   { if ($this->consent() === true) $this->send('activated'); }
        public function deactivated() { if ($this->consent() === true) $this->send('deactivated'); }
        public function uninstalled() { if ($this->consent() === true) $this->send('uninstalled'); }

        /** The main line of an error message; at most 10 a day. */
        public function error($message)
        {
            if ($this->consent() !== true) return;
            $day = gmdate('Ymd');
            $n = $this->get('err_day') === $day ? (int)$this->get('err_n') : 0;
            if ($n >= self::ERRORS_PER_DAY) return;
            $this->set('err_day', $day); $this->set('err_n', $n + 1);
            $this->send('error', array('message' => self::scrub((string)$message)));
        }

        /** First line only, file paths shortened to the file name, at most 300 characters. */
        public static function scrub($m)
        {
            $m = trim((string)strtok(str_replace("\r", "\n", $m), "\n"));
            $m = preg_replace('~(?:[A-Za-z]:)?[\\\\/](?:[^\\\\/\s:\'"]+[\\\\/])+([^\\\\/\s:\'"]+)~', '$1', $m);
            return function_exists('mb_substr') ? mb_substr($m, 0, 300) : substr($m, 0, 300);
        }

        private function send($event, array $extra = array())
        {
            $install = (string)$this->get('install');
            if ($install === '' || $this->o['key'] === '') return;
            $body = wp_json_encode(array_merge(array(
                'key' => $this->o['key'], 'install' => $install, 'event' => $event, 'kit' => self::KIT,
                'version' => (string)$this->o['version'], 'platform' => (string)$this->o['platform'],
                'platform_version' => (string)$this->o['platform_version'], 'php' => PHP_VERSION,
            ), $extra));
            $url = (string)$this->o['endpoint'];
            $ua = 'GhostStat-Insights/' . self::KIT;
            wp_remote_post($url, array('timeout' => 3, 'user-agent' => $ua,
                'headers' => array('Content-Type' => 'application/json'), 'body' => $body));
        }

        // ------------------------------------------------------------------ WordPress
        /** @var GhostStat_Insights_1[] */
        private static $wp = array();
        /** @var string */
        private $wpId = '';

        /** One line in the main plugin file. Asks the owner on the Plugins screen and the Dashboard (administrators only). */
        public static function wordpress($pluginFile, array $o)
        {
            $opt = 'ghoststat_insights_' . substr(md5((string)($o['key'] ?? '')), 0, 12);
            $o['get'] = function ($k) use ($opt) { $a = get_option($opt, array()); return is_array($a) && array_key_exists($k, $a) ? $a[$k] : null; };
            $o['set'] = function ($k, $v) use ($opt) {
                $a = get_option($opt, array()); if (!is_array($a)) $a = array();
                if ($v === null) unset($a[$k]); else $a[$k] = $v;
                update_option($opt, $a, false);
            };
            $o['platform'] = 'wordpress';
            $o['platform_version'] = function_exists('get_bloginfo') ? (string)get_bloginfo('version') : '';
            $gs = new self($o);
            $id = substr(md5((string)$o['key']), 0, 12);
            $gs->wpId = $id;
            self::$wp[$id] = $gs;
            register_activation_hook($pluginFile, array($gs, 'activated'));
            register_deactivation_hook($pluginFile, array($gs, 'deactivated'));
            add_action('admin_init', array($gs, 'tick'));
            add_action('admin_notices', function () use ($gs, $id) { $gs->wpNotice($id); });
            add_action('admin_post_ghoststat_insights_' . $id, function () use ($gs, $id) {
                if (!current_user_can('manage_options')) wp_die('', '', array('response' => 403));
                check_admin_referer('ghoststat_insights_' . $id);
                $gs->setConsent(isset($_POST['answer']) && sanitize_key(wp_unslash($_POST['answer'])) === 'yes');
                wp_safe_redirect(wp_get_referer() ? wp_get_referer() : admin_url('plugins.php'));
                exit;
            });
            return $gs;
        }

        /** @internal the opt-in question, until it is answered */
        public function wpNotice($id)
        {
            if ($this->o['consent'] !== null || $this->consent() !== null || !current_user_can('manage_options')) return;
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if ($screen && !in_array($screen->id, array('plugins', 'dashboard'), true)) return;
            echo '<div class="notice notice-info">' . $this->wpChoice() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wpChoice() escapes every part it builds.
        }

        /**
         * The question with its buttons, or (after a yes) "sharing is on" with a Stop button. Put it on your own settings
         * screen too, so the owner can change the answer later: echo $insights->wpChoice();
         */
        public function wpChoice()
        {
            if ($this->wpId === '' || $this->o['consent'] !== null || !function_exists('admin_url')) return '';
            $on = $this->consent() === true;
            $h = '<p>' . esc_html($on ? $this->label('on', 'Sharing is on: ' . $this->consentText()) : $this->consentText()) . '</p>';
            $h .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><p>';
            $h .= '<input type="hidden" name="action" value="' . esc_attr('ghoststat_insights_' . $this->wpId) . '">';
            $h .= wp_nonce_field('ghoststat_insights_' . $this->wpId, '_wpnonce', true, false);
            if ($on) $h .= '<button class="button" name="answer" value="no">' . esc_html($this->label('stop', 'Stop sharing')) . '</button>';
            else $h .= '<button class="button button-primary" name="answer" value="yes">' . esc_html($this->label('allow', 'Allow')) . '</button> <button class="button" name="answer" value="no">' . esc_html($this->label('deny', 'No thanks')) . '</button>';
            return $h . '</p></form>';
        }
    }
}
