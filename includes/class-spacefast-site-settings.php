<?php

defined( 'ABSPATH' ) || exit;

/**
 * Maps the small set of WordPress-owned site settings onto a connected Space.
 *
 * The mapper is deliberately pure. The client always starts from the current
 * Space config and sends its settings digest back, so unrelated dashboard or
 * repository settings are preserved and concurrent edits fail safely.
 */
final class Spacefast_Site_Settings {
	/** @return array{title:string,description:string,noindex:bool,source_url:string} */
	public static function snapshot(): array {
		return array(
			'title' => self::limit( sanitize_text_field( (string) get_bloginfo( 'name' ) ), 255 ),
			'description' => self::limit( sanitize_text_field( (string) get_bloginfo( 'description' ) ), 1000 ),
			'noindex' => '0' === (string) get_option( 'blog_public', '1' ),
			'source_url' => untrailingslashit( home_url() ),
		);
	}

	/**
	 * @param array<string,mixed> $space Current Space resource.
	 * @param array<string,mixed> $preferences WordPress sync preferences.
	 * @param array{title:string,description:string,noindex:bool,source_url:string} $snapshot WordPress values.
	 * @return array<string,mixed>
	 */
	public static function patch( array $space, array $preferences, array $snapshot, string $mode ): array {
		$config = isset( $space['config'] ) && is_array( $space['config'] ) ? $space['config'] : array();
		$body = array();

		if ( ! empty( $preferences['sync_title'] ) ) {
			if ( '' !== $snapshot['title'] ) {
				$body['title'] = $snapshot['title'];
				$config['meta'] = isset( $config['meta'] ) && is_array( $config['meta'] ) ? $config['meta'] : array();
				$config['meta']['title'] = self::limit( $snapshot['title'], 300 );
			}
			if ( '' !== $snapshot['description'] ) {
				$config['meta'] = isset( $config['meta'] ) && is_array( $config['meta'] ) ? $config['meta'] : array();
				$config['meta']['description'] = $snapshot['description'];
			} elseif ( isset( $config['meta'] ) && is_array( $config['meta'] ) ) {
				unset( $config['meta']['description'] );
				if ( array() === $config['meta'] ) unset( $config['meta'] );
			}
		}

		if ( ! empty( $preferences['sync_visibility'] ) ) {
			$body['noindex'] = $snapshot['noindex'];
		}

		if ( Spacefast_Settings::MODE_HEADLESS === $mode && ! empty( $preferences['sync_source'] ) ) {
			$config['dataSources'] = isset( $config['dataSources'] ) && is_array( $config['dataSources'] )
				? $config['dataSources']
				: array();
			$config['dataSources']['wordpress'] = array(
				'kind' => 'wordpress',
				'url' => $snapshot['source_url'],
			);
			$config['defaultDataSource'] = 'wordpress';
		}

		$body['config'] = $config;
		if ( isset( $space['settingsDigest'] ) && is_string( $space['settingsDigest'] ) ) {
			$body['baseSettingsDigest'] = $space['settingsDigest'];
		}
		return $body;
	}

	private static function limit( string $value, int $length ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
	}
}
