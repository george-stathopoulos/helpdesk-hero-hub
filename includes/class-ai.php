<?php
/**
 * Optional AI help, through the WordPress AI Client (WordPress 7.0+).
 *
 * @package Helpdesk_Hero_Hub
 */

defined( 'ABSPATH' ) || exit;

/**
 * Uses whatever AI provider the site owner connected in WordPress (Settings › Connectors).
 * Helpdesk Hero Hub has no AI service of its own and stores no AI keys. Nothing is sent to an
 * AI provider unless someone presses an AI button.
 */
final class Helpdesk_Hero_Hub_AI {

	/**
	 * Cached availability.
	 *
	 * @var bool|null
	 */
	private static $available = null;

	/**
	 * Whether text generation is available on this site.
	 *
	 * @return bool
	 */
	public static function available() {
		if ( null === self::$available ) {
			self::$available = false;
			if ( function_exists( 'wp_ai_client_prompt' ) ) {
				try {
					self::$available = (bool) wp_ai_client_prompt( 'ping' )->is_supported_for_text_generation();
				} catch ( Throwable $e ) {
					self::$available = false;
				}
			}
		}
		/**
		 * Filters whether Helpdesk Hero's AI features are available.
		 *
		 * @param bool $available Available.
		 */
		return (bool) apply_filters( 'helpdesk_hero_hub_ai_available', self::$available );
	}

	/**
	 * The local, in-browser AI provider "AI Provider for WebLLM": the model runs in the browser
	 * (WebGPU), so there's no API key, no cost and nothing leaves the site. PHP-started requests
	 * need its "In-browser worker" option and an open wp-admin tab.
	 *
	 * @return array { installed, active, worker, settings, plugins, url }
	 */
	public static function local_ai() {
		$active = defined( 'WEBLLM_API_KEY' ) || class_exists( 'WordPress\\WebLlmAiProvider\\Provider\\WebLlmProvider' );
		return array(
			'installed' => $active || file_exists( WP_PLUGIN_DIR . '/ai-provider-for-webllm/plugin.php' ),
			'active'    => $active,
			'worker'    => $active && (bool) get_option( 'ai_provider_webllm_worker_enabled', false ),
			'settings'  => admin_url( 'options-general.php?page=ai-provider-webllm' ),
			'plugins'   => admin_url( 'plugins.php' ),
			'url'       => 'https://github.com/ProgressPlanner/ai-provider-for-webllm',
		);
	}

	/**
	 * Whether AI generation may need a browser (the local WebLLM provider is active), so it must
	 * not be started from cron or WP-CLI.
	 *
	 * @return bool
	 */
	public static function needs_browser() {
		return self::local_ai()['active'];
	}

	/**
	 * Generate text.
	 *
	 * @param string $prompt Prompt.
	 * @param string $system System instruction.
	 * @return string|WP_Error
	 */
	public static function text( $prompt, $system ) {
		/**
		 * Short-circuits text generation (for tests, or to use another AI service).
		 *
		 * @param string|WP_Error|null $text   Return a string or WP_Error to skip the AI Client.
		 * @param string               $prompt Prompt.
		 * @param string               $system System instruction.
		 */
		$pre = apply_filters( 'helpdesk_hero_hub_ai_pre_text', null, $prompt, $system );
		if ( null !== $pre ) {
			return is_wp_error( $pre ) ? $pre : trim( (string) $pre );
		}
		if ( ! self::available() ) {
			return new WP_Error( 'helpdesk_hero_ai', __( 'No AI provider is connected to this site. Connect one in Settings › Connectors.', 'helpdesk-hero-hub' ) );
		}
		try {
			$result = wp_ai_client_prompt( $prompt )->using_system_instruction( $system )->generate_text();
		} catch ( Throwable $e ) {
			return new WP_Error( 'helpdesk_hero_ai', $e->getMessage() );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return trim( (string) $result );
	}

	/**
	 * Generate JSON (the model is asked for JSON only; fences are stripped).
	 *
	 * @param string $prompt Prompt.
	 * @param string $system System instruction.
	 * @return array|WP_Error
	 */
	public static function json( $prompt, $system ) {
		$text = self::text( $prompt, $system . "\n\nAnswer with one JSON object only. No markdown, no code fences, no commentary." );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		$text = preg_replace( '/^```(?:json)?\s*|\s*```$/m', '', $text );
		$from = strpos( $text, '{' );
		$to   = strrpos( $text, '}' );
		$data = false !== $from && false !== $to ? json_decode( substr( $text, $from, $to - $from + 1 ), true ) : null;
		return is_array( $data ) ? $data : new WP_Error( 'helpdesk_hero_hub_ai_json', __( 'The AI answer could not be read. Try again.', 'helpdesk-hero-hub' ) );
	}

	/**
	 * Health flags as compact text for prompts.
	 *
	 * @param array $flags Flags.
	 * @return string
	 */
	public static function flags_text( array $flags ) {
		$lines = array();
		foreach ( $flags as $flag ) {
			$lines[] = '- [' . $flag['level'] . '] ' . $flag['title'] . ( $flag['detail'] ? ': ' . $flag['detail'] : '' );
		}
		return $lines ? implode( "\n", $lines ) : '- none';
	}
}
