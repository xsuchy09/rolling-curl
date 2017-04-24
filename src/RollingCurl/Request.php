<?php
/**
 * A cURL library to fetch a large number of resources while only using
 * a limited number of simultaneous connections
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

use DateTime;

/**
 * Class that represent a single curl request
 */
class Request
{

	/**
	 * @var string
	 */
	protected $url;

	/**
	 * @var string
	 */
	protected $method;

	/**
	 * @var string
	 */
	protected $postData;

	/**
	 * @var array
	 */
	protected $headers;

	/**
	 * @var array
	 */
	protected $options = [];

	/**
	 * @var mixed
	 */
	protected $extraInfo;

	/**
	 * @var string
	 */
	protected $responseText;

	/**
	 * @var array
	 */
	protected $responseInfo;

	/**
	 * @var string
	 */
	protected $responseError;

	/**
	 * @var int
	 */
	protected $responseErrno;
	
	/**
	 * @var DateTime
	 */
	protected $start;
	
	/**
	 * @var DateTime
	 */
	protected $end;
	
	/**
	 * @var int
	 */
	protected $executionTime;

	/**
	 * @param string $url
	 * @param string $method
	 * 
	 * @return Request
	 */
	public function __construct($url, $method = 'GET')
	{
		$this->setUrl($url);
		$this->setMethod($method);
	}

	/**
	 * You may wish to store some "extra" info with this request, you can put any of that here.
	 *
	 * @param mixed $extraInfo
	 * 
	 * @return Request
	 */
	public function setExtraInfo($extraInfo)
	{
		$this->extraInfo = $extraInfo;
		return $this;
	}

	/**
	 * @return mixed
	 */
	public function getExtraInfo()
	{
		return $this->extraInfo;
	}

	/**
	 * @param array $headers
	 * 
	 * @return Request
	 */
	public function setHeaders($headers)
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
	 * @param string $method
	 * 
	 * @return Request
	 */
	public function setMethod($method)
	{
		$this->method = $method;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getMethod()
	{
		return $this->method;
	}

	/**
	 * @param array $options
	 * 
	 * @return Request
	 */
	public function setOptions(array $options)
	{
		$this->options = $options;
		return $this;
	}

	/**
	 * @param array $options
	 * 
	 * @return Request
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
	 * @param string $postData
	 * 
	 * @return Request
	 */
	public function setPostData($postData)
	{
		$this->postData = $postData;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getPostData()
	{
		return $this->postData;
	}

	/**
	 * @param int $responseErrno
	 * 
	 * @return Request
	 */
	public function setResponseErrno($responseErrno)
	{
		$this->responseErrno = $responseErrno;
		return $this;
	}

	/**
	 * @return int
	 */
	public function getResponseErrno()
	{
		return $this->responseErrno;
	}

	/**
	 * @param string $responseError
	 * 
	 * @return Request
	 */
	public function setResponseError($responseError)
	{
		$this->responseError = $responseError;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getResponseError()
	{
		return $this->responseError;
	}

	/**
	 * @param array $responseInfo
	 * 
	 * @return Request
	 */
	public function setResponseInfo($responseInfo)
	{
		$this->responseInfo = $responseInfo;
		return $this;
	}

	/**
	 * @return array
	 */
	public function getResponseInfo()
	{
		return $this->responseInfo;
	}

	/**
	 * @param string $responseText
	 * 
	 * @return Request
	 */
	public function setResponseText($responseText)
	{
		$this->responseText = $responseText;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getResponseText()
	{
		return $this->responseText;
	}

	/**
	 * @param string $url
	 * 
	 * @return Request
	 */
	public function setUrl($url)
	{
		$this->url = $url;
		return $this;
	}

	/**
	 * @return string
	 */
	public function getUrl()
	{
		return $this->url;
	}

	/**
	 * Set start of execution of request.
	 * 
	 * @param DateTime $start
	 * 
	 * @return Request
	 */
	public function setStart(DateTime $start = null)
	{
		if ($start === null) {
			$start = $this->getDateTimeWithMicroseconds();
		}
		$this->start = $start;
		return $this;
	}
	
	/**
	 * Get start of exection of request.
	 * 
	 * @return DateTime
	 */
	public function getStart()
	{
		return $this->start;
	}

	/**
	 * Set end of execution of request.
	 * 
	 * @param DateTime $end
	 * 
	 * @return Request
	 */
	public function setEnd(DateTime $end = null)
	{
		if ($end === null) {
			$end = $this->getDateTimeWithMicroseconds();
		}
		$this->end = $end;
		return $this;
	}

	/**
	 * Get end of execution of request.
	 * 
	 * @return DateTime
	 */
	public function getEnd()
	{
		return $this->end;
	}
	
	/**
	 * Get time of execution of request (in microseconds).
	 * 
	 * @return float
	 */
	public function getExecutionTimeMicroseconds()
	{
		if ($this->start === null || $this->end === null) {
			return null;
		}
		$secondsDiff = $this->end->getTimestamp() - $this->start->getTimestamp();
		$microsecondsDiff = (int)((int)$secondsDiff * 1000000 + ((int)$this->end->format('u') - (int)$this->start->format('u')));
		$this->executionTime = (float)((int)$microsecondsDiff / 1000000);
		return (int)$microsecondsDiff;
	}
	
	/**
	 * Get time of execution of request (in seconds).
	 * 
	 * @return float
	 */
	public function getExecutionTime()
	{
		if ($this->executionTime === null) {
			$this->getExecutionTimeMicroseconds();
		}
		return (float)$this->executionTime;
	}
	
	/**
	 * Get actual time of execution of request (in seconds).
	 * 
	 * @return float
	 */
	public function getActualExecutionTime()
	{
		if ($this->start === null || $this->end !== null) { // not started or is ended
			return null;
		}
		$now = $this->getDateTimeWithMicroseconds();
		$secondsDiff = $now->getTimeStamp() - $this->start->getTimestamp();
		$microsecondsDiff = (int)((int)$secondsDiff * 1000000 + ((int)$now->format('u') - (int)$this->start->format('u')));
		return (float)((int)$microsecondsDiff / 1000000);
	}
	
	
	/**
	 * Get DateTime object with microseconds.
	 * 
	 * @param string $dateTimeWithMicroseconds
	 * 
	 * @return DateTime
	 */
	protected function getDateTimeWithMicroseconds($dateTimeWithMicroseconds = null)
	{
		if ($dateTimeWithMicroseconds === null) {
			$microseconds = round(microtime(true) - time(), 6) * 1000000;
			$dateAndTime = date('Y-m-d H:i:s');
			
			$dateTimeWithMicroseconds = $dateAndTime . '.' . $microseconds;
		}
		return DateTime::createFromFormat('Y-m-d H:i:s.u', $dateTimeWithMicroseconds);
	}
}
