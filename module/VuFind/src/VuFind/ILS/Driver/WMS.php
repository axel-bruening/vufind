<?php
/**
 * WorldShare Management System Driver
 *
 * PHP version 5
 *
 * Copyright (C) Villanova University 2007.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category VuFind2
 * @package  ILS_Drivers
 * @author   Karen Coombs <librarywebchic@gmail.com>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:building_an_ils_driver Wiki
 */
namespace VuFind\ILS\Driver;

use OCLC\Auth\WSKey;
use OCLC\Auth\AccessToken;
use VuFind\Exception\ILS as ILSException,
	VuFind\Config\Locator as ConfigLocator;
use Zend\Session\Container;

/**
 * WorldShare Management System Driver
 *
 * @category VuFind2
 * @package  ILS_Drivers
 * @author   Karen Coombs <librarywebchic@gmail.com>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:building_an_ils_driver Wiki
 */
class WMS extends AbstractBase implements \VuFindHttp\HttpServiceAwareInterface
{
	/**
	 * HTTP service
	 *
	 * @var \VuFindHttp\HttpServiceInterface
	 */
	protected $httpService = null;
	
	/**
	 * Date formatting object
	 *
	 * @var \VuFind\Date\Converter
	 */
	protected $dateFormat;
	
	/**
	 * Constructor
	 *
	 */
	public function __construct($config, $recordLoader, \VuFind\Date\Converter $dateConverter) {
		$this->wcdiscoveryConfig = $config;
	
		$this->recordLoader = $recordLoader;
		
		if ($this->wcdiscoveryConfig){
			$this->wskey = $this->wcdiscoveryConfig->General->wskey;
			$this->secret = $this->wcdiscoveryConfig->General->secret;
			$this->institution = $this->wcdiscoveryConfig->General->institution;
		} elseif ($this->config) {
			$this->wskey = $this->config->General->wskey;
			$this->secret = $this->config->General->secret;
			$this->institution = $this->config->General->institution;
		//TODO: want an elseif statement here for the MultiDriver backend
		} else {
			throw new Exception('You do not have the proper properties setup in either the WorldCatDiscovery or WMS ini files');
		}
		
		$this->dateFormat = $dateConverter;
		
		$this->session = new Container('WorldCatDiscovery');
	}
	
	/**
	 * Set the HTTP service to be used for HTTP requests.
	 *
	 * @param HttpServiceInterface $service HTTP service
	 *
	 * @return void
	 */
	public function setHttpService(\VuFindHttp\HttpServiceInterface $service)
	{
		$this->httpService = $service;
	}
	
    /**
     * Initialize the driver.
     *
     * Validate configuration and perform all resource-intensive tasks needed to
     * make the driver active.
     *
     * @throws ILSException
     * @return void
     */
	
    public function init()
    {
        $this->loadPickupLocations($this->config['Catalog']['pickupLocationsFile']);
    }
    
    /**
     * Get or create an access token.
     *
     * @return string
     */
    protected function getAccessToken()
    {
    	if (empty($this->session->accessToken) || $this->session->accessToken->isExpired()){
    		$options = array(
    				'services' => array('WorldCatDiscoveryAPI', 'WMS_Availability', 'WMS_NCIP','refresh_token')
    		);
    		$wskey = new WSKey($this->wskey, $this->secret, $options);
    		$accessToken = $wskey->getAccessTokenWithClientCredentials($this->institution, $this->institution);
    		$this->session->accessToken = $accessToken;
    	}
    	return $this->session->accessToken;
    }
    
    /**
     * Loads pickup location information from configuration file.
     *
     * @param string $filename File to load from
     *
     * @throws ILSException
     * @return void
     */
    public function loadPickupLocations($filename)
    {
    	// Load pickup locations file:
    	$pickupLocationsFile
    	= ConfigLocator::getConfigPath($filename, 'config/vufind');
    	if (!file_exists($pickupLocationsFile)) {
    		throw new ILSException(
    				"Cannot load pickup locations file: {$pickupLocationsFile}."
    		);
    	}
    	if (($handle = fopen($pickupLocationsFile, "r")) !== false) {
    		while (($data = fgetcsv($handle)) !== false) {
    			$this->pickupLocations[$data[0]][] = array(
    					'locationID' => $data[1],
    					'locationDisplay' => $data[2]
    			);
    		}
    		fclose($handle);
    	}
    }
    
    /**
     * Send an NCIP request.
     *
     * @param string $xml XML request document
     *
     * @return object     SimpleXMLElement parsed from response
     */
    protected function sendRequest($xml)
    {
    	$url = 'https://' . $this->institution . '.share.worldcat.org/ncip/circ-patron';
    	// Make the NCIP request:
    	try {
    		$client = $this->httpService
    		->createClient($url);
    		// Set timeout value
    		$timeout = isset($this->config['Catalog']['http_timeout'])
    		? $this->config['Catalog']['http_timeout'] : 30;
    		$client->setOptions(array('timeout' => $timeout));
    		$client->setHeaders(array(
    				"Authorization" => 'Bearer ' . $this->getAccessToken()->getValue()
    		));
    		$client->setRawBody($xml);
    		$client->setEncType('application/xml; "charset=utf-8"');
    		$result = $client->setMethod('POST')->send();
    	} catch (\Exception $e) {
    		throw new ILSException($e->getMessage());
    	}
    
    	if (!$result->isSuccess()) {
    		throw new ILSException('HTTP error');
    	}
    
    	// Process the NCIP response:
    	$response = $result->getBody();
    	$result = @simplexml_load_string($response);
    	if (is_a($result, 'SimpleXMLElement')) {
    		$result->registerXPathNamespace('ns1', 'http://www.niso.org/2008/ncip');
    		return $result;
    	} else {
    		throw new ILSException("Problem parsing XML");
    	}
    }

    /**
     * Get Status
     *
     * This is responsible for retrieving the status information of a certain
     * record.
     *
     * @param string $id The record id to retrieve the holdings for
     *
     * @return mixed     On success, an associative array with the following keys:
     * id, availability (boolean), status, location, reserve, callnumber.
     */
    public function getStatus($id)
    {
    	$holding = array();
    	if ($this->recordLoader->load($id, 'WorldCatDiscovery')->getOffer($this->institution)){
	    	// Make the request to WMS_Availability web service
	    	$wmsAvailabilityRequest = "https://worldcat.org/circ/availability/sru/service?x-registryId=" . $this->institution;
	    	$wmsAvailabilityRequest .= "&query=no:" . $id;
	    	
	    	try {
	    		$client = $this->httpService
	    		->createClient($wmsAvailabilityRequest);
	    		$adapter = new \Zend\Http\Client\Adapter\Curl();
	    		$client->setAdapter($adapter);
	    		$client->setHeaders(array(
	    				"Authorization" => 'Bearer ' . $this->getAccessToken()->getValue()
	    			));
	    		$wmsAvailabilityResponse = $client->setMethod('GET')->send();
	    	} catch (\Exception $e) {
	    		throw new ILSException($e->getMessage());
	    	}
	    	$availabilityXML = simplexml_load_string($wmsAvailabilityResponse->getContent());
	    	$copies = $availabilityXML->xpath('//holdings/holding');

	    	foreach ($copies as $copy){
	    		$holding[] = array('availability' => ($copy->circulations->circulation->availableNow->attributes()->value == "1") ? true : false,
	    				'status' => ($copy->circulations->circulation->availableNow->attributes()->value == "1") ? 'On the shelf' : (string) $copy->circulations->circulation->reasonUnavailable,
	    				'location' => (isset($copy->temporaryLocation)) ? $copy->temporaryLocation : $copy->localLocation .  ' ' . $copy->shelvingLocation,
	    				'reserve' => 'No',
	    				'callnumber' => $copy->callNumber,
	    				'duedate' => $this->dateFormat->convertToDisplayDate("m-d-y", $copy->circulations->circulation->availabilityDate),
	    				'number' => $copy->copyNumber,
	    				'item_id' => $copy->circulations->circulation->itemId,
	    				'barcode' => $copy->circulations->circulation->itemId,
	    				'requests_placed' => $copy->circulations->circulation->onHold->attributes()->value
	    		);
	    	}
    	}
    	
        return $holding;
    }

    /**
     * Get Statuses
     *
     * This is responsible for retrieving the status information for a
     * collection of records.
     *
     * @param array $ids The array of record ids to retrieve the status for
     *
     * @return mixed     An array of getStatus() return values on success.
     */
    public function getStatuses($ids)
    {
        $items = array();
        foreach ($ids as $id) {
            $items[] = $this->getStatus($id);
        }
        return $items;
    }

    /**
     * Get Holding
     *
     * This is responsible for retrieving the holding information of a certain
     * record.
     *
     * @param string $id     The record id to retrieve the holdings for
     * @param array  $patron Patron data
     *
     * @return mixed     On success, an associative array with the following keys:
     * id, availability (boolean), status, location, reserve, callnumber, duedate,
     * number, barcode.
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getHolding($id, array $patron = null)
    {
        return $this->getStatus($id);
    }    

    /**
     * Get Purchase History
     *
     * This is responsible for retrieving the acquisitions history data for the
     * specific record (usually recently received issues of a serial).
     *
     * @param string $id The record id to retrieve the info for
     *
     * @return mixed     An array with the acquisitions data on success.
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getPurchaseHistory($id)
    {
    	return array();
    }
    
    /**
     * Patron Login
     *
     * This is responsible for authenticating a patron against the catalog.
     *
     * @param string $username The patron username
     * @param string $password The patron password
     *
     * @return mixed           Associative array of patron info on successful login,
     * null on unsuccessful login.
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function patronLogin($username, $password)
    {
    	$patronInfo = array(
    			"principalID" => $this->config->Test->principalID,
    			"principalIDNS" => $this->config->Test->principalIDNS,
    			"institution" => $this->institution
    	);
        return $patronInfo;
    }
    
    /**
     * Get Patron Transactions
     *
     * This is responsible for retrieving all transactions (i.e. checked out items)
     * by a specific patron.
     *
     * @param array $patron The patron array from patronLogin
     *
     * @throws \VuFind\Exception\Date
     * @throws ILSException
     * @return array        Array of the patron's transactions on success.
     */
    public function getMyTransactions($patron)
    {
    	$extras = array('<ns1:LoanedItemsDesired/>');
    	$request = $this->getLookupUserRequest(
    			$patron['principalID'],
    			$this->institution, $extras
    	);
    	$response = $this->sendRequest($request);
    
    	$retVal = array();
    	$list = $response->xpath('ns1:LookupUserResponse/ns1:LoanedItem');
    
    	foreach ($list as $current) {
    		$current->registerXPathNamespace('ns1', 'http://www.niso.org/2008/ncip');
    		$tmp = $current->xpath('ns1:DateDue');
    		$due = strtotime((string)$tmp[0]);
    		$due = date("l, d-M-y h:i a", $due);
    		$title = $current->xpath('ns1:Title');
    		$item_id = $current->xpath('ns1:ItemId/ns1:ItemIdentifierValue');
    		$bib_id = $current->xpath(
    				'ns1:Ext/ns1:BibliographicDescription/' .
    				'ns1:BibliographicRecordId/ns1:BibliographicRecordIdentifier'
    		);
    		// Hack to account for bibs from other non-local institutions
    		// temporarily until consortial functionality is enabled.
    		if ((string)$bib_id[0]) {
    			$tmp = (string)$bib_id[0];
    		} else {
    			$tmp = "1";
    		}
    		$retVal[] = array(
    				'id' => $tmp,
    				'duedate' => $due,
    				'title' => (string)$title[0],
    				'item_id' => (string)$item_id[0],
    				'renewable' => true,
    		);
    	}
    
    	return $retVal;
    }
    
    /**
     * Get Patron Fines
     *
     * This is responsible for retrieving all fines by a specific patron.
     *
     * @param array $patron The patron array from patronLogin
     *
     * @throws \VuFind\Exception\Date
     * @throws ILSException
     * @return mixed        Array of the patron's fines on success.
     */
    public function getMyFines($patron)
    {
    	$extras = array('<ns1:UserFiscalAccountDesired/>');
    	$request = $this->getLookupUserRequest(
    			$patron['principalID'],
    			$this->institution, $extras
    	);
    	$response = $this->sendRequest($request);
    
    	$list = $response->xpath(
    			'ns1:LookupUserResponse/ns1:UserFiscalAccount/ns1:AccountDetails'
    	);
    
    	$fines = array();
    	$balance = 0;
    	foreach ($list as $current) {
    
    		$current->registerXPathNamespace('ns1', 'http://www.niso.org/2008/ncip');
    
    		$tmp = $current->xpath(
    				'ns1:FiscalTransactionInformation/ns1:Amount/ns1:MonetaryValue'
    		);
    		$amount = (string)$tmp[0];
    		$tmp = $current->xpath('ns1:AccrualDate');
    		$date = (string)$tmp[0];
    		$tmp = $current->xpath(
    				'ns1:FiscalTransactionInformation/ns1:FiscalTransactionType'
    		);
    		$desc = (string)$tmp[0];
    		/* This is an item ID, not a bib ID, so it's not actually useful:
    		 $tmp = $current->xpath(
    		 'ns1:FiscalTransactionInformation/ns1:ItemDetails/' .
    		 'ns1:ItemId/ns1:ItemIdentifierValue'
    		 );
    		 $id = (string)$tmp[0];
    		*/
    		$id = '';
    		$balance += $amount;
    		$fines[] = array(
    				'amount' => $amount,
    				'balance' => $balance,
    				'checkout' => '',
    				'fine' => $desc,
    				'duedate' => '',
    				'createdate' => $date,
    				'id' => $id
    		);
    	}
    	return $fines;
    }
    
    /**
     * Get Patron Holds
     *
     * This is responsible for retrieving all holds by a specific patron.
     *
     * @param array $patron The patron array from patronLogin
     *
     * @throws \VuFind\Exception\Date
     * @throws ILSException
     * @return array        Array of the patron's holds on success.
     */
    public function getMyHolds($patron)
    {
    	$extras = array('<ns1:RequestedItemsDesired/>');
    	$request = $this->getLookupUserRequest(
    			$patron['patron']['principalID'],
    			$this->institution,
    			$patron['patron_agency_id'], $extras
    	);
    	$response = $this->sendRequest($request);
    
    	$retVal = array();
    	$list = $response->xpath('ns1:LookupUserResponse/ns1:RequestedItem');
    	foreach ($list as $current) {
    		$id = $current->xpath(
    				'ns1:Ext/ns1:BibliographicDescription/' .
    				'ns1:BibliographicRecordId/ns1:BibliographicRecordIdentifier'
    		);
    		// (unused variable): $created = $current->xpath('ns1:DatePlaced');
    		$title = $current->xpath('ns1:Title');
    		$pos = $current->xpath('ns1:HoldQueuePosition');
    		$requestType = $current->xpath('ns1:RequestType');
    		$requestId = $current->xpath('ns1:RequestId/ns1:RequestIdentifierValue');
    		$itemId = $current->xpath('ns1:ItemId/ns1:ItemIdentifierValue');
    		$pickupLocation = $current->xpath('ns1:PickupLocation');
    		$expireDate = $current->xpath('ns1:PickupExpiryDate');
    		$expireDate = strtotime((string)$expireDate[0]);
    		$expireDate = date("l, d-M-y", $expireDate);
    		$requestType = (string)$requestType[0];
    		// Only return requests of type Hold or Recall. Callslips/Stack
    		// Retrieval requests are fetched using getMyStorageRetrievalRequests
    		if ($requestType === "Hold" or $requestType === "Recall") {
    			$retVal[] = array(
    					'id' => (string)$id[0],
    					'create' => '',
    					'expire' => $expireDate,
    					'title' => (string)$title[0],
    					'position' => (string)$pos[0],
    					'requestId' => (string)$requestId[0],
    					'item_id' => (string)$itemId[0],
    					'location' => (string)$pickupLocation[0],
    			);
    		}
    	}
    
    	return $retVal;
    }
    
    /**
     * Get Renew Details
     *
     * This function returns the item id as a string which is then used
     * as submitted form data in checkedOut.php. This value is then extracted by
     * the RenewMyItems function.
     *
     * @param array $checkOutDetails An array of item data
     *
     * @return string Data for use in a form field
     */
    public function getRenewDetails($checkOutDetails)
    {
    	$renewDetails = $checkOutDetails['item_id'];
    	return $renewDetails;
    }
    
    /**
     * Place Hold
     *
     * Attempts to place a hold or recall on a particular item and returns
     * an array with result details or throws an exception on failure of support
     * classes
     *
     * @param array $details An array of item and patron data
     *
     * @throws ILSException
     * @return mixed An array of data on the request including
     * whether or not it was successful
     */
    public function placeHold($details)
    {
    	$userID = $details['patron']['principalID'];
    	$bibId = $details['bib_id'];
    	$itemId = $details['item_id'];
    	$pickUpLocation = $details['pickUpLocation'];
    	$holdType = $details['holdtype'];
    	$lastInterestDate = $details['requiredBy'];
    	$lastInterestDate = substr($lastInterestDate, 6, 10) . '-'
    			. substr($lastInterestDate, 0, 5);
    	$lastInterestDate = $lastInterestDate . "T00:00:00.000Z";
    
    	$request = $this->getRequest(
    			$userId, $this->institution, $bibId, $itemId,
    			$holdType, "Item", $lastInterestDate, $pickUpLocation
    	);
    	$response = $this->sendRequest($request);
    	$success = $response->xpath(
    			'ns1:RequestItemResponse/ns1:ItemId/ns1:ItemIdentifierValue'
    	);
    
    	if ($success) {
    		return array(
    				'success' => true,
    				"sysMessage" => 'Request Successful.'
    		);
    	} else {
    		return array(
    				'success' => false,
    				"sysMessage" => 'Request Not Successful.'
    		);
    	}
    }
    
    /**
     * Cancel Holds
     *
     * Attempts to Cancel a hold or recall on a particular item. The
     * data in $cancelDetails['details'] is determined by getCancelHoldDetails().
     *
     * @param array $cancelDetails An array of item and patron data
     *
     * @return array               An array of data on each request including
     * whether or not it was successful.
     */
    public function cancelHolds($cancelDetails)
    {
    	$count = 0;
    	$userID = $details['patron']['principalID'];
    	$details = $cancelDetails['details'];
    	$response = array();
    
    	foreach ($details as $cancelDetails) {
    		list($itemId, $requestId) = explode("|", $cancelDetails);
    		$request = $this->getCancelRequest(
    				$userID, $this->institution, $requestId, "Hold"
    		);
    		$cancelRequestResponse = $this->sendRequest($request);
    		$userId = $cancelRequestResponse->xpath(
    				'ns1:CancelRequestItemResponse/' .
    				'ns1:UserId/ns1:UserIdentifierValue'
    		);
    		$itemId = (string)$itemId;
    		if ($userId) {
    			$count++;
    			$response[$itemId] = array(
    					'success' => true,
    					'status' => 'hold_cancel_success',
    			);
    		} else {
    			$response[$itemId] = array(
    					'success' => false,
    					'status' => 'hold_cancel_fail',
    			);
    		}
    	}
    	$result = array('count' => $count, 'items' => $response);
    	return $result;
    }
    
    /**
     * Get Cancel Hold Details
     *
     * This function returns the item id and recall id as a string
     * separated by a pipe, which is then submitted as form data in Hold.php. This
     * value is then extracted by the CancelHolds function.  item id is used as the
     * array key in the response.
     *
     * @param array $holdDetails An array of item data
     *
     * @return string Data for use in a form field
     */
    public function getCancelHoldDetails($holdDetails)
    {
    	$cancelDetails = $holdDetails['id']."|".$holdDetails['requestId'];
    	return $cancelDetails;
    }
    
    /**
     * Renew My Items
     *
     * Function for attempting to renew a patron's items.  The data in
     * $renewDetails['details'] is determined by getRenewDetails().
     *
     * @param array $renewDetails An array of data required for renewing items
     * including the Patron ID and an array of renewal IDS
     *
     * @return array              An array of renewal information keyed by item ID
     */
    public function renewMyItems($renewDetails)
    {
    	$details = array();
    	foreach ($renewDetails['details'] as $renewId) {
    		$request = $this->getRenewRequest(
    				$userID, $this->institution, $renewId
    		);
    		$response = $this->sendRequest($request);
    		$dueDate = $response->xpath('ns1:RenewItemResponse/ns1:DateDue');
    		if ($dueDate) {
    			$tmp = $dueDate;
    			$newDueDate = (string)$tmp[0];
    			$tmp = split("T", $newDueDate);
    			$splitDate = $tmp[0];
    			$splitTime = $tmp[1];
    			$details[$renewId] = array(
    					"success" => true,
    					"new_date" => $splitDate,
    					"new_time" => rtrim($splitTime, "Z"),
    					"item_id" => $renewId,
    			);
    
    		} else {
    			$details[$renewId] = array(
    					"success" => false,
    					"item_id" => $renewId,
    			);
    		}
    	}
    
    	return array(null, "details" => $details);
    }
    
    /**
     * Helper function to build the request XML to cancel a request:
     *
     * @param string $userID           UserID
     * @param string $requestId Id of the request to cancel
     * @param string $type      The type of request to cancel (Hold, etc)
     *
     * @return string           NCIP request XML
     */
    protected function getCancelRequest($userID, $institution, $requestId, $type)
    {
    	return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
    			'<ns1:NCIPMessage xmlns:ns1="http://www.niso.org/2008/ncip" ' .
    			'ns1:version="http://www.niso.org/schemas/ncip/v2_0/imp1/' .
    			'xsd/ncip_v2_0.xsd">' .
    			'<ns1:CancelRequestItem>' .
    			'<ns1:UserId>' .
    			'<ns1:AgencyId>' .
    			$institution .
    			'</ns1:AgencyId>' .
    			'<ns1:UserIdentifierValue>' .
    			$userID .
    			'</ns1:UserIdentifierValue>' .
    			'</ns1:UserId>' .
    			'<ns1:RequestId>' .
    			'<ns1:RequestIdentifierValue>' .
    			htmlspecialchars($requestId) .
    			'</ns1:RequestIdentifierValue>' .
    			'</ns1:RequestId>' .
    			'<ns1:RequestType>' .
    			htmlspecialchars($type) .
    			'</ns1:RequestType>' .
    			'</ns1:CancelRequestItem>' .
    			'</ns1:NCIPMessage>';
    }
    
    /**
     * Helper function to build the request XML to request an item
     * (Hold, Storage Retrieval, etc)
     *
     * @param string $userID           UserID
     * @param string $bibId            Bib Id of item to request
     * @param string $itemId           Id of item to request
     * @param string $requestType      Type of the request (Hold)
     * @param string $requestScope     Level of request (title, item, etc)
     * @param string $lastInterestDate Last date interested in item
     * @param string $pickupLocation   Code of location to pickup request
     *
     * @return string          NCIP request XML
     */
    protected function getRequest($userID, $institution, $bibId, $itemId,
    		$requestType, $requestScope, $lastInterestDate, $pickupLocation = null
    ) {
    	return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
    			'<ns1:NCIPMessage xmlns:ns1="http://www.niso.org/2008/ncip" ' .
    			'ns1:version="http://www.niso.org/schemas/ncip/v2_0/imp1/' .
    			'xsd/ncip_v2_0.xsd">' .
    			'<ns1:RequestItem>' .
    			'<ns1:InitiationHeader>' .
    			'<ns1:FromAgencyId>' .
    			'<ns1:AgencyId>' .
    			$institution .
    			'</ns1:AgencyId>' .
    			'</ns1:FromAgencyId>' .
    			'<ns1:ToAgencyId>' .
    			'<ns1:AgencyId>' .
    			$institution .
    			'</ns1:AgencyId>' .
    			'</ns1:ToAgencyId>' .
    			'</ns1:InitiationHeader>' .
    			'<ns1:UserId>' .
    			'<ns1:AgencyId>' .
    			$institution .
    			'</ns1:AgencyId>' .
    			'<ns1:UserIdentifierValue>' .
    			$userID .
    			'</ns1:UserIdentifierValue>' .
    			'</ns1:UserId>' .
    			'<ns1:BibliographicId>' .
    			'<ns1:BibliographicRecordId>' .
    			'<ns1:AgencyId>' .
    			$institution .
    			'</ns1:AgencyId>' .
    			'<ns1:BibliographicRecordIdentifier>' .
    			htmlspecialchars($bibId) .
    			'</ns1:BibliographicRecordIdentifier>' .
    			'</ns1:BibliographicRecordId>' .
    			'</ns1:BibliographicId>' .
    			'<ns1:ItemId>' .
    			'<ns1:ItemIdentifierValue>' .
    			htmlspecialchars($itemId) .
    			'</ns1:ItemIdentifierValue>' .
    			'</ns1:ItemId>' .
    			'<ns1:RequestType>' .
    			htmlspecialchars($requestType) .
    			'</ns1:RequestType>' .
    			'<ns1:RequestScopeType ' .
    			'ns1:Scheme="http://www.niso.org/ncip/v1_0/imp1/schemes' .
    			'/requestscopetype/requestscopetype.scm">' .
    			htmlspecialchars($requestScope) .
    			'</ns1:RequestScopeType>' .
    			'<ns1:PickupLocation>' .
    			htmlspecialchars($pickupLocation) .
    			'</ns1:PickupLocation>' .
    			'<ns1:PickupExpiryDate>' .
    			htmlspecialchars($lastInterestDate) .
    			'</ns1:PickupExpiryDate>' .
    			'</ns1:RequestItem>' .
    			'</ns1:NCIPMessage>';
    }
    
    /**
     * Helper function to build the request XML to renew an item:
     *
     * @param string $userID   UserID
     * @param string $itemId   Id of item to renew
     *
     * @return string          NCIP request XML
     */
    protected function getRenewRequest($userID, $institution, $itemId)
    {
    	return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
    			'<ns1:NCIPMessage xmlns:ns1="http://www.niso.org/2008/ncip" ' .
    			'ns1:version="http://www.niso.org/schemas/ncip/v2_0/imp1/' .
    			'xsd/ncip_v2_0.xsd">' .
    			'<ns1:RenewItem>' .
		    	'<ns1:InitiationHeader>' .
		    	'<ns1:FromAgencyId >' .
		    	'<ns1:AgencyId ncip:Scheme="http://oclc.org/ncip/schemes/agencyid.scm">' .
		    	$institution .
		    	'</ns1:AgencyId>' .
		    	'</ns1:FromAgencyId>' .
		    	'<ns1:ToAgencyId >' .
		    	'<ns1:AgencyId ncip:Scheme="http://oclc.org/ncip/schemes/agencyid.scm">' .
		    	$institution .
		    	'</ns1:AgencyId>' .
		    	'</ns1:ToAgencyId>' .
		    	'</ns1:InitiationHeader>' .
		    	'<ns1:UserId>' .
		    	'<ns1:AgencyId>' .
		    	$institution .
		    	'</ns1:AgencyId>' .
		    	'<ns1:UserIdentifierValue>' .
		    	$userID .
		    	'</ns1:UserIdentifierValue>' .
		    	'</ns1:UserId>' .
    			'<ns1:ItemId>' .
    			'<ns1:ItemIdentifierValue>' .
    			htmlspecialchars($itemId) .
    			'</ns1:ItemIdentifierValue>' .
    			'</ns1:ItemId>' .
    			'</ns1:RenewItem>' .
    			'</ns1:NCIPMessage>';
    }
    
    /**
     * Helper function to build the request XML to log in a user
     * and/or retrieve loaned items / request information
     *
     * @param string $userID         UserID
     * @param string $extras         Extra elements to include in the request
     *
     * @return string          NCIP request XML
     */
    protected function getLookupUserRequest($userID, $institution, $extras) {
    	$ret = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
    			'<ns1:NCIPMessage xmlns:ns1="http://www.niso.org/2008/ncip" ' .
    			'ns1:version="http://www.niso.org/schemas/ncip/v2_0/imp1/' .
    			'xsd/ncip_v2_0.xsd">' .
    			'<ns1:LookupUser>';
    
    	$ret .=
    	'<ns1:InitiationHeader>' .
    	'<ns1:FromAgencyId >' .
    	'<ns1:AgencyId ncip:Scheme="http://oclc.org/ncip/schemes/agencyid.scm">' .
    	$institution .
    	'</ns1:AgencyId>' .
    	'</ns1:FromAgencyId>' .
    	'<ns1:ToAgencyId >' .
    	'<ns1:AgencyId ncip:Scheme="http://oclc.org/ncip/schemes/agencyid.scm">' .
    	$institution .
    	'</ns1:AgencyId>' .
    	'</ns1:ToAgencyId>' .
    	'</ns1:InitiationHeader>';
    
    	$ret .=
    	'<ns1:UserId>' .
    	'<ns1:UserIdentifierValue>' .
    	$userID .
    	'</ns1:UserIdentifierValue>' .
    	'</ns1:UserId>' .
    	implode('', $extras) .
    	'</ns1:LookupUser>' .
    	'</ns1:NCIPMessage>';
    
    	return $ret;
    }
}