<?php

/**
 * AdyenHppApi class
 *
 * An OOP Wrapper around Adyen HPP methods
 *  https://docs.adyen.com/developers/hpp-manual
 * @package       DatingVIP
 * @subpackage    Payment
 * @category      API
 * @author        Dragan Dinic <dragan@dinke.net>
 * @version       1.0
 */

namespace DatingVIP\Payment\Adyen;

use DatingVIP\cURL\Request as Curl;

class AdyenHppApi
{
    /**
     * Default type for HPP URL
     */
    const DEFAULT_HPP_URL = 'hpp_multi';

    /**
     * Default curl setup
     */
    const CURL_USER_AGENT       = 'DatingVIP-Adyen-Client/Version:2016.Jun.24';
    const CURL_REQUEST_TIMEOUT  = 30;

    /**
     * Test and Live URL's config for various Adyen services
     *
     * @access protected
     * @var array
     */
    protected $urls = [
        'test' => [
            'customer_area' => 'https://ca-test.adyen.com/',
            'hpp_details' => 'https://test.adyen.com/hpp/skipDetails.shtml',
            'hpp_multi' => 'https://test.adyen.com/hpp/select.shtml',
            'hpp_single' => 'https://test.adyen.com/hpp/pay.shtml',
            'directory' => 'https://test.adyen.com/hpp/directory.shtml',
            'modification_rest' => 'https://pal-test.adyen.com/pal/adapter/httppost',
        ],
        'live' => [
            'customer_area' => 'https://ca-live.adyen.com/',
            'hpp_details' => 'https://live.adyen.com/hpp/skipDetails.shtml',
            'hpp_multi' => 'https://live.adyen.com/hpp/select.shtml',
            'hpp_single' => 'https://live.adyen.com/hpp/pay.shtml',
            'directory' => 'https://live.adyen.com/hpp/directory.shtml',
            'modification_rest' => 'https://pal-live.adyen.com/pal/adapter/httppost',
        ],
    ];

    /**
     * HPP URL used for redirection
     *
     * @access protected
     * @var String
     */
    protected $hpp_url;

    /**
     * Environment (live or test)
     *
     * @access protected
     * @var String
     */
    protected $environment;

    /**
     * Last error msg
     *
     * @access protected
     * @var String
     */
    protected $error_msg;

    /**
     * Instance of DatingVIP\cURL\Request lib
     *
     * @var API_Client
     */
    protected $curl;

    /**
     * Adyen_API constructor
     *
     * @access public
     * @param string $environment
     */
    public function __construct(Curl $curl, $environment = 'test')
    {
        $this->environment = ($environment == 'live') ? 'live' : 'test';

        $this->hpp_url = $this->urls[$this->environment][self::DEFAULT_HPP_URL];

        $this->curl = $curl->setUseragent(self::CURL_USER_AGENT)
                            ->setTimeout(self::CURL_REQUEST_TIMEOUT);
    }

    /**
     * Generate full hpp redirection URL from passed data
     *
     * @param array $data
     * @return mixed string or boolean false in case of failure
     */
    public function getHPPURL($data)
    {
        //basic validation if all required params are passed
        if (empty($data) || !is_array($data)) {
            return false;
        }

        $required_params = [
            'merchantReference',
            'paymentAmount',
            'currencyCode',
            'shipBeforeDate',
            'skinCode',
            'merchantAccount',
            'orderData',
            'sessionValidity',
            'merchantReturnData',
            'countryCode',
            'shopperEmail',
            'shopperReference',
            'hmac_key',
        ];

        if (!$this->validateRequired($required_params, $data)) {
            return false;
        }

        //fill in required params
        $params = [];

        $params['merchantReference'] = $data['merchantReference'];
        $params['paymentAmount'] = $data['paymentAmount'] * 100; //we send whole amount in cents ie. 24.99 s/b 2499
        $params['currencyCode'] = $data['currencyCode'];
        $params['shipBeforeDate'] = $data['shipBeforeDate'];
        $params['skinCode'] = $data['skinCode'];
        $params['merchantAccount'] = $data['merchantAccount'];
        $params['shopperLocale'] = !empty($data['shopperLocale']) ? $data['shopperLocale'] : 'en_US';
        $params['orderData'] = base64_encode(gzencode($data['orderData']));
        $params['sessionValidity'] = $data['sessionValidity'];
        $params['merchantReturnData'] = $data['merchantReturnData'];
        $params['countryCode'] = $data['countryCode'];
        $params['shopperEmail'] = $data['shopperEmail'];
        $params['shopperReference'] = $data['shopperReference'];

        $params['allowedMethods'] = !empty($data['allowedMethods']) ? $data['allowedMethods'] : '';
        $params['blockedMethods'] = !empty($data['blockedMethods']) ? $data['blockedMethods'] : '';
        $params['offset'] = !empty($data['offset']) ? $data['offset'] : '';
        $params['shopperStatement'] = !empty($data['shopperStatement']) ? $data['shopperStatement'] : '';
        $params['brandCode'] = !empty($data['brandCode']) ? $data['brandCode'] : '';
        $params['issuerId'] = !empty($data['issuerId']) ? $data['issuerId'] : '';

        //recurring contract
        $params['recurringContract'] = !empty($data['recurringContract']) ? $data['recurringContract'] : 'RECURRING';

        //billing address stuff
        $params['billingAddress.postalCode'] = !empty($data['zipcode']) ? $data['zipcode'] : '';
        $params['billingAddress.country'] = !empty($data['country']) ? $data['country'] : '';

        //shopper stuff
        $params['shopper.firstName'] = !empty($data['first_name']) ? $data['first_name'] : '';
        $params['shopper.lastName'] = !empty($data['last_name']) ? $data['last_name'] : '';


        // HMAC Key is a shared secret KEY used to encrypt the signature. Set up the HMAC
        // key: Adyen Test CA >> Skins >> Choose your Skin >> Edit Tab >> Edit HMAC key for Test and Live
        $hmac_key = $data['hmac_key'];

        //unset all empty params
        foreach ($params as $key => $value) {
            if ($value == '') {
                unset($params[$key]);
            }
        }

        $params['merchantSig'] = $this->calculateHmacSig($hmac_key, $params);

        //change default hpp_url to details one only in case when brandCode is set
        if (!empty($params['brandCode'])) {
            $this->hpp_url = $this->urls[$this->environment]['hpp_details'];
        }

        return $this->hpp_url . '?' . http_build_query($params);
    }

    /**
     * Simple validation of required params
     *
     * @param array $required
     * @param array $data
     * @access protected
     * @return boolean
     */
    protected function validateRequired($required, $data)
    {
        if (!is_array($required) && !is_array($data)) {
            $this->error_msg = "Validate params must be array";
            return false;
        }

        //check required data params
        foreach ($required as $value) {
            if (empty($data[$value])) {
                $this->error_msg = "Missing input param: {$value}";
                return false;
            }
        }

        return true;
    }

    /**x
     * Calculate HmacSig
     *
     * @param $hmac_key
     * @param $params
     * @return string
     */
    protected function calculateHmacSig($hmac_key, $params)
    {
        // Sort the array by key using SORT_STRING order
        ksort($params, SORT_STRING);

        // Generate the signing data string
        $signData = implode(":", array_map(function ($val) {
            return str_replace(':', '\\:', str_replace('\\', '\\\\', $val));
        }, array_merge(array_keys($params), array_values($params))));

        // base64-encode the binary result of the HMAC computation
        return base64_encode(hash_hmac('sha256', $signData, pack("H*", $hmac_key), true));
    }

    /**
     * Send Modification Request
     *
     * @param string $action
     * @param array $data
     * @access public
     * @return array with result or boolean false in case of failure
     */
    public function sendModificationRequest($action, $data)
    {
        //basic validation if all required params are passed
        if (empty($action) || empty($data) || !is_array($data)) {
            $this->error_msg = "Missing required params";
            return false;
        }

        //validate modification command
        if (!in_array($action, ['capture', 'refund', 'cancel', 'cancelOrRefund'])) {
            $this->error_msg = "Unimplemented command";
            return false;
        }

        //set required params for data array
        $required_params = ['username', 'password', 'merchantAccount', 'originalReference'];

        if (in_array($action, ['capture', 'refund'])) {
            $required_params[] = 'amount';
            $required_params[] = 'currency';
        }

        //check required data params
        if (!$this->validateRequired($required_params, $data)) {
            return false;
        }

        //generate curl request
        $request = [
            'action' => 'Payment.' . $action,
            'modificationRequest.merchantAccount' => $data['merchantAccount'],
            'modificationRequest.originalReference' => $data['originalReference'],
        ];
        if (!empty($data['currency'])) {
            $request['modificationRequest.modificationAmount.currency'] = $data['currency'];
        }
        if (!empty($data['amount'])) {
            $request['modificationRequest.modificationAmount.value'] = $data['amount'] * 100;
        }

        //make request
        try {
            $response = $this->curl->setCredentials($data['username'], $data['password'])
                                    ->post($this->urls[$this->environment]['modification_rest'], $request);

        } catch (\RuntimeException $e) {
            return false;
        }

        $result = $response->getData();

        //parse result
        if ($result === false || strpos($result, 'pspReference') === false) {
            $this->error_msg = $response->getError();
            return false;
        }

        $response = [];
        parse_str($result, $response);
        return $response;
    }

    /**
     * Get recurring contract data
     *
     * @param array $data
     * @access public
     * @return mixed array with data on success, boolean false on failure
     */
    public function requestRecurringContract($data)
    {
        $required = ['username', 'password', 'merchantAccount', 'shopperReference'];
        if (!$this->validateRequired($required, $data)) {
            return false;
        }

        //generate api_client request
        $request = [
            'action' => 'Recurring.listRecurringDetails',
            'recurringDetailsRequest.merchantAccount' => $data['merchantAccount'],
            'recurringDetailsRequest.shopperReference' => $data['shopperReference'],
            'recurringDetailsRequest.recurring.contract' => 'RECURRING',
        ];

        //make request
        try {
            $response = $this->curl->setCredentials($data['username'], $data['password'])
                ->post($this->urls[$this->environment]['modification_rest'], $request);

        } catch (\RuntimeException $e) {
            return false;
        }

        $result = $response->getData();

        //parse result
        if ($result === false || strpos($result, 'recurringDetailReference') === false) {
            $this->error_msg = $response->getError();
            return false;
        }

        $response = [];
        parse_str($result, $response);
        return $response;
    }

    /**
     * Submit recurring payment
     *
     * @param array $data
     * @access public
     * @return mixed array with data on success, boolean false on failure
     */
    public function submitRecurringPayment($data)
    {
        $required = [
            'username',
            'password',
            'recurringDetailReference',
            'merchantAccount',
            'merchantReference',
            'currency',
            'amount',
            'shopperReference',
            'shopperEmail'
        ];
        if (!$this->validateRequired($required, $data)) {
            return false;
        }

        //generate api_client request
        $request = [
            'action' => 'Payment.authorise',
            'paymentRequest.selectedRecurringDetailReference' => $data['recurringDetailReference'],
            'paymentRequest.recurring.contract' => 'RECURRING',
            'paymentRequest.merchantAccount' => $data['merchantAccount'],
            'paymentRequest.amount.currency' => $data['currency'],
            'paymentRequest.amount.value' => $data['amount'] * 100,
            'paymentRequest.reference' => $data['merchantReference'],
            'paymentRequest.shopperEmail' => $data['shopperEmail'],
            'paymentRequest.shopperReference' => $data['shopperReference'],
            'paymentRequest.shopperInteraction' => 'ContAuth',
            'paymentRequest.fraudOffset' => '',
            'paymentRequest.shopperIP' => '',
            'paymentRequest.shopperStatement' => '',
            'paymentRequest.selectedbrand' => 'sepadirectdebit',
        ];

        //make request
        try {
            $response = $this->curl->setCredentials($data['username'], $data['password'])
                ->post($this->urls[$this->environment]['modification_rest'], $request);

        } catch (\RuntimeException $e) {
            return false;
        }

        $result = $response->getData();

        //parse result
        if ($result === false || strpos($result, 'pspReference') === false) {
            $this->error_msg = $response->getError();
            return false;
        }

        $response = [];
        parse_str($result, $response);
        return $response;
    }

    /**
     * Get error msg
     *
     * @access public
     * @return string
     */
    public function getErrorMsg()
    {
        return $this->error_msg;
    }
}
