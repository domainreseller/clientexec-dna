<?php
use DomainNameApi\DomainNameAPI_PHPLibrary;

require_once 'modules/admin/models/RegistrarPlugin.php';
require_once 'plugins/registrars/domainnameapi/api.php';

class PluginDomainnameapi extends RegistrarPlugin
{
    public const MODULE_VERSION='1.0.2';
    public $features = [
        'nameSuggest' => false,
        'importDomains' => true,
        'importPrices' => true,
    ];


    /**
     * @var DomainNameAPI_PHPLibrary
     */
    private  $api;

    public function setup()
    {
        $this->api = new DomainNameAPI_PHPLibrary(
            $this->settings->get('plugin_Domainnameapi_Username'),
            $this->settings->get('plugin_Domainnameapi_Password'),
            DomainNameAPI_PHPLibrary::APPLICATION_CLIENTEXEC
        );

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

        if (!empty($params['namesuggest'])) {
            $params['namesuggest'] = array_diff($params['namesuggest'], [$params['tld']]);
            array_unshift($params['namesuggest'], $params['tld']);
            $tldList = $params['namesuggest'];
        } else {
            $tldList = [$params['tld']];
        }

        $result = $this->api->CheckAvailability([$params['sld']], $tldList, '1', 'create');
        $this->logCall();

        if ($result['result'] == 'OK') {
            foreach ($result['data'] as $domain) {
                $status    = ($domain['Status'] == 'notavailable') ? 1 : 0;
                $domains[] = [
                    'tld'    => $domain['TLD'],
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
        return $this->user->lang('Transfer has been initiated.');
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
        return $this->user->lang('{domain} has been registered.', ['domain' => $userPackage->getCustomField('Domain Name')]);
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
        return $this->user->lang('{domain} has been renewed.', ['domain' => $userPackage->getCustomField('Domain Name')]);
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

        $result = $this->api->RegisterWithContactInfo($params['sld'] . '.' . $params['tld'], $params['NumYears'], [
                'Administrative' => $this->contactInfoToArray($params),
                'Billing'        => $this->contactInfoToArray($params),
                'Technical'      => $this->contactInfoToArray($params),
                'Registrant'     => $this->contactInfoToArray($params)
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

    private function contactInfoToArray($params, $set = false)
{
    $contactInfo = [
        'FirstName'        => $params[$set ? 'Registrant_FirstName' : 'RegistrantFirstName'],
        'LastName'         => $params[$set ? 'Registrant_LastName' : 'RegistrantLastName'],
        'Company'          => $params[$set ? 'Registrant_OrganizationName' : 'RegistrantOrganizationName'],
        'EMail'            => $params[$set ? 'Registrant_EmailAddress' : 'RegistrantEmailAddress'],
        'AddressLine1'     => $params[$set ? 'Registrant_Address1' : 'RegistrantAddress1'],
        'AddressLine2'     => $params[$set ? 'Registrant_Address2' : 'RegistrantAddress2'],
        'City'             => $params[$set ? 'Registrant_City' : 'RegistrantCity'],
        'Country'          => $params[$set ? 'Registrant_Country' : 'RegistrantCountry'],
        'Fax'              => $params[$set ? 'Registrant_Fax' : 'RegistrantFax'],
        'Phone'            => $this->validatePhone($params[$set ? 'Registrant_Phone' : 'RegistrantPhone'], $params[$set ? 'Registrant_Country' : 'RegistrantCountry']),
        'PhoneCountryCode' => $this->validateCountryCode($params[$set ? 'Registrant_Phone' : 'RegistrantPhone'], $params[$set ? 'Registrant_Country' : 'RegistrantCountry']),
        'Type'             => 'Contact',
        'ZipCode'          => $params[$set ? 'Registrant_PostalCode' : 'RegistrantPostalCode'],
        'State'            => $params[$set ? 'Registrant_StateProvince' : 'RegistrantStateProvince'],
    ];

    if (!$set) {
        $contactInfo['Status'] = '';
    }

    return $contactInfo;
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
                'Administrative' =>  $this->contactInfoToArray($params, true) ,
                'Billing' =>   $this->contactInfoToArray($params, true),
                'Technical' =>   $this->contactInfoToArray($params, true),
                'Registrant' =>   $this->contactInfoToArray($params, true),
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

            $nameservers = isset($result["data"]["NameServers"][0]) ? $result["data"]["NameServers"] : [];

            return array_values($nameservers);
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
            $data['is_registered'] = $result['data']['Status'] == 'ACTIVE';
            $data['is_expired'] = strtotime($result['data']['Dates']['Expiration'])>time();
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

        $pageLength = 100;
        $page=1;
        if ($params['next'] > $pageLength) {
            $page = ceil($params['next'] / $pageLength);
        }
        $getListArgs = [
            'PageSize'       => $pageLength,
            'PageNumber'     => $page,
            'OrderColumn'    => 'Id',
            'OrderDirection' => 'DESC',
        ];

        $result = $this->api->GetList($getListArgs);
        if (is_array($result['data']['Domains'])) {
            foreach ($result['data']['Domains'] as $domain) {

               $domainName = trim($domain['DomainName']);
                $temp = explode('.', $domainName);
                $sld = $temp[0];
                unset($temp[0]);
                $tld = implode('.', $temp);


                $data = [
                    'id' => $domain['ID'],
                    'sld' => $sld,
                    'tld' => $tld,
                    'exp' => $domain['Dates']['Expiration']
                ];
                $domainsList[] = $data;
            }
        }

        $metaData               = [];
        $metaData['total']      = (int)$result['TotalCount'];
        $metaData['next']       = $page * $pageLength + 1;
        $metaData['start']      = 1 + ($page - 1) * $pageLength;
        $metaData['end']        = min($page * $pageLength, $metaData['total']);
        $metaData['numPerPage'] = $pageLength;
        return [$domainsList, $metaData];
    }

    public function getRegistrarLock($params)
    {
        $this->setup();
        $result = $this->api->SyncFromRegistry($params['sld'] . '.' . $params['tld']);
        if ($result['result'] == 'OK') {
            return $result['data']['LockStatus'] == 'true';
        } else {
            throw new CE_Exception($result['error']['Message'] . "\n" . $result['error']['Details']);
        }
    }

    public function doSetRegistrarLock($params)
    {
        $userPackage = new UserPackage($params['userPackageId']);
        $this->setRegistrarLock($this->buildLockParams($userPackage, $params));
        return $this->user->lang('Updated Registrar Lock.');
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
                $this->user->lang('Registrar Lock updated successfully.');
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
        CE_Lib::log(4, $this->api->getRequestData());

        CE_Lib::log(4, 'DomainName API Response:');
        CE_Lib::log(4, $this->api->getResponseData());
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
        $this->setup();
        $nameserver = $params['nsname'];
        $ip         = $params['nsip'];
        $result     = $this->api->AddChildNameServer($params['sld'] . '.' . $params['tld'], $nameserver, $ip);
        $this->logCall();
        if ($result['result'] == 'OK') {
            return $this->user->lang('Name Server registered successfully.');
        } else {
            throw new CE_Exception($result['error']['Message'] . "\n" . $result['error']['Details']);
        }
    }

    public function editNS($params)
    {
        $this->setup();
        $nameserver = $params['nsname'];
        $newIp      = $params['nsnewip'];
        $result     = $this->api->ModifyChildNameServer($params['sld'] . '.' . $params['tld'], $nameserver, $newIp);
        $this->logCall();
        if ($result['result'] == 'OK') {
            return $this->user->lang('Name Server updated successfully.');
        } else {
            throw new CE_Exception($result['error']['Message'] . "\n" . $result['error']['Details']);
        }
    }

    public function deleteNS($params)
    {
        $this->setup();
        $nameserver = $params['nsname'];
        $result     = $this->api->DeleteChildNameServer($params['sld'] . '.' . $params['tld'], $nameserver);
        $this->logCall();
        if ($result['result'] == 'OK') {
            return $this->user->lang('Name Server deleted successfully.');
        } else {
            throw new CE_Exception($result['error']['Message'] . "\n" . $result['error']['Details']);
        }
    }

   public function getTLDsAndPrices($params)
{
    $this->setup();
    $tldlist = $this->api->GetTldList(1200);
    $this->logCall();

    $tlds = [];
    if ($tldlist['result'] == 'OK') {
        foreach ($tldlist['data'] as $extension) {
            if(strlen($extension['tld'])>1){
                $price_registration = $extension['pricing']['registration'][1];
                $price_renew        = $extension['pricing']['renew'][1];
                $price_transfer     = $extension['pricing']['transfer'][1];
                $current_currency   = $extension['currencies']['registration'];

                $tlds[$extension['tld']]['register']=$price_registration;
                $tlds[$extension['tld']]['renew']=$price_renew;
                $tlds[$extension['tld']]['transfer']=$price_transfer;

            }
        }
        return $tlds;
    } else {
        throw new CE_Exception($tldlist['error']['Message'] . "\n" . $tldlist['error']['Details']);
    }


}

}
