<?php
/**
 * Namesilo Email Forwarding Management
 *
 * @copyright Copyright (c) 2021, Phillips Data, Inc.
 * @license http://opensource.org/licenses/mit-license.php MIT License
 * @package namesilo.commands
 */
class NamesiloEmailForwarding
{
    /**
     * @var NamesiloApi
     */
    private $api;

    /**
     * Sets the API to use for communication
     *
     * @param NamesiloApi $api The API to use for communication
     */
    public function __construct(NamesiloApi $api)
    {
        $this->api = $api;
    }

    /**
     * Returns a list of email forwarders for the particular domain.
     *
     * @param array $vars An array of input params including:
     *
     *  - domain Domain to fetch email forwarders
     * @return NamesiloResponse
     */
    public function listEmailForwards(array $vars)
    {
        return $this->api->submit('listEmailForwards', $vars);
    }

    /**
     * Add or modify an email forward
     *
     * @param array $vars An array of input params including:
     *
     *  - domain The domain related to the email address addition or modification
     *  - email The email forward to create/modify
     *  - forward1 The first email address to forward email
     *  - forward2-5 Up to 4 additional addresses to forward email
     * @return NamesiloResponse
     */
    public function configureEmailForward(array $vars)
    {
        return $this->api->submit('configureEmailForward', $vars);
    }

    /**
     * Removes an email forwarder
     *
     *  - domain The domain related to the email address to delete
     *  - email The forward to delete
     */
    public function deleteEmailForward(array $vars)
    {
        return $this->api->submit('deleteEmailForward', $vars);
    }
}
