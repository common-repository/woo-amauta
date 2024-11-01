<?php
/*
Plugin name: Amauta - WooCommerce
Plugin URI: https://amautawp.com
Author: ChichaPress
Author URI: https://emprende360.net
Description: WooCommerce and Subscriptio Integration for Amauta LMS
Version: 1.0
Support: https://chichapress.com/support/
Text Domain : amauta-wc
*/
__('WooCommerce and Subscriptio Integration for Amauta LMS', 'amauta-wc');

new AmautaWooCommerce();

class AmautaWooCommerce{
	private $default_fields = ['billing_first_name', 'billing_last_name', 'billing_company', 'billing_country','billing_address','billing_city','billing_state','billing_postcode','billing_phone','order_comments'];

	public function __construct(){
		$this->setLocale();
		$this->hooks();
	}

	public function setLocale(){
		load_textdomain( 'amauta-wc', dirname(__FILE__) .'/languages/amauta-wc-'. get_locale() .'.mo' );
	}

	public function hooks(){
		add_action( 'admin_init', [$this, 'admin_init'] );

		// Amauta CSS
		add_action( 'amauta/css/append', [$this, 'custom_css'] );
		add_filter( 'amauta/course/sections', [$this, 'course_meta'] );
		add_filter( 'amauta/program/sections', [$this, 'course_meta'] );

		add_filter( 'amauta/rewrite_rules', [$this, 'rewrite_rules'] );
		add_filter( 'amauta/meta_boxes', [$this, 'meta_box'] );
		add_action( 'save_post_course', [$this, 'save_post'] );
		add_action( 'save_post_program', [$this, 'save_post'] );
		
		add_filter( 'amauta/settings', [$this, 'settings'], 11, 1 );
		add_filter( 'amauta/settings/permalinks', [$this, 'permalinks'] );
		add_filter( 'woocommerce_checkout_fields' , [$this, 'remove_woo_checkout'] );
		//add_action( 'amauta/restrict/course', [$this, 'restrict_course'], 10, 4 );

		add_action( 'woocommerce_order_status_completed', [$this, 'wc_subscribe'], 10, 1 );
		add_action( 'woocommerce_order_status_processing', [$this, 'wc_subscribe'], 10, 1 );
		add_action( 'woocommerce_order_status_refunded', [$this, 'wc_unsubscribe'], 10, 1 );

		add_action( 'woocommerce_subscription_status_cancelled', [$this, 'wcs_unsubscribe'] );
		add_action( 'woocommerce_subscription_status_on-hold', [$this, 'wcs_unsubscribe'] );
		add_action( 'woocommerce_subscription_status_expired', [$this, 'wcs_unsubscribe'] );
		add_action( 'woocommerce_subscription_status_updated', array( $this, 'wcs_subscribe' ), 10, 3 );

		add_action( 'subscriptio_status_changed', [$this, 'subscriptio_access'], 10, 3);

		// WC Checkout
		add_action( 'template_redirect', [$this, 'redirect_checkout'], 1 );
		add_action( 'init', [$this, 'remove_notes']);
		

		// WooCommerce Account Tab
		add_action( 'init', [$this, 'addRewritePoint'] );
		add_filter( 'quiery_vars', [$this, 'addRewriteQV'] );
		add_filter( 'woocommerce_account_menu_items', [$this, 'accountLinks'] );
		add_action( 'woocommerce_account_amauta_endpoint', [$this, 'accountRender'] );
		add_filter( 'the_title', [$this, 'accountTitle'] );
	}

	public function admin_init(){
		if( !class_exists('AmautaUpdater') ) require_once dirname(__FILE__) . '/updater.php';

		new AmautaUpdater( __FILE__ );
	}

	public function course_meta( $sections = [] ){
		$sections[] = [
			'name'   => 'woocommerce',
			'title'  => __('Woocomerce', 'amauta-wc'),
			'fields' => [
				[
					'id'         => 'wooproduct',
					'type'       => 'select',
					'title'      => __('WooCommerce Product', 'amauta-wc'),
					'after'      => '<p>'.__('Assign this Course to a Product(s), the learner is required to have purchased at least one of the selected products.', 'amauta-wc').'</p>',
					
					'options'    => 'posts',
					'attributes' => ['class' => 'select2', 'multiple' => 'multiple', 'style' => 'width:100%;'],
					'query_args' => [
						'post_type' => 'product',
						'orderby'   => 'post_title',
						'order'     => 'ASC',
						'showposts' => -1
					]
				]
			]
		];
		
		return $sections;
	}

	public function meta_box( $boxes = [] ){
		$boxes[] = [
			'id'        => '_extra',
			'post_type' => 'product',
			'title'     => __('Amauta', 'amauta-wc'),
			'context'   => 'side',
			'priority'  => 'high',
			'sections'  => [
				[
					'name'   => 'amauta',
					'fields' => [
						[
							'id'         => 'checkout_url',
							'type'       => 'text',
							'title'      => __('Checkout URL', 'amauta-wc'),
							'default'    => site_url( '/wc-amauta/%post_id%' ),
							'attributes' => ['readonly' => 'true']
						]
					]
				]
			]
		];

		return $boxes;
	}

	public function remove_notes(){
		if( !function_exists('Amauta') ) return;

		if( amauta_op('woocomerce|wc_enable', false) ){
			$items = array_diff( $this->default_fields, amauta_op('woocommerce|wc_fields', []) );

			if( in_array('order_comments', $items) )
				add_filter('woocommerce_enable_order_notes_field', '__return_false');
		}
	}

	public function rewrite_rules( $rules = [] ){
		$rules['wcamauta'] = [
			'name' => 'wc-amauta',
			'slug' => 'wc-amauta',
			'rule' => 'wc-amauta/([^/]+)',
			'rewrite' => 'index.php?wc-amauta=$matches[1]',
			'position' => 'top',
			'dynamic' => true,
			'authorized' => true,
		];

		return $rules;
	}

	public function save_post(){
		if( isset($_POST['_extra']['wooproduct']) ){
			Amauta()->content->create_relationship('_wooproduct', $_POST['_extra']['wooproduct']);
		} 
	}

	public function settings( $options=[] ){
		$options[] = [
			'name' => 'amauta-wc',
			'title' => __('WooCommerce', 'amauta-wc'),
			'icon' => 'fa fa-shopping-basket',
			'fields' => [
				[
					'id' => 'woocommerce',
					'type' => 'fieldset',
					'nodiv' => true,
					'fields' => [
						[
							'content' => __('Checkout Fields', 'amauta-wc'),
							'type' => 'heading'
						],
						[
							'id' => 'wc_enable',
							'type' => 'switcher',
							'title' => __('Customize Checkout Fields', 'amauta-wc'),
							'default' => false
						],
						[
							'id'	=> 'wc_fields',
							'type'  => 'sorter',
							'title' => __('Checkout Fields', 'amauta-wc'),
							'dependency' => ['wc_enable', '==', 'true'],
							'default' => $this->default_fields,
							'options' => [
								'billing_first_name' => __('First name', 'amauta-wc'),
								'billing_last_name'  => __('Last name', 'amauta-wc'),
								'billing_company'    => __('Company name', 'amauta-wc'),
								'billing_country'    => __('Country', 'amauta-wc'),
								'billing_address'    => __('Street address', 'amauta-wc'),
								'billing_city'       => __('Town / City', 'amauta-wc'),
								'billing_state'      => __('State / County', 'amauta-wc'),
								'billing_postcode'   => __('Postcode / ZIP', 'amauta-wc'),
								'billing_phone'      => __('Phone', 'amauta-wc'),
								'order_comments'     => __('Order notes', 'amauta-wc'),
							],
						],
					]
				]
			]
		];

		return $options;
	}

	public function permalinks( $fields = [] ){
		$fields[] = [
			'id'      => 'wc_account',
			'type'    => 'text',
			'title'   => __('WooCommerce Account', 'amauta-wc'),
			'default' => amauta_op('permalinks|wc_account', _x('amauta', 'slug for woocommerce my account', 'amauta-wc'))
		];

		return $fields;
	}

	public function restrict_course( $access, $post_id, $user_id, $meta ){
		if( !function_exists( 'wc_customer_bought_product' ) ) return $access;

		$products_id = Amauta()->content->get_meta_item( 'wooproduct', $post_id );

		if( empty($products_id) ) return $access;
		if( !is_user_logged_in() ) return false;

		$current_user = get_user_by( 'id', $user_id );

		foreach( $products_id as $product_id ) {
			if( class_exists( 'WC_Subscriptions_Product' ) ) {
				if( WC_Subscriptions_Product::is_subscription( $product_id ) ) {
					if( wcs_user_has_subscription( $current_user->ID, $product_id, 'active' ) ) {
						if( !$access ){
							Amauta()->learner->add_course( $post_id, $user_id );
						}
						return true;
					} else {
						if( $access ){
							Amauta()->learner->remove_course( $post_id, $user_id );
						}
					}

				continue;
				}
			}

			if( wc_customer_bought_product( $current_user->user_email, $current_user->ID, $product_id ) ){
				if( !$access ){
					Amauta()->learner->add_course( $post_id, $user_id );
				}
				return true;		
			}
		}

		return false;
	}

	public function remove_added_to_cart_notice(){
    	$notices = WC()->session->get('wc_notices', array());

    	foreach( $notices['success'] as $key => &$notice){
        	if( strpos( $notice, 'has been added' ) !== false){
            	$added_to_cart_key = $key;
            	break;
        	}
    	}
    	unset( $notices['success'][$added_to_cart_key] );
	}
    

	public function redirect_checkout() {
		if( !function_exists('Amauta') ) return;

		$product = Amauta()->fw->get_rewrite_rule('wcamauta');

		if( !empty($product) ){
			WC()->cart->empty_cart(true);
			WC()->cart->add_to_cart( $product );

			$checkout_url = WC()->cart->get_checkout_url();

			wp_redirect( $checkout_url );
			die;
		}
	}

	
	public function remove_woo_checkout($fields){
		if( !function_exists('amauta_op') ) return $fields;
		if( !amauta_op('woocomemrce|wc_enable', false ) ) return $fields;

		$items = array_diff($this->default_fields, amauta_op('woocommerce|wc_fields', []) );

		foreach( $items as $field ){
			if( 'billing_address'==$field ){
				unset($fields['billing']['billing_address_1']);
				unset($fields['billing']['billing_address_2']);
				continue;
			}
			
			if( 'order_comments'==$field ){
				unset($fields['order']['order_comments']);
				continue;
			}

			if( 'billing_first_name'==$field && isset($fields['billing']['billing_last_name']) ){
				$fields['billing']['billing_last_name']['class'] = array('form-row-wide');
			}

			if( 'billing_last_name'==$field && isset($fields['billing']['billing_first_name']) ){
				$fields['billing']['billing_first_name']['class'] = array('form-row-wide');
			}

			if( 'billing_phone'==$field ){
				$fields['billing']['billing_email']['class'] = array('form-row-wide');
			}

			unset($fields['billing'][$field]);

		}

		return $fields;
	}

	public function updateCSS(){
		Amauta()->regenerate_css();
	}

	public function custom_css( $css ){
		$custom_css = "
			.awc-item{
				padding:20px 0;
				margin:0;
				border-bottom:1/px solid ".amauta_op('design|color4').";
			}
			.awc-item:first-child{
				padding-top:0;
			}
			.awc-item:last-of-type{
				border-bottom:0;
			}
			.awc-item .course-progressbar{
				margin-top:10px;
			}
			.awc-item .awc-percentage{
				float:right;
				font-style:italic;
				color:#adadad;
				font-size:14px;
			}
			.awc-item a{
				font-size:16px;
			}
		";

		if( amauta_op('woocommerce|wc_enable', false) ){

			$items = array_diff($this->default_fields, amauta_op('woocommerce|wc_fields', []) );
			
			if( in_array('order_comments', $items) ){
				$custom_css .= ".woocommerce-page.woocommerce-checkout .col2-set .col-1 {float: none !important;width: 100% !important;}";
			}
		}

		return $css . $custom_css;
	}

	public function addRewritePoint(){
		if( !function_exists('amauta_op') ) return;

		add_rewrite_endpoint( amauta_op('permalink|wc_account', 'amauta'), EP_ROOT | EP_PAGES );
	}

	public function addRewriteQV( $vars ){
		if( !function_exists('amauta_op') ) return;

		$vars[] = amauta_op('permalink|wc_account', 'amauta');
		return $vars;
	}

	public function accountTitle( $title ){
		global $wp_query;

		if( !function_exists('amauta_op') ) return;

		$is_endpoint = isset( $wp_query->query_vars[amauta_op('permalink|wc_account', 'amauta')] );

		if ( $is_endpoint && ! is_admin() && is_main_query() && in_the_loop() && is_account_page() ) {
			
			$title = __( 'My Courses', 'amauta-wc' );
			remove_filter( 'the_title', [$this, 'accountTitle'] );
		}
		return $title;
	}

	public function accountLinks( $items ){
		if( !function_exists('amauta_op') ) return;

		$new = [];
		$new[ amauta_op('permalink|wc_account', 'amauta') ] = __('My Courses', 'amauta-wc');

		$items = $this->insert($items, $new, '');

		return $items;
	}

	public function accountRender(){
		global $wpdb;


		$courses = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.*,
			(( SELECT COUNT(*) FROM {$wpdb->prefix}lessontrack AS t 
			  LEFT JOIN {$wpdb->prefix}lessons AS l 
			  ON (t.lesson_id=l.ID) WHERE l.course=s.course_id AND t.user_id=s.user_id
			)*100/(SELECT COUNT(*) FROM {$wpdb->prefix}lessons WHERE course=s.course_id)) AS progress FROM {$wpdb->prefix}course_signups AS s LEFT JOIN {$wpdb->posts} AS p  ON (s.course_id=p.ID) WHERE s.user_id=%s", 
			get_current_user_id()
		) );

		if( empty($courses) ){
			printf('<p><em>%s</em></p>', __('You don\'t have courses in this moment.', 'amauta-wc') );
		}

		foreach($courses as $course){
			echo '<div class="awc-item">';
			
			printf('<span class="awc-percentage">%3$s</span><a href="%2$s">%1$s</a>', $course->post_title, get_permalink($course->ID), ((int)$course->progress).'%' );
			printf('<div class="course-progressbar"><div class="course-progress" style="width:%s"></div></div>', ((int)$course->progress).'%' );

			echo '</div>';
		}
	}


	protected function coursesOptions(){
		$courses = amauta_getCourses();
		$data = [];

		foreach($courses as $course)
{			$data[$course->ID] = $course->post_title;
		}

		return $data;
	}


	public function removeCourse( $order_id  ){

		$order = 'WC_Order'==get_class($order_id) ? $order_id : new WC_Order( $order_id );
		$products = $order->get_items();

		foreach ( $products as $product ) {
			$courses_id = (array)get_post_meta( $product['product_id'], '_courses', true );
			foreach ( $courses_id as $cid ) {
				amauta_removeCourse( $cid, $order->custom_user );
			}
		}
	}

	public function wc_subscribe( $order_id ){
		if( !function_exists('Amauta') ) return;

		$order = new WC_Order( $order_id );
		if ( ($order) && ( $order->has_status( 'completed' ) ) ) {
			$products = $order->get_items();

			foreach($products as $product){
				$courses_id = Amauta()->content->get_relationship( '_wooproduct', $product['product_id']);

				if ( $courses_id && is_array( $courses_id ) ) {
					foreach ( $courses_id as $cid ) {
						switch( get_post_type($cid) ){
							case 'course':
								Amauta()->learner->add_course( $cid, $order->customer_user );
							break;	
							case 'program':
								$crs = Amauta()->content->get_meta_item( 'courses', $cid );
								if( is_array($crs) ){
									foreach( $crs as $cr ){
										Amauta()->learner->add_course( $cr, $order->customer_user );
									}
								}
							break;
						}
						

						// if WooCommerce subscription plugin enabled
						if ( class_exists( 'WC_Subscriptions' ) ) {
							// If it's a subscription...
							if ( WC_Subscriptions_Order::order_contains_subscription($order) || WC_Subscriptions_Renewal_Order::is_renewal( $order ) ) {
								error_log("Subscription (may be renewal) detected");
								if ( $sub_key = WC_Subscriptions_Manager::get_subscription_key($order_id, $product['product_id'] ) ) {
									error_log("Subscription key: " . $sub_key );
									$subscription_r = WC_Subscriptions_Manager::get_subscription( $sub_key );
								}
							}
						}
					}
				}
			}
		}
	}

	public function wc_unsubscribe( $order_id ){
		$order = 'WC_Order'==get_class($order_id) ? $order_id : new WC_Order( $order_id );
		$products = $order->get_items();

		foreach ( $products as $product ) {
			$courses_id = Amauta()->content->get_relationship( '_wooproduct', $product['product_id']);

			foreach ( $courses_id as $cid ) {
				switch( get_post_type($cid) ){
					case 'course':
						Amauta()->learner->remove_course( $cid, $order->customer_user );
					break;	
					case 'program':
						$crs = Amauta()->content->get_meta_item( 'courses', $cid );
						if( is_array($crs) ){
							foreach( $crs as $cr ){
								Amauta()->learner->remove_course( $cr, $order->customer_user );
							}
						}
					break;
				}
			}
		}
	}

	public function wcs_subscribe( $order, $new_status, $old_status ){
		if ( 'on-hold' != $old_status || 'active' != $new_status ) return;

		$this->_wcs_subscribe( $order );
	}

	private function _wcs_subscribe( $order ){
		$products   = $order->get_items();
		$start_date = $order->start_date;

		foreach ( $products as $product ) {
			$courses_id = Amauta()->content->get_relationship( '_wooproduct', $product['product_id']);
		
			// Update access to the courses
			if ( $courses_id && is_array( $courses_id ) ) {
				foreach ( $courses_id as $course_id ) {

					error_log( "Checking for course: " . $course_id . " and User: " . $order->customer_user );

					if(  empty( $order->customer_user ) || empty( $course_id ) ) {
						error_log( "User id: " . $order->customer_user . " Course Id:" . $course_id );
						return;
					}

					error_log( "Empty list or user don't have access yet." );
					switch( get_post_type($course_id) ){
						case 'course':
							Amauta()->learner->add_course( $course_id, $order->customer_user );
						break;	
						case 'program':
							$crs = Amauta()->content->get_meta_item( 'courses', $course_id );
							if( is_array($crs) ){
								foreach( $crs as $cr ){
									Amauta()->learner->add_course( $cr, $order->customer_user );
								}
							}
						break;
					}
				}
			}
		}
	}

	public function wcs_unsubscribe( $order_id ){
		$order = 'WC_Order'==get_class($order_id) ? $order_id : new WC_Order( $order_id );
		$products = $order->get_items();

		foreach ( $products as $product ) {
			$courses_id = Amauta()->content->get_relationship( '_wooproduct', $product['product_id']);

			if( !is_array($courses_id) ) return;

			foreach ( $courses_id as $cid ) {
				switch( get_post_type($cid) ){
					case 'course':
						Amauta()->learner->remove_course( $cid, $order->customer_user );
					break;	
					case 'program':
						$crs = Amauta()->content->get_meta_item( 'courses', $cid );
						if( is_array($crs) ){
							foreach( $crs as $cr ){
								Amauta()->learner->remove_course( $cr, $order->customer_user );
							}
						}
					break;
				}
			}
		}
	}

	public function subscriptio_access( $subscription, $old_status, $new_status ){
		$courses_id = Amauta()->content->get_relationship( '_wooproduct', $product['product_id']);

		if( !is_array($courses_id) ) return;

		foreach( $courses_id as $cid ){
			switch( $new_status ){
				case 'active':
					switch( get_post_type($cid) ){
						case 'course':
							Amauta()->learner->add_course( $cid, $subscription->user_id );
						break;	
						case 'program':
							$crs = Amauta()->content->get_meta_item( 'courses', $cid );
							if( is_array($crs) ){
								foreach( $crs as $cr ){
									Amauta()->learner->add_course( $cr, $subscription->user_id );
								}
							}
						break;
					}
				break;
				case 'paused':
				case 'cancelled':
					switch( get_post_type($cid) ){
						case 'course':
							Amauta()->learner->remove_course( $cid, $subscription->user_id );
						break;	
						case 'program':
							$crs = Amauta()->content->get_meta_item( 'courses', $cid );
							if( is_array($crs) ){
								foreach( $crs as $cr ){
									Amauta()->learner->remove_course( $cr, $subscription->user_id );
								}
							}
						break;
					}
				break;
			}
		}
	}

	protected function insert( $items, $new_items, $after ) {
		// Search for the item position and +1 since is after the selected item key.
		if( filter_var($after, FILTER_VALIDATE_INT) ){
			$position = $after;	
		} else {
			$position = array_search( $after, array_keys( $items ) ) + 1;
		}

		// Insert the new item.
		$array = array_slice( $items, 0, $position, true );
		$array += $new_items;
		$array += array_slice( $items, $position, count( $items ) - $position, true );

	    return $array;
	}
}