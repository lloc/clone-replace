<?php

/**
 * Replace one post with another
 *
 * @todo restore post meta with revision
 * @todo display post meta with revision when browsing revisions
 * @todo add post preview when replacing
 * @todo add GUI to replace one published post with another
 * @todo add ability to clone a revision
 * @todo add warning on to-be-replaced post indicating that it's soon to be replaced
 */

if ( !class_exists( 'CR_Replace' ) ) :

class CR_Replace {

	private static $instance;

	public $publishing = false;

	private function __construct() {
		/* Don't do anything, needs to be initialized via instance() method */
	}

	public function __clone() { wp_die( "Please don't __clone CR_Replace" ); }

	public function __wakeup() { wp_die( "Please don't __wakeup CR_Replace" ); }

	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new CR_Replace;
			self::$instance->setup();
		}
		return self::$instance;
	}

	public function setup() {
		add_action( 'load-post.php',           array( &$this, 'add_page_hooks' ) );
		add_action( 'load-post-new.php',       array( &$this, 'add_page_hooks' ) );

		add_action( 'save_post',               array( &$this, '__action_save_post' ) );
		add_action( 'wp_ajax_cr_search_posts', array( &$this, 'ajax_search_posts' ) );

		add_action( 'transition_post_status',  array( &$this, '__action_publish_post' ), 10, 3 );
	}


	public function add_page_hooks() {
		wp_enqueue_script( 'jquery-ui-autocomplete' );
		add_action( 'admin_footer', array( &$this, 'js' ) );
		add_action( 'clone-replace-actions', array( &$this, 'add_editpage_link' ) );
	}


	public function add_editpage_link() {
		global $post;

		if ( 'publish' != $post->post_status ) {
			$replace_id = get_post_meta( $post->ID, '_cr_replace_post_id', true );
			$replace_name = ( 0 != intval( $replace_id ) ) ? get_the_title( intval( $replace_id ) ) : '';
			?>
			<div id="replace-action">
				<h4 style="margin-bottom:0.33em"><?php _e( 'Replace', 'clone-replace' ); ?></h4>

				<?php wp_nonce_field( 'clone_replace', "replace_with_" . $post->ID ); ?>

				<div class="notice">
					<p><?php _e( 'When this post is published, it will replace the selected post. The data from this post will be moved to the replaced one, the latest version of the replaced post will become a revision, and this post will be deleted. There is no undo, per se.', 'clone-replace' ); ?></p>
				</div>

				<?php if ( 0 != ( $original_post_id = intval( get_post_meta( $post->ID, '_cr_original_post', true ) ) ) ) : ?>
				<p><a href="#" id="cr_replace_original_post" data-post-id="<?php echo $original_post_id ?>" data-title="<?php echo esc_attr( get_the_title( $original_post_id ) ) ?>"><?php _e( 'Replace original post', 'clone-replace' ); ?></a></p>
				<?php endif ?>

				<div>
					<label for="cr_replace_post_title"><?php _e( 'Find a post to replace', 'clone-replace' ); ?></label><br />
					<input type="text" id="cr_replace_post_title" value="<?php echo esc_attr( $replace_name ) ?>" style="width:100%" />
					<input type="hidden" name="cr_replace_post_id" id="cr_replace_post_id" value="<?php echo $replace_id ?>" />
				</div>

				<div id="replace_preview"></div>
			</div>
			<?php
		}
	}


	public function js() {
		?>
		<script type="text/javascript">
		jQuery(function($){
			if ( ! $('#replace-action').length )
				return;

			var $title = $('#cr_replace_post_title');
			var $post_id = $('#cr_replace_post_id');
			var cr_status = "<?php _e( 'Set to replace: <strong>{{title}}</strong>', 'clone-replace' ) ?>";
			cr_ac_options = {};
			cr_ac_options.select = function( e, ui ) {
				e.preventDefault();
				$title.val( ui.item.label );
				$post_id.val( ui.item.value );
			};
			cr_ac_options.focus = function( e, ui ) {
				e.preventDefault();
				$title.val( ui.item.label );
			};
			cr_ac_options.source = function( request, response ) {
				$.post( ajaxurl, { action: 'cr_search_posts', cr_autocomplete_search: request.term, cr_current_post: $('#post_ID').val() }, response, 'json' );
			};
			$title.autocomplete( cr_ac_options );

			$('.save-clone-replace').click(function(){
				$('#clone-replace-status').html( cr_status.replace( '{{title}}', $title.val() ) );
			});
			$('.cancel-clone-replace').click(function(){
				$title.val('');
				$post_id.val('');
				$('#clone-replace-status').html('');
			});
			$('#cr_replace_original_post').click(function(event) {
				event.preventDefault();
				$title.val( $(this).data('title') );
				$post_id.val( $(this).data('post-id') );
				$('.save-clone-replace').click();
			});
		});
		</script>
		<?php
	}


	public function ajax_search_posts() {
		$args = apply_filters( 'CR_Replace_ajax_query_args', array(
			's'                => $_POST['cr_autocomplete_search'],
			'post__not_in'     => array( intval( $_POST['cr_current_post'] ) ),
			'posts_per_page'   => 10,
			'orderby'          => 'post_date',
			'order'            => 'DESC',
			'post_status'      => 'publish',
			'post_type'        => 'any',
			'suppress_filters' => False
		) );
		$query = new WP_Query( $args );

		if ( ! $query->have_posts() )
			exit( '[]' );

		$posts = array();

		foreach( $query->posts as $post ) {
			$posts[] = array(
				'label' => ! empty( $post->post_title ) ? $post->post_title : __( '(no title)', 'clone-replace' ),
				'value' => $post->ID
			);
		}

		echo json_encode( $posts );
		exit;
	}

	/**
	 * On post publish, if this post is set to replace another, add a hook to do it.
	 * This is a two-hook process because we only want to run it when the post publishes.
	 *
	 * @return void
	 */
	public function __action_publish_post( $new_status, $old_status, $post ) {
		error_log( "Status changed for {$post->ID}: {$old_status} to {$new_status}" );
		if ( 'publish' == $new_status && 'publish' != $old_status ) {
			add_action( 'save_post', array( &$this, 'replacement_action' ), 20, 2 );
			// header( 'Content-type: text/plain' );
			// print_r( debug_backtrace() );
			// exit;
			//
			# if ( 0 != ( $replace_id = intval( get_post_meta( $post->ID, '_cr_replace_post_id', true ) ) ) ) {
			# 	$new_id = $this->replace_post( $replace_id, $post->ID );
			# 	error_log( "clone-replace: Replaced post {$replace_id} with {$post->ID}" );
			# 	wp_redirect( get_edit_post_link( $new_id, 'url' ) );
			# 	exit;
			# }
		}
	}


	public function replacement_action( $post_id, $post ) {
		error_log( "Should be replacing! {$post_id}, {$post->ID}, {$post->post_status}" );
		if ( 'publish' != $post->post_status )
			return;

		error_log( "Replacement triggered for {$post_id}" );
		if ( 0 != ( $replace_id = intval( get_post_meta( $post_id, '_cr_replace_post_id', true ) ) ) ) {
			$this->replace_post( $replace_id, $post_id );
			error_log( "clone-replace: Replaced post {$replace_id} with {$post_id}" );
			wp_redirect( get_edit_post_link( $replace_id, 'url' ) );
			exit;
		}
	}


	public function __action_save_post( $with_post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			return;

		if ( isset( $_POST['cr_replace_post_id'] ) && 0 != intval( $_POST['cr_replace_post_id'] ) ) {
			if ( ! isset( $_POST["replace_with_{$with_post_id}"] ) || ! wp_verify_nonce( $_POST["replace_with_{$with_post_id}"], 'clone_replace' ) )
				return;

			$replace_post_id = intval( $_POST['cr_replace_post_id'] );

			# If nothing has changed, no sense in progressing
			$old_replace_id = intval( get_post_meta( $with_post_id, '_cr_replace_post_id', true ) );
			if ( $old_replace_id == $replace_post_id )
				return;

			# The user needs to be able to edit the to-be-replaced post
			$post_type = get_post_type( $replace_post_id );
			$post_type_object = get_post_type_object( $post_type );
			if ( ! current_user_can( $post_type_object->cap->edit_post, $replace_post_id ) )
				return;

			# The user also needs to be able to delete the replacing post
			if ( get_post_type( $with_post_id ) != $post_type )
				$post_type_object = get_post_type_object( get_post_type( $with_post_id ) );
			if ( ! current_user_can( $post_type_object->cap->delete_post, $with_post_id ) )
				return;

			if ( !is_int( $replace_post_id ) || !is_int( $with_post_id ) )
				return false;

			error_log( "Saving post meta for {$with_post_id}" );

			# Whew! That was a lot of validation, but you never can be too safe.
			update_post_meta( $with_post_id,    '_cr_replace_post_id',   $replace_post_id );
			update_post_meta( $replace_post_id, '_cr_replacing_post_id', $with_post_id );

			# If this post was set to replace another, and that changed, we need to update that posts's meta
			if ( $old_replace_id )
				delete_post_meta( $old_replace_id, '_cr_replacing_post_id' );
		}
	}


	/**
	 * Replace one post with another
	 *
	 * @param int $old_post_id
	 * @param array $args Optional. Options for the new post.
	 * @return int the ID of the new post
	 */
	public function replace_post( $replace_post_id, $with_post_id ) {
		if ( is_int( $replace_post_id ) )
			$replace_post = get_post( $replace_post_id );
		if ( !is_object( $replace_post ) || is_wp_error( $replace_post ) )
			return false;

		if ( is_int( $with_post_id ) )
			$with_post = get_post( $with_post_id );
		if ( !is_object( $with_post ) || is_wp_error( $with_post ) )
			return false;

		# Make the to-be-replaced post and its meta a revision of itself
		$revision_id = $this->_store_post_revision( $replace_post_id );

		# Remove term relationships of to-be-replaced post
		$this->_truncate_post_terms( $replace_post_id );

		# Update fields we need to persist
		$with_post->ID          = $replace_post->ID;
		$with_post->post_name   = $replace_post->post_name;
		$with_post->guid        = $replace_post->guid;
		$with_post->post_status = $replace_post->post_status;
		wp_update_post( $with_post );

		# Update post_meta and term relationships of replacing post to its new post id
		# The replace and with look backwards, but they aren't
		$this->_copy_post_terms( $with_post_id, $replace_post_id );
		$this->_move_post_meta( $with_post_id, $replace_post_id );

		# Delete the replacing post
		error_log( "Should be deleting {$with_post_id}" );
		// remove_action( 'delete_post', '_wp_delete_post_menu_item' );

		$result = wp_delete_post( $with_post_id, true );
		error_log( "Should have deleted post! " . print_r( $result, 1 ) );

		# Perform cleanup actions
		$this->_cleanup( $replace_post_id, $revision_id );
	}


	/**
	 * Make a post a revision of itself and return the revision ID
	 *
	 * @param int $post_id
	 * @return int
	 */
	private function _store_post_revision( $post_id ) {
		global $wpdb;

		if ( !is_int( $post_id ) )
			return false;

		$revision_id = wp_save_post_revision( $post_id );

		$this->_move_post_meta( $post_id, $revision_id );
		error_log( "Moving post meta from {$post_id} to {$revision_id}" );

		return $revision_id;
	}


	private function _truncate_post_terms( $post_id ) {
		$taxonomies = get_post_taxonomies( $post_id );
		foreach ( (array) $taxonomies as $taxonomy ) {
			wp_set_object_terms( $post_id, NULL, $taxonomy );
		}
	}


	private function _copy_post_terms( $replace_post_id, $with_post_id ) {
		if ( !is_int( $replace_post_id ) || !is_int( $with_post_id ) )
			return false;

		# While it would be much more efficient to use SQL here, this
		# is much safer and more reliable to ensure proper term counts and
		# avoid collisions
		CR_Clone()->clone_terms( $replace_post_id, $with_post_id );
	}


	private function _move_post_meta( $replace_post_id, $with_post_id ) {
		if ( !is_int( $replace_post_id ) || !is_int( $with_post_id ) )
			return false;

		global $wpdb;
		# We use SQL here because otherwise we'd run (2n + 1) queries deleting postmeta and re-adding it
		$wpdb->query( "UPDATE {$wpdb->postmeta} SET `post_id` = {$with_post_id} WHERE `post_id` = {$replace_post_id}" );
	}


	private function _cleanup( $post_id, $revision_id ) {
		error_log( "Running cleanup" );
		delete_post_meta( $post_id,     '_cr_replace_post_id'   );
		delete_post_meta( $post_id,     '_cr_original_post'     );
		delete_post_meta( $revision_id, '_cr_replacing_post_id' );
	}
}

function CR_Replace() {
	return CR_Replace::instance();
}

endif;