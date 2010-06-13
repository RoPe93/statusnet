<?php
/*

MSN class ver 2.0 by Tommy Wu, Ricky Su
License: GPL

You can find MSN protocol from this site: http://msnpiki.msnfanatic.com/index.php/Main_Page

This class support MSNP15 for send message. The PHP module needed:

MSNP15: curl pcre mhash mcrypt bcmath

Usually, this class will try to use MSNP15 if your system can support it, if your system can't support it,
it will switch to use MSNP9. But if you use MSNP9, it won't support OIM (Offline Messages).
*/

class MSN {
    private $debug;
    private $timeout;
    private $protocol = 'MSNP15';
    private $passport_url = 'https://login.live.com/RST.srf';
    private $buildver = '8.1.0178';
    private $prod_key = 'PK}_A_0N_K%O?A9S';
    private $prod_id = 'PROD0114ES4Z%Q5W';
    private $login_method = 'SSO';
    private $oim_send_url = 'https://ows.messenger.msn.com/OimWS/oim.asmx';
    private $oim_send_soap = 'http://messenger.live.com/ws/2006/09/oim/Store2';
    private $windows;
    private $kill_me = false;
    private $id;
    private $ticket;
    private $user = '';
    private $password = '';
    private $NSfp=false;
    private $SBfp;
    private $passport_policy = '';
    private $alias;
    private $psm;
    private $use_ping;
    private $retry_wait;
    private $backup_file;
    private $update_pending;
    private $PhotoStickerFile=false;
    private $Emotions=false;
    private $MessageQueue=array();
    private $ChildProcess=array();
    private $MAXChildProcess=3;
    private $ReqSBXFRTimeout=60;
    private $SBTimeout=2;
    private $LastPing;
    private $ping_wait=50;
    private $SBIdleTimeout=10;
    private $SBStreamTimeout=10;
    private $NSStreamTimeout=2;
    private $MsnObjArray=array();
    private $MsnObjMap=array();
    private $SwitchBoardProcess=false;     // false=>Main Process,1 => sb_control_process,2 => sb_ring_process
    private $SwitchBoardSessionUser=false;
    private $SwitchBoardMessageQueue=array();
    private $ABAuthHeader;
    private $ABService;
    private $Contacts;
    private $IgnoreList;

    public $server = 'messenger.hotmail.com';
    public $port = 1863;


    public $clientid = '';

    public $oim_maildata_url = 'https://rsi.hotmail.com/rsi/rsi.asmx';
    public $oim_maildata_soap = 'http://www.hotmail.msn.com/ws/2004/09/oim/rsi/GetMetadata';
    public $oim_read_url = 'https://rsi.hotmail.com/rsi/rsi.asmx';
    public $oim_read_soap = 'http://www.hotmail.msn.com/ws/2004/09/oim/rsi/GetMessage';
    public $oim_del_url = 'https://rsi.hotmail.com/rsi/rsi.asmx';
    public $oim_del_soap = 'http://www.hotmail.msn.com/ws/2004/09/oim/rsi/DeleteMessages';

    public $membership_url = 'https://contacts.msn.com/abservice/SharingService.asmx';
    public $membership_soap = 'http://www.msn.com/webservices/AddressBook/FindMembership';

    public $addmember_url = 'https://contacts.msn.com/abservice/SharingService.asmx';
    public $addmember_soap = 'http://www.msn.com/webservices/AddressBook/AddMember';

    public $addcontact_url = 'https://contacts.msn.com/abservice/abservice.asmx';
    public $addcontact_soap = 'http://www.msn.com/webservices/AddressBook/ABContactAdd';

    public $delmember_url = 'https://contacts.msn.com/abservice/SharingService.asmx';
    public $delmember_soap = 'http://www.msn.com/webservices/AddressBook/DeleteMember';


    public $error = '';

    public $authed = false;

    public $oim_try = 3;

    public $log_file = '';

    public $log_path = false;

    public $font_fn = 'Arial';
    public $font_co = '333333';
    public $font_ef = '';


    // the message length (include header) is limited (maybe since WLM 8.5 released)
    // for WLM: 1664 bytes
    // for YIM: 518 bytes
    public $max_msn_message_len = 1664;
    public $max_yahoo_message_len = 518;
    
    // Begin added for StatusNet
    
    private $aContactList = array();
    private $aADL = array();
    private $switchBoardSessions = array();
    
    /**
    * Event Handler Functions
    */
    private $myEventHandlers = array();
    
    // End added for StatusNet

    private function Array2SoapVar($Array,$ReturnSoapVarObj=true,$TypeName=null,$TypeNameSpace=null)
    {
        $ArrayString='';
        foreach($Array as $Key => $Val)
        {
            if($Key{0}==':') continue;
            $Attrib='';
            if(is_array($Val[':']))
            {
                foreach($Val[':'] as $AttribName => $AttribVal)
                $Attrib.=" $AttribName='$AttribVal'";
            }
            if($Key{0}=='!')
            {
                //List Type Define
                $Key=substr($Key,1);
                foreach($Val as $ListKey => $ListVal)
                {
                    if($ListKey{0}==':') continue;
                    if(is_array($ListVal)) $ListVal=$this->Array2SoapVar($ListVal,false);
                    elseif(is_bool($ListVal)) $ListVal=$ListVal?'true':'false';
                    $ArrayString.="<$Key$Attrib>$ListVal</$Key>";
                }
                continue;
            }
            if(is_array($Val)) $Val=$this->Array2SoapVar($Val,false);
            elseif(is_bool($Val)) $Val=$Val?'true':'false';
            $ArrayString.="<$Key$Attrib>$Val</$Key>";
        }
        if($ReturnSoapVarObj) return new SoapVar($ArrayString,XSD_ANYXML,$TypeName,$TypeNameSpace);
        return $ArrayString;
    }

    public function End()
    {
        $this->log_message("*** someone kill me ***");
        $this->kill_me=true;
    }
    private function IsIgnoreMail($Email)
    {        
        if($this->IgnoreList==false) return false;
        foreach($this->IgnoreList as $Pattern)
        {
            if(preg_match($Pattern,$Email)) return true;
        }
        return false;
    }
    public function __construct ($Configs=array(), $timeout = 15, $client_id = 0x7000800C)
    {
        $this->user = $Configs['user'];
        $this->password = $Configs['password'];
        $this->alias = isset($Configs['alias']) ? $Configs['alias'] : '';
        $this->psm = isset($Configs['psm']) ? $Configs['psm'] : '';
        $my_add_function = isset($Configs['add_user_function']) ? $Configs['add_user_function'] : false;
        $my_rem_function = isset($Configs['remove_user_function']) ? $Configs['remove_user_function'] : false;
        $this->use_ping = isset($Configs['use_ping']) ? $Configs['use_ping'] : false;
        $this->retry_wait = isset($Configs['retry_wait']) ? $Configs['retry_wait'] : 30;
        $this->backup_file = isset($Configs['backup_file']) ? $Configs['backup_file'] : true;
        $this->update_pending = isset($Configs['update_pending']) ? $Configs['update_pending'] : true;
        $this->PhotoStickerFile=isset($Configs['PhotoSticker']) ? $Configs['PhotoSticker'] : false;
        $this->IgnoreList=isset($Configs['IgnoreList'])?$Configs['IgnoreList']:false;
        if($this->Emotions = isset($Configs['Emotions']) ? $Configs['Emotions']:false)
        {
            foreach($this->Emotions as $EmotionFilePath)
            $this->MsnObj($EmotionFilePath,$Type=2);
        }        
        $this->debug = isset($Configs['debug']) ? $Configs['debug'] : false;
        $this->timeout = $timeout;
        // check support
        if (!function_exists('curl_init')) throw new Exception("We need curl module!\n");
        if (!function_exists('preg_match')) throw new Exception("We need pcre module!\n");
        if (!function_exists('mhash')) throw new Exception("We need mhash module!\n");

        if (!function_exists('mcrypt_cbc')) throw new Exception("We need mcrypt module!\n");
        if (!function_exists('bcmod')) throw new Exception("We need bcmath module for $protocol!\n");

        /*
         http://msnpiki.msnfanatic.com/index.php/Client_ID
         Client ID for MSN:
         normal MSN 8.1 clientid is:
         01110110 01001100 11000000 00101100
         = 0x764CC02C

         we just use following:
         * 0x04: Your client can send/receive Ink (GIF format)
         * 0x08: Your client can send/recieve Ink (ISF format)
         * 0x8000: This means you support Winks receiving (If not set the official Client will warn with 'contact has an older client and is not capable of receiving Winks')
         * 0x70000000: This is the value for MSNC7 (WL Msgr 8.1)
         = 0x7000800C;
         */
        $this->clientid = $client_id;
        $this->windows =(strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
        $this->ABService=new SoapClient(realpath(dirname(__FILE__)).'/soap/msnab_sharingservice.wsdl',array('trace' => 1));
    }

    private function get_passport_ticket($url = '')
    {
        $user = $this->user;
        $password = htmlspecialchars($this->password);

        if ($url === '')
        $passport_url = $this->passport_url;
        else
        $passport_url = $url;

        $XML = '<?xml version="1.0" encoding="UTF-8"?>
<Envelope xmlns="http://schemas.xmlsoap.org/soap/envelope/"
          xmlns:wsse="http://schemas.xmlsoap.org/ws/2003/06/secext"
          xmlns:saml="urn:oasis:names:tc:SAML:1.0:assertion"
          xmlns:wsp="http://schemas.xmlsoap.org/ws/2002/12/policy"
          xmlns:wsu="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd"
          xmlns:wsa="http://schemas.xmlsoap.org/ws/2004/03/addressing"
          xmlns:wssc="http://schemas.xmlsoap.org/ws/2004/04/sc"
          xmlns:wst="http://schemas.xmlsoap.org/ws/2004/04/trust">
<Header>
  <ps:AuthInfo xmlns:ps="http://schemas.microsoft.com/Passport/SoapServices/PPCRL" Id="PPAuthInfo">
    <ps:HostingApp>{7108E71A-9926-4FCB-BCC9-9A9D3F32E423}</ps:HostingApp>
    <ps:BinaryVersion>4</ps:BinaryVersion>
    <ps:UIVersion>1</ps:UIVersion>
    <ps:Cookies></ps:Cookies>
    <ps:RequestParams>AQAAAAIAAABsYwQAAAAxMDMz</ps:RequestParams>
  </ps:AuthInfo>
  <wsse:Security>
    <wsse:UsernameToken Id="user">
      <wsse:Username>'.$user.'</wsse:Username>
      <wsse:Password>'.$password.'</wsse:Password>
    </wsse:UsernameToken>
  </wsse:Security>
</Header>
<Body>
  <ps:RequestMultipleSecurityTokens xmlns:ps="http://schemas.microsoft.com/Passport/SoapServices/PPCRL" Id="RSTS">
    <wst:RequestSecurityToken Id="RST0">
      <wst:RequestType>http://schemas.xmlsoap.org/ws/2004/04/security/trust/Issue</wst:RequestType>
      <wsp:AppliesTo>
        <wsa:EndpointReference>
          <wsa:Address>http://Passport.NET/tb</wsa:Address>
        </wsa:EndpointReference>
      </wsp:AppliesTo>
    </wst:RequestSecurityToken>
    <wst:RequestSecurityToken Id="RST1">
      <wst:RequestType>http://schemas.xmlsoap.org/ws/2004/04/security/trust/Issue</wst:RequestType>
      <wsp:AppliesTo>
        <wsa:EndpointReference>
          <wsa:Address>messengerclear.live.com</wsa:Address>
        </wsa:EndpointReference>
      </wsp:AppliesTo>
      <wsse:PolicyReference URI="'.$this->passport_policy.'"></wsse:PolicyReference>
    </wst:RequestSecurityToken>
    <wst:RequestSecurityToken Id="RST2">
      <wst:RequestType>http://schemas.xmlsoap.org/ws/2004/04/security/trust/Issue</wst:RequestType>
      <wsp:AppliesTo>
        <wsa:EndpointReference>
          <wsa:Address>messenger.msn.com</wsa:Address>
        </wsa:EndpointReference>
      </wsp:AppliesTo>
      <wsse:PolicyReference URI="?id=507"></wsse:PolicyReference>
    </wst:RequestSecurityToken>
    <wst:RequestSecurityToken Id="RST3">
      <wst:RequestType>http://schemas.xmlsoap.org/ws/2004/04/security/trust/Issue</wst:RequestType>
      <wsp:AppliesTo>
        <wsa:EndpointReference>
          <wsa:Address>contacts.msn.com</wsa:Address>
        </wsa:EndpointReference>
      </wsp:AppliesTo>
      <wsse:PolicyReference URI="MBI"></wsse:PolicyReference>
    </wst:RequestSecurityToken>
    <wst:RequestSecurityToken Id="RST4">
      <wst:RequestType>http://schemas.xmlsoap.org/ws/2004/04/security/trust/Issue</wst:RequestType>
      <wsp:AppliesTo>
        <wsa:EndpointReference>
          <wsa:Address>messengersecure.live.com</wsa:Address>
        </wsa:EndpointReference>
      </wsp:AppliesTo>
      <wsse:PolicyReference URI="MBI_SSL"></wsse:PolicyReference>
    </wst:RequestSecurityToken>
    <wst:RequestSecurityToken Id="RST5">
      <wst:RequestType>http://schemas.xmlsoap.org/ws/2004/04/security/trust/Issue</wst:RequestType>
      <wsp:AppliesTo>
        <wsa:EndpointReference>
          <wsa:Address>spaces.live.com</wsa:Address>
        </wsa:EndpointReference>
      </wsp:AppliesTo>
      <wsse:PolicyReference URI="MBI"></wsse:PolicyReference>
    </wst:RequestSecurityToken>
    <wst:RequestSecurityToken Id="RST6">
      <wst:RequestType>http://schemas.xmlsoap.org/ws/2004/04/security/trust/Issue</wst:RequestType>
      <wsp:AppliesTo>
        <wsa:EndpointReference>
          <wsa:Address>storage.msn.com</wsa:Address>
        </wsa:EndpointReference>
      </wsp:AppliesTo>
      <wsse:PolicyReference URI="MBI"></wsse:PolicyReference>
    </wst:RequestSecurityToken>
  </ps:RequestMultipleSecurityTokens>
</Body>
</Envelope>';

        $this->debug_message("*** URL: $passport_url");
        $this->debug_message("*** Sending SOAP:\n$XML");
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $passport_url);
        if ($this->debug) curl_setopt($curl, CURLOPT_HEADER, 1);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $XML);
        $data = curl_exec($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $this->debug_message("*** Get Result:\n$data");

        if ($http_code != 200) {
            // sometimes, rediret to another URL
            // MSNP15
            //<faultcode>psf:Redirect</faultcode>
            //<psf:redirectUrl>https://msnia.login.live.com/pp450/RST.srf</psf:redirectUrl>
            //<faultstring>Authentication Failure</faultstring>
            if (strpos($data, '<faultcode>psf:Redirect</faultcode>') === false) {
                $this->debug_message("*** Can't get passport ticket! http code = $http_code");
                return false;
            }
            preg_match("#<psf\:redirectUrl>(.*)</psf\:redirectUrl>#", $data, $matches);
            if (count($matches) == 0) {
                $this->debug_message("*** redirect, but can't get redirect URL!");
                return false;
            }
            $redirect_url = $matches[1];
            if ($redirect_url == $passport_url) {
                $this->debug_message("*** redirect, but redirect to same URL!");
                return false;
            }
            $this->debug_message("*** redirect to $redirect_url");
            return $this->get_passport_ticket($redirect_url);
        }

        // sometimes, rediret to another URL, also return 200
        // MSNP15
        //<faultcode>psf:Redirect</faultcode>
        //<psf:redirectUrl>https://msnia.login.live.com/pp450/RST.srf</psf:redirectUrl>
        //<faultstring>Authentication Failure</faultstring>
        if (strpos($data, '<faultcode>psf:Redirect</faultcode>') !== false) {
            preg_match("#<psf\:redirectUrl>(.*)</psf\:redirectUrl>#", $data, $matches);
            if (count($matches) != 0) {
                $redirect_url = $matches[1];
                if ($redirect_url == $passport_url) {
                    $this->debug_message("*** redirect, but redirect to same URL!");
                    return false;
                }
                $this->debug_message("*** redirect to $redirect_url");
                return $this->get_passport_ticket($redirect_url);
            }
        }

        // no Redurect faultcode or URL
        // we should get the ticket here

        // we need ticket and secret code
        // RST1: messengerclear.live.com
        // <wsse:BinarySecurityToken Id="Compact1">t=tick&p=</wsse:BinarySecurityToken>
        // <wst:BinarySecret>binary secret</wst:BinarySecret>
        // RST2: messenger.msn.com
        // <wsse:BinarySecurityToken Id="PPToken2">t=tick</wsse:BinarySecurityToken>
        // RST3: contacts.msn.com
        // <wsse:BinarySecurityToken Id="Compact3">t=tick&p=</wsse:BinarySecurityToken>
        // RST4: messengersecure.live.com
        // <wsse:BinarySecurityToken Id="Compact4">t=tick&p=</wsse:BinarySecurityToken>
        // RST5: spaces.live.com
        // <wsse:BinarySecurityToken Id="Compact5">t=tick&p=</wsse:BinarySecurityToken>
        // RST6: storage.msn.com
        // <wsse:BinarySecurityToken Id="Compact6">t=tick&p=</wsse:BinarySecurityToken>
        preg_match("#".
            "<wsse\:BinarySecurityToken Id=\"Compact1\">(.*)</wsse\:BinarySecurityToken>(.*)".
            "<wst\:BinarySecret>(.*)</wst\:BinarySecret>(.*)".
            "<wsse\:BinarySecurityToken Id=\"PPToken2\">(.*)</wsse\:BinarySecurityToken>(.*)".
            "<wsse\:BinarySecurityToken Id=\"Compact3\">(.*)</wsse\:BinarySecurityToken>(.*)".
            "<wsse\:BinarySecurityToken Id=\"Compact4\">(.*)</wsse\:BinarySecurityToken>(.*)".
            "<wsse\:BinarySecurityToken Id=\"Compact5\">(.*)</wsse\:BinarySecurityToken>(.*)".
            "<wsse\:BinarySecurityToken Id=\"Compact6\">(.*)</wsse\:BinarySecurityToken>(.*)".
            "#",
        $data, $matches);

        // no ticket found!
        if (count($matches) == 0) {
            $this->debug_message("*** Can't get passport ticket!");
            return false;
        }

        //$this->debug_message(var_export($matches, true));
        // matches[0]: all data
        // matches[1]: RST1 (messengerclear.live.com) ticket
        // matches[2]: ...
        // matches[3]: RST1 (messengerclear.live.com) binary secret
        // matches[4]: ...
        // matches[5]: RST2 (messenger.msn.com) ticket
        // matches[6]: ...
        // matches[7]: RST3 (contacts.msn.com) ticket
        // matches[8]: ...
        // matches[9]: RST4 (messengersecure.live.com) ticket
        // matches[10]: ...
        // matches[11]: RST5 (spaces.live.com) ticket
        // matches[12]: ...
        // matches[13]: RST6 (storage.live.com) ticket
        // matches[14]: ...

        // so
        // ticket => $matches[1]
        // secret => $matches[3]
        // web_ticket => $matches[5]
        // contact_ticket => $matches[7]
        // oim_ticket => $matches[9]
        // space_ticket => $matches[11]
        // storage_ticket => $matches[13]

        // yes, we get ticket
        $aTickets = array(
            'ticket' => html_entity_decode($matches[1]),
            'secret' => html_entity_decode($matches[3]),
            'web_ticket' => html_entity_decode($matches[5]),
            'contact_ticket' => html_entity_decode($matches[7]),
            'oim_ticket' => html_entity_decode($matches[9]),
            'space_ticket' => html_entity_decode($matches[11]),
            'storage_ticket' => html_entity_decode($matches[13])
        );        
        $this->ticket=$aTickets;
        $this->debug_message(var_export($aTickets, true));
        $ABAuthHeaderArray=array(
  'ABAuthHeader'=>array(
    ':'=>array('xmlns'=>'http://www.msn.com/webservices/AddressBook'),
    'ManagedGroupRequest'=>false,
    'TicketToken'=>htmlspecialchars($this->ticket['contact_ticket']),
        )
        );
        $this->ABAuthHeader=new SoapHeader("http://www.msn.com/webservices/AddressBook","ABAuthHeader", $this->Array2SoapVar($ABAuthHeaderArray));
        file_put_contents('/tmp/STTicket.txt',htmlspecialchars($this->ticket['storage_ticket']));
        //$this->debug_message("StorageTicket:\n",htmlspecialchars($this->ticket['storage_ticket']));
        return $aTickets;
    }
    private function UpdateContacts()
    {
        $ABApplicationHeaderArray=array(
 'ABApplicationHeader'=>array(
  ':'=>array('xmlns'=>'http://www.msn.com/webservices/AddressBook'),
  'ApplicationId'=>'CFE80F9D-180F-4399-82AB-413F33A1FA11',
  'IsMigration'=>false,
  'PartnerScenario'=>'ContactSave'
  )
  );
  $ABApplicationHeader=new SoapHeader("http://www.msn.com/webservices/AddressBook",'ABApplicationHeader', $this->Array2SoapVar($ABApplicationHeaderArray));
  $ABFindAllArray=array(
   'ABFindAll'=>array(
    ':'=>array('xmlns'=>'http://www.msn.com/webservices/AddressBook'),
    'abId'=>'00000000-0000-0000-0000-000000000000',
    'abView'=>'Full',
    'lastChange'=>'0001-01-01T00:00:00.0000000-08:00',
  )
  );
  $ABFindAll=new SoapParam($this->Array2SoapVar($ABFindAllArray),'ABFindAll');
  $this->ABService->__setSoapHeaders(array($ABApplicationHeader,$this->ABAuthHeader));
  $this->Contacts=array();
  try
  {
      $this->debug_message("*** Update Contacts...");
      $Result=$this->ABService->ABFindAll($ABFindAll);
      $this->debug_message("*** Result:\n".print_r($Result,true)."\n".$this->ABService->__getLastResponse());
      foreach($Result->ABFindAllResult->contacts->Contact as $Contact)
      $this->Contacts[$Contact->contactInfo->passportName]=$Contact;
  }
  catch(Exception $e)
  {
      $this->debug_message("*** Update Contacts Error \nRequest:".$this->ABService->__getLastRequest()."\nError:".$e->getMessage());
  }
    }
    protected function addContact($email, $network, $display = '', $sendADL = false)
    {
        if ($network != 1) return true;
        if(isset($this->Contacts[$email])) return true;

        $ABContactAddArray=array(
   'ABContactAdd'=>array(
    ':'=>array('xmlns'=>'http://www.msn.com/webservices/AddressBook'),
    'abId'=>'00000000-0000-0000-0000-000000000000',
    'contacts'=>array(
     'Contact'=>array(
      ':'=>array('xmlns'=>'http://www.msn.com/webservices/AddressBook'),
      'contactInfo'=>array(
       'contactType'=>'LivePending',
       'passportName'=>$email,
       'isMessengerUser'=>true,
       'MessengerMemberInfo'=>array(
        'DisplayName'=>$email
        )
        )
        )
        ),
    'options'=>array(
     'EnableAllowListManagement'=>true
        )
        )
        );
        $ABContactAdd=new SoapParam($this->Array2SoapVar($ABContactAddArray),'ABContactAdd');
        try
        {
            $this->debug_message("*** Add Contacts $email...");
            $this->ABService->ABContactAdd($ABContactAdd);
        }
        catch(Exception $e)
        {
            $this->debug_message("*** Add Contacts Error \nRequest:".$this->ABService->__getLastRequest()."\nError:".$e->getMessage());
        }
        if ($sendADL && !feof($this->NSfp)) {
            @list($u_name, $u_domain) = @explode('@', $email);
            foreach (array('1', '2') as $l) {
                $str = '<ml l="1"><d n="'.$u_domain.'"><c n="'.$u_name.'" l="'.$l.'" t="'.$network.'" /></d></ml>';
                $len = strlen($str);
                // NS: >>> ADL {id} {size}
                $this->ns_writeln("ADL $this->id $len");
                $this->ns_writedata($str);
            }
        }
        $this->UpdateContacts();
        return true;
    }

    function delMemberFromList($memberID, $email, $network, $list) {
        if ($network != 1 && $network != 32) return true;
        if ($memberID === false) return true;
        $user = $email;
        $ticket = htmlspecialchars($this->ticket['contact_ticket']);
        if ($network == 1)
        $XML = '<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"
               xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
               xmlns:xsd="http://www.w3.org/2001/XMLSchema"
               xmlns:soapenc="http://schemas.xmlsoap.org/soap/encoding/">
<soap:Header>
    <ABApplicationHeader xmlns="http://www.msn.com/webservices/AddressBook">
        <ApplicationId>996CDE1E-AA53-4477-B943-2BE802EA6166</ApplicationId>
        <IsMigration>false</IsMigration>
        <PartnerScenario>ContactMsgrAPI</PartnerScenario>
    </ABApplicationHeader>
    <ABAuthHeader xmlns="http://www.msn.com/webservices/AddressBook">
        <ManagedGroupRequest>false</ManagedGroupRequest>
        <TicketToken>'.$ticket.'</TicketToken>
    </ABAuthHeader>
</soap:Header>
<soap:Body>
    <DeleteMember xmlns="http://www.msn.com/webservices/AddressBook">
        <serviceHandle>
            <Id>0</Id>
            <Type>Messenger</Type>
            <ForeignId></ForeignId>
        </serviceHandle>
        <memberships>
            <Membership>
                <MemberRole>'.$list.'</MemberRole>
                <Members>
                    <Member xsi:type="PassportMember" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
                        <Type>Passport</Type>
                        <MembershipId>'.$memberID.'</MembershipId>
                        <State>Accepted</State>
                    </Member>
                </Members>
            </Membership>
        </memberships>
    </DeleteMember>
</soap:Body>
</soap:Envelope>';
        else
        $XML = '<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"
               xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
               xmlns:xsd="http://www.w3.org/2001/XMLSchema"
               xmlns:soapenc="http://schemas.xmlsoap.org/soap/encoding/">
<soap:Header>
    <ABApplicationHeader xmlns="http://www.msn.com/webservices/AddressBook">
        <ApplicationId>996CDE1E-AA53-4477-B943-2BE802EA6166</ApplicationId>
        <IsMigration>false</IsMigration>
        <PartnerScenario>ContactMsgrAPI</PartnerScenario>
    </ABApplicationHeader>
    <ABAuthHeader xmlns="http://www.msn.com/webservices/AddressBook">
        <ManagedGroupRequest>false</ManagedGroupRequest>
        <TicketToken>'.$ticket.'</TicketToken>
    </ABAuthHeader>
</soap:Header>
<soap:Body>
    <DeleteMember xmlns="http://www.msn.com/webservices/AddressBook">
        <serviceHandle>
            <Id>0</Id>
            <Type>Messenger</Type>
            <ForeignId></ForeignId>
        </serviceHandle>
        <memberships>
            <Membership>
                <MemberRole>'.$list.'</MemberRole>
                <Members>
                    <Member xsi:type="EmailMember" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
                        <Type>Email</Type>
                        <MembershipId>'.$memberID.'</MembershipId>
                        <State>Accepted</State>
                    </Member>
                </Members>
            </Membership>
        </memberships>
    </DeleteMember>
</soap:Body>
</soap:Envelope>';

        $header_array = array(
            'SOAPAction: '.$this->delmember_soap,
            'Content-Type: text/xml; charset=utf-8',
            'User-Agent: MSN Explorer/9.0 (MSN 8.0; TmstmpExt)'
            );

            $this->debug_message("*** URL: $this->delmember_url");
            $this->debug_message("*** Sending SOAP:\n$XML");
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $this->delmember_url);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $header_array);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
            if ($this->debug) curl_setopt($curl, CURLOPT_HEADER, 1);
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $XML);
            $data = curl_exec($curl);
            $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            $this->debug_message("*** Get Result:\n$data");

            if ($http_code != 200) {
                preg_match('#<faultcode>(.*)</faultcode><faultstring>(.*)</faultstring>#', $data, $matches);
                if (count($matches) == 0) {
                    $this->log_message("*** can't delete member (network: $network) $email ($memberID) to $list");
                    return false;
                }
                $faultcode = trim($matches[1]);
                $faultstring = trim($matches[2]);
                if (strcasecmp($faultcode, 'soap:Client') || stripos($faultstring, 'Member does not exist') === false) {
                    $this->log_message("*** can't delete member (network: $network) $email ($memberID) to $list, error code: $faultcode, $faultstring");
                    return false;
                }
                $this->log_message("*** delete member (network: $network) $email ($memberID) from $list, not exist");
                return true;
            }
            $this->log_message("*** delete member (network: $network) $email ($memberID) from $list");
            return true;
    }

    function addMemberToList($email, $network, $list) {
        if ($network != 1 && $network != 32) return true;
        $ticket = htmlspecialchars($this->ticket['contact_ticket']);
        $user = $email;

        if ($network == 1)
        $XML = '<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"
               xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
               xmlns:xsd="http://www.w3.org/2001/XMLSchema"
               xmlns:soapenc="http://schemas.xmlsoap.org/soap/encoding/">
<soap:Header>
    <ABApplicationHeader xmlns="http://www.msn.com/webservices/AddressBook">
        <ApplicationId>996CDE1E-AA53-4477-B943-2BE802EA6166</ApplicationId>
        <IsMigration>false</IsMigration>
        <PartnerScenario>ContactMsgrAPI</PartnerScenario>
    </ABApplicationHeader>
    <ABAuthHeader xmlns="http://www.msn.com/webservices/AddressBook">
        <ManagedGroupRequest>false</ManagedGroupRequest>
        <TicketToken>'.$ticket.'</TicketToken>
    </ABAuthHeader>
</soap:Header>
<soap:Body>
    <AddMember xmlns="http://www.msn.com/webservices/AddressBook">
        <serviceHandle>
            <Id>0</Id>
            <Type>Messenger</Type>
            <ForeignId></ForeignId>
        </serviceHandle>
        <memberships>
            <Membership>
                <MemberRole>'.$list.'</MemberRole>
                <Members>
                    <Member xsi:type="PassportMember" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
                        <Type>Passport</Type>
                        <State>Accepted</State>
                        <PassportName>'.$user.'</PassportName>
                    </Member>
                </Members>
            </Membership>
        </memberships>
    </AddMember>
</soap:Body>
</soap:Envelope>';
        else
        $XML = '<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"
               xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
               xmlns:xsd="http://www.w3.org/2001/XMLSchema"
               xmlns:soapenc="http://schemas.xmlsoap.org/soap/encoding/">
<soap:Header>
    <ABApplicationHeader xmlns="http://www.msn.com/webservices/AddressBook">
        <ApplicationId>996CDE1E-AA53-4477-B943-2BE802EA6166</ApplicationId>
        <IsMigration>false</IsMigration>
        <PartnerScenario>ContactMsgrAPI</PartnerScenario>
    </ABApplicationHeader>
    <ABAuthHeader xmlns="http://www.msn.com/webservices/AddressBook">
        <ManagedGroupRequest>false</ManagedGroupRequest>
        <TicketToken>'.$ticket.'</TicketToken>
    </ABAuthHeader>
</soap:Header>
<soap:Body>
    <AddMember xmlns="http://www.msn.com/webservices/AddressBook">
        <serviceHandle>
            <Id>0</Id>
            <Type>Messenger</Type>
            <ForeignId></ForeignId>
        </serviceHandle>
        <memberships>
            <Membership>
                <MemberRole>'.$list.'</MemberRole>
                <Members>
                    <Member xsi:type="EmailMember" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
                        <Type>Email</Type>
                        <State>Accepted</State>
                        <Email>'.$user.'</Email>
                        <Annotations>
                            <Annotation>
                                <Name>MSN.IM.BuddyType</Name>
                                <Value>32:YAHOO</Value>
                            </Annotation>
                        </Annotations>
                    </Member>
                </Members>
            </Membership>
        </memberships>
    </AddMember>
</soap:Body>
</soap:Envelope>';
        $header_array = array(
            'SOAPAction: '.$this->addmember_soap,
            'Content-Type: text/xml; charset=utf-8',
            'User-Agent: MSN Explorer/9.0 (MSN 8.0; TmstmpExt)'
            );

            $this->debug_message("*** URL: $this->addmember_url");
            $this->debug_message("*** Sending SOAP:\n$XML");
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $this->addmember_url);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $header_array);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
            if ($this->debug) curl_setopt($curl, CURLOPT_HEADER, 1);
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $XML);
            $data = curl_exec($curl);
            $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            $this->debug_message("*** Get Result:\n$data");

            if ($http_code != 200) {
                preg_match('#<faultcode>(.*)</faultcode><faultstring>(.*)</faultstring>#', $data, $matches);
                if (count($matches) == 0) {
                    $this->log_message("*** can't add member (network: $network) $email to $list");
                    return false;
                }
                $faultcode = trim($matches[1]);
                $faultstring = trim($matches[2]);
                if (strcasecmp($faultcode, 'soap:Client') || stripos($faultstring, 'Member already exists') === false) {
                    $this->log_message("*** can't add member (network: $network) $email to $list, error code: $faultcode, $faultstring");
                    return false;
                }
                $this->log_message("*** add member (network: $network) $email to $list, already exist!");
                return true;
            }
            $this->log_message("*** add member (network: $network) $email to $list");
            return true;
    }

    function getMembershipList($returnData=false) {
        $ticket = htmlspecialchars($this->ticket['contact_ticket']);
        $XML = '<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"
               xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
               xmlns:xsd="http://www.w3.org/2001/XMLSchema"
               xmlns:soapenc="http://schemas.xmlsoap.org/soap/encoding/">
<soap:Header>
    <ABApplicationHeader xmlns="http://www.msn.com/webservices/AddressBook">
        <ApplicationId>996CDE1E-AA53-4477-B943-2BE802EA6166</ApplicationId>
        <IsMigration>false</IsMigration>
        <PartnerScenario>Initial</PartnerScenario>
    </ABApplicationHeader>
    <ABAuthHeader xmlns="http://www.msn.com/webservices/AddressBook">
        <ManagedGroupRequest>false</ManagedGroupRequest>
        <TicketToken>'.$ticket.'</TicketToken>
    </ABAuthHeader>
</soap:Header>
<soap:Body>
    <FindMembership xmlns="http://www.msn.com/webservices/AddressBook">
        <serviceFilter>
            <Types>
                <ServiceType>Messenger</ServiceType>
                <ServiceType>Invitation</ServiceType>
                <ServiceType>SocialNetwork</ServiceType>
                <ServiceType>Space</ServiceType>
                <ServiceType>Profile</ServiceType>
            </Types>
        </serviceFilter>
    </FindMembership>
</soap:Body>
</soap:Envelope>';
        $header_array = array(
            'SOAPAction: '.$this->membership_soap,
            'Content-Type: text/xml; charset=utf-8',
            'User-Agent: MSN Explorer/9.0 (MSN 8.0; TmstmpExt)'
            );
            $this->debug_message("*** URL: $this->membership_url");
            $this->debug_message("*** Sending SOAP:\n$XML");
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $this->membership_url);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $header_array);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
            if ($this->debug) curl_setopt($curl, CURLOPT_HEADER, 1);
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $XML);
            $data = curl_exec($curl);
            $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            $this->debug_message("*** Get Result:\n$data");
            if($http_code != 200) return array();
            $p = $data;
            $aMemberships = array();
            while (1) {
                //$this->debug_message("search p = $p");
                $start = strpos($p, '<Membership>');
                $end = strpos($p, '</Membership>');
                if ($start === false || $end === false || $start > $end) break;
                //$this->debug_message("start = $start, end = $end");
                $end += 13;
                $sMembership = substr($p, $start, $end - $start);
                $aMemberships[] = $sMembership;
                //$this->debug_message("add sMembership = $sMembership");
                $p = substr($p, $end);
            }
            //$this->debug_message("aMemberships = ".var_export($aMemberships, true));

            $aContactList = array();
            foreach ($aMemberships as $sMembership) {
                //$this->debug_message("sMembership = $sMembership");
                if (isset($matches)) unset($matches);
                preg_match('#<MemberRole>(.*)</MemberRole>#', $sMembership, $matches);
                if (count($matches) == 0) continue;
                $sMemberRole = $matches[1];
                //$this->debug_message("MemberRole = $sMemberRole");
                if ($sMemberRole != 'Allow' && $sMemberRole != 'Reverse' && $sMemberRole != 'Pending') continue;
                $p = $sMembership;
                if (isset($aMembers)) unset($aMembers);
                $aMembers = array();
                while (1) {
                    //$this->debug_message("search p = $p");
                    $start = strpos($p, '<Member xsi:type="');
                    $end = strpos($p, '</Member>');
                    if ($start === false || $end === false || $start > $end) break;
                    //$this->debug_message("start = $start, end = $end");
                    $end += 9;
                    $sMember = substr($p, $start, $end - $start);
                    $aMembers[] = $sMember;
                    //$this->debug_message("add sMember = $sMember");
                    $p = substr($p, $end);
                }
                //$this->debug_message("aMembers = ".var_export($aMembers, true));
                foreach ($aMembers as $sMember) {
                    //$this->debug_message("sMember = $sMember");
                    if (isset($matches)) unset($matches);
                    preg_match('#<Member xsi\:type="([^"]*)">#', $sMember, $matches);
                    if (count($matches) == 0) continue;
                    $sMemberType = $matches[1];
                    //$this->debug_message("MemberType = $sMemberType");
                    $network = -1;
                    preg_match('#<MembershipId>(.*)</MembershipId>#', $sMember, $matches);
                    if (count($matches) == 0) continue;
                    $id = $matches[1];
                    if ($sMemberType == 'PassportMember') {
                        if (strpos($sMember, '<Type>Passport</Type>') === false) continue;
                        $network = 1;
                        preg_match('#<PassportName>(.*)</PassportName>#', $sMember, $matches);
                    }
                    else if ($sMemberType == 'EmailMember') {
                        if (strpos($sMember, '<Type>Email</Type>') === false) continue;
                        // Value is 32: or 32:YAHOO
                        preg_match('#<Annotation><Name>MSN.IM.BuddyType</Name><Value>(.*):(.*)</Value></Annotation>#', $sMember, $matches);
                        if (count($matches) == 0) continue;
                        if ($matches[1] != 32) continue;
                        $network = 32;
                        preg_match('#<Email>(.*)</Email>#', $sMember, $matches);
                    }
                    if ($network == -1) continue;
                    if (count($matches) > 0) {
                        $email = $matches[1];
                        @list($u_name, $u_domain) = @explode('@', $email);
                        if ($u_domain == NULL) continue;
                        $aContactList[$u_domain][$u_name][$network][$sMemberRole] = $id;
                        $this->log_message("*** add new contact (network: $network, status: $sMemberRole): $u_name@$u_domain ($id)");
                    }
                }
            }
            return $aContactList;
    }

    private function connect($user, $password, $redirect_server = '', $redirect_port = 1863) {
        $this->id = 1;
        if ($redirect_server === '') {
            $this->NSfp = @fsockopen($this->server, $this->port, $errno, $errstr, 5);
            if (!$this->NSfp) {
                $this->error = "Can't connect to $this->server:$this->port, error => $errno, $errstr";
                return false;
            }
        }
        else {
            $this->NSfp = @fsockopen($redirect_server, $redirect_port, $errno, $errstr, 5);
            if (!$this->NSfp) {
                $this->error = "Can't connect to $redirect_server:$redirect_port, error => $errno, $errstr";
                return false;
            }
        }

        stream_set_timeout($this->NSfp, $this->NSStreamTimeout);
        $this->authed = false;
        // MSNP9
        // NS: >> VER {id} MSNP9 CVR0
        // MSNP15
        // NS: >>> VER {id} MSNP15 CVR0
        $this->ns_writeln("VER $this->id $this->protocol CVR0");

        $start_tm = time();
        while (!feof($this->NSfp))
        {
            $data = $this->ns_readln();
            // no data?
            if ($data === false) {
                if ($this->timeout > 0) {
                    $now_tm = time();
                    $used_time = ($now_tm >= $start_tm) ? $now_tm - $start_tm : $now_tm;
                    if ($used_time > $this->timeout) {
                        // logout now
                        // NS: >>> OUT
                        $this->ns_writeln("OUT");
                        fclose($this->NSfp);
                        $this->error = 'Timeout, maybe protocol changed!';
                        $this->debug_message("*** $this->error");
                        return false;
                    }
                }
                continue;
            }
            $code = substr($data, 0, 3);
            $start_tm = time();

            switch ($code) {
                case 'VER':
                    // MSNP9
                    // NS: <<< VER {id} MSNP9 CVR0
                    // NS: >>> CVR {id} 0x0409 winnt 5.1 i386 MSMSGS 6.0.0602 msmsgs {user}
                    // MSNP15
                    // NS: <<< VER {id} MSNP15 CVR0
                    // NS: >>> CVR {id} 0x0409 winnt 5.1 i386 MSMSGS 8.1.0178 msmsgs {user}
                    $this->ns_writeln("CVR $this->id 0x0409 winnt 5.1 i386 MSMSGS $this->buildver msmsgs $user");
                    break;

                case 'CVR':
                    // MSNP9
                    // NS: <<< CVR {id} {ver_list} {download_serve} ....
                    // NS: >>> USR {id} TWN I {user}
                    // MSNP15
                    // NS: <<< CVR {id} {ver_list} {download_serve} ....
                    // NS: >>> USR {id} SSO I {user}
                    $this->ns_writeln("USR $this->id $this->login_method I $user");
                    break;

                case 'USR':
                    // already login for passport site, finish the login process now.
                    // NS: <<< USR {id} OK {user} {verify} 0
                    if ($this->authed) return true;
                    // max. 16 digits for password
                    if (strlen($password) > 16)
                    $password = substr($password, 0, 16);

                    $this->user = $user;
                    $this->password = $password;
                    // NS: <<< USR {id} SSO S {policy} {nonce}
                    @list(/* USR */, /* id */, /* SSO */, /* S */, $policy, $nonce,) = @explode(' ', $data);

                    $this->passport_policy = $policy;
                    $aTickets = $this->get_passport_ticket();
                    if (!$aTickets || !is_array($aTickets)) {
                        // logout now
                        // NS: >>> OUT
                        $this->ns_writeln("OUT");
                        fclose($this->NSfp);
                        $this->error = 'Passport authenticated fail!';
                        $this->debug_message("*** $this->error");
                        return false;
                    }

                    $ticket = $aTickets['ticket'];
                    $secret = $aTickets['secret'];
                    $this->ticket = $aTickets;
                    $login_code = $this->generateLoginBLOB($secret, $nonce);

                    // NS: >>> USR {id} SSO S {ticket} {login_code}
                    $this->ns_writeln("USR $this->id $this->login_method S $ticket $login_code");
                    $this->authed = true;
                    break;

                case 'XFR':
                    // main login server will redirect to anther NS after USR command
                    // MSNP9
                    // NS: <<< XFR {id} NS {server} 0 {server}
                    // MSNP15
                    // NS: <<< XFR {id} NS {server} U D
                    @list(/* XFR */, /* id */, $Type, $server, /* ... */) = @explode(' ', $data);
                    if($Type!='NS') break;
                    @list($ip, $port) = @explode(':', $server);
                    // this connection will close after XFR
                    fclose($this->NSfp);

                    $this->NSfp = @fsockopen($ip, $port, $errno, $errstr, 5);
                    if (!$this->NSfp) {
                        $this->error = "Can't connect to $ip:$port, error => $errno, $errstr";
                        $this->debug_message("*** $this->error");
                        return false;
                    }

                    stream_set_timeout($this->NSfp, $this->NSStreamTimeout);
                    // MSNP9
                    // NS: >> VER {id} MSNP9 CVR0
                    // MSNP15
                    // NS: >>> VER {id} MSNP15 CVR0
                    $this->ns_writeln("VER $this->id $this->protocol CVR0");
                    break;

                case 'GCF':
                    // return some policy data after 'USR {id} SSO I {user}' command
                    // NS: <<< GCF 0 {size}
                    @list(/* GCF */, /* 0 */, $size,) = @explode(' ', $data);
                    // we don't need the data, just read it and drop
                    if (is_numeric($size) && $size > 0)
                    $this->ns_readdata($size);
                    break;

                default:
                    // we'll quit if got any error
                    if (is_numeric($code)) {
                        // logout now
                        // NS: >>> OUT
                        $this->ns_writeln("OUT");
                        fclose($this->NSfp);
                        $this->error = "Error code: $code, please check the detail information from: http://msnpiki.msnfanatic.com/index.php/Reference:Error_List";
                        $this->debug_message("*** $this->error");
                        return false;
                    }
                    // unknown response from server, just ignore it
                    break;
            }
        }
        // never goto here
    }

    function derive_key($key, $magic) {
        $hash1 = mhash(MHASH_SHA1, $magic, $key);
        $hash2 = mhash(MHASH_SHA1, $hash1.$magic, $key);
        $hash3 = mhash(MHASH_SHA1, $hash1, $key);
        $hash4 = mhash(MHASH_SHA1, $hash3.$magic, $key);
        return $hash2.substr($hash4, 0, 4);
    }

    function generateLoginBLOB($key, $challenge) {
        $key1 = base64_decode($key);
        $key2 = $this->derive_key($key1, 'WS-SecureConversationSESSION KEY HASH');
        $key3 = $this->derive_key($key1, 'WS-SecureConversationSESSION KEY ENCRYPTION');

        // get hash of challenge using key2
        $hash = mhash(MHASH_SHA1, $challenge, $key2);

        // get 8 bytes random data
        $iv = substr(base64_encode(rand(1000,9999).rand(1000,9999)), 2, 8);

        $cipher = mcrypt_cbc(MCRYPT_3DES, $key3, $challenge."\x08\x08\x08\x08\x08\x08\x08\x08", MCRYPT_ENCRYPT, $iv);

        $blob = pack('LLLLLLL', 28, 1, 0x6603, 0x8004, 8, 20, 72);
        $blob .= $iv;
        $blob .= $hash;
        $blob .= $cipher;

        return base64_encode($blob);
    }

    function getOIM_maildata() {
        preg_match('#t=(.*)&p=(.*)#', $this->ticket['web_ticket'], $matches);
        if (count($matches) == 0) {
            $this->debug_message('*** no web ticket?');
            return false;
        }
        $t = htmlspecialchars($matches[1]);
        $p = htmlspecialchars($matches[2]);
        $XML = '<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
               xmlns:xsd="http://www.w3.org/2001/XMLSchema"
               xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
<soap:Header>
  <PassportCookie xmlns="http://www.hotmail.msn.com/ws/2004/09/oim/rsi">
    <t>'.$t.'</t>
    <p>'.$p.'</p>
  </PassportCookie>
</soap:Header>
<soap:Body>
  <GetMetadata xmlns="http://www.hotmail.msn.com/ws/2004/09/oim/rsi" />
</soap:Body>
</soap:Envelope>';

        $header_array = array(
            'SOAPAction: '.$this->oim_maildata_soap,
            'Content-Type: text/xml; charset=utf-8',
            'User-Agent: Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; SV1; Messenger '.$this->buildver.')'
            );

            $this->debug_message("*** URL: $this->oim_maildata_url");
            $this->debug_message("*** Sending SOAP:\n$XML");
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $this->oim_maildata_url);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $header_array);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
            if ($this->debug) curl_setopt($curl, CURLOPT_HEADER, 1);
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $XML);
            $data = curl_exec($curl);
            $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            $this->debug_message("*** Get Result:\n$data");

            if ($http_code != 200) {
                $this->debug_message("*** Can't get OIM maildata! http code: $http_code");
                return false;
            }

            // <GetMetadataResponse xmlns="http://www.hotmail.msn.com/ws/2004/09/oim/rsi">See #XML_Data</GetMetadataResponse>
            preg_match('#<GetMetadataResponse([^>]*)>(.*)</GetMetadataResponse>#', $data, $matches);
            if (count($matches) == 0) {
                $this->debug_message("*** Can't get OIM maildata");
                return '';
            }
            return $matches[2];
    }

    function getOIM_message($msgid) {
        preg_match('#t=(.*)&p=(.*)#', $this->ticket['web_ticket'], $matches);
        if (count($matches) == 0) {
            $this->debug_message('*** no web ticket?');
            return false;
        }
        $t = htmlspecialchars($matches[1]);
        $p = htmlspecialchars($matches[2]);

        // read OIM
        $XML = '<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
               xmlns:xsd="http://www.w3.org/2001/XMLSchema"
               xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
<soap:Header>
  <PassportCookie xmlns="http://www.hotmail.msn.com/ws/2004/09/oim/rsi">
    <t>'.$t.'</t>
    <p>'.$p.'</p>
  </PassportCookie>
</soap:Header>
<soap:Body>
  <GetMessage xmlns="http://www.hotmail.msn.com/ws/2004/09/oim/rsi">
    <messageId>'.$msgid.'</messageId>
    <alsoMarkAsRead>false</alsoMarkAsRead>
  </GetMessage>
</soap:Body>
</soap:Envelope>';

        $header_array = array(
            'SOAPAction: '.$this->oim_read_soap,
            'Content-Type: text/xml; charset=utf-8',
            'User-Agent: Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; SV1; Messenger '.$this->buildver.')'
            );

            $this->debug_message("*** URL: $this->oim_read_url");
            $this->debug_message("*** Sending SOAP:\n$XML");
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $this->oim_read_url);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $header_array);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
            if ($this->debug) curl_setopt($curl, CURLOPT_HEADER, 1);
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $XML);
            $data = curl_exec($curl);
            $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            $this->debug_message("*** Get Result:\n$data");

            if ($http_code != 200) {
                $this->debug_message("*** Can't get OIM: $msgid, http code = $http_code");
                return false;
            }

            // why can't use preg_match('#<GetMessageResult>(.*)</GetMessageResult>#', $data, $matches)?
            // multi-lines?
            $start = strpos($data, '<GetMessageResult>');
            $end = strpos($data, '</GetMessageResult>');
            if ($start === false || $end === false || $start > $end) {
                $this->debug_message("*** Can't get OIM: $msgid");
                return false;
            }
            $lines = substr($data, $start + 18, $end - $start);
            $aLines = @explode("\n", $lines);
            $header = true;
            $ignore = false;
            $sOIM = '';
            foreach ($aLines as $line) {
                $line = rtrim($line);
                if ($header) {
                    if ($line === '') {
                        $header = false;
                        continue;
                    }
                    continue;
                }
                // stop at empty lines
                if ($line === '') break;
                $sOIM .= $line;
            }
            $sMsg = base64_decode($sOIM);
            $this->debug_message("*** we get OIM ($msgid): $sMsg");

            // delete OIM
            $XML = '<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
               xmlns:xsd="http://www.w3.org/2001/XMLSchema"
               xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
<soap:Header>
  <PassportCookie xmlns="http://www.hotmail.msn.com/ws/2004/09/oim/rsi">
    <t>'.$t.'</t>
    <p>'.$p.'</p>
  </PassportCookie>
</soap:Header>
<soap:Body>
  <DeleteMessages xmlns="http://www.hotmail.msn.com/ws/2004/09/oim/rsi">
    <messageIds>
      <messageId>'.$msgid.'</messageId>
    </messageIds>
  </DeleteMessages>
</soap:Body>
</soap:Envelope>';

            $header_array = array(
            'SOAPAction: '.$this->oim_del_soap,
            'Content-Type: text/xml; charset=utf-8',
            'User-Agent: Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; SV1; Messenger '.$this->buildver.')'
            );

            $this->debug_message("*** URL: $this->oim_del_url");
            $this->debug_message("*** Sending SOAP:\n$XML");
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $this->oim_del_url);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $header_array);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
            if ($this->debug) curl_setopt($curl, CURLOPT_HEADER, 1);
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $XML);
            $data = curl_exec($curl);
            $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            $this->debug_message("*** Get Result:\n$data");

            if ($http_code != 200)
            $this->debug_message("*** Can't delete OIM: $msgid, http code = $http_code");
            else
            $this->debug_message("*** OIM ($msgid) deleted");
            return $sMsg;
    }
    private function NSLogout() {
        if (is_resource($this->NSfp) && !feof($this->NSfp)) {
            // logout now
            // NS: >>> OUT
            $this->ns_writeln("OUT");
            fclose($this->NSfp);
            $this->NSfp = false;
            $this->log_message("*** logout now!");
        }

    }
    private function NSRetryWait($Wait) {
        $this->log_message("*** wait for $Wait seconds");
        for($i=0;$i<$Wait;$i++) {
            sleep(1);
            if($this->kill_me) return false;
        }
        return true;
    }
    public function ProcessSendMessageFileQueue() {
        $aFiles = glob(MSN_CLASS_SPOOL_DIR.DIRECTORY_SEPARATOR.'*.msn');
        if (!is_array($aFiles)) return true;
        clearstatcache();
        foreach ($aFiles as $filename) {
            $fp = fopen($filename, 'rt');
            if (!$fp) continue;
            $aTo = array();
            $sMessage = '';
            $buf = trim(fgets($fp));
            if (substr($buf, 0, 3) == 'TO:') {
                $aTo = @explode(',', str_replace(array("\r","\n","\t",' '),'',substr($buf, 3)));
                while (!feof($fp)) $sMessage.=rtrim(fgets($fp))."\n";
            }
            fclose($fp);
            if (!is_array($aTo) || count($aTo) == 0 || $sMessage == '')
            $this->log_message("!!! message format error? delete $filename");
            else
            {
                foreach($aTo as $To)
                {
                    @list($user, $domain, $network) = @explode('@', $To);
                    $MessageList[$network]["$user@$domain"]=$sMessage;
                }
            }
            if($this->backup_file)
            {
                $backup_dir = MSN_CLASS_SPOOL_DIR.'/backup';
                if (!file_exists($backup_dir)) @mkdir($backup_dir);
                $backup_name = $backup_dir.'/'.strftime('%Y%m%d%H%M%S').'_'.posix_getpid().'_'.basename($filename);
                if (@rename($filename, $backup_name))
                $this->log_message("*** move file to $backup_name");
            }
            else @unlink($filename);
        }
        foreach ($MessageList as $network => $Messages)
        {
            switch(trim($network))
            {
                case '':
                case 1:   //MSN
                    // okay, try to ask a switchboard (SB) for sending message
                    // NS: >>> XFR {id} SB
                    // $this->ns_writeln("XFR $this->id SB");
                    foreach($Messages as $User => $Message)
                    $this->MessageQueue[$User][]=$Message;
                    break;
                case 'Offline':  //MSN
                    //Send OIM
                    //FIXME: 修正Send OIM
                    foreach($Messages as $To => $Message)
                    {
                        $lockkey='';
                        for ($i = 0; $i < $this->oim_try; $i++)
                        {
                            if(($oim_result = $this->sendOIM($To, $Message, $lockkey))===true) break;
                            if (is_array($oim_result) && $oim_result['challenge'] !== false) {
                                // need challenge lockkey
                                $this->log_message("*** we need a new challenge code for ".$oim_result['challenge']);
                                $lockkey = $this->getChallenge($oim_result['challenge']);
                                continue;
                            }
                            if ($oim_result === false || $oim_result['auth_policy'] !== false)
                            {
                                if ($re_login)
                                {
                                    $this->log_message("*** can't send OIM, but we already re-login again, so ignore this OIM");
                                    break;
                                }
                                $this->log_message("*** can't send OIM, maybe ticket expired, try to login again");
                                // maybe we need to re-login again
                                if(!$this->get_passport_ticket())
                                {
                                    $this->log_message("*** can't re-login, something wrong here, ignore this OIM");
                                    break;
                                }
                                $this->log_message("**** get new ticket, try it again");
                                continue;
                            }
                        }
                    }
                    break;
                default:  //Other
                    foreach($Messages as $To => $Message) {
                        $Message=$this->getMessage($Message, $network);
                        $len = strlen($Message);
                        $this->ns_writeln("UUM $this->id $To $network 1 $len");
                        $this->ns_writedata($Message);
                        $this->log_message("*** sent to $To (network: $network):\n$Message");
                    }
            }
        }
        if(isset($this->MessageQueue[$User])&&(!isset($this->MessageQueue[$User]['XFRSent'])))
        {
            $this->MessageQueue[$User]['XFRSent']=false;
            $this->MessageQueue[$User]['ReqTime']=false;
        }
        return true;
    }
    public function SignalFunction($signal)
    {
        switch($signal)
        {
            case SIGTRAP:
            case SIGTERM:
            case SIGHUP:
                $this->End();
                return;
            case SIGCHLD:
                $ChildPid=pcntl_wait($status,WUNTRACED);
                if($ChildPid>0)
                {
                    $this->log_message("*** Child Process End for ".$this->ChildProcess[$ChildPid]);
                    unset($this->ChildProcess[$ChildPid]);
                }
                return;
        }
    }

    public function Run()
    {
        $this->log_message("*** startup ***");
        if(!pcntl_signal(SIGCHLD,array($this,'SignalFunction'))) die("Signal SIGCHLD Error\n");
        if(!pcntl_signal(SIGTERM,array($this,'SignalFunction'))) die("Signal SIGTERM Error\n");
        if(!pcntl_signal(SIGTRAP,array($this,'SignalFunction'))) die("Signal SIGTRAP Error\n");
        $process_file = false;
        $sent = false;
        $aADL = array();
        $aContactList = array();
        while (true)
        {
            if($this->kill_me)
            {
                $this->log_message("*** Okay, kill me now!");
                return $this->NSLogout();
            }
            if (!is_resource($this->NSfp) || feof($this->NSfp))
            {
                $this->log_message("*** try to connect to MSN network");
                if (!$this->connect($this->user, $this->password))
                {
                    $this->log_message("!!! Can't connect to server: $this->error");
                    if(!$this->NSRetryWait($this->retry_wait)) continue;
                }
                $this->UpdateContacts();
                $this->LastPing=time();
                $this->log_message("*** connected, wait for command");
                $start_tm = time();
                $ping_tm = time();
                stream_set_timeout($this->NSfp, $this->NSStreamTimeout);
                    $aContactList = $this->getMembershipList();
                    if ($this->update_pending) {
                        if (is_array($aContactList)) {
                            $pending = 'Pending';
                            foreach ($aContactList as $u_domain => $aUserList) {
                                foreach ($aUserList as $u_name => $aNetworks) {
                                    foreach ($aNetworks as $network => $aData) {
                                        if (isset($aData[$pending])) {
                                            // pending list
                                            $cnt = 0;
                                            foreach (array('Allow', 'Reverse') as $list) {
                                                if (isset($aData[$list]))
                                                $cnt++;
                                                else {
                                                    if ($this->addMemberToList($u_name.'@'.$u_domain, $network, $list)) {
                                                        $aContactList[$u_domain][$u_name][$network][$list] = false;
                                                        $cnt++;
                                                    }
                                                }
                                            }
                                            if ($cnt >= 2) {
                                                $id = $aData[$pending];
                                                // we can delete it from pending now
                                                if ($this->delMemberFromList($id, $u_name.'@'.$u_domain, $network, $pending))
                                                unset($aContactList[$u_domain][$u_name][$network][$pending]);
                                            }
                                        }
                                        else {
                                            // sync list
                                            foreach (array('Allow', 'Reverse') as $list) {
                                                if (!isset($aData[$list])) {
                                                    if ($this->addMemberToList($u_name.'@'.$u_domain, $network, $list))
                                                    $aContactList[$u_domain][$u_name][$network][$list] = false;
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                    $n = 0;
                    $sList = '';
                    $len = 0;
                    if (is_array($aContactList)) {
                        foreach ($aContactList as $u_domain => $aUserList) {
                            $str = '<d n="'.$u_domain.'">';
                            $len += strlen($str);
                            if ($len > 7400) {
                                $aADL[$n] = '<ml l="1">'.$sList.'</ml>';
                                $n++;
                                $sList = '';
                                $len = strlen($str);
                            }
                            $sList .= $str;
                            foreach ($aUserList as $u_name => $aNetworks) {
                                foreach ($aNetworks as $network => $status) {
                                    $str = '<c n="'.$u_name.'" l="3" t="'.$network.'" />';
                                    $len += strlen($str);
                                    // max: 7500, but <ml l="1"></d></ml> is 19,
                                    // so we use 7475
                                    if ($len > 7475) {
                                        $sList .= '</d>';
                                        $aADL[$n] = '<ml l="1">'.$sList.'</ml>';
                                        $n++;
                                        $sList = '<d n="'.$u_domain.'">'.$str;
                                        $len = strlen($sList);
                                    }
                                    else
                                    $sList .= $str;
                                }
                            }
                            $sList .= '</d>';
                        }
                    }
                    $aADL[$n] = '<ml l="1">'.$sList.'</ml>';
                    // NS: >>> BLP {id} BL
                    $this->ns_writeln("BLP $this->id BL");
                    foreach ($aADL as $str) {
                        $len = strlen($str);
                        // NS: >>> ADL {id} {size}
                        $this->ns_writeln("ADL $this->id $len");
                        $this->ns_writedata($str);
                    }
                    // NS: >>> PRP {id} MFN name
                    if ($this->alias == '') $this->alias = $user;
                    $aliasname = rawurlencode($this->alias);
                    $this->ns_writeln("PRP $this->id MFN $aliasname");
                    //設定個人大頭貼
                    //$MsnObj=$this->PhotoStckObj();
                    // NS: >>> CHG {id} {status} {clientid} {msnobj}
                    $this->ns_writeln("CHG $this->id NLN $this->clientid");                    
                    if($this->PhotoStickerFile!==false)
                        $this->ns_writeln("CHG $this->id NLN $this->clientid ".rawurlencode($this->MsnObj($this->PhotoStickerFile)));
                    // NS: >>> UUX {id} length
                    $str = '<Data><PSM>'.htmlspecialchars($this->psm).'</PSM><CurrentMedia></CurrentMedia><MachineGuid></MachineGuid></Data>';
                    $len = strlen($str);
                    $this->ns_writeln("UUX $this->id $len");
                    $this->ns_writedata($str);               
            }
            $data = $this->ns_readln();
            if($data===false)
            {
                //If No NS Message Process SendMessageFileQueue
                if (time()-$this->LastPing > $this->ping_wait)
                {
                    // NS: >>> PNG
                    $this->ns_writeln("PNG");
                    $this->LastPing = time();
                }
                if(count($this->ChildProcess)<$this->MAXChildProcess)
                {
                    $Index=0;
                    foreach($this->MessageQueue as $User => $Message)
                    {
                        if(!trim($User)) continue;
                        if($Inxdex>=$this->MAXChildProcess-count($this->ChildProcess)) break;
                        if((!$Message['XFRSent'])||($Message['XFRSent']&&(time()-$this->MessageQueue[$User]['ReqTime']>$this->ReqSBXFRTimeout)))
                        {
                            $this->MessageQueue[$User]['XFRSent']=true;
                            $this->MessageQueue[$User]['ReqTime']=time();
                            $this->log_message("*** Request SB for $User");
                            $this->ns_writeln("XFR $this->id SB");
                            $Index++;
                        }
                    }
                }
                if($this->ProcessSendMessageFileQueue()) continue;
                break;
            }
            switch (substr($data,0,3))
            {
                case 'SBS':
                    // after 'USR {id} OK {user} {verify} 0' response, the server will send SBS and profile to us
                    // NS: <<< SBS 0 null
                    break;

                case 'RFS':
                    // FIXME:
                    // NS: <<< RFS ???
                    // refresh ADL, so we re-send it again
                    if (is_array($aADL)) {
                        foreach ($aADL as $str) {
                            $len = strlen($str);
                            // NS: >>> ADL {id} {size}
                            $this->ns_writeln("ADL $this->id $len");
                            $this->ns_writedata($str);
                        }
                    }
                    break;

                case 'LST':
                    // NS: <<< LST {email} {alias} 11 0
                    @list(/* LST */, $email, /* alias */, ) = @explode(' ', $data);
                    @list($u_name, $u_domain) = @explode('@', $email);
                    if (!isset($aContactList[$u_domain][$u_name][1])) {
                        $aContactList[$u_domain][$u_name][1]['Allow'] = 'Allow';
                        $this->log_message("*** add to our contact list: $u_name@$u_domain");
                    }
                    break;

                case 'ADL':
                    // randomly, we get ADL command, someome add us to their contact list for MSNP15
                    // NS: <<< ADL 0 {size}
                    @list(/* ADL */, /* 0 */, $size,) = @explode(' ', $data);
                    if (is_numeric($size) && $size > 0)
                    {
                        $data = $this->ns_readdata($size);
                        preg_match('#<ml><d n="([^"]+)"><c n="([^"]+)"(.*) t="(\d*)"(.*) /></d></ml>#', $data, $matches);
                        if (is_array($matches) && count($matches) > 0)
                        {
                            $u_domain = $matches[1];
                            $u_name = $matches[2];
                            $network = $matches[4];
                            if (isset($aContactList[$u_domain][$u_name][$network]))
                            $this->log_message("*** someone (network: $network) add us to their list (but already in our list): $u_name@$u_domain");
                            else
                            {
                                $re_login = false;
                                $cnt = 0;
                                foreach (array('Allow', 'Reverse') as $list)
                                {
                                    if (!$this->addMemberToList($u_name.'@'.$u_domain, $network, $list))
                                    {
                                        if ($re_login) {
                                            $this->log_message("*** can't add $u_name@$u_domain (network: $network) to $list");
                                            continue;
                                        }
                                        $aTickets = $this->get_passport_ticket();
                                        if (!$aTickets || !is_array($aTickets)) {
                                            // failed to login? ignore it
                                            $this->log_message("*** can't re-login, something wrong here");
                                            $this->log_message("*** can't add $u_name@$u_domain (network: $network) to $list");
                                            continue;
                                        }
                                        $re_login = true;
                                        $this->ticket = $aTickets;
                                        $this->log_message("**** get new ticket, try it again");
                                        if (!$this->addMemberToList($u_name.'@'.$u_domain, $network, $list))
                                        {
                                            $this->log_message("*** can't add $u_name@$u_domain (network: $network) to $list");
                                            continue;
                                        }
                                    }
                                    $aContactList[$u_domain][$u_name][$network][$list] = false;
                                    $cnt++;
                                }
                                $this->log_message("*** someone (network: $network) add us to their list: $u_name@$u_domain");
                            }
                            $str = '<ml l="1"><d n="'.$u_domain.'"><c n="'.$u_name.'" l="3" t="'.$network.'" /></d></ml>';
                            $len = strlen($str);
                        }
                        else
                        $this->log_message("*** someone add us to their list: $data");
                        $this->AddUsToMemberList($u_name.'@'.$u_domain, $network);
                    }
                    break;

                case 'RML':
                    // randomly, we get RML command, someome remove us to their contact list for MSNP15
                    // NS: <<< RML 0 {size}
                    @list(/* RML */, /* 0 */, $size,) = @explode(' ', $data);
                    if (is_numeric($size) && $size > 0)
                    {
                        $data = $this->ns_readdata($size);
                        preg_match('#<ml><d n="([^"]+)"><c n="([^"]+)"(.*) t="(\d*)"(.*) /></d></ml>#', $data, $matches);
                        if (is_array($matches) && count($matches) > 0)
                        {
                            $u_domain = $matches[1];
                            $u_name = $matches[2];
                            $network = $matches[4];
                            if (isset($aContactList[$u_domain][$u_name][$network]))
                            {
                                $aData = $aContactList[$u_domain][$u_name][$network];
                                foreach ($aData as $list => $id)
                                $this->delMemberFromList($id, $u_name.'@'.$u_domain, $network, $list);
                                unset($aContactList[$u_domain][$u_name][$network]);
                                $this->log_message("*** someone (network: $network) remove us from their list: $u_name@$u_domain");
                            }
                            else
                            $this->log_message("*** someone (network: $network) remove us from their list (but not in our list): $u_name@$u_domain");
                            $this->RemoveUsFromMemberList($u_name.'@'.$u_domain, $network);
                        }
                        else
                        $this->log_message("*** someone remove us from their list: $data");
                    }
                    break;

                case 'MSG':
                    // randomly, we get MSG notification from server
                    // NS: <<< MSG Hotmail Hotmail {size}
                    @list(/* MSG */, /* Hotmail */, /* Hotmail */, $size,) = @explode(' ', $data);
                    if (is_numeric($size) && $size > 0) {
                        $data = $this->ns_readdata($size);
                        $aLines = @explode("\n", $data);
                        $header = true;
                        $ignore = false;
                        $maildata = '';
                        foreach ($aLines as $line) {
                            $line = rtrim($line);
                            if ($header) {
                                if ($line === '') {
                                    $header = false;
                                    continue;
                                }
                                if (strncasecmp($line, 'Content-Type:', 13) == 0) {
                                    if (strpos($line, 'text/x-msmsgsinitialmdatanotification') === false &&
                                    strpos($line, 'text/x-msmsgsoimnotification') === false) {
                                        // we just need text/x-msmsgsinitialmdatanotification
                                        // or text/x-msmsgsoimnotification
                                        $ignore = true;
                                        break;
                                    }
                                }
                                continue;
                            }
                            if (strncasecmp($line, 'Mail-Data:', 10) == 0) {
                                $maildata = trim(substr($line, 10));
                                break;
                            }
                        }
                        if ($ignore) {
                            $this->log_message("*** ingnore MSG for: $line");
                            break;
                        }
                        if ($maildata == '') {
                            $this->log_message("*** ingnore MSG not for OIM");
                            break;
                        }
                        $re_login = false;
                        if (strcasecmp($maildata, 'too-large') == 0) {
                            $this->log_message("*** large mail-data, need to get the data via SOAP");
                            $maildata = $this->getOIM_maildata();
                            if ($maildata === false) {
                                $this->log_message("*** can't get mail-data via SOAP");
                                // maybe we need to re-login again
                                $aTickets = $this->get_passport_ticket();
                                if (!$aTickets || !is_array($aTickets)) {
                                    // failed to login? ignore it
                                    $this->log_message("*** can't re-login, something wrong here, ignore this OIM");
                                    break;
                                }
                                $re_login = true;
                                $this->ticket = $aTickets;
                                $this->log_message("**** get new ticket, try it again");
                                $maildata = $this->getOIM_maildata();
                                if ($maildata === false) {
                                    $this->log_message("*** can't get mail-data via SOAP, and we already re-login again, so ignore this OIM");
                                    break;
                                }
                            }
                        }
                        // could be a lots of <M>...</M>, so we can't use preg_match here
                        $p = $maildata;
                        $aOIMs = array();
                        while (1) {
                            $start = strpos($p, '<M>');
                            $end = strpos($p, '</M>');
                            if ($start === false || $end === false || $start > $end) break;
                            $end += 4;
                            $sOIM = substr($p, $start, $end - $start);
                            $aOIMs[] = $sOIM;
                            $p = substr($p, $end);
                        }
                        if (count($aOIMs) == 0) {
                            $this->log_message("*** ingnore empty OIM");
                            break;
                        }
                        foreach ($aOIMs as $maildata) {
                            // T: 11 for MSN, 13 for Yahoo
                            // S: 6 for MSN, 7 for Yahoo
                            // RT: the datetime received by server
                            // RS: already read or not
                            // SZ: size of message
                            // E: sender
                            // I: msgid
                            // F: always 00000000-0000-0000-0000-000000000009
                            // N: sender alias
                            preg_match('#<T>(.*)</T>#', $maildata, $matches);
                            if (count($matches) == 0) {
                                $this->log_message("*** ingnore OIM maildata without <T>type</T>");
                                continue;
                            }
                            $oim_type = $matches[1];
                            if ($oim_type = 13)
                            $network = 32;
                            else
                            $network = 1;
                            preg_match('#<E>(.*)</E>#', $maildata, $matches);
                            if (count($matches) == 0) {
                                $this->log_message("*** ingnore OIM maildata without <E>sender</E>");
                                continue;
                            }
                            $oim_sender = $matches[1];
                            preg_match('#<I>(.*)</I>#', $maildata, $matches);
                            if (count($matches) == 0) {
                                $this->log_message("*** ingnore OIM maildata without <I>msgid</I>");
                                continue;
                            }
                            $oim_msgid = $matches[1];
                            preg_match('#<SZ>(.*)</SZ>#', $maildata, $matches);
                            $oim_size = (count($matches) == 0) ? 0 : $matches[1];
                            preg_match('#<RT>(.*)</RT>#', $maildata, $matches);
                            $oim_time = (count($matches) == 0) ? 0 : $matches[1];
                            $this->log_message("*** You've OIM sent by $oim_sender, Time: $oim_time, MSGID: $oim_msgid, size: $oim_size");
                            $sMsg = $this->getOIM_message($oim_msgid);
                            if ($sMsg === false) {
                                $this->log_message("*** can't get OIM, msgid = $oim_msgid");
                                if ($re_login) {
                                    $this->log_message("*** can't get OIM via SOAP, and we already re-login again, so ignore this OIM");
                                    continue;
                                }
                                $aTickets = $this->get_passport_ticket();
                                if (!$aTickets || !is_array($aTickets)) {
                                    // failed to login? ignore it
                                    $this->log_message("*** can't re-login, something wrong here, ignore this OIM");
                                    continue;
                                }
                                $re_login = true;
                                $this->ticket = $aTickets;
                                $this->log_message("**** get new ticket, try it again");
                                $sMsg = $this->getOIM_message($oim_msgid);
                                if ($sMsg === false) {
                                    $this->log_message("*** can't get OIM via SOAP, and we already re-login again, so ignore this OIM");
                                    continue;
                                }
                            }
                            $this->log_message("*** MSG (Offline) from $oim_sender (network: $network): $sMsg");

                            $this->ReceivedMessage($oim_sender,$sMsg,$network,true);
                        }
                    }
                    break;

                case 'UBM':
                    // randomly, we get UBM, this is the message from other network, like Yahoo!
                    // NS: <<< UBM {email} $network $type {size}
                    @list(/* UBM */, $from_email, $network, $type, $size,) = @explode(' ', $data);
                    if (is_numeric($size) && $size > 0)
                    {
                        $data = $this->ns_readdata($size);
                        $aLines = @explode("\n", $data);
                        $header = true;
                        $ignore = false;
                        $sMsg = '';
                        foreach ($aLines as $line) {
                            $line = rtrim($line);
                            if ($header) {
                                if ($line === '') {
                                    $header = false;
                                    continue;
                                }
                                if (strncasecmp($line, 'TypingUser:', 11) == 0) {
                                    $ignore = true;
                                    break;
                                }
                                continue;
                            }
                            $aSubLines = @explode("\r", $line);
                            foreach ($aSubLines as $str) {
                                if ($sMsg !== '')
                                $sMsg .= "\n";
                                $sMsg .= $str;
                            }
                        }
                        if($ignore)
                        {
                            $this->log_message("*** ingnore from $from_email: $line");
                            break;
                        }
                        $this->log_message("*** MSG from $from_email (network: $network): $sMsg");
                        $this->ReceivedMessage($from_email,$sMsg,$network,false);
                    }
                    break;

                case 'UBX':
                    // randomly, we get UBX notification from server
                    // NS: <<< UBX email {network} {size}
                    @list(/* UBX */, /* email */, /* network */, $size,) = @explode(' ', $data);
                    // we don't need the notification data, so just ignore it
                    if (is_numeric($size) && $size > 0)
                    $this->ns_readdata($size);
                    break;

                case 'CHL':
                    // randomly, we'll get challenge from server
                    // NS: <<< CHL 0 {code}
                    @list(/* CHL */, /* 0 */, $chl_code,) = @explode(' ', $data);
                    $fingerprint = $this->getChallenge($chl_code);
                    // NS: >>> QRY {id} {product_id} 32
                    // NS: >>> fingerprint
                    $this->ns_writeln("QRY $this->id $this->prod_id 32");
                    $this->ns_writedata($fingerprint);
                    $this->ns_writeln("CHG $this->id NLN $this->clientid");                    
                    if($this->PhotoStickerFile!==false)
                        $this->ns_writeln("CHG $this->id NLN $this->clientid ".rawurlencode($this->MsnObj($this->PhotoStickerFile)));
                    break;
                case 'CHG':
                    // NS: <<< CHG {id} {status} {code}
                    // ignore it
                    // change our status to online first
                    break;

                case 'XFR':
                    // sometimes, NS will redirect to another NS
                    // MSNP9
                    // NS: <<< XFR {id} NS {server} 0 {server}
                    // MSNP15
                    // NS: <<< XFR {id} NS {server} U D
                    // for normal switchboard XFR
                    // NS: <<< XFR {id} SB {server} CKI {cki} U messenger.msn.com 0
                    @list(/* XFR */, /* {id} */, $server_type, $server, /* CKI */, $cki_code, /* ... */) = @explode(' ', $data);
                    @list($ip, $port) = @explode(':', $server);
                    if ($server_type != 'SB') {
                        // maybe exit?
                        // this connection will close after XFR
                        $this->NSLogout();
                        continue;
                    }
                    if(count($this->MessageQueue))
                    {
                        foreach($this->MessageQueue as $User => $Message)
                        {
                            //$this->ChildProcess[$ChildPid]
                            $this->log_message("*** XFR SB $User");
                            $pid=pcntl_fork();
                            if($pid)
                            {
                                //Parrent Process
                                $this->ChildProcess[$pid]=$User;
                                break;
                            }
                            elseif($pid==-1)
                            {
                                $this->log_message("*** Fork Error $User");
                                break;
                            }
                            else
                            {
                                //Child Process
                                $this->log_message("*** Child Process Start for $User");
                                unset($Message['XFRSent']);
                                unset($Message['ReqTime']);
                                $bSBresult = $this->switchboard_control($ip, $port, $cki_code, $User, $Message);
                                if ($bSBresult === false)
                                {
                                    // error for switchboard
                                    $this->log_message("!!! error for sending message to ".$User);
                                }
                                die;
                            }
                        }
                        unset($this->MessageQueue[$User]);
                    }
                    /*
                     $bSBresult = $this->switchboard_control($ip, $port, $cki_code, $aMSNUsers[$nCurrentUser], $sMessage);
                     if ($bSBresult === false) {
                     // error for switchboard
                     $this->log_message("!!! error for sending message to ".$aMSNUsers[$nCurrentUser]);
                     $aOfflineUsers[] = $aMSNUsers[$nCurrentUser];
                     }*/
                    break;
                case 'QNG':
                    // NS: <<< QNG {time}
                    @list(/* QNG */, $this->ping_wait) = @explode(' ', $data);
                    if ($this->ping_wait == 0) $this->ping_wait = 50;
                    //if (is_int($use_ping) && $use_ping > 0) $ping_wait = $use_ping;
                    //Mod by Ricky Set Online
                    break;

                case 'RNG':
                    if($this->PhotoStickerFile!==false)
                        $this->ns_writeln("CHG $this->id NLN $this->clientid ".rawurlencode($this->MsnObj($this->PhotoStickerFile)));
                    else
                        $this->ns_writeln("CHG $this->id NLN $this->clientid");
                    // someone is trying to talk to us
                    // NS: <<< RNG {session_id} {server} {auth_type} {ticket} {email} {alias} U {client} 0
                    $this->log_message("NS: <<< RNG $data");
                    @list(/* RNG */, $sid, $server, /* auth_type */, $ticket, $email, $name, ) = @explode(' ', $data);
                    @list($sb_ip, $sb_port) = @explode(':', $server);
                    if($this->IsIgnoreMail($email)) 
                    {
                        $this->log_message("*** Ignore RNG from $email");
                        break;
                    }
                    $this->log_message("*** RING from $email, $sb_ip:$sb_port");
                    $this->addContact($email,1,$email, true);
                    $pid=pcntl_fork();
                    if($pid)
                    {
                        //Parrent Process
                        $this->ChildProcess[$pid]='RNG';
                        break;
                    }
                    elseif($pid==-1)
                    {
                        $this->log_message("*** Fork Error $User");
                        break;
                    }
                    else
                    {
                        //Child Process
                        $this->log_message("*** Ring Child Process Start for $User");
                        $this->switchboard_ring($sb_ip, $sb_port, $sid, $ticket,$email);
                        die;
                    }
                    break;
                case 'OUT':
                    // force logout from NS
                    // NS: <<< OUT xxx
                    fclose($this->NSfp);
                    $this->log_message("*** LOGOUT from NS");
                    break;

                default:
                    $code = substr($data,0,3);
                    if (is_numeric($code)) {
                        $this->error = "Error code: $code, please check the detail information from: http://msnpiki.msnfanatic.com/index.php/Reference:Error_List";
                        $this->debug_message("*** NS: $this->error");

                        return $this->NsLogout();
                    }
                    break;
            }
        }
        return $this->NsLogout();
    }

    /*public function SendMessage($Message, $To)
    {
        $FileName = MSN_CLASS_SPOOL_DIR.'/'.strftime('%Y%m%d%H%M%S',time()).'_'.posix_getpid().'_sendMessage.msn';
        if(!is_array($To))
        $To=array($To);
        $Receiver='';
        foreach($To as $Email)
        {
            list($name,$host,$network)=explode('@',$Email);
            $network=$network==''?1:$network;
            if($network==1 && $this->SwitchBoardProcess && $this->SwitchBoardSessionUser=="$name@$host" )
            {
                $this->debug_message("*** SendMessage to $Receiver use SB message queue.");
                array_push($this->SwitchBoardMessageQueue,$Message);
                continue;
            }
            $Receiver.="$name@$host@$network,";
        }
        if($Receiver=='') return;
        $Receiver=substr($Receiver,0,-1);
        $this->debug_message("*** SendMessage to $Receiver use File queue.");
        file_put_contents($FileName,"TO: $Receiver\n$Message\n");
    }*/

    function getChallenge($code)
    {
        // MSNP15
        // http://msnpiki.msnfanatic.com/index.php/MSNP11:Challenges
        // Step 1: The MD5 Hash
        $md5Hash = md5($code.$this->prod_key);
        $aMD5 = @explode("\0", chunk_split($md5Hash, 8, "\0"));
        for ($i = 0; $i < 4; $i++) {
            $aMD5[$i] = implode('', array_reverse(@explode("\0", chunk_split($aMD5[$i], 2, "\0"))));
            $aMD5[$i] = (0 + base_convert($aMD5[$i], 16, 10)) & 0x7FFFFFFF;
        }

        // Step 2: A new string
        $chl_id = $code.$this->prod_id;
        $chl_id .= str_repeat('0', 8 - (strlen($chl_id) % 8));

        $aID = @explode("\0", substr(chunk_split($chl_id, 4, "\0"), 0, -1));
        for ($i = 0; $i < count($aID); $i++) {
            $aID[$i] = implode('', array_reverse(@explode("\0", chunk_split($aID[$i], 1, "\0"))));
            $aID[$i] = 0 + base_convert(bin2hex($aID[$i]), 16, 10);
        }

        // Step 3: The 64 bit key
        $magic_num = 0x0E79A9C1;
        $str7f = 0x7FFFFFFF;
        $high = 0;
        $low = 0;
        for ($i = 0; $i < count($aID); $i += 2) {
            $temp = $aID[$i];
            $temp = bcmod(bcmul($magic_num, $temp), $str7f);
            $temp = bcadd($temp, $high);
            $temp = bcadd(bcmul($aMD5[0], $temp), $aMD5[1]);
            $temp = bcmod($temp, $str7f);

            $high = $aID[$i+1];
            $high = bcmod(bcadd($high, $temp), $str7f);
            $high = bcadd(bcmul($aMD5[2], $high), $aMD5[3]);
            $high = bcmod($high, $str7f);

            $low = bcadd(bcadd($low, $high), $temp);
        }

        $high = bcmod(bcadd($high, $aMD5[1]), $str7f);
        $low = bcmod(bcadd($low, $aMD5[3]), $str7f);

        $new_high = bcmul($high & 0xFF, 0x1000000);
        $new_high = bcadd($new_high, bcmul($high & 0xFF00, 0x100));
        $new_high = bcadd($new_high, bcdiv($high & 0xFF0000, 0x100));
        $new_high = bcadd($new_high, bcdiv($high & 0xFF000000, 0x1000000));
        // we need integer here
        $high = 0+$new_high;

        $new_low = bcmul($low & 0xFF, 0x1000000);
        $new_low = bcadd($new_low, bcmul($low & 0xFF00, 0x100));
        $new_low = bcadd($new_low, bcdiv($low & 0xFF0000, 0x100));
        $new_low = bcadd($new_low, bcdiv($low & 0xFF000000, 0x1000000));
        // we need integer here
        $low = 0+$new_low;

        // we just use 32 bits integer, don't need the key, just high/low
        // $key = bcadd(bcmul($high, 0x100000000), $low);

        // Step 4: Using the key
        $md5Hash = md5($code.$this->prod_key);
        $aHash = @explode("\0", chunk_split($md5Hash, 8, "\0"));

        $hash = '';
        $hash .= sprintf("%08x", (0 + base_convert($aHash[0], 16, 10)) ^ $high);
        $hash .= sprintf("%08x", (0 + base_convert($aHash[1], 16, 10)) ^ $low);
        $hash .= sprintf("%08x", (0 + base_convert($aHash[2], 16, 10)) ^ $high);
        $hash .= sprintf("%08x", (0 + base_convert($aHash[3], 16, 10)) ^ $low);

        return $hash;
    }

    private function getMessage($sMessage, $network = 1)
    {
        $msg_header = "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nX-MMS-IM-Format: FN=$this->font_fn; EF=$this->font_ef; CO=$this->font_co; CS=0; PF=22\r\n\r\n";
        $msg_header_len = strlen($msg_header);
        if ($network == 1)
            $maxlen = $this->max_msn_message_len - $msg_header_len;
        else
            $maxlen = $this->max_yahoo_message_len - $msg_header_len;
        $sMessage=str_replace("\r", '', $sMessage);
        $msg=substr($sMessage,0,$maxlen);
        return $msg_header.$msg;
    }
    /**
     *
     * @param $Action 連線模式 'Active' => 主動傳送訊息,'Passive' => 接收訊息
     * @param $Param
     * @return boolean
     */
    private function DoSwitchBoard($Action,$Param)
    {
        $SessionEnd=false;
        $Joined=false;
        $id=1;
        $LastActive=time();
        stream_set_timeout($this->SBFp, $this->SBTimeout);
        switch($Action)
        {
            case 'Active':
                $cki_code=$Param['cki'];
                $user=$Param['user'];
                $this->SwitchBoardMessageQueue=$Param['Msg'];
                // SB: >>> USR {id} {user} {cki}
                $this->SB_writeln("USR $id $this->user $cki_code");
                $id++;
                $this->SwitchBoardSessionUser=$user;
                break;
            case 'Passive':
                $ticket=$Param['ticket'];
                $sid=$Param['sid'];
                $user=$Param['user'];
                // SB: >>> ANS {id} {user} {ticket} {session_id}
                $this->SB_writeln("ANS $id $this->user $ticket $sid");
                $id++;
                $this->SwitchBoardSessionUser=$user;
                break;
            default:
                return false;
        }
        while((!feof($this->SBFp))&&(!$SessionEnd))
        {
            $data = $this->SB_readln();
            if($this->kill_me)
            {
                $this->log_message("*** SB Okay, kill me now!");
                break;
            }
            if($data === false)
            {
                if(time()-$LastActive > $this->SBIdleTimeout)
                {
                    $this->debug_message("*** SB Idle Timeout!");
                    break;
                }
                if(!$Joined) continue;
                foreach($this->SwitchBoardMessageQueue as $Message)
                {
                    if($Message=='') continue;
                    $aMessage = $this->getMessage($Message);
                    //CheckEmotion...
                    $MsnObjDefine=$this->GetMsnObjDefine($aMessage);
                    if($MsnObjDefine!=='')
                    {
                        $SendString="MIME-Version: 1.0\r\nContent-Type: text/x-mms-emoticon\r\n\r\n$MsnObjDefine";
                        $len = strlen($SendString);
                        $this->SB_writeln("MSG $id N $len");
                        $id++;
                        $this->SB_writedata($SendString);
                        $this->id++;
                    }
                    $len = strlen($aMessage);
                    $this->SB_writeln("MSG $id N $len");
                    $id++;
                    $this->SB_writedata($aMessage);
                }
                $this->SwitchBoardMessageQueue=array();
                if(!$this->IsIgnoreMail($user)) $LastActive = time();
                continue;
            }
            $code = substr($data, 0, 3);
            switch($code)
            {
                case 'IRO':
                    // SB: <<< IRO {id} {rooster} {roostercount} {email} {alias} {clientid}
                    @list(/* IRO */, /* id */, $cur_num, $total, $email, $alias, $clientid) = @explode(' ', $data);
                    $this->log_message("*** $email join us");
                    $Joined=true;
                    break;
                case 'BYE':
                    $this->log_message("*** Quit for BYE");
                    $SessionEnd=true;
                    break;
                case 'USR':
                    // SB: <<< USR {id} OK {user} {alias}
                    // we don't need the data, just ignore it
                    // request user to join this switchboard
                    // SB: >>> CAL {id} {user}
                    $this->SB_writeln("CAL $id $user");
                    $id++;
                    break;
                case 'CAL':
                    // SB: <<< CAL {id} RINGING {?}
                    // we don't need this, just ignore, and wait for other response
                    $this->id++;
                    break;
                case 'JOI':
                    // SB: <<< JOI {user} {alias} {clientid?}
                    // someone join us
                    // we don't need the data, just ignore it
                    // no more user here
                    $Joined=true;
                    break;
                case 'MSG':
                    // SB: <<< MSG {email} {alias} {len}
                    @list(/* MSG */, $from_email, /* alias */, $len, ) = @explode(' ', $data);
                    $len = trim($len);
                    $data = $this->SB_readdata($len);
                    $aLines = @explode("\n", $data);
                    $header = true;
                    $ignore = false;
                    $is_p2p = false;
                    $sMsg = '';
                    foreach ($aLines as $line)
                    {
                        $line = rtrim($line);
                        if ($header) {
                            if ($line === '') {
                                $header = false;
                                continue;
                            }
                            if (strncasecmp($line, 'TypingUser:', 11) == 0) {
                                // typing notification, just ignore
                                $ignore = true;
                                break;
                            }
                            if (strncasecmp($line, 'Chunk:', 6) == 0) {
                                // we don't handle any split message, just ignore
                                $ignore = true;
                                break;
                            }
                            if (strncasecmp($line, 'Content-Type: application/x-msnmsgrp2p', 38) == 0) {
                                // p2p message, ignore it, but we need to send acknowledgement for it...
                                $is_p2p = true;
                                $p = strstr($data, "\n\n");
                                $sMsg = '';
                                if ($p === false) {
                                    $p = strstr($data, "\r\n\r\n");
                                    if ($p !== false)
                                    $sMsg = substr($p, 4);
                                }
                                else
                                $sMsg = substr($p, 2);
                                break;
                            }
                            if (strncasecmp($line, 'Content-Type: application/x-', 28) == 0) {
                                // ignore all application/x-... message
                                // for example:
                                //      application/x-ms-ink        => ink message
                                $ignore = true;
                                break;
                            }
                            if (strncasecmp($line, 'Content-Type: text/x-', 21) == 0) {
                                // ignore all text/x-... message
                                // for example:
                                //      text/x-msnmsgr-datacast         => nudge, voice clip....
                                //      text/x-mms-animemoticon         => customized animemotion word
                                $ignore = true;
                                break;
                            }
                            continue;
                        }
                        if ($sMsg !== '')
                        $sMsg .= "\n";
                        $sMsg .= $line;
                    }
                    if ($ignore)
                    {
                        $this->log_message("*** ingnore from $from_email: $line");
                        break;
                    }
                    if ($is_p2p)
                    {
                        // we will ignore any p2p message after sending acknowledgement
                        $ignore = true;
                        $len = strlen($sMsg);
                        $this->log_message("*** p2p message from $from_email, size $len");
                        // header = 48 bytes
                        // content >= 0 bytes
                        // footer = 4 bytes
                        // so it need to >= 52 bytes
                        /*if ($len < 52) {
                            $this->log_message("*** p2p: size error, less than 52!");
                            break;
                        }*/
                        $aDwords = @unpack("V12dword", $sMsg);
                        if (!is_array($aDwords)) {
                            $this->log_message("*** p2p: header unpack error!");
                            break;
                        }
                        $this->debug_message("*** p2p: dump received message:\n".$this->dump_binary($sMsg));
                        $hdr_SessionID = $aDwords['dword1'];
                        $hdr_Identifier = $aDwords['dword2'];
                        $hdr_DataOffsetLow = $aDwords['dword3'];
                        $hdr_DataOffsetHigh = $aDwords['dword4'];
                        $hdr_TotalDataSizeLow = $aDwords['dword5'];
                        $hdr_TotalDataSizeHigh = $aDwords['dword6'];
                        $hdr_MessageLength = $aDwords['dword7'];
                        $hdr_Flag = $aDwords['dword8'];
                        $hdr_AckID = $aDwords['dword9'];
                        $hdr_AckUID = $aDwords['dword10'];
                        $hdr_AckSizeLow = $aDwords['dword11'];
                        $hdr_AckSizeHigh = $aDwords['dword12'];
                        $this->debug_message("*** p2p: header SessionID = $hdr_SessionID");
                        $this->debug_message("*** p2p: header Inentifier = $hdr_Identifier");
                        $this->debug_message("*** p2p: header Data Offset Low = $hdr_DataOffsetLow");
                        $this->debug_message("*** p2p: header Data Offset High = $hdr_DataOffsetHigh");
                        $this->debug_message("*** p2p: header Total Data Size Low = $hdr_TotalDataSizeLow");
                        $this->debug_message("*** p2p: header Total Data Size High = $hdr_TotalDataSizeHigh");
                        $this->debug_message("*** p2p: header MessageLength = $hdr_MessageLength");
                        $this->debug_message("*** p2p: header Flag = $hdr_Flag");
                        $this->debug_message("*** p2p: header AckID = $hdr_AckID");
                        $this->debug_message("*** p2p: header AckUID = $hdr_AckUID");
                        $this->debug_message("*** p2p: header AckSize Low = $hdr_AckSizeLow");
                        $this->debug_message("*** p2p: header AckSize High = $hdr_AckSizeHigh");
                        if($hdr_Flag==2) {
                            //This is an ACK from SB ignore....
                            $this->debug_message("*** p2p: //This is an ACK from SB ignore....:\n");
                            break;
                        }
                        $MsgBody=$this->linetoArray(substr($sMsg,48,-4));
                        $this->debug_message("*** p2p: body".print_r($MsgBody,true));
                        if(($MsgBody['EUF-GUID']=='{A4268EEC-FEC5-49E5-95C3-F126696BDBF6}')&&($PictureFilePath=$this->GetPictureFilePath($MsgBody['Context'])))
                        {
                            while(true)
                            {
                                if($this->SB_readln()===false) break;
                            }
                            $this->debug_message("*** p2p: Inv hdr:\n".$this->dump_binary(substr($sMsg,0,48)));
                            preg_match('/{([0-9A-F\-]*)}/i',$MsgBody['Via'],$Matches);
                            $BranchGUID=$Matches[1];
                            //it's an invite to send a display picture.
                            $new_id = ~$hdr_Identifier;
                            $hdr = pack("LLLLLLLLLLLL", $hdr_SessionID,
                            $new_id,
                            0, 0,
                            $hdr_TotalDataSizeLow, $hdr_TotalDataSizeHigh,
                            0,
                            2,
                            $hdr_Identifier,
                            $hdr_AckID,
                            $hdr_TotalDataSizeLow, $hdr_TotalDataSizeHigh);
                            $footer = pack("L", 0);
                            $message = "MIME-Version: 1.0\r\nContent-Type: application/x-msnmsgrp2p\r\nP2P-Dest: $from_email\r\n\r\n$hdr$footer";
                            $len = strlen($message);
                            $this->SB_writeln("MSG $id D $len");
                            $id++;
                            $this->SB_writedata($message);
                            $this->log_message("*** p2p: send display picture acknowledgement for $hdr_SessionID");
                            $this->debug_message("*** p2p: Invite ACK message:\n".$this->dump_binary($message));                            
                            $this->SB_readln();//Read ACK;                            
                            $this->debug_message("*** p2p: Invite ACK Hdr:\n".$this->dump_binary($hdr));
                            $new_id-=3;
                            //Send 200 OK message
                            $MessageContent="SessionID: ".$MsgBody['SessionID']."\r\n\r\n".pack("C", 0);
                            $MessagePayload=
                                "MSNSLP/1.0 200 OK\r\n".
                                "To: <msnmsgr:".$from_email.">\r\n".
                                "From: <msnmsgr:".$this->user.">\r\n".
                                "Via: ".$MsgBody['Via']."\r\n".
                                "CSeq: ".($MsgBody['CSeq']+1)."\r\n".
                                "Call-ID: ".$MsgBody['Call-ID']."\r\n".
                                "Max-Forwards: 0\r\n".
                                "Content-Type: application/x-msnmsgr-sessionreqbody\r\n".
                                "Content-Length: ".strlen($MessageContent)."\r\n\r\n".
                            $MessageContent;
                            $hdr_TotalDataSizeLow=strlen($MessagePayload);
                            $hdr_TotalDataSizeHigh=0;
                            $hdr = pack("LLLLLLLLLLLL", $hdr_SessionID,
                            $new_id,
                            0, 0,
                            $hdr_TotalDataSizeLow, $hdr_TotalDataSizeHigh,
                            strlen($MessagePayload),
                            0,
                            rand(),
                            0,
                            0,0);

                            $message =
                                "MIME-Version: 1.0\r\n".
                                "Content-Type: application/x-msnmsgrp2p\r\n".
                                "P2P-Dest: $from_email\r\n\r\n$hdr$MessagePayload$footer";
                            $this->SB_writeln("MSG $id D ".strlen($message));
                            $id++;
                            $this->SB_writedata($message);
                            $this->debug_message("*** p2p: dump 200 ok message:\n".$this->dump_binary($message));
                            $this->SB_readln();//Read ACK;
                            
                            $this->debug_message("*** p2p: 200 ok:\n".$this->dump_binary($hdr));
                            //send Data preparation message
                            //send 4 null bytes as data
                            $hdr_TotalDataSizeLow=4;
                            $hdr_TotalDataSizeHigh=0;
                            $new_id++;
                            $hdr = pack("LLLLLLLLLLLL",
                            $MsgBody['SessionID'],
                            $new_id,
                            0, 0,
                            $hdr_TotalDataSizeLow, $hdr_TotalDataSizeHigh,
                            $hdr_TotalDataSizeLow,
                            0,
                            rand(),
                            0,
                            0,0);
                            $message =
                                "MIME-Version: 1.0\r\n".
                                "Content-Type: application/x-msnmsgrp2p\r\n".
                                "P2P-Dest: $from_email\r\n\r\n$hdr".pack('L',0)."$footer";
                            $this->SB_writeln("MSG $id D ".strlen($message));
                            $id++;
                            $this->SB_writedata($message);
                            $this->debug_message("*** p2p: dump send Data preparation message:\n".$this->dump_binary($message));
                            $this->debug_message("*** p2p: Data Prepare Hdr:\n".$this->dump_binary($hdr));
                            $this->SB_readln();//Read ACK;

                            //send Data Content..
                            $footer=pack('N',1);
                            $new_id++;
                            $FileSize=filesize($PictureFilePath);
                            if($hTitle=fopen($PictureFilePath,'rb'))
                            {
                                $Offset=0;
                                //$new_id++;
                                while(!feof($hTitle))
                                {
                                    $FileContent=fread($hTitle,1024);
                                    $FileContentSize=strlen($FileContent);
                                    $hdr = pack("LLLLLLLLLLLL",
                                    $MsgBody['SessionID'],
                                    $new_id,
                                    $Offset, 0,
                                    $FileSize,0,
                                    $FileContentSize,
                                    0x20,
                                    rand(),
                                    0,
                                    0,0
                                    );
                                    $message =
                                        "MIME-Version: 1.0\r\n".
                                        "Content-Type: application/x-msnmsgrp2p\r\n".
                                        "P2P-Dest: $from_email\r\n\r\n$hdr$FileContent$footer";
                                    $this->SB_writeln("MSG $id D ".strlen($message));
                                    $id++;
                                    $this->SB_writedata($message);
                                    $this->debug_message("*** p2p: dump send Data Content message  $Offset / $FileSize :\n".$this->dump_binary($message));
                                    $this->debug_message("*** p2p: Data Content Hdr:\n".$this->dump_binary($hdr));
                                    //$this->SB_readln();//Read ACK;
                                    $Offset+=$FileContentSize;
                                }
                            }
                            //Send Bye
                            /*
                            $MessageContent="\r\n".pack("C", 0);
                            $MessagePayload=
                                "BYE MSNMSGR:MSNSLP/1.0\r\n".
                                "To: <msnmsgr:$from_email>\r\n".
                                "From: <msnmsgr:".$this->user.">\r\n".
                                "Via: MSNSLP/1.0/TLP ;branch={".$BranchGUID."}\r\n".                            
                                "CSeq: 0\r\n".
                                "Call-ID: ".$MsgBody['Call-ID']."\r\n".
                                "Max-Forwards: 0\r\n".
                                "Content-Type: application/x-msnmsgr-sessionclosebody\r\n".
                                "Content-Length: ".strlen($MessageContent)."\r\n\r\n".$MessageContent;
                            $footer=pack('N',0);
                            $hdr_TotalDataSizeLow=strlen($MessagePayload);
                            $hdr_TotalDataSizeHigh=0;
                            $new_id++;
                            $hdr = pack("LLLLLLLLLLLL", 
                            0,
                            $new_id,
                            0, 0,
                            $hdr_TotalDataSizeLow, $hdr_TotalDataSizeHigh,
                            0,
                            0,
                            rand(),
                            0,
                            0,0);
                            $message =
                                        "MIME-Version: 1.0\r\n".
                                        "Content-Type: application/x-msnmsgrp2p\r\n".
                                        "P2P-Dest: $from_email\r\n\r\n$hdr$MessagePayload$footer";
                            $this->SB_writeln("MSG $id D ".strlen($message));
                            $id++;
                            $this->SB_writedata($message);
                            $this->debug_message("*** p2p: dump send BYE message :\n".$this->dump_binary($message));
                            */
                            break;
                        }
                        //TODO:
                        //if ($hdr_Flag == 2) {
                        // just send ACK...
                        //    $this->SB_writeln("ACK $id");
                        //    break;
                        //}
                        if ($hdr_SessionID == 4) {
                            // ignore?
                            $this->debug_message("*** p2p: ignore flag 4");
                            break;
                        }
                        $finished = false;
                        if ($hdr_TotalDataSizeHigh == 0) {
                            // only 32 bites size
                            if (($hdr_MessageLength + $hdr_DataOffsetLow) == $hdr_TotalDataSizeLow)
                            $finished = true;
                        }
                        else {
                            // we won't accept any file transfer
                            // so I think we won't get any message size need to use 64 bits
                            // 64 bits size here, can't count directly...
                            $totalsize = base_convert(sprintf("%X%08X", $hdr_TotalDataSizeHigh, $hdr_TotalDataSizeLow), 16, 10);
                            $dataoffset = base_convert(sprintf("%X%08X", $hdr_DataOffsetHigh, $hdr_DataOffsetLow), 16, 10);
                            $messagelength = base_convert(sprintf("%X", $hdr_MessageLength), 16, 10);
                            $now_size = bcadd($dataoffset, $messagelength);
                            if (bccomp($now_size, $totalsize) >= 0)
                            $finished = true;
                        }
                        if (!$finished) {
                            // ignore not finished split packet
                            $this->debug_message("*** p2p: ignore split packet, not finished");
                            break;
                        }
                        //$new_id = ~$hdr_Identifier;
                        /*
                         $new_id++;
                         $hdr = pack("LLLLLLLLLLLL", $hdr_SessionID,
                         $new_id,
                         0, 0,
                         $hdr_TotalDataSizeLow, $hdr_TotalDataSizeHigh,
                         0,
                         2,
                         $hdr_Identifier,
                         $hdr_AckID,
                         $hdr_TotalDataSizeLow, $hdr_TotalDataSizeHigh);
                         $footer = pack("L", 0);
                         $message = "MIME-Version: 1.0\r\nContent-Type: application/x-msnmsgrp2p\r\nP2P-Dest: $from_email\r\n\r\n$hdr$footer";
                         $len = strlen($message);
                         $this->SB_writeln("MSG $id D $len");
                         $id++;
                         $this->SB_writedata($message);
                         $this->log_message("*** p2p: send acknowledgement for $hdr_SessionID");
                         $this->debug_message("*** p2p: dump sent message:\n".$this->dump_binary($hdr.$footer));
                         */
                        break;
                    }
                    $this->log_message("*** MSG from $from_email: $sMsg");
                    $this->ReceivedMessage($from_email,$sMsg,$network,false);
                    break;
                case '217':
                    $this->log_message("*** User $user is offline. Try OIM.");
                    foreach($this->SwitchBoardMessageQueue as $Message)
                    $this->SendMessage($Message,"$user@Offline");
                    $SessionEnd=true;
                    break;
                default:
                    if (is_numeric($code))
                    {
                        $this->error = "Error code: $code, please check the detail information from: http://msnpiki.msnfanatic.com/index.php/Reference:Error_List";
                        $this->debug_message("*** SB: $this->error");
                        $SessionEnd=true;
                    }
                    break;
            }
            if(!$this->IsIgnoreMail($user)) $LastActive = time();
        }
        if (feof($this->SBFp))
        {
            // lost connection? error? try OIM later
            @fclose($this->SBFp);
            return false;
        }
        $this->SB_writeln("OUT");
        @fclose($this->SBFp);
        return true;
    }
    private function switchboard_control($ip, $port, $cki_code, $user, $Messages)
    {
        $this->SwitchBoardProcess=1;
        $this->debug_message("*** SB: try to connect to switchboard server $ip:$port");
        $this->SBFp = @fsockopen($ip, $port, $errno, $errstr, 5);
        if (!$this->SBFp)
        {
            $this->debug_message("*** SB: Can't connect to $ip:$port, error => $errno, $errstr");
            return false;
        }
        return $this->DoSwitchBoard('Active',array('cki'=>$cki_code, 'user'=>$user,'Msg'=>$Messages));
    }
    private function switchboard_ring($ip, $port, $sid, $ticket,$user)
    {
        $this->SwitchBoardProcess=2;
        $this->debug_message("*** SB: try to connect to switchboard server $ip:$port");
        $this->SBFp = @fsockopen($ip, $port, $errno, $errstr, 5);
        if (!$this->SBFp)
        {
            $this->debug_message("*** SB: Can't connect to $ip:$port, error => $errno, $errstr");
            return false;
        }
        return $this->DoSwitchBoard('Passive',array('sid'=>$sid,'user'=>$user,'ticket'=>$ticket));
    }

    private function sendOIM($to, $sMessage, $lockkey)
    {
        $XML = '<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
               xmlns:xsd="http://www.w3.org/2001/XMLSchema"
               xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
<soap:Header>
  <From memberName="'.$this->user.'"
        friendlyName="=?utf-8?B?'.base64_encode($this->user).'?="
        xml:lang="zh-TW"
        proxy="MSNMSGR"
        xmlns="http://messenger.msn.com/ws/2004/09/oim/"
        msnpVer="'.$this->protocol.'"
        buildVer="'.$this->buildver.'"/>
  <To memberName="'.$to.'" xmlns="http://messenger.msn.com/ws/2004/09/oim/"/>
  <Ticket passport="'.htmlspecialchars($this->ticket['oim_ticket']).'"
          appid="'.$this->prod_id.'"
          lockkey="'.$lockkey.'"
          xmlns="http://messenger.msn.com/ws/2004/09/oim/"/>
  <Sequence xmlns="http://schemas.xmlsoap.org/ws/2003/03/rm">
    <Identifier xmlns="http://schemas.xmlsoap.org/ws/2002/07/utility">http://messenger.msn.com</Identifier>
    <MessageNumber>1</MessageNumber>
  </Sequence>
</soap:Header>
<soap:Body>
  <MessageType xmlns="http://messenger.msn.com/ws/2004/09/oim/">text</MessageType>
  <Content xmlns="http://messenger.msn.com/ws/2004/09/oim/">MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8
Content-Transfer-Encoding: base64
X-OIM-Message-Type: OfflineMessage
X-OIM-Run-Id: {DAB68CFA-38C9-449B-945E-38AFA51E50A7}
X-OIM-Sequence-Num: 1

'.chunk_split(base64_encode($sMessage)).'
  </Content>
</soap:Body>
</soap:Envelope>';

        $header_array = array(
            'SOAPAction: '.$this->oim_send_soap,
            'Content-Type: text/xml',
            'User-Agent: Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; SV1; Messenger '.$this->buildver.')'
            );

            $this->debug_message("*** URL: $this->oim_send_url");
            $this->debug_message("*** Sending SOAP:\n$XML");
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $this->oim_send_url);
            curl_setopt($curl, CURLOPT_HTTPHEADER, $header_array);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
            if ($this->debug) curl_setopt($curl, CURLOPT_HEADER, 1);
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $XML);
            $data = curl_exec($curl);
            $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            $this->debug_message("*** Get Result:\n$data");

            if ($http_code == 200) {
                $this->debug_message("*** OIM sent for $to");
                return true;
            }

            $challenge = false;
            $auth_policy = false;
            // the lockkey is invalid, authenticated fail, we need challenge it again
            // <LockKeyChallenge xmlns="http://messenger.msn.com/ws/2004/09/oim/">364763969</LockKeyChallenge>
            preg_match("#<LockKeyChallenge (.*)>(.*)</LockKeyChallenge>#", $data, $matches);
            if (count($matches) != 0) {
                // yes, we get new LockKeyChallenge
                $challenge = $matches[2];
                $this->debug_message("*** OIM need new challenge ($challenge) for $to");
            }
            // auth policy error
            // <RequiredAuthPolicy xmlns="http://messenger.msn.com/ws/2004/09/oim/">MBI_SSL</RequiredAuthPolicy>
            preg_match("#<RequiredAuthPolicy (.*)>(.*)</RequiredAuthPolicy>#", $data, $matches);
            if (count($matches) != 0) {
                $auth_policy = $matches[2];
                $this->debug_message("*** OIM need new auth policy ($auth_policy) for $to");
            }
            if ($auth_policy === false && $challenge === false) {
                //<faultcode xmlns:q0="http://messenger.msn.com/ws/2004/09/oim/">q0:AuthenticationFailed</faultcode>
                preg_match("#<faultcode (.*)>(.*)</faultcode>#", $data, $matches);
                if (count($matches) == 0) {
                    // no error, we assume the OIM is sent
                    $this->debug_message("*** OIM sent for $to");
                    return true;
                }
                $err_code = $matches[2];
                //<faultstring>Exception of type 'System.Web.Services.Protocols.SoapException' was thrown.</faultstring>
                preg_match("#<faultstring>(.*)</faultstring>#", $data, $matches);
                if (count($matches) > 0)
                $err_msg = $matches[1];
                else
                $err_msg = '';
                $this->debug_message("*** OIM failed for $to");
                $this->debug_message("*** OIM Error code: $err_code");
                $this->debug_message("*** OIM Error Message: $err_msg");
                return false;
            }
            return array('challenge' => $challenge, 'auth_policy' => $auth_policy);
    }

    // read data for specified size
    private function ns_readdata($size) {
        $data = '';
        $count = 0;
        while (!feof($this->NSfp)) {
            $buf = @fread($this->NSfp, $size - $count);
            $data .= $buf;
            $count += strlen($buf);
            if ($count >= $size) break;
        }
        $this->debug_message("NS: data ($size/$count) <<<\n$data");
        return $data;
    }

    // read one line
    private function ns_readln() {
        $data = @fgets($this->NSfp, 4096);
        if ($data !== false) {
            $data = trim($data);
            $this->debug_message("NS: <<< $data");
        }
        return $data;
    }

    // write to server, append \r\n, also increase id
    private function ns_writeln($data) {
        @fwrite($this->NSfp, $data."\r\n");
        $this->debug_message("NS: >>> $data");
        $this->id++;
        return;
    }

    // write data to server
    private function ns_writedata($data) {
        @fwrite($this->NSfp, $data);
        $this->debug_message("NS: >>> $data");
        return;
    }

    // read data for specified size for SB
    private function sb_readdata($size) {
        $data = '';
        $count = 0;
        while (!feof($this->SBFp)) {
            $buf = @fread($this->SBFp, $size - $count);
            $data .= $buf;
            $count += strlen($buf);
            if ($count >= $size) break;
        }
        $this->debug_message("SB: data ($size/$count) <<<\n$data");
        return $data;
    }

    // read one line for SB
    private function sb_readln() {
        $data = @fgets($this->SBFp, 4096);
        if ($data !== false) {
            $data = trim($data);
            $this->debug_message("SB: <<< $data");
        }
        return $data;
    }

    // write to server for SB, append \r\n, also increase id
    // switchboard server only accept \r\n, it will lost connection if just \n only
    private function sb_writeln($data) {
        @fwrite($this->SBFp, $data."\r\n");
        $this->debug_message("SB: >>> $data");
        $this->id++;
        return;
    }

    // write data to server
    private function sb_writedata($data) {
        @fwrite($this->SBFp, $data);
        $this->debug_message("SB: >>> $data");
        return;
    }

    // show debug information
    function debug_message($str) {
        if (!$this->debug) return;
        if($this->debug===STDOUT) echo $str."\n";
        /*$fname=MSN_CLASS_LOG_DIR.DIRECTORY_SEPARATOR.'msn_'.strftime('%Y%m%d').'.debug';
        $fp = fopen($fname, 'at');
        if ($fp) {
            fputs($fp, strftime('%m/%d/%y %H:%M:%S').' ['.posix_getpid().'] '.$str."\n");
            fclose($fp);
            return;
        }*/
        // still show debug information, if we can't open log_file
        echo $str."\n";
        return;
    }

    function dump_binary($str) {
        $buf = '';
        $a_str = '';
        $h_str = '';
        $len = strlen($str);
        for ($i = 0; $i < $len; $i++) {
            if (($i % 16) == 0) {
                if ($buf !== '') {
                    $buf .= "$h_str $a_str\n";
                }
                $buf .= sprintf("%04X:", $i);
                $a_str = '';
                $h_str = '';
            }
            $ch = ord($str[$i]);
            if ($ch < 32)
            $a_str .= '.';
            else
            $a_str .= chr($ch);
            $h_str .= sprintf(" %02X", $ch);
        }
        if ($h_str !== '')
        $buf .= "$h_str $a_str\n";
        return $buf;
    }

    // write log
    function log_message($str) {
        /*$fname = MSN_CLASS_LOG_DIR.DIRECTORY_SEPARATOR.'msn_'.strftime('%Y%m%d').'.log';
        $fp = fopen($fname, 'at');
        if ($fp) {
            fputs($fp, strftime('%m/%d/%y %H:%M:%S').' ['.posix_getpid().'] '.$str."\n");
            fclose($fp);
        }*/
        $this->debug_message($str);
        return;
    }
    /**
     *
     * @param $FilePath 圖檔路徑
     * @param $Type     檔案類型 3=>大頭貼,2表情圖案    
     * @return array
     */
    private function MsnObj($FilePath,$Type=3)
    {
        if(!($FileSize=filesize($FilePath))) return '';
        $Location=md5($FilePath);
        $Friendly=md5($FilePath.$Type);
        if(isset($this->MsnObjMap[$Location])) return $this->MsnObjMap[$Location];
        $sha1d=base64_encode(sha1(file_get_contents($FilePath),true));
        $sha1c=base64_encode(sha1("Creator".$this->user."Size$FileSize"."Type$Type"."Location$Location"."Friendly".$Friendly."SHA1D$sha1d",true));
        $this->MsnObjArray[$Location]=$FilePath;
        $MsnObj='<msnobj Creator="'.$this->user.'" Size="'.$FileSize.'" Type="'.$Type.'" Location="'.$Location.'" Friendly="'.$Friendly.'" SHA1D="'.$sha1d.'" SHA1C="'.$sha1c.'"/>';
        $this->MsnObjMap[$Location]=$MsnObj;
        $this->debug_message("*** p2p: addMsnObj $FilePath::$MsnObj\n");
        return $MsnObj;
    }
    private function linetoArray($lines) {
        $lines=str_replace("\r",'',$lines);
        $lines=explode("\n",$lines);
        foreach($lines as $line) {
            if(!isset($line{3})) continue;
            list($Key,$Val)=explode(':',$line);
            $Data[trim($Key)]=trim($Val);
        }
        return $Data;
    }
    private function GetPictureFilePath($Context)
    {
        $MsnObj=base64_decode($Context);
        if(preg_match('/location="(.*?)"/i',$MsnObj,$Match))
        $location=$Match[1];
        $this->debug_message("*** p2p: PictureFile[$location] ::All".print_r($this->MsnObjArray,true)."\n");
        if($location&&(isset($this->MsnObjArray[$location])))
        return $this->MsnObjArray[$location];
        return false;
    }
    private function GetMsnObjDefine($Message)
    {
        $DefineString='';
        if(is_array($this->Emotions))
        foreach($this->Emotions as $Pattern => $FilePath)
        {
            if(strpos($Message,$Pattern)!==false)
            $DefineString.="$Pattern\t".$this->MsnObj($FilePath,2)."\t";
        }
        return $DefineString;
    }
    /**
     * Receive Message Overload Function
     * @param $Sender
     * @param $Message
     * @param $Network   1 => msn , 32 =>yahoo
     * @param $IsOIM
     * @return unknown_type
     */
    protected function ReceivedMessage($Sender,$Message,$Network,$IsOIM=false){}
    /**
     * Remove Us From Member List Overload Function
     * @param $User
     * @param $Message
     * @param $Network   1 => msn , 32 =>yahoo
     * @return unknown_type
     */
    protected function RemoveUsFromMemberList($User,$Network){}
    /**
     * Add Us to Member List Overload Function
     * @param $User
     * @param $Message
     * @param $Network   1 => msn , 32 =>yahoo
     * @return unknown_type
     */
    protected function AddUsToMemberList($User,$Network){}
    
    public function signon() {
        $this->log_message("*** try to connect to MSN network");
        while(!$this->connect($this->user, $this->password))
        {
            $this->log_message("!!! Can't connect to server: $this->error");
            $this->callHandler('ConnectFailed', NULL);
            $this->NSRetryWait($this->retry_wait);
        }
        $this->UpdateContacts();
        $this->LastPing=time();
        $this->log_message("*** connected, wait for command");
        $start_tm = time();
        $ping_tm = time();
        stream_set_timeout($this->NSfp, $this->NSStreamTimeout);
        $this->aContactList = $this->getMembershipList();
        if ($this->update_pending) {
            if (is_array($this->aContactList)) {
                $pending = 'Pending';
                foreach ($this->aContactList as $u_domain => $aUserList) {
                    foreach ($aUserList as $u_name => $aNetworks) {
                        foreach ($aNetworks as $network => $aData) {
                            if (isset($aData[$pending])) {
                                // pending list
                                $cnt = 0;
                                foreach (array('Allow', 'Reverse') as $list) {
                                    if (isset($aData[$list]))
                                    $cnt++;
                                    else {
                                        if ($this->addMemberToList($u_name.'@'.$u_domain, $network, $list)) {
                                            $this->aContactList[$u_domain][$u_name][$network][$list] = false;
                                            $cnt++;
                                        }
                                    }
                                }
                                if ($cnt >= 2) {
                                    $id = $aData[$pending];
                                    // we can delete it from pending now
                                    if ($this->delMemberFromList($id, $u_name.'@'.$u_domain, $network, $pending))
                                    unset($this->aContactList[$u_domain][$u_name][$network][$pending]);
                                }
                            }
                            else {
                                // sync list
                                foreach (array('Allow', 'Reverse') as $list) {
                                    if (!isset($aData[$list])) {
                                        if ($this->addMemberToList($u_name.'@'.$u_domain, $network, $list))
                                        $this->aContactList[$u_domain][$u_name][$network][$list] = false;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        $n = 0;
        $sList = '';
        $len = 0;
        if (is_array($this->aContactList)) {
            foreach ($this->aContactList as $u_domain => $aUserList) {
                $str = '<d n="'.$u_domain.'">';
                $len += strlen($str);
                if ($len > 7400) {
                    $this->aADL[$n] = '<ml l="1">'.$sList.'</ml>';
                    $n++;
                    $sList = '';
                    $len = strlen($str);
                }
                $sList .= $str;
                foreach ($aUserList as $u_name => $aNetworks) {
                    foreach ($aNetworks as $network => $status) {
                        $str = '<c n="'.$u_name.'" l="3" t="'.$network.'" />';
                        $len += strlen($str);
                        // max: 7500, but <ml l="1"></d></ml> is 19,
                        // so we use 7475
                        if ($len > 7475) {
                            $sList .= '</d>';
                            $this->aADL[$n] = '<ml l="1">'.$sList.'</ml>';
                            $n++;
                            $sList = '<d n="'.$u_domain.'">'.$str;
                            $len = strlen($sList);
                        }
                        else
                        $sList .= $str;
                    }
                }
                $sList .= '</d>';
            }
        }
        $this->aADL[$n] = '<ml l="1">'.$sList.'</ml>';
        // NS: >>> BLP {id} BL
        $this->ns_writeln("BLP $this->id BL");
        foreach ($this->aADL as $str) {
            $len = strlen($str);
            // NS: >>> ADL {id} {size}
            $this->ns_writeln("ADL $this->id $len");
            $this->ns_writedata($str);
        }
        // NS: >>> PRP {id} MFN name
        if ($this->alias == '') $this->alias = $user;
        $aliasname = rawurlencode($this->alias);
        $this->ns_writeln("PRP $this->id MFN $aliasname");
        //設定個人大頭貼
        //$MsnObj=$this->PhotoStckObj();
        // NS: >>> CHG {id} {status} {clientid} {msnobj}
        $this->ns_writeln("CHG $this->id NLN $this->clientid");
        if($this->PhotoStickerFile!==false)
            $this->ns_writeln("CHG $this->id NLN $this->clientid ".rawurlencode($this->MsnObj($this->PhotoStickerFile)));
        // NS: >>> UUX {id} length
        $str = '<Data><PSM>'.htmlspecialchars($this->psm).'</PSM><CurrentMedia></CurrentMedia><MachineGuid></MachineGuid></Data>';
        $len = strlen($str);
        $this->ns_writeln("UUX $this->id $len");
        $this->ns_writedata($str);
    }
    
    public function NSreceive() {
        $this->log_message("*** startup ***");
        
        // Sign in again if not signed in or socket failed
        if (!is_resource($this->NSfp) || feof($this->NSfp)) {
            $this->callHandler('Reconnect', NULL);
            $this->signon();
        }
        
        $data = $this->ns_readln();
        if($data === false) {
            // There was no data / an error when reading from the socket so reconnect
            $this->callHandler('Reconnect', NULL);
            $this->signon();
        } else {
            switch (substr($data,0,3))
            {
                case 'SBS':
                    // after 'USR {id} OK {user} {verify} 0' response, the server will send SBS and profile to us
                    // NS: <<< SBS 0 null
                    break;
    
                case 'RFS':
                    // FIXME:
                    // NS: <<< RFS ???
                    // refresh ADL, so we re-send it again
                    if (is_array($this->aADL)) {
                        foreach ($this->aADL as $str) {
                            $len = strlen($str);
                            // NS: >>> ADL {id} {size}
                            $this->ns_writeln("ADL $this->id $len");
                            $this->ns_writedata($str);
                        }
                    }
                    break;
    
                case 'LST':
                    // NS: <<< LST {email} {alias} 11 0
                    @list(/* LST */, $email, /* alias */, ) = @explode(' ', $data);
                    @list($u_name, $u_domain) = @explode('@', $email);
                    if (!isset($this->aContactList[$u_domain][$u_name][1])) {
                        $this->aContactList[$u_domain][$u_name][1]['Allow'] = 'Allow';
                        $this->log_message("*** add to our contact list: $u_name@$u_domain");
                    }
                    break;
    
                case 'ADL':
                    // randomly, we get ADL command, someome add us to their contact list for MSNP15
                    // NS: <<< ADL 0 {size}
                    @list(/* ADL */, /* 0 */, $size,) = @explode(' ', $data);
                    if (is_numeric($size) && $size > 0)
                    {
                        $data = $this->ns_readdata($size);
                        preg_match('#<ml><d n="([^"]+)"><c n="([^"]+)"(.*) t="(\d*)"(.*) /></d></ml>#', $data, $matches);
                        if (is_array($matches) && count($matches) > 0)
                        {
                            $u_domain = $matches[1];
                            $u_name = $matches[2];
                            $network = $matches[4];
                            if (isset($this->aContactList[$u_domain][$u_name][$network]))
                            $this->log_message("*** someone (network: $network) add us to their list (but already in our list): $u_name@$u_domain");
                            else
                            {
                                $re_login = false;
                                $cnt = 0;
                                foreach (array('Allow', 'Reverse') as $list)
                                {
                                    if (!$this->addMemberToList($u_name.'@'.$u_domain, $network, $list))
                                    {
                                        if ($re_login) {
                                            $this->log_message("*** can't add $u_name@$u_domain (network: $network) to $list");
                                            continue;
                                        }
                                        $aTickets = $this->get_passport_ticket();
                                        if (!$aTickets || !is_array($aTickets)) {
                                            // failed to login? ignore it
                                            $this->log_message("*** can't re-login, something wrong here");
                                            $this->log_message("*** can't add $u_name@$u_domain (network: $network) to $list");
                                            continue;
                                        }
                                        $re_login = true;
                                        $this->ticket = $aTickets;
                                        $this->log_message("**** get new ticket, try it again");
                                        if (!$this->addMemberToList($u_name.'@'.$u_domain, $network, $list))
                                        {
                                            $this->log_message("*** can't add $u_name@$u_domain (network: $network) to $list");
                                            continue;
                                        }
                                    }
                                    $this->aContactList[$u_domain][$u_name][$network][$list] = false;
                                    $cnt++;
                                }
                                $this->log_message("*** someone (network: $network) add us to their list: $u_name@$u_domain");
                            }
                            $str = '<ml l="1"><d n="'.$u_domain.'"><c n="'.$u_name.'" l="3" t="'.$network.'" /></d></ml>';
                            $len = strlen($str);
                        }
                        else
                        $this->log_message("*** someone add us to their list: $data");
                        $this->AddUsToMemberList($u_name.'@'.$u_domain, $network);
                    }
                    break;
    
                case 'RML':
                    // randomly, we get RML command, someome remove us to their contact list for MSNP15
                    // NS: <<< RML 0 {size}
                    @list(/* RML */, /* 0 */, $size,) = @explode(' ', $data);
                    if (is_numeric($size) && $size > 0)
                    {
                        $data = $this->ns_readdata($size);
                        preg_match('#<ml><d n="([^"]+)"><c n="([^"]+)"(.*) t="(\d*)"(.*) /></d></ml>#', $data, $matches);
                        if (is_array($matches) && count($matches) > 0)
                        {
                            $u_domain = $matches[1];
                            $u_name = $matches[2];
                            $network = $matches[4];
                            if (isset($this->aContactList[$u_domain][$u_name][$network]))
                            {
                                $aData = $this->aContactList[$u_domain][$u_name][$network];
                                foreach ($aData as $list => $id)
                                $this->delMemberFromList($id, $u_name.'@'.$u_domain, $network, $list);
                                unset($this->aContactList[$u_domain][$u_name][$network]);
                                $this->log_message("*** someone (network: $network) remove us from their list: $u_name@$u_domain");
                            }
                            else
                            $this->log_message("*** someone (network: $network) remove us from their list (but not in our list): $u_name@$u_domain");
                            $this->RemoveUsFromMemberList($u_name.'@'.$u_domain, $network);
                        }
                        else
                        $this->log_message("*** someone remove us from their list: $data");
                    }
                    break;
    
                case 'MSG':
                    // randomly, we get MSG notification from server
                    // NS: <<< MSG Hotmail Hotmail {size}
                    @list(/* MSG */, /* Hotmail */, /* Hotmail */, $size,) = @explode(' ', $data);
                    if (is_numeric($size) && $size > 0) {
                        $data = $this->ns_readdata($size);
                        $aLines = @explode("\n", $data);
                        $header = true;
                        $ignore = false;
                        $maildata = '';
                        foreach ($aLines as $line) {
                            $line = rtrim($line);
                            if ($header) {
                                if ($line === '') {
                                    $header = false;
                                    continue;
                                }
                                if (strncasecmp($line, 'Content-Type:', 13) == 0) {
                                    if (strpos($line, 'text/x-msmsgsinitialmdatanotification') === false &&
                                    strpos($line, 'text/x-msmsgsoimnotification') === false) {
                                        // we just need text/x-msmsgsinitialmdatanotification
                                        // or text/x-msmsgsoimnotification
                                        $ignore = true;
                                        break;
                                    }
                                }
                                continue;
                            }
                            if (strncasecmp($line, 'Mail-Data:', 10) == 0) {
                                $maildata = trim(substr($line, 10));
                                break;
                            }
                        }
                        if ($ignore) {
                            $this->log_message("*** ingnore MSG for: $line");
                            break;
                        }
                        if ($maildata == '') {
                            $this->log_message("*** ingnore MSG not for OIM");
                            break;
                        }
                        $re_login = false;
                        if (strcasecmp($maildata, 'too-large') == 0) {
                            $this->log_message("*** large mail-data, need to get the data via SOAP");
                            $maildata = $this->getOIM_maildata();
                            if ($maildata === false) {
                                $this->log_message("*** can't get mail-data via SOAP");
                                // maybe we need to re-login again
                                $aTickets = $this->get_passport_ticket();
                                if (!$aTickets || !is_array($aTickets)) {
                                    // failed to login? ignore it
                                    $this->log_message("*** can't re-login, something wrong here, ignore this OIM");
                                    break;
                                }
                                $re_login = true;
                                $this->ticket = $aTickets;
                                $this->log_message("**** get new ticket, try it again");
                                $maildata = $this->getOIM_maildata();
                                if ($maildata === false) {
                                    $this->log_message("*** can't get mail-data via SOAP, and we already re-login again, so ignore this OIM");
                                    break;
                                }
                            }
                        }
                        // could be a lots of <M>...</M>, so we can't use preg_match here
                        $p = $maildata;
                        $aOIMs = array();
                        while (1) {
                            $start = strpos($p, '<M>');
                            $end = strpos($p, '</M>');
                            if ($start === false || $end === false || $start > $end) break;
                            $end += 4;
                            $sOIM = substr($p, $start, $end - $start);
                            $aOIMs[] = $sOIM;
                            $p = substr($p, $end);
                        }
                        if (count($aOIMs) == 0) {
                            $this->log_message("*** ingnore empty OIM");
                            break;
                        }
                        foreach ($aOIMs as $maildata) {
                            // T: 11 for MSN, 13 for Yahoo
                            // S: 6 for MSN, 7 for Yahoo
                            // RT: the datetime received by server
                            // RS: already read or not
                            // SZ: size of message
                            // E: sender
                            // I: msgid
                            // F: always 00000000-0000-0000-0000-000000000009
                            // N: sender alias
                            preg_match('#<T>(.*)</T>#', $maildata, $matches);
                            if (count($matches) == 0) {
                                $this->log_message("*** ingnore OIM maildata without <T>type</T>");
                                continue;
                            }
                            $oim_type = $matches[1];
                            if ($oim_type = 13)
                            $network = 32;
                            else
                            $network = 1;
                            preg_match('#<E>(.*)</E>#', $maildata, $matches);
                            if (count($matches) == 0) {
                                $this->log_message("*** ingnore OIM maildata without <E>sender</E>");
                                continue;
                            }
                            $oim_sender = $matches[1];
                            preg_match('#<I>(.*)</I>#', $maildata, $matches);
                            if (count($matches) == 0) {
                                $this->log_message("*** ingnore OIM maildata without <I>msgid</I>");
                                continue;
                            }
                            $oim_msgid = $matches[1];
                            preg_match('#<SZ>(.*)</SZ>#', $maildata, $matches);
                            $oim_size = (count($matches) == 0) ? 0 : $matches[1];
                            preg_match('#<RT>(.*)</RT>#', $maildata, $matches);
                            $oim_time = (count($matches) == 0) ? 0 : $matches[1];
                            $this->log_message("*** You've OIM sent by $oim_sender, Time: $oim_time, MSGID: $oim_msgid, size: $oim_size");
                            $sMsg = $this->getOIM_message($oim_msgid);
                            if ($sMsg === false) {
                                $this->log_message("*** can't get OIM, msgid = $oim_msgid");
                                if ($re_login) {
                                    $this->log_message("*** can't get OIM via SOAP, and we already re-login again, so ignore this OIM");
                                    continue;
                                }
                                $aTickets = $this->get_passport_ticket();
                                if (!$aTickets || !is_array($aTickets)) {
                                    // failed to login? ignore it
                                    $this->log_message("*** can't re-login, something wrong here, ignore this OIM");
                                    continue;
                                }
                                $re_login = true;
                                $this->ticket = $aTickets;
                                $this->log_message("**** get new ticket, try it again");
                                $sMsg = $this->getOIM_message($oim_msgid);
                                if ($sMsg === false) {
                                    $this->log_message("*** can't get OIM via SOAP, and we already re-login again, so ignore this OIM");
                                    continue;
                                }
                            }
                            $this->log_message("*** MSG (Offline) from $oim_sender (network: $network): $sMsg");
    
                            //$this->ReceivedMessage($oim_sender,$sMsg,$network,true);
                            $this->callHandler('IMin', array('sender' => $oim_sender, 'message' => $sMsg, 'network' => $network, 'offline' => true));
                        }
                    }
                    break;
    
                case 'UBM':
                    // randomly, we get UBM, this is the message from other network, like Yahoo!
                    // NS: <<< UBM {email} $network $type {size}
                    @list(/* UBM */, $from_email, $network, $type, $size,) = @explode(' ', $data);
                    if (is_numeric($size) && $size > 0)
                    {
                        $data = $this->ns_readdata($size);
                        $aLines = @explode("\n", $data);
                        $header = true;
                        $ignore = false;
                        $sMsg = '';
                        foreach ($aLines as $line) {
                            $line = rtrim($line);
                            if ($header) {
                                if ($line === '') {
                                    $header = false;
                                    continue;
                                }
                                if (strncasecmp($line, 'TypingUser:', 11) == 0) {
                                    $ignore = true;
                                    break;
                                }
                                continue;
                            }
                            $aSubLines = @explode("\r", $line);
                            foreach ($aSubLines as $str) {
                                if ($sMsg !== '')
                                $sMsg .= "\n";
                                $sMsg .= $str;
                            }
                        }
                        if($ignore)
                        {
                            $this->log_message("*** ingnore from $from_email: $line");
                            break;
                        }
                        $this->log_message("*** MSG from $from_email (network: $network): $sMsg");
                        //$this->ReceivedMessage($from_email,$sMsg,$network,false);
                        $this->callHandler('IMin', array('sender' => $from_email, 'message' => $sMsg, 'network' => $network, 'offline' => false));
                    }
                    break;
    
                case 'UBX':
                    // randomly, we get UBX notification from server
                    // NS: <<< UBX email {network} {size}
                    @list(/* UBX */, /* email */, /* network */, $size,) = @explode(' ', $data);
                    // we don't need the notification data, so just ignore it
                    if (is_numeric($size) && $size > 0)
                    $this->ns_readdata($size);
                    break;
    
                case 'CHL':
                    // randomly, we'll get challenge from server
                    // NS: <<< CHL 0 {code}
                    @list(/* CHL */, /* 0 */, $chl_code,) = @explode(' ', $data);
                    $fingerprint = $this->getChallenge($chl_code);
                    // NS: >>> QRY {id} {product_id} 32
                    // NS: >>> fingerprint
                    $this->ns_writeln("QRY $this->id $this->prod_id 32");
                    $this->ns_writedata($fingerprint);
                    $this->ns_writeln("CHG $this->id NLN $this->clientid");                    
                    if($this->PhotoStickerFile!==false)
                        $this->ns_writeln("CHG $this->id NLN $this->clientid ".rawurlencode($this->MsnObj($this->PhotoStickerFile)));
                    break;
                case 'CHG':
                    // NS: <<< CHG {id} {status} {code}
                    // ignore it
                    // change our status to online first
                    break;
    
                case 'XFR':
                    // sometimes, NS will redirect to another NS
                    // MSNP9
                    // NS: <<< XFR {id} NS {server} 0 {server}
                    // MSNP15
                    // NS: <<< XFR {id} NS {server} U D
                    // for normal switchboard XFR
                    // NS: <<< XFR {id} SB {server} CKI {cki} U messenger.msn.com 0
                    @list(/* XFR */, /* {id} */, $server_type, $server, /* CKI */, $cki_code, /* ... */) = @explode(' ', $data);
                    @list($ip, $port) = @explode(':', $server);
                    if ($server_type != 'SB') {
                        // maybe exit?
                        // this connection will close after XFR
                        $this->NSLogout();
                        continue;
                    }
                    if(count($this->MessageQueue))
                    {
                        foreach($this->MessageQueue as $User => $Message)
                        {
                            //$this->ChildProcess[$ChildPid]
                            $this->log_message("*** XFR SB $User");
                            $pid=pcntl_fork();
                            if($pid)
                            {
                                //Parrent Process
                                $this->ChildProcess[$pid]=$User;
                                break;
                            }
                            elseif($pid==-1)
                            {
                                $this->log_message("*** Fork Error $User");
                                break;
                            }
                            else
                            {
                                //Child Process
                                $this->log_message("*** Child Process Start for $User");
                                unset($Message['XFRSent']);
                                unset($Message['ReqTime']);
                                $bSBresult = $this->switchboard_control($ip, $port, $cki_code, $User, $Message);
                                if ($bSBresult === false)
                                {
                                    // error for switchboard
                                    $this->log_message("!!! error for sending message to ".$User);
                                }
                                die;
                            }
                        }
                        unset($this->MessageQueue[$User]);
                    }
                    /*
                     $bSBresult = $this->switchboard_control($ip, $port, $cki_code, $aMSNUsers[$nCurrentUser], $sMessage);
                     if ($bSBresult === false) {
                     // error for switchboard
                     $this->log_message("!!! error for sending message to ".$aMSNUsers[$nCurrentUser]);
                     $aOfflineUsers[] = $aMSNUsers[$nCurrentUser];
                     }*/
                    break;
                case 'QNG':
                    // NS: <<< QNG {time}
                    @list(/* QNG */, $ping_wait) = @explode(' ', $data);
                    //if ($this->ping_wait == 0) $this->ping_wait = 50;
                    //if (is_int($use_ping) && $use_ping > 0) $ping_wait = $use_ping;
                    //Mod by Ricky Set Online
                    
                    $this->callHandler('Pong', $ping_wait);
                    break;
    
                case 'RNG':
                    if($this->PhotoStickerFile!==false)
                        $this->ns_writeln("CHG $this->id NLN $this->clientid ".rawurlencode($this->MsnObj($this->PhotoStickerFile)));
                    else
                        $this->ns_writeln("CHG $this->id NLN $this->clientid");
                    // someone is trying to talk to us
                    // NS: <<< RNG {session_id} {server} {auth_type} {ticket} {email} {alias} U {client} 0
                    $this->log_message("NS: <<< RNG $data");
                    @list(/* RNG */, $sid, $server, /* auth_type */, $ticket, $email, $name, ) = @explode(' ', $data);
                    @list($sb_ip, $sb_port) = @explode(':', $server);
                    if($this->IsIgnoreMail($email))
                    {
                        $this->log_message("*** Ignore RNG from $email");
                        break;
                    }
                    $this->log_message("*** RING from $email, $sb_ip:$sb_port");
                    $this->addContact($email,1,$email, true);
                    $pid=pcntl_fork();
                    if($pid)
                    {
                        //Parrent Process
                        $this->ChildProcess[$pid]='RNG';
                        break;
                    }
                    elseif($pid==-1)
                    {
                        $this->log_message("*** Fork Error $User");
                        break;
                    }
                    else
                    {
                        //Child Process
                        $this->log_message("*** Ring Child Process Start for $User");
                        $this->switchboard_ring($sb_ip, $sb_port, $sid, $ticket,$email);
                        die;
                    }
                    break;
                case 'OUT':
                    // force logout from NS
                    // NS: <<< OUT xxx
                    $this->log_message("*** LOGOUT from NS");
                    return $this->NsLogout();
    
                default:
                    $code = substr($data,0,3);
                    if (is_numeric($code)) {
                        $this->error = "Error code: $code, please check the detail information from: http://msnpiki.msnfanatic.com/index.php/Reference:Error_List";
                        $this->debug_message("*** NS: $this->error");
    
                        return $this->NsLogout();
                    }
                    break;
            }
        }
    }
    
    public function sendMessageViaSB($message, $to) {
        $socket = $this->switchBoardSessions[$to]['socket'];
        $lastActive = $this->switchBoardSessions[$to]['lastActive'];
        $joined = $this->switchBoardSessions[$to]['joined'];
        
        //FIXME Probably not needed (we're not running in a loop anymore)
        /*if($this->kill_me)
        {
            $this->log_message("*** SB Okay, kill me now!");
            endSBSession($socket);
        }*/
        
        if(!$Joined) {
            // If our participant has not joined the session yet we can't message them!
            //TODO Check the behaviour of the queue runner when we return false
            return false;
        }
        
        $aMessage = $this->getMessage($Message);
        //CheckEmotion...
        $MsnObjDefine=$this->GetMsnObjDefine($aMessage);
        if($MsnObjDefine !== '')
        {
            $SendString="MIME-Version: 1.0\r\nContent-Type: text/x-mms-emoticon\r\n\r\n$MsnObjDefine";
            $len = strlen($SendString);
            $this->SB_writeln("MSG $id N $len");
            $id++;
            $this->SB_writedata($SendString);
            $this->id++;
        }
        $len = strlen($aMessage);
        $this->SB_writeln("MSG $id N $len");
        
        // Increment the trID
        $this->switchBoardSessions[$to]['id']++;
        
        $this->SB_writedata($aMessage);
        
        // Don't close the SB session, we might as well leave it open
        
        return true;
    }
    
    //FIXME Not sure if this is needed?
    private function endSBSession($socket) {
        if (feof($this->SBFp))
        {
            // lost connection? error? try OIM later
            @fclose($this->SBFp);
            return false;
        }
        $this->SB_writeln("OUT");
        @fclose($this->SBFp);
        return true;
    }
    
    private function getSBSession($to) {
        
    }
    
    public function sendMessage($message, $to) {
        if($message != '') {
            list($name,$host,$network)=explode('@',$to);
            $network=$network==''?1:$network;
            
            if($network === 1 && isset($this->switchBoardSessions[$to])) {
                $recipient = $name . $host;
                $this->debug_message("*** Sending Message to $recipient using existing SB session");
                return $this->sendMessageViaSB($message, $recipient);
            } else {
                $this->debug_message("*** Not MSN network or no existing SB session");
                //TODO implement creation of SB session etc
            }
        }
        return true;
    }
    
    /**
     * Sends a ping command
     * 
     * Should be called about every 50 seconds
     */
    public function send_ping() {
        // NS: >>> PNG
        $this->ns_writeln("PNG");
    }
    
    public function getNSSocket() {
        return $this->NSfp;
    }
    
    // TODO Allow for multiple SB session sockets
    public function getSBSocket() {
        return $this->SBfp;
    }
    
    public function getSockets() {
        return array($this->NSfp, $this->SBfp);
    }
    
    /**
     * Calls User Handler
     *
     * Calls registered handler for a specific event.
     * 
     * @param String $event Command (event) name (Rvous etc)
     * @param String $data Raw message from server
     * @see registerHandler
     * @return void
     */
    private function callHandler($event, $data) {
        if (isset($this->myEventHandlers[$event])) {
            call_user_func($this->myEventHandlers[$event], $data);
        }
    }
    
    /** 
     * Registers a user handler
     * 
     * Handler List
     * IMIn, Pong, ConnectFailed, Reconnect
     *
     * @param String $event Event name
     * @param String $handler User function to call
     * @see callHandler
     * @return boolean Returns true if successful
     */
    public function registerHandler($event, $handler) {
        if (is_callable($handler)) {
            $this->myEventHandlers[$event] = $handler;
            return true;
        } else {
            return false;
        }
    }
}
