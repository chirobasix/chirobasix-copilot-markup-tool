<?php
/**
 * Plugin Name: ChiroBasix Copilot - MarkUp Bridge
 * Description: Allows copilot.chirobasix.com to embed this site in an iframe and provides
 *              a postMessage bridge for the MarkUp feedback tool (scroll tracking, navigation).
 * Version: 2.1.0
 * Author: ChiroBasix
 * GitHub Repo: chirobasix/chirobasix-copilot-markup-tool
 */

defined( 'ABSPATH' ) || exit;

// ─── GitHub Self-Updater for MU-Plugins ──────────────────────────────────────
// MU-plugins don't use the standard WP update system, so this class checks
// GitHub releases and overwrites this file when a newer version is available.

class CBX_Copilot_Bridge_Updater {

    private const GITHUB_OWNER = 'chirobasix';
    private const GITHUB_REPO  = 'chirobasix-copilot-markup-tool';
    private const TRANSIENT    = 'cbx_copilot_bridge_update';
    private const CHECK_INTERVAL = 6 * HOUR_IN_SECONDS;

    private $current_version;
    private $plugin_file;

    public function __construct() {
        $this->plugin_file     = __FILE__;
        $this->current_version = $this->get_local_version();

        add_action( 'admin_init', array( $this, 'maybe_update' ) );
        add_action( 'admin_notices', array( $this, 'update_notice' ) );
    }

    private function get_local_version() {
        $data = get_file_data( __FILE__, array( 'Version' => 'Version' ) );
        return $data['Version'] ?? '0.0.0';
    }

    private function get_latest_release() {
        $cached = get_transient( self::TRANSIENT );
        if ( false !== $cached ) {
            return $cached;
        }

        $url = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            self::GITHUB_OWNER,
            self::GITHUB_REPO
        );

        $response = wp_remote_get( $url, array(
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'CBX-Copilot-Bridge-Updater',
            ),
            'timeout' => 10,
        ) );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            // Cache the failure briefly so we don't hammer the API
            set_transient( self::TRANSIENT, array( 'tag_name' => '0.0.0' ), 30 * MINUTE_IN_SECONDS );
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['tag_name'] ) ) {
            return false;
        }

        set_transient( self::TRANSIENT, $body, self::CHECK_INTERVAL );
        return $body;
    }

    public function maybe_update() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Manual trigger: ?cbx_copilot_bridge_update=1
        if ( ! isset( $_GET['cbx_copilot_bridge_update'] ) ) {
            // Also run on the background schedule check
            $release = $this->get_latest_release();
            if ( ! $release ) {
                return;
            }

            $remote_version = ltrim( $release['tag_name'], 'vV' );
            if ( ! version_compare( $remote_version, $this->current_version, '>' ) ) {
                return;
            }

            // Auto-update: download and replace
            $this->do_update( $release );
            return;
        }

        // Manual trigger
        check_admin_referer( 'cbx_copilot_bridge_update' );

        delete_transient( self::TRANSIENT );
        $release = $this->get_latest_release();
        if ( ! $release ) {
            add_settings_error( 'cbx_copilot_bridge', 'update_failed', 'Could not fetch latest release from GitHub.', 'error' );
            return;
        }

        $remote_version = ltrim( $release['tag_name'], 'vV' );
        if ( ! version_compare( $remote_version, $this->current_version, '>' ) ) {
            add_settings_error( 'cbx_copilot_bridge', 'up_to_date', 'Already running the latest version (' . $this->current_version . ').', 'info' );
            return;
        }

        $this->do_update( $release );
    }

    private function do_update( $release ) {
        $remote_version = ltrim( $release['tag_name'], 'vV' );

        // Download the raw PHP file from the release (single-file mu-plugin)
        $raw_url = sprintf(
            'https://raw.githubusercontent.com/%s/%s/%s/allow-copilot-iframe.php',
            self::GITHUB_OWNER,
            self::GITHUB_REPO,
            $release['tag_name']
        );

        $response = wp_remote_get( $raw_url, array(
            'timeout'    => 15,
            'headers'    => array( 'User-Agent' => 'CBX-Copilot-Bridge-Updater' ),
        ) );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return;
        }

        $new_content = wp_remote_retrieve_body( $response );

        // Sanity check: must contain our plugin header
        if ( false === strpos( $new_content, 'ChiroBasix Copilot - MarkUp Bridge' ) ) {
            return;
        }

        // Write the updated file
        global $wp_filesystem;
        if ( empty( $wp_filesystem ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        $result = $wp_filesystem->put_contents( $this->plugin_file, $new_content, FS_CHMOD_FILE );

        if ( $result ) {
            delete_transient( self::TRANSIENT );
            update_option( 'cbx_copilot_bridge_updated', $remote_version );
        }
    }

    public function update_notice() {
        $updated_version = get_option( 'cbx_copilot_bridge_updated' );
        if ( $updated_version ) {
            delete_option( 'cbx_copilot_bridge_updated' );
            printf(
                '<div class="notice notice-success is-dismissible"><p><strong>ChiroBasix Copilot MarkUp Bridge</strong> has been updated to version %s.</p></div>',
                esc_html( $updated_version )
            );
        }
    }
}

new CBX_Copilot_Bridge_Updater();

// ─── Iframe Embedding Headers ────────────────────────────────────────────────

add_action('send_headers', function () {
    header("Content-Security-Policy: frame-ancestors 'self' https://copilot.chirobasix.com");
    header_remove('X-Frame-Options');
});

// ─── MarkUp Bridge Script ────────────────────────────────────────────────────

add_action('wp_footer', function () {
    ?>
    <script>
    (function() {
        if (window.self === window.top) return; // Not in an iframe, skip

        function reportState() {
            window.parent.postMessage({
                type: 'markup-scroll',
                scrollX: window.scrollX,
                scrollY: window.scrollY,
                pageWidth: document.documentElement.scrollWidth,
                pageHeight: document.documentElement.scrollHeight,
                viewportWidth: window.innerWidth,
                viewportHeight: window.innerHeight,
                currentUrl: window.location.href,
                timestamp: Date.now()
            }, '*');
        }

        // Listen for commands from parent
        window.addEventListener('message', function(e) {
            if (e.data && e.data.type === 'markup-get-scroll') {
                reportState();
            } else if (e.data && e.data.type === 'markup-scroll-to') {
                var scrollBehavior = e.data.behavior || 'smooth';
                window.scrollTo({
                    left: e.data.scrollX || 0,
                    top: e.data.scrollY || 0,
                    behavior: scrollBehavior
                });
                setTimeout(reportState, scrollBehavior === 'instant' ? 50 : 400);
            } else if (e.data && e.data.type === 'markup-set-comment-mode') {
                document.body.style.cursor = e.data.enabled ? 'crosshair' : '';
            } else if (e.data && e.data.type === 'markup-clear-selection') {
                window.getSelection().removeAllRanges();
            }
        });

        // Track whether text was just selected (to suppress click after selection)
        var hadTextSelection = false;

        // Listen for text selection FIRST (mouseup fires before click)
        document.addEventListener('mouseup', function() {
            var sel = window.getSelection();
            if (sel && sel.toString().trim().length > 0) {
                hadTextSelection = true;
                var range = sel.getRangeAt(0);
                var rect = range.getBoundingClientRect();
                window.parent.postMessage({
                    type: 'markup-text-selected',
                    selectedText: sel.toString().trim(),
                    rectTop: rect.top + window.scrollY,
                    rectLeft: rect.left + window.scrollX,
                    rectWidth: rect.width,
                    rectHeight: rect.height,
                    viewportX: rect.left + rect.width / 2,
                    viewportY: rect.top,
                    scrollX: window.scrollX,
                    scrollY: window.scrollY,
                    viewportWidth: window.innerWidth,
                    viewportHeight: window.innerHeight,
                    currentUrl: window.location.href
                }, '*');
            } else {
                hadTextSelection = false;
            }
        });

        // Listen for clicks (for pin placement in comment mode)
        // Suppress if text was just selected (mouseup already handled it)
        document.addEventListener('click', function(e) {
            if (hadTextSelection) {
                hadTextSelection = false;
                return;
            }
            window.parent.postMessage({
                type: 'markup-click',
                viewportX: e.clientX,
                viewportY: e.clientY,
                pageX: e.pageX,
                pageY: e.pageY,
                scrollX: window.scrollX,
                scrollY: window.scrollY,
                viewportWidth: window.innerWidth,
                viewportHeight: window.innerHeight,
                currentUrl: window.location.href
            }, '*');
        });

        // Report on scroll — continuous rAF loop while scrolling for smooth pin tracking
        var scrolling = false;
        var scrollTimer = null;
        function scrollLoop() {
            if (!scrolling) return;
            reportState();
            requestAnimationFrame(scrollLoop);
        }
        window.addEventListener('scroll', function() {
            if (!scrolling) {
                scrolling = true;
                reportState(); // immediate first report
                requestAnimationFrame(scrollLoop);
            }
            clearTimeout(scrollTimer);
            scrollTimer = setTimeout(function() {
                scrolling = false;
                reportState(); // final report when scroll stops
            }, 150);
        }, { passive: true });

        // Report on resize
        window.addEventListener('resize', reportState);

        // Initial report after DOM ready
        if (document.readyState === 'complete') {
            reportState();
        } else {
            window.addEventListener('load', reportState);
        }
    })();
    </script>
    <?php
});
