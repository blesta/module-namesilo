<?php

/**
 * Dynadot API
 *
 * @package blesta
 * @subpackage blesta.components.modules.dynadot.apis
 * @copyright Copyright (c) 2013, Phillips Data, Inc.
 * @license http://opensource.org/licenses/mit-license.php MIT License
 */
class DynadotApi
{
    /**
     * @var string The user to connect as
     */
    private $user;

    /**
     * @var string The key to use when connecting
     */
    private $key;

    /**
     * @var bool Whether or not to process in sandbox mode (for testing)
     */
    private $sandbox;

    /**
     * @var array An array of last request parameters
     */
    private $last_request = [];

    /**
     * @var int The HTTP status code of the last request
     */
    public $httpcode;

    /**
     * Sets the connection details
     *
     * @param string $key The key to use when connecting
     * @param bool $sandbox Whether or not to process in sandbox mode (for testing)
     */
    public function __construct($key, $sandbox = true)
    {
        $this->key = $key;
        $this->sandbox = $sandbox;
    }

    /**
     * Submits a request to the API
     *
     * @param string $command The command to submit
     * @param array $args An array of arguments to submit
     * @return DynadotResponse The response object
     */
    public function submit($command, array $args = [])
    {
        $url = 'https://api.dynadot.com/api3.xml';
        if ($this->sandbox) {
            $url = 'https://api-sandbox.dynadot.com/api3.xml';
        }

        $args['key'] = $this->key;
        $args['command'] = $command;

        $this->last_request = [
            'url' => $url,
            'args' => $args
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($args));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Security fix: Enable SSL verification
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $response = curl_exec($ch);
        $this->httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        Loader::load(dirname(__FILE__) . DS . 'dynadot_response.php');

        return new DynadotResponse($response);
    }

    /**
     * Returns the details of the last request made
     *
     * @return array An array containing:
     *  - url The URL of the last request
     *  - args The arguments passed to the last request
     */
    public function lastRequest()
    {
        return $this->last_request;
    }
}
