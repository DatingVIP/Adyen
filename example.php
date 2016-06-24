<?php

require 'vendor/autoload.php';

//setup curl
$curl = new DatingVIP\cURL\Request;

//create new Adyen API instance
$adyen_api = new DatingVIP\Payment\Adyen\AdyenHppApi($curl);

//set data
//Generate params needed in order to make Adyen's getHPPURL call
$params = [];

//fill in required params
$params['hmac_key'] = 'put-your-hmac-key-here';
$params['merchantReference'] = "1_1";
$params['paymentAmount'] = 10;
$params['currencyCode'] = 'EUR';
$params['shipBeforeDate'] = date("Y-m-d", strtotime("+1 days"));
$params['skinCode'] = 'put-your-scin-code-here';
$params['merchantAccount'] = 'your-merchant-account';
$params['shopperLocale'] = 'nl';
$params['brandCode'] = 'ideal';
$params['orderData'] = 'This is test example';
$params['sessionValidity'] = date("c", strtotime("+1 days"));
$params['merchantReturnData'] = '1234567890';
$params['countryCode'] = 'nl';
$params['shopperEmail'] = 'dragan@dinke.net';
$params['shopperReference'] = 'm1_12345';

//recurring contract
$params['recurringContract'] = 'RECURRING';

//personal data
$params['first_name']   = 'John';
$params['last_name']    =  'Smith';
$params['zipcode']      = '0000';
$params['country']      = 'NL';

$redirect_url = $adyen_api->getHPPURL($params);
var_dump($redirect_url);




