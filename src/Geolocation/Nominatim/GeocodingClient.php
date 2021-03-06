<?php

namespace Clevis\Geolocation\Nominatim;

use Nette\Object;
use Nette\InvalidArgumentException;
use Clevis\Geolocation\Position;
use Clevis\Geolocation\Rectangle;
use Clevis\Geolocation\Address;
use Clevis\Geolocation\IGeocodingService;
use Clevis\Geolocation\ConnectionException;
use Clevis\Geolocation\InvalidStatusException;
use Clevis\Geolocation\InvalidResponseException;


/**
 * Client for Nominatim Geocoding API
 *
 * Nominatim.openstreetmap.org "requirements
 * No heavy uses (an absolute maximum of 1 request/s).
 * Provide a valid HTTP Referer or User-Agent identifying the application (stock User-Agents as set by http libraries will not do).
 * Clearly display attribution as suitable for your medium.
 * Data is provided under the ODbL licence which requires to share alike (although small extractions are likely to be covered by fair usage / fair dealing)."
 *
 * Own instances of nominatim are not subject to usage restrictions (except for the license, 'course).
 *
 * May be overridden for different nominatim instances (see $base_url)
 * 
 * @author Jan Martinec
 */
class GeocodingClient extends Object implements IGeocodingService
{

	protected $base_url = 'http://nominatim.openstreetmap.org/'; // use the public nominatim instance

	protected $user_name = ''; // set to empty for "no HTTP auth required"
	protected $user_pwd = '';

	protected $email = 'info@rekola.cz'; // needed in case of capacity problems (not in case of own instance)
	protected $ua = 'ReKolaSMS http://rekola.cz/ info@rekola.cz 1.0 2014-01-07'; // User-Agent string; required!

	protected $munge_address = true; // Nominatim has problems when ZIP cosdes are included, or city parts ("Praha 3"); this toggle attempts to strip such extraneous info

	private $viewport_bias = null;

	/**
	 * allows use of other nominatims
	 */
	public function setBaseUrl($url) {
		$this->base_url = $url;
	}

	/**
	 * Get GPS position for given address
	 *
	 * @param Address|string
	 * @param array
	 * @param bool
	 * @return Position|NULL
	 */
	public function getPosition($address, $options = array(), $fullResult = FALSE)
	{
		// address
		if ($address instanceof Address)
		{
			$address = (string) $address;
		}
		elseif (!is_string($address))
		{
			throw new InvalidArgumentException('Address should be instance of Address or a string.');
		}

		// bounds
		if (!empty($options['bounds']) && $options['bounds'] instanceof Rectangle)
		{
			/** @var Rectangle $rec */
			$rec = $options['bounds'];
			$b = $rec->getLatLonBounds();
			$options['viewboxlbrt'] = "$b[0],$b[1]|$b[2],$b[3]";
			$options['bounded'] = 1;
			unset($options['bounds']);
		}

		if ($this->munge_address) {
			$address = $this->mungeAddress($address);
		}


		$result = $this->getResponse($address, $options);

		return $fullResult ? $result : $result->getPosition();
	}

	/**
	 * Get address for given GPS position
	 *
	 * @param Position
	 * @param array
	 * @param bool
	 * @return Address|NULL
	 */
	public function getAddress(Position $position, $options = array(), $fullResult = FALSE)
	{
		$result = $this->getResponse($position, $options);

		return $fullResult ? $result : $result->getAddress();
	}

	/**
	 * Get both position and address for given query
	 *
	 * @param string|Address|Position
	 * @param array
	 * @return array (Position|NULL, Address|NULL)
	 */
	public function getPositionAndAddress($query, $options = array())
	{
		if ($query instanceof Position)
		{
			/** @var GeocodingResponse $response */
			$response = $this->getAddress($query, $options, TRUE);
			if ($response)
			{
				return array($response->getPosition(), $response->getAddress());
			}
		}
		else
		{
			/** @var GeocodingResponse $response */
			$response = $this->getPosition($query, $options, TRUE);
			if ($response)
			{
				return array($response->getPosition(), $response->getAddress());
			}
		}

		return array(NULL, NULL);
	}

	/**
	 * Get a full geocoding query result
	 *
	 * @param string|Address|Position
	 * @return GeocodingResponse
	 */
	public function getResponse($query, $options)
	{
		if ($query instanceof Position)
		{
			$options['lat'] = $query->latitude;
			$options['lon'] = $query->longitude; // NB: not "lng"!
		}
		else
		{
			$options['lat'] = null;
			$options['lon'] = null;
			$options['q'] = ((string) $query); // NB: not "address"!
		}

		return $this->query($options);
	}

	/**
	 * Set the geocoder to bias on a "rectangle" defined by two Position "corners";
	 *  following queries will prefer the viewport set this way.
	 *  Note that there are always two areas likely to be defined this way (see the links);
	 *  choosing the correct one is up to the implementation.
	 *
	 * @see http://stackoverflow.com/questions/23084764/draw-rectangle-on-map-given-two-opposite-coordinates-determine-which-ones-ar#comment35283253_23084764
	 * @see http://i.piskvor.org/test/which.png
	 * @see http://i.piskvor.org/test/which2.png
	 *
	 * @param Position $corner1
	 * @param Position $corner2
	 * @return boolean true if region biasing is available, false otherwise
	 */
	public function setBias(Position $corner1, Position $corner2) {
		$this->viewport_bias = array($corner1,$corner2);
	}


	/**
	 * Reset the bias set by setBias; following queries will not have a preferred viewport
	 *
	 * @return void
	 */
	public function unsetBias() {
		$this->viewport_bias = null;
	}

	/**
	 * Executes query on OSM Nominatim API
	 *
	 * @param  string
	 * @param  string
	 * @param  array [bounds, language, region, sensor]
	 * @return \StdClass
	 */
	protected function query(array $options)
	{
		if ($options['lat'] && $options['lon']) {
			$method = 'reverse';
		} else {
			$method = 'search';
		}

		if (is_array($this->viewport_bias) && (count($this->viewport_bias) == 2)) {
			/** @var Position $corner1 */
			$corner1  = $this->viewport_bias[0];
			/** @var Position $corner2 */
			$corner2  = $this->viewport_bias[1];
			/** @see http://wiki.openstreetmap.org/wiki/Nominatim */
			$options['viewbox'] = (
				$corner1->latitude . ',' . $corner1->longitude
				. ','
				. $corner2->latitude . ',' . $corner2->longitude
			);
			$options['bounded'] = '1';
		}

		$options['format'] = 'json';
		$options['addressdetails'] = 1;
		$options['email'] = $this->email;

		$url = $this->base_url . $method . '?' . http_build_query($options);

		$curl = curl_init();

		curl_setopt($curl, CURLOPT_HTTPHEADER, Array('Content-Type: application/json; charset=utf-8'));

		if ($this->user_name) {
			curl_setopt($curl, CURLOPT_USERPWD, $this->user_name . ':' . $this->user_pwd);
		}
		if ($this->ua) {
			curl_setopt($curl, CURLOPT_USERAGENT,$this->ua);
		}
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($curl, CURLOPT_URL, $url);
		$response = curl_exec($curl);
		$response_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);

		if (!$response)
		{
			throw new ConnectionException("Unable to connect to geocoding API.");
		}
		$payload = @json_decode($response); // @ - intentionally to escalate error to exception

		if ($payload && !is_array($payload)) {
			$payload = array($payload);
		}

		if (!$payload)
		{
			if ((is_array($payload) && (count($payload) == 0)))
			{
				throw new InvalidStatusException("Geocoding query failed (no results).");

			} else
			{
				throw new InvalidResponseException("Unable to parse response from geocoding API.");
			}
		}
		if ($response_code != 200)
		{
			throw new InvalidStatusException("Geocoding query failed (status: '" . $response_code . "').");
		}

		return new GeocodingResponse($this, $payload, $options);
	}

	protected function mungeAddress($address) {
		$address = preg_replace('/\d{3}\W*\d{2}/','',$address); // no ZIP codes - assuming there is no 5-digit house number
		$matched = 0;
		do { // remove all numbers behind the first comma, if any
			$address = preg_replace('/,([^\d]*?)([\d]+)/',',\\1',$address, -1, $matched);
		} while ($matched > 0);
		$address = preg_replace('/(Praha|Brno|Olomouc|Plzeň|Plzen|Ostrava|Pardubice) +\d+/i',',\\1',$address, -1, $matched); // remove "Praha 3", as this administrative district division is useless to us; worse, not all address points have it!
		$address = preg_replace('/,? +,?/',' ',$address);

		return $address;
	}
}
