<?php

namespace Yoast\WP\SEO\Builders;

use wpdb;
use Yoast\WP\SEO\Exceptions\Indexable\Author_Not_Built_Exception;
use Yoast\WP\SEO\Helpers\Author_Archive_Helper;
use Yoast\WP\SEO\Helpers\Post_Helper;
use Yoast\WP\SEO\Models\Indexable;
use Yoast\WP\SEO\Values\Indexables\Indexable_Builder_Versions;

/**
 * Author Builder for the indexables.
 *
 * Formats the author meta to indexable format.
 */
class Indexable_Author_Builder {

	use Indexable_Social_Image_Trait;

	/**
	 * The author archive helper.
	 *
	 * @var Author_Archive_Helper
	 */
	private $author_archive;

	/**
	 * The latest version of the Indexable_Author_Builder.
	 *
	 * @var int
	 */
	protected $version;

	/**
	 * Holds the taxonomy helper instance.
	 *
	 * @var Post_Helper
	 */
	protected $post_helper;

	/**
	 * The WPDB instance.
	 *
	 * @var wpdb
	 */
	protected $wpdb;

	/**
	 * Indexable_Author_Builder constructor.
	 *
	 * @param Author_Archive_Helper      $author_archive The author archive helper.
	 * @param Indexable_Builder_Versions $versions       The Indexable version manager.
	 * @param Post_Helper                $post_helper    The post helper.
	 * @param wpdb                       $wpdb           The WPDB instance.
	 */
	public function __construct(
		Author_Archive_Helper $author_archive,
		Indexable_Builder_Versions $versions,
		Post_Helper $post_helper,
		wpdb $wpdb
	) {
		$this->author_archive = $author_archive;
		$this->version        = $versions->get_latest_version_for_type( 'user' );
		$this->post_helper    = $post_helper;
		$this->wpdb           = $wpdb;
	}

	/**
	 * Formats the data.
	 *
	 * @param int       $user_id   The user to retrieve the indexable for.
	 * @param Indexable $indexable The indexable to format.
	 *
	 * @return Indexable The extended indexable.
	 *
	 * @throws Author_Not_Built_Exception When author is not built.
	 */
	public function build( $user_id, Indexable $indexable ) {
		$exception = $this->check_if_user_should_be_indexed( $user_id );
		if ( $exception ) {
			throw $exception;
		}

		$meta_data = $this->get_meta_data( $user_id );

		$indexable->object_id              = $user_id;
		$indexable->object_type            = 'user';
		$indexable->permalink              = \get_author_posts_url( $user_id );
		$indexable->title                  = $meta_data['wpseo_title'];
		$indexable->description            = $meta_data['wpseo_metadesc'];
		$indexable->is_cornerstone         = false;
		$indexable->is_robots_noindex      = ( $meta_data['wpseo_noindex_author'] === 'on' );
		$indexable->is_robots_nofollow     = null;
		$indexable->is_robots_noarchive    = null;
		$indexable->is_robots_noimageindex = null;
		$indexable->is_robots_nosnippet    = null;
		$indexable->is_public              = ( $indexable->is_robots_noindex ) ? false : null;
		$indexable->has_public_posts       = $this->author_archive->author_has_public_posts( $user_id );
		$indexable->blog_id                = \get_current_blog_id();

		$this->reset_social_images( $indexable );
		$this->handle_social_images( $indexable );

		$timestamps                      = $this->get_object_timestamps( $user_id );
		$indexable->object_published_at  = $timestamps->published_at;
		$indexable->object_last_modified = $timestamps->last_modified;

		$indexable->version = $this->version;

		return $indexable;
	}

	/**
	 * Retrieves the meta data for this indexable.
	 *
	 * @param int $user_id The user to retrieve the meta data for.
	 *
	 * @return array List of meta entries.
	 */
	protected function get_meta_data( $user_id ) {
		$keys = [
			'wpseo_title',
			'wpseo_metadesc',
			'wpseo_noindex_author',
		];

		$output = [];
		foreach ( $keys as $key ) {
			$output[ $key ] = $this->get_author_meta( $user_id, $key );
		}

		return $output;
	}

	/**
	 * Retrieves the author meta.
	 *
	 * @param int    $user_id The user to retrieve the indexable for.
	 * @param string $key     The meta entry to retrieve.
	 *
	 * @return string|null The value of the meta field.
	 */
	protected function get_author_meta( $user_id, $key ) {
		$value = \get_the_author_meta( $key, $user_id );
		if ( \is_string( $value ) && $value === '' ) {
			return null;
		}

		return $value;
	}

	/**
	 * Finds an alternative image for the social image.
	 *
	 * @param Indexable $indexable The indexable.
	 *
	 * @return array|bool False when not found, array with data when found.
	 */
	protected function find_alternative_image( Indexable $indexable ) {
		$gravatar_image = \get_avatar_url(
			$indexable->object_id,
			[
				'size'   => 500,
				'scheme' => 'https',
			]
		);
		if ( $gravatar_image ) {
			return [
				'image'  => $gravatar_image,
				'source' => 'gravatar-image',
			];
		}

		return false;
	}

	/**
	 * Returns the timestamps for a given author.
	 *
	 * @param int $author_id The author ID.
	 *
	 * @return object An object with last_modified and published_at timestamps.
	 */
	protected function get_object_timestamps( $author_id ) {
		$post_statuses = $this->post_helper->get_public_post_statuses();

		$sql = "
			SELECT MAX(p.post_modified_gmt) AS last_modified, MIN(p.post_date_gmt) AS published_at
			FROM {$this->wpdb->posts} AS p
			WHERE p.post_status IN (" . \implode( ', ', \array_fill( 0, \count( $post_statuses ), '%s' ) ) . ")
				AND p.post_password = ''
				AND p.post_author = %d
		";

		$replacements = \array_merge( $post_statuses, [ $author_id ] );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- We are using wpdb prepare.
		return $this->wpdb->get_row( $this->wpdb->prepare( $sql, $replacements ) );
	}

	/**
	 * Checks if the user should be indexed.
	 * Returns an exception with an appropriate message if not.
	 *
	 * @param string $user_id The user id.
	 *
	 * @return Author_Not_Built_Exception|null The exception if it should not be indexed, or `null` if it should.
	 */
	protected function check_if_user_should_be_indexed( $user_id ) {
		$exception = null;

		if ( $this->author_archive->are_disabled() ) {
			$exception = Author_Not_Built_Exception::author_archives_are_disabled( $user_id );
		}

		if ( $this->author_archive->author_has_public_posts( $user_id ) === false ) {
			$exception = Author_Not_Built_Exception::author_archives_are_not_indexed_for_users_without_posts( $user_id );
		}

		/**
		 * Filter: Include or exclude a user from being build and saved as an indexable.
		 * Return an `Author_Not_Built_Exception` when the indexable should not be build, with an appropriate message telling why it should not be built.
		 * Return `null` if the indexable should be build.
		 *
		 * @param Author_Not_Built_Exception|null $exception An exception if the indexable is not being built, `null` if the indexable should be built.
		 * @param string                          $user_id   The ID of the user that should or should not be excluded.
		 */
		return \apply_filters( 'wpseo_should_build_and_save_user_indexable', $exception, $user_id );
	}
}
