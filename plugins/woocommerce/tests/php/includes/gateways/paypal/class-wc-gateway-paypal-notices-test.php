<?php
/**
 * Unit tests for WC_Gateway_Paypal_Notices class.
 *
 * @package WooCommerce\Tests\Paypal.
 */

declare(strict_types=1);

require_once WC_ABSPATH . 'includes/gateways/paypal/includes/class-wc-gateway-paypal-notices.php';
 
/**
 * Class WC_Gateway_Paypal_Notices_Test.
 */
class WC_Gateway_Paypal_Notices_Test extends \WC_Unit_Test_Case {

	/**
	 * The PayPal gateway instance.
	 *
	 * @var WC_Gateway_Paypal
	 */
	private $gateway;

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_user_id;

	/**
	 * Shop manager user ID.
	 *
	 * @var int
	 */
	private $shop_manager_user_id;

	/**
	 * Customer user ID.
	 *
	 * @var int
	 */
	private $customer_user_id;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create test users.
		$this->admin_user_id = $this->factory->user->create(
			array(
				'role' => 'administrator',
			)
		);
		$this->shop_manager_user_id = $this->factory->user->create(
			array(
				'role' => 'shop_manager',
			)
		);
		$this->customer_user_id = $this->factory->user->create(
			array(
				'role' => 'customer',
			)
		);

		$this->gateway = $this->create_mock_gateway();
		$this->mock_gateway_available();

		wp_set_current_user( $this->admin_user_id );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		// Clean up users.
		if ( $this->admin_user_id ) {
			wp_delete_user( $this->admin_user_id );
		}
		if ( $this->shop_manager_user_id ) {
			wp_delete_user( $this->shop_manager_user_id );
		}
		if ( $this->customer_user_id ) {
			wp_delete_user( $this->customer_user_id );
		}

		// Clear user meta for all notice dismissals.
		delete_user_meta( get_current_user_id(), 'dismissed_paypal_migration_completed_notice' );
		delete_user_meta( get_current_user_id(), 'dismissed_paypal_account_restricted_notice' );
		delete_user_meta( get_current_user_id(), 'dismissed_paypal_unsupported_currency_notice' );

		// Reset PayPal gateway settings.
		delete_option( 'woocommerce_paypal_settings' );

		// Reset the gateway singleton to null.
		WC_Gateway_Paypal::set_instance( null );

		parent::tearDown();
	}

	/**
	 * Test that hooks are added during construction.
	 */
	public function test_constructor_adds_hooks() {
		// Remove existing hooks.
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'admin_head' );

		$notices = new WC_Gateway_Paypal_Notices();

		$this->assertNotFalse( has_action( 'admin_notices', array( $notices, 'add_paypal_notices' ) ) );
		$this->assertNotFalse( has_action( 'admin_head', array( $notices, 'add_paypal_notices_on_payments_settings_page' ) ) );
	}

	/**
	 * Data provider for user capability tests.
	 *
	 * @return array
	 */
	public function user_capability_data_provider() {
		return array(
			'admin can see notices'     => array(
				'user_role'      => 'administrator',
				'should_display' => true,
			),
			'shop_manager can see notices'      => array(
				'user_role'        => 'shop_manager',
				'should_display' => true,
			),
			'customer cannot see notices'       => array(
				'user_role'        => 'customer',
				'should_display' => false,
			),
		);
	}

	/**
	 * Test that notices respect user capabilities.
	 *
	 * @dataProvider user_capability_data_provider
	 * @param int  $user_role The role of the user.
	 * @param bool $should_display Whether the notice should display.
	 */
	public function test_notices_respect_user_capabilities( $user_role, $should_display ) {
		$user_id_map = array(
			'administrator' => $this->admin_user_id,
			'shop_manager' => $this->shop_manager_user_id,
			'customer' => $this->customer_user_id,
		);
		wp_set_current_user( $user_id_map[ $user_role ] );

		$notices = new WC_Gateway_Paypal_Notices();

		ob_start();
		$notices->add_paypal_notices();
		$output = ob_get_clean();

		if ( $should_display ) {
			$this->assertStringContainsString( 'WooCommerce has upgraded your PayPal integration from PayPal Standard to PayPal Payments (PPCP), for a more reliable and modern checkout experience.', $output );
		} else {
			$this->assertEmpty( $output );
		}
	}

	/**
	 * Test that migration notice is displayed when not dismissed.
	 */
	public function test_migration_notice_displayed_when_not_dismissed() {
		$notices = new WC_Gateway_Paypal_Notices();

		ob_start();
		$notices->add_paypal_notices();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'WooCommerce has upgraded your PayPal integration from PayPal Standard to PayPal Payments (PPCP), for a more reliable and modern checkout experience.', $output );
		$this->assertStringContainsString( 'notice notice-warning', $output );
		$this->assertStringContainsString( 'wc-hide-notice=paypal_migration_completed', $output );
	}

	/**
	 * Test that migration notice is not displayed when dismissed.
	 */
	public function test_migration_notice_not_displayed_when_dismissed() {
		update_user_meta( $this->admin_user_id, 'dismissed_paypal_migration_completed_notice', true );

		$notices = new WC_Gateway_Paypal_Notices();

		ob_start();
		$notices->add_paypal_notices();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'PayPal integration from PayPal Standard to PayPal Payments', $output );
	}

	/**
	 * Test that account restricted notice is displayed when flag is set.
	 */
	public function test_account_restricted_notice_displayed_when_flag_set() {		
		$this->gateway->update_option( 'paypal_account_restricted', 'yes' );
		$this->mock_gateway_available();

		$notices = new WC_Gateway_Paypal_Notices();

		ob_start();
		$notices->add_paypal_notices();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'PayPal Account Restricted', $output );
		$this->assertStringContainsString( 'notice notice-error', $output );
		$this->assertStringContainsString( 'https://www.paypal.com/smarthelp/contact-us', $output );
	}

	/**
	 * Test that account restricted notice is not displayed when flag is not set.
	 */
	public function test_account_restricted_notice_not_displayed_when_flag_not_set() {
		$this->mock_gateway_available();

		$notices = new WC_Gateway_Paypal_Notices();

		ob_start();
		$notices->add_paypal_notices();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'PayPal Account Restricted', $output );
	}

	/**
	 * Test that account restricted notice is not displayed when dismissed.
	 */
	public function test_account_restricted_notice_not_displayed_when_dismissed() {		
		// Create mock with account_restricted set to 'yes'.
		$this->create_mock_gateway( 'yes' );
		update_user_meta( $this->admin_user_id, 'dismissed_paypal_account_restricted_notice', true );

		$notices = new WC_Gateway_Paypal_Notices();

		ob_start();
		$notices->add_paypal_notices();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'PayPal Account Restricted', $output );
	}

	/**
	 * Data provider for supported currencies.
	 *
	 * @return array
	 */
	public function currency_support_data_provider() {
		return array(
			'USD is supported'  => array(
				'currency'      => 'USD',
				'should_display' => false,
			),
			'EUR is supported'  => array(
				'currency'      => 'EUR',
				'should_display' => false,
			),
			'INR is not supported' => array(
				'currency'      => 'INR',
				'should_display' => true,
			),
			'Invalid currency code not supported' => array(
				'currency'      => 'XYZ',
				'should_display' => true,
			),
		);
	}

	/**
	 * Test unsupported currency notice display based on currency.
	 *
	 * @dataProvider currency_support_data_provider
	 * @param string $currency The currency code.
	 * @param bool   $should_display Whether the notice should display.
	 */
	public function test_unsupported_currency_notice_respects_currency( $currency, $should_display ) {
		$store_currency = get_option( 'woocommerce_currency' );
		// Set the currency.
		update_option( 'woocommerce_currency', $currency );

		$notices = new WC_Gateway_Paypal_Notices();

		ob_start();
		$notices->add_paypal_notices();
		$output = ob_get_clean();

		if ( $should_display ) {
			$this->assertStringContainsString( 'PayPal Standard does not support your store currency', $output );
			$this->assertStringContainsString( $currency, $output );
		} else {
			$this->assertStringNotContainsString( 'PayPal Standard does not support your store currency', $output );
		}

		// Reset currency.
		update_option( 'woocommerce_currency', $store_currency );
	}

	/**
	 * Test that unsupported currency notice is not displayed when dismissed.
	 */
	public function test_unsupported_currency_notice_not_displayed_when_dismissed() {
		$store_currency = get_option( 'woocommerce_currency' );
		// Set the currency.
		update_option( 'woocommerce_currency', 'TRY' );
		update_user_meta( $this->admin_user_id, 'dismissed_paypal_unsupported_currency_notice', true );

		$notices = new WC_Gateway_Paypal_Notices();

		ob_start();
		$notices->add_paypal_notices();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'PayPal Standard does not support your store currency', $output );

		// Reset currency.
		update_option( 'woocommerce_currency', $store_currency );
	}

	/**
	 * Test setting account restriction flag.
	 */
	public function test_set_account_restriction_flag() {
		// Create a fresh mock for this test with expectations.
		$mock_gateway = $this->getMockBuilder( WC_Gateway_Paypal::class )
			->onlyMethods( array( 'get_option', 'update_option' ) )
			->getMock();
		
		$mock_gateway->method( 'get_option' )
			->with( 'paypal_account_restricted', 'no' )
			->willReturn( 'no' );
		
		$mock_gateway->expects( $this->once() )
			->method( 'update_option' )
			->with( 'paypal_account_restricted', 'yes' );
		
		WC_Gateway_Paypal::set_instance( $mock_gateway );

		WC_Gateway_Paypal_Notices::set_account_restriction_flag();
	}

	/**
	 * Test that setting account restriction flag when already set does not call update.
	 */
	public function test_set_account_restriction_flag_when_already_set() {
		// Create a fresh mock that returns 'yes' (already set).
		$mock_gateway = $this->getMockBuilder( WC_Gateway_Paypal::class )
			->onlyMethods( array( 'get_option', 'update_option' ) )
			->getMock();
		
		$mock_gateway->method( 'get_option' )
			->with( 'paypal_account_restricted', 'no' )
			->willReturn( 'yes' );
		
		// Should NOT call update_option since it's already set.
		$mock_gateway->expects( $this->never() )
			->method( 'update_option' );
		
		WC_Gateway_Paypal::set_instance( $mock_gateway );

		WC_Gateway_Paypal_Notices::set_account_restriction_flag();
	}

	/**
	 * Test clearing account restriction flag.
	 */
	public function test_clear_account_restriction_flag() {
		// Create a fresh mock that returns 'yes' (needs clearing).
		$mock_gateway = $this->getMockBuilder( WC_Gateway_Paypal::class )
			->onlyMethods( array( 'get_option', 'update_option' ) )
			->getMock();
		
		$mock_gateway->method( 'get_option' )
			->with( 'paypal_account_restricted', 'no' )
			->willReturn( 'yes' );
		
		$mock_gateway->expects( $this->once() )
			->method( 'update_option' )
			->with( 'paypal_account_restricted', 'no' );
		
		WC_Gateway_Paypal::set_instance( $mock_gateway );

		WC_Gateway_Paypal_Notices::clear_account_restriction_flag();
	}

	/**
	 * Test that clearing account restriction flag when already cleared does not call update.
	 */
	public function test_clear_account_restriction_flag_when_already_cleared() {
		// Create a fresh mock that returns 'no' (already cleared).
		$mock_gateway = $this->getMockBuilder( WC_Gateway_Paypal::class )
			->onlyMethods( array( 'get_option', 'update_option' ) )
			->getMock();
		
		$mock_gateway->method( 'get_option' )
			->with( 'paypal_account_restricted', 'no' )
			->willReturn( 'no' );
		
		// Should NOT call update_option since it's already cleared.
		$mock_gateway->expects( $this->never() )
			->method( 'update_option' );
		
		WC_Gateway_Paypal::set_instance( $mock_gateway );

		WC_Gateway_Paypal_Notices::clear_account_restriction_flag();
	}

	/**
	 * Test that notices are not displayed when gateway is not available.
	 */
	public function test_notices_not_displayed_when_gateway_not_available() {
		$this->mock_gateway_not_available();

		$notices = new WC_Gateway_Paypal_Notices();

		ob_start();
		$notices->add_paypal_notices();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test that notices are not displayed when Orders v2 is not enabled.
	 */
	public function test_notices_not_displayed_when_orders_v2_not_enabled() {
		$mock_gateway = $this->getMockBuilder( WC_Gateway_Paypal::class )
			->onlyMethods( array( 'should_use_orders_v2' ) )
			->getMock();
		$mock_gateway->method( 'should_use_orders_v2' )->willReturn( false );
		WC_Gateway_Paypal::set_instance( $mock_gateway );

		$notices = new WC_Gateway_Paypal_Notices();

		ob_start();
		$notices->add_paypal_notices();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test that notices on payment settings page only display on correct page.
	 */
	public function test_notices_on_payments_settings_page_only_on_correct_page() {
		$this->mock_gateway_available();

		// Mock the screen.
		set_current_screen( 'woocommerce_page_wc-settings' );
		global $current_tab, $current_section;
		$current_tab = 'checkout';
		$current_section = '';

		$notices = new WC_Gateway_Paypal_Notices();

		ob_start();
		$notices->add_paypal_notices_on_payments_settings_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'PayPal', $output );
	}

	/**
	 * Test that notices on payment settings page don't display on wrong tab.
	 */
	public function test_notices_not_displayed_on_wrong_tab() {
		wp_set_current_user( $this->admin_user_id );
		$this->mock_gateway_available();

		// Mock the screen with wrong tab.
		set_current_screen( 'woocommerce_page_wc-settings' );
		global $current_tab, $current_section;
		$current_tab = 'general';
		$current_section = '';

		$notices = new WC_Gateway_Paypal_Notices();

		ob_start();
		$notices->add_paypal_notices_on_payments_settings_page();
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test that multiple notices can be displayed simultaneously.
	 */
	public function test_multiple_notices_displayed_simultaneously() {
		$this->gateway->update_option( 'paypal_account_restricted', 'yes' );
		$this->mock_gateway_available();

		
		$store_currency = get_option( 'woocommerce_currency' );
		update_option( 'woocommerce_currency', 'TRY' );

		$notices = new WC_Gateway_Paypal_Notices();

		ob_start();
		$notices->add_paypal_notices();
		$output = ob_get_clean();

		// Should contain migration notice.
		$this->assertStringContainsString( 'PayPal integration from PayPal Standard to PayPal Payments', $output );
		// Should contain account restricted notice.
		$this->assertStringContainsString( 'PayPal Account Restricted', $output );
		// Should contain unsupported currency notice.
		$this->assertStringContainsString( 'PayPal Standard does not support your store currency', $output );

		// Reset currency.
		update_option( 'woocommerce_currency', $store_currency );
	}

	/**
	 * Mock gateway as available.
	 */
	private function mock_gateway_available() {
		update_option(
			'woocommerce_paypal_settings',
			array(
				'enabled'      => 'yes',
				'_should_load' => 'yes',
			)
		);
	}

	/**
	 * Mock gateway as not available.
	 */
	private function mock_gateway_not_available() {
		update_option(
			'woocommerce_paypal_settings',
			array(
				'enabled'      => 'no',
				'_should_load' => 'no',
			)
		);
	}

	/**
	 * Create a mock gateway instance.
	 *
	 * @param string $account_restricted_value The value for paypal_account_restricted option (default: 'no').
	 * @return WC_Gateway_Paypal|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function create_mock_gateway( $account_restricted_value = 'no' ) {
		$mock_gateway = $this->getMockBuilder( WC_Gateway_Paypal::class )
			->onlyMethods( array( 'should_use_orders_v2' ) )
			->getMock();
		
		$mock_gateway->method( 'should_use_orders_v2' )->willReturn( true );

		// Inject the mock gateway as the singleton instance.
		WC_Gateway_Paypal::set_instance( $mock_gateway );
		
		return $mock_gateway;
	}
}

