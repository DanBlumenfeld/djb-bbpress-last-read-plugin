<?php
   /*
   Plugin Name: bbPress Plugin Plugin for Unread Replies
   Plugin URI: http://bbpress.danieljblumenfeld.com/bbpress-plugin-plugin-for-unread-replies/
   Description: a plugin to provide support for user-specific, device-independent tracking of read and unread replies in a bbPress forum
   Version: 1.2.2
   Author: Dan Blumenfeld
   Author URI: http://danieljblumenfeld.com
   License: GPL2
   */
   
   /**************************************************************************/
   /* Summary of logic and implementation                                    */ 
   /**************************************************************************/
   /*
		Forums contain Topics, which have Replies. All of these are Wordpress
		Custom Post Types. In order to track whether a Topic contains unread 
		Replies, we need to compare the most recent Reply in the Topic with the
		last Reply read in that Topic by the current user.
		
		The bbPress Topics already maintain the id of the most recent Reply; 
		so, all we need to do is to maintain a map for each user. This map will
		be of Topic IDs to the IDs of their most recently read replies.
		
		Secondary functions, such as "Mark All As Unread", can be readily 
		performed by manipulating the user's map.
		
		In addition, we can use the map to add an HTML anchor tag to the first 
		Reply in a Topic that is not yet read, permitting a URL to specify a 
		jump to said internal anchor, AKA "Go to First Unread Reply."
		
		One non-functional goal of this plugin is simplicity: if at all
		possible, we should avoid creating any new database structures.
		
		Implementation is as follows:
		The user map will be maintained in user metadata. It will be 
		parsed into an array of key-value pairs, where the key is the Topic ID
		and the value is the ID of the Reply last read by the user.
		When the Topics are being rendered, each Topic's last-reply-ID will be 
		compared to the user's last-read-reply-ID for that Topic. If the 
		last-reply-id is greater than the last-read-reply-id, a CSS class will 
		be added to the Topic to indicate that it contains unread replies. If 
		there is no last-read-reply-ID, we add a CSS class to indicate that it 
		is an unread topic.
		When a given Reply is rendered, the user's map is updated for that 
		parent Topic and the current Reply.
   */
   
   /**************************************************************************/
   /* Version History                                                        */ 
   /**************************************************************************/ 
   /*
		1.0		2/17/2013	Alpha release on Bike-PGH message board
		1.01	2/18/2013	Added first-unread anchor tag
							Added "djb_bbp_unread_replies" CSS class
							Suppressed writing unread info if no user logged in
							Cleaned up code and documentation
		1.1		2/25/2013	Added djb_bbp_get_first_unread_reply_link() method
		1.2		3/6/2013	Modified djb_bbp_get_first_unread_reply_link() method
							to return topic permalink if no user logged in.
		1.2.1 	5/23/2013	Fixed problem where visiting prior page reset 
							last_read_reply_id.
		1.2.2   5/23/2013	Fixed query that was failing to get first unread reply
   */
   
   /**************************************************************************/
   /* TODOs                                                                  */ 
   /**************************************************************************/
   /*
		Add "Mark All as Read"
				
		Test performance with a couple of thousand Topics in the map
				
		Make sure bbPress exists on activation
		
		Clean up user metadata on uninstall
   */
 
   
   /**************************************************************************/
   /* Global user map access                                                 */  
   /* I'm not sure if this is necessary, or merely overhead...               */
   /**************************************************************************/
   global $djb_bbp_user_replies_map; 
   $djb_bbp_user_replies_map = array();
   global $djb_bbp_loaded_initial_map;   
   $djb_bbp_loaded_initial_map = false;  
   
   /**
	* Ensures that the user map is loaded
	*
	* @global object $djb_bbp_loaded_initial_map Check if we've already loaded the map
	* @global object $djb_bbp_user_replies_map The user's map of last-read Replies
	*
	* @since 1.0
	*
	* @uses djb_bbp_get_user_last_read_replies_map() To load the map
	*
	*/
   function djb_bbp_verify_map() {
   
	//Make sure we're logged in
	$user_id = get_current_user_id();
	
	if (0 == $user_id)	{
		
		return;
	}
   
	global $djb_bbp_user_replies_map; 
	global $djb_bbp_loaded_initial_map;
	
	if( $djb_bbp_loaded_initial_map == false ) {
		$djb_bbp_user_replies_map = djb_bbp_get_user_last_read_replies_map();
		$djb_bbp_loaded_initial_map = true;
	}
	
   }
   
   
   /**************************************************************************/
   /* Plugin Lifecycle Housekeeping                                                           */                    
   /**************************************************************************/
   
   register_activation_hook( __FILE__, 'djb_bbp_unread_activate' );
   
   /**
	* Handles activation of the plugin
	*
	* @since 1.0
	*
	*/
   function djb_bbp_unread_activate() {
	//TODO: check to be sure bbPress exists
   }
   
   register_deactivation_hook( __FILE__, 'djb_bbp_unread_deactivate' );
   
   /**
	* Handles deactivation of the plugin
	*
	* @since 1.0
	*
	*/
   function djb_bbp_unread_deactivate() {
	//TODO: anything?
   }
   
   register_uninstall_hook( __FILE__, 'djb_bbp_unread_uninstall' );
   
   /**
	* Handles removal of the plugin
	*
	* @since 1.0
	*
	*/
   function djb_bbp_unread_uninstall() {
	//TODO: clean up the user metadata for all users
   }
   
   /**************************************************************************/
   /* Adding custom classes to Topics                                        */
   /**************************************************************************/
   
   /*
   * Adds read/unread classes to Topics
   */
   add_filter( 'post_class', 'djb_bbp_unread_class' );
    
   
   /**
	* Applies unread/read CSS classes to Topics as needed
	*
	* Does what it says on the tin. The determination of whether a Topic contains unread
	* Replies is based on the last_reply_id stored in the post metadata, as well as the
	* user's map of Topic ids to last-read Reply ids.
	*
	* @global object $djb_bbp_user_replies_map The user's map of last-read Replies
	*
	* @since 1.0
	*
	* @uses get_the_ID() To get the current post id
	* @uses get_post_type() To get the current post type
	* @uses bbp_get_topic_post_type() To get the custom post type for bbPress Topics
	* @uses get_post_meta() To get the last_reply_id for the Topic
	*
	*/
   function djb_bbp_unread_class( $classes ){
   
	//Make sure we're logged in
	$user_id = get_current_user_id();
	
	if (0 == $user_id)	{
		
		return $classes;
	}
   
	global $djb_bbp_user_replies_map;
	djb_bbp_verify_map();
   
	$post_id = get_the_ID();   
   
	//make sure current post is a Topic
	if( get_post_type( $post_id ) == bbp_get_topic_post_type() ) {
	
		//get last_reply_id from post_meta
		$last_reply_id = get_post_meta( $post_id, '_bbp_last_reply_id', true ); //"_bbp_last_reply_id" is the meta key from bbPress
		
		//Get last-read Reply from user map
		$last_read_reply_id = $djb_bbp_user_replies_map[$post_id];
		
		//If last_read Reply doesn't exist, add "unread topic" class to the current Topic.
		if( $last_read_reply_id == '' ) {
			$classes[] = "djb_bbp_unread_topic";
		}
		
		//if last-read Reply id is less than last_reply_id, add "unread replies" class to the current Topic
		if( $last_read_reply_id < $last_reply_id ) {
			$classes[] = "djb_bbp_unread_replies";
		}
		
	}
	
	return $classes;
   
   }
   
   /**************************************************************************/
   /* Reading a Topic                                                        */
   /**************************************************************************/
   
   /* Hook up our events */
   add_action('bbp_template_before_single_topic', 'djb_bbp_before_rendering_topic');
   add_action('bbp_theme_before_reply_admin_links', 'djb_bbp_before_rendering_reply');
   add_action('bbp_template_after_single_topic', 'djb_bbp_after_rendering_topic');
   
   /* Track current Topic id */
   global $djb_bbp_current_topic_id;
   
   /* Track last-read Reply id */
   global $djb_bbp_last_read_reply_id;
   
   /* Have we written a first-unread anchor tag yet? */
   global $djb_bbp_current_topic_wrote_first_unread_anchor;
   
   /**
	* Fires before rendering a single Topic
	*
	* @global object $djb_bbp_current_topic_id  Keep track of our current Topic and last read Reply
	*
	* @since 1.0
	*
	* @uses get_the_ID() To get the current post ID
	*
	*/
   function djb_bbp_before_rendering_topic() {
   
	//Make sure we're logged in
	$user_id = get_current_user_id();
	
	if (0 == $user_id)	{
		
		return;
	}
   
	//Set up globals
	global $djb_bbp_current_topic_id;
	global $djb_bbp_last_read_reply_id;
	
	global $djb_bbp_user_replies_map;
	djb_bbp_verify_map();
	
	global $djb_bbp_current_topic_wrote_first_unread_anchor;
	$djb_bbp_current_topic_wrote_first_unread_anchor = 'nope';
   
	//TODO: Make sure current post is a Topic	
	
	//Save the current Topic id globally
	$post_ID = get_the_ID();
	$djb_bbp_current_topic_id = $post_ID;
	
	//Get the last read reply id before we start processing Replies
	$djb_bbp_last_read_reply_id = $djb_bbp_user_replies_map[$post_ID];
   
   }
   
   /**
	* Fires before rendering a single Reply
	*
	* @global object $djb_bbp_current_topic_id  Keep track of our current Topic
	* @global object $$djb_bbp_current_topic_wrote_first_unread_anchor  Did we already write a first_unread anchor?
	* @global object $djb_bbp_user_replies_map  Our map of Topics to last read Replies
	*
	* @since 1.0
	*
	* @uses djb_bbp_verify_map() To make sure we've loaded the user map
	* @uses get_the_ID() To get the current post ID
	*
	*/
   function djb_bbp_before_rendering_reply() {
   
	//Make sure we're logged in
	$user_id = get_current_user_id();
	
	if (0 == $user_id)	{
		
		return;
	}
   
	//Set up globals
	global $djb_bbp_current_topic_id;
	global $djb_bbp_last_read_reply_id;
	global $djb_bbp_current_topic_wrote_first_unread_anchor;
	
	global $djb_bbp_user_replies_map;
	djb_bbp_verify_map();
   
	//TODO: make sure current post is a Reply
	
	//Get current Reply id
	$post_ID = get_the_ID();
		
	//Determine if we need to write a "first_unread_reply" anchor
	if( 'yep' != $djb_bbp_current_topic_wrote_first_unread_anchor ) {
	
		if( (int)$djb_bbp_last_read_reply_id < (int)$post_ID )  {
			echo('<a id="first_unread_reply" name="first_unread_reply"></a>');
			//Set the flag so we don't write another anchor
			$djb_bbp_current_topic_wrote_first_unread_anchor = 'yep';
		}
	}
	
	//Update the global user map with current Reply, if it's newer than the old one
	if( (int)$djb_bbp_last_read_reply_id < (int)$post_ID )  {
		$djb_bbp_user_replies_map[$djb_bbp_current_topic_id] = $post_ID;
	}
	
   
   }
   
   /**
	* Fires after rendering a single Topic
	*
	* Serializes the user map to user metadata
	*
	* @global object $djb_bbp_user_replies_map  Our map of Topics to last read Replies
	*
	* @since 1.0
	*
	* @uses djb_bbp_verify_map() To make sure we've loaded the user map
	*
	*/
   function djb_bbp_after_rendering_topic() {
   
	//Make sure we're logged in
	$user_id = get_current_user_id();
	
	if (0 == $user_id)	{
		
		return;
	}
   
	global $djb_bbp_user_replies_map;
	djb_bbp_verify_map();
	
	//Update the user map in metadata
	djb_bbp_save_user_last_read_replies_map($djb_bbp_user_replies_map);
   
   }
   
   /**************************************************************************/
   /* Adding a new Topic                                                     */
   /**************************************************************************/
   add_action('bbp_new_topic_post_extras', 'djb_bbp_new_topic');
   
   /**
	* Fires when adding a new Topic
	*
	* Serializes the user map to user metadata
	*
	* @global object $djb_bbp_user_replies_map  Our map of Topics to last read Replies
	*
	* @since 1.0
	*
	* @uses djb_bbp_verify_map() To make sure we've loaded the user map
	*
	*/
   function djb_bbp_new_topic() {
   
   //Make sure we're logged in
	$user_id = get_current_user_id();
	
	if (0 == $user_id)	{
		
		return;
	}
   
	global $djb_bbp_user_replies_map;
	djb_bbp_verify_map();
   
	//TODO: Add new Topic to user map, using its own id as the last read Reply id	
	
	djb_bbp_save_user_last_read_replies_map($djb_bbp_user_replies_map);
   }
   
   /**************************************************************************/
   /* Hyperlink generation                                                   */                    
   /**************************************************************************/
   /**
	* Allows us to (expensively) get a URL for the current user's first unread reply
	*
	* @global object $djb_bbp_user_replies_map  Our map of Topics to last read Replies
	* @global object $wpdb  The Wordpress database object
	*
	* @since 1.0
	*
	* @uses get_current_user_id() to make sure we're logged in
	* @uses bbp_topic_permalink() to return topic permalink if the user isn't logged in
	* @uses get_post_type() to get the post type of the supplied topic id
	* @uses bbp_get_topic_post_type() to verify that the current post type is that of a Topic
	* @uses djb_bbp_verify_map() To make sure we've loaded the user map
	* @uses bbp_get_reply_url() to generate a URL for the correct reply_id
	*
	*/
   function djb_bbp_get_first_unread_reply_link($topic_id) {
   
    //Make sure we're logged in
	$user_id = get_current_user_id();
	
	if (0 == $user_id)	{
		//Just return the topic link
		return bbp_topic_permalink();
	}
	
	//Make sure we're dealing with a topic
	if( get_post_type( $topic_id ) != bbp_get_topic_post_type() ) {
	
		return;
	}
   
	global $djb_bbp_user_replies_map;
	djb_bbp_verify_map();	
	
	$last_read_reply_id = $djb_bbp_user_replies_map[$topic_id];	
		
	if($last_read_reply_id == '') {	
		$last_read_reply_id = 0;
	}	
	
	global $wpdb;
	
	$sql = $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_parent = %d and ID > %d", $topic_id, $last_read_reply_id );

	$first_unread_reply_id = $wpdb->get_var($sql);
	
	if( NULL == $first_unread_reply_id) {
		$first_unread_reply_id = $last_read_reply_id;
	}
	
	return bbp_get_reply_url( $first_unread_reply_id );	
	
   }
   
   /**************************************************************************/
   /* User map maintenance                                                   */                    
   /**************************************************************************/
   
   /**
	* Gets the current user's Topic/Reply map
	*
	* Gets array of current user's last_read Reply ids, mapped to parent Topic ids
	*
	* @since 1.0
	*
	* @return array Topic (custom post type) ID mapped to Last Read Reply (custom post type) ID
	*
	*/
   function djb_bbp_get_user_last_read_replies_map() {
	
	$curr_user_id = get_current_user_id();
	
	$new_map = array();
	$new_map[0] = 0;	
			
	if ( 0 == $curr_user_id) {
		//Not logged in
	} else {
		
		$meta_string = get_user_meta( $curr_user_id, 'djb_bbp_user_map', true );	
		
		
		$pairs = explode('|', $meta_string);
		
		if( is_array($pairs) ){
		
			foreach ($pairs as $pair ) {
				
				$nv = explode(':', $pair);
					
				if( is_array($nv) ) {
					$nv = array_values($nv);			
					$topic_id = $nv[0];
					$reply_id = $nv[1];
											
					$new_map[$topic_id] = $reply_id;
				}
				
			}
		}
		
	}
	
	return $new_map;
   }
   
   /**
	* Helper set function for user map metadata
	*
	* @since 1.0
	*
	*/
   function djb_bbp_set_user_map($the_string) {
   
	$user_id = get_current_user_id();
	
	if ($user_id != 0)	{
		
		update_user_meta( $user_id, 'djb_bbp_user_map', $the_string );
	}
	
   }
   
   /**
	* Saves the supplied map
	*
	* Saves the user's topic/reply map to user metadata.
	*
	* @since 1.0
	*
	*/
   function djb_bbp_save_user_last_read_replies_map($theMap) {
   
	$new_meta_string = '';
   
	foreach ($theMap as $topic_id => $last_read_reply_id) {
		$new_meta_string .= ( '|' . $topic_id . ':' . $last_read_reply_id );
	}
	
	djb_bbp_set_user_map($new_meta_string);
	
   }
   
   /**
	* Marks all topics as unread for the current user
	*
	* Wipes out the user's topic/reply map.
	*
	* @since 1.0
	*
	*/
   function djb_bbp_mark_all_as_unread() {
	
	djb_bbp_set_user_map('');
	
   }
   
   /**
	* Marks all topics as read for the current user
	*
	* Updates the user's topic/reply map such that all topics
	* are mapped to their most recent replies.
	*
	* @since 1.0
	*
	*/
   function djb_bbp_mark_all_as_read() {
	//TODO: implement.
	
	//Sounds like a big DB query...any way to do it efficiently?
   }
   
?>