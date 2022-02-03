<?php
/**
* Woocommerce LatitudeFinance Payment Extension
*
* NOTICE OF LICENSE
*
* Copyright 2020 LatitudeFinance
*
* Licensed under the Apache License, Version 2.0 (the "License");
* you may not use this file except in compliance with the License.
* You may obtain a copy of the License at
*
*   http://www.apache.org/licenses/LICENSE-2.0
*
* Unless required by applicable law or agreed to in writing, software
* distributed under the License is distributed on an "AS IS" BASIS,
* WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
* See the License for the specific language governing permissions and
* limitations under the License.
*
* @category    LatitudeFinance
* @package     Latitude_Finance
* @author      MageBinary Team
* @copyright   Copyright (c) 2020 LatitudeFinance (https://www.latitudefinancial.com.au/)
* @license     http://www.apache.org/licenses/LICENSE-2.0
*/
namespace Latitude\Tests\Wpunit;
use Codeception\Exception\ModuleException;
use tad\WPBrowser\Module\WPLoader\FactoryStore;

/**
 * Class ReturnActionWithLatitudePayTest
 * @package Latitude\Tests\Wpunit
 */
class ReturnActionWithLatitudePayTest extends LatitudePay
{
     /**
     * Accessing return action without token in session
     * User use an old success url to try and place an order twice
     * User open new tab and paste the success url, after completing 1 previous order
     * Scenario should fail on token check
     * @test
     */
    public function shouldNotBeAbleToUseSuccessUrlTwice()
    {
        $this->tester->createApiTokenSuccess();
		$this->tester->createApiPurchaseSuccess();
        WC()->cart->empty_cart();
        WC()->cart->add_to_cart( $this->simple_product->get_id(), 3 );
        WC()->cart->calculate_totals();
        $order = $this->tester->create_order();
        $result = $this->gateway->process_payment($order->get_id()); // order_id and purchase_token in session now

        $_GET = [
            'result' => \BinaryPay_Variable::STATUS_COMPLETED,
            'message' => 'The customer cancelled the purchase',
            'wc-api' => 'latitudepay_return_action',
            'token' => 'xxxxxxxxxxx', 
            'reference' => $order->get_id()
        ];
        $_GET['signature'] = $this->generate_signature($_GET); 

        // This first return action will be successful, token will be unset at this point
        $this->gateway->return_action();

        // User try to trigger the success url again to try and create double order
        $this->gateway->return_action();
        $notices = wc_get_notices( 'error' );
        $this->assertIsArray($notices);
        if(is_array($notices[0]) && isset($notices[0]['notice'])){
            $this->assertEquals($notices[0]['notice'], 'You are not allowed to access the return handler directly. If you want to know more about this error message, please contact us.');
        } else {
            $this->assertEquals($notices[0], 'You are not allowed to access the return handler directly. If you want to know more about this error message, please contact us.');
        }
    }

	/**
     * Normal successful payment scenario
     * @test
     */
    public function shouldBeAbleToHandleSuccessScenario()
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

    /**
     * Failed payment test scenario
     * @test
     */
    public function shouldBeAbleToHandleCancelledPayment()
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
}