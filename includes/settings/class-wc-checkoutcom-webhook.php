<?php

include_once 'class-wc-checkoutcom-workflows.php';

use Checkout\CheckoutApiException;
use Checkout\Webhooks\WebhookRequest;

/**
 * Class WC_Checkoutcom_Webhook
 */
class WC_Checkoutcom_Webhook {

	private static $instance = null;

	private $checkout = null;

	private $list = [];

	private $url_is_registered = false;

	private $ACCOUNT_TYPE = 'ABC';

	/**
	 * Constructor.
	 */
	public function __construct() {

		add_action( 'wp_ajax_wc_checkoutcom_register_webhook', array( $this, 'ajax_register_webhook' ) );
		add_action( 'wp_ajax_wc_checkoutcom_check_webhook', array( $this, 'ajax_check_webhook' ) );

		$this->ACCOUNT_TYPE = cko_is_nas_account() ? 'NAS' : 'ABC';

		$this->checkout = new Checkout_SDK();
	}

	/**
	 * Get singleton instance.
	 *
	 * @return WC_Checkoutcom_Webhook
	 */
	public static function get_instance(): WC_Checkoutcom_Webhook {
		if ( self::$instance == null ) {
			self::$instance = new WC_Checkoutcom_Webhook();
		}

		return self::$instance;
	}

	/**
	 * AJAX request handler for registering webhook.
	 *
	 * @return void
	 */
	public function ajax_register_webhook() {

		check_ajax_referer( 'checkoutcom_register_webhook', 'security' );

		$w_id = false;

		if ( 'ABC' === $this->ACCOUNT_TYPE ) {
			$webhook_response = (array) $this->create( $this->generate_current_webhook_url() );

			if ( empty( $webhook_response ) || empty( $webhook_response['id'] ) ) {
				WC_Checkoutcom_Utility::logger( $webhook_response, null );
			}

			$w_id = $webhook_response['id'];

		} else {

			// NAS account type.
			$workflow_response = WC_Checkoutcom_Workflows::get_instance()->create( $this->generate_current_webhook_url() );

			if ( empty( $workflow_response ) || empty( $workflow_response['id'] ) ) {
				WC_Checkoutcom_Utility::logger( $workflow_response, null );
			}

			$w_id = $workflow_response['id'];
		}

		if ( false === $w_id ) {
			wp_send_json_error( null, 400 );
		} else {
			wp_send_json_success();
		}

		wp_die();
	}

	/**
	 * Register new webhook.
	 *
	 * @param $url
	 *
	 * @return mixed|WP_Error
	 */
	public function create( $url ) {

		if ( empty( $url ) ) {
			$url = $this->generate_current_webhook_url();
		}

		$event_types = [
			'card_verification_declined',
			'card_verified',
			'dispute_canceled',
			'dispute_evidence_required',
			'dispute_expired',
			'dispute_lost',
			'dispute_resolved',
			'dispute_won',
			'payment_approved',
			'payment_canceled',
			'payment_capture_declined',
			'payment_capture_pending',
			'payment_captured',
			'payment_chargeback',
			'payment_declined',
			'payment_expired',
			'payment_paid',
			'payment_pending',
			'payment_refund_declined',
			'payment_refund_pending',
			'payment_refunded',
			'payment_retrieval',
			'payment_void_declined',
			'payment_voided',
			'source_updated',
		];

		try {
			$webhookRequest = new WebhookRequest();
			$webhookRequest->url = $url;
			$webhookRequest->content_type = 'json';
			$webhookRequest->event_types = $event_types;
			$webhookRequest->active = true;

			return $this->checkout->get_builder()->getWebhooksClient()->registerWebhook( $webhookRequest );

		} catch ( CheckoutApiException $ex ) {
			$gateway_debug = WC_Admin_Settings::get_option( 'cko_gateway_responses' ) === 'yes';

			$error_message = esc_html__( 'An error has occurred while processing webhook request.', 'wc_checkout_com' );

			if ( $gateway_debug ) {
				$error_message .= $ex->getMessage();
			}

			WC_Checkoutcom_Utility::logger( $error_message, $ex );
		}
	}

	/**
	 * Get current webhook url.
	 *
	 * @return string
	 */
	public static function generate_current_webhook_url(): string {
		return add_query_arg( 'wc-api', 'wc_checkoutcom_webhook', home_url( '/' ) );
	}

	/**
	 * AJAX request handler for checking webhook.
	 *
	 * @return string
	 */
	public function ajax_check_webhook() {

		check_ajax_referer( 'checkoutcom_check_webhook', 'security' );

		if ( 'ABC' === $this->ACCOUNT_TYPE ) {
			$webhook_is_ready = $this->is_registered();

			$message = esc_html__( 'Webhook is configured at this URL:', 'wc_checkout_com' );
		} else {
			// NAS account type.
			// @todo: Use SDK to get webhooks or workflows.
			$webhook_is_ready = WC_Checkoutcom_Workflows::get_instance()->is_registered();

			$message = esc_html__( 'Webhook is configured with this name:', 'wc_checkout_com' );
		}

		if ( $webhook_is_ready ) {

			$message = $message ? $message : esc_html__( 'Webhook is configured at this URL:', 'wc_checkout_com' );
			$message = sprintf( '%s <code>%s</code>', $message, $webhook_is_ready );
		} else {

			$message = esc_html__( 'Webhook is not configured with the current site or there is some issue with connection, Please check logs or try again.', 'wc_checkout_com' );
		}

		wp_send_json_success( array( 'message' => $message ) );
	}

	/**
	 * Check if webhook is registered.
	 *
	 * @param string $url Webhook URL.
	 *
	 * @return string|null
	 */
	public function is_registered( $url = '' ): string {
		$webhooks = $this->get_list();

		if ( empty( $url ) ) {
			$url = home_url( '/' );
		}

		foreach ( $webhooks as $item ) {
			if ( false !== strpos( $item['url'], $url ) ) {
				$this->url_is_registered = $item['url'];

				return $this->url_is_registered;
			}
		}

		return $this->url_is_registered;
	}

	/**
	 * Get list of all webhooks.
	 *
	 * @return array|mixed
	 */
	public function get_list(): array {

		if ( $this->list ) {
			return $this->list;
		}

		try {
			$webhooks = $this->checkout->get_builder()->getWebhooksClient()->retrieveWebhooks();

			if ( isset( $webhooks ) && ! empty( $webhooks['items'] ) ) {
				$this->list = $webhooks['items'];

				return $this->list;
			}

		} catch ( CheckoutApiException $ex ) {
			$gateway_debug = WC_Admin_Settings::get_option( 'cko_gateway_responses' ) === 'yes';

			$error_message = esc_html__( 'An error has occurred while processing webhook request.', 'wc_checkout_com' );

			if ( $gateway_debug ) {
				$error_message .= $ex->getMessage();
			}

			WC_Checkoutcom_Utility::logger( $error_message, $ex );
		}

		return $this->list;
	}
}

WC_Checkoutcom_Webhook::get_instance();