<?php

/**
 * Namesilo Module
 *
 * @package blesta
 * @subpackage blesta.components.modules.namesilo
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @link http://www.blesta.com/ Blesta
 * @copyright Copyright (c) 2015-2018, NETLINK IT SERVICES
 * @link http://www.netlink.ie/ NETLINK
 */
class Namesilo extends RegistrarModule
{
    /**
     * @var array Namesilo response codes
     */
    private static $codes;

    /**
     * @var string Default module view path
     */
    private static $defaultModuleView;

    private $api;
    public $logger;

    /**
     * Initializes the module
     */
    public function __construct()
    {
        // Load config.json
        $this->loadConfig(__DIR__ . DS . 'config.json');

        // Load models required by this module
        Loader::loadModels($this, ['PluginManager']);

        // Load components required by this module
        Loader::loadComponents($this, ['Input', 'Record']);

        // Load the language required by this module
        Language::loadLang('namesilo', null, __DIR__ . DS . 'language' . DS);

        // Load configuration
        Configure::load('namesilo', __DIR__ . DS . 'config' . DS);

        // Get Namesilo response codes
        self::$codes = Configure::get('Namesilo.status.codes');

        // Set default module view
        self::$defaultModuleView = 'components' . DS . 'modules' . DS . 'namesilo' . DS;
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
        $this->addCronTasks($this->getCronTasks());
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
            // Handle the upgrade, set errors using $this->Input->setErrors() if any errors encountered
            if (!isset($this->Record)) {
                Loader::loadComponents($this, ['Record']);
            }

            // Upgrade to 2.0.0
            if (version_compare($current_version, '2.0.0', '<')) {
                $rows = $this->getRows();
                foreach ($rows as $row) {
                    // Delete tld pricing
                    $this->Record->from('module_row_meta')
                        ->where('module_row_meta.module_row_id', '=', $row->id)
                        ->open()
                            ->where('module_row_meta.key', 'LIKE', 'tld_%_pricing')
                            ->orwhere('module_row_meta.key', '=', 'tld_packages_map')
                            ->orwhere('module_row_meta.key', '=', 'tld_packages_settings')
                        ->close()
                        ->delete();
                }
            }

            // Upgrade to 2.1.0
            if (version_compare($current_version, '2.1.0', '<')) {
                Cache::clearCache(
                    'tlds_prices',
                    Configure::get('Blesta.company_id') . DS . 'modules' . DS . 'namesilo' . DS
                );
            }
            // Upgrade if possible
            if (version_compare($current_version, '3.4.1', '<')) {
                $this->addCronTasks($this->getCronTasks());
            }
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
        if (!isset($this->Record)) {
            Loader::loadComponents($this, ['Record']);
        }
        Loader::loadModels($this, ['CronTasks']);

        $cron_tasks = $this->getCronTasks();

        if ($last_instance) {
            // Remove the cron tasks
            foreach ($cron_tasks as $task) {
                $cron_task = $this->CronTasks->getByKey($task['key'], $task['dir'], $task['task_type']);
                if ($cron_task) {
                    $this->CronTasks->deleteTask($cron_task->id, $task['task_type'], $task['dir']);
                }
            }
        }

        // Remove individual cron task runs
        foreach ($cron_tasks as $task) {
            $cron_task_run = $this->CronTasks
                ->getTaskRunByKey($task['key'], $task['dir'], false, $task['task_type']);
            if ($cron_task_run) {
                $this->CronTasks->deleteTaskRun($cron_task_run->task_run_id);
            }
        }
    }


    /**
     * Runs the cron task identified by the key used to create the cron task
     *
     * @param string $key The key used to create the cron task
     * @see CronTasks::add()
     */
    public function cron($key)
    {
        if ($key == 'pull_contacts') {
            $this->synchronizeContacts();
        }
    }

    /**
     * Retrieves cron tasks available to this module along with their default values
     *
     * @return array A list of cron tasks
     */
    private function getCronTasks()
    {
        return [
            [
                'key' => 'pull_contacts',
                'task_type' => 'module',
                'dir' => 'namesilo',
                'name' => Language::_('Namesilo.getCronTasks.pull_contacts_name', true),
                'description' => Language::_('Namesilo.getCronTasks.pull_contacts_desc', true),
                'type' => 'interval',
                'type_value' => 10,
                'enabled' => 1
            ]
        ];
    }

    /**
     * Attempts to add new cron tasks for this module
     *
     * @param array $tasks A list of cron tasks to add
     */
    private function addCronTasks(array $tasks)
    {
        Loader::loadModels($this, ['CronTasks']);
        foreach ($tasks as $task) {
            $task_id = $this->CronTasks->add($task);

            if (!$task_id) {
                $cron_task = $this->CronTasks->getByKey($task['key'], $task['dir'], $task['task_type']);
                if ($cron_task) {
                    $task_id = $cron_task->id;
                }
            }

            if ($task_id) {
                $task_vars = ['enabled' => $task['enabled']];
                if ($task['type'] === 'time') {
                    $task_vars['time'] = $task['type_value'];
                } else {
                    $task_vars['interval'] = $task['type_value'];
                }

                $this->CronTasks->addTaskRun($task_id, $task_vars);
            }
        }
    }

    /**
     * Pulls in contacts from Namesilo and assigns them to the appropriate Blesta user
     *
     * @param stdClass $module_row The module row for which to pull in domain contacts
     * @param int $remaining_batch_slots The number of batch slots remaining
     * @return int The number of batch slots remaining
     */
    private function synchronizeContacts()
    {
        // Get all the namesilo module rows for this company
        $module_rows = $this->Record->from('module_rows')->
            select(['module_rows.*'])->
            innerJoin('modules', 'modules.id', '=', 'module_rows.module_id', false)->
            where('modules.company_id', '=', Configure::get('Blesta.company_id'))->
            where('modules.class', '=', 'namesilo')->
            fetchAll();

        $remaining_batch_slots = 100;
        foreach ($module_rows as $module_row) {
            $remaining_batch_slots = $this->synchronizeContactsForModuleRow($module_row, $remaining_batch_slots);
        }
    }

    /**
     * Pulls in contacts from Namesilo using the given module row and assigns
     * them to the appropriate blesta user
     *
     * @param stdClass $module_row The module row for which to pull in domain contacts
     * @param int $remaining_batch_slots The number of batch slots remaining
     * @param int $client_id Submit to only sync for the given client
     * @return int The number of batch slots remaining
     */
    private function synchronizeContactsForModuleRow($module_row, $remaining_batch_slots = null, $client_id = null, $additional_domains = [])
    {
        // Get all Namesilo services for the module row
        $record = $this->Record->from('services')->
            select(['services.*', 'service_fields.value' => 'domain'])->
            on('service_fields.key', '=', 'domain')->
            innerJoin('service_fields', 'service_fields.service_id', '=', 'services.id', false)->
            on('module_client_meta.key', '=', 'contacts')->
            on('module_client_meta.client_id', '=', 'services.client_id', false)->
            leftJoin('module_client_meta', 'module_client_meta.module_row_id', '=', 'services.module_row_id', false)->
            where('module_client_meta.value', '=', null)->
            where('services.module_row_id', '=', $module_row->id ?? null)->
            where('services.status', '=', 'active');

        // Filter client ID
        if ($client_id) {
            $record->where('services.client_id', '=', $client_id);
        }

        $services = $record->fetchAll();

        // Create a list of domains with their service and client ids
        $client_domains = [];
        foreach ($services as $service) {
            if (empty($client_domains[$service->client_id])) {
                $client_domains[$service->client_id] = [];
            }

            $client_domains[$service->client_id][] = $service->domain;
        }

        // Get a batch of 100 domains for which to fetch contacts
        $queued_client_domains = [];
        foreach ($client_domains as $client_id => $domains) {
            if (!is_null($remaining_batch_slots)
                && !empty($queued_client_domains)
                && (count($queued_client_domains) + count($domains)) > $remaining_batch_slots
            ) {
                break;
            }
            $remaining_batch_slots -= count($domains);

            $queued_client_domains[$client_id] = array_merge($domains, $additional_domains[$client_id] ?? []);
        }

        foreach ($additional_domains as $client_id => $additional_domain) {
            $queued_client_domains[$client_id] = array_merge($queued_client_domains[$client_id] ?? [], $additional_domain);
        }

        $this->synchronizeContactsForDomains($queued_client_domains, $module_row);

        return $remaining_batch_slots;
    }

    /**
     * Pulls in contacts from Namesilo and assigns them to the appropriate blesta user
     *
     * @param array $queued_client_domains A list clients their domains in the queue to pull in contacts
     * @param stdClass $module_row The module row for which to pull in domain contacts
     * @return int The number of batch slots remaining
     */
    private function synchronizeContactsForDomains($queued_client_domains, $module_row)
    {
        // Get the contact info for each domain
        $domains_api = $this->loadApiCommand('Domains', $module_row->id, true);
        $client_contacts = [];
        foreach ($queued_client_domains as $client_id => $queued_domains) {
            foreach ($queued_domains as $queued_domain) {
                // Fetch domain info from Namesilo
                $domainInfo = $domains_api->getDomainInfo(['domain' => $queued_domain]);
                if ((self::$codes[$domainInfo->status()][1] ?? 'fail') == 'fail') {
                    $this->processResponse($this->api, $domainInfo);

                    continue;
                }

                // Get the contact ids from the domain
                $domain_response = $domainInfo->response(true);
                $contact_ids = $domain_response['contact_ids'];
                if (!isset($client_contacts[$client_id])) {
                    $client_contacts[$client_id] = [];
                }

                // Get the name for each contact from Namesilo
                foreach ($contact_ids as $contact_id) {
                    if (array_key_exists($contact_id, $client_contacts[$client_id])) {
                        continue;
                    }

                    $contactsInfo = $domains_api->getContacts(['contact_id' => $contact_id]);
                        $this->processResponse($this->api, $contactsInfo);
                    if ((self::$codes[$domainInfo->status()][1] ?? 'fail') == 'fail') {

                        continue;
                    }

                    $contact_response = $contactsInfo->response();
                    $client_contacts[$client_id][$contact_id] = $contact_response->contact->first_name
                        . ' ' . $contact_response->contact->last_name;
                }
            }
        }

        // For each client store a mapping of namesilo contact ids to names
        foreach ($client_contacts as $contact_client_id => $contacts) {
            $this->Record->duplicate('module_id', '=', $module_row->module_id)->
                duplicate('module_row_id', '=', $module_row->id)->
                duplicate('client_id', '=', $contact_client_id)->
                duplicate('key', '=', 'contacts')->
                insert(
                    'module_client_meta',
                    [
                        'module_id' => $module_row->module_id,
                        'module_row_id' => $module_row->id,
                        'client_id' => $contact_client_id,
                        'key' => 'contacts',
                        'value' => json_encode($contacts)
                    ]
                );
        }
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
        $api = $this->getApi($row->meta->user, $row->meta->key, $row->meta->sandbox == 'true');
        $dns = new NamesiloDomainsDns($api);

        $response = $dns->getList(['domain' => $domain]);
        $this->processResponse($api, $response);
        $result = $response->response();

        $nameservers = [];
        if (isset($result->nameservers)) {
            foreach ($result->nameservers->nameserver as $ns) {
                $nameservers[] = [
                    'url' => trim($ns),
                    'ips' => [gethostbyname(trim($ns))]
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
        $api = $this->getApi($row->meta->user, $row->meta->key, $row->meta->sandbox == 'true');
        $dns = new NamesiloDomainsDns($api);

        $args = [];
        $i = 1;
        foreach ($vars as $ns) {
            $args['ns' . $i] = $ns;
            $i++;
        }

        $args['domain'] = $domain;

        $response = $dns->setCustom($args);
        $this->processResponse($api, $response);

        return self::$codes[$response->status()][1] == 'success';
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
                        'message' => Language::_('Namesilo.!error.epp.empty', true),
                        'post_format' => 'trim'
                    ]
                ],
            ];
            $rules = array_merge($rules, $rule);
        }

        // .us fields
        if (isset($vars['usnc']) || isset($vars['usap'])) {
            $rule = [
                'usnc' => [
                    'empty' => [
                        'rule' => ['isEmpty'],
                        'negate' => true,
                        'message' => Language::_('Namesilo.!error.US.RegistrantNexus.empty', true),
                        'post_format' => 'trim',
                        'final' => true
                    ],
                    'valid' => [
                        'rule' => ['array_key_exists', Configure::get('Namesilo.domain_fields.us')['usnc']['options']],
                        'message' => Language::_('Namesilo.!error.US.RegistrantNexus.invalid', true)
                    ]
                ],
                'usap' => [
                    'empty' => [
                        'rule' => ['isEmpty'],
                        'negate' => true,
                        'message' => Language::_('Namesilo.!error.US.RegistrantPurpose.empty', true),
                        'post_format' => 'trim',
                        'final' => true
                    ],
                    'valid' => [
                        'rule' => ['array_key_exists', Configure::get('Namesilo.domain_fields.us')['usap']['options']],
                        'message' => Language::_('Namesilo.!error.US.RegistrantPurpose.invalid', true)
                    ]
                ],
            ];
            $rules = array_merge($rules, $rule);
        }

        // .ca fields
        if (isset($vars['calf']) || isset($vars['cawd']) || isset($vars['caln'])) {
            $rule = [
                'calf' => [
                    'empty' => [
                        'rule' => ['isEmpty'],
                        'negate' => true,
                        'message' => Language::_('Namesilo.!error.CA.CIRALegalType.empty', true),
                        'post_format' => 'trim',
                        'final' => true
                    ],
                    'valid' => [
                        'rule' => ['array_key_exists', Configure::get('Namesilo.domain_fields.ca')['calf']['options']],
                        'message' => Language::_('Namesilo.!error.CA.CIRALegalType.invalid', true)
                    ],
                    'other' => [
                        'rule' => ['matches', '/^OTHER$/'],
                        'negate' => true,
                        'message' => Language::_('Namesilo.!error.CA.CIRALegalType.other', true)
                    ]
                ],
                'cawd' => [
                    'empty' => [
                        'rule' => ['isEmpty'],
                        'negate' => true,
                        'message' => Language::_('Namesilo.!error.CA.CIRAWhoisDisplay.empty', true),
                        'post_format' => 'trim',
                        'final' => true
                    ],
                    'valid' => [
                        'rule' => ['array_key_exists', Configure::get('Namesilo.domain_fields.ca')['cawd']['options']],
                        'message' => Language::_('Namesilo.!error.CA.CIRAWhoisDisplay.invalid', true)
                    ]
                ],
                'caln' => [
                    'empty' => [
                        'rule' => ['isEmpty'],
                        'negate' => true,
                        'message' => Language::_('Namesilo.!error.CA.CIRALanguage.empty', true),
                        'post_format' => 'trim',
                        'final' => true
                    ],
                    'valid' => [
                        'rule' => ['array_key_exists', Configure::get('Namesilo.domain_fields.ca')['caln']['options']],
                        'message' => Language::_('Namesilo.!error.CA.CIRALanguage.invalid', true)
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
        $row = $this->getModuleRow($package->module_row);
        $api = $this->getApi($row->meta->user, $row->meta->key, $row->meta->sandbox == 'true');

        #
        # TODO: Handle validation checks
        # TODO: Fix nameservers
        #

        if (isset($vars['domain'])) {
            $tld = $this->getTld($vars['domain'], $row);
            $vars['domain'] = trim($vars['domain']);
        }

        $input_fields = array_merge(
            Configure::get('Namesilo.domain_fields'),
            (array) Configure::get('Namesilo.domain_fields' . $tld),
            (array) Configure::get('Namesilo.nameserver_fields'),
            (array) Configure::get('Namesilo.transfer_fields'),
            ['years' => true, 'transfer' => $vars['transfer'] ?? 1, 'private' => 0]
        );

        // Set the whois privacy field based on the config option
        if (isset($vars['configoptions']['id_protection'])) {
            $vars['private'] = $vars['configoptions']['id_protection'];
        }

        // .ca and .us domains can't have traditional whois privacy
        if ($tld == '.ca' || $tld == '.us') {
            unset($input_fields['private']);
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

                if (!isset($this->ModuleClientMeta)) {
                    Loader::loadModels($this, ['ModuleClientMeta']);
                }

                $whois_fields = Configure::get('Namesilo.whois_fields');

                // Set all whois info from client ($vars['client_id'])
                if (!isset($this->Clients)) {
                    Loader::loadModels($this, ['Clients']);
                }
                if (!isset($this->Contacts)) {
                    Loader::loadModels($this, ['Contacts']);
                }

                $client = $this->Clients->get($vars['client_id']);

                if ($client) {
                    $contact_numbers = $this->Contacts->getNumbers($client->contact_id);
                }

                foreach ($whois_fields as $key => $value) {
                    $input_fields[$value['rp']] = true;
                    if (strpos($key, 'phone') !== false) {
                        $vars[$value['rp']] = $this->formatPhone(
                            isset($contact_numbers[0]) ? $contact_numbers[0]->number : null,
                            $client->country
                        );
                    } else {
                        $vars[$value['rp']] =
                            (isset($value['lp']) && !empty($value['lp'])) ? $client->{$value['lp']} : 'NA';
                    }
                }

                $fields = array_intersect_key($vars, $input_fields);

                $default_meta = $this->ModuleClientMeta->get(
                    $vars['client_id'],
                    'default_contact_id',
                    $row->module_id,
                    $row->id
                );

                if (isset($default_meta->value)) {
                    $fields['contact_id'] = $default_meta->value;
                }
                if (!empty($row->meta->portfolio)) {
                    $fields['portfolio'] = $row->meta->portfolio;
                }
                if (!empty($row->meta->payment_id)) {
                    $fields['payment_id'] = $row->meta->payment_id;
                }

                // for .ca domains we need to create a special contact to use
                if ($tld == '.ca') {
                    $domains = new NamesiloDomains($api);
                    $response = $domains->addContacts($vars);
                    $this->processResponse($api, $response);
                    if ($this->Input->errors()) {
                        return;
                    }
                    $fields['contact_id'] = $response->response()->contact_id;
                }

                // Validate if the provided term is valid
                if (!$this->isValidTerm($tld, $fields['years'] ?? 1, !empty($vars['auth']))) {
                    $this->Input->setErrors(
                        ['term' => ['invalid' => Language::_('Namesilo.!error.invalid_term', true)]]
                    );

                    return;
                }

                // Handle transfer
                if (isset($vars['auth']) && $vars['auth']) {
                    // Check if the domain is available for transfer
                    if (!$this->checkTransferAvailability($vars['domain'], $row->id)) {
                        return;
                    }

                    $transfer = new NamesiloDomainsTransfer($api);
                    $response = $transfer->create($fields);
                    $this->processResponse($api, $response);

                    if ($this->Input->errors()) {
                        if (isset($vars['contact_id'])) {
                            $domains->deleteContacts(['contact_id' => $vars['contact_id']]);
                        }

                        return;
                    }
                } else {
                    // Check if the domain is available for registration
                    if (!$this->checkAvailability($vars['domain'], $row->id)) {
                        return;
                    }

                    // Handle registration
                    $domains = new NamesiloDomains($api);

                    $response = $domains->create($fields);
                    $this->processResponse($api, $response);

                    if ($this->Input->errors()) {
                        // if namesilo is running a promotion on registrations we have to work around their system if
                        // we are doing a multi-year registration
                        $error = 'Invalid number of years, or no years provided.';
                        if (reset($this->Input->errors()['errors']) === $error) {
                            // unset the errors since we are working around it
                            $this->Input->setErrors([]);

                            // set the registration length to 1 year and save the remainder for an extension
                            $total_years = $fields['years'];
                            $fields['years'] = 1;
                            $response = $domains->create($fields);
                            $this->processResponse($api, $response);

                            // now extend the remainder of the years
                            $fields['years'] = $total_years - 1;
                            $response = $domains->renew($fields);
                            $this->processResponse($api, $response);
                        }

                        if (isset($vars['contact_id'])) {
                            $domains->deleteContacts(['contact_id' => $vars['contact_id']]);
                        }

                        return;
                    }

                    // Sync domain contacts for the current client
                    $this->synchronizeContactsForModuleRow($row, null, $client->id, [$client->id => [$vars['domain']]]);
                }
            }
        }

        $meta = [];
        $fields = array_intersect_key($vars, $input_fields);
        foreach ($fields as $key => $value) {
            $meta[] = [
                'key' => $key,
                'value' => $value,
                'encrypted' => 0
            ];
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
        $api = $this->getApi($row->meta->user, $row->meta->key, $row->meta->sandbox == 'true');
        $domains = new NamesiloDomains($api);

        // Manually renew the domain
        $renew = isset($vars['renew']) ? (int) $vars['renew'] : 0;
        if ($renew > 0 && $vars['use_module'] == 'true') {
            $this->renewService($package, $service, $parent_package, $parent_service, $renew);
            unset($vars['renew']);
        }

        // Handle whois privacy via config option
        $id_protection = $this->featureServiceEnabled('id_protection', $service);
        if (!$id_protection && isset($vars['configoptions']['id_protection'])) {
            $response = $domains->addPrivacy(['domain' => $this->getServiceDomain($service)]);
            $this->processResponse($api, $response);
        } elseif ($id_protection && !isset($vars['configoptions']['id_protection'])) {
            $response = $domains->removePrivacy(['domain' => $this->getServiceDomain($service)]);
            $this->processResponse($api, $response);
        }

        return null; // All this handled by admin/client tabs instead
    }

    /**
     * Cancels the service on the remote server. Sets Input errors on failure,
     * preventing the service from being canceled.
     */
    public function cancelService($package, $service, $parent_package = null, $parent_service = null)
    {
        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
        $api = $this->getApi($row->meta->user, $row->meta->key, $row->meta->sandbox == 'true');

        if ($package->meta->type == 'domain') {
            $fields = $this->serviceFieldsToObject($service->fields);

            $domains = new NamesiloDomains($api);
            $response = $domains->setAutoRenewal($fields->{'domain'}, false);
            $this->processResponse($api, $response);

            if ($this->Input->errors()) {
                return;
            }
        }

        return;
    }

    /**
     * Suspends the service on the remote server. Sets Input errors on failure,
     * preventing the service from being suspended.
     */
    public function suspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
        $api = $this->getApi($row->meta->user, $row->meta->key, $row->meta->sandbox == 'true');

        if ($package->meta->type == 'domain') {
            $fields = $this->serviceFieldsToObject($service->fields);

            // Make sure auto renew is off
            $domains = new NamesiloDomains($api);
            $response = $domains->setAutoRenewal($fields->{'domain'}, false);
            $this->processResponse($api, $response);

            if ($this->Input->errors()) {
                return;
            }
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
        $api = $this->getApi($row->meta->user, $row->meta->key, $row->meta->sandbox == 'true');

        // Renew domain renewDomain?version=1&type=xml&key=12345&domain=namesilo.com&years=2
        if ($package->meta->type == 'domain') {
            $fields = $this->serviceFieldsToObject($service->fields);

            $vars = [
                'domain' => $fields->{'domain'},
                'years' => 1
            ];

            if (!empty($row->meta->payment_id)) {
                $vars['payment_id'] = $row->meta->payment_id;
            }

            if (!$years) {
                foreach ($package->pricing as $pricing) {
                    if ($pricing->id == $service->pricing_id) {
                        $vars['years'] = $pricing->term;
                        break;
                    }
                }
            } else {
                $vars['years'] = $years;
            }

            $domains = new NamesiloDomains($api);
            $response = $domains->renew($vars);
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
     * Returns the rendered view of the manage module page
     *
     * @param mixed $module A stdClass object representing the module and its rows
     * @param array $vars An array of post data submitted to or on the manage module page
     *  (used to repopulate fields after an error)
     * @return string HTML content containing information to display when viewing the manager module page
     */
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

        #
        #
        # TODO: add tab to check status of all transfers: check if possible with Namesilo...
        # ref: NamesiloDomainsTransfer->getList()
        #
        #
        $link_buttons = [];
        foreach ($module->rows as $row) {
            if (isset($row->meta->key)) {
                $link_buttons = [
                    [
                        'name' => Language::_('Namesilo.manage.audit_domains', true),
                        'attributes' => [
                            'href' => $this->base_uri . 'settings/company/modules/addrow/' . $module->id .
                                '?action=audit_domains'
                        ]
                    ]
                ];
                break;
            }
        }

        $this->view->set('module', $module);
        $this->view->set('link_buttons', $link_buttons);
        $this->view->set('module', $module);

        return $this->view->fetch();
    }

    /**
     * Returns the rendered view of the add module row page
     *
     * @param array $vars An array of post data submitted to or on the add module row page
     *  (used to repopulate fields after an error)
     * @return string HTML content containing information to display when viewing the add module row page
     */
    public function manageAddRow(array &$vars)
    {
        $action = isset($_GET['action']) ? $_GET['action'] : null;

        // Load the view into this object, so helpers can be automatically added to the view
        $this->view = new View((!empty($action) ? $action : 'add_row'), 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView(self::$defaultModuleView);

        // Load the helpers and models required for this view
        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);
        Loader::loadModels($this, ['Services', 'ModuleManager', 'Clients', 'ClientGroups']);

        if ($action == 'audit_domains') {
            $vars = [];
            $module_row = $this->getRow();

            $api = $this->getApi(
                $module_row->meta->user,
                $module_row->meta->key,
                $module_row->meta->sandbox == 'true',
                null,
                true
            );
            $domains = new NamesiloDomains($api);

            if ($module_row->meta->portfolio) {
                $vars['portfolio'] = $module_row->meta->portfolio;
            }

            $response = $domains->getList($vars)->response();
            $domain_list = (isset($response->domains->domain) ? $response->domains->domain : null);

            $vars['domains'] = [];

            if (!empty($domain_list)) {
                if (!is_array($domain_list)) {
                    $domain_list = [$domain_list];
                }

                foreach ($domain_list as $domain) {
                    $record = $this->Record->select()->from('services')->leftJoin(
                        'service_fields',
                        'services.id',
                        '=',
                        'service_fields.service_id',
                        false
                    )->where('services.status', 'IN', ['active', 'suspended'])->where(
                        'service_fields.value',
                        '=',
                        $domain
                    )->where('services.module_row_id', '=', $module_row->id)->where(
                        'service_fields.key',
                        '=',
                        'domain'
                    )->numResults();

                    if (!$record) {
                        $vars['domains'][] = $domain;
                    }
                }
            }

            // Set view
            $this->view->set('vars', (object) $vars);

            return $this->view->fetch();
        } elseif ($action == 'get_renew_info') {
            $service_id = isset($_GET['service_id']) ? $_GET['service_id'] : null;
            if (is_null($service_id)) {
                // exit() to prevent any output other than json from being rendered
                exit();
            }

            // Load the API
            $module_row = $this->getRow();
            $api = $this->getApi(
                $module_row->meta->user,
                $module_row->meta->key,
                $module_row->meta->sandbox == 'true',
                null,
                true
            );

            // Get the domain renewal details for the service
            $domains = new NamesiloDomains($api);
            $vars = $this->getRenewInfo($service_id, $domains);

            exit(json_encode($vars));
        } else {
            // Set unspecified checkboxes
            if (empty($vars['sandbox'])) {
                $vars['sandbox'] = 'false';
            }

            // Set view
            $this->view->set('vars', (object) $vars);

            return $this->view->fetch();
        }
    }

    /**
     * Returns the rendered view of the edit module row page
     *
     * @param stdClass $module_row The stdClass representation of the existing module row
     * @param array $vars An array of post data submitted to or on the edit module row page
     *  (used to repopulate fields after an error)
     * @return string HTML content containing information to display when viewing the edit module row page
     */
    public function manageEditRow($module_row, array &$vars)
    {
        // Load the view into this object, so helpers can be automatically added to the view
        $this->view = new View('edit_row', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView(self::$defaultModuleView);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);

        if (empty($vars)) {
            $vars = (array) $module_row->meta;
        }

        $this->view->set('vars', (object) $vars);

        return $this->view->fetch();
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
        $meta_fields = ['user', 'key', 'sandbox', 'portfolio', 'payment_id', 'namesilo_module'];
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
        $meta_fields = ['user', 'key', 'sandbox', 'portfolio', 'payment_id', 'namesilo_module'];
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
     * Returns all fields used when adding/editing a package, including any
     * javascript to execute when the page is rendered with these fields.
     *
     * @param $vars stdClass A stdClass object representing a set of post fields
     * @return ModuleFields A ModuleFields object, containg the fields to render as well as any additional
     *  HTML markup to include
     */
    public function getPackageFields($vars = null)
    {
        Loader::loadHelpers($this, ['Html']);

        // Fetch all packages available for the given server or server group
        $module_row = null;
        if (isset($vars->module_group) && $vars->module_group == '') {
            if (isset($vars->module_row) && $vars->module_row > 0) {
                $module_row = $this->getModuleRow($vars->module_row);
            } else {
                $rows = $this->getModuleRows();
                if (isset($rows[0])) {
                    $module_row = $rows[0];
                }
                unset($rows);
            }
        } else {
            // Fetch the 1st server from the list of servers in the selected group
            $rows = $this->getModuleRows(isset($vars->module_group) ? $vars->module_group : null);
            if (isset($rows[0])) {
                $module_row = $rows[0];
            }
            unset($rows);
        }

        $fields = new ModuleFields();

        $types = [
            'domain' => Language::_('Namesilo.package_fields.type_domain', true),
        ];

        // Set type of package
        $type = $fields->label(
            Language::_('Namesilo.package_fields.type', true),
            'namesilo_type'
        );
        $type->attach(
            $fields->fieldSelect(
                'meta[type]',
                $types,
                (isset($vars->meta['type']) ? $vars->meta['type'] : null),
                ['id' => 'namesilo_type']
            )
        );
        $fields->setField($type);

        // Set all TLD checkboxes
        $tld_options = $fields->label(Language::_('Namesilo.package_fields.tld_options', true));

        $tlds = $this->getTlds();
        sort($tlds);

        foreach ($tlds as $tld) {
            $tld_label = $fields->label($tld, 'tld_' . $tld);
            $tld_options->attach(
                $fields->fieldCheckbox(
                    'meta[tlds][]',
                    $tld,
                    (isset($vars->meta['tlds']) && in_array($tld, $vars->meta['tlds'])),
                    ['id' => 'tld_' . $tld],
                    $tld_label
                )
            );
        }
        $fields->setField($tld_options);

        $epp_code_label = $fields->label(Language::_('Namesilo.package_fields.epp_code', true));
        $epp_code_label->attach(
            $fields->fieldCheckbox(
                'meta[epp_code]',
                '1',
                $vars->meta['epp_code'] ?? '0' == '1',
                ['id' => 'epp_code'],
                $fields->label(Language::_('Namesilo.package_fields.enable_epp_code', true), 'epp_code')
            )
        );
        $fields->setField($epp_code_label);

        // Set nameservers
        for ($i = 1; $i <= 5; $i++) {
            $type = $fields->label(Language::_('Namesilo.package_fields.ns' . $i, true), 'namesilo_ns' . $i);
            $type->attach(
                $fields->fieldText(
                    'meta[ns][]',
                    (isset($vars->meta['ns'][$i - 1]) ? $vars->meta['ns'][$i - 1] : null),
                    ['id' => 'namesilo_ns' . $i]
                )
            );
            $fields->setField($type);
        }

        $fields->setHtml(
            "
            <script type=\"text/javascript\">
                $(document).ready(function() {
                    toggleTldOptions($('#namesilo_type').val());

                    // Re-fetch module options
                    $('#namesilo_type').change(function() {
                        toggleTldOptions($(this).val());
                    });

                    function toggleTldOptions(type) {
                        if (type == 'ssl')
                            $('.namesilo_tlds').hide();
                        else
                            $('.namesilo_tlds').show();
                    }
                });
            </script>
        "
        );

        return $fields;
    }

    /**
     * Returns all fields to display to an admin attempting to add a service with the module
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param $vars stdClass A stdClass object representing a set of post fields
     * @return ModuleFields A ModuleFields object, containg the fields to render as well as any additional
     *  HTML markup to include
     */
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
                return $this->arrayToModuleFields(Configure::get('Namesilo.transfer_fields'), null, $vars);
            } else {
                // Handle domain registration
                #
                # TODO: Select TLD, then display additional fields
                #

                $fields = Configure::get('Namesilo.transfer_fields');

                foreach ($package->meta->tlds as $tld) {
                    if ($tld == '.ca') {
                        $fields = array_merge(
                            $fields,
                            (array) Configure::get('Namesilo.domain_fields' . $tld)
                        );

                        // .ca domains can't have traditional whois privacy
                        if ($tld == '.ca') {
                            unset($fields['private']);
                        }
                    }
                }

                $fields['transfer'] = [
                    'label' => Language::_('Namesilo.domain.DomainAction', true),
                    'type' => 'radio',
                    'value' => '1',
                    'options' => [
                        '0' => 'Register',
                        '1' => 'Transfer',
                    ],
                ];

                $fields['auth'] = [
                    'label' => Language::_('Namesilo.transfer.EPPCode', true),
                    'type' => 'text',
                ];

                $module_fields = $this->arrayToModuleFields(
                    array_merge($fields, Configure::get('Namesilo.nameserver_fields')),
                    null,
                    $vars
                );

                $module_fields->setHtml(
                    "
                    <script type=\"text/javascript\">
                        $(document).ready(function() {
                            $('#transfer_id_0').prop('checked', true);
                            $('#auth_id').closest('li').hide();
                            // Set whether to show or hide the ACL option
                            $('#auth').closest('li').hide();
                            if ($('input[name=\"transfer\"]:checked').val() == '1') {
                                $('#auth_id').closest('li').show();
                            }

                            $('input[name=\"transfer\"]').change(function() {
                                if ($('input[name=\"transfer\"]:checked').val() == '1') {
                                    $('#auth_id').closest('li').show();
                                    $('#ns1_id').closest('li').hide();
                                    $('#ns2_id').closest('li').hide();
                                    $('#ns3_id').closest('li').hide();
                                    $('#ns4_id').closest('li').hide();
                                    $('#ns5_id').closest('li').hide();
                                } else {
                                    $('#auth_id').closest('li').hide();
                                    $('#ns1_id').closest('li').show();
                                    $('#ns2_id').closest('li').show();
                                    $('#ns3_id').closest('li').show();
                                    $('#ns4_id').closest('li').show();
                                    $('#ns5_id').closest('li').show();
                                }
                            });

                            $('input[name=\"transfer\"]').change();
                        });
                    </script>"
                );

                // Build the domain fields
                $fields = $this->buildDomainModuleFields($vars);
                if ($fields) {
                    $module_fields = $fields;
                }
            }
        }

        return (isset($module_fields) ? $module_fields : new ModuleFields());
    }

    /**
     * Returns all fields to display to a client attempting to add a service with the module
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param $vars stdClass A stdClass object representing a set of post fields
     * @return ModuleFields A ModuleFields object, containg the fields to render as well as any additional
     *  HTML markup to include
     */
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

            if (isset($vars->domain)) {
                $tld = $this->getTld($vars->domain);
            }

            // Handle transfer request
            if ((isset($vars->transfer) && $vars->transfer) || isset($vars->auth)) {
                $fields = array_merge(
                    Configure::get('Namesilo.transfer_fields'),
                    (array) Configure::get('Namesilo.domain_fields' . $tld)
                );

                // .ca domains can't have traditional whois privacy
                if ($tld == '.ca') {
                    unset($fields['private']);
                }

                // We should already have the domain name don't make editable
                $fields['domain']['type'] = 'hidden';
                $fields['domain']['label'] = null;
                // we already know we're doing a transfer, don't make it editable
                $fields['transfer']['type'] = 'hidden';
                $fields['transfer']['label'] = null;

                $module_fields = $this->arrayToModuleFields($fields, null, $vars);

                return $module_fields;
            } else {
                // Handle domain registration
                $fields = array_merge(
                    Configure::get('Namesilo.nameserver_fields'),
                    Configure::get('Namesilo.domain_fields'),
                    (array) Configure::get('Namesilo.domain_fields' . $tld)
                );

                // .ca domains can't have traditional whois privacy
                if ($tld == '.ca') {
                    unset($fields['private']);
                }

                // We should already have the domain name don't make editable
                $fields['domain']['type'] = 'hidden';
                $fields['domain']['label'] = null;

                $module_fields = $this->arrayToModuleFields($fields, null, $vars);
            }
        }

        // Determine whether this is an AJAX request
        return (isset($module_fields) ? $module_fields : new ModuleFields());
    }

    /**
     * Builds and returns the module fields for domain registration
     *
     * @param stdClass $vars An stdClass object representing the input vars
     * @param $client True if rendering the client view, or false for the admin (optional, default false)
     * return mixed The module fields for this service, or false if none could be created
     */
    private function buildDomainModuleFields($vars, $client = false)
    {
        if (isset($vars->domain)) {
            $tld = $this->getTld($vars->domain);

            $extension_fields = Configure::get('Namesilo.domain_fields' . $tld);
            if ($extension_fields) {
                // Set the fields
                $fields = array_merge(Configure::get('Namesilo.domain_fields'), $extension_fields);

                if (!isset($vars->transfer) || $vars->transfer == '0') {
                    $fields = array_merge($fields, Configure::get('Namesilo.nameserver_fields'));
                } else {
                    $fields = array_merge($fields, Configure::get('Namesilo.transfer_fields'));
                }

                if ($client) {
                    // We should already have the domain name don't make editable
                    $fields['domain']['type'] = 'hidden';
                    $fields['domain']['label'] = null;
                }

                // Build the module fields
                $module_fields = new ModuleFields();

                // Allow AJAX requests
                $ajax = $module_fields->fieldHidden('allow_ajax', 'true', ['id' => 'namesilo_allow_ajax']);
                $module_fields->setField($ajax);
                $please_select = ['' => Language::_('AppController.select.please', true)];

                foreach ($fields as $key => $field) {
                    // Build the field
                    $label = $module_fields->label((isset($field['label']) ? $field['label'] : ''), $key);

                    $type = null;
                    if ($field['type'] == 'text') {
                        $type = $module_fields->fieldText(
                            $key,
                            (isset($vars->{$key}) ? $vars->{$key} :
                                (isset($field['options']) ? $field['options'] : '')),
                            ['id' => $key]
                        );
                    } elseif ($field['type'] == 'select') {
                        $type = $module_fields->fieldSelect(
                            $key,
                            (isset($field['options']) ? $please_select + $field['options'] : $please_select),
                            (isset($vars->{$key}) ? $vars->{$key} : ''),
                            ['id' => $key]
                        );
                    } elseif ($field['type'] == 'checkbox') {
                        $type = $module_fields->fieldCheckbox($key, (isset($field['options']) ? $field['options'] : 1));
                        $label = $module_fields->label((isset($field['label']) ? $field['label'] : ''), $key);
                    } elseif ($field['type'] == 'hidden') {
                        $type = $module_fields->fieldHidden(
                            $key,
                            (isset($vars->{$key}) ? $vars->{$key} :
                                (isset($field['options']) ? $field['options'] : '')),
                            ['id' => $key]
                        );
                    }

                    // Include a tooltip if set
                    if (!empty($field['tooltip'])) {
                        $label->attach($module_fields->tooltip($field['tooltip']));
                    }

                    if ($type) {
                        $label->attach($type);
                        $module_fields->setField($label);
                    }
                }
            }
        }

        return (isset($module_fields) ? $module_fields : false);
    }

    /**
     * Returns all fields to display to an admin attempting to edit a service with the module
     *
     * @param stdClass $package A stdClass object representing the selected package
     * @param $vars stdClass A stdClass object representing a set of post fields
     * @return ModuleFields A ModuleFields object, containg the fields to render as well as any additional
     *  HTML markup to include
     */
    public function getAdminEditFields($package, $vars = null)
    {
        Loader::loadHelpers($this, ['Html']);

        $fields = new ModuleFields();

        // Create domain label
        $domain = $fields->label(Language::_('Namesilo.manage.manual_renewal', true), 'renew');
        // Create domain field and attach to domain label
        $domain->attach(
            $fields->fieldSelect(
                'renew',
                [0, '1 year', '2 years', '3 years', '4 years', '5 years'],
                (isset($vars->renew) ? $vars->renew : null),
                ['id' => 'renew']
            )
        );
        // Set the label as a field
        $fields->setField($domain);

        return $fields;
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

    /**
     * Returns all tabs to display to an admin when managing a service
     *
     * @param stdClass $service A stdClass object representing the service
     * @return array An array of tabs in the format of method => title.
     *  Example: ['methodName' => "Title", 'methodName2' => "Title2"]
     */
    public function getAdminServiceTabs($service)
    {
        Loader::loadModels($this, ['Packages']);

        $package = $this->Packages->get($service->package_id ?? $service->package->id);

        if ($package->meta->type == 'domain') {
            $tabs = [
                'tabWhois' => Language::_('Namesilo.tab_whois.title', true),
                'tabManageContacts' => Language::_('Namesilo.tab_manage_contacts.title', true),
                'tabEmailForwarding' => Language::_('Namesilo.tab_email_forwarding.title', true),
                'tabNameservers' => Language::_('Namesilo.tab_nameservers.title', true),
                'tabHosts' => Language::_('Namesilo.tab_hosts.title', true),
                'tabDnssec' => Language::_('Namesilo.tab_dnssec.title', true),
                'tabDnsRecords' => Language::_('Namesilo.tab_dnsrecord.title', true),
                'tabSettings' => Language::_('Namesilo.tab_settings.title', true),
                'tabAdminActions' => Language::_('Namesilo.tab_adminactions.title', true),
            ];

            // Check if DNS Management is enabled
            if (!$this->featureServiceEnabled('dns_management', $service)) {
                unset($tabs['tabDnssec'], $tabs['tabDnsRecords']);
            }

            // Check if Email Forwarding is enabled
            if (!$this->featureServiceEnabled('email_forwarding', $service)) {
                unset($tabs['tabEmailForwarding']);
            }

            return $tabs;
        }
    }

    /**
     * Returns all tabs to display to a client when managing a service.
     *
     * @param stdClass $service A stdClass object representing the service
     * @return array An array of tabs in the format of method => title, or method => array where array contains:
     *
     *  - name (required) The name of the link
     *  - icon (optional) use to display a custom icon
     *  - href (optional) use to link to a different URL
     *      Example:
     *      ['methodName' => "Title", 'methodName2' => "Title2"]
     *      ['methodName' => ['name' => "Title", 'icon' => "icon"]]
     */
    public function getClientServiceTabs($service)
    {
        Loader::loadModels($this, ['Packages']);

        $package = $this->Packages->get($service->package_id ?? $service->package->id);

        if ($package->meta->type == 'domain') {
            $tabs = [
                'tabClientWhois' => [
                    'name' => Language::_('Namesilo.tab_whois.title', true),
                    'icon' => 'fas fa-users'
                ],
                'tabClientManageContacts' => [
                    'name' => Language::_('Namesilo.tab_manage_contacts.title', true),
                    'icon' => 'fas fa-users'
                ],
                'tabClientEmailForwarding' => [
                    'name' => Language::_('Namesilo.tab_email_forwarding.title', true),
                    'icon' => 'fas fa-envelope'
                ],
                'tabClientNameservers' => [
                    'name' => Language::_('Namesilo.tab_nameservers.title', true),
                    'icon' => 'fas fa-server'
                ],
                'tabClientHosts' => [
                    'name' => Language::_('Namesilo.tab_hosts.title', true),
                    'icon' => 'fas fa-hdd'
                ],
                'tabClientDnssec' => [
                    'name' => Language::_('Namesilo.tab_dnssec.title', true),
                    'icon' => 'fas fa-globe-americas'
                ],
                'tabClientDnsRecords' => [
                    'name' => Language::_('Namesilo.tab_dnsrecord.title', true),
                    'icon' => 'fas fa-sitemap'
                ],
                'tabClientSettings' => [
                    'name' => Language::_('Namesilo.tab_settings.title', true),
                    'icon' => 'fas fa-cog'
                ]
            ];

            // Check if DNS Management is enabled
            if (!$this->featureServiceEnabled('dns_management', $service)) {
                unset($tabs['tabClientDnssec'], $tabs['tabClientDnsRecords']);
            }

            // Check if Email Forwarding is enabled
            if (!$this->featureServiceEnabled('email_forwarding', $service)) {
                unset($tabs['tabClientEmailForwarding']);
            }

            return $tabs;
        }
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
        foreach ($service->options as $option) {
            if ($option->option_name == $feature) {
                return true;
            }
        }

        return false;
    }

    /**
     * Admin Whois tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabWhois($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageWhois('tab_whois', $package, $service, $get, $post, $files);
    }

    /**
     * Client Whois tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabClientWhois($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageWhois('tab_client_whois', $package, $service, $get, $post, $files);
    }

    /**
     * Admin Manage Contacts tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabManageContacts($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageManageContacts('tab_manage_contacts', $package, $service, $get, $post, $files);
    }

    /**
     * Client Manage Contacts tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabClientManageContacts($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageManageContacts('tab_client_manage_contacts', $package, $service, $get, $post, $files);
    }

    /**
     * Admin Nameservers tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabNameservers($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageNameservers('tab_nameservers', $package, $service, $get, $post, $files);
    }

    /**
     * Admin Hosts tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabHosts($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageHosts('tab_hosts', $package, $service, $get, $post, $files);
    }

    /**
     * Admin DNSSEC tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabDnssec($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageDnssec('tab_dnssec', $package, $service, $get, $post, $files);
    }

    /**
     * Admin DNS Records tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabDnsRecords($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageDnsRecords('tab_dnsrecords', $package, $service, $get, $post, $files);
    }

    /**
     * Admin Email Forwarding tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabEmailForwarding($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageEmailForwarding('tab_email_forwarding', $package, $service, $get, $post, $files);
    }

    /**
     * Client Email Forwarding tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabClientEmailForwarding($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageEmailForwarding('tab_client_email_forwarding', $package, $service, $get, $post, $files);
    }

    /**
     * Client Nameservers tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabClientNameservers($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageNameservers('tab_client_nameservers', $package, $service, $get, $post, $files);
    }

    /**
     * Client Hosts tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabClientHosts($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageHosts('tab_client_hosts', $package, $service, $get, $post, $files);
    }

    /**
     * Client Dnssec tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabClientDnssec($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageDnssec('tab_client_dnssec', $package, $service, $get, $post, $files);
    }

    /**
     * Client DNS Records tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabClientDnsRecords($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageDnsRecords('tab_client_dnsrecords', $package, $service, $get, $post, $files);
    }

    /**
     * Admin Settings tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabSettings($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageSettings('tab_settings', $package, $service, $get, $post, $files);
    }

    /**
     * Client Settings tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabClientSettings($package, $service, array $get = null, array $post = null, array $files = null)
    {
        if (!isset($this->Clients)) {
            Loader::loadModels($this, ['Clients']);
        }
        foreach ($this->Clients->getCustomFieldValues($service->{'client_id'}) as $key => $value) {
            if ($value->{'name'} == 'Disable Domain Transfers' && $value->{'value'} == 'Yes') {
                $this->view = new View('whois_disabled', 'default');
                $this->view->setDefaultView(self::$defaultModuleView);

                return $this->view->fetch();
            }
        }

        return $this->manageSettings('tab_client_settings', $package, $service, $get, $post, $files);
    }

    /**
     * Admin Actions tab
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    public function tabAdminActions($package, $service, array $get = null, array $post = null, array $files = null)
    {
        $vars = new stdClass();

        Loader::load(__DIR__ . DS . 'includes' . DS . 'communication.php');

        $communication = new Communication($service);

        $vars->options = $communication->getNotices();

        if (!empty($post)) {
            $fields = $this->serviceFieldsToObject($service->fields);
            $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
            $api = $this->getApi($row->meta->user, $row->meta->key, $row->meta->sandbox == 'true');
            $domains = new NamesiloDomains($api);

            if (!empty($post['notice'])) {
                $communication->send($post);
            }

            if (isset($post['action']) && $post['action'] == 'resendAdminEmail') {
                $domain_transfer_info = new NamesiloDomainsTransfer($api);
                $admin_email_vars['domain'] = $fields->domain;
                $transfer_info_response = $domain_transfer_info->resendAdminEmail($admin_email_vars);
                $this->processResponse($api, $transfer_info_response);
            }

            if (!empty($post['eppCode'])) {
                $domains_transfer = new NamesiloDomainsTransfer($api);
                $epp_vars['domain'] = $fields->domain;
                $epp_vars['auth'] = $post['eppCode'];

                $transfer_info = $domains_transfer->updateEpp($epp_vars);
                $this->processResponse($api, $transfer_info);
            }

            if (isset($post['action']) && $post['action'] == 'sync_date') {
                Loader::loadModels($this, ['Services']);

                $domain_info = $domains->getDomainInfo(['domain' => $fields->domain]);
                $this->processResponse($api, $domain_info);

                if (!$this->Input->errors()) {
                    $domain_info = $domain_info->response();
                    $expires = $domain_info->expires;
                    $edit_vars['date_renews'] = date('Y-m-d h:i:s', strtotime($expires));
                    $this->Services->edit($service->id, $edit_vars, $bypass_module = true);
                }
            }
        }

        $this->view = new View('tab_admin_actions', 'default');

        Loader::loadHelpers($this, ['Form', 'Html']);

        $this->view->set('vars', $vars);
        $this->view->setDefaultView(self::$defaultModuleView);

        return $this->view->fetch();
    }

    /**
     * Handle updating whois information
     *
     * @param string $view The view to use
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    private function manageWhois($view, $package, $service, array $get = null, array $post = null, array $files = null)
    {
        // if the domain is pending transfer display a notice of such
        $checkDomainStatus = $this->checkDomainStatus($service, $package);
        if (isset($checkDomainStatus)) {
            return $checkDomainStatus;
        }

        if (!isset($this->ModuleClientMeta)) {
            Loader::loadModels($this, ['ModuleClientMeta']);
        }
        // Load the API command
        $domains = $this->loadApiCommand('Domains', $service->module_row_id ?? $package->module_row);

        // Initialize variables
        $vars = new stdClass();
        $this->view = new View($view, 'default');
        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);
        $sections = ['registrant', 'administrative', 'technical', 'billing'];
        $fields = $this->serviceFieldsToObject($service->fields);

        // Get the current contact IDs
        if (!($contact_ids = $this->getContactsByDomain($domains, $fields->domain))) {
            return false;
        }

        if (!empty($post)) {
            $post['domain'] = $fields->domain;
            $domains->setContacts($post);

            $vars = (object) $post;
        } else {
            $vars = (object) $contact_ids;
        }

        $contacts = [];
        $module = $this->getModule();
        $contact_meta = $this->ModuleClientMeta->get($service->client_id, 'contacts', $module->id, $service->module_row_id);
        if ($contact_meta) {
            foreach(json_decode($contact_meta->value, true) ?? [] as $contact_id => $contact_name) {
                $contacts[$contact_id] = $contact_name . "-" . $contact_id;
            }
        }

        $this->view->set('vars', $vars);
        $this->view->set('contact_ids', $contacts);
        $this->view->set('sections', $sections);
        $this->view->setDefaultView(self::$defaultModuleView);

        return $this->view->fetch();
    }

    /**
     * Gets all the contact IDs for the given domain
     *
     * @param NamesiloDomains $domains_command The API command object to use for the request
     * @param string $domain The domain for which to fetch contacts
     * @return boolean
     */
    private function getContactsByDomain($domains_command, $domain)
    {
        $domainInfo = $domains_command->getDomainInfo(['domain' => $domain]);
        if ((self::$codes[$domainInfo->status()][1] ?? 'fail') == 'fail') {
            $this->processResponse($this->api, $domainInfo);

            return false;
        }

        return $domainInfo->response(true)['contact_ids'];
    }

    /**
     * Handle managing contact information
     *
     * @param string $view The view to use
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    private function manageManageContacts($view, $package, $service, array $get = null, array $post = null, array $files = null)
    {
        // if the domain is pending transfer display a notice of such
        $checkDomainStatus = $this->checkDomainStatus($service, $package);
        if (isset($checkDomainStatus)) {
            return $checkDomainStatus;
        }

        if (($get['action'] ?? '') == 'manage') {
            if (array_key_exists('contact_id', $get)) {
                return $this->handleContactEdit($view, $package, $service, $get, $post);
            } else {
                return $this->handleContactAdd($view, $package, $service, $get, $post);
            }
        }

        if (($get['action'] ?? '') == 'delete') {
            $this->handleContactDelete($package, $service, $get, $post);
        }
        return $this->handleContactList($view, $package, $service, $get, $post);
    }

    /**
     * Handle updating contact information
     *
     * @param string $view The view to use
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @return string The string representing the contents of this tab
     */
    private function handleContactEdit($view, $package, $service, array $get = null, array $post)
    {
        if (!isset($this->ModuleClientMeta)) {
            Loader::loadModels($this, ['ModuleClientMeta']);
        }
        $contacts = [];
        $module = $this->getModule();
        $contact_meta = $this->ModuleClientMeta->get($service->client_id, 'contacts', $module->id, $service->module_row_id);
        if ($contact_meta) {
            $contacts = json_decode($contact_meta->value, true);
        }

        // Make sure a user only edits their own contact
        if (!array_key_exists($post['contact_id'] ?? $get['contact_id'], $contacts)) {
            return $this->handleContactList($view, $package, $service, $get, $post);
        }

        // Load the API command
        $domains = $this->loadApiCommand('Domains', $service->module_row_id ?? $package->module_row);

        // Initialize variables
        $vars = new stdClass();
        $whois_fields = Configure::get('Namesilo.whois_fields');
        $contact_id = $post['contact_id'] ?? $get['contact_id'];
        $this->view = new View($view == 'tab_manage_contacts' ? 'tab_edit_contact' : 'tab_client_edit_contact', 'default');
        $this->view->base_uri = $this->base_uri;
        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        $response = $domains->getContacts(['contact_id' => $contact_id]);
        if ((self::$codes[$response->status()][1] ?? 'fail') == 'fail') {
            return false;
        }

        if (!empty($post)) {
            $response = $domains->updateContacts($post);
            $this->processResponse($this->api, $response);
            if ((self::$codes[$response->status()][1] ?? 'fail') != 'fail') {
                $contacts[$contact_id] = $post['fn'] . ' ' . $post['ln'];
                $this->ModuleClientMeta->set(
                    $service->client_id,
                    $module->id,
                    $service->module_row_id,
                    [['key' => 'contacts', 'value' => json_encode($contacts)]]
                );
            }

            $vars = (object) $post;
        } else {
            $vars = $this->formatContact($response->response()->contact, $whois_fields);
        }

        $all_fields = [];
        foreach ($whois_fields as $value) {
            $key = $value['rp'];
            $all_fields[$key] = $value;
        }

        $this->view->set('vars', $vars);
        $this->view->set('contact_id', $contact_id);
        $this->view->set('service', $service);
        $this->view->set('fields', $this->arrayToModuleFields($all_fields, null, $vars)->getFields());
        $this->view->setDefaultView(self::$defaultModuleView);

        return $this->view->fetch();
    }

    /**
     * Handle deleting contact information
     *
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @return string The string representing the contents of this tab
     */
    private function handleContactDelete($package, $service, array $get = null, array $post)
    {
        if (!isset($this->ModuleClientMeta)) {
            Loader::loadModels($this, ['ModuleClientMeta']);
        }
        $contacts = [];
        $module = $this->getModule();
        $contact_meta = $this->ModuleClientMeta->get($service->client_id, 'contacts', $module->id, $service->module_row_id);
        if ($contact_meta) {
            $contacts = json_decode($contact_meta->value, true);
        }

        // Make sure a user only edits their own contact
        if (!array_key_exists($post['contact_id'] ?? $get['contact_id'], $contacts)) {
            return;
        }

        // Load the API command
        $domains = $this->loadApiCommand('Domains', $service->module_row_id ?? $package->module_row);
        $contact_id = $post['contact_id'] ?? $get['contact_id'];
        $response = $domains->getContacts(['contact_id' => $contact_id]);
        if ((self::$codes[$response->status()][1] ?? 'fail') == 'fail') {
            return false;
        }

        $response = $domains->deleteContacts(['contact_id' => $contact_id]);
        $this->processResponse($this->api, $response);
        if ((self::$codes[$response->status()][1] ?? 'fail') != 'fail') {
            unset($contacts[$contact_id]);
            $this->ModuleClientMeta->set(
                $service->client_id,
                $module->id,
                $service->module_row_id,
                [['key' => 'contacts', 'value' => json_encode($contacts)]]
            );

            $this->setMessage(
                'success',
                Language::_(
                    'Namesilo.!success.contact_deleted',
                    true
                )
            );
        }
    }

    /**
     * Handle updating contact information
     *
     * @param string $view The view to use
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @return string The string representing the contents of this tab
     */
    private function handleContactAdd($view, $package, $service, array $get = null, array $post)
    {
        if (!isset($this->ModuleClientMeta)) {
            Loader::loadModels($this, ['ModuleClientMeta']);
        }
        $contacts = [];
        $module = $this->getModule();
        $contact_meta = $this->ModuleClientMeta->get($service->client_id, 'contacts', $module->id, $service->module_row_id);
        if ($contact_meta) {
            $contacts = json_decode($contact_meta->value, true);
        }

        // Load the API command
        $domains = $this->loadApiCommand('Domains', $service->module_row_id ?? $package->module_row);

        // Initialize variables
        $vars = new stdClass();
        $whois_fields = Configure::get('Namesilo.whois_fields');
        $this->view = new View($view == 'tab_manage_contacts' ? 'tab_edit_contact' : 'tab_client_edit_contact', 'default');
        $this->view->base_uri = $this->base_uri;
        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        if (!empty($post)) {
            $response = $domains->addContacts($post);
            $this->processResponse($this->api, $response);
            if ((self::$codes[$response->status()][1] ?? 'fail') != 'fail') {
                $contacts[$response->response()->contact_id] = $post['fn'] . ' ' . $post['ln'];
                $this->ModuleClientMeta->set(
                    $service->client_id,
                    $module->id,
                    $service->module_row_id,
                    [['key' => 'contacts', 'value' => json_encode($contacts)]]
                );

                return $this->handleContactList($view, $package, $service, $get);
            }

            $vars = (object) $post;
        }

        $all_fields = [];
        foreach ($whois_fields as $value) {
            $key = $value['rp'];
            $all_fields[$key] = $value;
        }

        $this->view->set('vars', $vars);
        $this->view->set('fields', $this->arrayToModuleFields($all_fields, null, $vars)->getFields());
        $this->view->setDefaultView(self::$defaultModuleView);

        return $this->view->fetch();
    }

    private function formatContact($contact, $whois_fields)
    {
        $vars = ['contact_id' => $contact->contact_id];
        foreach ($contact as $contact_field => $value){
            if (!array_key_exists($contact_field, $whois_fields) || !is_string($value)) {
                continue;
            }

            $vars[$whois_fields[$contact_field]['rp']] = $value;
        }

        return (object) $vars;
    }

    /**
     * Handle listing contact information
     *
     * @param string $view The view to use
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @return string The string representing the contents of this tab
     */
    private function handleContactList($view, $package, $service, array $get = null, array $post = []) {

        if (!isset($this->ModuleClientMeta)) {
            Loader::loadModels($this, ['ModuleClientMeta']);
        }

        if (!isset($this->Session)) {
            Loader::loadModels($this, ['Session']);
        }

        // Load the API command
        $domains = $this->loadApiCommand('Domains', $service->module_row_id ?? $package->module_row);
        $module = $this->getModule();

        // Fetch current client
        $client_id = $service->client_id;
        if ($this->Session->read('blesta_client_id')) {
            $client_id = (int) $this->Session->read('blesta_client_id');
        }

        // Initialize variables
        $vars = new stdClass();
        $this->view = new View($view, 'default');
        $this->view->base_uri = $this->base_uri;

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        if (!empty($post)) {
            if (isset($post['submit']) && $post['default_contact_id']) {
                $this->ModuleClientMeta->set(
                    $service->client_id,
                    $module->id,
                    $service->module_row_id,
                    [['key' => 'default_contact_id', 'value' => $post['default_contact_id']]]
                );
            } elseif (isset($post['pull_contacts'])) {
                $module_row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
                $this->synchronizeContactsForModuleRow($module_row, null, $client_id);
            }

            $vars = (object) $post;
        } else {
            $default_meta = $this->ModuleClientMeta->get(
                $service->client_id,
                'default_contact_id',
                $module->id,
                $service->module_row_id
            );

            $vars = (object) ['default_contact_id' => $default_meta->value ?? null];
        }

        $contacts = [];
        $contact_meta = $this->ModuleClientMeta->get($service->client_id, 'contacts', $module->id, $service->module_row_id);
        if ($contact_meta) {
            $contacts = json_decode($contact_meta->value, true);
        }

        $this->view->set('vars', $vars);
        $this->view->set('contacts', $contacts);
        $this->view->set('service', $service);
        $this->view->setDefaultView(self::$defaultModuleView);

        return $this->view->fetch();
    }

    /**
     * Manage domain email forwarders
     *
     * @param string $view The name of the view to fetch
     * @param stdClass $package An stdClass object representing the package
     * @param stdClass $service An stdClass object representing the service
     * @param array $get Any GET arguments (optional)
     * @param array $post Any POST arguments (optional)
     * @param array $files Any FILES data (optional)
     * @return string The rendered view
     */
    private function manageEmailForwarding(
        $view,
        $package,
        $service,
        array $get = null,
        array $post = null,
        array $files = null
    )
    {
        $vars = new stdClass();

        // if the domain is pending transfer display a notice of such
        $checkDomainStatus = $this->checkDomainStatus($service, $package);
        if (isset($checkDomainStatus)) {
            return $checkDomainStatus;
        }

        $this->view = new View($view, 'default');

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
        $api = $this->getApi($row->meta->user, $row->meta->key, $row->meta->sandbox == 'true');
        $email_forwarding = new NamesiloEmailForwarding($api);

        $fields = $this->serviceFieldsToObject($service->fields);

        if (!empty($post)) {
            // Delete email forwarder
            if (!empty($post['delete_email'])) {
                $args = [
                    'domain' => $fields->domain,
                    'email' => explode('@', $post['delete_email'])[0] ?? ''
                ];
                $response = $email_forwarding->deleteEmailForward($args);
                $this->processResponse($api, $response);
            } else {
                // Add a new forwarder
                if (!empty($post['new_email'])) {
                    $post['emails'][$post['new_email'] . '@' . $fields->domain] = [$post['new_email_to']];
                }

                // Add or update email forwarders
                foreach ($post['emails'] ?? [] as $email => $forwarders) {
                    $args_forwarders = [];
                    $i = 1;
                    foreach ($forwarders as $forwarder) {
                        if (!empty($forwarder)) {
                            $args_forwarders['forward' . $i] = $forwarder;
                            $i++;
                        }
                    }

                    $args = array_merge([
                        'domain' => $fields->domain,
                        'email' => $email
                    ], $args_forwarders);
                    $response = $email_forwarding->configureEmailForward($args);
                    $this->processResponse($api, $response);
                }
            }

            $vars = (object) $post;
        }

        // Get email forwarders
        $response = $email_forwarding->listEmailForwards(['domain' => $fields->domain])->response();
        if (isset($response->addresses)) {
            if (!is_array($response->addresses)) {
                $response->addresses = [$response->addresses];
            }

            $vars->addresses = [];
            foreach ($response->addresses as $address) {
                if (isset($address->forwards_to) && !is_array($address->forwards_to)) {
                    $address->forwards_to = [$address->forwards_to];
                }

                $vars->addresses[] = $address;
            }
        }

        $this->view->set('vars', $vars);
        $this->view->set('domain', $fields->domain);
        $this->view->setDefaultView(self::$defaultModuleView);

        return $this->view->fetch();
    }

    /**
     * Handle updating nameserver information
     *
     * @param string $view The name of the view to fetch
     * @param stdClass $package An stdClass object representing the package
     * @param stdClass $service An stdClass object representing the service
     * @param array $get Any GET arguments (optional)
     * @param array $post Any POST arguments (optional)
     * @param array $files Any FILES data (optional)
     * @return string The rendered view
     */
    private function manageNameservers(
        $view,
        $package,
        $service,
        array $get = null,
        array $post = null,
        array $files = null
    )
    {
        $vars = new stdClass();

        // if the domain is pending transfer display a notice of such
        $checkDomainStatus = $this->checkDomainStatus($service, $package);
        if (isset($checkDomainStatus)) {
            return $checkDomainStatus;
        }

        $this->view = new View($view, 'default');
        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
        $api = $this->getApi($row->meta->user, $row->meta->key, $row->meta->sandbox == 'true');
        $dns = new NamesiloDomainsDns($api);

        $fields = $this->serviceFieldsToObject($service->fields);

        $tld = $this->getTld($fields->domain, $row);
        $sld = substr($fields->domain, 0, -strlen($tld));

        if (!empty($post)) {
            $args = [];
            $i = 1;
            foreach ($post['ns'] as $ns) {
                $args['ns' . $i] = $ns;
                $i++;
            }

            $args['domain'] = $fields->domain;

            $response = $dns->setCustom($args);
            $this->processResponse($api, $response);

            $vars = (object) $post;
        } else {
            $response = $dns->getList(['domain' => $fields->domain])->response();

            if (isset($response->nameservers)) {
                $vars->ns = [];
                foreach ($response->nameservers->nameserver as $ns) {
                    $vars->ns[] = $ns;
                }
            }
        }

        $this->view->set('vars', $vars);
        $this->view->setDefaultView(self::$defaultModuleView);

        return $this->view->fetch();
    }

    /**
     * Since the api only returns XML sometimes the return array/object changes based on the XML
     *
     * @param stdClass $package An stdClass object representing the package
     * @param stdClass $service An stdClass object representing the service
     */
    private function getRegisteredHosts($package, $service)
    {
        $fields = $this->serviceFieldsToObject($service->fields);

        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
        $api = $this->getApi($row->meta->user, $row->meta->key, $row->meta->sandbox == 'true');
        $ns = new NamesiloDomainsNs($api);

        $response = $ns->getInfo(['domain' => $fields->domain])->response();
        $host_obj = new stdClass();
        $hosts = [];

        // lets get our data in a consistent format
        if (isset($response->hosts->host) && isset($response->hosts->ip)) {
            if (!is_array($response->hosts->ip)) {
                $ips[] = $response->hosts->ip;
            } else {
                $ips = $response->hosts->ip;
            }
            $host_obj->host = $response->hosts->host;
            $host_obj->ip = $ips;
            $hosts[0] = $host_obj;

            return $hosts;
        }

        if (isset($response->hosts)) {
            foreach ($response->hosts as $host) {
                if (!is_array($host->ip)) {
                    $ips[] = $host->ip;
                } else {
                    $ips = $host->ip;
                }
                $host_obj->host = $host->host;
                $host_obj->ip = $ips;
                $hosts[] = $host_obj;
                $host_obj = new stdClass();
                $ips = null;
            }
        }

        return $hosts;
    }

    /**
     * Handle updating host information
     *
     * @param string $view The name of the view to fetch
     * @param stdClass $package An stdClass object representing the package
     * @param stdClass $service An stdClass object representing the service
     * @param array $get Any GET arguments (optional)
     * @param array $post Any POST arguments (optional)
     * @param array $files Any FILES data (optional)
     * @return string The rendered view
     */
    private function manageHosts($view, $package, $service, array $get = null, array $post = null, array $files = null)
    {
        $vars = new stdClass();

        // if the domain is pending transfer display a notice of such
        $checkDomainStatus = $this->checkDomainStatus($service, $package);
        if (isset($checkDomainStatus)) {
            return $checkDomainStatus;
        }

        $this->view = new View($view, 'default');
        $this->view->base_uri = $this->base_uri;
        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
        $api = $this->getApi($row->meta->user, $row->meta->key, $row->meta->sandbox == 'true');
        $ns = new NamesiloDomainsNs($api);

        $fields = $this->serviceFieldsToObject($service->fields);
        $this->view->set('domain', $fields->domain);

        if (!empty($post)) {
            foreach ($post['hosts'] ?? [] as $host => $ips) {
                $ips_arr = [];
                foreach ($ips as $key => $ip) {
                    if ($ip) {
                        $ips_arr['ip' . ($key + 1)] = $ip;
                    }
                }

                // if all of the ips are blanked, lets remove the host
                if (!$ips_arr) {
                    $response = $ns->delete(['domain' => $fields->domain, 'current_host' => $host]);
                    $this->processResponse($api, $response);
                } else {
                    $args = array_merge(
                        ['domain' => $fields->domain, 'current_host' => $host, 'new_host' => $host],
                        $ips_arr
                    );
                    $response = $ns->update($args);
                    $this->processResponse($api, $response);
                }
            }

            if (!empty($post['new_host']) && !empty($post['new_host_ip'])) {
                $response = $ns->create(
                    ['domain' => $fields->domain, 'new_host' => $post['new_host'], 'ip1' => $post['new_host_ip']]
                );
                $this->processResponse($api, $response);
            }

            $vars = (object) $post;
        }

        $vars->hosts = $this->getRegisteredHosts($package, $service);
        $this->view->set('vars', $vars);
        $this->view->set('client_id', $service->client_id);
        $this->view->set('service_id', $service->id);
        $this->view->setDefaultView(self::$defaultModuleView);

        return $this->view->fetch();
    }

    /**
     * Handle updating host information
     *
     * @param string $view The name of the view to fetch
     * @param stdClass $package An stdClass object representing the package
     * @param stdClass $service An stdClass object representing the service
     * @param array $get Any GET arguments (optional)
     * @param array $post Any POST arguments (optional)
     * @param array $files Any FILES data (optional)
     * @return string The rendered view
     */
    private function manageDnssec($view, $package, $service, array $get = null, array $post = null, array $files = null)
    {
        $vars = new stdClass();

        // if the domain is pending transfer display a notice of such
        $checkDomainStatus = $this->checkDomainStatus($service, $package);
        if (isset($checkDomainStatus)) {
            return $checkDomainStatus;
        }

        $this->view = new View($view, 'default');
        $this->view->base_uri = $this->base_uri;
        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
        $api = $this->getApi($row->meta->user, $row->meta->key, $row->meta->sandbox == 'true');
        $dns = new NamesiloDomainsDns($api);

        $fields = $this->serviceFieldsToObject($service->fields);
        $this->view->set('domain', $fields->domain);

        if (!empty($post)) {
            if (isset($post['action'])) {
                if ($post['action'] == 'addDnssec') {
                    $response = $dns->dnsSecAddRecord(
                        [
                            'domain' => $fields->domain,
                            'digest' => $post['digest'],
                            'keyTag' => $post['key_tag'],
                            'digestType' => $post['digest_type'],
                            'alg' => $post['algorithm'],
                        ]
                    );
                    $this->processResponse($api, $response);
                } elseif ($post['action'] == 'deleteDnssec') {
                    $response = $dns->dnsSecDeleteRecord(
                        [
                            'domain' => $fields->domain,
                            'digest' => $post['digest'],
                            'keyTag' => $post['key_tag'],
                            'digestType' => $post['digest_type'],
                            'alg' => $post['algorithm'],
                        ]
                    );
                    $this->processResponse($api, $response);
                }
            }
        }

        $ds = $dns->dnsSecListRecords(['domain' => $fields->domain])->response();

        // get a consistent format because xml parsing in php is inconsistent
        if (isset($ds->ds_record) && !is_array($ds->ds_record)) {
            $ds->ds_record = [$ds->ds_record];
        } else {
            $ds->ds_record = $ds->ds_record;
        }

        $vars->selects = Configure::get('Namesilo.dnssec');
        $vars->records = $ds->ds_record;
        $this->view->set('vars', $vars);
        $this->view->set('client_id', $service->client_id);
        $this->view->set('service_id', $service->id);
        $this->view->setDefaultView(self::$defaultModuleView);

        return $this->view->fetch();
    }

    /**
     * Handle updating DNS Record information
     *
     * @param string $view The name of the view to fetch
     * @param stdClass $package An stdClass object representing the package
     * @param stdClass $service An stdClass object representing the service
     * @param array $get Any GET arguments (optional)
     * @param array $post Any POST arguments (optional)
     * @param array $files Any FILES data (optional)
     * @return string The rendered view
     */
    private function manageDnsRecords(
        $view,
        $package,
        $service,
        array $get = null,
        array $post = null,
        array $files = null
    )
    {
        $vars = new stdClass();

        // if the domain is pending transfer display a notice of such
        $checkDomainStatus = $this->checkDomainStatus($service, $package);
        if (isset($checkDomainStatus)) {
            return $checkDomainStatus;
        }

        $this->view = new View($view, 'default');
        $this->view->base_uri = $this->base_uri;

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
        $api = $this->getApi($row->meta->user, $row->meta->key, $row->meta->sandbox == 'true');
        $dns = new NamesiloDomainsDns($api);

        $fields = $this->serviceFieldsToObject($service->fields);
        $this->view->set('domain', $fields->domain);

        if (!empty($post)) {
            if (isset($post['action'])) {
                if ($post['action'] == 'addDnsRecord') {
                    $dns_fields = $this->getDnsFields($post, $fields);
                    $response = $dns->dnsAddRecord($dns_fields);
                    $this->processResponse($api, $response);
                } elseif ($post['action'] == 'updateDnsRecord') {
                    $dns_fields = $this->getDnsFields($post, $fields);
                    $response = $dns->dnsUpdateRecord($dns_fields);
                    $this->processResponse($api, $response);
                } elseif ($post['action'] == 'deleteDnsRecord') {
                    $response = $dns->dnsDeleteRecord(
                        [
                            'domain' => $fields->domain,
                            'rrid' => $post['record_id'],
                        ]
                    );
                    $this->processResponse($api, $response);
                }
            }
        }

        $records = $dns->dnsListRecords(['domain' => $fields->domain])->response(true);

        // Get a consistent format because XML parsing in PHP is inconsistent
        if (isset($records['resource_record']) && !is_array($records['resource_record'])) {
            $records['resource_record'] = (array) $records['resource_record'];
        } elseif (!isset($records['resource_record'])) {
            $records['resource_record'] = [];
        }

        // We are expecting a multidimensional array
        if ($this->isMultiArray($records['resource_record']) === false) {
            $records['resource_record'] = [0 => $records['resource_record']];
        }

        $vars->selects = Configure::get('Namesilo.dns_records');
        $vars->records = $records['resource_record'];

        $this->view->set('vars', $vars);
        $this->view->set('client_id', $service->client_id);
        $this->view->set('service_id', $service->id);

        $this->view->setDefaultView(self::$defaultModuleView);

        return $this->view->fetch();
    }

    private function getDnsFields($post, $fields) {
        $dns_fields = [
            'domain' => $fields->domain,
            'rrtype' => $post['record_type'],
            'rrhost' => $post['host'],
            'rrvalue' => $post['value'],
            'rrttl' => $post['ttl'],
        ];
        if (isset($post['record_id']) && !empty($post['record_id'])) {
            $dns_fields['rrid'] = $post['record_id'];
        }
        if (isset($post['distance']) && !empty($post['distance']) && $post['record_type'] == 'MX') {
            $dns_fields['rrdistance'] = $post['distance'];
        }

        return $dns_fields;
    }

    /**
     * Handle updating settings
     *
     * @param string $view The view to use
     * @param stdClass $package A stdClass object representing the current package
     * @param stdClass $service A stdClass object representing the current service
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters
     * @param array $files Any FILES parameters
     * @return string The string representing the contents of this tab
     */
    private function manageSettings(
        $view,
        $package,
        $service,
        array $get = null,
        array $post = null,
        array $files = null
    )
    {
        $vars = new stdClass();

        // if the domain is pending transfer display a notice of such
        $checkDomainStatus = $this->checkDomainStatus($service, $package);
        if (isset($checkDomainStatus)) {
            return $checkDomainStatus;
        }

        $this->view = new View($view, 'default');
        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
        $api = $this->getApi($row->meta->user, $row->meta->key, $row->meta->sandbox == 'true');
        $domains = new NamesiloDomains($api);
        $transfer = new NamesiloDomainsTransfer($api);

        // Determine if this service has access to epp_code
        $epp_code = $package->meta->epp_code ?? '0';

        $fields = $this->serviceFieldsToObject($service->fields);

        if (!empty($post)) {
            if (isset($post['resend_verification_email'])) {
                $response = $domains->emailVerification(['email' => $post['resend_verification_email']]);
                $this->processResponse($api, $response);
            } else {
                if ($epp_code && isset($post['registrar_lock'])) {
                    $LockAction = $post['registrar_lock'] == 'Yes' ? 'Lock' : 'Unlock';
                    $response = $domains->setRegistrarLock($LockAction, ['domain' => $fields->domain]);
                    $this->processResponse($api, $response);
                }

                if ($epp_code && isset($post['request_epp'])) {
                    $response = $transfer->getEpp(['domain' => $fields->domain]);
                    $this->processResponse($api, $response);
                    unset($post['request_epp']);
                    $this->setMessage(
                        'success',
                        Language::_(
                            'Namesilo.!success.epp_code_sent',
                            true
                        )
                    );
                }

                $vars = (object) $post;
            }
        }

        $info = $domains->getDomainInfo(['domain' => $fields->domain]);
        $info_response = $info->response();

        if (isset($info_response->locked)) {
            $vars->registrar_lock = $info_response->locked;
        }

        if (isset($info_response->contact_ids->registrant)) {
            $registrant_id = $info_response->contact_ids->registrant;
            $registrant_info = $domains->getContacts(['contact_id' => $registrant_id]);
            $registrant_email = $registrant_info->response()->contact->email;

            $registrant_verification = $domains->registrantVerificationStatus()->response(true);
            if ($registrant_verification) {
                if (!is_array($registrant_verification['email'])) {
                    $registrant_verification['email'] = [$registrant_verification['email']];
                }
                foreach ($registrant_verification['email'] as $key => $registrant) {
                    if (isset($registrant['email_address']) && $registrant['email_address'] == $registrant_email) {
                        $vars->registrant_verification_info = $registrant;
                    }
                }
            }
        }

        $this->view->set('epp_code', $epp_code);
        $this->view->set('vars', $vars);
        $this->view->setDefaultView(self::$defaultModuleView);

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
        $api = $this->getApi($row->meta->user, $row->meta->key, $row->meta->sandbox == 'true');

        $domains = new NamesiloDomains($api);
        $result = $domains->check(['domains' => $domain]);
        $this->processResponse($api, $result);

        if ((self::$codes[$result->status()][1] ?? 'fail') == 'fail') {
            return false;
        }

        $responseXML = $result->responseXML();
        $xpath_result = $responseXML->xpath("//available/domain[text()='" . $domain . "']");

        if (empty($xpath_result)) {
            // The domain was not in the available element, its not available.
            return false;
        }

        $attributes = $xpath_result[0]->attributes();
        if (isset($attributes->premium) && $attributes->premium == "1") {
            $this->Input->setErrors(
                ['availability' => ['premium' => Language::_('Namesilo.!error.premium_domain', true, $domain)]]
            );

            return false;
        }

        return true;
    }

    /**
     * Verifies if a domain of the provided TLD can be registered or transfer by the provided term
     *
     * @param string $tld The TLD to verify
     * @param int $term The term in which the domain name will be registered or transferred (in years)
     * @param bool $transfer True if the domains is going to be transferred, false otherwise (optional)
     * @return bool True if the term is valid for the current TLD
     */
    public function isValidTerm($tld, $term, $transfer = false)
    {
        if ($term > 10 || ($transfer && $term > 1)) {
            return false;
        }

        return true;
    }

    /**
     * Gets the domain registration date
     *
     * @param stdClass $service The service belonging to the domain to lookup
     * @param string $format The format to return the registration date in
     * @return string The domain registration date in UTC time in the given format
     * @see Services::get()
     */
    public function getRegistrationDate($service, $format = 'Y-m-d H:i:s')
    {
        Loader::loadHelpers($this, ['Date']);

        $domain = $this->getServiceDomain($service);
        $module_row_id = $service->module_row_id ?? null;

        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->user, $row->meta->key, $row->meta->sandbox == 'true');

        $domains = new NamesiloDomains($api);
        $result = $domains->getDomainInfo(['domain' => $domain]);
        $this->processResponse($api, $result);

        if ((self::$codes[$result->status()][1] ?? 'fail') == 'fail') {
            return false;
        }

        $response = $result->response();

        return isset($response->created)
            ? $this->Date->format(
                $format,
                $response->created
            )
            : false;
    }

    /**
     * Gets the domain expiration date
     *
     * @param stdClass $service The service belonging to the domain to lookup
     * @param string $format The format to return the expiration date in
     * @return string The domain expiration date in UTC time in the given format
     * @see Services::get()
     */
    public function getExpirationDate($service, $format = 'Y-m-d H:i:s')
    {
        Loader::loadHelpers($this, ['Date']);

        $domain = $this->getServiceDomain($service);
        $module_row_id = $service->module_row_id ?? null;

        $row = $this->getModuleRow($module_row_id);
        $api = $this->getApi($row->meta->user, $row->meta->key, $row->meta->sandbox == 'true');

        $domains = new NamesiloDomains($api);
        $result = $domains->getDomainInfo(['domain' => $domain]);
        $this->processResponse($api, $result);

        if ((self::$codes[$result->status()][1] ?? 'fail') == 'fail') {
            return false;
        }

        $response = $result->response();

        return isset($response->expires)
            ? $this->Date->format(
                $format,
                $response->expires
            )
            : false;
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

        return $this->getServiceName($service);
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
        $row = !empty($row) ? $row : $this->getModuleRows()[0];

        // Fetch the TLDs results from the cache, if they exist
        $cache = Cache::fetchCache(
            'tlds',
            Configure::get('Blesta.company_id') . DS . 'modules' . DS . 'namesilo' . DS
        );

        if ($cache) {
            return unserialize(base64_decode($cache));
        }

        // Fetch namesilo TLDs
        $tlds = [];

        if (empty($row)) {
            return $tlds;
        }

        $this->log('getPrices', serialize(['user' => $row->meta->user]), 'input', true);

        $result = $this->getApi(
            $row->meta->user,
            $row->meta->key,
            $row->meta->sandbox == 'true'
        )->submit('getPrices');

        $this->log('getPrices', $result->raw(), 'output', !empty($result->response()));

        if (!$result->response()) {
            return [];
        }

        foreach ($result->response() as $tld => $v) {
            if (!is_object($v)) {
                continue;
            }
            $tlds[] = '.' . $tld;
        }

        // Save the TLDs results to the cache
        if (count($tlds) > 0) {
            if (Configure::get('Caching.on') && is_writable(CACHEDIR)) {
                try {
                    Cache::writeCache(
                        'tlds',
                        base64_encode(serialize($tlds)),
                        strtotime(Configure::get('Blesta.cache_length')) - time(),
                        Configure::get('Blesta.company_id') . DS . 'modules' . DS . 'namesilo' . DS
                    );
                } catch (Exception $e) {
                    // Write to cache failed, so disable caching
                    Configure::set('Caching.on', false);
                }
            }
        }

        return $tlds;
    }

    /**
     * Get a list of the TLD prices
     *
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @return array A list of all TLDs and their pricing
     *    [tld => [currency => [year# => ['register' => price, 'transfer' => price, 'renew' => price]]]]
     */
    public function getTldPricing($module_row_id = null)
    {
        return $this->getFilteredTldPricing($module_row_id);
    }

    /**
     * Get a filtered list of the TLD prices
     *
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @param array $filters A list of criteria by which to filter fetched pricings including but not limited to:
     *
     *  - tlds A list of tlds for which to fetch pricings
     *  - currencies A list of currencies for which to fetch pricings
     *  - terms A list of terms for which to fetch pricings
     * @return array A list of all TLDs and their pricing
     *    [tld => [currency => [year# => ['register' => price, 'transfer' => price, 'renew' => price]]]]
     */
    public function getFilteredTldPricing($module_row_id = null, $filters = [])
    {
        $this->setModuleRow($this->getModuleRow($module_row_id));
        $tld_prices = $this->getPrices();
        $tld_yearly_prices = [];
        foreach ($tld_prices as $tld => $currency_prices) {
            $tld_yearly_prices[$tld] = [];
            foreach ($currency_prices as $currency => $prices) {
                $tld_yearly_prices[$tld][$currency] = [];
                foreach (range(1, 10) as $years) {
                    // Filter by 'terms'
                    if (isset($filters['terms']) && !in_array($years, $filters['terms'])) {
                        continue;
                    }

                    $tld_yearly_prices[$tld][$currency][$years] = [
                        'register' => $prices->registration * $years,
                        'transfer' => $prices->transfer * $years,
                        'renew' => $prices->renew * $years
                    ];
                }
            }
        }

        return $tld_yearly_prices;
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
            'user' => [
                'valid' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Namesilo.!error.user.valid', true)
                ]
            ],
            'key' => [
                'valid' => [
                    'last' => true,
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Namesilo.!error.key.valid', true)
                ],
                'valid_connection' => [
                    'rule' => [
                        [$this, 'validateConnection'],
                        $vars['user'],
                        isset($vars['sandbox']) ? $vars['sandbox'] : 'false'
                    ],
                    'message' => Language::_('Namesilo.!error.key.valid_connection', true)
                ]
            ],
            'portfolio' => [
                'valid' => [
                    'rule' => [
                        [$this, 'validatePortfolio'],
                        $vars['key'],
                        $vars['user'],
                        isset($vars['sandbox']) ? $vars['sandbox'] : 'false'
                    ],
                    'message' => Language::_('Namesilo.!error.portfolio.valid_portfolio', true)
                ]
            ],
            'payment_id' => [
                'valid' => [
                    'rule' => ['matches', '/^[\s\d]*$/'],
                    'message' => Language::_('Namesilo.!error.payment_id.valid_format', true)
                ]
            ]
        ];
    }

    /**
     * Validates that the given connection details are correct by attempting to check the availability of a domain
     *
     * @param string $key The API key
     * @param string $user The API user
     * @param string $sandbox "true" if this is a sandbox account, false otherwise
     * @return bool True if the connection details are valid, false otherwise
     */
    public function validateConnection($key, $user, $sandbox)
    {
        $api = $this->getApi($user, $key, $sandbox == 'true');
        $domains = new NamesiloDomains($api);
        $response = $domains->check(['domains' => 'example.com']);
        $this->processResponse($api, $response);

        return true;
    }

    /**
     * Validates the portfolio is valid
     *
     * @param string $portfolio The portfolio name
     * @param string $key The API key
     * @param string $user The API user
     * @param string $sandbox 'true' or 'false', whether to use the sandbox API
     * @return bool True if the portfolio is valid, or false otherwise
     */
    public function validatePortfolio($portfolio, $key, $user, $sandbox)
    {
        $api = $this->getApi($user, $key, $sandbox == 'true');
        $domains = new NamesiloDomains($api);
        $response = $domains->portfolioList();
        $this->processResponse($api, $response);
        $response = $response->response();

        if ($response && isset($response->portfolios->name) && !is_array($response->portfolios->name)) {
            $response->portfolios->name = [$response->portfolios->name];
        }

        if (isset($response->portfolios->name)) {
            if (!in_array($portfolio, $response->portfolios->name) && $portfolio) {
                return false;
            }
        }

        return true;
    }

    /**
     * Loads the given API command class
     *
     * @param string $command The name of the command to load
     * @param int $module_row_id The ID of the module row which provides credentials for initializing the API
     * @param bool $force_new True to force a new instance of the API, false by default
     * @return mixed The API command object
     */
    private function loadApiCommand($command, $module_row_id, $force_new = false)
    {
        $full_command_class = 'Namesilo' . $command;

        if (!$this->api || $force_new) {
            $row = $this->getModuleRow($module_row_id);
            $this->getApi($row->meta->user, $row->meta->key, $row->meta->sandbox == 'true');
        }

        return new $full_command_class($this->api);
    }

    /**
     * Initializes the NamesiloApi and returns an instance of that object
     *
     * @param string $user The user to connect as
     * @param string $key The key to use when connecting
     * @param bool $sandbox Whether or not to process in sandbox mode (for testing)
     * @param string $username The username to execute an API command using
     * @param bool $batch use API batch mode
     * @return NamesiloApi The NamesiloApi instance
     */
    public function getApi($user = null, $key = null, $sandbox = true, $username = null, $batch = false)
    {
        Loader::load(__DIR__ . DS . 'apis' . DS . 'namesilo_api.php');

        if (empty($user) || empty($key)) {
            if (($row = $this->getModuleRow())) {
                $user = $row->meta->user;
                $key = $row->meta->key;
                $sandbox = $row->meta->sandbox;
            }
        }

        $this->api = new NamesiloApi($user, $key, $sandbox, $username, $batch);
        return $this->api;
    }

    /**
     * Process API response, setting an errors, and logging the request
     *
     * @param NamesiloApi $api The Namesilo API object
     * @param NamesiloResponse $response The Namesilo API response object
     */
    private function processResponse(NamesiloApi $api, NamesiloResponse $response)
    {
        $this->logRequest($api, $response);

        $status = $response->status();

        // Set errors if non-200 http code
        if ($api->httpcode != 200) {
            $this->Input->setErrors(['errors' => ['API returned non-200 HTTP code']]);
        }

        // Set errors, if any
        if ((self::$codes[$status][1] ?? 'fail') == 'fail') {
            $errors = $response->errors() ? $response->errors() : [];
            $this->Input->setErrors(['errors' => (array) $errors]);
        }
    }

    /**
     * Logs the API request
     *
     * @param NamesiloApi $api The Namesilo API object
     * @param NamesiloResponse $response The Namesilo API response object
     */
    private function logRequest(NamesiloApi $api, NamesiloResponse $response)
    {
        $last_request = $api->lastRequest();
        $url = substr($last_request['url'], 0, strpos($last_request['url'], '?')) . ' (' . $api->getUser() . ')';

        $this->log($url, serialize($last_request['args']), 'input', true);
        $this->log(
            $url,
            serialize($response->response()),
            'output',
            (self::$codes[$response->status()][1] ?? 'fail') == 'success'
        );
    }

    /**
     * Returns the TLD of the given domain
     *
     * @param string $domain The domain to return the TLD from
     * @param stdClass module row object
     * @return string The TLD of the domain
     */
    private function getTld($domain, $row = null)
    {
        if ($row == null) {
            $row = $this->getRow();
        }

        if ($row == null) {
            $row = $this->getRow();
        }

        $tlds = $this->getTlds();
        $domain = strtolower($domain);

        foreach ($tlds as $tld) {
            if (substr($domain, -strlen($tld)) == $tld) {
                return $tld;
            }
        }

        return strstr($domain, '.');
    }

    /**
     * Formats a phone number into +NNN.NNNNNNNNNN
     *
     * @param string $number The phone number
     * @param string $country The ISO 3166-1 alpha2 country code
     * @return string The number in +NNN.NNNNNNNNNN
     */
    private function formatPhone($number, $country)
    {
        if (!isset($this->Contacts)) {
            Loader::loadModels($this, ['Contacts']);
        }

        $phone = $this->Contacts->intlNumber($number, $country, '.');
        $phone_parts = explode('.', $phone, 2);
        $formatted_phone = preg_replace('/[^0-9]+/', '', $phone_parts[1] ?? $phone);

        if (in_array($country, ['US', 'CA'])) {
            $formatted_phone = substr($formatted_phone, -10);
        }

        return $formatted_phone;
    }

    /**
     * Retrieves the domain status view
     *
     * @param stdClass $service An stdClass object representing the service
     * @param stdClass $package An stdClass object representing the package
     * @return null|string The domain status view if available, otherwise void
     */
    private function checkDomainStatus($service, $package)
    {
        $fields = $this->serviceFieldsToObject($service->fields);
        $row = $this->getModuleRow($service->module_row_id ?? $package->module_row);
        $api = $this->getApi($row->meta->user, $row->meta->key, $row->meta->sandbox == 'true');
        $domains = new NamesiloDomains($api);
        $domain_info = $domains->getDomainInfo(['domain' => $fields->domain])->response();

        if (isset($domain_info->code) && $domain_info->code != 300) {
            $transfer = new NamesiloDomainsTransfer($api);
            $transfer_info = $transfer->getStatus(['domain' => $fields->domain])->response();

            if (isset($transfer_info->code) && $transfer_info->code == 300) {
                $this->view = new View('transferstatus', 'default');
                $this->view->setDefaultView(self::$defaultModuleView);
                $this->view->set('transferstatus', $transfer_info);
                Loader::loadHelpers($this, ['Form', 'Html']);

                return $this->view->fetch();
            }
        }
    }

    /**
     * Retrieves renew data information
     *
     * @param int $service_id The ID of the service
     * @param NamesiloApi $api_object An instance of the API
     * @return array An array of key/value pairs representing the renew data information
     */
    private function getRenewInfo($service_id, $api_object)
    {
        $vars = [];

        $service = $this->Services->get($service_id);
        $api_response = $api_object->getDomainInfo(
            [
                'domain' => $service->name
            ]
        )->response();

        if ($api_response->code != 300) {
            $vars = [
                'domain' => $service->name,
                'error' => [
                    'code' => $api_response->code,
                    'detail' => $api_response->detail
                ]
            ];

            return $vars;
        } elseif (strtotime($api_response->expires) < 946706400) {
            $vars = [
                'domain' => $service->name,
                'error' => [
                    'code' => $api_response->code,
                    'detail' => $api_response->expires . 'expires date from the API cannot possibly be valid'
                ]
            ];

            return $vars;
        }

        $date_renews = new DateTime($service->date_renews);
        $expires = new DateTime($api_response->expires);

        $client = $this->Clients->get($service->client_id);
        $suspend_days =
            $this->ClientGroups->getSetting($client->client_group_id, 'suspend_services_days_after_due')->value;

        // take into account suspension threshold and a 3 day buffer
        $target_date_obj = $expires->modify('- ' . (3 + $suspend_days) . ' days');
        $target_date = $target_date_obj->format('Y-m-d H:i:s');

        $diff = $date_renews->diff($target_date_obj)->format('%a');
        if ($diff > 0) {
            // Highlight if its greater than 90 days
            $highlight = $diff >= 90;
            $vars = [
                'service_id' => $service_id,
                'domain' => $service->name,
                'date_before' => $date_renews->format('Y-m-d H:i:s'),
                'date_after' => $target_date,
                'error' => false,
                'checked' => !$highlight,
                'highlight' => $highlight
            ];
        }

        return $vars;
    }

    /**
     * Retrieves all the Namesilo prices
     *
     * @param array $filters A list of criteria by which to filter fetched pricings including but not limited to:
     *
     *  - tlds A list of tlds for which to fetch pricings
     *  - currencies A list of currencies for which to fetch pricings
     * @return array An array containing all the TLDs with their respective prices
     */
    protected function getPrices(array $filters = [])
    {
        // Fetch the TLDs results from the cache, if they exist
        $cache = Cache::fetchCache(
            'tlds_prices',
            Configure::get('Blesta.company_id') . DS . 'modules' . DS . 'namesilo' . DS
        );

        if ($cache) {
            $result = unserialize(base64_decode($cache));
        }

        Loader::loadModels($this, ['Currencies']);

        if (!isset($result)) {
            $row = $this->getRow();
            $api = $this->getApi(
                $row->meta->user,
                $row->meta->key,
                $row->meta->sandbox == 'true'
            );
            $result = $api->submit('getPrices')->response();

            // Save the TLDs results to the cache
            if (
                Configure::get('Caching.on') && is_writable(CACHEDIR)
                && isset($result->detail) && $result->detail == 'success'
            ) {
                try {
                    Cache::writeCache(
                        'tlds_prices',
                        base64_encode(serialize($result)),
                        strtotime(Configure::get('Blesta.cache_length')) - time(),
                        Configure::get('Blesta.company_id') . DS . 'modules' . DS . 'namesilo' . DS
                    );
                } catch (Exception $e) {
                    // Write to cache failed, so disable caching
                    Configure::set('Caching.on', false);
                }
            }
        }

        $tlds = [];
        if (isset($result->detail) && $result->detail == 'success') {
            $tlds = (array) $result;
            unset($tlds['code']);
            unset($tlds['detail']);
        }

        // Get all currencies
        $currencies = $this->Currencies->getAll(Configure::get('Blesta.company_id'));

        // Convert namesilo prices to all currencies
        $pricing = [];

        foreach ($tlds as $tld => $tld_pricing) {
            $tld = '.' . trim($tld, '.');

            // Filter by 'tlds'
            if (isset($filters['tlds']) && !in_array($tld, $filters['tlds'])) {
                continue;
            }

            foreach ($currencies as $currency) {
                // Filter by 'currencies'
                if (isset($filters['currencies']) && !in_array($currency->code, $filters['currencies'])) {
                    continue;
                }

                $pricing[$tld][$currency->code] = (object) [
                    'registration' => $this->Currencies->convert(
                        is_scalar($tld_pricing->registration) ? $tld_pricing->registration : 0,
                        'USD',
                        $currency->code,
                        Configure::get('Blesta.company_id')
                    ),
                    'transfer' => $this->Currencies->convert(
                        is_scalar($tld_pricing->transfer) ? $tld_pricing->transfer : 0,
                        'USD',
                        $currency->code,
                        Configure::get('Blesta.company_id')
                    ),
                    'renew' => $this->Currencies->convert(
                        is_scalar($tld_pricing->renew) ? $tld_pricing->renew : 0,
                        'USD',
                        $currency->code,
                        Configure::get('Blesta.company_id')
                    )
                ];
            }
        }

        return $pricing;
    }

    /**
     * Retrieves all the Namesilo module rows
     *
     * @return array An array containing all the module rows
     */
    private function getRows()
    {
        Loader::loadModels($this, ['ModuleManager']);

        $module_rows = [];
        $modules = $this->ModuleManager->getInstalled();

        foreach ($modules as $module) {
            $module_data = $this->ModuleManager->get($module->id);

            foreach ($module_data->rows as $module_row) {
                if (isset($module_row->meta->namesilo_module)) {
                    $module_rows[] = $module_row;
                }
            }
        }

        return $module_rows;
    }

    /**
     * Retrieves the Namesilo module row
     *
     * @return null|stdClass An stdClass object representing the module row if found, otherwise void
     */
    private function getRow()
    {
        $module_rows = $this->getRows();

        return $module_rows[0] ?? null;
    }

    /**
     * Check if array is multidimensional
     *
     * @return bool true|false
     */
    private function isMultiArray(array $array)
    {
        rsort($array);

        return isset($array[0]) && is_array($array[0]);
    }
}
