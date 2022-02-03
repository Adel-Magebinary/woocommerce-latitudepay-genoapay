<?php

namespace Latitude\Tests\Wpunit;
use Codeception\Exception\ModuleException;
use tad\WPBrowser\Module\WPLoader\FactoryStore;


/**
 * Class CallbackWithLatitudePayTest
 * @package Latitude\Tests\Wpunit
 */
class CallbackWithLatitudePayTest extends LatitudePay
{
     /**
     * Reference is not set on url parameter
     * Use case: when somehow information from Lpay & Gpay core API is corrupted/missing 
     * @test
     */
    public function shouldBeAbleToHandleIncompleteParameter()
    {
        // Create order and process payment as usual until redirected to Gpay portal
        $this->tester->createApiTokenSuccess();
		$this->tester->createApiPurchaseSuccess();
        WC()->cart->empty_cart();
        WC()->cart->add_to_cart( $this->simple_product->get_id(), 3 );
        WC()->cart->calculate_totals();
        $order = $this->tester->create_order();
        $result = $this->gateway->process_payment($order->get_id()); // order_id and purchase_token in session now
        
        // Return from Gpay portal with failed param
        $_GET = [
            'result' => \BinaryPay_Variable::STATUS_FAILED,
            'message' => 'The customer cancelled the purchase',
            'wc-api' => 'latitudepay_return_action',
            'token' => 'xxxxxxxxxxx', 
        ];
        $_GET['signature'] = $this->generate_signature($_GET); 

        // return_action() should stop it at signature check, token will be unset at this point
        $this->gateway->return_action();
        $notices = wc_get_notices( 'error' );
        $this->assertIsArray($notices);
        if(is_array($notices[0]) && isset($notices[0]['notice'])){
            $this->assertEquals($notices[0]['notice'], 'Incomplete information on the request');
        } else {
            $this->assertEquals($notices[0], 'Incomplete information on the request');
        }
    }

    /**
     * Test send request with invalid signature to callback scenario
     * use case: User copy and paste fail url to new tab (clean session) and change it to complete
     * @test
     */
    public function callbackShouldValidateResponseSignature()
    {
        // Create order and process payment as usual until redirected to Gpay portal
        $this->tester->createApiTokenSuccess();
		$this->tester->createApiPurchaseSuccess();
        WC()->cart->empty_cart();
        WC()->cart->add_to_cart( $this->simple_product->get_id(), 3 );
        WC()->cart->calculate_totals();
        $order = $this->tester->create_order();
        $result = $this->gateway->process_payment($order->get_id()); // order_id and purchase_token in session now
        
        // Return from Gpay portal with failed param
        $_GET = [
            'result' => \BinaryPay_Variable::STATUS_FAILED,
            'message' => 'The customer cancelled the purchase',
            'wc-api' => 'latitudepay_return_action',
            'token' => 'xxxxxxxxxxx', 
            'reference' => $order->get_id()
        ];
        $_GET['signature'] = $this->generate_signature($_GET); 

        // User intercepted and changed the result parameter
        $_GET['result'] = \BinaryPay_Variable::STATUS_COMPLETED;

        // User open a new tab
        WC()->session->set( 'order_id', null );
        WC()->session->set( 'purchase_token', null );

        // return_action() should stop it at signature check, token will be unset at this point
        $this->gateway->return_action();
        $notices = wc_get_notices( 'error' );
        $this->assertIsArray($notices);
        if(is_array($notices[0]) && isset($notices[0]['notice'])){
            $this->assertEquals($notices[0]['notice'], 'The return action handler is not valid for the request.');
        } else {
            $this->assertEquals($notices[0], 'The return action handler is not valid for the request.');
        }
    }

    /**
     * Failed payment test scenario
     * @test
     */
    public function callbackShouldBeAbleToHandleCancelledPayment()
    {
        // Create order and process payment as usual until redirected to Lpay portal
        $this->tester->createApiTokenSuccess();
		$this->tester->createApiPurchaseSuccess();
        WC()->cart->empty_cart();
        WC()->cart->add_to_cart( $this->simple_product->get_id(), 3 );
        WC()->cart->calculate_totals();
        $order = $this->tester->create_order();
        $result = $this->gateway->process_payment($order->get_id()); // order_id and purchase_token in session now
        
        // Return from Lpay portal with failed param
        $_GET = [
            'result' => \BinaryPay_Variable::STATUS_FAILED,
            'message' => 'The customer cancelled the purchase',
            'wc-api' => 'latitudepay_return_action',
            'token' => 'xxxxxxxxxxx', 
            'reference' => $order->get_id()
        ];
        $_GET['signature'] = $this->generate_signature($_GET); 

        // User close browser right before returning with $_GET (parameters), sessions are killed
        WC()->session->set( 'order_id', null );
        WC()->session->set( 'purchase_token', null );

        $this->gateway->return_action();
        $notices = wc_get_notices( 'error' );
        $this->assertIsArray($notices);
        if(is_array($notices[0]) && isset($notices[0]['notice'])){
            $this->assertEquals($notices[0]['notice'], 'your purchase has been cancelled.');
        } else {
            $this->assertEquals($notices[0], 'your purchase has been cancelled.');
        }

        $this->assertEquals($order->get_status(), 'failed');
    }

    /**
     * Normal successful payment scenario
     * @test
     */
    public function callbackShouldBeAbleToHandleSuccessScenario()
    {
        $this->tester->createApiTokenSuccess();
		$this->tester->createApiPurchaseSuccess();
        WC()->cart->empty_cart();
        WC()->cart->add_to_cart( $this->simple_product->get_id(), 3 );
        WC()->cart->calculate_totals();
        $order = $this->tester->create_order();
        $result = $this->gateway->process_payment($order->get_id());
        $purchaseToken = WC()->session->get('purchase_token');
        $_GET = [
            'result' => \BinaryPay_Variable::STATUS_COMPLETED,
            'message' => 'Payment Success',
            'wc-api' => 'latitudepay_return_action',
            'token' => $purchaseToken, 
            'reference' => $order->get_id()
        ];
        $_GET['signature'] = $this->generate_signature($_GET); 

        // User close browser right before returning with $_GET (parameters), sessions are killed
        WC()->session->set( 'order_id', null );
        WC()->session->set( 'purchase_token', null );

        $this->gateway->return_action();
        $notices = wc_get_notices( 'error' );
        $this->assertEmpty($notices);
        $headers = xdebug_get_headers();
        $this->assertContains(
            "X-Redirect-By: WordPress",
            $headers
        );
        $this->assertRegExp(
            "/order-received/",
            json_encode($headers)
        );
        $this->assertEquals($order->get_status(), 'processing');
    }
}