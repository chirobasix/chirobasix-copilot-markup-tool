<?php
/**
 * Plugin Name: ChiroBasix Copilot - MarkUp Bridge
 * Description: Allows copilot.chirobasix.com to embed this site in an iframe and provides
 *              a postMessage bridge for the MarkUp feedback tool (scroll tracking, navigation).
 * Version: 2.3.0
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

// ─── Suppress Popups in Copilot iframe ─────────────────────────────────────

add_action('wp_head', function () {
    // Only inject when loaded inside the Copilot iframe
    if ( empty( $_SERVER['HTTP_SEC_FETCH_DEST'] ) || $_SERVER['HTTP_SEC_FETCH_DEST'] !== 'iframe' ) {
        // Fallback: check referer
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if ( strpos( $referer, 'copilot.chirobasix.com' ) === false && strpos( $referer, 'localhost' ) === false ) {
            return;
        }
    }
    ?>
    <style id="cbx-copilot-popup-suppress">
    /* Common WordPress popup / modal selectors */
    .elementor-popup-modal,
    .elementor-location-popup,
    [data-elementor-type="popup"],
    .jet-popup,
    .pum-overlay,
    .pum-container,
    .sg-popup-overlay,
    .hustle-popup,
    .hustle-modal,
    .omapi-holder,
    .optinmonster,
    #om-holder,
    .sumo-overlay,
    .icegram,
    .nf-modal-bg,
    .ninja-forms-modal,
    .wpforms-container-full.wpforms-popup,
    .cookie-notice,
    #cookie-notice,
    #cookie-law-info-bar,
    .cc-window,
    .cky-consent-container,
    #CybotCookiebotDialog,
    .moove-gdpr-info-bar-container,
    .gdpr-cookie-banner,
    [class*="cookie-consent"],
    [class*="cookie-banner"],
    [id*="cookie-popup"],
    .tawk-min-container,
    #tidio-chat,
    .fb_dialog,
    .drift-frame-controller,
    .intercom-lightweight-app,
    .crisp-client,
    [class*="chat-widget"],
    [id*="chat-widget"] {
        display: none !important;
        visibility: hidden !important;
        opacity: 0 !important;
        pointer-events: none !important;
    }

    /* Hide overlay backdrops */
    .pum-overlay,
    .sg-popup-overlay,
    .elementor-popup-modal ~ .dialog-lightbox-overlay,
    .modal-backdrop,
    [class*="popup-overlay"] {
        display: none !important;
    }

    /* Prevent body scroll lock from popups */
    body.pum-open,
    body.elementor-popup-modal-open,
    body.modal-open,
    body.no-scroll,
    body.noscroll,
    body.overflow-hidden {
        overflow: auto !important;
        position: static !important;
    }
    </style>
    <?php
});

add_action('wp_footer', function () {
    ?>
    <script>
    (function() {
        if (window.self === window.top) return; // Not in an iframe, skip

        // ─── Popup suppression (JS-created popups) ─────────────────
        // Watch for dynamically added popup elements and hide them
        var popupObserver = new MutationObserver(function(mutations) {
            mutations.forEach(function(m) {
                m.addedNodes.forEach(function(node) {
                    if (node.nodeType !== 1) return;
                    var el = node;
                    var cls = (el.className || '').toString().toLowerCase();
                    var id = (el.id || '').toLowerCase();
                    var isPopup =
                        cls.indexOf('popup') !== -1 ||
                        cls.indexOf('modal') !== -1 ||
                        cls.indexOf('overlay') !== -1 ||
                        cls.indexOf('cookie') !== -1 ||
                        cls.indexOf('chat-widget') !== -1 ||
                        cls.indexOf('optinmonster') !== -1 ||
                        cls.indexOf('hustle') !== -1 ||
                        id.indexOf('popup') !== -1 ||
                        id.indexOf('modal') !== -1 ||
                        id.indexOf('cookie') !== -1 ||
                        el.getAttribute('data-elementor-type') === 'popup';
                    if (isPopup) {
                        el.style.display = 'none';
                        el.style.visibility = 'hidden';
                    }
                });
            });
        });
        popupObserver.observe(document.body || document.documentElement, { childList: true, subtree: true });

        function getScrollX() {
            return window.scrollX || window.pageXOffset || document.documentElement.scrollLeft || document.body.scrollLeft || 0;
        }
        function getScrollY() {
            return window.scrollY || window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0;
        }

        function reportState() {
            window.parent.postMessage({
                type: 'markup-scroll',
                scrollX: getScrollX(),
                scrollY: getScrollY(),
                pageWidth: document.documentElement.scrollWidth,
                pageHeight: document.documentElement.scrollHeight,
                viewportWidth: window.innerWidth,
                viewportHeight: window.innerHeight,
                currentUrl: window.location.href,
                timestamp: Date.now()
            }, '*');
        }

        // ─── html2canvas loader (lazy, on first capture request) ───
        var html2canvasPromise = null;
        function loadHtml2Canvas() {
            if (html2canvasPromise) return html2canvasPromise;
            html2canvasPromise = new Promise(function(resolve, reject) {
                if (window.html2canvas) return resolve(window.html2canvas);
                var s = document.createElement('script');
                s.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
                s.onload = function() { resolve(window.html2canvas); };
                s.onerror = reject;
                document.head.appendChild(s);
            });
            return html2canvasPromise;
        }

        function captureScreenshot(opts) {
            var size = opts.size || 400;
            var pageX = opts.pageX || 0;
            var pageY = opts.pageY || 0;
            var requestId = opts.requestId || null;

            var x = Math.max(0, pageX - size / 2);
            var y = Math.max(0, pageY - size / 2);

            return loadHtml2Canvas().then(function(html2canvas) {
                return html2canvas(document.body, {
                    x: x,
                    y: y,
                    width: size,
                    height: size,
                    useCORS: true,
                    allowTaint: true,
                    logging: false,
                    backgroundColor: '#ffffff',
                    scale: 1
                });
            }).then(function(canvas) {
                var dataUrl = canvas.toDataURL('image/png');
                window.parent.postMessage({
                    type: 'markup-screenshot-result',
                    requestId: requestId,
                    dataUrl: dataUrl,
                    pageX: pageX,
                    pageY: pageY,
                    size: size
                }, '*');
            }).catch(function(err) {
                window.parent.postMessage({
                    type: 'markup-screenshot-result',
                    requestId: requestId,
                    error: String(err && err.message || err)
                }, '*');
            });
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
            } else if (e.data && e.data.type === 'markup-capture-screenshot') {
                captureScreenshot(e.data);
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

        // Report on EVERY scroll event — browsers throttle to ~60fps so this is safe.
        // Also keep a rAF loop running briefly to catch momentum/inertial scroll on
        // touch devices and during smooth-scroll animations.
        var scrolling = false;
        var scrollTimer = null;
        function scrollLoop() {
            if (!scrolling) return;
            reportState();
            requestAnimationFrame(scrollLoop);
        }
        function onScroll() {
            reportState(); // fire immediately on every scroll event
            if (!scrolling) {
                scrolling = true;
                requestAnimationFrame(scrollLoop);
            }
            clearTimeout(scrollTimer);
            scrollTimer = setTimeout(function() {
                scrolling = false;
                reportState();
            }, 200);
        }
        window.addEventListener('scroll', onScroll, { passive: true, capture: true });
        // Some plugins scroll the documentElement directly — listen there too
        document.addEventListener('scroll', onScroll, { passive: true, capture: true });
        window.addEventListener('wheel', onScroll, { passive: true });
        window.addEventListener('touchmove', onScroll, { passive: true });

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
