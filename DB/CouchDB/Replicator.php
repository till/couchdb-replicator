#!/usr/bin/env php
<?php
/**
 * +-----------------------------------------------------------------------+
 * | Copyright (c) 2010, Till Klampaeckel                                  |
 * | All rights reserved.                                                  |
 * |                                                                       |
 * | Redistribution and use in source and binary forms, with or without    |
 * | modification, are permitted provided that the following conditions    |
 * | are met:                                                              |
 * |                                                                       |
 * | o Redistributions of source code must retain the above copyright      |
 * |   notice, this list of conditions and the following disclaimer.       |
 * | o Redistributions in binary form must reproduce the above copyright   |
 * |   notice, this list of conditions and the following disclaimer in the |
 * |   documentation and/or other materials provided with the distribution.|
 * | o The names of the authors may not be used to endorse or promote      |
 * |   products derived from this software without specific prior written  |
 * |   permission.                                                         |
 * |                                                                       |
 * | THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS   |
 * | "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT     |
 * | LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR |
 * | A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT  |
 * | OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, |
 * | SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT      |
 * | LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, |
 * | DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY |
 * | THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT   |
 * | (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE |
 * | OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.  |
 * |                                                                       |
 * +-----------------------------------------------------------------------+
 * | Author: Till Klampaeckel <till@php.net>                               |
 * +-----------------------------------------------------------------------+
 *
 * PHP version 5
 *
 * @category Database
 * @package  DB_CouchDB_Replicator
 * @author   Till Klampaeckel <till@php.net>
 * @license  http://www.opensource.org/licenses/bsd-license.php The BSD License
 * @version  GIT: $Id$
 * @link     http://till.klampaeckel.de/blog/
 */

/**
 * DB_CouchDB_Replicator - Helper class.
 *
 * @category Database
 * @package  DB_CouchDB_Replicator
 * @author   Till Klampaeckel <till@php.net>
 * @license  http://www.opensource.org/licenses/bsd-license.php The BSD License
 * @version  Release: @package_version@
 * @link     http://till.klampaeckel.de/blog/
 */
class DB_CouchDB_Replicator
{
    /**
     * @var HTTP_Request2 $client
     */
    protected $client;

    /**
     * @var boolean $debug More debug output?
     */
    public $debug = false;

    /**
     * @var int $perPage
     * @see self::setPageSize()
     * @see self::getUri()
     */
    protected $perPage = 1000;

    /**
     * To be done.
     *
     * @return void
     */
    public function testConnection()
    {
        // use net_url2 to get to the couchdb server and 
        // try to get the greeting
    }

    /**
     * Set the page size, which is number of documents to pull.
     *
     * @param int $page The number of documents to fetch.
     *
     * @return $this
     * @see    self::getDocuments()
     */
    public function setPageSize($page)
    {
        $this->perPage = $page;
        return $this;
    }

    /**
     * Save the documents to the server.
     *
     * @param string $server    The target server.
     * @param array  $documents The documents, stacked stdClass.
     *
     * @return void
     */
    public function saveDocuments($server, array $documents)
    {
        $i = 0;
        foreach ($documents as $document) {
            $document = $document->doc;

            $document->id = $document->_id;
            unset($document->_rev);
            unset($document->_id);

            $endpoint = $server . "/{$document->id}";

            unset($document->id);

            $status = $this->saveDocument($endpoint, $document);
            if ($status !== true) {
                $this->handleException(new Exception("Error."));
            }

            unset($this->client); // clean up

            $i++;

            if (($i%100) == 0) {
                sleep(1);
            }
        }
    }

    /**
     * Save a single document.
     *
     * We'll need a stdClass object of the $document, sans id. The document is saved
     * via request to the new server.
     *
     * @param string   $server   The server to save to, includes the DB.
     * @param stdClass $document The document object.
     *
     * @return boolean
     * @uses   self::makeRequest()
     * @uses   self::handleException()
     */
    public function saveDocument($server, $document)
    {
        $resp = $this->makeRequest($server, HTTP_Request2::METHOD_PUT, $document);
        if (!($resp instanceof HTTP_Request2_Response)) {
            sleep(20); // let's sleep a while
            return $this->saveDocument($server, $document); // recursive loop ftw! :D
        }
        if ($resp->getStatus() == 201) {
            return true;
        }
        if ($resp->getStatus() == 401) {
            // auth timeout
            return false;
        }
        if ($resp->getStatus() == 409) {
            // document already in the index (are we resuming?)
            return true;
        }
        $this->handleException(new Exception($resp->getBody(), $resp->getStatus()));
    }

    /**
     * Determine if this is a valid URI to a database resource.
     *
     * @param string $database The URI of a database.
     *
     * @return boolean
     * @uses   Validate::uri()
     */
    public function isValid($database)
    {
        static $options = array('allowed_schemes' => array('http', 'https'));
        return Validate::uri($database, $options);
    }

    /**
     * Get total number of documents on the server.
     *
     * @param string $server The CouchDB server.
     *
     * @return int
     */
    public function getTotals($server)
    {
        $uri  = $this->getUri($server, false);
        $resp = $this->makeRequest($uri);

        $data = json_decode($resp->getBody());

        return (int) $data->total_rows;
    }

    /**
     * Return the setting.
     *
     * @return int
     */
    public function getPerPage()
    {
        return $this->perPage;
    }

    /**
     * Read documents from server.
     *
     * @param string $database The URI of the database to read from.
     *
     * @return array
     * @uses   self::getUri()
     */
    public function getDocuments($database)
    {
        $uri  = $this->getUri($database);
        $resp = $this->makeRequest($uri);
        if (!($resp instanceof HTTP_Request2_Response)) {
            // probably a timeout
            while (!($resp instanceof HTTP_Request2_Response)) {
                sleep(10); // give it a rest
                $resp = $this->makeRequest($uri);
            }
        }
        $data = $this->parseResponse($resp);

        return $data;
    }

    /**
     * Parse the response received from {@link self::makeRequest()}.
     *
     * @param HTTP_Request2_Respone $response The response from the CouchDB server.
     *
     * @return mixed An arrayi if it was successful, false if the request died or
     *               auth time out.
     * @throws RuntimeException For an unhandled response. ;)
     */
    protected function parseResponse(HTTP_Request2_Response $response)
    {
        if ($response->getStatus() !== 200) {
            $msg  = "An error occured: {$response->getStatus()}.\n";
            $msg .= $response->getBody();
            $msg .= "\n\n";
            $msg .= "Stuck on current: {$GLOBALS['current']}. Please resume later.";
            if ($response->getStatus() == 0) {
                return false;
            }
            if ($response->getStatus() == 401) {
                // auth timed out, let's redo this
                return false;
            }

            throw new RuntimeException($msg);
        }
        $data = json_decode($response->getBody());
        return $data->rows;
    }

    /**
     * Make request against the URI.
     *
     * @param string  $uri    The URL.
     * @param string  $method The request method.
     * @param mixed   $data   For POST/PUT.
     * @param boolean $force  Force reconnect/new client - to be implemented.
     *
     * @return HTTP_Request2_Response
     */
    protected function makeRequest(
        $uri,
        $method = HTTP_Request2::METHOD_GET,
        $data = null,
        $force = false
    ) {
        // if ($this->client === null) {
            $this->client = new Http_Request2;
        // }
        $this->client->setUrl($uri)->setMethod($method);


        $payload = null;
        if ($data !== null && $method == HTTP_Request2::METHOD_PUT) {

            $payload = json_encode($data);

            $this->client->setHeader('Content-Type: application/json');
            $this->client->setBody($payload);
        }

        if ($this->debug === true) {
            echo "DEBUG:\n";
            echo "Current: {$GLOBALS['current']}\n";
            echo "Method: {$method}\n";
            echo "URL: {$uri}\n";
            echo "Data: {$payload}\n\n";
        }

        try {
            $resp = $this->client->send();
            return $resp;
        } catch (HTTP_Request2_Exception $e) {
            // most likely a timeout or "Malformed response."
            if (strstr($e->getMessage(), 'Malformed response')) {
                /*
                var_dump($this->client->getLastEvent(),
                    $this->client->getUrl(),
                    $this->client->getBody());
                */
                return;
            }

            var_dump("DEBUG: " . $e->getMessage());

            $this->handleException($e);

        } catch (Exception $e) {
            $this->handleException($e);
        }
    }

    /**
     * Handle the exception and try to improve the trace, etc..
     *
     * @param Exeption $e The exception to re-throw.
     *
     * @return void
     * @throws RuntimeException
     */
    protected function handleException(Exception $e)
    {
        $msg  = "An error occured: {$e->getCode()}.\n";
        $msg .= "Message: {$e->getMessage()}\n";
        $msg .= "Trace:\n";
        $msg .= $e->getTraceAsString();
        $msg .= "\n\n";
        $msg .= "Stuck on current: {$GLOBALS['current']}. Please resume later.";

        throw new RuntimeException($msg);
    }

    /**
     * Build URI for {@link self::getDocuments()}.
     * 
     * @param string  $server       The CouchDB server.
     * @param boolean $include_docs True, or false.
     *
     * @return string
     * @see    self::getDocuments()
     * @uses   $GLOBALS['current'];
     * @uses   self::$pagePage
     * @todo   Refactor this and get rid of $GLOBALS
     */
    protected function getUri($server, $include_docs = true)
    {
        if ($include_docs === true) {
            $include_docs = 'true';
        } else {
            $include_docs = 'false';
        }
        $uri  = "{$server}/_all_docs/?include_docs={$include_docs}";
        $uri .= "&limit={$this->perPage}";
        $uri .= "&skip={$GLOBALS['current']}";

        return $uri;
    }
}