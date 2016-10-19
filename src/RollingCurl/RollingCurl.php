<?php
/**
 * A cURL library to fetch a large number of resources while maintaining
 * a consistent number of simultaneous connections
 *
 * @package RollingCurl
 * @version 3.1.1
 * @author Jeff Minard (http://jrm.cc/)
 * @author Josh Fraser (www.joshfraser.com)
 * @author Alexander Makarov (http://rmcreative.ru/)
 * @author Petr Suchy (xsuchy09) <suchy@wamos.cz> <http://www.wamos.cz>
 * @license Apache License 2.0
 * @link https://github.com/xsuchy09/rolling-curl
 */

namespace RollingCurl;

use Exception;
use InvalidArgumentException;
use RollingCurl\Request;

/**
 * Class that holds a rolling queue of curl requests.
 */
class RollingCurl
{

	/**
	 * Max number of simultaneous requests.
	 * 
	 * @var int
	 */
	private $simultaneousLimit = 5;

	/**
	 * Callback function to be applied to each result.
	 * 
	 * @var callable
	 */
	private $callback;

	/**
	 * Callback function to be called between result processing.
	 * 
	 * @var callable
	 */
	private $idleCallback;
	
	/**
	 * If idleCallback was called.
	 * 
	 * @var bool
	 */
	private $idleCallbackCalled = false;

	/**
	 * Set your base options that you want to be used with EVERY request. (Can be overridden individually)
	 * 
	 * @var array
	 */
	protected $options = array(
		CURLOPT_RETURNTRANSFER => 1,
		CURLOPT_FOLLOWLOCATION => 1,
		CURLOPT_MAXREDIRS => 5,
		CURLOPT_CONNECTTIMEOUT => 30,
		CURLOPT_TIMEOUT => 30,
	);

	/**
	 * Set your default multicurl options
	 * 
	 * @var array
	 */
	protected $multicurlOptions = [];

	/**
	 * @var array
	 */
	private $headers = [];

	/**
	 * Requests queued to be processed
	 * 
	 * @var Request[]
	 */
	private $pendingRequests = [];

	/**
	 * @var int
	 */
	private $pendingRequestsPosition = 0;

	/**
	 * Requests currently being processed by curl
	 * 
	 * @var Request[]
	 */
	private $activeRequests = [];

	/**
	 * All processed requests
	 * 
	 * @var Request[]
	 */
	private $completedRequests = [];

	/**
	 * A count of executed calls
	 *
	 * While you can count() on pending/active, completed may be cleared.
	 * 
	 * @var int
	 */
	private $completedRequestCount = 0;

	/**
	 * Add a request to the request queue
	 *
	 * @param Request $request
	 * @return RollingCurl
	 */
	public function add(Request $request)
	{
		$this->pendingRequests[] = $request;

		return $this;
	}

	/**
	 * Create new Request and add it to the request queue
	 *
	 * @param string $url
	 * @param string $method
	 * @param array|string $postData
	 * @param array $headers
	 * @param array $options
	 * 
	 * @return RollingCurl
	 */
	public function request($url, $method = 'GET', $postData = null, array $headers = null, array $options = null)
	{
		$newRequest = new Request($url, $method);
		if ($postData) {
			$newRequest->setPostData($postData);
		}
		if ($headers) {
			$newRequest->setHeaders($headers);
		}
		if ($options) {
			$newRequest->setOptions($options);
		}
		return $this->add($newRequest);
	}

	/**
	 * Perform GET request
	 *
	 * @param string $url
	 * @param array $headers
	 * @param array $options
	 * 
	 * @return RollingCurl
	 */
	public function get($url, array $headers = null, array $options = null)
	{
		return $this->request($url, 'GET', null, $headers, $options);
	}

	/**
	 * Perform POST request
	 *
	 * @param string $url
	 * @param array|string $postData
	 * @param array $headers
	 * @param array $options
	 * 
	 * @return RollingCurl
	 */
	public function post($url, $postData = null, array $headers = null, array $options = null)
	{
		return $this->request($url, 'POST', $postData, $headers, $options);
	}

	/**
	 * Perform PUT request
	 *
	 * @param  string      $url
	 * @param  null        $putData
	 * @param  array       $headers
	 * @param  array       $options
	 *
	 * @return RollingCurl
	 */
	public function put($url, $putData = null, array $headers = null, array $options = null)
	{
		return $this->request($url, 'PUT', $putData, $headers, $options);
	}

	/**
	 * Perform DELETE request
	 *
	 * @param  string      $url
	 * @param  array       $headers
	 * @param  array       $options
	 *
	 * @return RollingCurl
	 */
	public function delete($url, array $headers = null, array $options = null)
	{
		return $this->request($url, 'DELETE', null, $headers, $options);
	}

	/**
	 * Run all queued requests
	 *
	 * @return void
	 */
	public function execute()
	{

		$master = curl_multi_init();
		foreach ($this->multicurlOptions AS $multiOption => $multiValue) {
			curl_multi_setopt($master, $multiOption, $multiValue);
		}

		// start the first batch of requests
		$firstBatch = $this->getNextPendingRequests($this->getSimultaneousLimit());

		// what a silly "error"
		if (count($firstBatch) == 0) {
			return;
		}

		foreach ($firstBatch as $request) {
			// setup the curl request, queue it up, and put it in the active array
			$ch = curl_init();
			$options = $this->prepareRequestOptions($request);
			curl_setopt_array($ch, $options);
			curl_multi_add_handle($master, $ch);
			$request->setStart();
			$this->activeRequests[(int)$ch] = $request;
		}

		$active = null;

		// Use a shorter select timeout when there is something to do between calls
		$idleCallback = $this->idleCallback;
		$selectTimeout = $idleCallback ? 0.1 : 1.0;

		do {

			// ensure we're running
			$status = curl_multi_exec($master, $active);
			// see if there is anything to read
			while ($transfer = curl_multi_info_read($master)) {

				// get the request object back and put the curl response into it
				$key = (int)$transfer['handle'];
				$request = $this->activeRequests[$key];
				$request->setResponseText(curl_multi_getcontent($transfer['handle']))
						->setResponseErrno(curl_errno($transfer['handle']))
						->setResponseError(curl_error($transfer['handle']))
						->setResponseInfo(curl_getinfo($transfer['handle']))
						->setEnd();

				// remove the request from the list of active requests
				unset($this->activeRequests[$key]);

				// move the request to the completed set
				$this->completedRequests[] = $request;
				$this->completedRequestCount++;

				// start a new request (it's important to do this before removing the old one)
				if ($nextRequest = $this->getNextPendingRequest()) {
					// setup the curl request, queue it up, and put it in the active array
					$ch = curl_init();
					$options = $this->prepareRequestOptions($nextRequest);
					curl_setopt_array($ch, $options);
					curl_multi_add_handle($master, $ch);
					
					$nextRequest->setStart();
					$this->activeRequests[(int)$ch] = $nextRequest;
				}

				// remove the curl handle that just completed
				curl_multi_remove_handle($master, $transfer['handle']);

				// if there is a callback, run it
				if (is_callable($this->callback)) {
					$callback = $this->callback;
					$callback($request, $this);
				}
				
				// close curl and other cleaning - memory
				curl_close($transfer['handle']);
				unset($transfer['handler']);
				unset($key);

				// if something was requeued, this will get it running/update our loop check values
				$status = curl_multi_exec($master, $active);
			}

			// Error detection -- this is very, very rare
			$err = null;
			switch ($status) {
				case CURLM_BAD_EASY_HANDLE:
					$err = 'CURLM_BAD_EASY_HANDLE';
					break;
				case CURLM_OUT_OF_MEMORY:
					$err = 'CURLM_OUT_OF_MEMORY';
					break;
				case CURLM_INTERNAL_ERROR:
					$err = 'CURLM_INTERNAL_ERROR';
					break;
				case CURLM_BAD_HANDLE:
					$err = 'CURLM_BAD_HANDLE';
					break;
			}
			if ($err) {
				throw new Exception('curl_multi_exec failed with error code (' . $status . ') const (' . $err . ')');
			}

			// Block until *something* happens to avoid burning CPU cycles for naught
			while (0 === curl_multi_select($master, $selectTimeout) && $idleCallback) {
				$idleCallback($this);
				$this->idleCallbackCalled = true;
			}

			// see if we're done yet or not
		} while ($status === CURLM_CALL_MULTI_PERFORM || $active);

		curl_multi_close($master);
	}

	/**
	 * Helper function to gather all the curl options: global, inferred, and per request
	 *
	 * @param Request $request
	 * 
	 * @return array
	 */
	private function prepareRequestOptions(Request $request)
	{

		// options for this entire curl object
		$options = $this->getOptions();

		// set the request URL
		$options[CURLOPT_URL] = $request->getUrl();

		// set the request method
		$options[CURLOPT_CUSTOMREQUEST] = $request->getMethod();

		// posting data w/ this request?
		if ($request->getPostData()) {
			$options[CURLOPT_POST] = 1;
			$options[CURLOPT_POSTFIELDS] = $request->getPostData();
		}

		// if the request has headers, use those, or if there are global headers, use those
		if ($request->getHeaders()) {
			$options[CURLOPT_HEADER] = 0;
			$options[CURLOPT_HTTPHEADER] = $request->getHeaders();
		} elseif ($this->getHeaders()) {
			$options[CURLOPT_HEADER] = 0;
			$options[CURLOPT_HTTPHEADER] = $this->getHeaders();
		}

		// if the request has options set, use those and have them take precedence
		if ($request->getOptions()) {
			$options = $request->getOptions() + $options;
		}

		return $options;
	}

	/**
	 * Define a callable to handle the response. 
	 * 
	 * It can be an anonymous function:
	 *
	 *     $rc = new RollingCurl();
	 *     $rc->setCallback(function($request, $rolling_curl) {
	 *         // process
	 *     });
	 *
	 * Or an existing function:
	 *
	 *     class MyClass {
	 *         function doCurl() {
	 *             $rc = new RollingCurl();
	 *             $rc->setCallback(array($this, 'callback'));
	 *         }
	 *
	 *         // Cannot be private or protected
	 *         public function callback($request, $rolling_curl) {
	 *             // process
	 *         } 
	 *     }
	 *
	 * The called code should expect two parameters: \RollingCurl\Request $request, \RollingCurl\RollingCurl $rollingCurl
	 *   $request is original request object, but now with body, headers, response code, etc
	 *   $rollingCurl is the rolling curl object itself (useful if you want to re/queue a URL)
	 *
	 * @param callable $callback
	 * 
	 * @throws InvalidArgumentException
	 * @return RollingCurl
	 */
	public function setCallback(callable $callback)
	{
		$this->callback = $callback;
		return $this;
	}

	/**
	 * @return callable
	 */
	public function getCallback()
	{
		return $this->callback;
	}

	/** Define a callable to be called when waiting for responses. May not be called (if requests are done very fast - less than 0.1 second)! Check if was called with wasIdleCallbackCalled().
	 *
	 * @param callable $callback
	 * 
	 * @return RollingCurl
	 */
	public function setIdleCallback(callable $callback)
	{
		$this->idleCallback = $callback;
		return $this;
	}

	/**
	 *
	 * @return callable
	 */
	public function getIdleCallback()
	{
		return $this->idleCallback;
	}

	/**
	 * @param array $headers
	 * 
	 * @return RollingCurl
	 */
	public function setHeaders(array $headers)
	{
		$this->headers = $headers;
		return $this;
	}

	/**
	 * @return array
	 */
	public function getHeaders()
	{
		return $this->headers;
	}

	/**
	 * @param array $options
	 * 
	 * @return RollingCurl
	 */
	public function setOptions(array $options)
	{
		$this->options = $options;
		return $this;
	}

	/**
	 * Override and add options
	 *
	 * @param array $options
	 * 
	 * @return RollingCurl
	 */
	public function addOptions(array $options)
	{
		$this->options = $options + $this->options;
		return $this;
	}

	/**
	 * @return array
	 */
	public function getOptions()
	{
		return $this->options;
	}

	/**
	 * @param array $multicurlOptions
	 * 
	 * @return RollingCurl
	 */
	public function setMulticurlOptions(array $multicurlOptions)
	{
		$this->multicurlOptions = $multicurlOptions;
		return $this;
	}

	/**
	 * Override and add multicurlOptions
	 *
	 * @param array $multicurlOptions
	 * 
	 * @return RollingCurl
	 */
	public function addMulticurlOptions(array $multicurlOptions)
	{
		$this->multicurlOptions = $multicurlOptions + $this->multicurlOptions;
		return $this;
	}

	/**
	 * @return array
	 */
	public function getMulticurlOptions()
	{
		return $this->multicurlOptions;
	}

	/**
	 * Set the limit for how many cURL requests will be execute simultaneously.
	 *
	 * Please be mindful that if you set this too high, requests are likely to fail
	 * more frequently or automated software may perceive you as a DOS attack and
	 * automatically block further requests.
	 *
	 * @param int $count
	 * 
	 * @throws InvalidArgumentException
	 * @return RollingCurl
	 */
	public function setSimultaneousLimit($count)
	{
		if (!is_int($count) || $count < 2) {
			throw new InvalidArgumentException('setSimultaneousLimit count must be an int >= 2');
		}
		$this->simultaneousLimit = $count;
		return $this;
	}

	/**
	 * @return int
	 */
	public function getSimultaneousLimit()
	{
		return $this->simultaneousLimit;
	}

	/**
	 * @return Request[]
	 */
	public function getCompletedRequests()
	{
		return $this->completedRequests;
	}

	/**
	 * Return the next $limit pending requests (may return an empty array)
	 *
	 * If you pass $limit <= 0 you will get all the pending requests back
	 *
	 * @param int $limit
	 * 
	 * @return Request[] May be empty
	 */
	private function getNextPendingRequests($limit = 1)
	{
		$requests = [];
		if ($limit <= 0) {
			$requests = array_slice($this->pendingRequests, $this->pendingRequestsPosition);
		} else {
			while ($limit--) {
				if (!isset($this->pendingRequests[$this->pendingRequestsPosition])) {
					break;
				}
				$requests[] = $this->pendingRequests[$this->pendingRequestsPosition];
				$this->pendingRequestsPosition++;
			}
		}
		return $requests;
	}

	/**
	 * Get the next pending request, or return null
	 *
	 * @return null|Request
	 */
	private function getNextPendingRequest()
	{
		$next = $this->getNextPendingRequests();
		return count($next) ? $next[0] : null;
	}

	/**
	 * Removes requests from the queue that have already been processed
	 *
	 * Beceause the request queue does not shrink during processing
	 * (merely traversed), it is sometimes necessary to prune the queue.
	 * This method creates a new array starting at the first un-processed
	 * request, replaces the old queue and resets counters.
	 *
	 * @return RollingCurl
	 */
	public function prunePendingRequestQueue()
	{
		$this->pendingRequests = $this->getNextPendingRequests(0);
		$this->pendingRequestsPosition = 0;
		return $this;
	}

	/**
	 * @param bool $useArray count the completedRequests array is true. Otherwise use the global counter.
	 * 
	 * @return int
	 */
	public function countCompleted($useArray = false)
	{
		return $useArray ? count($this->completedRequests) : $this->completedRequestCount;
	}

	/**
	 * @return int
	 */
	public function countPending()
	{
		return count($this->pendingRequests) - $this->pendingRequestsPosition;
	}

	/**
	 * @return int
	 */
	public function countActive()
	{
		return count($this->activeRequests);
	}

	/**
	 * Clear out all completed requests
	 *
	 * If you are running a very large number of requests, it's a good
	 * idea to call this every few completed requests so you don't run
	 * out of memory.
	 *
	 * @return RollingCurl
	 */
	public function clearCompleted()
	{
		// free memory
		// FOREACH - neasociativni pole
		$i = 0;
		while (true === isset($this->completedRequests[$i])) {
			unset($this->completedRequests[$i]);
			$i++;
		}
		$this->completedRequests = [];
		gc_collect_cycles();
		return $this;
	}
	
	/**
	 * Check if idleCallback was called.
	 * 
	 * @return bool
	 */
	public function wasIdleCallbackCalled()
	{
		return $this->idleCallbackCalled;
	}

}
