<?php

//The "extension=php_openssl.dll" is required.

try 
{
	//These parameters are needed to be optimalise depending on the environment:
	ini_set('memory_limit','1024M');
	ini_set('max_execution_time', 600);
	
	//Test ClientNumber:
	$clientNumber = 100000001; //!!!NOT FOR CUSTOMER TESTING, USE YOUR OWN, USE YOUR OWN!!!
	//Test username:
	$username = "myglsapitest@test.mygls.hu"; //!!!NOT FOR CUSTOMER TESTING, USE YOUR OWN, USE YOUR OWN!!!
	//Test password:
	$pwd = "1pImY_gls.hu"; //!!!NOT FOR CUSTOMER TESTING, USE YOUR OWN, USE YOUR OWN!!!
	$password = hash('sha512', $pwd, true);

	$parcels = []; 
	$parcel = new StdClass();
	$parcel->ClientNumber = $clientNumber;
	$parcel->ClientReference = "TEST PARCEL";
	$parcel->CODAmount = 0;
	$parcel->CODReference = "COD REFERENCE";
	$parcel->Content = "CONTENT";
	$parcel->Count = 1;
	$deliveryAddress = new StdClass();
	$deliveryAddress->ContactEmail = "something@anything.hu";
	$deliveryAddress->ContactName = "Contact Name";
	$deliveryAddress->ContactPhone = "+36701234567";
	$deliveryAddress->Name = "Delivery Address";
	$deliveryAddress->Street = "Európa u.";
	$deliveryAddress->HouseNumber = "2";
	$deliveryAddress->City = "Alsónémedi";
	$deliveryAddress->ZipCode = "2351";
	$deliveryAddress->CountryIsoCode = "HU";
	$deliveryAddress->HouseNumberInfo = "/b";
	$parcel->DeliveryAddress = $deliveryAddress;
	$pickupAddress = new StdClass();
	$pickupAddress->ContactName = "Contact Name";
	$pickupAddress->ContactPhone = "+36701234567";
	$pickupAddress->ContactEmail = "something@anything.hu";
	$pickupAddress->Name = "Pickup Address";
	$pickupAddress->Street = "Európa u.";
	$pickupAddress->HouseNumber = "2";
	$pickupAddress->City = "Alsónémedi";
	$pickupAddress->ZipCode = "2351";
	$pickupAddress->CountryIsoCode = "HU";
	$pickupAddress->HouseNumberInfo = "/a";
	$parcel->PickupAddress = $pickupAddress;
	$parcel->PickupDate = "2019-12-14";
	$service1 = new StdClass();
	$service1->Code = "PSD";
	$parameter1 = new StdClass();
	$parameter1->StringValue = "2351-CSOMAGPONT";
	$service1->PSDParameter = $parameter1;
	$services = [];
	$services[] = $service1;
	$parcel->ServiceList = $services;
	
	$parcels[] = $parcel;
	
	//The service URL:
	$wsdl = "https://api.test.mygls.hu/SERVICE_NAME.svc?singleWsdl";

	$soapOptions = array('soap_version'   => SOAP_1_1
					                , 'stream_context' => stream_context_create(array('ssl' => array('cafile' => 'cacert.pem'))));

	//Parcel service:
	$serviceName = "ParcelService";
					   
	PrintLabels($username,$password,$parcels,str_replace("SERVICE_NAME",$serviceName,$wsdl),$soapOptions);

	GetPrintedLabels(str_replace("SERVICE_NAME",$serviceName,$wsdl),$soapOptions,PrepareLabels($username,$password,$parcels,str_replace("SERVICE_NAME",$serviceName,$wsdl),$soapOptions));

	GetParcelList($username,$password,str_replace("SERVICE_NAME",$serviceName,$wsdl),$soapOptions);

	GetParcelStatuses($username,$password,str_replace("SERVICE_NAME",$serviceName,$wsdl),$soapOptions);
} 
catch (Exception $e) 
{
    echo $e->getMessage();
}

/*
* Label(s) generation by the service.
*/
function PrintLabels($username,$password,$parcels,$wsdl,$soapOptions)
{
	//Test request:
	$printLabelsRequest = array('Username' => $username,
	                                           'Password' => $password,
								               'ParcelList' => $parcels);
								
	$request = array ("printLabelsRequest" => $printLabelsRequest);
								
	//Service client creation:
	$client = new SoapClient($wsdl,$soapOptions);

	//Service calling:
	$response = $client->PrintLabels($request);
	
	if($response != null && count((array)$response->PrintLabelsResult->PrintLabelsErrorList) == 0 && $response->PrintLabelsResult->Labels != "")
	{
		//Label(s) saving:
		file_put_contents('php_soap_client_PrintLabels.pdf', $response->PrintLabelsResult->Labels);
	}
}

/*
* Preparing label(s) by the service.
*/
function PrepareLabels($username,$password,$parcels,$wsdl,$soapOptions)
{
	//Test request:
	$prepareLabelsRequest = array('Username' => $username,
	                                                'Password' => $password,
								                    'ParcelList' => $parcels);
								  
	$request = array ("prepareLabelsRequest" => $prepareLabelsRequest);
								
	//Service client creation:
	$client = new SoapClient($wsdl,$soapOptions);
	
	//Service calling:
	$response = $client->PrepareLabels($request);
	
	$parcelIdList = [];
	if($response != null && count((array)$response->PrepareLabelsResult->PrepareLabelsError) == 0 && count((array)$response->PrepareLabelsResult->ParcelInfoList) > 0)
	{
		$parcelIdList[] = $response->PrepareLabelsResult->ParcelInfoList->ParcelInfo->ParcelId;
	}
	
	//Test request:
	$getPrintedLabelsRequest = array('Username' => $username,
													   'Password' => $password,
													   'ParcelIdList' => $parcelIdList,
													   'PrintPosition' => 1,
													   'ShowPrintDialog' => 0);
									 
	return $getPrintedLabelsRequest;
}

/*
* Get label(s) by the service.
*/
function GetPrintedLabels($wsdl,$soapOptions,$getPrintedLabelsRequest)
{
	$request = array ("getPrintedLabelsRequest" => $getPrintedLabelsRequest);
								
	//Service client creation:
	$client = new SoapClient($wsdl,$soapOptions);

	//Service calling:
	$response = $client->GetPrintedLabels($request);
	
	if($response != null && count((array)$response->GetPrintedLabelsResult->GetPrintedLabelsErrorList) == 0 && $response->GetPrintedLabelsResult->Labels != "")
	{
		//Label(s) saving:
		file_put_contents('php_soap_client_GetPrintedLabels.pdf', $response->GetPrintedLabelsResult->Labels);
	}
}

/*
* Get parcel(s) information by date ranges.
*/
function GetParcelList($username,$password,$wsdl,$soapOptions)
{	
	//Test request:
	$getParcelListRequest = array('Username' => $username,
												  'Password' => $password,
												  'PickupDateFrom' => '2020-04-16',
												  'PickupDateTo' => '2020-04-16',
												  'PrintDateFrom' => null,
												  'PrintDateTo' => null);
	
	$request = array ("getParcelListRequest" => $getParcelListRequest);
								
	//Service client creation:
	$client = new SoapClient($wsdl,$soapOptions);

	//Service calling:
	$response = $client->GetParcelList($request);
	
	var_dump(count((array)$response->GetParcelListResult->GetParcelListErrors));
	var_dump(count((array)$response->GetParcelListResult->PrintDataInfoList));
}

/*
* Get parcel statuses.
*/
function GetParcelStatuses($username,$password,$wsdl,$soapOptions)
{
	//Test request:
	$getParcelStatusesRequest = array('Username' => $username,
														  'Password' => $password,
														  'ParcelNumber' => 0,
														  'ReturnPOD' => true,
														  'LanguageIsoCode' => "HU");
								
	$request = array ("getParcelStatusesRequest" => $getParcelStatusesRequest);
								
	//Service client creation:
	$client = new SoapClient($wsdl,$soapOptions);

	//Service calling:
	$response = $client->GetParcelStatuses($request);
	
	if($response != null )
	{
		var_dump(count((array)$response->GetParcelStatusesResult->GetParcelStatusErrors));		
		if(count((array)$response->GetParcelStatusesResult->GetParcelStatusErrors) == 0 && $response->GetParcelStatusesResult->POD != "")
		{
			//POD saving:
			file_put_contents('php_soap_client_GetParcelStatuses.pdf', $response->GetParcelStatusesResult->POD);
		}
	}
}