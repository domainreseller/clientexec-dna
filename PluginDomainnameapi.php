<?php

require_once 'modules/admin/models/RegistrarPlugin.php';
require_once 'plugins/registrars/domainnameapi/api.php';

class PluginDomainnameapi extends RegistrarPlugin
{
    public $features = [
        'nameSuggest' => false,
        'importDomains' => true,
        'importPrices' => false,
    ];

    private $api;

    public function setup()
    {
        $this->api = new DomainNameAPI_PHPLibrary();

        $this->api->setUser(
            $this->settings->get('plugin_Domainnameapi_Username'),
            $this->settings->get('plugin_Domainnameapi_Password')
        );

        $this->api->useCaching(true);

        if ($this->settings->get('plugin_Domainnameapi_Use testing server')) {
            $this->api->useTestMode(true);
        } else {
            $this->api->useTestMode(false);
        }
        $this->api->setConnectionMethod('SOAP');
    }

    public function getVariables()
    {
        $variables = [
            lang('Plugin Name') => [
                'type' => 'hidden',
                'description' => lang('How CE sees this plugin (not to be confused with the Signup Name)'),
                'value' => lang('Domain Name Api')
            ],
            lang('Use testing server') => [
                'type' => 'yesno',
                'description' => lang('Select Yes if you wish to use the testing environment, so that transactions are not actually made.'),
                'value' => 0
            ],
            lang('Username') => [
                'type' => 'text',
                'description' => lang('Enter your username for your Domain Name Api reseller account.'),
                'value' => ''
            ],
            lang('Password')  => [
                'type' => 'password',
                'description' => lang('Enter the password for your Domain Name API reseller account.'),
                'value' => '',
            ],
            lang('NameServer 1') => [
                'type' => 'text',
                'description' => lang('Enter Name Server #1, used in stand alone domains.'),
                'value' => '',
            ],
            lang('NameServer 2') => [
                'type' => 'text',
                'description' => lang('Enter Name Server #1, used in stand alone domains.'),
                'value' => '',
            ],
            lang('Supported Features') => [
                'type' => 'label',
                'description' => '* ' . lang('TLD Lookup') . '<br>* ' . lang('Domain Registration') . ' <br>* ' . lang('Domain Registration with ID Protect') . ' <br>* ' . lang('Existing Domain Importing') . ' <br>* ' . lang('Get / Set Nameserver Records') . ' <br>* ' . lang('Get / Set Contact Information') . ' <br>* ' . lang('Get / Set Registrar Lock') . ' <br>* ' . lang('Initiate Domain Transfer') . ' <br>* ' . lang('Automatically Renew Domain') . ' <br>* ' . lang('View EPP Code'),
                'value' => ''
            ],
            lang('Actions') => [
                'type' => 'hidden',
                'description' => lang('Current actions that are active for this plugin (when a domain isn\'t registered)'),
                'value' => 'Register'
            ],
            lang('Registered Actions') => [
                'type' => 'hidden',
                'description' => lang('Current actions that are active for this plugin (when a domain is registered)'),
                'value' => 'Renew (Renew Domain),DomainTransferWithPopup (Initiate Transfer),Cancel',
            ],
            lang('Registered Actions For Customer') => [
                'type' => 'hidden',
                'description' => lang('Current actions that are active for this plugin (when a domain is registered)'),
                'value' => '',
            ]
        ];

        return $variables;
    }

    public function checkDomain($params)
    {
        $this->setup();
        $domains = [];

        if (isset($params['namesuggest'])) {
            foreach ($params['namesuggest'] as $key => $value) {
                if ($value == $params['tld']) {
                    unset($params['namesuggest'][$key]);
                    break;
                }
            }
            array_unshift($params['namesuggest'], $params['tld']);
            $tldList = $params['namesuggest'];
        } else {
            $tldList = [$params['tld']];
        }

        $result = $this->api->CheckAvailability(
            [$params['sld']],
            $tldList,
            '1',
            'create'
        );
        $this->logCall();

        if ($result['result'] == 'OK') {
            foreach ($result['data'] as $domain) {
                if ($domain['Status'] == 'notavailable') {
                    $status = 1;
                } elseif ($domain['Status'] == 'available') {
                    $status = 0;
                }
                $domains[] = [
                    'tld' => $domain['TLD'],
                    'domain' => $domain['DomainName'],
                    'status' => $status
                ];
            }
        } else {
            throw new Exception($result['error']['Message'] . "\n" . $result['error']['Details']);
        }

        return ['result' => $domains];
    }

    /**
     * Initiate a domain transfer
     *
     * @param array $params
     */
    public function doDomainTransferWithPopup($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $transferid = $this->initiateTransfer($this->buildTransferParams($userPackage, $params));
        $userPackage->setCustomField("Registrar Order Id", $userPackage->getCustomField("Registrar") . '-' . $transferid);
        $userPackage->setCustomField('Transfer Status', $transferid);
        return "Transfer of has been initiated.";
    }

    /**
     * Register domain name
     *
     * @param array $params
     */
    public function doRegister($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $orderid = $this->registerDomain($this->buildRegisterParams($userPackage, $params));
        $userPackage->setCustomField("Registrar Order Id", $userPackage->getCustomField("Registrar") . '-' . $orderid);
        return $userPackage->getCustomField('Domain Name') . ' has been registered.';
    }

    /**
     * Renew domain name
     *
     * @param array $params
     */
    public function doRenew($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $orderid = $this->renewDomain($this->buildRenewParams($userPackage, $params));
        $userPackage->setCustomField("Registrar Order Id", $userPackage->getCustomField("Registrar") . '-' . $orderid);
        return $userPackage->getCustomField('Domain Name') . ' has been renewed.';
    }

    public function getTransferStatus($params)
    {
    }

    public function initiateTransfer($params)
    {
        $this->setup();
        $result = $this->api->Transfer($params['sld'] . '.' . $params['tld'], $params['eppCode']);
        $this->logCall($result);
        if ($result['result'] == 'OK') {
        } else {
            throw new CE_Exception($result['error']['Message'] . "\n" . $result['error']['Details']);
        }
    }

    public function renewDomain($params)
    {
        $this->setup();
        $result = $this->api->Renew($params['sld'] . '.' . $params['tld'], $params['NumYears']);
        $this->logCall($result);
        if ($result['result'] == 'OK') {
        } else {
            throw new CE_Exception($result['error']['Message'] . "\n" . $result['error']['Details']);
        }
    }

    public function registerDomain($params)
    {
        $this->setup();
        $nameServers = [];
        if (isset($params['NS1'])) {
            for ($i = 1; $i <= 12; $i++) {
                if (isset($params["NS$i"])) {
                    $nameServers[] = $params["NS$i"]['hostname'];
                } else {
                    break;
                }
            }
        }

        if (count($nameServers) == 0) {
            $nameServers = [
                $this->settings->get('plugin_Domainnameapi_NameServer 1'),
                $this->settings->get('plugin_Domainnameapi_NameServer 2')
            ];
        }

        $privacy = false;
        if (isset($params['package_addons']['IDPROTECT']) && $params['package_addons']['IDPROTECT'] == 1) {
            $privacy = true;
        }

        $result = $this->api->RegisterWithContactInfo(
            $params['sld'] . '.' . $params['tld'],
            $params['NumYears'],
            [
            'Administrative' => [
                'FirstName' => $params['RegistrantFirstName'],
                'LastName' => $params['RegistrantLastName'],
                'Company' => $params['RegistrantOrganizationName'],
                'EMail' => $params['RegistrantEmailAddress'],
                'AddressLine1' => $params['RegistrantAddress1'],
                'State' => $params['RegistrantStateProvince'],
                'City' => $params['RegistrantCity'],
                'Country' => $params['RegistrantCountry'],
                'Phone' => $this->validatePhone($params['RegistrantPhone'], $params['RegistrantCountry']),
                'PhoneCountryCode' => $this->validateCountryCode($params['RegistrantPhone'], $params['RegistrantCountry']),
                'Type' => 'Contact',
                'ZipCode' => $params['RegistrantPostalCode'],
                'Status' => ''
            ],
            'Billing' => [
                'FirstName' => $params['RegistrantFirstName'],
                'LastName' => $params['RegistrantLastName'],
                'Company' => $params['RegistrantOrganizationName'],
                'EMail' => $params['RegistrantEmailAddress'],
                'AddressLine1' => $params['RegistrantAddress1'],
                'State' => $params['RegistrantStateProvince'],
                'City' => $params['RegistrantCity'],
                'Country' => $params['RegistrantCountry'],
                'Phone' => $this->validatePhone($params['RegistrantPhone'], $params['RegistrantCountry']),
                'PhoneCountryCode' => $this->validateCountryCode($params['RegistrantPhone'], $params['RegistrantCountry']),
                'Type' => 'Contact',
                'ZipCode' => $params['RegistrantPostalCode'],
                'Status' => ''
            ],
            'Technical' => [
                'FirstName' => $params['RegistrantFirstName'],
                'LastName' => $params['RegistrantLastName'],
                'Company' => $params['RegistrantOrganizationName'],
                'EMail' => $params['RegistrantEmailAddress'],
                'AddressLine1' => $params['RegistrantAddress1'],
                'State' => $params['RegistrantStateProvince'],
                'City' => $params['RegistrantCity'],
                'Country' => $params['RegistrantCountry'],
                'Phone' => $this->validatePhone($params['RegistrantPhone'], $params['RegistrantCountry']),
                'PhoneCountryCode' => $this->validateCountryCode($params['RegistrantPhone'], $params['RegistrantCountry']),
                'Type' => 'Contact',
                'ZipCode' => $params['RegistrantPostalCode'],
                'Status' => ''
            ],
            'Registrant' => [
                'FirstName' => $params['RegistrantFirstName'],
                'LastName' => $params['RegistrantLastName'],
                'Company' => $params['RegistrantOrganizationName'],
                'EMail' => $params['RegistrantEmailAddress'],
                'AddressLine1' => $params['RegistrantAddress1'],
                'State' => $params['RegistrantStateProvince'],
                'City' => $params['RegistrantCity'],
                'Country' => $params['RegistrantCountry'],
                'Phone' => $this->validatePhone($params['RegistrantPhone'], $params['RegistrantCountry']),
                'PhoneCountryCode' => $this->validateCountryCode($params['RegistrantPhone'], $params['RegistrantCountry']),
                'Type' => 'Contact',
                'ZipCode' => $params['RegistrantPostalCode'],
                'Status' => ''
            ]
            ],
            $nameServers,
            true,
            $privacy
        );
        $this->logCall();

        if ($result['result'] == 'OK') {
        } else {
            throw new CE_Exception($result['error']['Message'] . "\n" . $result['error']['Details']);
        }
    }

    private function validateCountryCode($country)
    {
        $query = "SELECT phone_code FROM country WHERE iso=? AND phone_code != ''";
        $result = $this->db->query($query, $country);
        if (!$row = $result->fetch()) {
            return '';
        }
        return $row['phone_code'];
    }

    private function validatePhone($phone, $country)
    {
        // strip all non numerical values
        $phone = preg_replace('/[^\d]/', '', $phone);

        if ($phone == '') {
            return $phone;
        }


        $code = $this->validateCountryCode($country);
        if ($code == '') {
            return $phone;
        }

        // check if code is already there
        $phone = preg_replace("/^($code)(\\d+)/", '+\1.\2', $phone);
        if ($phone[0] == '+') {
            return $phone;
        }

        // if not, prepend it
        return "+$code.$phone";
    }

    public function getContactInformation($params)
    {
        $this->setup();
        $result = $this->api->GetContacts($params['sld'] . '.' . $params['tld']);
        $this->logCall();
        if ($result['result'] == 'OK') {
            $info = [];
            foreach (['Administrative', 'Billing', 'Registrant', 'Technical'] as $type) {
                $data = $result['data']['contacts'][$type];
                $info[$type]['OrganizationName']  = [$this->user->lang('Organization'), $data['Company']];
                $info[$type]['FirstName'] = [$this->user->lang('First Name'), $data['FirstName']];
                $info[$type]['LastName']  = [$this->user->lang('Last Name'), $data['LastName']];
                $info[$type]['Address1']  = [$this->user->lang('Address') . ' 1', $data['Address']['Line1']];
                $info[$type]['Address2']  = [$this->user->lang('Address') . ' 2', $data['Address']['Line2']];
                $info[$type]['City']      = [$this->user->lang('City'), $data['Address']['City']];
                $info[$type]['StateProvince']  = [$this->user->lang('Province') . '/' . $this->user->lang('State'), $data['Address']['State']];
                $info[$type]['Country']   = [$this->user->lang('Country'), $data['Address']['Country']];
                $info[$type]['PostalCode']  = [$this->user->lang('Postal Code'), $data['Address']['ZipCode']];
                $info[$type]['EmailAddress']     = [$this->user->lang('E-mail'), $data['EMail']];
                $info[$type]['Phone']  = [$this->user->lang('Phone'), $data['Phone']['Phone']['Number']];
                $info[$type]['Fax']       = [$this->user->lang('Fax'), $data['Phone']['Fax']['Number']];
            }
            return $info;
        } else {
            throw new CE_Exception($result['error']['Message'] . "\n" . $result['error']['Details']);
        }
    }

    public function setContactInformation($params)
    {
        $this->setup();
        $result = $this->api->SaveContacts(
            $params['sld'] . '.' . $params['tld'],
            [
                'Administrative' => [
                    'FirstName' => $params['Registrant_FirstName'],
                    'LastName' => $params['Registrant_LastName'],
                    'Company' => $params['Registrant_OrganizationName'],
                    'EMail' => $params['Registrant_EmailAddress'],
                    'AddressLine1' => $params['Registrant_Address1'],
                    'AddressLine2' => $params['Registrant_Address2'],
                    'City' => $params['Registrant_City'],
                    'Country' => $params['Registrant_Country'],
                    'Fax' => $params['Registrant_Fax'],
                    'Phone' => $this->validatePhone($params['Registrant_Phone'], $params['Registrant_Country']),
                    'PhoneCountryCode' => $this->validateCountryCode($params['Registrant_Phone'], $params['Registrant_Country']),
                    'Type' => 'Contact',
                    'ZipCode' => $params['Registrant_PostalCode'],
                    'State' =>  $params['Registrant_StateProvince'],
                ],
                'Billing' => [
                    'FirstName' => $params['Registrant_FirstName'],
                    'LastName' => $params['Registrant_LastName'],
                    'Company' => $params['Registrant_OrganizationName'],
                    'EMail' => $params['Registrant_EmailAddress'],
                    'AddressLine1' => $params['Registrant_Address1'],
                    'AddressLine2' => $params['Registrant_Address2'],
                    'City' => $params['Registrant_City'],
                    'Country' => $params['Registrant_Country'],
                    'Fax' => $params['Registrant_Fax'],
                    'Phone' => $this->validatePhone($params['Registrant_Phone'], $params['Registrant_Country']),
                    'PhoneCountryCode' => $this->validateCountryCode($params['Registrant_Phone'], $params['Registrant_Country']),
                    'Type' => 'Contact',
                    'ZipCode' => $params['Registrant_PostalCode'],
                    'State' =>  $params['Registrant_StateProvince'],
                ],
                'Technical' => [
                    'FirstName' => $params['Registrant_FirstName'],
                    'LastName' => $params['Registrant_LastName'],
                    'Company' => $params['Registrant_OrganizationName'],
                    'EMail' => $params['Registrant_EmailAddress'],
                    'AddressLine1' => $params['Registrant_Address1'],
                    'AddressLine2' => $params['Registrant_Address2'],
                    'City' => $params['Registrant_City'],
                    'Country' => $params['Registrant_Country'],
                    'Fax' => $params['Registrant_Fax'],
                    'Phone' => $this->validatePhone($params['Registrant_Phone'], $params['Registrant_Country']),
                    'PhoneCountryCode' => $this->validateCountryCode($params['Registrant_Phone'], $params['Registrant_Country']),
                    'Type' => 'Contact',
                    'ZipCode' => $params['Registrant_PostalCode'],
                    'State' =>  $params['Registrant_StateProvince'],
                ],
                'Registrant' => [
                    'FirstName' => $params['Registrant_FirstName'],
                    'LastName' => $params['Registrant_LastName'],
                    'Company' => $params['Registrant_OrganizationName'],
                    'EMail' => $params['Registrant_EmailAddress'],
                    'AddressLine1' => $params['Registrant_Address1'],
                    'AddressLine2' => $params['Registrant_Address2'],
                    'City' => $params['Registrant_City'],
                    'Country' => $params['Registrant_Country'],
                    'Fax' => $params['Registrant_Fax'],
                    'Phone' => $this->validatePhone($params['Registrant_Phone'], $params['Registrant_Country']),
                    'PhoneCountryCode' => $this->validateCountryCode($params['Registrant_Phone'], $params['Registrant_Country']),
                    'Type' => 'Contact',
                    'ZipCode' => $params['Registrant_PostalCode'],
                    'State' =>  $params['Registrant_StateProvince'],
                ],
            ]
        );
        $this->logCall();
        if ($result['result'] == 'OK') {
            return $this->user->lang('Contact Information updated successfully.');
        } else {
            throw new CE_Exception($result['error']['Message'] . "\n" . $result['error']['Details']);
        }
    }

    public function getNameServers($params)
    {
        $this->setup();
        $result = $this->api->SyncFromRegistry($params['sld'] . '.' . $params['tld']);
        $this->logCall();

        $info = [];

        if ($result["result"] == "OK") {
            if (isset($result["data"]["NameServers"][0][0])) {
                $info[] = $result["data"]["NameServers"][0][0];
            }
            if (isset($result["data"]["NameServers"][0][1])) {
                $info[] = $result["data"]["NameServers"][0][1];
            }
            if (isset($result["data"]["NameServers"][0][2])) {
                $info[] = $result["data"]["NameServers"][0][2];
            }
            if (isset($result["data"]["NameServers"][0][3])) {
                $info[] = $result["data"]["NameServers"][0][3];
            }
            if (isset($result["data"]["NameServers"][0][4])) {
                $info[] = $result["data"]["NameServers"][0][4];
            }

            return $info;
        } else {
            throw new CE_Exception($result['error']['Message'] . "\n" . $result['error']['Details']);
        }
    }

    public function setNameServers($params)
    {
        $this->setup();
        $nameservers = [];

        foreach ($params['ns'] as $value) {
            $nameservers[] = $value;
        }

        $result = $this->api->ModifyNameserver($params['sld'] . '.' . $params['tld'], $nameservers);
        $this->logCall();
        if ($result["result"] == "OK") {
        } else {
            throw new CE_Exception($result['error']['Message'] . "\n" . $result['error']['Details']);
        }
    }

    public function getGeneralInfo($params)
    {
        $this->setup();
        $result = $this->api->SyncFromRegistry($params['sld'] . '.' . $params['tld']);

        if ($result['result'] == 'OK') {
            $data = [];
            $data['domain'] = $result['data']['DomainName'];
            $data['expiration'] = $result['data']['Dates']['Expiration'];
            $data['is_registered'] = ($result['data']['Status'] == 'ACTIVE') ? true : false;
            $data['is_expired'] = ($result['data']['Status'] == 'PendingDelete') ? true : false;
            $data['registrationstatus'] = 'N/A';
            $data['purchasestatus'] = 'N/A';

            return $data;
        } else {
            throw new Exception('Error fetching domain details.');
        }
    }

    public function fetchDomains($params)
    {
        $this->setup();
        $domainsList = [];
        $domainNameGateway = new DomainNameGateway();

        $result = $this->api->GetList();
        if (is_array($result['data']['Domains'])) {
            foreach ($result['data']['Domains'] as $domain) {
                $temp = $domainNameGateway->splitDomain($domain['DomainName']);

                $data = [
                    'id' => $domain['ID'],
                    'sld' => $temp[0],
                    'tld' => $temp[1],
                    'exp' => $domain['Dates']['Expiration']
                ];
                $domainsList[] = $data;
            }
        }

        return [$domainsList, []];
    }

    public function getRegistrarLock($params)
    {
        $this->setup();
        $result = $this->api->SyncFromRegistry($params['sld'] . '.' . $params['tld']);
        if ($result['result'] == 'OK') {
            return ($result['data']['LockStatus'] == 'true' ) ? true : false;
        } else {
            throw new CE_Exception($result['error']['Message'] . "\n" . $result['error']['Details']);
        }
    }

    public function doSetRegistrarLock($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $this->setRegistrarLock($this->buildLockParams($userPackage, $params));
        return "Updated Registrar Lock.";
    }

    public function setRegistrarLock($params)
    {
        $this->setup();
        $result = $this->api->SyncFromRegistry($params['sld'] . '.' . $params['tld']);
        if ($result['result'] == 'OK') {
            if ($result['data']['LockStatus'] == 'true') {
                $result = $this->api->DisableTheftProtectionLock($params['sld'] . '.' . $params['tld']);
            } else {
                $result = $this->api->EnableTheftProtectionLock($params['sld'] . '.' . $params['tld']);
            }
            $this->logCall();
            if ($result['result'] == 'OK') {
            } else {
                throw new CE_Exception($result['error']['Message'] . "\n" . $result['error']['Details']);
            }
        } else {
            throw new CE_Exception($result['error']['Message'] . "\n" . $result['error']['Details']);
        }
    }

    public function getEPPCode($params)
    {
        $this->setup();
        $result = $this->api->SyncFromRegistry($params['sld'] . '.' . $params['tld']);
        if ($result['result'] == 'OK') {
            return $result['data']['AuthCode'];
        } else {
            throw new CE_Exception($result['error']['Message'] . "\n" . $result['error']['Details']);
        }
    }

    private function logCall()
    {
        CE_Lib::log(4, 'DomainName API Request:');
        CE_Lib::log(4, $this->api->__REQUEST);

        CE_Lib::log(4, 'DomainName API Response:');
        CE_Lib::log(4, $this->api->__RESPONSE);
    }

    public function getDNS($params)
    {
        throw new CE_Exception('DNS Management is not currently supported.');
    }

    public function setDNS($params)
    {
        throw new CE_Exception('DNS Management is not currently supported.');
    }

    public function sendTransferKey($params)
    {
    }

    public function setAutorenew($params)
    {
    }

    public function checkNSStatus($params)
    {
    }

    public function registerNS($params)
    {
    }

    public function editNS($params)
    {
    }

    public function deleteNS($params)
    {
    }
}
