<?php

namespace WP\OAuth2;

use WP\OAuth2\Tokens\Access_Token;
use WP_Error;
use WP_Post;
use WP_User;

class Client {
	const POST_TYPE            = 'oauth2_client';
	const CLIENT_ID_KEY        = '_oauth2_client_id';
	const CLIENT_SECRET_KEY    = '_oauth2_client_secret';
	const TYPE_KEY             = '_oauth2_client_type';
	const REDIRECT_URI_KEY     = '_oauth2_redirect_uri';
	const AUTH_CODE_KEY_PREFIX = '_oauth2_authcode_';
	const AUTH_CODE_LENGTH     = 12;
	const CLIENT_ID_LENGTH     = 12;
	const CLIENT_SECRET_LENGTH = 48;
	const AUTH_CODE_AGE        = 600; // 10 * MINUTE_IN_SECONDS

	/**
	 * @var WP_Post
	 */
	protected $post;

	/**
	 * Constructor.
	 *
	 * @param WP_Post $post
	 */
	protected function __construct( WP_Post $post ) {
		$this->post = $post;
	}

	/**
	 * Get the client's ID.
	 *
	 * @return string Client ID.
	 */
	public function get_id() {
		$result = get_post_meta( $this->get_post_id(), static::CLIENT_ID_KEY, false );
		if ( empty( $result ) ) {
			return null;
		}

		return $result[0];
	}

	/**
	 * Get the client's post ID.
	 *
	 * For internal (WordPress) use only. For external use, use get_key()
	 *
	 * @return int Client ID.
	 */
	public function get_post_id() {
		return $this->post->ID;
	}

	/**
	 * Get the client's name.
	 *
	 * @return string HTML string.
	 */
	public function get_name() {
		return get_the_title( $this->get_post_id() );
	}

	/**
	 * Get the client's description.
	 *
	 * @return string
	 */
	public function get_description() {
		$post = get_post( $this->get_post_id() );

		return $post->post_content;
	}

	/**
	 * Get the client's type.
	 *
	 * @return string Type ID if available, or an empty string.
	 */
	public function get_type() {
		return get_post_meta( $this->get_post_id(), static::TYPE_KEY, true );
	}

	/**
	 * Get the Client Secret Key.
	 *
	 * @return string The Secret Key if available, or an empty string.
	 */
	public function get_secret() {
		return get_post_meta( $this->get_post_id(), static::CLIENT_SECRET_KEY, true );
	}

	/**
	 * Get registered URI for the client.
	 *
	 * @return array List of valid redirect URIs.
	 */
	public function get_redirect_uris() {
		return (array) get_post_meta( $this->get_post_id(), static::REDIRECT_URI_KEY, true );
	}

	/**
	 * Validate a callback URL.
	 *
	 * Based on {@see wp_http_validate_url}, but less restrictive around ports
	 * and hosts. In particular, it allows any scheme, host or port rather than
	 * just HTTP with standard ports.
	 *
	 * @param string $url URL for the callback.
	 * @return bool True for a valid callback URL, false otherwise.
	 */
	public static function validate_callback( $url ) {
		if ( strpos( $url, ':' ) === false ) {
			return false;
		}

		$parsed_url = wp_parse_url( $url );
		if ( ! $parsed_url || empty( $parsed_url['host'] ) ) {
			return false;
		}

		if ( isset( $parsed_url['user'] ) || isset( $parsed_url['pass'] ) ) {
			return false;
		}

		if ( false !== strpbrk( $parsed_url['host'], ':#?[]' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if a redirect URI is valid for the client.
	 *
	 * @todo Implement this properly :)
	 *
	 * @param string $uri Supplied redirect URI to check.
	 * @return boolean True if the URI is valid, false otherwise.
	 */
	public function check_redirect_uri( $uri ) {
		if ( ! $this->validate_callback( $uri ) ) {
			return false;
		}

		$supplied = wp_parse_url( $uri );

		// Check all components except query and fragment
		$parts = array( 'scheme', 'host', 'port', 'user', 'pass', 'path' );
		$valid = true;
		foreach ( $parts as $part ) {
			if ( isset( $registered[ $part ] ) !== isset( $supplied[ $part ] ) ) {
				$valid = false;
				break;
			}

			if ( ! isset( $registered[ $part ] ) ) {
				continue;
			}

			if ( $registered[ $part ] !== $supplied[ $part ] ) {
				$valid = false;
				break;
			}
		}

		/**
		 * Filter whether a callback is counted as valid.
		 *
		 * By default, the URLs must match scheme, host, port, user, pass, and
		 * path. Query and fragment segments are allowed to be different.
		 *
		 * To change this behaviour, filter this value. Note that consumers must
		 * have a callback registered, even if you relax this restruction. It is
		 * highly recommended not to change this behaviour, as clients will
		 * expect the same behaviour across all WP sites.
		 *
		 * @param boolean $valid True if the callback URL is valid, false otherwise.
		 * @param string $url Supplied callback URL.
		 * @param WP_Post $consumer Consumer post; stored callback saved as `consumer` meta value.
		 */
		return apply_filters( 'rest_oauth.check_callback', $valid, $uri, $this );
	}

	/**
	 * @param WP_User $user
	 *
	 * @return string|WP_Error
	 */
	public function generate_authorization_code( WP_User $user ) {
		$code = wp_generate_password( static::AUTH_CODE_LENGTH, false );
		$meta_key = static::AUTH_CODE_KEY_PREFIX . $code;
		$data = array(
			'user'       => $user->ID,
			'expiration' => static::AUTH_CODE_AGE,
		);
		$result = add_post_meta( $this->get_post_id(), wp_slash( $meta_key ), wp_slash( $data ), true );
		if ( ! $result ) {
			return new WP_Error();
		}

		return $code;
	}

	/**
	 * @return bool|WP_Error
	 */
	public function regenerate_secret() {
		$result = update_post_meta( $this->get_post_id(), static::CLIENT_SECRET_KEY, wp_generate_password( static::CLIENT_SECRET_LENGTH, false ) );
		if ( ! $result ) {
			return new WP_Error( 'oauth2.client.create.failed_meta', __( 'Could not regenerate the client secret.', 'oauth2' ) );
		}

		return true;
	}

	/**
	 * Issue token for a user.
	 *
	 * @param \WP_User $user
	 * 
	 * @return Access_Token
	 */
	public function issue_token( WP_User $user ) {
		return Tokens\Access_Token::create( $this, $user );
	}

	/**
	 * Get a client by ID.
	 *
	 * @param int $id Client/post ID.
	 * @return static|null Client instance on success, null if invalid/not found.
	 */
	public static function get_by_id( $id ) {
		$post = get_post( $id );
		if ( ! $post ) {
			return null;
		}

		return new static( $post );
	}

	/**
	 * Get a client by Client ID.
	 *
	 * @param int $id Client ID of the app.
	 * @return static|null Client instance on success, null if invalid/not found.
	 */
	public static function get_by_client_id( $id ) {
		$args = array(
			'meta_query' => array(
				array(
					'key' => '_oauth2_client_id',
					'value' => $id,
					'compare' => '=',
				)
			),
			'post_type' => 'oauth2_client',
			'post_status' => 'any'
		);

		$client_ids = get_posts( $args );
		if ( count( $client_ids ) !== 1 ) {
			return null;
		}

		return new static( $client_ids[0] );
	}

	/**
	 * Create a new client.
	 *
	 * @param array $data {
	 * }
	 * @return WP_Error|Client Client instance on success, error otherwise.
	 */
	public static function create( $data ) {
		$post_data = array(
			'post_type'    => static::POST_TYPE,
			'post_title'   => $data['name'],
			'post_content' => $data['description'],
			'post_status'  => 'draft',
		);

		$post_id = wp_insert_post( wp_slash( $post_data ), true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Generate ID and secret.
		$meta = array(
			static::REDIRECT_URI_KEY  => $data['meta']['callback'],
			static::TYPE_KEY          => $data['meta']['type'],
			static::CLIENT_ID_KEY     => wp_generate_password( static::CLIENT_ID_LENGTH, false ),
			static::CLIENT_SECRET_KEY => wp_generate_password( static::CLIENT_SECRET_LENGTH, false ),
		);

		foreach ( $meta as $key => $value ) {
			$result = update_post_meta( $post_id, wp_slash( $key ), wp_slash( $value ) );
			if ( ! $result ) {
				// Failed, rollback.
				return new WP_Error( 'oauth2.client.create.failed_meta', __( 'Could not save meta value.', 'oauth2' ) );
			}
		}

		$post = get_post( $post_id );

		return new static( $post );
	}

	/**
	 * @param array $data
	 *
	 * @return WP_Error|Client Client instance on success, error otherwise.
	 */
	public function update( $data ) {
		$post_data = array(
			'ID'           => $this->get_post_id(),
			'post_type'    => static::POST_TYPE,
			'post_title'   => $data['name'],
			'post_content' => $data['description'],
		);

		$post_id = wp_update_post( wp_slash( $post_data ), true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$meta = array(
			static::REDIRECT_URI_KEY  => $data['meta']['callback'],
			static::TYPE_KEY          => $data['meta']['type'],
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, wp_slash( $key ), wp_slash( $value ) );
		}

		$post = get_post( $post_id );

		return new static( $post );
	}

	/**
	 * Delete the client.
	 *
	 * @return bool
	 */
	public function delete() {
		return (bool) wp_delete_post( $this->get_post_id(), true );
	}

	/**
	 * Register the underlying post type.
	 */
	public static function register_type() {
		register_post_type( static::POST_TYPE, array(
			'public'          => false,
			'hierarchical'    => true,
			'capability_type' => array(
				'client',
				'clients',
			),
			'supports'        => array(
				'title',
				'editor',
				'revisions',
				'author',
				'thumbnail',
			),
		));
	}
}
