<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

class SagePay {
	private	$list_url = array(
		// URL to use is chosen from 
		'sim'	=> 'https://test.sagepay.com/Simulator/VSPServerGateway.asp?Service=',
		'test'	=> 'https://test.sagepay.com/gateway/service/',
		'live'	=> 'https://live.sagepay.com/gateway/service/'
	);
	private	$list_svc = array(
		// service strings that complement the Simulator URL
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
			'cancel'		=> 'VendorCancelTx'
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
	// the VPS version string
	private	$sage_ver = '2.23';
	// the VendorTxCode assigned
	private	$sage_vnd;
	// the currency to be used in the transactions
	private	$currency;
	// the notification URL to be used throughout
	private	$notify;
	// should contain one of the URLs defined in $list_url
	private	$sage_url;
	// should be either sim or sys to access the indexed $list_services
	private	$sage_svc;
	
	public	$last_tx_code = '';
	
	function SagePay($config=array()) {
		if (is_array($config)&&!empty($config)) {
			$this->initialize($config);
		} else {
			return FALSE;
		}
	}
	function initialize($config) {
		// MODE should be set in the $config, otherwise use SIM
		$mode = isset($config['mode']) ? $config['mode'] : 'sim';
		// know which mode we are using
		$this->sage_svc	= (($mode!='test'&&$mode!='live') ? 'sim' : 'sys');
		$this->sage_url	= $this->list_url[$mode];
		// keep the VendorTxCode
		$this->sage_vnd = isset($config['vendor_name']) ? $config['vendor_name'] : '';
		// set the Currency and NotificationURL
		$this->currency	= isset($config['currency']) ? $config['currency'] : 'GBP';
		$this->notify	= isset($config['notify']) ? $config['notify'] : '';
	}
	function notification_url($url=FALSE) {
		if ($url) {
			$this->notify = $url;
			return TRUE;
		} else {
			return $this->notify;
		}
	}
//	GENERAL FUNCTIONS (these should not be called directly)
	function _clean_input($dirty, $is_num=FALSE, $is_tx=FALSE) {
		$high	= FALSE;
		if ($is_num) {
			$allow		= '0123456789.';
		} else {
			if ($is_tx) {
				$allow	= 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_.';
			} else {
				$high	= TRUE;
				$allow	= ' ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789.,\'/{}@():?-_&�$=%~<>*+"';
			}
		}
		$clean	= '';
		$alen	= strlen($allow);
		for ($i=0;$i<strlen($dirty);$i++) {
			$char	= substr($dirty,$i,1);
			if (strspn($char,$allow,0,$alen)>0) {
				$clean .= $char;
			} elseif ($high&&(bin2hex($char)>190)) {
				$clean .= $char;
			}
		}
		return ltrim($clean);
	}
	function _parse_response($response) {
		$lines = explode("\r\n",$response);
		$result = array();
		foreach($lines as $line) {
			if (trim($line)!='') {
				list($f,$v) = explode('=',$line,2);
				$result[$f] = trim($v);
			}
		}
		return $result;
	}
	function _call($operation, $request) {
		// make sure the $operation is in lowercase to match the correct index of services
		$operation	= strtolower($operation);
		// check that operation is listed... just in case
		if (!isset($this->list_svc[$this->sage_svc][$operation])) {
			return array(
				'Success'		=> FALSE,
				'Status'		=> 'OPERATION',
				'StatusDetail'	=> 'Operation "'.$operation.'" does not exist or is not supported in the current mode'
			);
		}
		
		// compile the URL to use
		$url		= $this->sage_url.$this->list_svc[$this->sage_svc][$operation];
		// $request should be url encoded and stringified (&field=value)
		$postdata	= 'VPSProtocol='.$this->sage_ver;
		foreach($request as $f=>$v) {
			$postdata .= '&'.$f.'='.urlencode($v);
		}
		
		// let's cURL, but no longer than 1 minute
		set_time_limit(60);
		$ch			= curl_init();
		curl_setopt($ch, CURLOPT_URL,				$url);
		/* not sure about these 2... leave out for now */
//		curl_setopt($ch, CURLOPT_FAILONERROR,		1);
//		curl_setopt($ch, CURLOPT_FOLLOWLOCATION,	1);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER,	1);
		curl_setopt($ch, CURLOPT_TIMEOUT,			30);
		curl_setopt($ch, CURLOPT_HEADER,			0);
		curl_setopt($ch, CURLOPT_POST,				1);
		curl_setopt($ch, CURLOPT_POSTFIELDS,		$postdata);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER,	FALSE);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,	1);
		$response	= curl_exec($ch);
		
		// check server's response
		if (!$response) {
			// cURL error...
			return array(
				'Success'		=> FALSE,
				'Status'		=> 'CURL',
				'StatusDetails'	=> curl_error($ch)
			);
			// let's not leave the handle open...
			curl_close($ch);
		} else {
			// cURL executed fine: close the handle
			curl_close($ch);
			return array_merge(array('Success'=>TRUE),$this->_parse_response($response));
		}
	}
	
//	SERVICE-SPECIFIC FUNCTIONS (the ones you actually call)
	/**
	 * CALLBACK acknowledgement of notification received
	 * 
	 * @param	array	$options (status, status_detail, redirect_url)
	 * @return	string	$response {response string that should be output in reply to VPS callback}
	 */
	function txCallback($options) {
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
	function txPayment(array $customer, array $order) {
		$this->last_tx_code				= $this->_clean_input($this->sage_vnd.'_'.gmdate("Ymd_His").'_payment',FALSE,TRUE);
		$post	= array();
		$post['TxType']					= 'PAYMENT';
		$post['Vendor']					= $this->sage_vnd;
		$post['VendorTxCode']			= $this->last_tx_code;
		$post['Amount']					= $order['amount'];
		$post['Currency']				= $this->currency;
		$post['Description']			= $order['description'];
		$post['NotificationURL']		= $this->notify;
		$post['BillingFirstnames']		= $post['DeliveryFirstnames']	= $customer['first_name'];
		$post['BillingSurname']			= $post['DeliverySurname']		= $customer['last_name'];
		$post['BillingAddress1']		= $post['DeliveryAddress1']		= $customer['address1'];
		if (isset($customer['address2'])&&!empty($customer['address2'])) {
			$post['BillingAddress2']	= $post['DeliveryAddress2']		= $customer['address2'];
		}
		$post['BillingCity']			= $post['DeliveryCity']			= $customer['city'];
		$post['BillingPostCode']		= $post['DeliveryPostCode']		= $customer['postcode'];
		$post['BillingCountry']			= $post['DeliveryCountry']		= $customer['country'];
		if (isset($customer['state'])&&!empty($customer['state'])) {
			$post['BillingState']		= $post['DeliveryState']		= $customer['state'];
		}
		if (isset($customer['phone'])&&!empty($customer['phone'])) {
			$post['BillingPhone']		= $post['DeliveryPhone']		= $customer['phone'];
		}
		$post['CustomerEmail']			= $customer['email'];
		$post['Basket']					= $order['basket']; // dunno yet
		// these are more or less fixed...
		$post['AllowGiftAid']			= '0';
		$post['ApplyAVSCV2']			= '0';
		$post['Apply3DSecure']			= '0';
		$post['Profile']				= 'NORMAL';
		
		return $this->_call('payment',$post);
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
	function txAuthorize(array $order) {
		$this->last_tx_code			= $this->_clean_input($this->sage_vnd.'_'.gmdate("Ymd_His").'_authorize',FALSE,TRUE);
		$post = array();
		$post['TxType']				= 'AUTHORIZE';
		$post['Vendor']				= $this->sage_vnd;
		$post['VendorTxCode']		= $this->last_tx_code;
		$post['Amount']				= $order['amount'];
		$post['Currency']			= $this->currency;
		$post['Description']		= $order['description'];
		$post['RelatedVPSTxId']		= $order['related_vps_tx_id'];
		$post['RelatedVendorTxCode']= $order['related_vendor_tx_code']; 
		$post['RelatedSecurityKey']	= $order['related_security_key'];
		if (isset($order['apply_avs_cv2'])&&!empty($order['apply_avs_cv2'])) {
			$post['ApplyAVSCV2']	= $order['apply_avs_cv2'];
		}
		
		return $this->_call('authorize',$post);
	}
	/**
	 * RELEASE transaction
	 * 
	 * @param	array	$order (vps_tx_id, tx_auth_no, release_amount)
	 * @return	array	$result (Success, VPSProtocol, Status, StatusDetail)
	 */
	function txRelease(array $order) {
		$this->last_tx_code		= $this->_clean_input($this->sage_vnd.'_'.gmdate("Ymd_His").'_release',FALSE,TRUE);
		$post = array();
		$post['TxType']			= 'RELEASE';
		$post['Vendor']			= $this->sage_vnd;
		$post['VendorTxCode']	= $this->last_tx_code;
		$post['VPSTxId']		= $order['vps_tx_id'];
		$post['SecurityKey']	= $order['security_key'];
		$post['TxAuthNo']		= $order['tx_auth_no'];
		$post['ReleaseAmount']	= $order['release_amount'];
		
		return $this->_call('release',$post);
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
		$this->last_tx_code			= $this->_clean_input($this->sage_vnd.'_'.gmdate("Ymd_His").'_repeat',FALSE,TRUE);
		$post = array();
		$post['TxType']				= 'REPEAT';
		$post['Vendor']				= $this->sage_vnd;
		$post['VendorTxCode']		= $this->last_tx_code;
		$post['Amount']				= $order['amount'];
		$post['Currency']			= $this->currency;
		$post['Description']		= $order['description'];
		$post['RelatedVPSTxId']		= $order['related_vps_tx_id'];
		$post['RelatedVendorTxCode']= $order['related_vendor_tx_code']; 
		$post['RelatedSecurityKey']	= $order['related_security_key'];
		$post['RelatedTxAuthNo']	= $order['related_tx_auth_no'];
		if (isset($order['cv2'])&&!empty($order['cv2'])) {
			$post['CV2']			= $order['cv2'];
		}
		
		return $this->_call('repeat',$post);
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
	function txRefund(array $order) {
		$this->last_tx_code			= $this->_clean_input($this->sage_vnd.'_'.gmdate("Ymd_His").'_refund',FALSE,TRUE);
		$post = array();
		$post['TxType']				= 'REFUND';
		$post['Vendor']				= $this->sage_vnd;
		$post['VendorTxCode']		= $this->last_tx_code;
		$post['Amount']				= $order['amount'];
		$post['Currency']			= $this->currency;
		$post['Description']		= $order['description'];
		$post['RelatedVPSTxId']		= $order['related_vps_tx_id'];
		$post['RelatedVendorTxCode']= $order['related_vendor_tx_code']; 
		$post['RelatedSecurityKey']	= $order['related_security_key'];
		$post['RelatedTxAuthNo']	= $order['related_tx_auth_no'];
		
		return $this->_call('refund',$post);
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
	function txDirectRefund(array $order, array $card) {
		$this->last_tx_code			= $this->_clean_input($this->sage_vnd.'_'.gmdate("Ymd_His").'_directrefund',FALSE,TRUE);
		$post = array();
		$post['TxType']				= 'DIRECTREFUND';
		$post['Vendor']				= $this->sage_vnd;
		$post['VendorTxCode']		= $this->last_tx_code;
		$post['Amount']				= $order['amount'];
		$post['Currency']			= $this->currency;
		$post['Description']		= $order['description'];
		$post['CardHolder']			= $card['holder'];
		$post['CardNumber']			= $card['number'];
		if (isset($card['start_date'])&&!empty($card['start_date'])) {
			$post['StartDate']		= $card['start_date'];
		}
		$post['ExpiryDate']			= $card['expiry_date'];
		if (isset($card['issue_number'])&&!empty($card['issue_number'])) {
			$post['IssueNumber']	= $card['issue_number'];
		}
		$post['CardType']			= $card['type'];
		$post['AccountType']		= 'E';
		
		return $this->_call('directrefund',$post);
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
	function txManual(array $customer, array $card, array $order) {
		$this->last_tx_code			= $this->_clean_input($this->sage_vnd.'_'.gmdate("Ymd_His").'_manual',FALSE,TRUE);
		$post = array();
		$post['TxType']				= 'MANUAL';
		$post['Vendor']				= $this->sage_vnd;
		$post['VendorTxCode']		= $this->last_tx_code;
		$post['Amount']				= $order['amount'];
		$post['Currency']			= $this->currency;
		$post['Description']		= $order['description'];
		$post['CardHolder']			= $card['holder'];
		$post['CardNumber']			= $card['number'];
		if (isset($card['start_date'])&&!empty($card['start_date'])) {
			$post['StartDate']		= $card['start_date'];
		}
		$post['ExpiryDate']			= $card['expiry_date'];
		if (isset($card['issue_number'])&&!empty($card['issue_number'])) {
			$post['IssueNumber']	= $card['issue_number'];
		}
		if (isset($card['cv2'])&&!empty($card['cv2'])) {
			$post['CV2']			= $card['cv2'];
		}
		$post['CardType']			= $card['type'];
		$post['BillingFirstnames']	= $post['DeliveryFirstnames']	= $customer['first_name'];
		$post['BillingSurname']		= $post['DeliverySurname']		= $customer['last_name'];
		$post['BillingAddress1']	= $post['DeliveryAddress1']		= $customer['address1'];
		if (isset($customer['address2'])&&!empty($customer['address2'])) {
			$post['BillingAddress2']= $post['DeliveryAddress2']		= $customer['address2'];
		}
		$post['BillingCity']		= $post['DeliveryCity']			= $customer['city'];
		$post['BillingPostCode']	= $post['DeliveryPostCode']		= $customer['postcode'];
		$post['BillingCountry']		= $post['DeliveryCountry']		= $customer['country'];
		if (isset($customer['state'])&&!empty($customer['state'])) {
			$post['BillingState']	= $post['DeliveryState']		= $customer['state'];
		}
		if (isset($customer['phone'])&&!empty($customer['phone'])) {
			$post['BillingPhone']	= $post['DeliveryPhone']		= $customer['phone'];
		}
		if (isset($customer['email'])&&!empty($customer['email'])) {
			$post['CustomerEMail']	= $customer['email'];
		}
		if (isset($order['basket'])&&!empty($order['basket'])) {
			$post['Basket']			= $order['basket'];
		}
		$post['GiftAidPayment']		= '0';
		if (isset($customer['ip_address'])&&!empty($customer['ip_address'])) {
			$post['ClientIPAddress']= $customer['ip_address'];
		}
		$post['AccountType']		= 'E';
		
		
		return $this->_call('manual',$post);
	}
	/**
	 * CANCEL transaction
	 * 
	 * @param	array	$order (vps_tx_id, security_key)
	 * @return	array	$result (Success, VPSProtocol, Status, StatusDetail)
	 */
	function txCancel(array $order) {
		$this->last_tx_code		= $this->_clean_input($this->sage_vnd.'_'.gmdate("Ymd_His").'_cancel',FALSE,TRUE);
		$post = array();
		$post['TxType']			= 'CANCEL';
		$post['Vendor']			= $this->sage_vnd;
		$post['VendorTxCode']	= $this->last_tx_code;
		$post['VPSTxId']		= $order['vps_tx_id'];
		$post['SecurityKey']	= $order['security_key'];
		
		return $this->_call('cancel',$post);
	}
	/**
	 * ABORT transaction
	 * 
	 * @param	array	$order (vps_tx_id, security_key, tx_auth_no)
	 * @return	array	$result (Success, VPSProtocol, Status, StatusDetail)
	 */
	function txAbort(array $order) {
		$this->last_tx_code		= $this->_clean_input($this->sage_vnd.'_'.gmdate("Ymd_His").'_abort',FALSE,TRUE);
		$post = array();
		$post['TxType']			= 'ABORT';
		$post['Vendor']			= $this->sage_vnd;
		$post['VendorTxCode']	= $this->last_tx_code;
		$post['VPSTxId']		= $order['vps_tx_id'];
		$post['SecurityKey']	= $order['security_key'];
		$post['TxAuthNo']		= $order['tx_auth_no'];
		
		return $this->_call('abort',$post);
	}
	/**
	 * VOID transaction
	 * 
	 * @param	array	$order (vps_tx_id, security_key, tx_auth_no)
	 * @return	array	$result (Success, VPSProtocol, Status, StatusDetail)
	 */
	function txVoid(array $order) {
		$this->last_tx_code		= $this->_clean_input($this->sage_vnd.'_'.gmdate("Ymd_His").'_void',FALSE,TRUE);
		$post = array();
		$post['TxType']			= 'VOID';
		$post['Vendor']			= $this->sage_vnd;
		$post['VendorTxCode']	= $this->last_tx_code;
		$post['VPSTxId']		= $order['vps_tx_id'];
		$post['SecurityKey']	= $order['security_key'];
		$post['TxAuthNo']		= $order['tx_auth_no'];
		
		return $this->_call('void',$post);
	}
}