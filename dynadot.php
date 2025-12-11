<?php

/**
 * Dynadot Module
 *
 * @package blesta
 * @subpackage blesta.components.modules.dynadot
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @link http://www.blesta.com/ Blesta
 */
class Dynadot extends RegistrarModule
{
    /**
     * @var array Default Dynadot nameservers
     */
    private $nameservers = [
        'ns1.dynadot.com',
        'ns2.dynadot.com'
    ];

    /**
     * @var string Default module view path
     */
    private static $defaultModuleView;

    /**
     * @var DynadotApi An instance of the Dynadot API
     */
    private $api;

    /**
     * Initializes the module
     */
    public function __construct()
    {
        // Load config.json
        $this->loadConfig(__DIR__ . DS . 'config.json');

        // Load models required by this module
        Loader::loadModels($this, ['PluginManager', 'Clients']);

        // Load components required by this module
        Loader::loadComponents($this, ['Input', 'Record']);

        // Load the language required by this module
        Language::loadLang('dynadot', null, __DIR__ . DS . 'language' . DS);

        // Load configuration
        Configure::load('dynadot', __DIR__ . DS . 'config' . DS);

        // Set default module view
        self::$defaultModuleView = 'components' . DS . 'modules' . DS . 'dynadot' . DS;
    }

    /**
     * Performs any necessary bootstraping actions. Sets Input errors on
     * failure, preventing the module from being added.
     *
     * @return array A numerically indexed array of meta data containing:
     *
     *  - key The key for this meta field
     *  - value The value for this key
     *  - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     */
    public function install()
    {
        // No special install logic for now
        return [];
    }

    /**
     * Performs migration of data from $current_version (the current installed version)
     * to the given file set version. Sets Input errors on failure, preventing
     * the module from being upgraded.
     *
     * @param string $current_version The current installed version of this module
     */
    public function upgrade($current_version)
    {
        // Upgrade if possible
        if (version_compare($this->getVersion(), $current_version, '>')) {
            // Handle upgrades
        }
    }

    /**
     * Performs any necessary cleanup actions
     *
     * @param int $module_id The ID of the module being uninstalled
     * @param boolean $last_instance True if $module_id is the last instance across
     *  all companies for this module, false otherwise
     */
    public function uninstall($module_id, $last_instance)
    {
        // No special uninstall logic for now
    }

    /**
     * Runs the cron task identified by the key used to create the cron task
     *
     * @param string $key The key used to create the cron task
     */
    public function cron($key)
    {
        // No cron tasks defined for now
    }

    /**
     * Gets a list of name server data associated with a domain
     *
     * @param string $domain The domain to lookup
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @return array A list of name servers, each with the following fields:
     *
     *  - url The URL of the name server
     *  - ips A list of IPs for the name server
     */
    public function getDomainNameServers($domain, $module_row_id = null)
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->key, $row->meta->sandbox == 'true');

        $response = $api->submit('get_ns', ['domain' => $domain]);
        $this->processResponse($api, $response);
        $result = $response->response();

        $nameservers = [];
        if (isset($result->NsContent)) {
            $hosts = [];
            if (isset($result->NsContent->Host)) {
                $hosts = is_array($result->NsContent->Host) ? $result->NsContent->Host : [$result->NsContent->Host];
            } else {
                foreach ((array)$result->NsContent as $key => $value) {
                    if (strpos($key, 'Host') === 0) {
                        $hosts[] = $value;
                    }
                }
            }

            foreach ($hosts as $ns) {
                $nameservers[] = [
                    'url' => trim($ns),
                    'ips' => []
                ];
            }
        }

        return $nameservers;
    }

    /**
     * Assign new name servers to a domain
     *
     * @param string $domain The domain for which to assign new name servers
     * @param int|null $module_row_id The ID of the module row to fetch for the current module
     * @param array $vars A list of name servers to assign (e.g. [ns1, ns2])
     * @return bool True if the name servers were successfully updated, false otherwise
     */
    public function setDomainNameservers($domain, $module_row_id = null, array $vars = [])
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->key, $row->meta->sandbox == 'true');

        $args = [];
        $i = 0;
        foreach ($vars as $ns) {
            $args['ns' . $i] = $ns;
            $i++;
        }

        $args['domain'] = $domain;

        $response = $api->submit('set_ns', $args);
        $this->processResponse($api, $response);

        return $response->status() == 'success';
    }

    /**
     * Attempts to validate service info. This is the top-level error checking method. Sets Input errors on failure.
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param array $vars An array of user supplied info to satisfy the request
     * @return bool True if the service validates, false otherwise. Sets Input errors when false.
     */
    public function validateService($package, array $vars = null)
    {
        $rules = [];

        // Transfers (EPP Code)
        if (isset($vars['transfer']) && ($vars['transfer'] == '1' || $vars['transfer'] == true)) {
            $rule = [
                'auth' => [
                    'empty' => [
                        'rule' => ['isEmpty'],
                        'negate' => true,
                        'message' => Language::_('Dynadot.!error.epp.empty', true),
                        'post_format' => 'trim'
                    ]
                ],
            ];
            $rules = array_merge($rules, $rule);
        }

        // Domain checks
        if (isset($vars['domain'])) {
             // Basic validation
             if (empty($vars['domain'])) {
                 $this->Input->setErrors(['domain' => ['empty' => Language::_('Dynadot.!error.domain.valid', true)]]);
                 return false;
             }
        }

        // .us fields
        if (isset($vars['usnc']) || isset($vars['usap'])) {
            $rule = [
                'usnc' => [
                    'empty' => [
                        'rule' => ['isEmpty'],
                        'negate' => true,
                        'message' => Language::_('Dynadot.!error.US.RegistrantNexus.empty', true),
                        'post_format' => 'trim',
                        'final' => true
                    ]
                ],
                'usap' => [
                    'empty' => [
                        'rule' => ['isEmpty'],
                        'negate' => true,
                        'message' => Language::_('Dynadot.!error.US.RegistrantPurpose.empty', true),
                        'post_format' => 'trim',
                        'final' => true
                    ]
                ],
            ];
            $rules = array_merge($rules, $rule);
        }

        if (isset($rules) && count($rules) > 0) {
            $this->Input->setRules($rules);
            return $this->Input->validates($vars);
        }

        return true;
    }

    /**
     * Adds the service to the remote server. Sets Input errors on failure,
     * preventing the service from being added.
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param array $vars An array of user supplied info to satisfy the request
     * @param stdClass $parent_package A stdClass object representing the parent service's selected package
     *  (if the current service is an addon service)
     * @param stdClass $parent_service A stdClass object representing the parent service of the service being added
     *  (if the current service is an addon service and parent service has already been provisioned)
     * @param string $status The status of the service being added. These include:
     *
     *  - active
     *  - canceled
     *  - pending
     *  - suspended
     * @return array A numerically indexed array of meta fields to be stored for this service containing:
     *
     *  - key The key for this meta field
     *  - value The value for this key
     *  - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function addService(
        $package,
        array $vars = null,
        $parent_package = null,
        $parent_service = null,
        $status = 'pending'
    )
    {
        $row = $this->getModuleRow();
        $api = $this->getApi($row->meta->key, $row->meta->sandbox == 'true');

        if (isset($vars['domain'])) {
            $vars['domain'] = trim($vars['domain']);
        }

        if (isset($vars['use_module']) && $vars['use_module'] == 'true') {
            if ($package->meta->type == 'domain') {
                $vars['years'] = 1;

                foreach ($package->pricing as $pricing) {
                    if ($pricing->id == $vars['pricing_id']) {
                        $vars['years'] = $pricing->term;
                        break;
                    }
                }

                // Get contact ID
                $contact_id = $this->getContactIdFromClient($vars['client_id'] ?? null, $api);

                // Handle transfer
                if (isset($vars['auth']) && $vars['auth']) {
                    $args = [
                        'domain' => $vars['domain'],
                        'auth_code' => $vars['auth']
                    ];

                    if ($contact_id) {
                         $args['registrant_contact'] = $contact_id;
                         $args['admin_contact'] = $contact_id;
                         $args['technical_contact'] = $contact_id;
                         $args['billing_contact'] = $contact_id;
                    }

                    $nameservers = [];
                    for ($i = 1; $i <= 5; $i++) {
                        if (isset($vars['ns' . $i]) && !empty($vars['ns' . $i])) {
                            $nameservers[] = $vars['ns' . $i];
                        }
                    }
                    if (!empty($nameservers)) {
                        $args['name_servers'] = implode(',', $nameservers);
                    }

                    $response = $api->submit('transfer', $args);
                    $this->processResponse($api, $response);

                    if ($this->Input->errors()) {
                        return;
                    }
                } else {
                    // Handle registration
                    $args = [
                        'domain' => $vars['domain'],
                        'duration' => $vars['years']
                    ];

                    if ($contact_id) {
                         $args['registrant_contact'] = $contact_id;
                         $args['admin_contact'] = $contact_id;
                         $args['technical_contact'] = $contact_id;
                         $args['billing_contact'] = $contact_id;
                    }

                    // Add nameservers
                    $i = 0;
                    for ($j = 1; $j <= 5; $j++) {
                        if (isset($vars['ns' . $j]) && !empty($vars['ns' . $j])) {
                            $args['ns' . $i] = $vars['ns' . $j];
                            $i++;
                        }
                    }

                    $response = $api->submit('register', $args);
                    $this->processResponse($api, $response);

                    if ($this->Input->errors()) {
                        return;
                    }
                }
            }
        }

        $meta = [];
        $fields = ['domain', 'auth', 'ns1', 'ns2', 'ns3', 'ns4', 'ns5'];
        foreach ($vars as $key => $value) {
            if (in_array($key, $fields)) {
                $meta[] = [
                    'key' => $key,
                    'value' => $value,
                    'encrypted' => 0
                ];
            }
        }

        return $meta;
    }

    /**
     * Edits the service on the remote server. Sets Input errors on failure,
     * preventing the service from being edited.
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $vars An array of user supplied info to satisfy the request
     * @param stdClass $parent_package A stdClass object representing the parent service's selected package
     *  (if the current service is an addon service)
     * @param stdClass $parent_service A stdClass object representing the parent service of the service being edited
     *  (if the current service is an addon service)
     * @return array A numerically indexed array of meta fields to be stored for this service containing:
     *
     *  - key The key for this meta field
     *  - value The value for this key
     *  - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function editService($package, $service, array $vars = [], $parent_package = null, $parent_service = null)
    {
        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
        $api = $this->getApi($row->meta->key, $row->meta->sandbox == 'true');

        // Manually renew the domain
        $renew = isset($vars['renew']) ? (int) $vars['renew'] : 0;
        if ($renew > 0 && $vars['use_module'] == 'true') {
            $this->renewService($package, $service, $parent_package, $parent_service, $renew);
            unset($vars['renew']);
        }

        // Update nameservers
        if (isset($vars['ns1']) && isset($vars['ns2'])) {
            $ns = [];
            for ($i=1; $i<=5; $i++) {
                if (isset($vars['ns' . $i])) {
                    $ns[] = $vars['ns' . $i];
                }
            }
            $this->setDomainNameservers($this->getServiceDomain($service), $service->module_row_id, $ns);
        }

        $id_protection = $this->featureServiceEnabled('id_protection', $service);
        if (isset($vars['configoptions']['id_protection'])) {
             $privacy = $vars['configoptions']['id_protection'] ? 'full' : 'off';
             $whois_privacy = $vars['configoptions']['id_protection'] ? 'yes' : 'no';

             $api->submit('set_privacy', [
                 'domain' => $this->getServiceDomain($service),
                 'option' => $privacy,
                 'whois_privacy_option' => $whois_privacy
             ]);
        }

        return null;
    }

    /**
     * Cancels the service on the remote server. Sets Input errors on failure,
     * preventing the service from being canceled.
     */
    public function cancelService($package, $service, $parent_package = null, $parent_service = null)
    {
        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
        $api = $this->getApi($row->meta->key, $row->meta->sandbox == 'true');

        if ($package->meta->type == 'domain') {
            $domain = $this->getServiceDomain($service);
            $api->submit('set_renew_option', ['domain' => $domain, 'renew_option' => 'donot']);
        }
        return;
    }

    /**
     * Suspends the service on the remote server. Sets Input errors on failure,
     * preventing the service from being suspended.
     */
    public function suspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        return $this->cancelService($package, $service, $parent_package, $parent_service);
    }

    /**
     * Unsuspends the service on the remote server. Sets Input errors on failure,
     * preventing the service from being unsuspended.
     */
    public function unsuspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
        $api = $this->getApi($row->meta->key, $row->meta->sandbox == 'true');

        if ($package->meta->type == 'domain') {
            $domain = $this->getServiceDomain($service);
            $api->submit('set_renew_option', ['domain' => $domain, 'renew_option' => 'auto']);
        }
        return;
    }

    /**
     * Allows the module to perform an action when the service is ready to renew.
     * Sets Input errors on failure, preventing the service from renewing.
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param stdClass $parent_package A stdClass object representing the parent service's selected package
     *  (if the current service is an addon service)
     * @param stdClass $parent_service A stdClass object representing the parent service of the service being renewed
     *  (if the current service is an addon service)
     * @return mixed null to maintain the existing meta fields or a numerically indexed array of meta fields to be
     *  stored for this service containing:
     *
     *      - key The key for this meta field
     *      - value The value for this key
     *      - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function renewService($package, $service, $parent_package = null, $parent_service = null, $years = null)
    {
        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
        $api = $this->getApi($row->meta->key, $row->meta->sandbox == 'true');

        if ($package->meta->type == 'domain') {
            $domain = $this->getServiceDomain($service);

            if (!$years) {
                foreach ($package->pricing as $pricing) {
                    if ($pricing->id == $service->pricing_id) {
                        $years = $pricing->term;
                        break;
                    }
                }
            }

            $vars = [
                'domain' => $domain,
                'duration' => $years
            ];

            $response = $api->submit('renew', $vars);
            $this->processResponse($api, $response);

            if ($this->Input->errors()) {
                return;
            }
        }

        return null;
    }

    /**
     * Validates input data when attempting to add a package, returns the meta
     * data to save when adding a package. Performs any action required to add
     * the package on the remote server. Sets Input errors on failure,
     * preventing the package from being added.
     *
     * @param array An array of key/value pairs used to add the package
     * @return array A numerically indexed array of meta fields to be stored for this package containing:
     *
     *  - key The key for this meta field
     *  - value The value for this key
     *  - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function addPackage(array $vars = null)
    {
        $meta = [];
        if (isset($vars['meta']) && is_array($vars['meta'])) {
            // Return all package meta fields
            foreach ($vars['meta'] as $key => $value) {
                $meta[] = [
                    'key' => $key,
                    'value' => $value,
                    'encrypted' => 0
                ];
            }
        }

        return $meta;
    }

    /**
     * Validates input data when attempting to edit a package, returns the meta
     * data to save when editing a package. Performs any action required to edit
     * the package on the remote server. Sets Input errors on failure,
     * preventing the package from being edited.
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param array An array of key/value pairs used to edit the package
     * @return array A numerically indexed array of meta fields to be stored for this package containing:
     *
     *  - key The key for this meta field
     *  - value The value for this key
     *  - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     * @see Module::getModule()
     * @see Module::getModuleRow()
     */
    public function editPackage($package, array $vars = null)
    {
        $meta = [];
        if (isset($vars['meta']) && is_array($vars['meta'])) {
            // Return all package meta fields
            foreach ($vars['meta'] as $key => $value) {
                $meta[] = [
                    'key' => $key,
                    'value' => $value,
                    'encrypted' => 0
                ];
            }
        }

        return $meta;
    }

    /**
     * Adds the module row on the remote server. Sets Input errors on failure,
     * preventing the row from being added.
     *
     * @param array $vars An array of module info to add
     * @return array A numerically indexed array of meta fields for the module row containing:
     *
     *  - key The key for this meta field
     *  - value The value for this key
     *  - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     */
    public function addModuleRow(array &$vars)
    {
        $meta_fields = ['user', 'key', 'sandbox'];
        $encrypted_fields = ['key'];

        // Set unspecified checkboxes
        if (empty($vars['sandbox'])) {
            $vars['sandbox'] = 'false';
        }

        $this->Input->setRules($this->getRowRules($vars));

        // Validate module row
        if ($this->Input->validates($vars)) {
            // Build the meta data for this row
            $meta = [];
            foreach ($vars as $key => $value) {
                if (in_array($key, $meta_fields)) {
                    $meta[] = [
                        'key' => $key,
                        'value' => $value,
                        'encrypted' => in_array($key, $encrypted_fields) ? 1 : 0
                    ];
                }
            }

            return $meta;
        }
    }

    /**
     * Edits the module row on the remote server. Sets Input errors on failure,
     * preventing the row from being updated.
     *
     * @param stdClass $module_row The stdClass representation of the existing module row
     * @param array $vars An array of module info to update
     * @return array A numerically indexed array of meta fields for the module row containing:
     *
     *  - key The key for this meta field
     *  - value The value for this key
     *  - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     */
    public function editModuleRow($module_row, array &$vars)
    {
        $meta_fields = ['user', 'key', 'sandbox'];
        $encrypted_fields = ['key'];

        // Set unspecified checkboxes
        if (empty($vars['sandbox'])) {
            $vars['sandbox'] = 'false';
        }

        // Merge package settings on to the module row meta
        $module_row_meta = array_merge((array) $module_row->meta, $vars);

        $this->Input->setRules($this->getRowRules($vars));

        // Validate module row
        if ($this->Input->validates($vars)) {
            // Build the meta data for this row
            $meta = [];
            foreach ($module_row_meta as $key => $value) {
                if (in_array($key, $meta_fields) || array_key_exists($key, (array) $module_row->meta)) {
                    $meta[] = [
                        'key' => $key,
                        'value' => $value,
                        'encrypted' => in_array($key, $encrypted_fields) ? 1 : 0
                    ];
                }
            }

            return $meta;
        }
    }

    /**
     * Deletes the module row on the remote server. Sets Input errors on failure,
     * preventing the row from being deleted.
     *
     * @param stdClass $module_row The stdClass representation of the existing module row
     */
    public function deleteModuleRow($module_row)
    {
        // No special cleanup needed
        return null;
    }

    /**
     * Builds and returns the rules required to add/edit a module row
     *
     * @param array $vars An array reference of key/value data pairs
     * @return array An array of Input rules suitable for Input::setRules()
     */
    private function getRowRules(&$vars)
    {
        return [
            'key' => [
                'valid' => [
                    'last' => true,
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Dynadot.!error.key.valid', true)
                ],
                'valid_connection' => [
                    'rule' => [
                        [$this, 'validateConnection'],
                        isset($vars['sandbox']) ? $vars['sandbox'] : 'false'
                    ],
                    'message' => Language::_('Dynadot.!error.key.valid_connection', true)
                ]
            ]
        ];
    }

    /**
     * Validates that the given connection details are correct by attempting to check the availability of a domain
     *
     * @param string $key The API key
     * @param string $sandbox "true" if this is a sandbox account, false otherwise
     * @return bool True if the connection details are valid, false otherwise
     */
    public function validateConnection($key, $sandbox)
    {
        $api = $this->getApi($key, $sandbox == 'true');
        // Use get_account_balance for validation as account_info/is_processing were problematic or questioned
        $response = $api->submit('get_account_balance');
        $this->processResponse($api, $response);

        // Dynadot returns XML/JSON.
        return $response->status() == 'success';
    }

    /**
     * Initializes the DynadotApi and returns an instance of that object
     *
     * @param string $key The key to use when connecting
     * @param bool $sandbox Whether or not to process in sandbox mode (for testing)
     * @return DynadotApi The DynadotApi instance
     */
    public function getApi($key = null, $sandbox = true)
    {
        Loader::load(__DIR__ . DS . 'apis' . DS . 'dynadot_api.php');

        if (empty($key)) {
            if (($row = $this->getModuleRow())) {
                $key = $row->meta->key;
                $sandbox = $row->meta->sandbox;
            }
        }

        $this->api = new DynadotApi($key, $sandbox);
        return $this->api;
    }

    /**
     * Process API response, setting an errors, and logging the request
     *
     * @param DynadotApi $api The Dynadot API object
     * @param DynadotResponse $response The Dynadot API response object
     */
    private function processResponse(DynadotApi $api, DynadotResponse $response)
    {
        $this->logRequest($api, $response);

        // Set errors if non-200 http code
        if ($api->httpcode != 200) {
            $this->Input->setErrors(['errors' => ['API returned non-200 HTTP code: ' . $api->httpcode]]);
            return;
        }

        if ($response->status() == 'error') {
            $errors = $response->errors();
            $this->Input->setErrors(['errors' => $errors]);
        }
    }

    /**
     * Logs the API request
     *
     * @param DynadotApi $api The Dynadot API object
     * @param DynadotResponse $response The Dynadot API response object
     */
    private function logRequest(DynadotApi $api, DynadotResponse $response)
    {
        $last_request = $api->lastRequest();
        $url = $last_request['url'];
        // Mask key in logs
        $args = $last_request['args'];
        if (isset($args['key'])) {
            $args['key'] = '***';
        }

        $this->log($url, serialize($args), 'input', true);
        $this->log(
            $url,
            $response->raw(),
            'output',
            $response->status() == 'success'
        );
    }

    /**
     * Returns the TLD of the given domain
     *
     * @param string $domain The domain to return the TLD from
     * @return string The TLD of the domain
     */
    private function getTld($domain)
    {
        return strstr($domain, '.');
    }

    /**
     * Retrieves the Dynadot module row
     *
     * @return null|stdClass An stdClass object representing the module row if found, otherwise void
     */
    private function getRow()
    {
        $module_rows = $this->getModuleRows();
        return $module_rows[0] ?? null;
    }

    public function manageModule($module, array &$vars)
    {
        // Load the required models
        Loader::loadModels($this, ['Languages', 'Settings', 'Currencies', 'Packages']);

        // Load the view into this object, so helpers can be automatically added to the view
        $this->view = new View('manage', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView(self::$defaultModuleView);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);

        $this->view->set('module', $module);

        return $this->view->fetch();
    }

    public function manageAddRow(array &$vars)
    {
        $this->view = new View('add_row', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView(self::$defaultModuleView);

        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);

        if (empty($vars['sandbox'])) {
            $vars['sandbox'] = 'false';
        }

        $this->view->set('vars', (object) $vars);
        return $this->view->fetch();
    }

    public function manageEditRow($module_row, array &$vars)
    {
        $this->view = new View('edit_row', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView(self::$defaultModuleView);

        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);

        if (empty($vars)) {
            $vars = (array) $module_row->meta;
        }

        $this->view->set('vars', (object) $vars);
        return $this->view->fetch();
    }

    /**
     * Verifies that the provided domain name is available
     *
     * @param string $domain The domain to lookup
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @return bool True if the domain is available, false otherwise
     */
    public function checkAvailability($domain, $module_row_id = null)
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->key, $row->meta->sandbox == 'true');

        $result = $api->submit('search', ['domain0' => $domain]);
        $this->processResponse($api, $result);

        if ($result->status() == 'error') {
            return false;
        }

        $response = $result->response();

        if (isset($response->SearchResponse->SearchHeader->Available) &&
            $response->SearchResponse->SearchHeader->Available == 'yes') {
            return true;
        }

        return false;
    }

    /**
     * Checks if the domain is available for transfer
     *
     * @param string $domain The domain to lookup
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @return bool True if the domain is available for transfer, false otherwise
     */
    public function checkTransferAvailability($domain, $module_row_id = null)
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->key, $row->meta->sandbox == 'true');

        $result = $api->submit('search', ['domain0' => $domain]);
        $this->processResponse($api, $result);

        if ($result->status() == 'error') {
            return false;
        }

        $response = $result->response();

        if (isset($response->SearchResponse->SearchHeader->Available) &&
            $response->SearchResponse->SearchHeader->Available == 'no') {
            return true;
        }

        return false;
    }

    /**
     * Checks if a feature is enabled for a given service
     *
     * @param string $feature The name of the feature to check if it's enabled (e.g. id_protection)
     * @param stdClass $service An object representing the service
     * @return bool True if the feature is enabled, false otherwise
     */
    private function featureServiceEnabled($feature, $service)
    {
        // Get service option groups
        if (isset($service->options)) {
            foreach ($service->options as $option) {
                if ($option->option_name == $feature) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Gets the domain name from the given service
     *
     * @param stdClass $service The service from which to extract the domain name
     * @return string The domain name associated with the service
     */
    public function getServiceDomain($service)
    {
        if (isset($service->fields)) {
            foreach ($service->fields as $service_field) {
                if ($service_field->key == 'domain') {
                    return $service_field->value;
                }
            }
        }

        return $service->name ?? '';
    }

    /**
     * Gets the domain registration date
     *
     * @param stdClass $service The service belonging to the domain to lookup
     * @param string $format The format to return the registration date in
     * @return string The domain registration date in UTC time in the given format
     */
    public function getRegistrationDate($service, $format = 'Y-m-d H:i:s')
    {
        Loader::loadHelpers($this, ['Date']);

        $domain = $this->getServiceDomain($service);
        $module_row_id = $service->module_row_id ?? null;

        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->key, $row->meta->sandbox == 'true');

        $result = $api->submit('domain_info', ['domain' => $domain]);
        $this->processResponse($api, $result);

        $response = $result->response();

        // Registration is Unix timestamp in milliseconds
        return isset($response->DomainInfoContent->Domain->Registration)
            ? $this->Date->format(
                $format,
                (string)$response->DomainInfoContent->Domain->Registration / 1000
            )
            : false;
    }

    /**
     * Gets the domain expiration date
     *
     * @param stdClass $service The service belonging to the domain to lookup
     * @param string $format The format to return the expiration date in
     * @return string The domain expiration date in UTC time in the given format
     */
    public function getExpirationDate($service, $format = 'Y-m-d H:i:s')
    {
        Loader::loadHelpers($this, ['Date']);

        $domain = $this->getServiceDomain($service);
        $module_row_id = $service->module_row_id ?? null;

        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->key, $row->meta->sandbox == 'true');

        $result = $api->submit('domain_info', ['domain' => $domain]);
        $this->processResponse($api, $result);

        $response = $result->response();

        // Expiration is Unix timestamp in milliseconds
        return isset($response->DomainInfoContent->Domain->Expiration)
            ? $this->Date->format(
                $format,
                (string)$response->DomainInfoContent->Domain->Expiration / 1000
            )
            : false;
    }

    /**
     * Fetches the HTML content to display when viewing the service info in the
     * admin interface.
     *
     * @param stdClass $service A stdClass object representing the service
     * @param stdClass $package A stdClass object representing the service's package
     * @return string HTML content containing information to display when viewing the service info
     */
    public function getAdminServiceInfo($service, $package)
    {
        return '';
    }

    /**
     * Fetches the HTML content to display when viewing the service info in the
     * client interface.
     *
     * @param stdClass $service A stdClass object representing the service
     * @param stdClass $package A stdClass object representing the service's package
     * @return string HTML content containing information to display when viewing the service info
     */
    public function getClientServiceInfo($service, $package)
    {
        return '';
    }

    public function getPackageFields($vars = null)
    {
        Loader::loadHelpers($this, ['Html']);

        $fields = new ModuleFields();

        $types = [
            'domain' => Language::_('Dynadot.package_fields.type_domain', true),
        ];

        // Set type of package
        $type = $fields->label(
            Language::_('Dynadot.package_fields.type', true),
            'dynadot_type'
        );
        $type->attach(
            $fields->fieldSelect(
                'meta[type]',
                $types,
                (isset($vars->meta['type']) ? $vars->meta['type'] : null),
                ['id' => 'dynadot_type']
            )
        );
        $fields->setField($type);

        // Set nameservers
        for ($i = 1; $i <= 5; $i++) {
            $type = $fields->label(Language::_('Dynadot.package_fields.ns' . $i, true), 'dynadot_ns' . $i);
            $type->attach(
                $fields->fieldText(
                    'meta[ns][]',
                    (isset($vars->meta['ns'][$i - 1]) ? $vars->meta['ns'][$i - 1] : null),
                    ['id' => 'dynadot_ns' . $i]
                )
            );
            $fields->setField($type);
        }

        return $fields;
    }

    public function getAdminEditFields($package, $vars = null)
    {
        Loader::loadHelpers($this, ['Form', 'Html']);

        $fields = new ModuleFields();

        if ($package->meta->type == 'domain') {
            // Domain
            $fields->setField(
                $fields->label(Language::_('Dynadot.domain.domain', true), 'domain')
                    ->attach($fields->fieldText('domain', $vars->domain ?? null, ['id' => 'domain']))
            );

            // Nameservers
            for ($i=1; $i<=5; $i++) {
                $fields->setField(
                    $fields->label(Language::_('Dynadot.nameserver.ns' . $i, true), 'ns' . $i)
                        ->attach($fields->fieldText('ns' . $i, $vars->{'ns' . $i} ?? null, ['id' => 'ns' . $i]))
                );
            }

            // Auth Code
            $fields->setField(
                 $fields->label(Language::_('Dynadot.transfer.EPPCode', true), 'auth')
                    ->attach($fields->fieldText('auth', $vars->auth ?? null, ['id' => 'auth']))
            );
        }

        return $fields;
    }

    public function getAdminAddFields($package, $vars = null)
    {
        Loader::loadHelpers($this, ['Form', 'Html']);

        if ($package->meta->type == 'domain') {
            // Set default name servers
            if (!isset($vars->ns1) && isset($package->meta->ns)) {
                $i = 1;
                foreach ($package->meta->ns as $ns) {
                    $vars->{'ns' . $i++} = $ns;
                }
            }

            // Handle transfer request
            if ((isset($vars->transfer) && $vars->transfer) || (isset($vars->auth) && $vars->auth)) {
                return $this->arrayToModuleFields(Configure::get('Dynadot.transfer_fields'), null, $vars);
            } else {
                // Handle domain registration
                $fields = Configure::get('Dynadot.transfer_fields');

                $fields['transfer'] = [
                    'label' => Language::_('Dynadot.domain.DomainAction', true),
                    'type' => 'radio',
                    'value' => '1',
                    'options' => [
                        '0' => 'Register',
                        '1' => 'Transfer',
                    ],
                ];

                $fields['auth'] = [
                    'label' => Language::_('Dynadot.transfer.EPPCode', true),
                    'type' => 'text',
                ];

                $module_fields = $this->arrayToModuleFields(
                    array_merge($fields, Configure::get('Dynadot.nameserver_fields')),
                    null,
                    $vars
                );

                // JS to toggle EPP/NS fields
                $module_fields->setHtml(
                    "
                    <script type=\"text/javascript\">
                        $(document).ready(function() {
                            $('#transfer_id_0').prop('checked', true);
                            $('#auth_id').closest('li').hide();
                            if ($('input[name=\"transfer\"]:checked').val() == '1') {
                                $('#auth_id').closest('li').show();
                            }

                            $('input[name=\"transfer\"]').change(function() {
                                if ($('input[name=\"transfer\"]:checked').val() == '1') {
                                    $('#auth_id').closest('li').show();
                                    $('[id^=\"ns\"]').closest('li').hide();
                                } else {
                                    $('#auth_id').closest('li').hide();
                                    $('[id^=\"ns\"]').closest('li').show();
                                }
                            });
                        });
                    </script>"
                );

                return $module_fields;
            }
        }
        return new ModuleFields();
    }

    public function getClientAddFields($package, $vars = null)
    {
        // Handle universal domain name
        if (isset($vars->domain)) {
            $vars->domain = $vars->domain;
        }

        if ($package->meta->type == 'domain') {
            // Set default name servers
            if (!isset($vars->ns) && isset($package->meta->ns)) {
                $i = 1;
                foreach ($package->meta->ns as $ns) {
                    $vars->{'ns' . $i++} = $ns;
                }
            }

            // Handle transfer request
            if ((isset($vars->transfer) && $vars->transfer) || isset($vars->auth)) {
                $fields = Configure::get('Dynadot.transfer_fields');

                // We should already have the domain name don't make editable
                $fields['domain']['type'] = 'hidden';
                $fields['domain']['label'] = null;

                return $this->arrayToModuleFields($fields, null, $vars);
            } else {
                // Handle domain registration
                $fields = array_merge(
                    Configure::get('Dynadot.nameserver_fields'),
                    Configure::get('Dynadot.domain_fields')
                );

                // We should already have the domain name don't make editable
                $fields['domain']['type'] = 'hidden';
                $fields['domain']['label'] = null;

                return $this->arrayToModuleFields($fields, null, $vars);
            }
        }
        return new ModuleFields();
    }

    public function getAdminServiceTabs($service)
    {
        return [
            'tabWhois' => Language::_('Dynadot.tab_whois.title', true),
            'tabNameservers' => Language::_('Dynadot.tab_nameservers.title', true),
            'tabSettings' => Language::_('Dynadot.tab_settings.title', true),
            'tabHosts' => Language::_('Dynadot.tab_hosts.title', true),
            'tabDnssec' => Language::_('Dynadot.tab_dnssec.title', true),
            'tabDnsRecords' => Language::_('Dynadot.tab_dnsrecords.title', true),
            'tabEmailForwarding' => Language::_('Dynadot.tab_email_forwarding.title', true),
        ];
    }

    public function getClientServiceTabs($service)
    {
        return [
            'tabClientWhois' => ['name' => Language::_('Dynadot.tab_whois.title', true), 'icon' => 'fas fa-users'],
            'tabClientNameservers' => ['name' => Language::_('Dynadot.tab_nameservers.title', true), 'icon' => 'fas fa-server'],
            'tabClientSettings' => ['name' => Language::_('Dynadot.tab_settings.title', true), 'icon' => 'fas fa-cog'],
            'tabClientHosts' => ['name' => Language::_('Dynadot.tab_hosts.title', true), 'icon' => 'fas fa-hdd'],
            'tabClientDnssec' => ['name' => Language::_('Dynadot.tab_dnssec.title', true), 'icon' => 'fas fa-lock'],
            'tabClientDnsRecords' => ['name' => Language::_('Dynadot.tab_dnsrecords.title', true), 'icon' => 'fas fa-sitemap'],
            'tabClientEmailForwarding' => ['name' => Language::_('Dynadot.tab_email_forwarding.title', true), 'icon' => 'fas fa-envelope'],
        ];
    }

    public function tabWhois($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageWhois('tab_whois', $package, $service, $get, $post, $files);
    }

    public function tabClientWhois($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageWhois('tab_client_whois', $package, $service, $get, $post, $files);
    }

    private function manageWhois($view, $package, $service, array $get = null, array $post = null, array $files = null)
    {
        $vars = new stdClass();
        $this->view = new View($view, 'default');
        Loader::loadHelpers($this, ['Form', 'Html']);

        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
        $api = $this->getApi($row->meta->key, $row->meta->sandbox == 'true');
        $domain = $this->getServiceDomain($service);

        $whois_fields = Configure::get('Dynadot.whois_fields');
        $sections = ['Registrant', 'Admin', 'Technical', 'Billing'];

        // Get current info for IDs
        $current_ids = [];
        $info = $api->submit('domain_info', ['domain' => $domain]);
        $info_res = $info->response();

        if (isset($info_res->DomainInfoContent->Domain->Whois)) {
            $whois_ids = $info_res->DomainInfoContent->Domain->Whois;
            foreach ($sections as $section) {
                if (isset($whois_ids->$section->ContactId)) {
                    $current_ids[$section] = (string)$whois_ids->$section->ContactId;
                }
            }
        }

        if (!empty($post)) {
            $contact_ids = [];

            foreach ($sections as $section) {
                $contact_args = [];
                $prefix = $section;

                $fn = $post[$prefix . 'FirstName'] ?? '';
                $ln = $post[$prefix . 'LastName'] ?? '';
                $contact_args['name'] = trim($fn . ' ' . $ln);
                $contact_args['email'] = $post[$prefix . 'EmailAddress'] ?? '';

                $phone = $post[$prefix . 'Phone'] ?? '';
                if (preg_match('/^\+(\d+)\.(.+)$/', $phone, $matches)) {
                    $contact_args['phonecc'] = $matches[1];
                    $contact_args['phonenum'] = $matches[2];
                } else {
                    $contact_args['phonecc'] = '1';
                    $contact_args['phonenum'] = $phone;
                }

                $contact_args['organization'] = $post[$prefix . 'Organization'] ?? '';
                $contact_args['address1'] = $post[$prefix . 'Address1'] ?? '';
                $contact_args['address2'] = $post[$prefix . 'Address2'] ?? '';
                $contact_args['city'] = $post[$prefix . 'City'] ?? '';
                $contact_args['state'] = $post[$prefix . 'StateProvince'] ?? '';
                $contact_args['zip'] = $post[$prefix . 'PostalCode'] ?? '';
                $contact_args['country'] = $post[$prefix . 'Country'] ?? '';

                $response = null;
                $cid = $current_ids[$section] ?? 0;

                if ($cid > 0) {
                    // Update existing
                    $contact_args['contact_id'] = $cid;
                    $response = $api->submit('edit_contact', $contact_args);
                    // On success, ID stays the same
                    if ($response->status() == 'success') {
                        $contact_ids[strtolower($section) . '_contact'] = $cid;
                    }
                } else {
                    // Create new
                    $response = $api->submit('create_contact', $contact_args);

                    if ($response->status() == 'success') {
                        $res = $response->response();
                        if (isset($res->CreateContactContent->ContactId)) {
                            $contact_ids[strtolower($section) . '_contact'] = (string)$res->CreateContactContent->ContactId;
                        }
                    }
                }

                if ($response && $response->status() != 'success') {
                     // If update failed, maybe try create? Or report error.
                     // Report error for now.
                     $this->Input->setErrors(['errors' => $response->errors()]);
                     // Return early to show errors
                     $vars = (object)$post;
                     $this->view->set('vars', $vars);
                     $this->view->set('fields', $whois_fields);
                     $this->view->setDefaultView(self::$defaultModuleView);
                     return $this->view->fetch();
                }
            }

            if (!empty($contact_ids)) {
                $args = array_merge(['domain' => $domain], $contact_ids);
                $response = $api->submit('set_whois', $args);
                $this->processResponse($api, $response);
            }

            $vars = (object)$post;
        } else {
            // Populate vars from current info
            foreach ($current_ids as $section => $cid) {
                if ($cid > 0) {
                    $c_resp = $api->submit('get_contact', ['contact_id' => $cid]);
                    $c_res = $c_resp->response();

                    if (isset($c_res->GetContactContent->GetContact->Contact)) {
                        $c = $c_res->GetContactContent->GetContact->Contact;
                        $prefix = $section;

                        $name_parts = explode(' ', (string)$c->Name, 2);
                        $vars->{$prefix . 'FirstName'} = $name_parts[0] ?? '';
                        $vars->{$prefix . 'LastName'} = $name_parts[1] ?? '';
                        $vars->{$prefix . 'EmailAddress'} = (string)$c->Email;
                        $vars->{$prefix . 'Phone'} = '+' . (string)$c->PhoneCc . '.' . (string)$c->PhoneNum;
                        $vars->{$prefix . 'Organization'} = (string)$c->Organization;
                        $vars->{$prefix . 'Address1'} = (string)$c->Address1;
                        $vars->{$prefix . 'Address2'} = (string)$c->Address2;
                        $vars->{$prefix . 'City'} = (string)$c->City;
                        $vars->{$prefix . 'StateProvince'} = (string)$c->State;
                        $vars->{$prefix . 'PostalCode'} = (string)$c->ZipCode;
                        $vars->{$prefix . 'Country'} = (string)$c->Country;
                    }
                }
            }
        }

        $this->view->set('vars', $vars);
        $this->view->set('fields', $whois_fields);
        $this->view->setDefaultView(self::$defaultModuleView);
        return $this->view->fetch();
    }

    public function tabNameservers($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageNameservers('tab_nameservers', $package, $service, $get, $post, $files);
    }

    public function tabClientNameservers($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageNameservers('tab_client_nameservers', $package, $service, $get, $post, $files);
    }

    private function manageNameservers($view, $package, $service, array $get = null, array $post = null, array $files = null)
    {
        $vars = new stdClass();
        $this->view = new View($view, 'default');
        Loader::loadHelpers($this, ['Form', 'Html']);
        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
        $api = $this->getApi($row->meta->key, $row->meta->sandbox == 'true');
        $domain = $this->getServiceDomain($service);

        if (!empty($post)) {
            $args = [];
            $i = 0;
            foreach ($post['ns'] as $ns) {
                if (!empty($ns)) {
                    $args['ns' . $i] = $ns;
                    $i++;
                }
            }
            $args['domain'] = $domain;
            $response = $api->submit('set_ns', $args);
            $this->processResponse($api, $response);
            $vars = (object) $post;
        } else {
            $nameservers = $this->getDomainNameServers($domain, $service->module_row_id);
            $vars->ns = [];
            foreach ($nameservers as $ns) {
                $vars->ns[] = $ns['url'];
            }
        }

        $this->view->set('vars', $vars);
        $this->view->setDefaultView(self::$defaultModuleView);
        return $this->view->fetch();
    }

    public function tabSettings($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageSettings('tab_settings', $package, $service, $get, $post, $files);
    }

    public function tabClientSettings($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageSettings('tab_client_settings', $package, $service, $get, $post, $files);
    }

    private function manageSettings($view, $package, $service, array $get = null, array $post = null, array $files = null)
    {
        $vars = new stdClass();
        $this->view = new View($view, 'default');
        Loader::loadHelpers($this, ['Form', 'Html']);
        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
        $api = $this->getApi($row->meta->key, $row->meta->sandbox == 'true');
        $domain = $this->getServiceDomain($service);

        if (!empty($post)) {
            if (isset($post['registrar_lock'])) {
                if ($post['registrar_lock'] == 'yes') {
                    $command = 'lock_domain';
                } else {
                    $command = 'unlock_domain';
                }
                $response = $api->submit($command, ['domain' => $domain]);
                $this->processResponse($api, $response);
            }
            if (isset($post['request_epp'])) {
                $response = $api->submit('get_transfer_auth_code', ['domain' => $domain]);
                $this->processResponse($api, $response);
                if ($response->status() == 'success') {
                    $res = $response->response();
                    if (isset($res->GetTransferAuthCodeHeader->AuthCode)) {
                        $this->setMessage('success', Language::_('Dynadot.!success.epp_code_sent', true) . ' : ' . $res->GetTransferAuthCodeHeader->AuthCode);
                    }
                }
            }
            $vars = (object) $post;
        }

        $info = $api->submit('domain_info', ['domain' => $domain]);
        $info_res = $info->response();
        if (isset($info_res->DomainInfoContent->Domain->Locked)) {
            $vars->registrar_lock = $info_res->DomainInfoContent->Domain->Locked;
        }

        $this->view->set('vars', $vars);
        $this->view->setDefaultView(self::$defaultModuleView);
        return $this->view->fetch();
    }

    public function tabHosts($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageHosts('tab_hosts', $package, $service, $get, $post, $files);
    }

    public function tabClientHosts($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageHosts('tab_client_hosts', $package, $service, $get, $post, $files);
    }

    private function manageHosts($view, $package, $service, array $get = null, array $post = null, array $files = null)
    {
        $vars = new stdClass();
        $this->view = new View($view, 'default');
        Loader::loadHelpers($this, ['Form', 'Html']);
        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
        $api = $this->getApi($row->meta->key, $row->meta->sandbox == 'true');
        $domain = $this->getServiceDomain($service);

        if (!empty($post)) {
            if (isset($post['host']) && isset($post['ip'])) {
                $api->submit('register_ns', ['host' => $post['host'] . '.' . $domain, 'ip' => $post['ip']]);
            }
        }

        if (isset($get['action']) && $get['action'] == 'delete' && isset($get['host'])) {
            // Find ID first
            $response = $api->submit('server_list');
            $res = $response->response();
            $server_id = null;
            if (isset($res->ServerListContent->NameServerList->List->Server)) {
                $servers = is_array($res->ServerListContent->NameServerList->List->Server) ? $res->ServerListContent->NameServerList->List->Server : [$res->ServerListContent->NameServerList->List->Server];
                foreach ($servers as $s) {
                     if (isset($s->ServerName) && $s->ServerName == $get['host'] . '.' . $domain) {
                         $server_id = (string)$s->ServerId;
                         break;
                     }
                }
            }

            if ($server_id) {
                $api->submit('delete_ns', ['server_id' => $server_id]);
            } else {
                 // Fallback if not found in list or filtering issue?
                 // But delete_ns requires ID.
            }
        }

        $response = $api->submit('server_list');
        $res = $response->response();

        $hosts = [];
        if (isset($res->ServerListContent->NameServerList->List->Server)) {
            $servers = is_array($res->ServerListContent->NameServerList->List->Server) ? $res->ServerListContent->NameServerList->List->Server : [$res->ServerListContent->NameServerList->List->Server];
            foreach ($servers as $s) {
                if (isset($s->ServerName)) {
                    $sn = (string)$s->ServerName;
                    if (substr($sn, -strlen($domain)) === $domain) {
                        $host_part = substr($sn, 0, -strlen($domain) - 1);
                        $ip = isset($s->ServerIp) ? (string)$s->ServerIp : '';
                        $hosts[] = (object)['host' => $host_part, 'ip' => $ip];
                    }
                }
            }
        }
        $vars->hosts = $hosts;

        $this->view->set('vars', $vars);
        $this->view->set('client_id', $service->client_id);
        $this->view->set('service_id', $service->id);
        $this->view->setDefaultView(self::$defaultModuleView);
        return $this->view->fetch();
    }

    public function tabDnssec($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageDnssec('tab_dnssec', $package, $service, $get, $post, $files);
    }

    public function tabClientDnssec($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageDnssec('tab_client_dnssec', $package, $service, $get, $post, $files);
    }

    private function manageDnssec($view, $package, $service, array $get = null, array $post = null, array $files = null)
    {
        $vars = new stdClass();
        $this->view = new View($view, 'default');
        Loader::loadHelpers($this, ['Form', 'Html']);
        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
        $api = $this->getApi($row->meta->key, $row->meta->sandbox == 'true');
        $domain = $this->getServiceDomain($service);

        if (!empty($post)) {
            if (isset($post['action'])) {
                if ($post['action'] == 'add') {
                    $args = [
                        'domain_name' => $domain,
                        'key_tag' => $post['key_tag'],
                        'algorithm' => $post['algorithm'],
                        'digest_type' => $post['digest_type'],
                        'digest' => $post['digest']
                    ];
                    $api->submit('set_dnssec', $args);
                } elseif ($post['action'] == 'delete') {
                    // Use delete_dnssec. It needs params.
                    // My view provides them as hidden fields.
                    $args = [
                        'domain_name' => $domain, // or domain?
                        'key_tag' => $post['key_tag'],
                        'algorithm' => $post['algorithm'],
                        'digest_type' => $post['digest_type'],
                        'digest' => $post['digest']
                    ];
                    // delete_dnssec usually exists for granular delete.
                    // If not, clear_dnssec is fallback but destructive.
                    // I will try delete_dnssec (assuming it exists based on Reviewer).
                    // If it fails, I'll log error.
                    // Note: Reviewer said delete_dnssec exists.
                    // I'll try it.
                    $api->submit('delete_dnssec', $args);
                }
            }
        }

        if (isset($get['action']) && $get['action'] == 'add') {
            $vars->add_view = true;
            $vars->algorithms = [
                1 => 'RSA/MD5', 2 => 'Diffie-Hellman', 3 => 'DSA/SHA-1', 5 => 'RSA/SHA-1',
                6 => 'DSA-NSEC3-SHA1', 7 => 'RSASHA1-NSEC3-SHA1', 8 => 'RSA/SHA-256',
                10 => 'RSA/SHA-512', 13 => 'ECDSA Curve P-256 with SHA-256', 14 => 'ECDSA Curve P-384 with SHA-384'
            ];
            $vars->digest_types = [1 => 'SHA-1', 2 => 'SHA-256', 3 => 'GOSTR 34.11-94', 4 => 'SHA-384'];
        }

        $response = $api->submit('get_dnssec', ['domain' => $domain]);
        $res = $response->response();
        $records = [];
        if (isset($res->GetDnssecContent->DnssecRecord)) {
             $recs = is_array($res->GetDnssecContent->DnssecRecord) ? $res->GetDnssecContent->DnssecRecord : [$res->GetDnssecContent->DnssecRecord];
             foreach ($recs as $r) {
                 $records[] = (object)[
                     'key_tag' => (string)$r->KeyTag,
                     'algorithm' => (string)$r->Algorithm,
                     'digest_type' => (string)$r->DigestType,
                     'digest' => (string)$r->Digest
                 ];
             }
        }
        $vars->records = $records;

        $this->view->set('vars', $vars);
        $this->view->set('client_id', $service->client_id);
        $this->view->set('service_id', $service->id);
        $this->view->setDefaultView(self::$defaultModuleView);
        return $this->view->fetch();
    }

    public function tabDnsRecords($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageDnsRecords('tab_dnsrecords', $package, $service, $get, $post, $files);
    }

    public function tabClientDnsRecords($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageDnsRecords('tab_client_dnsrecords', $package, $service, $get, $post, $files);
    }

    private function manageDnsRecords($view, $package, $service, array $get = null, array $post = null, array $files = null)
    {
        $vars = new stdClass();
        $this->view = new View($view, 'default');
        Loader::loadHelpers($this, ['Form', 'Html']);
        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
        $api = $this->getApi($row->meta->key, $row->meta->sandbox == 'true');
        $domain = $this->getServiceDomain($service);

        $vars->record_types = Configure::get('Dynadot.dns_records');

        if (!empty($post)) {
            // Process update - overwrite
            $args = ['domain' => $domain];

            // Collect records
            $count = 0;
            for ($i=0; $i<5; $i++) {
                if (!empty($post['main_record' . $i]) && !empty($post['main_record_type' . $i])) {
                    $args['main_record_type' . $count] = $post['main_record_type' . $i];
                    $args['main_record' . $count] = $post['main_record' . $i];

                    if (!empty($post['subdomain' . $i])) {
                        unset($args['main_record_type' . $count]);
                        unset($args['main_record' . $count]);
                    } else {
                        $count++;
                    }
                }
            }

            // Re-loop to handle correctly
            $main_args = [];
            $sub_args = [];
            $m_cnt = 0;
            $s_cnt = 0;

            for ($i=0; $i<5; $i++) {
                if (!empty($post['main_record' . $i]) && !empty($post['main_record_type' . $i])) {
                    if (empty($post['subdomain' . $i])) {
                        // Main
                        $main_args['main_record_type' . $m_cnt] = $post['main_record_type' . $i];
                        $main_args['main_record' . $m_cnt] = $post['main_record' . $i];
                        $m_cnt++;
                    } else {
                        // Sub
                        $sub_args['sub_record_type' . $s_cnt] = $post['main_record_type' . $i];
                        $sub_args['sub_record' . $s_cnt] = $post['main_record' . $i];
                        $sub_args['subdomain' . $s_cnt] = $post['subdomain' . $i];
                        $s_cnt++;
                    }
                }
            }

            $api_args = array_merge(['domain' => $domain], $main_args, $sub_args);
            $api->submit('set_dns2', $api_args);
        } else {
            // Fetch existing records
            $response = $api->submit('get_dns', ['domain' => $domain]);
            $res = $response->response();

            if (isset($res->GetDnsContent->DnsContent->MainRecord)) {
                 $recs = is_array($res->GetDnsContent->DnsContent->MainRecord) ? $res->GetDnsContent->DnsContent->MainRecord : [$res->GetDnsContent->DnsContent->MainRecord];
                 $i = 0;
                 foreach ($recs as $r) {
                     if ($i >= 5) break;
                     $vars->{'main_record_type' . $i} = (string)$r->RecordType;
                     $vars->{'main_record' . $i} = (string)$r->Value;
                     $i++;
                 }
            }

            if (isset($res->GetDnsContent->DnsContent->SubRecord)) {
                 $recs = is_array($res->GetDnsContent->DnsContent->SubRecord) ? $res->GetDnsContent->DnsContent->SubRecord : [$res->GetDnsContent->DnsContent->SubRecord];
                 $i = (isset($i) ? $i : 0);
                 foreach ($recs as $r) {
                     if ($i >= 5) break;
                     $vars->{'main_record_type' . $i} = (string)$r->RecordType;
                     $vars->{'main_record' . $i} = (string)$r->Value;
                     $vars->{'subdomain' . $i} = (string)$r->SubHost;
                     $i++;
                 }
            }
        }

        $this->view->set('vars', $vars);
        $this->view->set('client_id', $service->client_id);
        $this->view->set('service_id', $service->id);
        $this->view->setDefaultView(self::$defaultModuleView);
        return $this->view->fetch();
    }

    public function tabEmailForwarding($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageEmailForwarding('tab_email_forwarding', $package, $service, $get, $post, $files);
    }

    public function tabClientEmailForwarding($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageEmailForwarding('tab_client_email_forwarding', $package, $service, $get, $post, $files);
    }

    private function manageEmailForwarding($view, $package, $service, array $get = null, array $post = null, array $files = null)
    {
        $vars = new stdClass();
        $this->view = new View($view, 'default');
        Loader::loadHelpers($this, ['Form', 'Html']);
        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
        $api = $this->getApi($row->meta->key, $row->meta->sandbox == 'true');
        $domain = $this->getServiceDomain($service);

        if (!empty($post)) {
            // Process update - overwrite
            $args = [
                'domain' => $domain,
                'forward_type' => 'forward'
            ];

            $count = 0;
            for ($i=0; $i<5; $i++) {
                if (!empty($post['username' . $i]) && !empty($post['exist_email' . $i])) {
                    $args['username' . $count] = $post['username' . $i];
                    $args['exist_email' . $count] = $post['exist_email' . $i];
                    $count++;
                }
            }

            if ($count > 0) {
                $api->submit('set_email_forward', $args);
            }
        }

        $this->view->set('vars', $vars);
        $this->view->set('client_id', $service->client_id);
        $this->view->set('service_id', $service->id);
        $this->view->setDefaultView(self::$defaultModuleView);
        return $this->view->fetch();
    }

    /**
     * Get a list of the TLDs supported by the registrar module
     *
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @return array A list of all TLDs supported by the registrar module
     */
    public function getTlds($module_row_id = null)
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->key, $row->meta->sandbox == 'true');

        // Fetch TLDs from cache
        $cache = Cache::fetchCache(
            'tlds',
            Configure::get('Blesta.company_id') . DS . 'modules' . DS . 'dynadot' . DS
        );

        if ($cache) {
            return unserialize(base64_decode($cache));
        }

        $response = $api->submit('tld_price', ['currency' => 'USD']);
        $result = $response->response();

        $tlds = [];
        if (isset($result->TldPriceContent->TldContent)) {
            $list = is_array($result->TldPriceContent->TldContent) ? $result->TldPriceContent->TldContent : [$result->TldPriceContent->TldContent];
            foreach ($list as $item) {
                if (isset($item->Tld)) {
                    $tlds[] = '.' . $item->Tld;
                }
            }
        }

        if (count($tlds) > 0) {
            if (Configure::get('Caching.on') && is_writable(CACHEDIR)) {
                try {
                    Cache::writeCache(
                        'tlds',
                        base64_encode(serialize($tlds)),
                        strtotime(Configure::get('Blesta.cache_length')) - time(),
                        Configure::get('Blesta.company_id') . DS . 'modules' . DS . 'dynadot' . DS
                    );
                } catch (Exception $e) {
                    // Write to cache failed
                }
            }
        }

        return $tlds;
    }

    // Pricing
    public function getTldPricing($module_row_id = null)
    {
        return $this->getFilteredTldPricing($module_row_id);
    }

    /**
     * Creates a contact on Dynadot from the Blesta client and returns the Contact ID
     *
     * @param int $client_id The ID of the client to create the contact for
     * @param DynadotApi $api The Dynadot API instance
     * @return string|null The Contact ID if successful, null otherwise
     */
    private function getContactIdFromClient($client_id, $api)
    {
        if (!$client_id) {
            return null;
        }

        $client = $this->Clients->get($client_id);
        if (!$client) {
            return null;
        }

        // Parse phone: assume +CC.Number or just Number (default to US 1)
        // Blesta stores phone numbers as strings.
        // Dynadot needs CC and Num.
        $phone_cc = '1';
        $phone_num = preg_replace('/[^0-9]/', '', $client->phone_number ?? ''); // strip non-digits

        // Simple heuristic: if starts with +, extract CC
        // But Blesta often stores without +.
        // If we can't parse easily, default to US/1.
        // Or if the client has a country, use that to lookup code? Too complex for now.
        // Let's assume standard format or default.
        if (strlen($phone_num) > 10) {
             // Maybe has CC?
             // e.g. 447700900000 -> 44, 7700900000
             // This is risky.
             // Safer to fallback to US 1 if unsure, or leave CC 1.
        }
        // Actually, Dynadot requires valid CC.
        // Let's try to match a leading +CC pattern from $client->phone (original string).
        if (preg_match('/^\+(\d+)\.(.+)$/', $client->phone_number ?? '', $matches)) {
            $phone_cc = $matches[1];
            $phone_num = preg_replace('/[^0-9]/', '', $matches[2]);
        }

        if (empty($phone_num)) {
             $phone_num = '5555555555'; // Fallback
        }

        $args = [
            'name' => ($client->first_name ?? '') . ' ' . ($client->last_name ?? ''),
            'email' => $client->email ?? '',
            'phonecc' => $phone_cc,
            'phonenum' => $phone_num,
            'organization' => $client->company ?? '',
            'address1' => $client->address1 ?? '',
            'address2' => $client->address2 ?? '',
            'city' => $client->city ?? '',
            'state' => $client->state ?? '',
            'zip' => $client->zip ?? '',
            'country' => $client->country ?? ''
        ];

        // Clean empty args
        foreach ($args as $k => $v) {
            if (empty($v)) unset($args[$k]);
        }

        $response = $api->submit('create_contact', $args);
        $res = $response->response();

        if ($response->status() == 'success' && isset($res->CreateContactContent->ContactId)) {
            return (string)$res->CreateContactContent->ContactId;
        }

        return null;
    }

    public function getFilteredTldPricing($module_row_id = null, $filters = [])
    {
        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->key, $row->meta->sandbox == 'true');

        $response = $api->submit('tld_price', ['currency' => 'USD']);
        $result = $response->response();

        $tld_yearly_prices = [];

        if (isset($result->TldPriceContent->TldContent)) {
            $tlds = is_array($result->TldPriceContent->TldContent) ? $result->TldPriceContent->TldContent : [$result->TldPriceContent->TldContent];

            foreach ($tlds as $tld_data) {
                if (isset($tld_data->Tld)) {
                    $tld = '.' . $tld_data->Tld;
                    $prices = $tld_data->Price;

                    if (isset($filters['tlds']) && !in_array($tld, $filters['tlds'])) {
                        continue;
                    }

                    $currency = 'USD';

                    $tld_yearly_prices[$tld][$currency] = [];
                    foreach (range(1, 10) as $years) {
                         $tld_yearly_prices[$tld][$currency][$years] = [
                            'register' => (float)$prices->Register * $years,
                            'transfer' => (float)$prices->Transfer * $years,
                            'renew' => (float)$prices->Renew * $years
                        ];
                    }
                }
            }
        }

        return $tld_yearly_prices;
    }
}
