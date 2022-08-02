<?php

include_once dirname(__FILE__) . '/vikbooking-duitku-sanitized.php';
include_once dirname(__FILE__) . '/vikbooking-duitku-validation.php';
defined('ABSPATH') or die('No script kiddies please!');

JLoader::import('adapter.payment.payment');


abstract class AbstractDuitkuPayment extends JPayment{
	
	/** you can control it with Sanitized (default: true) */
    public static $sanitized = true;
    public static $validation = true;
    
	protected function buildAdminParameters() {
		return array(
            'merchantcode' => array(
                'label' => __('Merchant Code','vikbooking'),
                'type' => 'text'
            ),
            'merchantkey' => array(
                'label' => __('Merchant Key','vikbooking'),
                'type' => 'text'
            ),
            'uimode' => array(
                'label' => __('UI Mode','vikbooking'),
                'type' => 'select',
                'options' => array('Popup', 'Redirect'),
            ),
            'testmode' => array(
                'label' => __('Test Mode','vikbooking'),
                'type' => 'select',
                'options' => array('Yes', 'No'),
            ),
            'expiry' => array(
                'label' => __('Expiry Period','vikbooking'),
                'type' => 'text'
            ),
        );
	}
	
	public function __construct($alias, $order, $params = array()) {
		parent::__construct($alias, $order, $params);
	}
	
	protected function beginTransaction() {
		/** See the code below to build this method */

        $merchantCode = $this->getParam('merchantcode');
        $merchantKey = $this->getParam('merchantkey');
        $expiryPeriod = $this->getParam('expiry');
        $timestamp = round(microtime(true) * 1000); 
        $amount =$this->get('total_to_pay');
        $details = $this->get('details');
        $merchant_id= $this->get('sid')."-".$this->get('ts');
        $payment_method = $this->getParam('paymentmethod');
        $signature = strtolower(hash('sha256', $merchantCode.$timestamp.$merchantKey));
        $cust_data = $this->get('custdata');
        $name = substr(explode("\r\n", $cust_data)[0],6);
        $prod_detail = $this->get('transaction_name');
        $email = $this->get('custmail');
        $phone = $this->get('phone');
        $returnUrl = $this->get('return_url');
        $return = str_replace("http://localhost","https://c234-182-253-47-123.ap.ngrok.io",$returnUrl);
        $callbackUrl =$this->get('notify_url');
        $callback = str_replace("http://localhost","https://c234-182-253-47-123.ap.ngrok.io",$callbackUrl);
        

        if( $this->getParam('testmode') == 'Yes' ) {
            $action_url = "https://api-sandbox.duitku.com/api/merchant/createInvoice";
        }
        else{
            $action_url = "https://api-prod.duitku.com/api/merchant/createInvoice";
        }
        
        if(empty($expiryPeriod)){
            $expiryPeriod = 24;
        }

        $headers = array(
            'Content-Type' => 'application/json',
            'x-duitku-signature' => $signature,
            'x-duitku-timestamp' => $timestamp,
            'x-duitku-merchantCode' => $merchantCode
          );

        $params = array( 
            'paymentAmount' => intval($amount),
            'merchantOrderId' => $merchant_id,
            'productDetails' => $prod_detail,
            'additionalParam' => '',
            'merchantUserInfo' => $email,
            'customerVaName' => $name,
            'email' => $email,
            'phoneNumber' => $phone,
            'callbackUrl' => esc_url_raw($callback),
            'returnUrl' => esc_url_raw($return),
            'expiryPeriod' => $expiryPeriod
        );

        if (self::$validation) {
            WC_Gateway_Duitku_Validation::duitkuRequest($params);
        }
          
        if (self::$sanitized) {
            WC_Gateway_Duitku_Sanitized::duitkuRequest($params);
        }
        
        $args = array(
            'body'        => json_encode($params),
            'timeout'     => '90',
            'httpversion' => '1.0',
            'headers'     => $headers,
        ); 
        $response = wp_remote_post($action_url, $args);
        
         $httpcode = wp_remote_retrieve_response_code($response);
         $server_output = wp_remote_retrieve_body($response);
         $resp = json_decode($server_output);
         echo $callbackUrl;
         //echo $expiryPeriod;
         //echo json_encode($details);
         if (isset($resp->statusCode)){
            if($resp->statusCode == "00"){
                if($this->getParam('uimode') == 'Redirect'){
                    $redirectUrl = $resp->paymentUrl;
                    $form='<a href="'.$redirectUrl.'">';  
                    $form.='<input type="button" value="Proceed to Payment" class="btn btn-outline-primary me-2"/>';
                    echo $form;
                }
                else{
                    $reference = $resp->reference;
                    //echo $reference;
                    echo '<script src="https://app-sandbox.duitku.com/lib/js/duitku.js"></script> <button type="button" id="test" class="btn btn-outline-primary me-2">test</button><script type="text/javascript">var libraryDuitkuCheckoutExecute=false; var libraryDuitkuCheckout=function (event){if (libraryDuitkuCheckoutExecute){return false;}libraryDuitkuCheckoutExecute=true; var checkoutButton=document.getElementById("test"); var REFERENCE_NUMBER="'.$reference.'"; var LANG="<%=lang %>"; var countExecute=0; var checkoutExecuted=false; var intervalFunction=0; function executeCheckout(){intervalFunction=setInterval(function (){try{console.log("Duitku payment running.", ++countExecute); checkout.process(REFERENCE_NUMBER); checkoutExecuted=true;}catch (e){if (countExecute >=20){location.reload(); checkoutButton.className="btn btn-info"; checkoutButton.innerHTML="Reloading..."; return;}}finally{clearInterval(intervalFunction);}}, 1000);}var clickCount=0; checkoutButton.className="btn btn-success"; checkoutButton.innerHTML="Proceed to Payment"; checkoutButton.onclick=function (){if (clickCount >=2){location.reload(); checkoutButton.className="btn btn-info"; checkoutButton.innerHTML="Reloading..."; return;}checkoutButton.className="btn btn-success"; checkoutButton.innerHTML="Proceed to Payment"; executeCheckout(); clickCount++;}; executeCheckout();}; document.addEventListener("DOMContentLoaded", libraryDuitkuCheckout); setTimeout(function (){console.log("calling"); libraryDuitkuCheckout(null);}, 30000);</script>';
                }
             }
         }
	}
	
    protected function validateTransaction(JPaymentStatus &$status) {
        $log = '';

        $merchantCode = sanitize_text_field($_POST['merchantCode']);
        $merchantKey = $this->getParam('merchantkey');
        $merchantOrderId = $_GET['sid']."-".$_GET['ts'];
        $signature = sanitize_text_field($_POST['signature']);
        //Checking Transaction
        $signature_transaction = md5($merchantCode . $merchantOrderId . $merchantKey);

        $params_body = array(
            'merchantCode' => $merchantCode,
            'merchantOrderId' => $merchantOrderId,
            'signature' => $signature_transaction
        );

        $headers = array(
            'Content-Type' => 'application/json'
        );

        if( $this->getParam('testmode') == 'Yes' ) {
            $url = 'https://sandbox.duitku.com/webapi/api/merchant/transactionStatus';
        }
        else{
            $url = 'https://passport.duitku.com/webapi/api/merchant/transactionStatus';
        }

        $args = array(
            'body'        => json_encode($params_body),
            'timeout'     => '90',
            'httpversion' => '1.0',
            'headers'     => $headers,
        ); 

        $response = wp_remote_post($url, $args);
        
        $httpcode = wp_remote_retrieve_response_code($response);//curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $server_output = wp_remote_retrieve_body($response);
        $resp = json_decode($server_output);

        $amount = sanitize_text_field($_POST['amount']);

        /** In case of error the log will be sent via email to the admin */
        $params = $merchantCode . $amount . $merchantOrderId . $merchantKey;
        $calcSignature = md5($params);

        $callback = $_POST;
        $status -> appendLog(json_encode($callback, JSON_PRETTY_PRINT));
        $status -> appendLog("Payment ".$resp->statusMessage);

        if($signature != $calcSignature){
            $status->appendLog( "Transaction Error!\n Signature Mismatched!"); 
        }

        else {
            $response = sanitize_text_field($_POST['resultCode']);
            //Callback log
            /** Process your gateway response here */
            if($response == '00' && $resp->statusCode == '00' && $resp->amount == $amount) {
                $status->verified(); 
                /** Set a value for the value paid */
                $status->paid($amount);
            } else {
                $status->appendLog( "Transaction Error!\n");
            }
        }
        //stop iteration
        return true;
    }

}
?>