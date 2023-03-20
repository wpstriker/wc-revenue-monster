<?php
/*
Plugin Name: WC Revenue Monster
Plugin URI: http://www.wpstriker.com/plugins
Description: Plugin for WC Revenue Monster
Version: 1.0
Author: wpstriker
Author URI: http://www.wpstriker.com
License: GPLv2
Copyright 2019 wpstriker (email : wpstriker@gmail.com)
*/

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

define('WC_REVENUE_MONSTER_URL', plugin_dir_url(__FILE__));
define('WC_REVENUE_MONSTER_DIR', plugin_dir_path(__FILE__));

require_once WC_REVENUE_MONSTER_DIR . 'functions.php';

require_once WC_REVENUE_MONSTER_DIR . 'vendor/autoload.php';

use RevenueMonster\SDK\RevenueMonster;
use RevenueMonster\SDK\Exceptions\ApiException;
use RevenueMonster\SDK\Exceptions\ValidationException;
use RevenueMonster\SDK\Request\WebPayment;
use RevenueMonster\SDK\Request\QRPay;
use RevenueMonster\SDK\Request\QuickPay;

// Make sure WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	return;
}

function wc_revenue_monster_init() {
	
	function wc_revenue_monster_add_to_gateways( $methods ) {
		$methods[] 	= 'WC_Revenue_Monster'; 
		return $methods;
	}
	add_filter( 'woocommerce_payment_gateways', 'wc_revenue_monster_add_to_gateways' );
	
	if( class_exists( 'WC_Payment_Gateway' ) ) {
		
		class WC_Revenue_Monster extends WC_Payment_Gateway {
		
			public function __construct() {
				
				$this->id               				= 'revenue_monster';
				$this->icon             				= WC_REVENUE_MONSTER_URL . 'images/rm-logo.png';
				$this->has_fields       				= true;
				$this->method_title     				= 'Revenu Monster Cards Settings';             
				
				$this->init_form_fields();
				$this->init_settings();

				$this->supports                 		= array( 'products' );
				
				$this->enable_log 						= true;

				$this->title                   			= $this->get_option( 'revenue_monster_title' );
				$this->revenue_monster_description  	= $this->get_option( 'revenue_monster_description');

				$this->revenue_monster_teststoreid 		= $this->get_option( 'revenue_monster_teststoreid' );
				$this->revenue_monster_testclientid 	= $this->get_option( 'revenue_monster_testclientid' );
				$this->revenue_monster_testclientsecret = $this->get_option( 'revenue_monster_testclientsecret' );
				$this->revenue_monster_testprivatekey 	= $this->get_option( 'revenue_monster_testprivatekey' );
				
				$this->revenue_monster_livestoreid 		= $this->get_option( 'revenue_monster_livestoreid' );
				$this->revenue_monster_liveclientid 	= $this->get_option( 'revenue_monster_liveclientid' );
				$this->revenue_monster_liveclientsecret = $this->get_option( 'revenue_monster_liveclientsecret' );
				$this->revenue_monster_liveprivatekey 	= $this->get_option( 'revenue_monster_liveprivatekey' );
							
				$this->revenue_monster_sandbox       	= $this->get_option( 'revenue_monster_sandbox' ); 
				
				//add_action( 'wp_enqueue_scripts', array( $this, 'load_revenue_monster_scripts' ) );
				
				if( $this->revenue_monster_sandbox == 'no' ) {
					$this->rm_storeid 		= $this->revenue_monster_livestoreid; 				
					$this->rm_clientid 		= $this->revenue_monster_liveclientid; 				
					$this->rm_clientsecret  = $this->revenue_monster_liveclientsecret;
					$this->rm_privatekey    = $this->revenue_monster_liveprivatekey;
				} else {
					$this->rm_storeid 		= $this->revenue_monster_teststoreid; 				
					$this->rm_clientid 		= $this->revenue_monster_testclientid;
					$this->rm_clientsecret  = $this->revenue_monster_testclientsecret;
					$this->rm_privatekey    = $this->revenue_monster_testprivatekey;
				}
				
				if ( is_admin() ) {
					add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
				}
				
				add_action( 'woocommerce_api_wc_gateway_revenue_monster', array( $this, 'check_ipn_response' ) );				
				
				add_action( 'parse_request', array( $this, 'revenue_monster_return' ) );
			}
			
			public function revenue_monster_return( $query ) {
				
				if ( $query->request != 'revenuemonster' ) {
					return;
				}
				
				$orderId	= $_REQUEST['orderId'];
				
				global $wpdb;
				$order_id	= $wpdb->get_var( "SELECT post_id FROM " . $wpdb->postmeta . " WHERE meta_key = '_revenue_monster_order_id' AND meta_value = '" . $orderId . "'" );
				
				if( $order_id ) {
					$order 	= wc_get_order( intval( $order_id ) );
					
					if( $order ) {
						
						try {
							// Initialise sdk instance
							$rm = new RevenueMonster([
								'clientId' 		=> $this->rm_clientid,
								'clientSecret' 	=> $this->rm_clientsecret,
								'privateKey' 	=> $this->rm_privatekey,
								'version' 		=> 'stable',
								'isSandbox' 	=> $this->revenue_monster_sandbox == 'no' ? false : true,
							]);
							
							$response = $rm->payment->findByOrderId( $orderId );
							
							if( $response->status == 'SUCCESS' ) {
								$transactionId	= $response->transactionId;	
								
								// we received the payment
								$order->payment_complete( $transactionId );
								$order->reduce_order_stock();
	 
								$order->add_order_note( 'Order status: SUCCESS, transactionId: ' . $transactionId . ' orderId: ' . $orderId );
	 
								// Empty cart
								$woocommerce->cart->empty_cart();
								
								//wp_redirect( $order->get_return_url() );		
								//die();						
							} 
							
						} catch(ApiException $e) {
							//print_rr( $e );													
						} catch (Exception $e) {
							//print_rr( $e );			
						}
						
						wp_redirect( $order->get_return_url() );							
						//wp_redirect( $order->get_cancel_order_url() );
						die();
					}
				}
				
				wp_redirect( site_url( '/' ) );
				die();	
				
				die();	
			}
				
			public function admin_options() {
				?>
				<h3><?php _e( 'Revenue Monster Credit cards payment gateway addon for Woocommerce', 'woocommerce' ); ?></h3>
				<p><?php  _e( 'Revenue Monster is a company that provides a way for individuals and businesses to accept payments over the Internet.', 'woocommerce' ); ?></p>
				<table class="form-table">
					<?php $this->generate_settings_html(); ?>
					<script type="text/javascript">
						jQuery( '#woocommerce_revenue_monster_revenue_monster_sandbox' ).on( 'change', function() {
							var sandbox    = jQuery( '#woocommerce_revenue_monster_revenue_monster_testclientid, #woocommerce_revenue_monster_revenue_monster_testclientsecret, #woocommerce_revenue_monster_revenue_monster_testprivatekey, #woocommerce_revenue_monster_revenue_monster_teststoreid' ).closest( 'tr' ),
								production = jQuery( '#woocommerce_revenue_monster_revenue_monster_liveclientid, #woocommerce_revenue_monster_revenue_monster_liveclientsecret, #woocommerce_revenue_monster_revenue_monster_liveprivatekey, #woocommerce_revenue_monster_revenue_monster_livestoreid' ).closest( 'tr' );

							if ( jQuery( this ).is( ':checked' ) ) {
								sandbox.show();
								production.hide();
							} else {
								sandbox.hide();
								production.show();
							}
						}).change();
					</script>
				</table>
                <?php
			}
			
			public function init_form_fields() {

				$this->form_fields = array(
					'enabled' => array(
						'title' => __( 'Enable/Disable', 'woocommerce' ),
						'type' => 'checkbox',
						'label' => __( 'Enable Revenue Monster', 'woocommerce' ),
						'default' => 'yes'
						),

					'revenue_monster_title' => array(
						'title' => __( 'Title', 'woocommerce' ),
						'type' => 'text',
						'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
						'default' => __( 'Credit Card', 'woocommerce' ),
						'desc_tip'      => true,
						),

					'revenue_monster_description' => array(
						'title' => __( 'Description', 'woocommerce' ),
						'type' => 'textarea',
						'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce' ),
						'default' => __( 'We do not store any card details', 'woocommerce' ),
						'desc_tip'      => true,
						),
					
					'revenue_monster_teststoreid' => array(
						'title' => __( 'Test Store Id', 'woocommerce' ),
						'type' => 'text',
						'description' => __( 'This is the Store(Merchant) Id found in API Keys in Account Dashboard.', 'woocommerce' ),
						'default' => '',
						'desc_tip'      => true,
						'placeholder' => 'Revenue Monster Test Store(Merchant) Id'
						),
						
					'revenue_monster_testclientid' => array(
						'title' => __( 'Test Client Id', 'woocommerce' ),
						'type' => 'text',
						'description' => __( 'This is the Client Id found in API Keys in Account Dashboard.', 'woocommerce' ),
						'default' => '',
						'desc_tip'      => true,
						'placeholder' => 'Revenue Monster Test Client Id'
						),

					'revenue_monster_testclientsecret' => array(
						'title' => __( 'Test Client Secret Key', 'woocommerce' ),
						'type' => 'text',
						'description' => __( 'This is the Client Secret Key found in API Keys in Account Dashboard.', 'woocommerce' ),
						'default' => '',
						'desc_tip'      => true,
						'placeholder' => 'Revenue Monster Test Client Secret Key'
						),
					
					'revenue_monster_testprivatekey' => array(
						'title' => __( 'Test Private Key', 'woocommerce' ),
						'type' => 'textarea',
						'description' => __( 'This is private key used in Account Dashboard.', 'woocommerce' ),
						'default' => '',
						'desc_tip'      => true,
						'placeholder' => 'Revenue Monster Test Private Key'
						),
					
					'revenue_monster_livestoreid' => array(
						'title' => __( 'Live Store Id', 'woocommerce' ),
						'type' => 'text',
						'description' => __( 'This is the Store(Merchant) Id found in API Keys in Account Dashboard.', 'woocommerce' ),
						'default' => '',
						'desc_tip'      => true,
						'placeholder' => 'Revenue Monster Live Store(Merchant) Id'
						),		

					'revenue_monster_liveclientid' => array(
						'title' => __( 'Live Client Id', 'woocommerce' ),
						'type' => 'text',
						'description' => __( 'This is the Client Id found in API Keys in Account Dashboard.', 'woocommerce' ),
						'default' => '',
						'desc_tip'      => true,
						'placeholder' => 'Revenue Monster Live Client Id'
						),

					'revenue_monster_liveclientsecret' => array(
						'title' => __( 'Live Client Secret', 'woocommerce' ),
						'type' => 'text',
						'description' => __( 'This is the Client Secret found in API Keys in Account Dashboard.', 'woocommerce' ),
						'default' => '',
						'desc_tip'      => true,
						'placeholder' => 'Revenue Monster Live Client Secret'
						),
					
					'revenue_monster_liveprivatekey' => array(
						'title' => __( 'Live Private Key', 'woocommerce' ),
						'type' => 'textarea',
						'description' => __( 'This is private key used in Account Dashboard.', 'woocommerce' ),
						'default' => '',
						'desc_tip'      => true,
						'placeholder' => 'Revenue Monster Live Private Key'
						),	
						
					'revenue_monster_sandbox' => array(
						'title'       => __( 'Revenue Monster Sandbox', 'woocommerce' ),
						'type'        => 'checkbox',
						'label'       => __( 'Enable Revenue Monster sandbox (Sandbox mode if checked)', 'woocommerce' ),
						'description' => __( 'If checked its in sanbox mode and if unchecked its in live mode', 'woocommerce' ),
						'desc_tip'      => true,
						'default'     => 'no',
						),
					);
					
			}
			
			
			public function get_description() {
				
				return apply_filters( 'woocommerce_gateway_description', wpautop( wptexturize( trim( $this->revenue_monster_description ) ) ), $this->id );
				
			}
					
			public function is_available() {
				if( 'yes' == $this->revenue_monster_sandbox && ( empty( $this->revenue_monster_testclientid ) || empty( $this->revenue_monster_testclientsecret ) || empty( $this->revenue_monster_testprivatekey ) ) ) { 
					return false; 
				}
			
				if( 'no' == $this->revenue_monster_sandbox && ( empty( $this->revenue_monster_liveclientid ) || empty( $this->revenue_monster_liveclientsecret ) || empty( $this->revenue_monster_liveprivatekey ) ) ) { 
					return false; 
				}
			
				return true;
			}
			
			public function load_revenue_monster_scripts() {
			
				wp_enqueue_script( 'revenue_monster', plugins_url( 'assets/js/revenue_monster.js',  __FILE__  ), array( 'wc-credit-card-form' ), '', true );
										
			}
			
			public function get_icon() {
				$icon 	= '';
				
				$icon  .= '<img src="' . esc_url( WC_REVENUE_MONSTER_URL . 'images/rm-logo.png' ) . '" alt="Revenue Monster" />';       
						
				return apply_filters( 'woocommerce_revenue_monster_icon', $icon, $this->id );
			}
			
			public function check_ipn_response() {
				$posted = wp_unslash( $_POST );
				
				$this->log( 'check_ipn_response: ' );
				$this->log( $posted );
				
				$postdata = file_get_contents("php://input"); 
				$this->log( 'postdata: ' );
				$this->log( $postdata );
				
				wp_die( 'OK' );		
			}
					
			public function process_payment( $order_id ) {       
				global $error;
				global $woocommerce;
				
				$wc_order	= wc_get_order( $order_id );
				
				try {
					// Initialise sdk instance
					$rm = new RevenueMonster([
						'clientId' 		=> $this->rm_clientid,
						'clientSecret' 	=> $this->rm_clientsecret,
						'privateKey' 	=> $this->rm_privatekey,
						'version' 		=> 'stable',
						'isSandbox' 	=> $this->revenue_monster_sandbox == 'no' ? false : true,
					]);
													
					$rmwp = new WebPayment();
					
					$uniqid	= uniqid(); 
					$rmwp->order->id 				= $uniqid;
					$rmwp->order->title 			= 'Sales';
					$rmwp->order->currencyType 		= 'MYR';
					$rmwp->order->amount 			= intval( $wc_order->get_total() * 100 );
					$rmwp->order->detail 			= 'OrderID:' . $order_id;
					$rmwp->order->additionalData 	= 'user_id:' . $wc_order->get_user_id() . '::-::email:' . $wc_order->get_billing_email();		
					$rmwp->type 					= 'WEB_PAYMENT';
					//$rmwp->method 					= [ "WECHATPAY_MY", "WECHATPAY_CN", "ALIPAY_CN" ];
					$rmwp->storeId 					= $this->rm_storeid;					
					//$rmwp->redirectUrl 				= $this->get_return_url( $wc_order ); 
					$rmwp->redirectUrl 				= esc_url_raw( home_url( '/revenuemonster/' ) );					
					$rmwp->notifyUrl 				= esc_url_raw( str_replace( 'https:', 'http:', add_query_arg( 'wc-api', 'WC_Gateway_Revenue_Monster', home_url( '/' ) ) ) );
					$rmwp->layoutVersion 			= 'v1';
					
					$response = $rm->payment->createWebPayment( $rmwp );
					
					$this->log( 'Payment started: ' );
					$this->log( $response );
																				
					$timestamp	= date('Y-m-d H:i:s A e', current_time( 'timestamp' ) );

					$wc_order->add_order_note( __( 'Revenue Monster payment started at ' . $timestamp . ', checkoutId = ' . $response->checkoutId, 'woocommerce' ) );
					
					add_post_meta( $wc_order->get_id(), '_revenue_monster_checkout_id', $response->checkoutId );
					add_post_meta( $wc_order->get_id(), '_revenue_monster_order_id', $uniqid );
													
					return array (
							'result'   => 'success',
							'redirect' => $response->url,
							);
					
				} catch(ApiException $e) {
					$this->log( 'Payment Failure: ' );
					$this->log( $e->getMessage() );
											
					$wc_order->add_order_note( __( 'Payment Failure: ' . $e->getMessage(), 'woocommerce' ) );
					wc_add_notice( 'Payment Failure: ' . $e->getMessage(),  $notice_type = 'error' );																	
				} catch (Exception $e) {
					$this->log( 'Payment Failure: ' );
					$this->log( $e->getMessage() );
											
					$wc_order->add_order_note( __( 'Payment Failure: ' . $e->getMessage(), 'woocommerce' ) );
					wc_add_notice( 'Payment Failure: ' . $e->getMessage(),  $notice_type = 'error' );
				}
							
			} // end of function process_payment()
			
			public function process_refund( $order_id, $amount = NULL, $reason = '' ) {
				
				$wc_order    = new WC_Order( $order_id );
				
				if( $amount > 0 ) {
					
					$transactionId	= get_post_meta( $order_id , '_revenue_monster_charge_id', true );
					
					try {
						// Initialise sdk instance
						$rm = new RevenueMonster([
							'clientId' 		=> $this->rm_clientid,
							'clientSecret' 	=> $this->rm_clientsecret,
							'privateKey' 	=> $this->rm_privatekey,
							'version' 		=> 'stable',
							'isSandbox' 	=> $this->revenue_monster_sandbox == 'no' ? false : true,
						]);
											
						$response = $rm->payment->refund( array(
							'transactionId'	=> $transactionId,
							'refund'		=> array(
								'type'			=> 'FULL',
								'currencyType'	=> 'MYR',
								'amount'		=> $amount		
							),
							'reason'		=> $reason ? $reason : 'NA'  
						) );
												
						$this->log( 'Refunded' );
						$this->log( $response );
											
						$rtimestamp  = date('Y-m-d H:i:s', current_time( 'timestamp' ) );
						
						//$wc_order->add_order_note( __( $amount . ' Refunded at ' . $rtimestamp . ', Refund Ref ID = ' . $response->transactionId, 'woocommerce' ) );                         						
						return true;
						
					} catch(ApiException $e) {
						$this->log( 'Refund failed: ' );
						$this->log( $e->getMessage() );
						
						$wc_order->add_order_note( __( 'Refund failed: ' . $e->getMessage(), 'woocommerce' ) );
						return false;																		
					} catch (Exception $e) {
						$this->log( 'Refund failed: ' );
						$this->log( $e->getMessage() );
						
						$wc_order->add_order_note( __( 'Refund failed: ' . $e->getMessage(), 'woocommerce' ) );
						return false;
					}				                		
				} else {
					$wc_order->add_order_note( __('Refund cant proccess, amount is less than zero. ', 'woocommerce' ) );            
					return false;
				}

			} // end of  process_refund()
			
			public function log( $msg ) {
				
				if( ! $this->enable_log )
					return;
					
				$msg	= function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $msg ) : $msg;
				
				$msg	= ( is_array( $msg ) || is_object( $msg ) ) ? print_r( $msg, 1 ) : $msg;
					
				error_log( date('[Y-m-d H:i:s e] ') . $msg . PHP_EOL, 3, __DIR__ . "/debug.log" );
			}
			
		}
				
	}
	
	new WC_Revenue_Monster();
}

add_action( 'plugins_loaded', 'wc_revenue_monster_init' );