<?php

/*
 * Copyright (C) 2010
 * Licensed to CiviCRM under the Academic Free License version 3.0.
 *
 * Written and contributed by Parvez Husain (http://www.parvez.me)
 *
 */

/**
 *
 * @package CRM
 * @author Marshal Newrock <marshal@idealso.com>
 * $Id: IntuitQuickbooks.php 26018 2010-01-25 09:00:59Z deepak $
 */

/* NOTE:
 * When looking up response codes in the Intuit Quickbooks API, they
 * begin at one, so always delete one from the "Position in Response"
 */

require_once 'CRM/Core/Payment.php';


class org_civicrm_payment_intuitquickbooks extends CRM_Core_Payment {
  static protected $_mode = null;
    

  static protected $_params = array();
    
  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  static private $_singleton = null;
  /**
   * Constructor
   *
   * @param string $mode the mode of operation: live or test
   *
   * @return void
   */
  function __construct( $mode, &$paymentProcessor ) {
    
    $this->_mode             = $mode;
    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName    = ts('Intuit Quickbooks');
    

    $config = CRM_Core_Config::singleton();
    $this->_setParam( 'applicationLogin'  , $paymentProcessor['user_name']  );
    $this->_setParam( 'connectionTicket'  , $paymentProcessor['password']  );
    $this->_setParam( 'applicationID'     , $paymentProcessor['signature'] );
    $this->_setParam( 'applicationURL'    , $paymentProcessor['url_site'] );
    if ( $this->_mode == 'live' ) {
      $this->_setParam( 'pemFile' , '/tmp/pems/intuit.pem' );
    } else {
      $this->_setParam( 'pemFile' , '/tmp/pems/intuit-test.pem' );
    }

  }

  /**
   * This function checks to see if we have the right config values 
   *
   * @return string the error message if any
   * @public
   */
  function checkConfig( ) {
    $error = array();
    if ( empty( $this->_paymentProcessor['user_name'] ) ) {
      $error[] = ts( 'Application Login is not set for this payment processor' );
    }
        
    if ( empty( $this->_paymentProcessor['password'] ) ) {
      $error[] = ts( 'Connection Ticket is not set for this payment processor' );
    }

    if ( empty( $this->_paymentProcessor['signature'] ) ) {
      $error[] = ts( 'Application ID is not set for this payment processor' );
    }

    if ( empty( $this->_paymentProcessor['url_site'] ) ) {
      $error[] = ts( 'Application URL is not set for this payment processor' );
    }

    if ( ! empty( $error ) ) {
      return implode( '<p>', $error );
    } else {
      return null;
    }
  }


  /**
   * Submit an Automated Recurring Billing subscription
   *
   * @param  array $params assoc array of input parameters for this transaction
   * @return array the result in a nice formatted array (or an error object)
   * @public
   */
  function doDirectPayment( &$params ) {   

    if ( ! defined( 'CURLOPT_SSLCERT' ) ) {
      return self::error( 9001, 'Intuit Quickbooks requires curl with SSL support' );
    }

    foreach ( $params as $field => $value ) {
      $this->_setParam( $field, $value );
    }      
      
    $PHP_QBMSXML[0] = '<?xml version="1.0" ?>
        <?qbmsxml version="4.5"?>
        <QBMSXML>
        <SignonMsgsRq>
        <SignonAppCertRq>
        <ClientDateTime>'.date('Y-m-d\TH:i:s').'</ClientDateTime>
        <ApplicationLogin>'.$this->_getParam( 'applicationLogin' ).'</ApplicationLogin>
        <ConnectionTicket>'.$this->_getParam( 'connectionTicket' ).'</ConnectionTicket>
        </SignonAppCertRq>
        </SignonMsgsRq>
        </QBMSXML>';
      
    // submit to intuit
    $gatewayUrl =  $this->_getParam( 'applicationURL' );
    $response = $this->sendToIntuit( $gatewayUrl, $PHP_QBMSXML[0], $this->_getParam( 'pemFile' ) );
     
    //Go ahead and get the session ticket
    //Find the location of the start tag
    $PHP_TempString = strstr($response, "<SessionTicket>");
    $PHP_EndLocation = strpos($PHP_TempString, "</SessionTicket>");
    $PHP_SessionTicket = substr($PHP_TempString, 15, $PHP_EndLocation - 15);
      
    if($params['is_recur'] == 1) {
      $PHP_QBMSXML[1] = '<?xml version="1.0"?>
          <?qbmsxml version="4.5"?>
          <QBMSXML>
          <SignonMsgsRq>
           <SignonTicketRq>
            <ClientDateTime>'.date('Y-m-d\TH:i:s').'</ClientDateTime>
            <SessionTicket>'.$PHP_SessionTicket.'</SessionTicket>
           </SignonTicketRq>
          </SignonMsgsRq>
          <QBMSXMLMsgsRq>
           <CustomerCreditCardWalletAddRq>
               <CustomerID>Customer-'.$params['contactID'].'</CustomerID>
               <CreditCardNumber>'.$this->_getParam( 'credit_card_number' ).'</CreditCardNumber>
               <ExpirationMonth>'.str_pad( $this->_getParam( 'month' ), 2, '0', STR_PAD_LEFT ).'</ExpirationMonth>
               <ExpirationYear>'.$this->_getParam( 'year' ).'</ExpirationYear>
               <NameOnCard>'.$this->_getParam( 'billing_first_name' ).' '.$this->_getParam( 'billing_last_name' ).'</NameOnCard>
               <CreditCardAddress>'.$this->_getParam( 'street_address' ).'</CreditCardAddress>
               <CreditCardPostalCode>'.$this->_getParam( 'postal_code' ).'</CreditCardPostalCode>
          </CustomerCreditCardWalletAddRq>
         </QBMSXMLMsgsRq>
         </QBMSXML>';

      $response = $this->sendToIntuit( $gatewayUrl, $PHP_QBMSXML[1], $this->_getParam( 'pemFile' ) );
        
      //Go ahead and get the Wallet ID
      //Find the location of the start tag
      $PHP_TempString = strstr($response, "<WalletEntryID>");
      $PHP_EndLocation = strpos($PHP_TempString, "</WalletEntryID>");
      $PHP_WalletId = substr($PHP_TempString, 15, $PHP_EndLocation - 15);
      $PHP_TempString = strstr($response, "<IsDuplicate>");
      $PHP_EndLocation = strpos($PHP_TempString, "</IsDuplicate>");
      $PHP_DuplWallet = substr($PHP_TempString, 13, $PHP_EndLocation - 13);
      if($PHP_DuplWallet == 'true') {
        $params['invoice_id'] = $PHP_WalletId.'-'.rand();
      }else {
        $params['invoice_id'] = $PHP_WalletId;
      }
        
      $tomorrow = date( 'Y-m-d',mktime(0,0,0,date("m"),date("d")+1,date("Y")));
      if($params['frequency_unit'] == 'day') {
        $freqInterval = $params['frequency_interval'];
      }else if($params['frequency_unit'] == 'week') {
        if($params['frequency_interval']) {
          $interval = $params['frequency_interval'] * 7;
        }else {
          $interval = 7;
        }
        $freqInterval = $interval;
      }else if($params['frequency_unit'] == 'month') {
        $d = date("d")+1;
        $freqInterval = "0 0 0 ".$d." * ?";
      }else if($params['frequency_unit'] == 'year') {
        $d = date("d")+1;
        $freqInterval = "0 0 0 ".$d." ".date("m")." ?";
      }
        
      $PHP_QBMSXML[2] = '<?xml version="1.0"?>
        <?qbmsxml version="4.5"?>
        <QBMSXML>
        <SignonMsgsRq>
         <SignonTicketRq>
          <ClientDateTime>'.date('Y-m-d\TH:i:s').'</ClientDateTime>
          <SessionTicket>'.$PHP_SessionTicket.'</SessionTicket>
         </SignonTicketRq>
        </SignonMsgsRq>
        <QBMSXMLMsgsRq>
         <CustomerScheduledBillingAddRq>
           <CustomerID>Customer-'.$params['contactID'].'</CustomerID>
           <WalletEntryID>'.$PHP_WalletId.'</WalletEntryID>
           <PaymentType>CreditCard</PaymentType>
           <Amount>'.$this->_getParam( 'amount' ).'</Amount>
           <StartDate>'.$tomorrow.'</StartDate>
           <FrequencyExpression>'.$freqInterval.'</FrequencyExpression>
           <ScheduledBillingStatus>Active</ScheduledBillingStatus>
         </CustomerScheduledBillingAddRq>
        </QBMSXMLMsgsRq>
        </QBMSXML>';

      $response = $this->sendToIntuit( $gatewayUrl, $PHP_QBMSXML[2], $this->_getParam( 'pemFile' ) );

      //Go ahead and get the session ticket
      //Find the location of the start tag
      $PHP_TempString = strstr($response, "<ScheduledBillingID>");
      $PHP_EndLocation = strpos($PHP_TempString, "</ScheduledBillingID>");
      $PHP_ScheduleBillId = substr($PHP_TempString, 20, $PHP_EndLocation - 20);
        
      $params['processor_id'] = $PHP_ScheduleBillId;
      $params['trxn_id'] = $PHP_ScheduleBillId;
      self::processRecurContribution( $component = 'contribute', $params);
      $recur = new CRM_Contribute_DAO_ContributionRecur( );
      $recur->id = $params['contributionRecurID'];
      $recur->find( true ); 
      $subscriptionPaymentStatus = 'START';
      require_once 'CRM/Contribute/BAO/ContributionPage.php';
      CRM_Contribute_BAO_ContributionPage::recurringNofify( $subscriptionPaymentStatus, $params['contactID'],
                                                            $params['contributionPageID'], $recur );
    }else {
      $PHP_QBMSXML[1] = '<?xml version="1.0" ?>
        <?qbmsxml version="4.5"?>
        <QBMSXML>
        <SignonMsgsRq>
        <SignonTicketRq>
        <ClientDateTime>'.date('Y-m-d\TH:i:s').'</ClientDateTime>
        <SessionTicket>'.$PHP_SessionTicket.'</SessionTicket>
        </SignonTicketRq>
        </SignonMsgsRq>
        <QBMSXMLMsgsRq>
        <CustomerCreditCardChargeRq>
        <TransRequestID>'.$this->_getParam( 'invoiceID' ).'</TransRequestID>
        <CreditCardNumber>'.$this->_getParam( 'credit_card_number' ).'</CreditCardNumber>
        <ExpirationMonth>'.str_pad( $this->_getParam( 'month' ), 2, '0', STR_PAD_LEFT ).'</ExpirationMonth>
        <ExpirationYear>'.$this->_getParam( 'year' ).'</ExpirationYear>
        <IsECommerce>true</IsECommerce>
        <Amount>'.$this->_getParam( 'amount' ).'</Amount>
        <NameOnCard>'.$this->_getParam( 'billing_first_name' ).' '.$this->_getParam( 'billing_last_name' ).'</NameOnCard>
        <CreditCardAddress>'.$this->_getParam( 'street_address' ).'</CreditCardAddress>
        <CreditCardPostalCode>'.$this->_getParam( 'postal_code' ).'</CreditCardPostalCode>
        <CommercialCardCode></CommercialCardCode>
        <SalesTaxAmount>0.00</SalesTaxAmount>
        <CardSecurityCode>'.$this->_getParam( 'cvv2' ).'</CardSecurityCode>
        </CustomerCreditCardChargeRq>
        </QBMSXMLMsgsRq>
        </QBMSXML>';

      // submit to intuit
      $response = $this->sendToIntuit( $gatewayUrl, $PHP_QBMSXML[1], $this->_getParam( 'pemFile' ) );
        
      $xml = simplexml_load_string($response);
      if ( $xml->QBMSXMLMsgsRs->CustomerCreditCardChargeRs['statusCode'] != "0" ) {
        return self::error( $xml->QBMSXMLMsgsRs->CustomerCreditCardChargeRs['statusCode'], 
                            $xml->QBMSXMLMsgsRs->CustomerCreditCardChargeRs['statusMessage'] );
      }

      $params['trxn_id'] = (string) $xml->QBMSXMLMsgsRs->CustomerCreditCardChargeRs->CreditCardTransID;
    }
    return $params;
  }

  /**
   * Get the value of a field if set
   *
   * @param string $field the field
   * @return mixed value of the field, or empty string if the field is
   * not set
   */
  function _getParam( $field ) {
    return CRM_Utils_Array::value( $field, $this->_params, '' );
  }

  /** 
   * singleton function used to manage this object 
   * 
   * @param string $mode the mode of operation: live or test
   *
   * @return object 
   * @static 
   * 
   */ 
  static function &singleton( $mode, &$paymentProcessor ) {
    $processorName = $paymentProcessor['name'];
    if (self::$_singleton[$processorName] === null ) {
      self::$_singleton[$processorName] = new org_civicrm_payment_intuitquickbooks( $mode, $paymentProcessor );
    }
    return self::$_singleton[$processorName];
  }


  function &error( $errorCode = null, $errorMessage = null ) {
    $e =& CRM_Core_Error::singleton( );
    if ( $errorCode ) {
      $e->push( $errorCode, 0, null, $errorMessage );
    } else {
      $e->push( 9001, 0, null, 'Unknowns System Error.' );
    }
    return $e;
  }

  /**
   * Set a field to the specified value.  Value must be a scalar (int,
   * float, string, or boolean)
   *
   * @param string $field
   * @param mixed $value
   * @return bool false if value is not a scalar, true if successful
   */ 
  function _setParam( $field, $value ) {
    if ( ! is_scalar($value) ) {
      return false;
    } else {
      $this->_params[$field] = $value;
    }
  }

  /**
   * sendToIntuit function used to send the params to intuit and get the response
   */
  function sendToIntuit( $url, $parameters, $pemFile) {
    $server =  $url;
    $PHP_QBMSXML = $parameters;
    $result = null;
    if ( function_exists( 'curl_init' ) ) {
      $PHP_Header[] = "Content-type: application/x-qbmsxml";
      $PHP_Header[] = "Content-length: ".strlen($PHP_QBMSXML);

      $submit = curl_init();
      curl_setopt($submit, CURLOPT_POST, 1);
      curl_setopt($submit, CURLOPT_RETURNTRANSFER, 1);
      curl_setopt($submit, CURLOPT_CUSTOMREQUEST, 'POST');
      curl_setopt($submit, CURLOPT_URL, $server);
      curl_setopt($submit, CURLOPT_TIMEOUT, 10);
      curl_setopt($submit, CURLOPT_HTTPHEADER, $PHP_Header);
      curl_setopt($submit, CURLOPT_POSTFIELDS, $PHP_QBMSXML);
      curl_setopt($submit, CURLOPT_VERBOSE, 1);
      curl_setopt($submit, CURLOPT_SSL_VERIFYPEER, 1);
      curl_setopt($submit, CURLOPT_SSLCERT, $pemFile);
      curl_setopt($submit, CURLOPT_SSL_VERIFYPEER, false);
      $response = curl_exec($submit);
      if ( !$response ) {
        return self::error( curl_errno($submit), curl_error($submit) );
      }
      curl_close( $submit );
    } 
    return $response;
  }


  /**
   * processRecurContribution function to make the transaction entry and financial transaction entry
   */
  function processRecurContribution( $component = 'contribute', $params ) 
  {
    require_once 'CRM/Contribute/PseudoConstant.php';
    $contributionStatus = CRM_Contribute_PseudoConstant::contributionStatus( );
    $completed['contribution_status_id'] = array_search( 'Completed', $contributionStatus );

    CRM_Core_DAO::setFieldValue( 'CRM_Contribute_DAO_Contribution', $params['contributionID'], 'trxn_id', $params['trxn_id'] );
    CRM_Core_DAO::setFieldValue( 'CRM_Contribute_DAO_Contribution', $params['contributionID'], 'contribution_status_id', $completed );
        
    CRM_Core_DAO::setFieldValue( 'CRM_Contribute_DAO_ContributionRecur', $params['contributionRecurID'], 
                                 'processor_id', $params['processor_id'] );
    CRM_Core_DAO::setFieldValue( 'CRM_Contribute_DAO_ContributionRecur', $params['contributionRecurID'], 
                                 'invoice_id', $params['invoice_id'] );
    $trxnParams = array(
                        'contribution_id'   => $params['contributionID'],
                        'trxn_date'         => date( 'YmdHis' ),
                        'trxn_type'         => 'Debit',
                        'total_amount'      => $params['amount'],
                        'fee_amount'        => CRM_Utils_Array::value( 'fee_amount', $params['trxn_id']  ),
                        'net_amount'        => CRM_Utils_Array::value( 'net_amount', $params['trxn_id'], $params['amount'] ),
                        'currency'          => $params['currencyID'],
                        'payment_processor' => $this->_paymentProcessor['name'],
                        'trxn_id'           => $params['trxn_id'],
                        'trxn_result_code'  => NULL,
                        );
    require_once 'CRM/Core/BAO/FinancialTrxn.php';
    $trxn =& CRM_Core_BAO_FinancialTrxn::create( $trxnParams );
  }
}         
