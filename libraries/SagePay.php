<?php
/**
 * SagePay
 */
class SagePay {

	/**
	 * Default timeout for Curl stuff
	 */
	const DEFAULT_TIMEOUT = 3;

	/**
	 * Default currency to use
	 */
	const DEFAULT_CURRENCY = 'GBP';

	/**
	 * The various URL's for connecting to
	 *
	 * @var array
	 */
	private	$list_url = array(
		'sim'	=> 'https://test.sagepay.com/Simulator/VSPServerGateway.asp?Service=',
		'test'	=> 'https://test.sagepay.com/gateway/service/',
		'live'	=> 'https://live.sagepay.com/gateway/service/',
	);

	/**
	 * Service strings
	 *
	 * @var array
	 */
	private	$list_svc = array(
		// Service strings that complement the Simulator URL
		'sim'	=> array(
			'payment'		=> 'VendorRegisterTx',
			'release'		=> 'VendorReleaseTx',
			'abort'			=> 'VendorAbortTx',
			'refund'		=> 'VendorRefundTx',
			'repeat'		=> 'VendorRepeatTx',
			'void'			=> 'VendorVoidTx',
			/* NOT SUPPORTED IN SIM MODE */
//			'manual'		=> FALSE,
//			'directrefund'	=> FALSE,
			'authorize'		=> 'VendorAuthorizeTx',
			'cancel'		=> 'VendorCancelTx',
		),
		// service strings that complement the Test & Live Systems URLs
		'sys'	=> array(
			'payment'		=> 'vspserver-register.vsp',
			'release'		=> 'release.vsp',
			'abort'			=> 'abort.vsp',
			'refund'		=> 'refund.vsp',
			'repeat'		=> 'repeat.vsp',
			'void'			=> 'void.vsp',
			'manual'		=> 'manualpayment.vsp',
			'directrefund'	=> 'directrefund.vsp',
			'authorize'		=> 'authorize.vsp',
			'cancel'		=> 'cancel.vsp'
		)
	);

	/**
	 * The VPS version string
	 * @var string
	 */
	private	$sage_ver = '2.23';

	/**
	 * The VendorTxCode assigned
	 * @var string
	 */
	private	$sage_vnd;

	/**
	 * the currency to be used in the transactions
	 * @var string
	 */
	private	$currency;

	/**
	 * The notification URL to be used throughout
	 * @var string
	 */
	private	$notify;

	/**
	 * Should contain one of the URLs defined in $list_url
	 * @var string
	 */
	private	$sage_url;

	/**
	 * Should be either sim or sys to access the indexed $list_services
	 * @var string
	 */
	private	$sage_svc;

	/**
	 * Last transaction code
	 * @var string
	 */
	public	$last_tx_code = '';

	/**
	 * Setup!
	 *
	 * @param array $config
	 * @return bool
	 */
	public function __construct ( $config = null )
	{
		if ( !is_null($config) AND is_array($config) )
		{
			$this->initialize($config);
		}
		else
		{
			// load the default config
			$this->initialize(Kohana::config('sagepay'));
		}
	}

	/**
	 * @param bool $url
	 * @return bool
	 */
	public function notification_url ( $url = null )
	{
		if ( !is_null($url) )
		{
			$this->notify = $url;
			return TRUE;
		}
		else
		{
			return $this->notify;
		}
	}
	
//	SERVICE-SPECIFIC FUNCTIONS (the ones you actually call)

	/**
	 * CALLBACK acknowledgement of notification received
	 * 
	 * @param	array	$options (status, status_detail, redirect_url)
	 * @return	string	$response {response string that should be output in reply to VPS callback}
	 */
	public function txCallback( $options )
	{
		return 'Status='.$options['status']."\r\n".
		'StatusDetail='.$options['status_detail']."\r\n".
		'RedirectURL='.$options['redirect_url']."\r\n";
	}
	
	/**
	 * PAYMENT transaction
	 * 
	 * @param	array	$customer (first_name, last_name, address1, [address2],
	 * 					city, postcode, country, [state], [phone], email)
	 * @param	array	$order (amount, description, basket)
	 * @return	array	$result (Success, VPSProtocol, Status, StatusDetail,
	 * 					VPSTxId, SecurityKey, NextURL)
	 */
	public function txPayment( $customer,  $order )
	{
		$this->last_tx_code	= $this->_clean_input($this->sage_vnd.'_'.gmdate("Ymd_His").'_payment', FALSE, TRUE);

		$post	= array();
		$post['TxType']					= 'PAYMENT';
		$post['Vendor'] = $this->sage_vnd;
		$post['VendorTxCode'] = $this->last_tx_code;
		$post['Amount'] = $order['amount'];
		$post['Currency'] = $this->currency;
		$post['Description'] = $order['description'];
		$post['NotificationURL'] = $this->notify;
		$post['BillingFirstnames'] = $post['DeliveryFirstnames'] = $customer['first_name'];
		$post['BillingSurname'] = $post['DeliverySurname'] = $customer['last_name'];
		$post['BillingAddress1'] = $post['DeliveryAddress1'] = $customer['address1'];

		if ( isset($customer['address2']) AND !empty($customer['address2']) )
		{
			$post['BillingAddress2'] = $post['DeliveryAddress2'] = $customer['address2'];
		}

		$post['BillingCity'] = $post['DeliveryCity'] = $customer['city'];
		$post['BillingPostCode'] = $post['DeliveryPostCode'] = $customer['postcode'];
		$post['BillingCountry'] = $post['DeliveryCountry'] = $customer['country'];

		if ( isset($customer['state']) AND !empty($customer['state']) )
		{
			$post['BillingState'] = $post['DeliveryState'] = $customer['state'];
		}

		if ( isset($customer['phone']) AND !empty($customer['phone']) )
		{
			$post['BillingPhone'] = $post['DeliveryPhone'] = $customer['phone'];
		}

		$post['CustomerEmail'] = $customer['email'];
		$post['Basket'] = $order['basket']; // dunno yet
		
		// these are more or less fixed...
		$post['AllowGiftAid'] = '0';
		$post['ApplyAVSCV2'] = '0';
		$post['Apply3DSecure'] = '0';
		$post['Profile'] = 'NORMAL';
		
		return $this->DoRequest('payment',$post);
	}

	/**
	 * AUTHORIZE transaction
	 * 
	 * @param	array	$order (amount, description, related_vps_tx_id,
	 * 					related_vendor_tx_code, related_security_key,
	 * 					[apply_avs_cv2])
	 * @return	array	$result (Success, VPSProtocol, Status, StatusDetail,
	 * 					VPSTxId, TxAuthNo, SecurityKey, AVSCV2, AddressResult,
	 * 					PostcodeResult, CV2Result)
	 */
	public function txAuthorize( $order ) 
	{
		$this->last_tx_code = $this->_clean_input($this->sage_vnd.'_'.gmdate("Ymd_His").'_authorize', FALSE, TRUE);
		
		$post = array();
		$post['TxType'] = 'AUTHORIZE';
		$post['Vendor'] = $this->sage_vnd;
		$post['VendorTxCode'] = $this->last_tx_code;
		$post['Amount'] = $order['amount'];
		$post['Currency'] = $this->currency;
		$post['Description'] = $order['description'];
		$post['RelatedVPSTxId'] = $order['related_vps_tx_id'];
		$post['RelatedVendorTxCode'] = $order['related_vendor_tx_code']; 
		$post['RelatedSecurityKey'] = $order['related_security_key'];
		
		if ( isset($order['apply_avs_cv2']) AND !empty($order['apply_avs_cv2']) )
		{
			$post['ApplyAVSCV2'] = $order['apply_avs_cv2'];
		}
		
		return $this->DoRequest('authorize',$post);
	}
	
	/**
	 * RELEASE transaction
	 * 
	 * @param	array	$order (vps_tx_id, tx_auth_no, release_amount)
	 * @return	array	$result (Success, VPSProtocol, Status, StatusDetail)
	 */
	public function txRelease ( $order ) {
		$this->last_tx_code = $this->_clean_input($this->sage_vnd.'_'.gmdate("Ymd_His").'_release', FALSE, TRUE);
		
		$post = array();
		$post['TxType'] = 'RELEASE';
		$post['Vendor'] = $this->sage_vnd;
		$post['VendorTxCode'] = $this->last_tx_code;
		$post['VPSTxId'] = $order['vps_tx_id'];
		$post['SecurityKey'] = $order['security_key'];
		$post['TxAuthNo'] = $order['tx_auth_no'];
		$post['ReleaseAmount'] = $order['release_amount'];
		
		return $this->DoRequest('release',$post);
	}
	
	/**
	 * REPEAT transaction
	 * 
	 * @param	array	$order (amount, description, related_vps_tx_id,
	 * 					related_vendor_tx_code, related_security_key,
	 * 					related_tx_auth_no, [cv2])
	 * @return	array	$result (Success, VPSProtocol, Status, StatusDetail,
	 * 					VPSTxId, TxAuthNo, SecurityKey, AVSCV2, AddressResult,
	 * 					PostcodeResult, CV2Result)
	 */
	function txRepeat(array $order) {
		$this->last_tx_code = $this->_clean_input($this->sage_vnd.'_'.gmdate("Ymd_His").'_repeat', FALSE, TRUE);
		
		$post = array();
		$post['TxType'] = 'REPEAT';
		$post['Vendor'] = $this->sage_vnd;
		$post['VendorTxCode'] = $this->last_tx_code;
		$post['Amount'] = $order['amount'];
		$post['Currency'] = $this->currency;
		$post['Description'] = $order['description'];
		$post['RelatedVPSTxId'] = $order['related_vps_tx_id'];
		$post['RelatedVendorTxCode'] = $order['related_vendor_tx_code']; 
		$post['RelatedSecurityKey'] = $order['related_security_key'];
		$post['RelatedTxAuthNo'] = $order['related_tx_auth_no'];
		
		if ( isset($order['cv2']) AND !empty($order['cv2']) )
		{
			$post['CV2'] = $order['cv2'];
		}
		
		return $this->DoRequest('repeat',$post);
	}
	
	/**
	 * REFUND transaction
	 * 
	 * @param	array	$order (amount, description, related_vps_tx_id,
	 * 					related_vendor_tx_code, related_security_key,
	 * 					related_tx_auth_no)
	 * @return	array	$result (Success, VPSProtocol, Status, StatusDetail,
	 * 					VPSTxId, TxAuthNo)
	 */
	public function txRefund( $order )
	{
		$this->last_tx_code = $this->_clean_input($this->sage_vnd.'_'.gmdate("Ymd_His").'_refund', FALSE, TRUE);
		
		$post = array();
		$post['TxType'] = 'REFUND';
		$post['Vendor'] = $this->sage_vnd;
		$post['VendorTxCode'] = $this->last_tx_code;
		$post['Amount'] = $order['amount'];
		$post['Currency'] = $this->currency;
		$post['Description'] = $order['description'];
		$post['RelatedVPSTxId'] = $order['related_vps_tx_id'];
		$post['RelatedVendorTxCode'] = $order['related_vendor_tx_code']; 
		$post['RelatedSecurityKey'] = $order['related_security_key'];
		$post['RelatedTxAuthNo'] = $order['related_tx_auth_no'];
		
		return $this->DoRequest('refund',$post);
	}
	
	/**
	 * DIRECTREFUND transaction
	 * 
	 * @param	array	$order (amount, description)
	 * @param	array	$card (holder, number, [start_date], expiry_date,
	 * 					[issue_number], type)
	 * @return	array	$result (Success, VPSProtocol, Status, StatusDetail,
	 * 					VPSTxId, TxAuthNo)
	 */
	public function txDirectRefund ( $order, $card )
	{
		$this->last_tx_code = $this->_clean_input($this->sage_vnd.'_'.gmdate("Ymd_His").'_directrefund', FALSE, TRUE);
		
		$post = array();
		$post['TxType'] = 'DIRECTREFUND';
		$post['Vendor'] = $this->sage_vnd;
		$post['VendorTxCode'] = $this->last_tx_code;
		$post['Amount']	 = $order['amount'];
		$post['Currency'] = $this->currency;
		$post['Description'] = $order['description'];
		$post['CardHolder'] = $card['holder'];
		$post['CardNumber'] = $card['number'];
		
		if ( isset($card['start_date']) AND !empty($card['start_date']) )
		{
			$post['StartDate'] = $card['start_date'];
		}
		
		$post['ExpiryDate'] = $card['expiry_date'];
		
		if ( isset($card['issue_number']) AND !empty($card['issue_number']) )
		{
			$post['IssueNumber'] = $card['issue_number'];
		}
		$post['CardType'] = $card['type'];
		$post['AccountType'] = 'E';
		
		return $this->DoRequest('directrefund',$post);
	}
	
	/**
	 * MANUAL transaction
	 * 
	 * @param	array	$customer (first_name, last_name, address1, [address2],
	 * 					city, postcode, country, [state], [phone], email)
	 * @param	array	$card (holder, number, [start_date], expiry_date,
	 * 					[issue_number], [cv2], type)
	 * @param	array	$order (amount, description, basket)
	 * @return	array	$result (Success, VPSProtocol, Status, StatusDetail,
	 * 					VPSTxId, TxAuthNo, SecurityKey)
	 */
	public function txManual( $customer, $card, $order)
	{
		$this->last_tx_code = $this->_clean_input($this->sage_vnd.'_'.gmdate("Ymd_His").'_manual', FALSE, TRUE);
		
		$post = array();
		$post['TxType'] = 'MANUAL';
		$post['Vendor'] = $this->sage_vnd;
		$post['VendorTxCode'] = $this->last_tx_code;
		$post['Amount'] = $order['amount'];
		$post['Currency'] = $this->currency;
		$post['Description'] = $order['description'];
		$post['CardHolder'] = $card['holder'];
		$post['CardNumber'] = $card['number'];
		
		if ( isset($card['start_date']) AND !empty($card['start_date']) )
		{
			$post['StartDate'] = $card['start_date'];
		}
		
		$post['ExpiryDate'] = $card['expiry_date'];
		
		if ( isset($card['issue_number']) AND !empty($card['issue_number']) )
		{
			$post['IssueNumber'] = $card['issue_number'];
		}
		
		if ( isset($card['cv2']) AND !empty($card['cv2']) )
		{
			$post['CV2'] = $card['cv2'];
		}
		
		$post['CardType'] = $card['type'];
		$post['BillingFirstnames'] = $post['DeliveryFirstnames'] = $customer['first_name'];
		$post['BillingSurname'] = $post['DeliverySurname'] = $customer['last_name'];
		$post['BillingAddress1'] = $post['DeliveryAddress1'] = $customer['address1'];
		
		if ( isset($customer['address2']) AND !empty($customer['address2']) )
		{
			$post['BillingAddress2']= $post['DeliveryAddress2'] = $customer['address2'];
		}
		
		$post['BillingCity'] = $post['DeliveryCity'] = $customer['city'];
		$post['BillingPostCode'] = $post['DeliveryPostCode'] = $customer['postcode'];
		$post['BillingCountry'] = $post['DeliveryCountry'] = $customer['country'];
		
		if ( isset($customer['state']) AND !empty($customer['state']) ) 
		{
			$post['BillingState'] = $post['DeliveryState'] = $customer['state'];
		}
		
		if ( isset($customer['phone']) AND !empty($customer['phone']) )
		{
			$post['BillingPhone'] = $post['DeliveryPhone'] = $customer['phone'];
		}
		
		if ( isset($customer['email']) AND !empty($customer['email']) )
		{
			$post['CustomerEMail'] = $customer['email'];
		}
		
		if ( isset($order['basket']) AND !empty($order['basket']) )
		{
			$post['Basket'] = $order['basket'];
		}
		
		$post['GiftAidPayment'] = '0';
		
		if ( isset($customer['ip_address']) AND !empty($customer['ip_address']) )
		{
			$post['ClientIPAddress'] = $customer['ip_address'];
		}
		
		$post['AccountType'] = 'E';
		
		return $this->DoRequest('manual',$post);
	}
	
	/**
	 * CANCEL transaction
	 * 
	 * @param	array	$order (vps_tx_id, security_key)
	 * @return	array	$result (Success, VPSProtocol, Status, StatusDetail)
	 */
	public function txCancel ( $order ) {
		$this->last_tx_code = $this->_clean_input($this->sage_vnd.'_'.gmdate("Ymd_His").'_cancel', FALSE, TRUE);
		
		$post = array();
		$post['TxType'] = 'CANCEL';
		$post['Vendor'] = $this->sage_vnd;
		$post['VendorTxCode'] = $this->last_tx_code;
		$post['VPSTxId'] = $order['vps_tx_id'];
		$post['SecurityKey'] = $order['security_key'];
		
		return $this->DoRequest('cancel',$post);
	}
	
	/**
	 * ABORT transaction
	 * 
	 * @param array $order (vps_tx_id, security_key, tx_auth_no)
	 * @return array $result (Success, VPSProtocol, Status, StatusDetail)
	 */
	public function txAbort ( $order )
	{
		$this->last_tx_code = $this->_clean_input($this->sage_vnd.'_'.gmdate("Ymd_His").'_abort', FALSE, TRUE);
		
		$post = array();
		$post['TxType'] = 'ABORT';
		$post['Vendor'] = $this->sage_vnd;
		$post['VendorTxCode'] = $this->last_tx_code;
		$post['VPSTxId'] = $order['vps_tx_id'];
		$post['SecurityKey'] = $order['security_key'];
		$post['TxAuthNo'] = $order['tx_auth_no'];
		
		return $this->DoRequest('abort',$post);
	}
	/**
	 * VOID transaction
	 * 
	 * @param	array	$order (vps_tx_id, security_key, tx_auth_no)
	 * @return	array	$result (Success, VPSProtocol, Status, StatusDetail)
	 */
	function txVoid(array $order) {
		$this->last_tx_code = $this->_clean_input($this->sage_vnd.'_'.gmdate("Ymd_His").'_void',FALSE,TRUE);
		$post = array();
		$post['TxType'] = 'VOID';
		$post['Vendor'] = $this->sage_vnd;
		$post['VendorTxCode'] = $this->last_tx_code;
		$post['VPSTxId'] = $order['vps_tx_id'];
		$post['SecurityKey'] = $order['security_key'];
		$post['TxAuthNo'] = $order['tx_auth_no'];
		
		return $this->DoRequest('void',$post);
	}

	/**
	 * Return the specified key from the supplied array, or a default if not set
	 *
	 * (This I've included, as it's not in KO2 & I liked it from KO3)
	 *
	 * @param array $array
	 * @param string $key
	 * @param null $default
	 * @return null
	 */
	private function kget ( $array, $key, $default = null )
	{
		return isset($array[$key]) ? $array[$key] : $default;
	}

	/**
	 * Do some cleaning of input...apparently.
	 *
	 * @param mixed $dirty
	 * @param bool $is_num
	 * @param bool $is_tx
	 * @return string
	 */
	private function _clean_input( $dirty, $is_num = FALSE, $is_tx = FALSE )
	{
		$high = FALSE;
		if ( $is_num )
		{
			$allow = '0123456789.';
		}
		elseif ( $is_tx )
		{
			$allow = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_.';
		}
		else
		{
			$high = TRUE;
			$allow = ' ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789.,\'/{}@():?-_&�$=%~<>*+"';
		}
		
		$clean = '';
		$alen = strlen($allow);

		for ( $i=0; $i < strlen($dirty); $i++ )
		{
			$char = substr($dirty,$i,1);
			if ( strspn($char, $allow, 0, $alen) > 0 )
			{
				$clean .= $char;
			}
			elseif ( $high AND (bin2hex($char) > 190) )
			{
				$clean .= $char;
			}
		}

		return ltrim($clean);
	}

	/**
	 * Actually perform a request against SagePay.
	 *
	 * N.b. this was called _call in the orginal version, which is confusing as it's close to __call.
	 *
	 * @param string $operation
	 * @param mixed $request
	 * @return array
	 */
	private function DoRequest ($operation, $request)
	{
		// make sure the $operation is in lowercase to match the correct index of services
		$operation	= strtolower($operation);
		// check that operation is listed... just in case
		if ( !isset($this->list_svc[$this->sage_svc][$operation]) ) {
			return array(
				'Success'		=> FALSE,
				'Status'		=> 'OPERATION',
				'StatusDetail'	=> 'Operation "'.$operation.'" does not exist or is not supported in the current mode'
			);
		}
		
		// compile the URL to use
		$url = $this->sage_url . $this->list_svc[$this->sage_svc][$operation];
		
		// $request should be url encoded and stringified (&field=value)
		$postdata = 'VPSProtocol=' . $this->sage_ver;
		
		foreach ($request as $f=>$v) {
			$postdata .= '&' . $f . '=' . urlencode($v);
		}
		
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);
		curl_setopt($ch, CURLOPT_HEADER, FALSE);
		curl_setopt($ch, CURLOPT_POST, TRUE);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 1);
		$response = curl_exec($ch);
		
		// check server's response
		if (!$response)
		{
			// cURL error...
			return array(
				'Success'		=> FALSE,
				'Status'		=> 'CURL',
				'StatusDetails'	=> curl_error($ch)
			);

			// let's not leave the handle open...
			curl_close($ch);
		}
		else
		{
			// cURL executed fine: close the handle
			curl_close($ch);
			return array_merge(array('Success'=>TRUE), $this->_parse_response($response));
		}
	}

	/**
	 * Load the config
	 *
	 * @param  array $config
	 * @return void
	 */
	private function initialize ($config)
	{
		// Default the mode to SIM if it's not set
		$mode = $this->kget($config, 'mode', 'sim');

		// know which mode we are using
		$this->sage_svc	= $mode != 'test' AND $mode != 'live' ? 'sim' : 'sys';
		$this->sage_url	= $this->list_url[$mode];

		// keep the VendorTxCode
		$this->sage_vnd = $this->kget($config, 'vendor_name');

		// set the Currency and NotificationURL
		$this->currency	= $this->kget($config, 'currency', self::DEFAULT_CURRENCY);
		$this->notify	= $this->kget($config, 'notify');
		
		// timeout
		$this->timeout = (int)$this->kget($config, 'timeout', self::DEFAULT_TIMEOUT);
	}

	/**
	 * Parse the response from SagePay
	 *
	 * @param string $response
	 * @return array
	 */
	private function _parse_response ( $response )
	{
		$lines = explode("\r\n",$response);
		$result = array();
		foreach($lines as $line)
		{
			if ( trim($line)!='' )
			{
				list($f,$v) = explode('=',$line,2);
				$result[$f] = trim($v);
			}
		}
		return $result;
	}
}