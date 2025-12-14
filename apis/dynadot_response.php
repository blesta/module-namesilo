<?php

/**
 * Dynadot API Response
 *
 * @package blesta
 * @subpackage blesta.components.modules.dynadot.apis
 * @copyright Copyright (c) 2013, Phillips Data, Inc.
 * @license http://opensource.org/licenses/mit-license.php MIT License
 */
class DynadotResponse
{
    /**
     * @var string The raw response from the API
     */
    private $raw;

    /**
     * @var SimpleXMLElement The XML parsed response from the API
     */
    private $xml;

    /**
     * Sets the raw response
     *
     * @param string $response The raw response from the API
     */
    public function __construct($response)
    {
        $this->raw = $response;

        try {
            $this->xml = new SimpleXMLElement($this->raw);
        } catch (Exception $e) {
            // Invalid XML
        }
    }

    /**
     * Returns the status of the API request
     *
     * @return string The status of the API request
     */
    public function status()
    {
        if ($this->xml) {
            // Check for SuccessCode tag.
            // It can be in a Header tag, or directly if it's a simple response.
            // Or if wrapped in Results (like search), we might need to check the first response?

            // Standard Dynadot response: <CommandResponse><CommandHeader><SuccessCode>0</SuccessCode>...</CommandHeader>...</CommandResponse>
            // Search response: <Results><SearchResponse><SearchHeader><SuccessCode>0</SuccessCode>...</SearchHeader></SearchResponse>...</Results>

            $headers = [];

            // If root is Results, iterate children
            if ($this->xml->getName() == 'Results') {
                foreach ($this->xml->children() as $child) {
                    foreach ($child->children() as $subChild) {
                        if (strpos($subChild->getName(), 'Header') !== false) {
                            $headers[] = $subChild;
                        }
                    }
                }
            } else {
                // If root is CommandResponse
                foreach ($this->xml->children() as $child) {
                    if (strpos($child->getName(), 'Header') !== false) {
                        $headers[] = $child;
                    }
                }
            }

            // Check headers for SuccessCode
            if (!empty($headers)) {
                // If any header has failure, we might consider it a failure, or depends on context.
                // For search, one failure might not mean global failure.
                // But usually we check the first one or the general status.

                // Let's assume success if the first header is 0.
                if (isset($headers[0]->SuccessCode)) {
                    return (string)$headers[0]->SuccessCode === '0' ? 'success' : 'error';
                }

                // Some responses have ResponseCode instead of SuccessCode (e.g. BulkRegister)
                if (isset($headers[0]->ResponseCode)) {
                    return (string)$headers[0]->ResponseCode === '0' || (string)$headers[0]->ResponseCode === '200' ? 'success' : 'error';
                }
            }

            // If we couldn't find a header with code, check if it's a direct error response?
            // Usually <Error> tag exists if status is error?
        }
        return 'error';
    }

    /**
     * Returns the response data
     *
     * @return stdClass A stdClass object representing the response, null if invalid response
     */
    public function response()
    {
        if ($this->xml) {
            return $this->formatResponse($this->xml);
        }
        return null;
    }

    /**
     * Returns the raw response
     *
     * @return string The raw response
     */
    public function raw()
    {
        return $this->raw;
    }

    /**
     * Returns the raw XML response
     *
     * @return SimpleXMLElement The XML response
     */
    public function responseXML()
    {
        return $this->xml;
    }

    /**
     * Returns any errors from the response
     *
     * @return array An array of errors
     */
    public function errors()
    {
        if ($this->status() == 'error') {
            if ($this->xml) {
                // Look for Error tag in Headers
                $headers = [];
                if ($this->xml->getName() == 'Results') {
                    foreach ($this->xml->children() as $child) {
                        foreach ($child->children() as $subChild) {
                            if (strpos($subChild->getName(), 'Header') !== false) {
                                $headers[] = $subChild;
                            }
                        }
                    }
                } else {
                    foreach ($this->xml->children() as $child) {
                        if (strpos($child->getName(), 'Header') !== false) {
                            $headers[] = $child;
                        }
                    }
                }

                $errors = [];
                foreach ($headers as $header) {
                    if (isset($header->Error)) {
                        $errors[] = (string)$header->Error;
                    }
                }

                if (!empty($errors)) {
                    return $errors;
                }

                // Check for implicit error or status message
                if (isset($this->xml->Error)) {
                    return [(string)$this->xml->Error];
                }
            }
            return ['Unknown Error'];
        }
        return false;
    }

    /**
     * Formats the XML response into a stdClass object
     *
     * @param SimpleXMLElement $xml The XML response
     * @return stdClass A stdClass object representing the response
     */
    private function formatResponse($xml)
    {
        $json = json_encode($xml);
        return json_decode($json);
    }
}
