<?php

/**
 * Output Open Graph protocol for consumption by Facebook and other consuming agents
 *
 * @since 1.1
 * @link http://ogp.me/ Open Graph protocol
 */
class Facebook_Open_Graph_Protocol {
	/**
	 * Base IRI of Open Graph protocol RDFa properties
	 *
	 * @since 1.1
	 * @var string
	 */
	const OGP_NS = 'http://ogp.me/ns#';

	/**
	 * Base IRI of Facebook RDFa properties
	 *
	 * @since 1.1
	 * @var string
	 */
	const FB_NS = 'http://ogp.me/ns/fb#';

	/**
	 * Base IRI of Open Graph protocol article object global properties
	 *
	 * @since 1.1
	 * @var string
	 */
	const ARTICLE_NS = 'http://ogp.me/ns/article#';

	/**
	 * Base IRI of Open Graph protocol profile object global properties
	 *
	 * @since 1.1
	 * @var string
	 */
	const PROFILE_NS = 'http://ogp.me/ns/profile#';

	/**
	 * Recursively build RDFa <meta> elements used for Open Graph protocol
	 *
	 * @since 1.0
	 * @param string $property whitespace separated list of CURIEs placed in a property attribute
	 * @param mixed content attribute value for the given property. use an array for array property values or structured properties
	 */
	 public static function meta_elements( $property, $content ) {
		if ( empty( $property ) || empty( $content ) )
			return;

		// array of property values or structured property
		if ( is_array( $content ) ) {
			foreach( $content as $structured_property => $content_value ) {
				// handle numeric keys from regular arrays
				// account for the special structured property of url which is equivalent to the root tag and sets up the structure
				if ( ! is_string( $structured_property ) || $structured_property === 'url' )
					self::meta_elements( $property, $content_value );
				else
					self::meta_elements( $property . ':' . $structured_property, $content_value );
			}
		} else {
			echo "<meta property=\"$property\" content=\"" . esc_attr( $content ) . "\" />\n";
		}
	}

	/**
	 * Clean post description text in preparation for Open Graph protocol description or Facebook post caption|description
	 *
	 * @since 1.1
	 * @uses strip_shortcodes()
	 * @uses wp_trim_words()
	 * @param string $description description text
	 * @return string description text passed through WordPress Core description cleaners, possibly trimmed
	 */
	public static function clean_description( $description, $trimmed = true ) {
		$description = trim( $description );
		if ( ! $description )
			return '';

		// basic filters from wp_trim_excerpt() that should apply to both excerpt and content
		// note: the_content filter is so polluted with extra third-party stuff we purposely avoid to better represent page content
		$description = strip_shortcodes( $description ); // remove any shortcodes that may exist, such as an image macro
		$description = str_replace( ']]>', ']]&gt;', $description );
		$description = trim( $description );

		if ( ! $description )
			return '';

		if ( $trimmed ) {
			// use the built-in WordPress function for localized support of words vs. characters
			// pass in customizations based on WordPress with publisher overrides
			$description = wp_trim_words(
				$description,
				apply_filters( 'facebook_excerpt_length', apply_filters( 'excerpt_length', 55 ) ), // standard excerpt length, which may be customized by the publisher via the core filter, passed through another filter for our context
				apply_filters( 'facebook_excerpt_more', __( '&hellip;' ) ) // filter the wp_trim_words default for publisher customization
			);
		} else {
			$description = wp_strip_all_tags( $description );
		}

		if ( $description )
			return $description;

		return '';
	}

	/**
	 * Convert full IRI Open Graph protocol values to CURIE prefixed values
	 * Mapping CURIEs is presumed to occur in a parent element of the group of <meta>s 
	 *
	 * @since 1.1.6
	 * @link http://www.w3.org/TR/rdfa-syntax/#s_curies RDFa 1.1 Core CURIEs
	 * @param array $ogp associative array of full IRI key and OGP value
	 * @return array associative array of full IRI replaced with prefix where mapped and the same OGP value passed in
	 */
	public static function prefixed_properties( $ogp ) {
		global $facebook_loader;

		if ( ! is_array( $ogp ) || empty( $ogp ) )
			return array();

		// map the referenced used by this plugin as well as OGP globals
		$curies = apply_filters( 'facebook_rdfa_mappings', array(
			self::OGP_NS => array( 'prefix' => 'og' ),
			self::FB_NS => array( 'prefix' => 'fb' ),
			self::ARTICLE_NS => array( 'prefix' => 'article' ),
			self::PROFILE_NS => array( 'prefix' => 'profile' ),
			'http://ogp.me/ns/book#' => array( 'prefix' => 'book' ),
			'http://ogp.me/ns/music#' => array( 'prefix' => 'music' ),
			'http://ogp.me/ns/video#' => array( 'prefix' => 'video' )
		) );
		if ( isset( $facebook_loader->credentials['app_namespace'] ) ) {
			$app_ns_url = esc_url_raw( 'http://ogp.me/ns/fb/' . $facebook_loader->credentials['app_namespace'] . '#', array( 'http' ) );
			if ( $app_ns_url && ! isset( $curies[$app_ns_url] ) )
				$curies[$app_ns_url] = array( 'prefix' => $facebook_loader->credentials['app_namespace'] );
		}

		foreach ( $curies as $reference => $properties ) {
			if ( ! isset( $properties['prefix'] ) ) {
				unset( $curies[$reference] );
				continue;
			}
			$properties['key_length'] = strlen( $reference );
			$curies[$reference] = $properties;
		}

		$ogp_prefixed = array();

		foreach ( $ogp as $property => $value ) {
			$prefixed_property_set = false;
			foreach ( $curies as $reference => $properties ) {
				if ( substr_compare( $property, $reference, 0, $properties['key_length'] ) === 0 ) {
					$ogp_prefixed[ $properties['prefix'] . ':' . substr( $property , $properties['key_length'] ) ] = $value;
					$prefixed_property_set = true;
					break;
				}
			}

			// pass through unmapped values
			if ( ! $prefixed_property_set )
				$ogp_prefixed[$property] = $value;
		}

		return $ogp_prefixed;
	}

	/**
	 * Add Open Graph protocol markup to <head>
	 * We use full IRIs for consistent mapping between mapped CURIE prefixes defined in a parent element and self-contained properties using a full IRI
	 *
	 * @since 1.0
	 * @link http://www.w3.org/TR/rdfa-syntax/#s_curieprocessing RDFa Core 1.1 CURIE and IRI processing
	 */
	public static function add_og_protocol() {
		global $post, $facebook_loader;

		$meta_tags = array(
			self::OGP_NS . 'site_name' => get_bloginfo( 'name' ),
			self::OGP_NS . 'type' => 'website'
		);

		if ( isset( $facebook_loader ) ) {
			if ( isset( $facebook_loader->locale ) )
				$meta_tags[ self::OGP_NS . 'locale' ] = $facebook_loader->locale;
			if ( isset( $facebook_loader->credentials ) && isset( $facebook_loader->credentials['app_id'] ) && $facebook_loader->credentials['app_id'] )
				$meta_tags[ self::FB_NS . 'app_id' ] = $facebook_loader->credentials['app_id'];
		}

		if ( is_home() || is_front_page() ) {
			$meta_tags[ self::OGP_NS . 'title' ] = get_bloginfo( 'name' );
			$meta_tags[ self::OGP_NS . 'description' ] = get_bloginfo( 'description' );
			if ( is_front_page() )
				$meta_tags[ self::OGP_NS . 'url' ] = site_url();
			else
				$meta_tags[ self::OGP_NS . 'url' ] = home_url();
		} else if ( is_single() && empty( $post->post_password ) ) {
			setup_postdata( $post );
			$post_type = get_post_type();
			$meta_tags[ self::OGP_NS . 'type' ] = 'article';
			$meta_tags[ self::OGP_NS . 'url' ] = apply_filters( 'facebook_rel_canonical', get_permalink() );
			if ( post_type_supports( $post_type, 'title' ) )
				$meta_tags[ self::OGP_NS . 'title' ] = get_the_title();
			if ( post_type_supports( $post_type, 'excerpt' ) ) {
				$description = '';

				// did the publisher specify a custom excerpt? use it
				if ( ! empty( $post->post_excerpt ) )
					$description = apply_filters( 'get_the_excerpt', $post->post_excerpt );
				else
					$description = $post->post_content;

				$description = self::clean_description( $description );
				if ( $description )
					$meta_tags[ self::OGP_NS . 'description' ] = $description;
			}

			$meta_tags[ self::ARTICLE_NS . 'published_time' ] = date( 'c', strtotime( $post->post_date_gmt ) );
			$meta_tags[ self::ARTICLE_NS . 'modified_time' ] = date( 'c', strtotime( $post->post_modified_gmt ) );

			if ( post_type_supports( $post_type, 'author' ) && isset( $post->post_author ) )
				$meta_tags[ self::ARTICLE_NS . 'author' ] = get_author_posts_url( $post->post_author );

			// add the first category as a section. all other categories as tags
			$cat_ids = get_the_category();

			if ( ! empty( $cat_ids ) ) {
				$no_category = apply_filters( 'the_category', __( 'Uncategorized' ) );

				$cat = get_category( $cat_ids[0] );

				if ( ! empty( $cat ) && isset( $cat->name ) && $cat->name !== $no_category )
					$meta_tags[ self::ARTICLE_NS . 'section' ] = $cat->name;

				//output the rest of the categories as tags
				unset( $cat_ids[0] );

				if ( ! empty( $cat_ids ) ) {
					$meta_tags[ self::ARTICLE_NS . 'tag' ] = array();
					foreach( $cat_ids as $cat_id ) {
						$cat = get_category( $cat_id );
						if ( isset( $cat->name ) && $cat->name !== $no_category )
							$meta_tags[ self::ARTICLE_NS . 'tag' ][] = $cat->name;
						unset( $cat );
					}
				}
			}

			// add tags. treat tags as lower priority than multiple categories
			$tags = get_the_tags();

			if ( $tags ) {
				if ( ! array_key_exists( self::ARTICLE_NS . 'tag', $meta_tags ) )
					$meta_tags[ self::ARTICLE_NS . 'tag' ] = array();

				foreach ( $tags as $tag ) {
					$meta_tags[ self::ARTICLE_NS . 'tag' ][] = $tag->name;
				}
			}

			// does current post type and the current theme support post thumbnails?
			if ( post_type_supports( $post_type, 'thumbnail' ) && function_exists( 'has_post_thumbnail' ) && has_post_thumbnail() ) {
				list( $post_thumbnail_url, $post_thumbnail_width, $post_thumbnail_height ) = wp_get_attachment_image_src( get_post_thumbnail_id( $post->ID ), 'full' );

				if ( ! empty( $post_thumbnail_url ) ) {
					$image = array( 'url' => $post_thumbnail_url );

					if ( ! empty( $post_thumbnail_width ) )
						$image['width'] = absint( $post_thumbnail_width );

					if ( ! empty($post_thumbnail_height) )
						$image['height'] = absint( $post_thumbnail_height );

					$meta_tags[ self::OGP_NS . 'image' ] = array( $image );
				}
			}
		} else if ( is_author() ) {
			$author = get_queried_object();
			if ( ! ( $author && isset( $author->ID ) ) )
				break;

			$author_id = $author->ID;

			$meta_tags[ self::OGP_NS . 'type' ] = 'profile';
			$meta_tags[ self::OGP_NS . 'title' ] = get_the_author_meta( 'display_name', $author_id );
			$meta_tags[ self::OGP_NS . 'url' ] = get_author_posts_url( get_the_author_meta( 'ID', $author_id ) );
			$meta_tags[ self::PROFILE_NS . 'first_name' ] = get_the_author_meta( 'first_name', $author_id );
			$meta_tags[ self::PROFILE_NS . 'last_name'] = get_the_author_meta( 'last_name', $author_id );

			$description = self::clean_description( get_the_author_meta( 'description', $author_id ) );
			if ( $description )
				$meta_tags[ self::OGP_NS . 'description'] = $description;

			// no need to show username if there is only one
			if ( is_multi_author() )
				$meta_tags[ self::PROFILE_NS . 'username' ] = get_the_author_meta( 'login', $author_id );
		} else if ( is_page() ) {
			$meta_tags[ self::OGP_NS . 'type' ] = 'article';
			$meta_tags[ self::OGP_NS . 'title' ] = get_the_title();
			$meta_tags[ self::OGP_NS . 'url' ] = apply_filters( 'facebook_rel_canonical', get_permalink() );
		}

		$meta_tags = apply_filters( 'fb_meta_tags', $meta_tags, $post );

		// default: true while Facebook crawler corrects its indexing of full IRI values
		if ( apply_filters( 'facebook_ogp_prefixed', true ) )
			$meta_tags = self::prefixed_properties( $meta_tags );

		foreach ( $meta_tags as $property => $content ) {
			self::meta_elements( $property, $content );
		}
	}
}
?>
