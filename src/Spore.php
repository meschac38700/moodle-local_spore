<?php

require_once 'Spyc.php';
require_once 'RESTHttpClient.php';
class Spore_Exception extends Exception {

}

class Spore {

  protected $_specs;
  protected $_client;
  protected $_methods;
  protected $_method_spec;
  protected $_format;
  protected $_host;
  protected $_base_url;
  protected $_request_path;
  protected $_request_url_path;
  protected $_request_params;
  protected $_request_cookies;
  protected $_request_raw_params;
  protected $_request_method;
  protected $_middlewares;
  protected $_httpClient = null;

  protected $_response;

  /**
   * Constructor
   *
   * @param  string $username
   * @param  string $password
   * @return void
   */
  public function __construct($spec_file = '') {
    $this->init($spec_file);
    $this->_request_params = array ();
    $this->_request_cookies = array();
    $this->_middlewares = array ();
  }

  /**
   * Initialize Spore with spec file
   *
   * @return void
   */
  public function init( $spec_file = '' ) {
    if (empty ($spec_file))
      throw new Spore_Exception('Initialization failed: spec file is not defined.');


    if ( ! is_string( $spec_file ) and is_array( $spec_file ) and array_key_exists( 'spore_route_file', $spec_file ) )
      $spec_file = $spec_file['spore_route_file'];
    elseif ( ! is_string( $spec_file ) and is_object( $spec_file ) and isset( $spec_file -> spore_route_file ) and strlen( $spec_file -> spore_route_file ) )
      $spec_file = $spec_file -> spore_route_file;
      elseif ( is_string ( $spec_file ) and (is_file ( $spec_file ) or FALSE !== parse_url( $spec_file ) ) )
         ;
    else 
      throw new Spore_Exception( "Initialization failed : the configuration file couldn't be found.");

    if ( !is_string ( $spec_file ) )
      throw new Spore_Exception('Initialization failed: Spore needs a file path to initialize.');

    // load the spec file
    $this->_load_spec($spec_file);

    if(substr($this->_specs['base_url'], -1) == "/") {
      $this->_specs['base_url']=substr($this->_specs['base_url'], 0, -1);
    }

    $this->_init_client($this->_specs['base_url']);

  }

  public function setBaseUrl($base_url) {
    $this->_base_url = $base_url;
  }

  /**
   * Enable middleware
   *
   * @param unknown_type $middleware
   * @param unknown_type $args
   */
  public function enable($middleware, $args) {
    // create middleware obj
    $m = new $middleware ($args);

    // add to middleware array
    array_push($this->_middlewares, $m);
  }

  /**
   * Load spec file
   *
   * @param   string  $spec_file
   * @return  array   $specs
   */
  protected function _load_spec( $spec_file ) {
    // load file and parse/decode
    if (preg_match("/\.(json|yaml)$/i", $spec_file, $matches)) {
      $spec_format = $matches[1];
      $specs_array = $this->_parse_spec_file($spec_file, $spec_format);

      if (!isset ($specs_array['methods']))
        throw new Spore_Exception('No method has been defined in the spec file: ' . $spec_file);

      // save the specs
      $this->_specs = $specs_array;
      
    } else {
      throw new Spore_Exception('Unsupported spec file: ' . $spec_file);
    }

  }

  protected function _parse_spec_file($spec_file, $spec_format) {
    /* if (file_exists($spec_file)) { */
      switch ($spec_format) {
        case 'json' :
          // read the spec file
          $fp = @fopen($spec_file, 'r');

          if (!$fp)
            throw new Spore_Exception('Unable to open file: ' . $spec_file);

          $specs_text = '';
          while (!feof($fp)) {
            $specs_text .= fgets($fp, 1024);
          }
          fclose($fp);

          // decode the json text
          $specs_obj = json_decode($specs_text);
          $specs_array = self::object_to_array($specs_obj);
          return $specs_array;
          break;

        case 'yaml' :
          //$specs_array = yaml_parse_file($spec_file);
          $specs_array = Spyc::YAMLLoad($spec_file);
          return $specs_array;
          break;

        default :
          throw new Spore_Exception('Unsupported spec file: ' . $spec_file);
      }
    /*
    } else {
      throw new Spore_Exception('File not found: ' . $spec_file);
    }
    */
  }

  /**
   * initialize REST Http Client
   *
   * @param   string  $spec_file
   * @return  array   $specs
   */
  protected function _init_client() {
    $base_url = $this->_specs['base_url'];
    $this->_base_url = $base_url;
    $client = RESTHttpClient :: connect($base_url);
    $client->addHeader('Accept-Charset', 'ISO-8859-1,utf-8');
    #TODO: manage exception
    $this->_client = $client;
  }

  /**
   * Method overloading
   *
   * @param  string $method
   * @param  array $params
   * @return object
   * @throws Zend_Service_Spore_Exception if unable to find method
   */
  public function __call($method, $params) {
    // check if method exists
    if ( !isset ( $this->_specs['methods'][$method] ) ) {
      throw new Spore_Exception('Invalid method "' . $method . '"');
    }

    // create the method on request / on the fly
    $this->_exec_method($method, $params);

    return $this->_response;
  }

  /**
   * Execute a client method
   *
   * @param object $method
   * @return void
   */
  protected function _exec_method($method, $params) {
    // set method spec
    $this->_setMethodSpec($this->_specs['methods'][$method]);

    // set request method
    $this->_setRequestMethod($this->_specs['methods'][$method]['method']);

    // prepare the params
    $this->_prepareParams($method, $params);

    // prepare the params
    $this->_prepareCookies();

    // execute all middlewares
    foreach ($this->_middlewares as $middleware) {
      $middleware->execute($this);
    }

    // send request
    $rest_response = null;
    switch (strtoupper($this->_request_method)) {
      case 'POST' :
        $rest_response = $this->_performSporePost($this->_request_path, $this->_request_raw_params);
        break;
      case 'PUT' :
        $rest_response = $this->_performSporePut($this->_request_path, $this->_request_raw_params);
        break;
      case 'DELETE' :
        $rest_response = $this->_performSporeDelete($this->_request_path, $this->_request_params);
        break;
      case 'GET' :
        $rest_response = $this->_performSporeGet($this->_request_path, $this->_request_params);
        break;

      default :
        $rest_response = $this->restGet($this->_request_path, $this->_request_params);
    }

    // set response
    $this->setResponse($rest_response);

    $this->_request_params = array();
  }

  protected function _setMethodSpec($spec) {
    $this->_method_spec = $spec;
  }

  protected function _setRequestMethod($request_method) {
    $this->_request_method = $request_method;
  }

  protected function _prepareParams($method, $params) {
    // get path
    $this->_request_path = $this->_base_url . $this->_specs['methods'][$method]['path'];
    $this->_request_url_path = $this->_specs['methods'][$method]['path'];

    // add required params into the path
    $required_params = array ();
    if (isset ($this->_specs['methods'][$method]['required_params'])) {
      foreach ($this->_specs['methods'][$method]['required_params'] as $param) {
        if (!isset ($params[0][$param]))
          throw new Spore_Exception('Expected parameter "' . $param . '" is not found.');

        $this->_insertParam($param, $params[0][$param]);
        array_push($required_params, $param);
      }
    }

    // add the rest of the params into the path
    if (!(empty($params)))
      foreach ($params[0] as $param => $value) {
        if (!in_array($param, $required_params)) {
          $this->_insertParam($param, $value);
        }
      }

    // format
    $this->_format = (isset ($params[0]['format'])) ? $params[0]['format'] : 'json';

    // also generate raw params from the request params array
    $this->_setRawParams($this->_request_params);
  }

  protected function _insertParam($param, $value) {
    if ($value != 0 && empty ($value))
      return;

    if (strstr($this->_request_path, ":$param")) {
      $this->_request_path = str_replace(":$param", $value, $this->_request_path);
      $this->_request_url_path = str_replace(":$param", $value, $this->_request_url_path);
    } else {
      $this->_request_params[$param] = $value;
    }

  }

  protected function _setRawParams($params = array ()) {
    $raw_params = '';
    if (isset($params) && !empty($params)) {
        foreach ($params as $key => $value) {
          $raw_params .= empty ($raw_params) ? '' : '&';
          $raw_params .= "$key=$value";
        }
    }
    $this->_request_raw_params = $raw_params;
  }

    protected function _prepareCookies() {
    $cookies = $this->_request_cookies;
    $client = RESTHttpClient :: getHttpClient();
    foreach ( $cookies as &$cookie_arrays) {
      if (!isset ($cookie_arrays["name"])) {
        throw new Spore_Exception('Expected cookie is not found.');
      } else {
        $cookie = "{$cookie_arrays['name']}={$cookie_arrays['value']};path={$cookie_arrays['path']};";
        if (!(empty($cookie_arrays['domain'])))
          $cookie += "domaine={$cookie_arrays['domain']};";
        if ($cookie_arrays['secure'])
          $cookie += "secure;";
      }
      $client->addCookie($cookie);
    }
  }

  /*
   * Use our own performPost() for PUT/POST method, since Zend_Rest_Client's restPut() always reset the
   * content-type header that we have set before.
   */
  protected function _performSporePost( $path, $data = null) {
    // set content-type
    $content_type = 'application/x-www-form-urlencoded; charset=utf-8';
    $this->_setContentType($content_type);

    $client = RESTHttpClient :: getHttpClient();
                return $client->doPost($path, $data);
  }
  protected function _performSporePut( $path, $data = null) {
    // set content-type
    $content_type = 'application/x-www-form-urlencoded; charset=utf-8';
    $this->_setContentType($content_type);

    $client = RESTHttpClient :: getHttpClient();
                return $client->doPut($path, $data);
  }
  protected function _performSporeGet($path, $data = null) {
    $content_type = 'application/x-www-form-urlencoded; charset=utf-8';
    $this->_setContentType($content_type);

    $client = RESTHttpClient :: getHttpClient();
    return $client->doGet($path, $data);
  }

  /*
   * Use our own performDelete() for DELETE method, since restDelete() doesn't have any $data parameter
   */
  protected function _performSporeDelete($path, array $data = null) {
    // set content-type
    $content_type = 'application/x-www-form-urlencoded; charset=utf-8';
    $this->_setContentType($content_type);

    $client = RESTHttpClient :: getHttpClient();
    return $client->doDelete($path, $data);
  }

  /**
   * Return the result as an object
   */
  public function setResponse($rest_response) {
    $client = RESTHttpClient :: getHttpClient();
    if (!isset($this->_response)) {
            $this->_response = new stdClass();
        }
    $this->_response->status = $client->getStatus();
    $this->_response->headers = $client->getHeaders();
    $this->_response->body = $this->_parseBody($client->getContent());

  }

  private function _parseBody($body) {
    switch (strtolower($this->_format)) {
      case 'xml' :
        return "TODO : parse xml response";
      case 'json' :
        return json_decode($body);
      case 'yml' :
      default :
        return $body;
    }
  }

  /*
   * Set the Content-Type header
   */
  private function _setContentType($content_type) {
    $client = RESTHttpClient :: getHttpClient();
    $client->createOrUpdateHeader('Content-Type', $content_type);
  }

  /**
   * Return the specification array.
   *
   * @return array  $specs
   */
  public function getSpecs() {
    return $this->_specs;
  }

  /**
   * Return available methods in the spec file.
   *
   * @return array  $methods
   */
  public function getMethods() {
    if (isset ($this->_methods))
      return $this->_methods;

    $methods = array ();
    foreach ($this->_specs['methods'] as $method => $param) {
      array_push($methods, $method);
    }
    $this->_methods = $methods;
    return $methods;
  }

  public function getFormat() {
    return $this->_format;
  }

  public function getMethodSpec() {
    return $this->_method_spec;
  }

  public function getRequestPath() {
    return $this->_request_path;
  }
  public function getRequestUrlPath() {
    return $this->_request_url_path;
  }

  public function getRequestParams() {
    return $this->_request_params;
  }

  public function getRequestMethod() {
    return $this->_request_method;
  }

  public function getMiddlewares() {
    return $this->_middlewares;
  }

  public function setCookie($name, $value, $path = "/", $domain = "", $secure = false) {
    $cookie_array = array("name" => $name,
                 "value" => $value,
                 "path" => $path,
                 "domain" => $domain,
                 "secure" => $secure);
    $this->_request_cookies[$name] = $cookie_array;
  }

        /*
         * recurcive function
         * object to array get an object and return an array
         *
         *   input:  $object (object, array, string)
         *   output: $array (string, array)
         */
        static private function object_to_array($object) {
            if (is_array($object) || is_object($object)) {
                $array = array();
                foreach ($object as $key => $value) {
                    $array[$key] = self::object_to_array($value);
                }
                return $array;
            }
            return (string)$object;
        }

}
