<?php

namespace Timber;

use Timber\Core;
use Timber\CoreInterface;

use Timber\Term;
use Timber\User;
use Timber\Image;
use Timber\Helper;
use Timber\URLHelper;
use Timber\PostGetter;
use Timber\PostType;

use WP_Post;

/**
 * This is the object you use to access or extend WordPress posts. Think of it as Timber's (more accessible) version of WP_Post. This is used throughout Timber to represent posts retrieved from WordPress making them available to Twig templates. See the PHP and Twig examples for an example of what it's like to work with this object in your code.
 * @example
 * ```php
 * // single.php, see connected twig example
 * $context = Timber::get_context();
 * $context['post'] = new Timber\Post(); // It's a new Timber\Post object, but an existing post from WordPress.
 * Timber::render('single.twig', $context);
 * ?>
 * ```
 * ```twig
 * {# single.twig #}
 * <article>
 *     <h1 class="headline">{{post.title}}</h1>
 *     <div class="body">
 *         {{post.content}}
 *     </div>
 * </article>
 * ```
 *
 * ```html
 * <article>
 *     <h1 class="headline">The Empire Strikes Back</h1>
 *     <div class="body">
 *         It is a dark time for the Rebellion. Although the Death Star has been destroyed, Imperial troops have driven the Rebel forces from their hidden base and pursued them across the galaxy.
 *     </div>
 * </article>
 * ```
 *
 * @package Timber
 */
class Post extends Core implements CoreInterface {

	/**
	 * @var string $ImageClass the name of the class to handle images by default
	 */
	public $ImageClass = 'Timber\Image';

	/**
	 * @var string $PostClass the name of the class to handle posts by default
	 */
	public $PostClass = 'Timber\Post';

	/**
	 * @var string $TermClass the name of the class to handle terms by default
	 */
	public $TermClass = 'Timber\Term';

	/**
	 * @var string $object_type what does this class represent in WordPress terms?
	 */
	public $object_type = 'post';

	/**
	 * @var string $representation what does this class represent in WordPress terms?
	 */
	public static $representation = 'post';

	/**
	 * @internal
	 * @var string $_content stores the processed content internally
	 */
	protected $_content;

	/**
	 * @var string $_permalink the returned permalink from WP's get_permalink function
	 */
	protected $_permalink;

	/**
	 * @var array $_next stores the results of the next Timber\Post in a set inside an array (in order to manage by-taxonomy)
	 */
	protected $_next = array();

	/**
	 * @var array $_prev stores the results of the previous Timber\Post in a set inside an array (in order to manage by-taxonomy)
	 */
	protected $_prev = array();

	/**
	 * @api
	 * @var string $class stores the CSS classes for the post (ex: "post post-type-book post-123")
	 */
	public $class;

	/**
	 * @api
	 * @var string $id the numeric WordPress id of a post
	 */
	public $id;

	/**
	 * @var string 	$ID 			the numeric WordPress id of a post, capitalized to match WP usage
	 */
	public $ID;

	/**
	 * @var int 	$post_author 	the numeric ID of the a post's author corresponding to the wp_user dtable
	 */
	public $post_author;

	/**
	 * @var string 	$post_content 	the raw text of a WP post as stored in the database
	 */
	public $post_content;

	/**
	 * @var string 	$post_date 		the raw date string as stored in the WP database, ex: 2014-07-05 18:01:39
	 */
	public $post_date;

	/**
	 * @var string 	$post_excerpt 	the raw text of a manual post excerpt as stored in the database
	 */
	public $post_excerpt;

	/**
	 * @var int 		$post_parent 	the numeric ID of a post's parent post
	 */
	public $post_parent;

	/**
	 * @api
	 * @var string 		$post_status 	the status of a post ("draft", "publish", etc.)
	 */
	public $post_status;

	/**
	 * @var string 	$post_title 	the raw text of a post's title as stored in the database
	 */
	public $post_title;

	/**
	 * @api
	 * @var string 	$post_type 		the name of the post type, this is the machine name (so "my_custom_post_type" as opposed to "My Custom Post Type")
	 */
	public $post_type;

	/**
	 * @api
	 * @var string 	$slug 		the URL-safe slug, this corresponds to the poorly-named "post_name" in the WP database, ex: "hello-world"
	 */
	public $slug;

	/**
	 * @var PostType $_type stores the PostType object for the Post
	 */
	protected $__type;

	/**
	 * If you send the constructor nothing it will try to figure out the current post id based on being inside The_Loop
	 * @example
	 * ```php
	 * $post = new Timber\Post();
	 * $other_post = new Timber\Post($random_post_id);
	 * ```
	 * @param mixed $pid
	 */
	public function __construct( $pid = null ) {
		$pid = $this->determine_id($pid);
		$this->init($pid);
	}

	/**
	 * tries to figure out what post you want to get if not explictly defined (or if it is, allows it to be passed through)
	 * @internal
	 * @param mixed a value to test against
	 * @return int the numberic id we should be using for this post object
	 */
	protected function determine_id( $pid ) {
		global $wp_query;
		if ( $pid === null &&
			isset($wp_query->queried_object_id)
			&& $wp_query->queried_object_id
			&& isset($wp_query->queried_object)
			&& is_object($wp_query->queried_object)
			&& get_class($wp_query->queried_object) == 'WP_Post'
			) {
				if ( isset($_GET['preview']) && isset($_GET['preview_nonce']) && wp_verify_nonce($_GET['preview_nonce'], 'post_preview_'.$wp_query->queried_object_id) ) {
					$pid = $this->get_post_preview_id($wp_query);
				} else if ( !$pid ) {
					$pid = $wp_query->queried_object_id;
				}
		} else if ( $pid === null && $wp_query->is_home && isset($wp_query->queried_object_id) && $wp_query->queried_object_id ) {
			//hack for static page as home page
			$pid = $wp_query->queried_object_id;
		} else if ( $pid === null ) {
			$gtid = false;
			$maybe_post = get_post();
			if ( isset($maybe_post->ID) ) {
				$gtid = true;
			}
			if ( $gtid ) {
				$pid = get_the_ID();
			}
			if ( !$pid ) {
				global $wp_query;
				if ( isset($wp_query->query['p']) ) {
					$pid = $wp_query->query['p'];
				}
			}
		}
		if ( $pid === null && ($pid_from_loop = PostGetter::loop_to_id()) ) {
			$pid = $pid_from_loop;
		}
		return $pid;
	}

	/**
	 * Outputs the title of the post if you do something like `<h1>{{post}}</h1>`
	 * @return string
	 */
	public function __toString() {
		return $this->title();
	}

	protected function get_post_preview_id( $query ) {
		$can = array(
	 		'edit_'.$query->queried_object->post_type.'s',
	 	);

	 	if ( $query->queried_object->author_id !== get_current_user_id() ) {
	 		$can[] = 'edit_others_'.$query->queried_object->post_type.'s';
	 	}

	 	$can_preview = array();

		foreach ( $can as $type ) {
			 if ( current_user_can($type) ) {
				$can_preview[] = true;
			 }
		}

		if ( count($can_preview) !== count($can) ) {
			 return;
		}

		$revisions = wp_get_post_revisions($query->queried_object_id);

		if ( !empty($revisions) ) {
			$revision = reset($revisions);
			return $revision->ID;
		}

		return false;
	}

	/**
	 * Initializes a Post
	 * @internal
	 * @param integer $pid
	 */
	protected function init( $pid = false ) {
		if ( $pid === false ) {
			$pid = get_the_ID();
		}
		if ( is_numeric($pid) ) {
			$this->ID = $pid;
		}
		$post_info = $this->get_info($pid);
		$this->import($post_info);
		//cant have a function, so gots to do it this way
		$post_class = $this->post_class();
		$this->class = $post_class;
	}

	/**
	 * Get the URL that will edit the current post/object
	 * @internal
	 * @deprecated since 1.0
	 * @codeCoverageIgnore
	 * @see Timber\Post::edit_link
	 * @return bool|string
	 */
	public function get_edit_url() {
		return $this->edit_link();
	}

	/**
	 * updates the post_meta of the current object with the given value
	 * @param string $field
	 * @param mixed $value
	 */
	public function update( $field, $value ) {
		if ( isset($this->ID) ) {
			update_post_meta($this->ID, $field, $value);
			$this->$field = $value;
		}
	}


	/**
	 * takes a mix of integer (post ID), string (post slug),
	 * or object to return a WordPress post object from WP's built-in get_post() function
	 * @internal
	 * @param integer $pid
	 * @return WP_Post on success
	 */
	protected function prepare_post_info( $pid = 0 ) {
		if ( is_string($pid) || is_numeric($pid) || (is_object($pid) && !isset($pid->post_title)) || $pid === 0 ) {
			$pid = self::check_post_id($pid);
			$post = get_post($pid);
			if ( $post ) {
				return $post;
			}
		}
		//we can skip if already is WP_Post
		return $pid;
	}


	/**
	 * helps you find the post id regardless of whether you send a string or whatever
	 * @param integer $pid ;
	 * @internal
	 * @return integer ID number of a post
	 */
	protected function check_post_id( $pid ) {
		if ( is_numeric($pid) && $pid === 0 ) {
			$pid = get_the_ID();
			return $pid;
		}
		if ( !is_numeric($pid) && is_string($pid) ) {
			$pid = self::get_post_id_by_name($pid);
			return $pid;
		}
		if ( !$pid ) {
			return null;
		}
		return $pid;
	}


	/**
	 * get_post_id_by_name($post_name)
	 * @internal
	 * @param string $post_name
	 * @return int
	 */
	public static function get_post_id_by_name( $post_name ) {
		global $wpdb;
		$query = $wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_name = %s LIMIT 1", $post_name);
		$result = $wpdb->get_row($query);
		if ( !$result ) {
			return null;
		}
		return $result->ID;
	}

	/**
	 * @return PostPreview
	 */
	public function preview() {
		return new PostPreview($this);
	}

	/**
	 * get a preview of your post, if you have an excerpt it will use that,
	 * otherwise it will pull from the post_content.
	 * If there's a <!-- more --> tag it will use that to mark where to pull through.
	 * @api
	 * @example
	 * ```twig
	 * <p>{{post.get_preview(50)}}</p>
	 * ```
	 * @param int $len The number of words that WP should use to make the tease. (Isn't this better than [this mess](http://wordpress.org/support/topic/changing-the-default-length-of-the_excerpt-1?replies=14)?). If you've set a post_excerpt on a post, we'll use that for the preview text; otherwise the first X words of the post_content
	 * @param bool $force What happens if your custom post excerpt is longer then the length requested? By default (`$force = false`) it will use the full `post_excerpt`. However, you can set this to true to *force* your excerpt to be of the desired length
	 * @param string $readmore The text you want to use on the 'readmore' link
	 * @param bool|string $strip true for default, false for none, string for list of custom attributes
	 * @param string $end The text to end the preview with (defaults to ...)
	 * @return string of the post preview
	 */
	public function get_preview( $len = 50, $force = false, $readmore = 'Read More', $strip = true, $end = '&hellip;' ) {
		$text = '';
		$link = '';
		$trimmed = false;
		$last_p_tag = null;
		if ( isset($this->post_excerpt) && strlen(trim($this->post_excerpt)) ) {
			if ( $force ) {
				$text = Helper::trim_words($this->post_excerpt, $len, false);
				$trimmed = true;
			} else {
				$text = $this->post_excerpt;
			}
		}
		if ( !strlen($text) && preg_match('/<!--\s?more(.*?)?-->/', $this->post_content, $readmore_matches) ) {
			$pieces = explode($readmore_matches[0], $this->post_content);
			$text = $pieces[0];
			if ( $force ) {
				$text = Helper::trim_words($text, $len, false);
				$trimmed = true;
			}
			$text = do_shortcode($text);
		}
		if ( !strlen($text) ) {
			$text = Helper::trim_words($this->get_content(), $len, false);
			$trimmed = true;
		}
		if ( !strlen(trim($text)) ) {
			return trim($text);
		}
		if ( $strip ) {
			$allowable_tags = (is_string($strip)) ? $strip : null;
			$text = trim(strip_tags($text, $allowable_tags));
		}
		if ( strlen($text) ) {
			$text = trim($text);
			$last = $text[strlen($text) - 1];
			if ( $last != '.' && $trimmed ) {
				$text .= $end;
			}
			if ( !$strip ) {
				$last_p_tag = strrpos($text, '</p>');
				if ( $last_p_tag !== false ) {
					$text = substr($text, 0, $last_p_tag);
				}
				if ( $last != '.' && $trimmed ) {
					$text .= $end.' ';
				}
			}
			$read_more_class = apply_filters('timber/post/get_preview/read_more_class', "read-more");
			if ( $readmore && isset($readmore_matches) && !empty($readmore_matches[1]) ) {
				$link = ' <a href="'.$this->link().'" class="'.$read_more_class.'">'.trim($readmore_matches[1]).'</a>';
			} elseif ( $readmore ) {
				$link = ' <a href="'.$this->link().'" class="'.$read_more_class.'">'.trim($readmore).'</a>';
			}
			$text .= apply_filters('timber/post/get_preview/read_more_link', $link);
			if ( !$strip && $last_p_tag && (strpos($text, '<p>') || strpos($text, '<p ')) ) {
				$text .= '</p>';
			}
		}
		return trim($text);
	}

	/**
	 * gets the post custom and attaches it to the current object
	 * @internal
	 * @param integer $pid a post ID number
	 */
	public function import_custom( $pid ) {
		$customs = $this->get_post_custom($pid);
		$this->import($customs);
	}

	/**
	 * Used internally to fetch the metadata fields (wp_postmeta table)
	 * and attach them to our TimberPost object
	 * @internal
	 * @param int $pid
	 * @return array
	 */
	protected function get_post_custom( $pid ) {
		apply_filters('timber_post_get_meta_pre', array(), $pid, $this);
		$customs = get_post_custom($pid);
		if ( !is_array($customs) || empty($customs) ) {
			return array();
		}
		foreach ( $customs as $key => $value ) {
			if ( is_array($value) && count($value) == 1 && isset($value[0]) ) {
				$value = $value[0];
			}
			$customs[$key] = maybe_unserialize($value);
		}
		$customs = apply_filters('timber_post_get_meta', $customs, $pid, $this);
		return $customs;
	}

	/**
	 * @param int $i
	 * @return string
	 */
	protected static function get_wp_link_page( $i ) {
		$link = _wp_link_page($i);
		$link = new \SimpleXMLElement($link.'</a>');
		if ( isset($link['href']) ) {
			return $link['href'];
		}
		return '';
	}

	/**
	 * Used internally by init, etc. to build TimberPost object
	 * @internal
	 * @param  int $pid
	 * @return null|object|WP_Post
	 */
	protected function get_info( $pid ) {
		$post = $this->prepare_post_info($pid);
		if ( !isset($post->post_status) ) {
			return null;
		}

		do_action_ref_array('the_post', array(&$post, &$GLOBALS['wp_query']));

		$post->status = $post->post_status;
		$post->id = $post->ID;
		$post->slug = $post->post_name;
		$customs = $this->get_post_custom($post->ID);
		$post->custom = $customs;
		$post = (object) array_merge((array) $customs, (array) $post);
		return $post;
	}

	/**
	 *
	 * Gets the comment form for use on a single article page
	 * @param array   $args this $args thing is a fucking mess, [fix at some point](http://codex.wordpress.org/Function_Reference/comment_form)
	 * @return string of HTML for the form
	 */
	public function comment_form( $args = array() ) {
		return Helper::get_comment_form($this->ID, $args);
	}


	/**
	 * Get the terms associated with the post
	 * This goes across all taxonomies by default
	 * @api
	 * @param string|array $tax What taxonom(y|ies) to pull from. Defaults to all registered taxonomies for the post type. You can use custom ones, or built-in WordPress taxonomies (category, tag). Timber plays nice and figures out that tag/tags/post_tag are all the same (and categories/category), for custom taxonomies you're on your own.
	 * @param bool $merge Should the resulting array be one big one (true)? Or should it be an array of sub-arrays for each taxonomy (false)?
	 * @return array
	 */
	public function terms( $tax = '', $merge = true, $TermClass = '' ) {
		$taxonomies = array();
		$TermClass = $TermClass ?: $this->TermClass;

		if ( is_string($merge) && class_exists($merge) ) {
			$TermClass = $merge;
		}
		if ( is_array($tax) ) {
			$taxonomies = $tax;
		}
		if ( is_string($tax) ) {
			if ( in_array($tax, array('all', 'any', '')) ) {
				$taxonomies = get_object_taxonomies($this->post_type);
			} else {
				$taxonomies = array($tax);
			}
		}

		$term_class_objects = array();

		foreach ( $taxonomies as $taxonomy ) {
			if ( in_array($taxonomy, array('tag', 'tags')) ) {
				$taxonomy = 'post_tag';
			}
			if ( $taxonomy == 'categories' ) {
				$taxonomy = 'category';
			}

			$terms = wp_get_post_terms($this->ID, $taxonomy);

			if ( is_wp_error($terms) ) {
				/* @var $terms WP_Error */
				Helper::error_log("Error retrieving terms for taxonomy '$taxonomy' on a post in timber-post.php");
				Helper::error_log('tax = '.print_r($tax, true));
				Helper::error_log('WP_Error: '.$terms->get_error_message());

				return $term_class_objects;
			}

			// map over array of wordpress terms, and transform them into instances of the TermClass
			$terms = array_map(function( $term ) use ($TermClass, $taxonomy) {
				return call_user_func(array($TermClass, 'from'), $term->term_id, $taxonomy);
			}, $terms);

			if ( $merge && is_array($terms) ) {
				$term_class_objects = array_merge($term_class_objects, $terms);
			} else if ( count($terms) ) {
				$term_class_objects[$taxonomy] = $terms;
			}
		}
		return $term_class_objects;
	}

	/**
	 * @param string|int $term_name_or_id
	 * @param string $taxonomy
	 * @return bool
	 */
	public function has_term( $term_name_or_id, $taxonomy = 'all' ) {
		if ( $taxonomy == 'all' || $taxonomy == 'any' ) {
			$taxes = get_object_taxonomies($this->post_type, 'names');
			$ret = false;
			foreach ( $taxes as $tax ) {
				if ( has_term($term_name_or_id, $tax, $this->ID) ) {
					$ret = true;
					break;
				}
			}
			return $ret;
		}
		return has_term($term_name_or_id, $taxonomy, $this->ID);
	}

	/**
	 * @return string
	 */
	public function get_paged_content() {
		return $this->paged_content();
	}


	/**
	 * Returns the post_type object with labels and other info
	 *
	 * @deprecated since 1.0.4
	 * @example
	 *
	 * ```twig
	 * This post is from <span>{{ post.get_post_type.labels.plural }}</span>
	 * ```
	 *
	 * ```html
	 * This post is from <span>Recipes</span>
	 * ```
	 * @return PostType
	 */
	public function get_post_type() {
		return $this->type();
	}


	/**
	 * @return int the number of comments on a post
	 */
	public function get_comment_count() {
		return get_comments_number($this->ID);
	}


	/**
	 * @param string $field_name
	 * @return boolean
	 */
	public function has_field( $field_name ) {
		return (!$this->get_field($field_name)) ? false : true;
	}


	/**
	 * @param string $field_name
	 * @return mixed
	 */
	public function get_field( $field_name ) {
		$value = apply_filters('timber_post_get_meta_field_pre', null, $this->ID, $field_name, $this);
		if ( $value === null ) {
			$value = get_post_meta($this->ID, $field_name);
			if ( is_array($value) && count($value) == 1 ) {
				$value = $value[0];
			}
			if ( is_array($value) && count($value) == 0 ) {
				$value = null;
			}
		}
		$value = apply_filters('timber_post_get_meta_field', $value, $this->ID, $field_name, $this);
		$value = $this->convert($value, __CLASS__);
		return $value;
	}

	/**
	 * @param string $field_name
	 */
	public function import_field( $field_name ) {
		$this->$field_name = $this->get_field($field_name);
	}

	/**
	 * Get the CSS classes for a post. For usage you should use `{{post.class}}` instead of `{{post.post_class}}`
	 * @internal
	 * @param string $class additional classes you want to add
	 * @see Timber\Post::$class
	 * @example
	 * ```twig
	 * <article class="{{ post.class }}">
	 *    {# Some stuff here #}
	 * </article>
	 * ```
	 *
	 * ```html
	 * <article class="post-2612 post type-post status-publish format-standard has-post-thumbnail hentry category-data tag-charleston-church-shooting tag-dylann-roof tag-gun-violence tag-hate-crimes tag-national-incident-based-reporting-system">
	 *    {# Some stuff here #}
	 * </article>
	 * ```
	 * @return string a space-seperated list of classes
	 */
	public function post_class( $class = '' ) {
		global $post;
		$old_global_post = $post;
		$post = $this;
		$class_array = get_post_class($class, $this->ID);
		$post = $old_global_post;
		if ( is_array($class_array) ) {
			return implode(' ', $class_array);
		}
		return $class_array;
	}

	// Docs

	/**
	 * @return array
	 * @codeCoverageIgnore
	 */
	public function get_method_values() {
		$ret = parent::get_method_values();
		$ret['author'] = $this->author();
		$ret['categories'] = $this->categories();
		$ret['category'] = $this->category();
		$ret['children'] = $this->children();
		$ret['comments'] = $this->comments();
		$ret['content'] = $this->content();
		$ret['edit_link'] = $this->edit_link();
		$ret['format'] = $this->format();
		$ret['link'] = $this->link();
		$ret['next'] = $this->next();
		$ret['pagination'] = $this->pagination();
		$ret['parent'] = $this->parent();
		$ret['path'] = $this->path();
		$ret['prev'] = $this->prev();
		$ret['terms'] = $this->terms();
		$ret['tags'] = $this->tags();
		$ret['thumbnail'] = $this->thumbnail();
		$ret['title'] = $this->title();
		return $ret;
	}

	/**
	 * Return the author of a post
	 * @api
	 * @example
	 * ```twig
	 * <h1>{{post.title}}</h1>
	 * <p class="byline">
	 *     <a href="{{post.author.link}}">{{post.author.name}}</a>
	 * </p>
	 * ```
	 * @return User|null A User object if found, false if not
	 */
	public function author() {
		if ( isset($this->post_author) ) {
			return new User($this->post_author);
		}
	}

	public function authors() {
		return apply_filters('timber/post/authors', array($this->author()), $this);
	}

	/**
	 * Get the author (WordPress user) who last modified the post
	 * @example
	 * ```twig
	 * Last updated by {{ post.modified_author.name }}
	 * ```
	 * ```html
	 * Last updated by Harper Lee
	 * ```
	 * @return User|null A User object if found, false if not
	 */
	public function modified_author() {
		$user_id = get_post_meta($this->ID, '_edit_last', true);
		return ($user_id ? new User($user_id) : $this->author());
	}

	/**
	 * Get the categoires on a particular post
	 * @api
	 * @return array of TimberTerms
	 */
	public function categories() {
		return $this->get_terms('category');
	}

	/**
	 * Returns a category attached to a post
	 * @api
	 * If mulitpuile categories are set, it will return just the first one
	 * @return TimberTerm|null
	 */
	public function category() {
		return $this->get_category();
	}

	/**
	 * Returns an array of children on the post as Timber\Posts
	 * (or other claass as you define).
	 * @api
	 * @example
	 * ```twig
	 * {% if post.children %}
	 *     Here are the child pages:
	 *     {% for child in page.children %}
	 *         <a href="{{ child.link }}">{{ child.title }}</a>
	 *     {% endfor %}
	 * {% endif %}
	 * ```
	 * @param string $post_type _optional_ use to find children of a particular post type (attachment vs. page for example). You might want to restrict to certain types of children in case other stuff gets all mucked in there. You can use 'parent' to use the parent's post type
	 * @param string|bool $childPostClass _optional_ a custom post class (ex: 'MyTimber\Post') to return the objects as. By default (false) it will use Timber\Post::$post_class value.
	 * @return array
	 */
	public function children( $post_type = 'any', $childPostClass = false ) {
		if ( $childPostClass === false ) {
			$childPostClass = $this->PostClass;
		}
		if ( $post_type == 'parent' ) {
			$post_type = $this->post_type;
		}
		$children = get_children('post_parent='.$this->ID.'&post_type='.$post_type.'&numberposts=-1&orderby=menu_order title&order=ASC&post_status=publish');
		foreach ( $children as &$child ) {
			$child = new $childPostClass($child->ID);
		}
		$children = array_values($children);
		return $children;
	}

	/**
	 * Gets the comments on a Timber\Post and returns them as an array of [TimberComments](#TimberComment) (or whatever comment class you set).
	 * @api
	 * @param int $count Set the number of comments you want to get. `0` is analogous to "all"
	 * @param string $order use ordering set in WordPress admin, or a different scheme
	 * @param string $type For when other plugins use the comments table for their own special purposes, might be set to 'liveblog' or other depending on what's stored in yr comments table
	 * @param string $status Could be 'pending', etc.
	 * @param string $CommentClass What class to use when returning Comment objects. As you become a Timber pro, you might find yourself extending TimberComment for your site or app (obviously, totally optional)
	 * @example
	 * ```twig
	 * {# single.twig #}
	 * <h4>Comments:</h4>
	 * {% for comment in post.comments %}
	 * 	<div class="comment-{{comment.ID}} comment-order-{{loop.index}}">
	 * 		<p>{{comment.author.name}} said:</p>
	 * 		<p>{{comment.content}}</p>
	 * 	</div>
	 * {% endfor %}
	 * ```
	 * @return bool|array
	 */
	public function comments( $count = 0, $order = 'wp', $type = 'comment', $status = 'approve', $CommentClass = 'Timber\Comment' ) {
		global $overridden_cpage, $user_ID;
		$overridden_cpage = false;

		$commenter = wp_get_current_commenter();
		$comment_author_email = $commenter['comment_author_email'];

		$args = array('post_id' => $this->ID, 'status' => $status, 'order' => $order);
		if ( $count > 0 ) {
			$args['number'] = $count;
		}
		if ( strtolower($order) == 'wp' || strtolower($order) == 'wordpress' ) {
			$args['order'] = get_option('comment_order');
		}

		if ( $user_ID ) {
			$args['include_unapproved'] = array($user_ID);
		} elseif ( !empty($comment_author_email) ) {
			$args['include_unapproved'] = array($comment_author_email);
		}

		$comments = get_comments($args);
		$timber_comments = array();

		if ( '' == get_query_var('cpage') && get_option('page_comments') ) {
			set_query_var('cpage', 'newest' == get_option('default_comments_page') ? get_comment_pages_count() : 1);
			$overridden_cpage = true;
		}

		foreach ( $comments as $key => &$comment ) {
			$timber_comment = new $CommentClass($comment);
			$timber_comments[$timber_comment->id] = $timber_comment;
		}

		// Build a flattened (depth=1) comment tree
		$comments_tree = array();
		foreach ( $timber_comments as $key => $comment ) {
			if ( !$comment->is_child() ) {
				continue;
			}

			$tree_element = $comment;
			do {
				$tree_element = $timber_comments[$tree_element->comment_parent];
			} while ( $tree_element->is_child() );

			$comments_tree[$tree_element->id][] = $comment->id;
		}

		// Add child comments to the relative "super parents"
		foreach ( $comments_tree as $comment_parent => $comment_children ) {
			foreach ( $comment_children as $comment_child ) {
				$timber_comments[$comment_parent]->add_child($timber_comments[$comment_child]);
				unset($timber_comments[$comment_child]);
			}
		}

		$timber_comments = array_values($timber_comments);

		return $timber_comments;
	}

	/**
	 * If the Password form is to be shown, show it!
	 * @return string|void
	 */
	protected function maybe_show_password_form(){
		if ( $this->password_required() ) {
			$show_pw = false;
			$show_pw = apply_filters('timber/post/content/show_password_form_for_protected', $show_pw);
			if ($show_pw) {
				return apply_filters('timber/post/content/password_form', get_the_password_form($this->ID), $this);
			}
		}
	}

	/**
	 * Gets the actual content of a WP Post, as opposed to post_content this will run the hooks/filters attached to the_content. \This guy will return your posts content with WordPress filters run on it (like for shortcodes and wpautop).
	 * @api
	 * @example
	 * ```twig
	 * <div class="article">
	 *     <h2>{{post.title}}</h2>
	 *     <div class="content">{{ post.content }}</div>
	 * </div>
	 * ```
	 * @param int $page
	 * @return string
	 */
	public function content( $page = 0, $len = -1 ) {
		if ( $form = $this->maybe_show_password_form() ) {
			return $form;
		}
		if ( $len == -1 && $page == 0 && $this->_content ) {
			return $this->_content;
		}
		$content = $this->post_content;
		if ( $len > 0 ) {
			$content = wp_trim_words($content, $len);
		}
		if ( $page ) {
			$contents = explode('<!--nextpage-->', $content);
			$page--;
			if ( count($contents) > $page ) {
				$content = $contents[$page];
			}
		}
		$content = apply_filters('the_content', ($content));
		if ( $len == -1 && $page == 0 ) {
			$this->_content = $content;
		}
		return $content;
	}

	/**
	 * @return string
	 */
	public function paged_content() {
		global $page;
		return $this->content($page, -1);
	}

	/**
	 * Get the date to use in your template!
	 * @api
	 * @example
	 * ```twig
	 * Published on {{ post.date }} // Uses WP's formatting set in Admin
	 * OR
	 * Published on {{ post.date | date('F jS') }} // Jan 12th
	 * ```
	 *
	 * ```html
	 * Published on January 12, 2015
	 * OR
	 * Published on Jan 12th
	 * ```
	 * @param string $date_format
	 * @return string
	 */
	public function date( $date_format = '' ) {
		$df = $date_format ? $date_format : get_option('date_format');
		$the_date = (string) mysql2date($df, $this->post_date);
		return apply_filters('get_the_date', $the_date, $df);
	}

	/**
	 * Get the time to use in your template
	 * @api
	 * @example
	 * ```twig
	 * Published at {{ post.time }} // Uses WP's formatting set in Admin
	 * OR
	 * Published at {{ post.time | time('G:i') }} // 13:25
	 * ```
	 *
	 * ```html
	 * Published at 1:25 pm
	 * OR
	 * Published at 13:25
	 * ```
	 * @param string $time_format
	 * @return string
	 */
	public function time( $time_format = '' ) {
		$tf = $time_format ? $time_format : get_option('time_format');
	 	$the_time = (string) mysql2date($tf, $this->post_date);
	 	return apply_filters('get_the_time', $the_time, $tf);
	}


	/**
	 * Returns the post_type object with labels and other info
	 *
	 * @since 1.0.4
	 * @example
	 *
	 * ```twig
	 * This post is from <span>{{ post.type.labels.name }}</span>
	 * ```
	 *
	 * ```html
	 * This post is from <span>Recipes</span>
	 * ```
	 * @return PostType
	 */
	public function type() {
		if ( isset($this->custom['type']) ) {
			return $this->custom['type'];
		}
		if ( !$this->__type instanceof PostType ) {
			$this->__type = new PostType($this->post_type);
		}
		return $this->__type;
	}

	/**
	 * Returns the edit URL of a post if the user has access to it
	 * @return bool|string the edit URL of a post in the WordPress admin
	 */
	public function edit_link() {
		if ( $this->can_edit() ) {
			return get_edit_post_link($this->ID);
		}
	}

	/**
	 * @api
	 * @return mixed
	 */
	public function format() {
		return get_post_format($this->ID);
	}
	
	/**
	 * whether post requires password and correct password has been provided
	 * @api
	 * @return boolean
	 */
	public function password_required() {
		return post_password_required($this->ID);
	}

	/**
	 * get the permalink for a post object
	 * @api
	 * @example
	 * ```twig
	 * <a href="{{post.link}}">Read my post</a>
	 * ```
	 * @return string ex: http://example.org/2015/07/my-awesome-post
	 */
	public function link() {
		if ( isset($this->_permalink) ) {
			return $this->_permalink;
		}
		$this->_permalink = get_permalink($this->ID);
		return $this->_permalink;
	}

	/**
	 * @param string $field_name
	 * @return mixed
	 */
	public function meta( $field_name = null ) {
		if ( $field_name === null ) {
			//on the off-chance the field is actually named meta
			$field_name = 'meta';
		}
		return $this->get_field($field_name);
	}

	/**
	 * @return string
	 */
	public function name() {
		return $this->title();
	}

	/**
	 * @param string $date_format
	 * @return string
	 */
	public function modified_date( $date_format = '' ) {
		$df = $date_format ? $date_format : get_option('date_format');
		$the_time = $this->get_modified_time($df);
		return apply_filters('get_the_modified_date', $the_time, $date_format);
	}

	/**
	 * @param string $time_format
	 * @return string
	 */
	public function modified_time( $time_format = '' ) {
		return $this->get_modified_time($time_format);
	}

	/**
	 * @api
	 * @param bool $in_same_term
	 * @return mixed
	 */
	public function next( $in_same_term = false ) {
		if ( !isset($this->_next) || !isset($this->_next[$in_same_term]) ) {
			global $post;
			$this->_next = array();
			$old_global = $post;
			$post = $this;
			if ( $in_same_term ) {
				$adjacent = get_adjacent_post(true, '', false, $in_same_term);
			} else {
				$adjacent = get_adjacent_post(false, '', false);
			}

			if ( $adjacent ) {
				$this->_next[$in_same_term] = new $this->PostClass($adjacent);
			} else {
				$this->_next[$in_same_term] = false;
			}
			$post = $old_global;
		}
		return $this->_next[$in_same_term];
	}

	/**
	 * Get a data array of pagination so you can navigate to the previous/next for a paginated post
	 * @return array
	 */
	public function pagination() {
		global $post, $page, $numpages, $multipage;
		$post = $this;
		$ret = array();
		if ( $multipage ) {
			for ( $i = 1; $i <= $numpages; $i++ ) {
				$link = self::get_wp_link_page($i);
				$data = array('name' => $i, 'title' => $i, 'text' => $i, 'link' => $link);
				if ( $i == $page ) {
					$data['current'] = true;
				}
				$ret['pages'][] = $data;
			}
			$i = $page - 1;
			if ( $i ) {
				$link = self::get_wp_link_page($i);
				$ret['prev'] = array('link' => $link);
			}
			$i = $page + 1;
			if ( $i <= $numpages ) {
				$link = self::get_wp_link_page($i);
				$ret['next'] = array('link' => $link);
			}
		}
		return $ret;
	}


	/**
	 * Finds any WP_Post objects and converts them to Timber\Posts
	 * @param array $data
	 * @param string $class
	 */
	public function convert( $data, $class ) {
		if ( is_array($data) ) {
			$func = __FUNCTION__;
			foreach ( $data as &$ele ) {
				if ( gettype($ele) === 'array' ) {
					$ele = $this->$func($ele, $class);
				} else {
					if ( $ele instanceof WP_Post ) {
						$ele = new $class($ele);
					}
				}
			}
		}

		return $data;
	}


	/**
	 * Gets the parent (if one exists) from a post as a Timber\Post object (or whatever is set in Timber\Post::$PostClass)
	 * @api
	 * @example
	 * ```twig
	 * Parent page: <a href="{{ post.parent.link }}">{{ post.parent.title }}</a>
	 * ```
	 * @return bool|Timber\Post
	 */
	public function parent() {
		if ( !$this->post_parent ) {
			return false;
		}
		return new $this->PostClass($this->post_parent);
	}


	/**
	 * Gets the relative path of a WP Post, so while link() will return http://example.org/2015/07/my-cool-post
	 * this will return just /2015/07/my-cool-post
	 * @api
	 * @example
	 * ```twig
	 * <a href="{{post.path}}">{{post.title}}</a>
	 * ```
	 * @return string
	 */
	public function path() {
		return URLHelper::get_rel_url($this->get_link());
	}


	/**
	 * Get the previous post in a set
	 * @api
	 * @example
	 * ```twig
	 * <h4>Prior Entry:</h4>
	 * <h3>{{post.prev.title}}</h3>
	 * <p>{{post.prev.get_preview(25)}}</p>
	 * ```
	 * @param bool $in_same_term
	 * @return mixed
	 */
	public function prev( $in_same_term = false ) {
		if ( isset($this->_prev) && isset($this->_prev[$in_same_term]) ) {
			return $this->_prev[$in_same_term];
		}
		global $post;
		$old_global = $post;
		$post = $this;
		$within_taxonomy = ($in_same_term) ? $in_same_term : 'category';
		$adjacent = get_adjacent_post(($in_same_term), '', true, $within_taxonomy);
		$prev_in_taxonomy = false;
		if ( $adjacent ) {
			$prev_in_taxonomy = new $this->PostClass($adjacent);
		}
		$this->_prev[$in_same_term] = $prev_in_taxonomy;
		$post = $old_global;
		return $this->_prev[$in_same_term];
	}

	/**
	 * Gets the tags on a post, uses WP's post_tag taxonomy
	 * @api
	 * @return array
	 */
	public function tags() {
		return $this->get_tags();
	}

	/**
	 * get the featured image as a TimberImage
	 * @api
	 * @example
	 * ```twig
	 * <img src="{{post.thumbnail.src}}" />
	 * ```
	 * @return TimberImage|null of your thumbnail
	 */
	public function thumbnail() {
		$tid = get_post_thumbnail_id($this->ID);
		if ( $tid ) {
			//return new Image($tid);
			return new $this->ImageClass($tid);
		}	
	}


	/**
	 * Returns the processed title to be used in templates. This returns the title of the post after WP's filters have run. This is analogous to `the_title()` in standard WP template tags.
	 * @api
	 * @example
	 * ```twig
	 * <h1>{{ post.title }}</h1>
	 * ```
	 * @return string
	 */
	public function title() {
		return apply_filters('the_title', $this->post_title, $this->ID);
	}


	/**
	 *
	 * ===================================
	 * DEPRECATED FUNCTIONS LIVE DOWN HERE
	 * ===================================
	 *
	 */

	/**
	 * Get the categories for a post
	 * @internal
	 * @deprecated since 1.0
	 * @codeCoverageIgnore
	 * @see Timber\Post::categories
	 * @return array of TimberTerms
	 */
	public function get_categories() {
		return $this->get_terms('category');
	}

	/**
	 * @internal
	 * @deprecated since 1.0
	 * @codeCoverageIgnore
	 * @see Timber\Post::category
	 * @return mixed
	 */
	public function get_category( ) {
		$cats = $this->get_categories();
		if ( count($cats) && isset($cats[0]) ) {
			return $cats[0];
		}
	}

	/**
	 * @param string $field
	 * @return TimberImage
	 */
	public function get_image( $field ) {
		return new $this->ImageClass($this->$field);
	}

	/**
	 * Gets an array of tags for you to use
	 * @internal
	 * @deprecated since 1.0
	 * @codeCoverageIgnore
	 * @example
	 * ```twig
	 * <ul class="tags">
	 *     {% for tag in post.tags %}
	 *         <li>{{tag.name}}</li>
	 *     {% endfor %}
	 * </ul>
	 * ```
	 * @return array
	 */
	public function get_tags() {
		return $this->get_terms('post_tag');
	}

	/**
	 * Outputs the title with filters applied
	 * @internal
	 * @deprecated since 1.0
	 * @codeCoverageIgnore
	 * @example
	 * ```twig
	 * <h1>{{post.get_title}}</h1>
	 * ```
	 * ```html
	 * <h1>Hello World!</h1>
	 * ```
	 * @return string
	 */
	public function get_title() {
		return $this->title();
	}

		/**
		 * Displays the content of the post with filters, shortcodes and wpautop applied
		 * @example
		 * ```twig
		 * <div class="article-text">{{post.get_content}}</div>
		 * ```
		 * ```html
		 * <div class="article-text"><p>Blah blah blah</p><p>More blah blah blah.</p></div>
		 * ```
		 * @param int $len
		 * @param int $page
		 * @return string
		 */
	public function get_content( $len = -1, $page = 0 ) {
		if ( $len === 0 ) {
			$len = -1;
		}
		return $this->content($page, $len);
	}

	/**
	 * @internal
	 * @deprecated since 1.0
	 * @return mixed
	 */
	public function get_format() {
		return $this->format();
	}

	/**
	 * Get the terms associated with the post
	 * This goes across all taxonomies by default
	 * @internal
	 * @deprecated since 1.0
	 * @codeCoverageIgnore
	 * @param string|array $tax What taxonom(y|ies) to pull from. Defaults to all registered taxonomies for the post type. You can use custom ones, or built-in WordPress taxonomies (category, tag). Timber plays nice and figures out that tag/tags/post_tag are all the same (and categories/category), for custom taxonomies you're on your own.
	 * @param bool $merge Should the resulting array be one big one (true)? Or should it be an array of sub-arrays for each taxonomy (false)?
	 * @return array
	 */
	public function get_terms( $tax = '', $merge = true ) {
		return $this->terms($tax, $merge);
	}

	/**
	 * @deprecated 0.20.0 use link() instead
	 * @codeCoverageIgnore
	 * @return string
	 */
	public function permalink() {
		Helper::warn('post.permalink has been removed, please use post.link');
		return $this->link();
	}

		/**
		 * @internal
		 * @see Timber\Post::date
		 * @deprecated since 1.0
		 * @codeCoverageIgnore
		 * @param  string $date_format
		 * @return string
		 */
	public function get_date( $date_format = '' ) {
		return $this->date($date_format);
	}

	/**
	 * @internal
	 * @see Timber\Post::modified_date
	 * @deprecated since 1.0
	 * @codeCoverageIgnore
	 * @param  string $date_format
	 * @return string
	 */
	public function get_modified_date( $date_format = '' ) {
		return $this->modified_date($date_format);
	}

	/**
	 * @internal
	 * @param  string $time_format
	 * @return string
	 */
	public function get_modified_time( $time_format = '' ) {
		$tf = $time_format ? $time_format : get_option('time_format');
		$the_time = get_post_modified_time($tf, false, $this->ID, true);
		return apply_filters('get_the_modified_time', $the_time, $time_format);
	}

	/**
	 * @internal
	 * @see Timber\Post::children
	 * @deprecated since 1.0
	 * @codeCoverageIgnore
	 * @param string 		$post_type
	 * @param bool|string 	$childPostClass
	 * @return array
	 */
	public function get_children( $post_type = 'any', $childPostClass = false ) {
		return $this->children($post_type, $childPostClass);
	}

		/**
		 * Get the permalink for a post, but as a relative path
		 * For example, where {{post.link}} would return "http://example.org/2015/07/04/my-cool-post"
		 * this will return the relative version: "/2015/07/04/my-cool-post"
		 * @internal
		 * @deprecated since 1.0
		 * @codeCoverageIgnore
		 * @return string
		 */
	public function get_path() {
		return $this->path();
	}

	/**
	 * Get the next post in WordPress's ordering
	 * @internal
	 * @deprecated since 1.0
	 * @codeCoverageIgnore
	 * @return TimberPost|boolean
	 */
	public function get_prev( $in_same_term = false ) {
		return $this->prev($in_same_term);
	}

	/**
	 * Get the parent post of the post
	 * @internal
	 * @deprecated since 1.0
	 * @codeCoverageIgnore
	 * @return bool|TimberPost
	 */
	public function get_parent() {
		return $this->parent();
	}

	/**
	 * Gets a User object from the author of the post
	 * @internal
	 * @deprecated since 1.0
	 * @codeCoverageIgnore
	 * @see Timber\Post::author
	 * @return User|null
	 */
	public function get_author() {
		return $this->author();
	}

	/**
	 * @internal
	 * @deprecated since 1.0
	 * @codeCoverageIgnore
	 * @return User|null
	 */
	public function get_modified_author() {
		return $this->modified_author();
	}

	/**
	 * @internal
	 * @see TimberPost::thumbnail
	 * @deprecated since 1.0
	 * @codeCoverageIgnore
	 * @return Image|null
	 */
	public function get_thumbnail() {
		return $this->thumbnail();
	}

	/**
	 * @internal
	 * @see TimberPost::link
	 * @deprecated since 1.0
	 * @codeCoverageIgnore
	 * @return string
	 */
	public function get_permalink() {
		return $this->link();
	}

	/**
	 * get the permalink for a post object
	 * In your templates you should use link:
	 * <a href="{{post.link}}">Read my post</a>
	 * @internal
	 * @deprecated since 1.0
	 * @codeCoverageIgnore
	 * @return string
	 */
	public function get_link() {
		return $this->get_permalink();
	}

	/**
	 * Get the next post in WordPress's ordering
	 * @internal
	 * @deprecated since 1.0
	 * @codeCoverageIgnore
	 * @param bool $taxonomy
	 * @return TimberPost|boolean
	 */
	public function get_next( $taxonomy = false ) {
		return $this->next($taxonomy);
	}

	/**
	 * Get a data array of pagination so you can navigate to the previous/next for a paginated post
	 * @internal
	 * @see Timber\Post::pagination();
	 * @deprecated since 1.0
	 * @codeCoverageIgnore
	 * @return array
	 */
	public function get_pagination() {
		return $this->pagination();
	}


	/**
	 * Get the comments for a post
	 * @internal
	 * @see Timber\Post::comments
	 * @param int $count
	 * @param string $order
	 * @param string $type
	 * @param string $status
	 * @param string $CommentClass
	 * @return array|mixed
	 */
	public function get_comments( $count = 0, $order = 'wp', $type = 'comment', $status = 'approve', $CommentClass = 'Timber\Comment' ) {
		return $this->comments($count, $order, $type, $status, $CommentClass);
	}

}
